<?php
declare(strict_types=1);
/**
 * 화물 운임 견적 엔진 (거리 × 톤급 → 예상 운임)
 *
 * 배경: 건축/토목 자재 배송은 택배(kg당)가 아니라 "톤급 트럭 대절 × 거리" 방식.
 * 요율 근거: 2026-09 화물중개 콜업체 2곳(전국레츠고화물 1833-7624, 전국한백퀵화물) 공시
 *   요금표 교차검증(거의 일치). 부가세 별도·지게차 상하차·편도 기준. 신뢰도 中(콜업체 가이드 시세).
 *   ⚠️ 실계약가는 유가·차량수급·상하차 방식·왕복 여부로 변동 → "예상(참고)"으로만 사용.
 * 거리: 시도 대표좌표 haversine × 1.3(도로 우회계수). 외부 지도 API 불필요.
 */

// 톤급 정의 — 열 인덱스 0~5로 일관 관리(소수 톤급을 배열 키/strict비교에 쓰면
//   PHP가 float 키를 int로 캐스팅하고 array_search가 오작동하므로 인덱스로 다룬다)
const FREIGHT_TON_LABEL = [1, 2.5, 5, 8, 11, 25];        // 표시용 톤급
const FREIGHT_TON_KG    = [1000, 2500, 5000, 8000, 11000, 25000]; // 톤급별 적재중량(명목, 보수적)

// 거리구간 상한(km) — 아래 요율표 행과 1:1
const FREIGHT_BRACKET_KM = [10, 30, 60, 90, 130, 150, 200, 250, 300, 400];

// 요율표: 브라켓 인덱스 → [톤급 => 원]. (연구 매트릭스 만원값 × 10000, 범위는 중앙값)
const FREIGHT_RATE = [
    //        1톤,     2.5톤,   5톤,     8톤,     11톤,    25톤
    0  => [40000,  70000,  90000,  130000, 140000, 190000], // ≤10km
    1  => [60000,  100000, 120000, 150000, 160000, 220000], // ≤30
    2  => [70000,  100000, 120000, 160000, 170000, 230000], // ≤60
    3  => [80000,  110000, 130000, 170000, 180000, 250000], // ≤90
    4  => [100000, 130000, 150000, 190000, 210000, 280000], // ≤130
    5  => [110000, 140000, 160000, 210000, 230000, 300000], // ≤150
    6  => [130000, 190000, 220000, 250000, 280000, 330000], // ≤200
    7  => [150000, 190000, 230000, 270000, 330000, 420000], // ≤250
    8  => [170000, 260000, 290000, 330000, 370000, 480000], // ≤300
    9  => [200000, 290000, 320000, 380000, 420000, 580000], // ≤400 (초과는 별도협의)
];

// 시도 대표좌표(도청/시청 소재지 근사) — 거리 계산용
const FREIGHT_SIDO_COORDS = [
    '서울' => [37.566, 126.978],
    '부산' => [35.180, 129.075],
    '대구' => [35.872, 128.601],
    '인천' => [37.456, 126.705],
    '광주' => [35.160, 126.851],
    '대전' => [36.350, 127.385],
    '울산' => [35.539, 129.311],
    '세종' => [36.480, 127.289],
    '경기' => [37.263, 127.029],
    '강원' => [37.885, 127.730],
    '충북' => [36.636, 127.491],
    '충남' => [36.658, 126.673],
    '전북' => [35.821, 127.109],
    '전남' => [34.816, 126.463],
    '경북' => [36.576, 128.505],
    '경남' => [35.228, 128.681],
    '제주' => [33.499, 126.531],
];

// 육로 거리 엔진으로 산출할 수 없는 도착지(해상 운송) — 별도 협의
const FREIGHT_NEGOTIATE_SIDO = ['제주'];

/**
 * spec '중량' 값 → 단위중량(kg). 천단위 쉼표 허용('2,640kg' → 2640.0).
 * kg 표기가 없거나 0 이하이면 0.0(중량 정보 없음).
 */
function freight_unit_kg(string $weight): float
{
    if (!preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)\s*kg/i', $weight, $m)) {
        return 0.0;
    }
    $kg = (float) str_replace(',', '', $m[1]);
    return $kg > 0 ? $kg : 0.0;
}

/**
 * 두 시도 간 도로거리 추정(km). haversine × 1.3, 동일 시도는 25km로 간주.
 * 미지의 시도명은 null.
 */
function freight_road_km(string $fromSido, string $toSido): ?float
{
    $a = FREIGHT_SIDO_COORDS[$fromSido] ?? null;
    $b = FREIGHT_SIDO_COORDS[$toSido]   ?? null;
    if (!$a || !$b) { return null; }
    if ($fromSido === $toSido) { return 25.0; } // 동일 권역 시내권 기본

    $R = 6371.0;
    $dLat = deg2rad($b[0] - $a[0]);
    $dLng = deg2rad($b[1] - $a[1]);
    $s = sin($dLat / 2) ** 2
       + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($s), sqrt(1 - $s));
    return round($R * $c * 1.3, 1); // 도로 우회계수 1.3
}

