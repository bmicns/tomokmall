<?php
declare(strict_types=1);
/**
 * Claude 원격 관리 API 진입점 (POST /api/remote-ops.php)
 *
 * 일반 사용자 요청과 분리된 관리 전용 엔드포인트. 세션·쿠키를 쓰지 않고, 요청마다 HMAC 서명·시각·nonce 로 인증한다.
 * 사용 설정(/app/.env.remote-ops 의 REMOTE_OPS_ENABLED=1 + REMOTE_OPS_KEY)이 없으면 아무 작업도 하지 않는다.
 * 사이트 코드(app/bootstrap.php)를 불러오지 않는다 — 배포로 사이트가 깨져도 이 API 로 복구할 수 있게.
 * 설계·사용법: docs/02-design/remote-ops.md, docs/REMOTE-OPS.md
 */
require __DIR__ . '/../../app/remote_ops/server.php';

rops_main(dirname(__DIR__));
