# 디자인 미리보기 (design-preview)

개발 전 Figma 시안을 HTML/CSS로 옮겨 검토하는 공간입니다. **실제 서비스 코드(`public/`, `app/`)와 분리**되어 있습니다.

- DB·세션·로그인 로직을 사용하지 않습니다.
- `scripts/deploy.sh`는 `public/`·`app/`·`database/`만 업로드하므로 이 폴더는 **운영 서버에 올라가지 않습니다.** `public/`으로 옮기지 마세요.
- 기존 이미지·폰트는 `/assets/...` 경로로 읽기 전용 재사용합니다(`public/assets`).

## 실행

```bash
bash scripts/design-preview.sh          # 0.0.0.0:8090 (사내 접속 허용)
PORT=9000 bash scripts/design-preview.sh
HOST=localhost bash scripts/design-preview.sh   # 이 PC에서만
```

| 접속 위치 | 주소 |
|---|---|
| 서버를 띄운 PC | `http://localhost:8090/design-preview/` |
| 사내 다른 PC (같은 네트워크) | `http://<서버 PC의 사내 IP>:8090/design-preview/` |

사내 IP 확인: macOS `ipconfig getifaddr en0`, Windows `ipconfig`(IPv4 주소), Linux `hostname -I`.
다른 PC에서 안 열리면 서버 PC의 방화벽(macOS: 시스템 설정 → 네트워크 → 방화벽)에서 `php`의 수신 연결을 허용하세요.

## 시안 추가

1. `frames/_template.html`을 복사해 `frames/<화면이름>.html`로 저장 (영문 소문자·숫자·하이픈)
2. `<title>`에 화면 이름, `<meta name="figma-url">`에 원본 Figma 프레임 링크 입력
3. `/design-preview/` 목록에서 데스크톱(1280)·태블릿(768)·모바일(360) 너비로 확인

`_`로 시작하는 파일은 목록에 표시되지 않습니다.