/** 거리(km) → 요율표 브라켓 인덱스 */
function freight_bracket_index(float $km): int
{
    foreach (FREIGHT_BRACKET_KM as $i => $max) {
        if ($km <= $max) { return $i; }
    }
    return count(FREIGHT_BRACKET_KM) - 1; // 400km 초과 → 최장 구간(별도협의 플래그는 호출부)
}

/**
 * 총중량(kg) → 필요 트럭 구성(톤급 열 인덱스 기준). 25톤 초과분은 25톤 다대 + 잔여 최소 톤급 1대.
 * @return array<array{col:int,count:int}>
 */
function freight_pick_trucks(float $totalKg): array
{
    if ($totalKg <= 0) { return []; }
    $col25 = count(FREIGHT_TON_KG) - 1; // 25톤 열
    $out   = [];
    if ($totalKg > FREIGHT_TON_KG[$col25]) {
        $n = (int) floor($totalKg / FREIGHT_TON_KG[$col25]);
        $out[] = ['col' => $col25, 'count' => $n];
        $totalKg -= $n * FREIGHT_TON_KG[$col25];
    }
    if ($totalKg > 0) {
        $picked = $col25;
        foreach (FREIGHT_TON_KG as $col => $kg) {
            if ($kg >= $totalKg) { $picked = $col; break; }
        }
        $out[] = ['col' => $picked, 'count' => 1];
    }
    return $out;
}

/**
 * 예상 운임 산출.
 * @return array{ok:bool, km?:float, over400?:bool, trucks?:array, total?:int, error?:string}
 */
function freight_estimate(string $fromSido, string $toSido, float $totalKg): array
{
    $km = freight_road_km($fromSido, $toSido);
    if ($km === null) { return ['ok' => false, 'error' => '지역 정보를 확인할 수 없습니다.']; }
    if ($totalKg <= 0) { return ['ok' => false, 'error' => '중량 정보가 없어 운임을 산출할 수 없습니다.']; }

    $bi     = freight_bracket_index($km);
    $trucks = freight_pick_trucks($totalKg);
    $total  = 0;
    $detail = [];
    foreach ($trucks as $t) {
        $rate  = FREIGHT_RATE[$bi][$t['col']];
        $sub   = $rate * $t['count'];
        $total += $sub;
        $detail[] = [
            'ton'   => FREIGHT_TON_LABEL[$t['col']],
            'count' => $t['count'],
            'each'  => $rate,
            'sub'   => $sub,
        ];
    }
    return [
        'ok'      => true,
        'km'      => $km,
        'over400' => $km > 400,
        'trucks'  => $detail,
        'total'   => $total, // 부가세 별도·지게차 상하차·편도 기준 예상
    ];
}

/**
 * 견적(여러 품목) 예상 운임. 상품별 운임을 더하지 않고 출하지별 총중량으로 1회 배차해 산출.
 * 중량 정보가 없는 품목이 하나라도 있으면 일부만 계산한 금액을 전체 운임처럼 보이지 않도록 금액을 내지 않는다.
 *
 * @param list<array{unit_kg:float,qty:int,origin:string}> $lines
 * @return array{status:string, freight?:int, total_kg?:float, km?:float, trucks?:array, origins?:array, missing?:int}
 *   status: ok | missing_weight(중량 없는 품목 포함) | negotiate(제주·400km 초과 — 별도 협의) | invalid(지역·품목 오류)
 */
function freight_estimate_lines(array $lines, string $dest): array
{
    if (!$lines || !isset(FREIGHT_SIDO_COORDS[$dest])) {
        return ['status' => 'invalid'];
    }
    if (in_array($dest, FREIGHT_NEGOTIATE_SIDO, true)) {
        return ['status' => 'negotiate'];
    }

    $missing    = 0;
    $kgByOrigin = [];
    foreach ($lines as $l) {
        $kg = (float) $l['unit_kg'] * (int) $l['qty'];
        if ($kg <= 0) { $missing++; continue; }
        $origin = (string) $l['origin'];
        $kgByOrigin[$origin] = ($kgByOrigin[$origin] ?? 0.0) + $kg;
    }
    if ($missing > 0) {
        return ['status' => 'missing_weight', 'missing' => $missing];
    }

    $total   = 0;
    $totalKg = 0.0;
    $maxKm   = 0.0;
    $trucks  = [];
    foreach ($kgByOrigin as $origin => $kg) {
        $est = freight_estimate((string) $origin, $dest, $kg);
        if (!$est['ok']) { return ['status' => 'invalid']; }
        if ($est['over400']) { return ['status' => 'negotiate']; }
        $total   += $est['total'];
        $totalKg += $kg;
        $maxKm    = max($maxKm, (float) $est['km']);
        $trucks   = array_merge($trucks, $est['trucks']);
    }
    return [
        'status'   => 'ok',
        'freight'  => $total, // 부가세 별도·지게차 상하차·편도 기준 예상
        'total_kg' => $totalKg,
        'km'       => $maxKm,
        'trucks'   => $trucks,
        'origins'  => array_map('strval', array_keys($kgByOrigin)),
    ];
}
