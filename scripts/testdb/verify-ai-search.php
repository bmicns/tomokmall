<?php
declare(strict_types=1);
/**
 * AI 상담 시연 흐름 검증 (scripts/testdb/verify-ai-search.php) — 로컬 '_test' DB + 실제 수집 상품(ktomok 1,452건)
 *
 * AI가 호출하는 search_products 도구를 직접 실행해 대표 규격·필요 수량·예상 자재비 표를 출력한다(OpenAI 호출 없음).
 * 사용: bash scripts/testdb/load-ktomok-docker.sh 후 출력된 export 적용 → php scripts/testdb/verify-ai-search.php > 결과.md
 * 안전장치: common.php 가드(로컬 '_test' DB만). DB는 SELECT만.
 */

require __DIR__ . '/common.php';
require_once __DIR__ . '/../../app/ai_tools.php';
tdb_test_pdo();

$cases = [
    '① 사람만 다니는 집 앞 20㎡ — 종류 비교' => ['query' => '보도블록', 'queries' => ['인터로킹 블록', '인조화강블록', '점토블럭'], 'area_m2' => 20],
    '② 차가 드나드는 진입로 20㎡ — 80T만' => ['query' => '보도블록', 'queries' => ['인터로킹 블록', '인조화강블록', '점토블럭'], 'area_m2' => 20, 'spec_mm' => 80],
    '②\' 점토블럭 80T 단일 검색(spec_strict)' => ['query' => '점토블럭', 'area_m2' => 20, 'spec_mm' => 80, 'spec_strict' => true],
    '③ 단일 검색 보도블록 20㎡' => ['query' => '보도블록', 'area_m2' => 20],
    '④ 테두리 경계석 18m' => ['query' => '경계석', 'length_m' => 18],
    '⑤ 배수 경계석 18m' => ['query' => '배수 경계석', 'length_m' => 18],
];
foreach ($cases as $title => $input) {
    $res = json_decode(tool_search_products($input), true);
    echo "\n### {$title}\n입력: `" . json_encode($input, JSON_UNESCAPED_UNICODE) . "`\n\n";
    if (!empty($res['note'])) { echo "안내(note): {$res['note']}\n\n"; }
    if (empty($res['products'])) { echo "결과 없음(다른 규격으로 대체하지 않음)\n"; continue; }
    echo "| 상품(id) | 규격 | 단가 | 필요 수량 | 계산 근거 | 예상 자재비 |\n|---|---|---:|---:|---|---:|\n";
    foreach ($res['products'] ?? [] as $p) {
        $reg = preg_match('/규격:\s*([^|]+)/u', (string) $p['spec'], $m) ? trim($m[1]) : '';
        printf("| %s (%d) | %s | %s원/%s | %s | %s | %s |\n", $p['name'], $p['id'], $reg, number_format($p['price_b2c']), $p['unit'] ?: '-',
            $p['suggested_qty'] === null ? '계산 불가' : number_format($p['suggested_qty']), $p['qty_basis'] ?: '-',
            $p['est_cost'] === null ? '-' : number_format($p['est_cost']) . '원');
    }
    $costs = array_filter(array_column($res['products'] ?? [], 'est_cost'));
    if (count($costs) >= 2) { echo "비교: 최저 " . number_format(min($costs)) . "원 ~ 최고 " . number_format(max($costs)) . "원 (차이 " . number_format(max($costs) - min($costs)) . "원)\n"; }
}
