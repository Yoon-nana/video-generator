<?php
ini_set('display_errors','0'); error_reporting(E_ALL);
ini_set('session.cookie_httponly','1'); ini_set('session.cookie_secure','1'); ini_set('session.cookie_samesite','Strict');
session_start(); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
$config = getenv('VIDEO_GENERATOR_CONFIG') ?: '/etc/yoong-video-generator/config.php';
function fail($message,$status=400){http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);exit;}
function out($x){header('Content-Type: application/json; charset=utf-8');echo json_encode($x,JSON_UNESCAPED_UNICODE);exit;}
if (!is_readable($config)) fail('서버 설정을 확인해 주세요.',503);
require $config;
$action=$_GET['action']??'';
if ($action==='login') {
 if ($_SERVER['REQUEST_METHOD']!=='POST') fail('잘못된 요청입니다.',405);
 $input=json_decode(file_get_contents('php://input'),true)??[];
 $bucket='/var/tmp/yoong-video-login-'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');
 $now=time(); $attempts=is_file($bucket)?json_decode((string)file_get_contents($bucket),true):[];
 $attempts=array_values(array_filter(is_array($attempts)?$attempts:[],fn($t)=>$t>$now-600));
 if(count($attempts)>=10) fail('시도가 너무 많습니다. 잠시 후 다시 시도해 주세요.',429);
 if(!password_verify((string)($input['password']??''),$passwordHash)) { $attempts[]=$now; file_put_contents($bucket,json_encode($attempts),LOCK_EX); fail('비밀번호가 올바르지 않습니다.',403); }
 @unlink($bucket); session_regenerate_id(true); $_SESSION['auth']=true; $_SESSION['csrf']=bin2hex(random_bytes(24)); out(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if(empty($_SESSION['auth'])) fail('비밀번호를 입력해 주세요.',401);
if ($action==='session') out(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
if ($action==='logout') {if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('잘못된 요청입니다.',403);session_destroy();out(['ok'=>true]);}
function remote($url,$method='GET',$data=null) {
 global $apiKey;
 $headers="Authorization: Bearer ".$apiKey."\r\nAccept: application/json\r\n";
 if($data!==null) $headers.="Content-Type: application/json\r\n";
 $opts=['http'=>['method'=>$method,'header'=>$headers,'timeout'=>35,'ignore_errors'=>true,'follow_location'=>0]];
 if($data!==null) $opts['http']['content']=json_encode($data,JSON_UNESCAPED_UNICODE);
 $raw=@file_get_contents($url,false,stream_context_create($opts));
 $status=0;if(isset($http_response_header[0])&&preg_match('/\s(\d{3})\s/',$http_response_header[0],$m))$status=(int)$m[1];
 $response=json_decode((string)$raw,true);
 if($status<200||$status>=300||!is_array($response)) { error_log('Video API failure: HTTP '.$status); fail('영상 서비스 요청에 실패했습니다. 잔액과 잠시 후 상태를 확인해 주세요.',502); }
 return $response;
}
if($action==='generate'){
 if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('잘못된 요청입니다.',403);
 $input=json_decode(file_get_contents('php://input'),true)??[]; $prompt=trim((string)($input['prompt']??''));
 $duration=filter_var($input['duration']??null,FILTER_VALIDATE_INT); if($duration===false||$duration<5||$duration>8)fail('영상 길이는 5초부터 8초까지 선택해 주세요.');
 if($prompt===''||strlen($prompt)>6000)fail('영상 설명을 입력해 주세요. (최대 2,000자)');
 if(!empty($_SESSION['last_generation']) && time()-$_SESSION['last_generation']<20)fail('잠시 후 다시 생성해 주세요.',429);
 if(!empty($_SESSION['active_job']))fail('현재 영상 생성이 진행 중입니다.',409);
 $result=remote('https://openrouter.ai/api/v1/videos','POST',['model'=>'heygen/heygen-video-1','prompt'=>$prompt,'aspect_ratio'=>'9:16','duration'=>$duration,'resolution'=>'480p']);
 $id=$result['id']??''; if(!preg_match('/^gen-vid-[A-Za-z0-9-]+$/',$id))fail('영상 생성 응답을 확인할 수 없습니다.',502);
 $_SESSION['active_job']=$id;$_SESSION['active_duration']=$duration;$_SESSION['last_generation']=time(); out(['status'=>'pending']);
}
if($action==='status'){
 $id=$_SESSION['active_job']??''; if(!$id)fail('진행 중인 영상이 없습니다.',404);
 $r=remote('https://openrouter.ai/api/v1/videos/'.rawurlencode($id));$status=$r['status']??'';
 if(in_array($status,['failed','cancelled'],true)){unset($_SESSION['active_job']);fail('영상 생성에 실패했습니다. 다시 시도해 주세요.',502);}
 if($status!=='completed')out(['status'=>'processing','progress'=>$r['progress']??null]);
 $url=$r['urls'][0]??''; if ($url==='') out(['status'=>'processing']); $parts=parse_url($url);
 if(($parts['scheme']??'')!=='https'||($parts['host']??'')!=='storage.googleapis.com')fail('결과 영상 주소를 확인할 수 없습니다.',502);
 $dir='/var/tmp/yoong-video-results'; if(!is_dir($dir)&&!@mkdir($dir,0700,true))fail('영상 저장 공간을 만들 수 없습니다.',500); foreach(glob($dir.'/*.mp4')?:[] as $oldFile){if(is_file($oldFile)&&filemtime($oldFile)<time()-86400)@unlink($oldFile);}
 $base=$dir.'/'.hash('sha256',session_id().$id); $in=$base.'-source.mp4';$out=$base.'.mp4';
 $ctx=stream_context_create(['http'=>['timeout'=>90,'follow_location'=>0]]);$source=@fopen($url,'rb',false,$ctx); if(!$source)fail('완성된 영상을 받지 못했습니다.',502);
 $dest=fopen($in,'wb'); if(!$dest)fail('영상 저장에 실패했습니다.',500); $bytes=stream_copy_to_stream($source,$dest,50000000);fclose($source);fclose($dest);
 if(!$bytes){@unlink($in);fail('영상 파일이 비어 있습니다.',502);}
 $duration=(int)($_SESSION['active_duration']??5); if($duration<5||$duration>8)$duration=5;
 $cmd='/usr/bin/ffmpeg -nostdin -v error -y -i '.escapeshellarg($in).' -t '.escapeshellarg((string)$duration).' -c:v libx264 -preset veryfast -crf 21 -c:a aac -movflags +faststart '.escapeshellarg($out).' 2>&1';
 exec($cmd,$log,$code);@unlink($in); if($code!==0||!is_file($out)){error_log('ffmpeg failed');fail('영상 길이 조정에 실패했습니다.',500);}
 $_SESSION['video_path']=$out; unset($_SESSION['active_job'],$_SESSION['active_duration']);out(['status'=>'completed','cost'=>$r['usage']['cost']??null]);
}
if($action==='video'){
 $path=$_SESSION['video_path']??''; if(!$path||!is_file($path)||strpos($path,'/var/tmp/yoong-video-results/')!==0)fail('영상을 찾을 수 없습니다.',404);
 header('Content-Type: video/mp4');header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="video.mp4"'); readfile($path);exit;
}
fail('잘못된 요청입니다.',404);
