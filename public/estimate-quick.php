<?php
declare(strict_types=1);
/**
 * 기업회원 즉시견적 페이지
 * 역할: 인증 확인 + 카테고리 조회 → quick_estimate_renderer.php 포함
 * 포함 금지: UI HTML, 비즈니스 로직
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';

$loginUrl = '/login.php?redirect=' . urlencode('/estimate-quick.php');

if (!is_logged_in()) {
    redirect($loginUrl);
}
if (!is_corporate_member()) {
    // 로그인했으나 개인회원 → 기업회원 전용 안내 모달(UI는 파셜에 위임). 이후 로직 실행 금지.
    require __DIR__ . '/../app/corporate_only_renderer.php';
    exit;
}

$user       = current_user();
$categories = db() ? fetch_categories() : [];

// GNB와 동일한 5개 대분류 + 하위 카테고리 트리 빌드
// layout.php GNB 쿼리와 동일하게 lv1 ID 11~15로 제한
$gnbRootIds  = [11, 12, 13, 14, 15];
$catLv1      = [];
$catByParent = [];
foreach ($categories as $c) {
    $id  = (int)$c['id'];
    $pid = $c['parent_id'] === null ? null : (int)$c['parent_id'];
    if ($pid === null && in_array($id, $gnbRootIds, true)) {
        $catLv1[] = [
            'id'   => $id,
            'name' => preg_replace('/제품$/u', '', $c['name']),
        ];
    } elseif ($pid !== null) {
        $catByParent[$pid][] = ['id' => $id, 'name' => $c['name']];
    }
}
// sort_order 기준 정렬
usort($catLv1, fn($a, $b) => array_search($a['id'], $gnbRootIds) - array_search($b['id'], $gnbRootIds));

require __DIR__ . '/../app/quick_estimate_renderer.php';
