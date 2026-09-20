# 건축자재몰 (비엠아이토목건축자재)

닷홈 Apache/PHP/MySQL 환경용 건축자재 쇼핑몰. AI 상담·검색 고도화 포함.

## 서버 환경

- PHP 8.4 / MySQL 8.0
- 웹루트: `/html` (public/ 배포), 앱 로직: `/app` (app/ 배포, 웹루트 밖)
- 기본 주소: `http://tomokmall.dothome.co.kr`

## 환경변수 (시크릿)

시크릿은 코드에 두지 않습니다. `.env.example`을 복사해 값을 채웁니다.

- 로컬: 프로젝트 루트에 `.env.local`
- 운영: 웹루트 밖 `/app/.env` (FTP로 별도 업로드, git 미포함)
- 키: `OPENAI_API_KEY`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

```bash
cp .env.example .env.local   # 후 값 입력
```

## 최초 셋업

1. `database/schema.sql`을 phpMyAdmin에서 실행 (추가 마이그레이션은 `database/*.sql`)
2. DB 셋업/보정 유틸은 `scripts/db-tools/` (웹루트에 두지 않음 — 필요 시 CLI/임시 사용)
3. `/app/.env` 업로드 후 접속 확인

## 개발·품질

```bash
php tests/functions.test.php   # 순수함수 단위 테스트(검색 분해·수량·하이라이트 등)
bash scripts/smoke.sh [URL]    # 배포 스모크(핵심 URL 상태·마커)
```

## 배포

```bash
FTP_PASS='***' bash scripts/deploy.sh
```

- 배포 전: PHP 문법 검사 + 단위 테스트 게이트(실패 시 중단)
- 업로드: `public/`→`/html`, `app/`→`/app`, `database/`→`/database`
- 배포 후: 스모크 체크 자동 실행
- ⚠️ 업로드-only(삭제 안 함). 서버에서 파일을 지우려면 FTP로 직접 삭제.

## 관리자

`/admin.php` — `require_admin()`로 보호(admin 역할만). 미로그인 시 로그인으로 리다이렉트.
