# 건축자재몰 PHP MVP

도트홈 Apache/PHP/MySQL 환경용 건축자재몰 MVP입니다.

## 서버 환경

- PHP 8.4
- MySQL 8.0
- 웹루트: `/hosting/bmicns/html`
- 기본 주소: `http://tomokmall.dothome.co.kr`

## 배포 순서

1. `database/schema.sql`을 phpMyAdmin에서 실행합니다.
2. `public/` 내용은 `/html`에, `app/` 폴더는 웹루트 밖 `/app`에 업로드합니다.
3. 접속 후 상품 목록, 장바구니, 견적 요청, 관리자 화면을 확인합니다.

## 관리자

초기 MVP는 별도 로그인 없이 `/admin.php`로 접근합니다. 운영 전 반드시 인증 기능을 추가해야 합니다.
