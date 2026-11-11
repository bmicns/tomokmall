<?php
declare(strict_types=1);
/**
 * 상용콘크리트 업체 엑셀 자동 정리 — 규칙(데이터만). 정리 엔진: app/syc_normalize.php
 *
 * 왜 규칙과 엔진을 나누나: 업체 파일이 바뀌거나 카테고리를 다시 나누고 합칠 때 고치는 곳은 이 파일 하나다.
 * 엔진은 이 규칙을 읽어 행을 바꾸고 '무엇을 왜 바꿨는지'를 남기기만 한다(규칙 없이 추측하지 않는다).
 * 이 파일에 넣는 것 = 사용자가 결정한 분류·표기 원칙(2026-11-11)을 업체 원본 표기에 다시 적용하는 데 필요한 사실.
 *
 *   category_merge  숨긴 카테고리 → 모은 카테고리(측구수로관 형식별 7개 → syc-s1). database/20261111_syc_categories_merge.sql 과 같게
 *   category_split  치수 칸 구성으로 나눈 카테고리 묶음: 원래 분류코드로 와도 '값이 있는 목록 치수' 로 알맞은 카테고리를 고른다.
 *                   database/20261111_syc_categories_split.sql 과 같게. dims = 그 카테고리 상품에 값이 있는 목록 치수(이름표 in_list)
 *   option_words    품명·모델 코드에 붙어 오면 옵션으로 옮길 글자(앞에 둠, 이 순서)
 *   code_suffix     모델 코드 '크기 + 형식' 에서 형식 표기: 대문자 1~3자 → 'B형', 대문자+숫자(W2) → 그대로, option_words → 옵션
 *   option_dims     옵션 글자 속 치수 → 치수 칸(부속 dim_a_* 등). text = 옵션에 남길 글자, only = 이 카테고리에서만
 *
 * 금지: 가격·출처·이미지 칸 규칙(엔진이 손대지 않는다), 근거 없는 값 만들기.
 */

function syc_normalize_rules(): array
{
    static $rules = null;
    if ($rules !== null) { return $rules; }
    $side = ['syc-s2', 'syc-s3', 'syc-s4', 'syc-s5', 'syc-s6', 'syc-s7'];
    $rules = [
        'category_merge' => array_fill_keys($side, 'syc-s1'),
        'category_split' => [
            ['syc-s8'  => ['w', 'h', 'l'],              'syc-s78' => ['w', 'h', 'v']],
            ['syc-s17' => ['d_i', 'w', 'h', 'l', 't', 't2'], 'syc-s79' => ['d_i', 'w', 'h', 'l', 't2']],
            ['syc-s18' => ['w', 'depth', 'l'],          'syc-s80' => ['w', 'depth'], 'syc-s81' => ['w'], 'syc-s82' => []],
            ['syc-s28' => ['d_i', 'h', 't_wall'],       'syc-s83' => ['d']],
            ['syc-s48' => ['w', 'h', 'v'],              'syc-s84' => ['w', 'v']],
            ['syc-s52' => ['w', 'h', 'v'],              'syc-s85' => ['d', 'h']],
            ['syc-s53' => ['w', 'h', 'v'],              'syc-s86' => ['d_i', 'd_o', 'h']],
            ['syc-s74' => ['w', 'h', 'l'],              'syc-s87' => ['w', 'h', 'l', 'h1']],
            ['syc-s75' => ['w', 'h', 'v'],              'syc-s88' => ['d', 'h'], 'syc-s89' => ['w', 'v']],
            ['syc-w2'  => ['d', 'h', 't'],              'syc-w5'  => ['d', 'w', 'h', 't']],
        ],
        'option_words' => ['앵글미부착', '앵글부착', '주문품', '무개', '유개', '상부구체', '연직구체', '하부구체'],
        // 옵션 글자 속 치수. 정규식 묶음 번호 → 칸. 칸에 이미 다른 값이 있으면 바꾸지 않고 경고(업체 값 우선)
        'option_dims' => [
            ['re' => '/^(그레이팅|유개그레이팅) (\d+)\*(\d+)\*(\d+)\/(\d+)\*(\d+)\*(\d+)$/u', 'text' => '$1',
             'set' => [2 => 'dim_a_w', 3 => 'dim_a_l', 4 => 'dim_a_t', 5 => 'dim_a2_w', 6 => 'dim_a2_l', 7 => 'dim_a2_t']],
            ['re' => '/^(그레이팅|유개그레이팅) (\d+)\*(\d+)\*(\d+)$/u', 'text' => '$1', 'set' => [2 => 'dim_a_w', 3 => 'dim_a_l', 4 => 'dim_a_t']],
            ['re' => '/^스틸그레이팅 (\d+)\*(\d+)\*(\d+)$/u', 'text' => '스틸그레이팅', 'set' => [1 => 'dim_a_w', 2 => 'dim_a_t', 3 => 'dim_a_l']],   // 폭*높이*길이
            ['re' => '/^안전커버 (\d+)\*(\d+)$/u', 'text' => '안전커버', 'set' => [1 => 'dim_a_w', 2 => 'dim_a_t']],
            ['re' => '/^그레이팅 높이 (\d+)$/u', 'text' => '', 'set' => [1 => 'dim_a_t']],                                            // 그레이팅 치수 셋째 값과 같음
            ['re' => '/^슬라브 [ØΦ](\d+)$/u', 'text' => '슬라브 있음', 'set' => [1 => 'dim_a_w']],
            ['re' => '/^그레이팅 뚜껑 (\d+)\*(\d+)\*(\d+)( \/ PE뚜껑 \d+)?$/u', 'text' => '그레이팅 뚜껑$4', 'set' => [1 => 'dim_a_w', 2 => 'dim_a_l', 3 => 'dim_a_t']],
            ['re' => '/^받침틀 (\d+)\*(\d+)$/u', 'text' => '받침틀', 'set' => [1 => 'dim_a_w', 2 => 'dim_a_l']],
            ['re' => '/^파형 ([\d.]+)[xX×]([\d.]+)$/u', 'text' => '', 'set' => [1 => 'dim_x1', 2 => 'dim_x2'], 'only' => ['syc-s76']],
            ['re' => '/^두께 (\d+) 이상$/u', 'text' => '', 'set' => [1 => 'dim_t'], 'only' => ['syc-w1']],                            // 두께 칸 이름 '최소두께'
        ],
    ];
    return $rules;
}
