# 비회원 AI 상담 이전 기록 보기 — 검토와 결정 (2026-11-26)

검토 문서(Claude Doc): https://claude.ai/artifact/J6ybwPbAGxZdFqsuQRAJ8w

## 1. 현재 구조 (2026-11-26 코드·운영 조회)
- 상담 번호: 기존 AI 상담 `rag_chat_key_issue()`, 빠른 상담 `qc`+30자. 허용 번호는 PHP 세션 `$_SESSION['chat_keys']`(최근 30개).
- PHP 세션 쿠키는 브라우저 종료 시 사라짐(lifetime 0) → 비회원은 이전 기록을 다시 열 수 없음.
- `ai_sessions`(상담 1건 = 1행, 비회원 `user_id` NULL), `chat_logs`(답변 1회 = 1행). 보관·삭제 없음.
- 운영: 비회원 `ai_sessions` 31건(30일 지난 것 13건), `chat_logs` 1년 지난 행 0건.

## 2. 비교한 방식
A 비회원 토큰 쿠키 + DB 해시(추천) / B 세션 기한 연장(로그인도 길어져 보안 약화) / C 브라우저 저장소(내용이 기기에 남음) / D 서명 쿠키에 상담 번호 목록(번호 = 열쇠) / E 휴대폰 인증(과함).

## 3. 사용자 결정 (2026-11-26)
1. A 방식
2. 비회원 대화 보관 30일(마지막 대화 기준, 자동 삭제)
3. `chat_logs` 1년 보관 후 삭제
4. 로그인 때 묻지 않고 이 기기 비회원 기록을 회원 계정으로 옮김(공용 PC 위험은 검토 문서에서 안내함)
5. DB 칸 `ai_sessions.guest_hash CHAR(64) NULL` + 인덱스 — 승인 대기
6. HTTPS 자동 이동 — 설명 후 결정 대기(권장: 별도 진행)

## 4. 설계 요점
- 쿠키 `tm_guest` = `random_bytes(32)`, HttpOnly·SameSite=Lax·HTTPS 면 Secure, 30일(대화 때 연장), 처음 저장할 때만 발급. 토큰은 URL·로그에 쓰지 않음.
- 읽기 조건: `session_key = ? AND (user_id = 회원 OR (user_id IS NULL AND guest_hash = 해시))`. 쓰기는 `chat_keys` 확인 유지 + `rag_session_writable` 에 `guest_hash` 조건.
- 삭제: 저장 때 약 1% 확률로 오래된 비회원 행 최대 200개 정리(닷홈 cron 불확실).
- 로그인: `UPDATE ai_sessions SET user_id=회원, guest_hash=NULL WHERE guest_hash=해시 AND user_id IS NULL` + 같은 번호 `chat_logs.user_id`, 토큰 쿠키 삭제. 로그아웃 후 새 토큰.
- 순서: DB 칸 추가 → 코드 배포(칸 없으면 지금처럼 동작).
