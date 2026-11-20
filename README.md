# 영상생성기

원하는 장면을 입력하면 HeyGen Video로 세로형 쇼츠 영상을 만드는 간단한 웹 앱입니다.

- 길이: 4~8초 선택. 4초는 5초 생성 후 편집합니다.
- 모델: OpenRouter의 `heygen/heygen-video-1`
- 비율과 해상도: 9:16, 480p
- 서버에서 비밀번호를 확인하고 API 키를 사용합니다.
- 생성 시 OpenRouter 계정에 사용료가 발생하며 요금은 변경될 수 있습니다.

## 준비

PHP 8.0 이상, OpenSSL, HTTPS, ffmpeg(기본 경로 `/usr/bin/ffmpeg`, libx264 및 AAC 인코더), OpenRouter API 키가 필요합니다. PHP의 HTTPS 스트림 접근(`allow_url_fopen`)과 `exec`도 사용할 수 있어야 합니다. 서버에 PHP 세션 쓰기 권한과 `/var/tmp` 쓰기 권한이 필요합니다.

1. `index.html`과 `api.php`를 PHP 웹 서버 디렉터리에 배치합니다.
2. 웹에서 접근할 수 없는 경로(기본값: `/etc/yoong-video-generator/config.php`)에 아래 형식으로 설정 파일을 만듭니다. API 키와 비밀번호를 저장소에 넣거나 웹 루트에 보관하지 마세요.

```php
<?php
$apiKey = '여기에_본인의_OpenRouter_API_키';
$passwordHash = '여기에_생성한_비밀번호_해시';
```

3. 서버에서 `php -r 'echo password_hash(readline("Password: "), PASSWORD_DEFAULT), PHP_EOL;'`을 실행해 비밀번호 해시를 만들고 설정 파일에 넣습니다. 키와 해시는 웹 서버 계정만 읽을 수 있도록 권한을 제한합니다.
4. 다른 경로를 사용하려면 웹 서버의 환경변수 `VIDEO_GENERATOR_CONFIG`에 설정 파일 절대 경로를 지정합니다.
5. HTTPS 주소에서 페이지를 열고 설정한 비밀번호를 입력합니다. 브라우저 인증 쿠키에 Secure 옵션이 적용되어 있어 HTTPS가 필요합니다.

## 작동 방식

브라우저는 비밀번호와 영상 설명을 PHP 서버에 전송합니다. 서버가 인증 후 OpenRouter API를 호출하고 작업 상태를 확인하며, 생성된 영상을 임시 저장소에서 ffmpeg로 편집합니다. 완성 영상은 인증 세션을 통해 재생·다운로드합니다. 임시 영상은 이후 새 영상이 완료될 때 24시간이 지난 파일부터 정리됩니다.

비밀번호를 아는 사람은 연결된 API 사용료를 발생시킬 수 있습니다. 공개 운영 시 서버의 접속 제한, 사용량 및 잔액 관리를 별도로 설정하세요. 생성 도중 브라우저를 닫았다가 다시 열었을 때는 같은 세션에서 다시 생성 버튼을 눌러 진행 중인 작업을 확인할 수 있습니다.

## 파일

- `index.html` — 화면과 브라우저 동작
- `api.php` — 인증, 영상 생성·상태 확인 및 편집
- `config.example.php` — 서버 전용 설정 형식 예시