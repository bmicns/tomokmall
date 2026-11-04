# 보관: 상담 요청(상담원 문의 접수 · 관리자 '상담 요청' 화면)

> **2026-11-04 사용자 요청으로 화면에서 제거하고 코드만 보관.** 이 폴더는 배포 대상이 아니다
> (`scripts/deploy.sh` 는 `public/`·`app/`·`database/` 만, `scripts/deploy-files.sh` 는 `public/`·`app/` 경로만 올린다). 웹에서 열 수 없다.

## 들어 있는 것 (원래 위치를 그대로 유지)

| 보관 위치 | 원래 위치 | 내용 |
|---|---|---|
| `app/consult.php` | `app/consult.php` | 문의 검증·저장, 목록·상세 조회, 상태 변경(완료 시 이름·연락처 파기), 메모, 같은 상담 키의 AI 상담 내역(`chat_logs`) 조회, 전화번호 정리 |
| `public/admin-consults.php` | 같음 | 관리자 › 운영 › 상담 요청 목록(상태 탭·검색·페이지) |
| `public/admin-consult.php` | 같음 | 상세(요청 정보·동의 내역·문의 내용·AI 상담 내역 전체·처리 상태·메모·처리 이력) |
| `public/api/admin/consults.php` | 같음 | 관리자 처리 API(상태 변경·메모, CSRF 토큰) |
| `public/api/consult/request.php` | 같음 | 고객 문의 접수 API(로그인 회원, 세션 10분 3건) |
| `tests/consult.test.php` | `tests/functions.test.php` 의 '상담 요청' 부분 | 순수 함수 테스트 21개 — `php _archive/consult-request/tests/consult.test.php` |

- 같이 쓰던 DB: `database/20261103_consult_requests.sql`(`consult_requests`·`consult_request_events`) — **운영에 이미 적용됨(2026-11-03)**, 이력이라 `database/` 에 그대로 둔다. 지금은 쓰는 코드가 없어 빈 테이블로 남는다. 삭제는 그 파일 [4] + 별도 승인.
- 이미 지운 것(커밋 기록에만 있음): 문자 발송 `app/sms.php`(2026-11-04 `2ceba6d`), 고객 문의 모달(같은 커밋), 관리자 메뉴 '상담 요청'(이 보관 커밋). 필요하면 `git show 39c1f94` 이후 커밋에서 확인.

## 되살릴 때

1. 이 폴더의 파일을 원래 위치로 `git mv`(위 표).
2. `app/admin_layout.php` 의 `$menus` 운영 칸에 `['key' => 'consults', 'label' => '상담 요청', 'url' => '/admin-consults.php']` 와 `_adm_icon()` 의 `'consults'` 아이콘 다시 추가(커밋 `39c1f94` 참고).
3. `tests/consult.test.php` 내용을 `tests/functions.test.php` 로 옮기고 `require_once __DIR__ . '/../app/consult.php';` 추가.
4. 고객 쪽 접수 화면(모달)은 카카오톡 채널 방식으로 바뀌었으므로 새로 정한다.
