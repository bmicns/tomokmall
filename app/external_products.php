<?php
declare(strict_types=1);
/**
 * 외부 건축자재 데이터 — 정규화·중복 제거·기존 상품 매칭·상담 카드 선택 도움말
 *
 * 책임: 이용이 허락된 출처(공공데이터 파일, 제조사가 제공한 카탈로그 등)의 상품 행을 같은 형식으로 정규화하고,
 *       같은 상품을 묶고, 우리 products 와 매칭 후보 점수를 계산한다. 상담 카드에 고르는 기준(쉬운 설명)을 붙인다.
 * 금지: 외부 가격을 우리 판매가로 쓰기(참고가로만 보관), products 자동 등록, 개인 연락처 저장,
 *       출처 권한 확인 없는 수집(scripts/external/import.php 가 data/external/sources.json 의 permission 으로 막음).
 * DB: external_products · external_product_matches (database/20261029_external_products.sql — 운영 미적용).
 *     테이블이 없으면 외부 지식 조회는 빈 배열 → 상담 카드는 상품 자체 속성만으로 동작(기존 동작 유지).
 */

/** HTML 엔티티·태그 제거, 공백 정리 */
function ext_clean_text(?string $v): string
{
    $v = html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $v));
}

/** 전화번호·이메일 제거(개인 연락처를 보관하지 않음). [제거 후 문자열, 제거 건수] */
function ext_scrub_pii(string $v): array
{
    $v = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '', $v, -1, $c1);
    $v = (string) preg_replace('/(?<!\d)(0\d{1,2})[-.\s]?\d{3,4}[-.\s]?\d{4}(?!\d)/u', '', $v, -1, $c2);
    $n = $c1 + $c2;
    return [trim((string) preg_replace('/\s{2,}/u', ' ', $v)), $n];
}

/** 판매 단위 정규화: ㎡ / 개 / 본 / m / 조 / 세트 / kg / 톤. 모르면 원문 */
function ext_unit(string $u): string
{
    $t = mb_strtolower(trim($u));
    return match (true) {
        $t === '' => '',
        (bool) preg_match('/^(m\s*2|㎡|m²|제곱미터|평방미터)$/u', $t) => '㎡',
        (bool) preg_match('/^(개|ea|매|장|pcs?)$/u', $t) => '개',
        (bool) preg_match('/^(본|개소)$/u', $t) => '본',
        (bool) preg_match('/^(m|미터|ｍ)$/u', $t) => 'm',
        (bool) preg_match('/^(조|set|세트)$/u', $t) => $t === '조' ? '조' : '세트',
        (bool) preg_match('/^(kg|킬로그램)$/u', $t) => 'kg',
        (bool) preg_match('/^(톤|ton|t)$/u', $t) => '톤',
        default => trim($u),
    };
}

/** 상품 종류(검색·매칭 기준). 앞쪽 규칙이 우선 */
function ext_product_type(string $text): string
{
    $rules = [
        '인조화강블록' => '/인조\s*화강/u', '점토블록' => '/점토\s*블[록럭]|점토\s*벽돌/u', '잔디블록' => '/잔디\s*블[록럭]/u',
        '점자블록' => '/점자\s*블[록럭]/u', '인터로킹블록' => '/인터로킹/u', '보차도블록' => '/보차도/u',
        '호안블록' => '/호안/u', '보강토블록' => '/보강토|옹벽\s*블[록럭]/u', '경계석' => '/경계\s*석|경계\s*블[록럭]/u',
        '그레이팅' => '/그레이팅|grating/iu', '맨홀' => '/맨홀|집수정/u', '수로관' => '/수로관|플륨|측구/u',
        '암거' => '/암거|box\s*culvert/iu', '흄관' => '/흄관|vr관/iu', 'PE관' => '/이중벽관|다중벽관|pe\s*관|hdpe|유공관/iu',
        'PVC관' => '/pvc/iu', '구조용블록' => '/속빈|조적|축조|식생/u', '보도블록' => '/보도\s*블[록럭]|블[록럭]/u',
    ];
    foreach ($rules as $type => $re) {
        if (preg_match($re, $text) === 1) {
            return $type;
        }
    }
    return '';
}

/** 재질 키. 표기가 없으면 '' (추측하지 않음) */
function ext_material(string $text): string
{
    $rules = [
        '인조화강' => '/인조\s*화강/u', '점토' => '/점토/u', '화강석' => '/화강석|자연석|판석/u', 'PE' => '/hdpe|(?<![a-z])pe(?![a-z])|폴리에틸렌|이중벽관|다중벽관/iu',
        'PVC' => '/pvc/iu', '주철' => '/주철|닥타일|덕타일|ductile/iu', '스틸' => '/스틸|steel|ss\s*275|sts|아연도/iu',
        '레진' => '/레진|frp/iu', '콘크리트' => '/콘크리트|흄관|pc\s*공법|시멘트/iu',
    ];
    foreach ($rules as $m => $re) {
        if (preg_match($re, $text) === 1) {
            return $m;
        }
    }
    return '';
}

/** 치수: 블록·판재 두께(80T 또는 가로*세로*두께), 관경(D300·Φ300·300A), 가로·세로(mm). 소수 미터 표기(0.8*0.8*2.0)는 mm 로 환산 */
function ext_dims(string $spec): array
{
    $r = ['width_mm' => null, 'length_mm' => null, 'thickness_mm' => null, 'diameter_mm' => null, 'dim3_mm' => null];
    $v = parse_spec_field($spec)['spec'] ?: $spec;
    $r['thickness_mm'] = ai_block_thickness($v);
    if ($r['thickness_mm'] === null && preg_match('/두께\s*(?:\(mm\))?\s*[:：]?\s*(\d{2,3})(?!\d)/u', $v, $m)) {   // 제조사 규격표 '두께 80'
        $r['thickness_mm'] = (int) $m[1];
    }
    if (preg_match('/(?:^|[^a-z])(?:d|Φ|ø|φ)\s*(\d{2,4})(?!\d)|(?<!\d)(\d{2,4})\s*(?:a|mm\s*관|파이)(?![a-z])/iu', $v, $m)) {
        $r['diameter_mm'] = (int) ($m[1] !== '' ? $m[1] : $m[2]);
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*[*x×]\s*(\d+(?:\.\d+)?)(?:\s*[*x×]\s*(\d+(?:\.\d+)?))?/u', $v, $m)) {
        $toMm = static fn(string $n): int => (int) round(str_contains($n, '.') && (float) $n < 10 ? (float) $n * 1000 : (float) $n);
        $r['width_mm']  = $toMm($m[1]);
        $r['length_mm'] = $toMm($m[2]);
        $r['dim3_mm']   = isset($m[3]) && $m[3] !== '' ? $toMm($m[3]) : null;   // 셋째 치수(암거 길이 등) — 매칭 비교용
    }
    return $r;
}

/** 투수 여부·하중 등급·용도·KS 표기 (표기된 것만) */
function ext_attrs(string $text): array
{
    $a = ['permeable' => null, 'load' => '', 'uses' => [], 'ks' => []];
    if (preg_match('/불\s*투수/u', $text)) {
        $a['permeable'] = false;
    } elseif (preg_match('/투수/u', $text)) {
        $a['permeable'] = true;
    }
    $a['load'] = match (true) {
        (bool) preg_match('/안전\s*중하중/u', $text) => '안전중하중',
        (bool) preg_match('/중하중/u', $text)       => '중하중',
        (bool) preg_match('/경하중/u', $text)       => '경하중',
        (bool) preg_match('/보행/u', $text)         => '보행',
        default => '',
    };
    foreach (['차도' => '/차도/u', '보도' => '/보도|인도/u', '주차장' => '/주차/u', '조경' => '/조경|정원|공원/u',
              '배수' => '/배수|우수|빗물/u', '하수' => '/하수|오수/u', '수로' => '/수로|측구/u'] as $u => $re) {
        if (preg_match($re, $text)) {
            $a['uses'][] = $u;
        }
    }
    if (preg_match_all('/KS\s*([A-Z])\s*-?\s*(\d{4})/u', $text, $m, PREG_SET_ORDER)) {
        $a['ks'] = array_values(array_unique(array_map(static fn($x) => "KS {$x[1]} {$x[2]}", $m)));
    }
    return $a;
}

/** 이름 비교용 키: 공백·괄호·기호 제거, 블럭→블록 */
function ext_name_key(string $name): string
{
    $k = mb_strtolower(str_replace('블럭', '블록', $name));
    return (string) preg_replace('/[\s()\[\]_\-·.,\/]+/u', '', $k);
}

/**
 * 원본 행 → 정규화 행 (순수 함수).
 * 입력 키: name, spec, maker, unit, price, external_id, source_url, category, material, use, note (없으면 빈 값)
 * 가격은 ref_price(참고가)로만 둔다 — 우리 판매가가 아니다.
 */
function ext_normalize(array $raw, string $sourceKey): array
{
    $pii = 0;
    $clean = static function ($v) use (&$pii): string {
        [$s, $n] = ext_scrub_pii(ext_clean_text(is_scalar($v) ? (string) $v : ''));
        $pii += $n;
        return $s;
    };
    // 원문 문장(ext_page_facts): 종류별 최대 5개·200자, 개인 연락처 제거
    $facts = [];
    foreach (is_array($raw['facts'] ?? null) ? array_intersect_key($raw['facts'], array_flip(['uses', 'features', 'cautions', 'certs', 'strength'])) : [] as $k => $list) {
        $list = array_values(array_filter(array_map(static fn($t) => mb_substr($clean($t), 0, 200), (array) $list)));
        if ($list) { $facts[$k] = array_slice($list, 0, 5); }
    }
    $name  = $clean($raw['name'] ?? '');
    $spec  = $clean($raw['spec'] ?? '');
    $cat   = $clean($raw['category'] ?? '');
    if (preg_match('/^\d+(?:\.\d+)?\s*[*x×]/u', $name) && $cat !== '') {   // 이름 칸에 치수만 있는 출처(예: '0.8*0.8*2.0_130T') → 분류명이 상품명
        [$name, $spec] = [$cat, trim("{$name} {$spec}")];
    }
    $maker = $clean($raw['maker'] ?? '');
    $extra = $clean(($raw['category'] ?? '') . ' ' . ($raw['material'] ?? '') . ' ' . ($raw['use'] ?? '') . ' ' . ($raw['note'] ?? ''));
    $all   = "{$name} {$spec} {$extra}";
    $unit  = ext_unit((string) (($raw['unit'] ?? '') !== '' ? $raw['unit'] : parse_spec_field($spec)['unit']));
    $price = preg_replace('/[^\d]/', '', (string) ($raw['price'] ?? ''));
    $dims  = ext_dims($spec);
    foreach (ext_dims($name) as $k => $v) {   // 규격 칸에 없는 치수는 이름에서
        $dims[$k] ??= $v;
    }
    $type  = ext_product_type("{$name} {$extra}");
    $n = [
        'source_key'   => $sourceKey,
        'external_id'  => mb_substr(trim((string) ($raw['external_id'] ?? '')), 0, 100),
        'name'         => mb_substr($name, 0, 200),
        'maker'        => mb_substr($maker, 0, 100),
        'product_type' => $type,
        'spec_raw'     => mb_substr($spec, 0, 500),
        'material'     => ext_material($all),
        'attrs'        => ext_attrs($all),
        'unit'         => $unit,
        'ref_price'    => ($price !== '' && (int) $price > 0) ? (int) $price : null,
        'source_url'   => mb_substr(trim((string) ($raw['source_url'] ?? '')), 0, 500),
        'pii_removed'  => $pii,
        'facts'        => $facts,   // 제조사 페이지 원문 문장(용도·특징·주의·인증·강도) — 원문 그대로. 중복 키에는 쓰지 않음
    ] + $dims;
    $n['dedup_key'] = sha1(implode('|', [ext_name_key($maker), $type, ext_name_key($name), $dims['width_mm'], $dims['length_mm'], $dims['dim3_mm'], $dims['thickness_mm'], $dims['diameter_mm'], ext_name_key($spec)]));
    return $n;
}

/** 같은 dedup_key 를 하나로 묶는다(빈 칸이 적은 행 우선). [unique 목록, 중복으로 합친 건수] */
function ext_dedup(array $rows): array
{
    $by = [];
    $filled = static fn(array $r): int => count(array_filter($r, static fn($v) => $v !== null && $v !== '' && $v !== []));
    foreach ($rows as $r) {
        $k = $r['dedup_key'];
        if (!isset($by[$k]) || $filled($r) > $filled($by[$k])) {
            $by[$k] = $r;
        }
    }
    return [array_values($by), count($rows) - count($by)];
}

/** 규격의 핵심 부분: '규격:' 값, 없으면 중량·단위·상차 같은 부가 표기 앞까지 */
function ext_spec_core(string $spec): string
{
    $core = parse_spec_field($spec)['spec'];
    return $core !== '' ? $core : trim((string) (preg_split('/\s*(?:\||중량|단위|상차|운반|설치)/u', $spec)[0] ?? ''));
}

/** 이름 속 모델·형식 표기(NS-03, DN형, i2형, U형 등) — 다르면 다른 상품 */
function ext_model_code(string $name): string
{
    preg_match_all('/[a-z]+\d*\s*형|[a-z]{1,4}-?\d{1,3}(?![\d*])/iu', $name, $m);
    return mb_strtolower(implode(' ', $m[0]));
}

/**
 * 외부 정규화 행과 우리 상품 1개의 매칭 점수 0~100 (순수 함수). 상품은 ext_normalize 로 같은 형식으로 만든 행.
 * 종류(30, 다르면 0점) + 이름(같으면 30, 아니면 유사도 20 이내, 모델 표기 다르면 -25) + 규격 문자열(같으면 15, 아니면 유사도 10 이내)
 * + 치수(두께·관경 10, 가로·세로·셋째 5 — 다르면 2배 감점) + 재질 3 + 투수 여부 2(반대면 -20).
 */
function ext_match_score(array $e, array $p): array
{
    $score = 0;
    $why = [];
    if ($e['product_type'] !== '' && $e['product_type'] === $p['product_type']) {
        $score += 30; $why[] = '종류';
    } elseif ($e['product_type'] !== '' && $p['product_type'] !== '') {
        return ['score' => 0, 'reasons' => '종류 다름'];
    }
    $en = ext_name_key($e['name']);
    $pn = ext_name_key($p['name']);
    if ($en === $pn) {
        $score += ($e['product_type'] === '' && $p['product_type'] === '') ? 50 : 30;   // 종류를 모르는 품목(규사 등)은 이름 일치를 더 믿는다
        $why[] = '이름';
    } else {
        similar_text($en, $pn, $pct);
        $score += (int) round(20 * $pct / 100);
        $em = ext_model_code($e['name']);
        $pm = ext_model_code($p['name']);
        if ($em !== '' && $pm !== '' && $em !== $pm) { $score -= 25; }
    }
    $es = ext_name_key(ext_spec_core($e['spec_raw']));
    $ps = ext_name_key(ext_spec_core($p['spec_raw']));
    if ($es !== '' && $es === $ps) {
        $score += 15; $why[] = '규격';
    } elseif ($es !== '' && $ps !== '') {
        similar_text($es, $ps, $spct);
        $score += (int) round(10 * $spct / 100);
    }
    foreach (['thickness_mm' => 10, 'diameter_mm' => 10, 'width_mm' => 5, 'length_mm' => 5, 'dim3_mm' => 5] as $k => $w) {
        if ($e[$k] === null || $p[$k] === null) { continue; }
        if ($e[$k] === $p[$k]) { $score += $w; }
        else { $score -= $w * 2; }
    }
    if ($e['material'] !== '' && $e['material'] === $p['material']) { $score += 3; }
    if ($e['attrs']['permeable'] !== null && $e['attrs']['permeable'] === $p['attrs']['permeable']) { $score += 2; }
    elseif ($e['attrs']['permeable'] !== null && $p['attrs']['permeable'] !== null) { $score -= 20; }
    return ['score' => max(0, min(100, $score)), 'reasons' => implode('·', $why)];
}

/** 우리 상품 행(products) → 매칭용 정규화 행 */
function ext_normalize_product(array $row): array
{
    $f = parse_spec_field((string) ($row['spec'] ?? ''));
    return ['id' => (int) $row['id']] + ext_normalize(['name' => $row['name'] ?? '', 'spec' => $row['spec'] ?? '', 'unit' => $f['unit']], 'products');
}

/** 매칭 후보 상위 $limit 개 (score >= 50). 판정: 80 이상 'probable', 50~79 'review'. 확정(confirmed)은 사람이 한다 */
function ext_best_matches(array $e, array $productsNorm, int $limit = 3): array
{
    $out = [];
    foreach ($productsNorm as $p) {
        $s = ext_match_score($e, $p);
        if ($s['score'] >= 50) {
            $out[] = ['product_id' => $p['id'], 'name' => $p['name'], 'spec' => $p['spec_raw'], 'score' => $s['score'], 'reasons' => $s['reasons'],
                      'grade' => $s['score'] >= 80 ? 'probable' : 'review'];
        }
    }
    usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}

/**
 * 상담 카드 '고르는 기준' 문구 (순수 함수) — 초보 고객이 추가 검색 없이 고를 수 있게 규격을 쉬운 말로.
 * 상품 자체 표기(이름·규격)에서 읽은 것과, 사람이 확정한 외부 자료($knowledge: 제조사·KS 표기, 출처명)만 쓴다.
 * 성능·강도 수치나 가격은 만들지 않는다. 최대 4개.
 */
function ext_selection_guide(array $productRow, array $knowledge = []): array
{
    $n = ext_normalize_product($productRow);
    $g = [];
    $paving = in_array($n['product_type'], ['인조화강블록', '점토블록', '인터로킹블록', '보차도블록', '보도블록', '잔디블록'], true);
    if ($paving && $n['thickness_mm'] !== null) {
        $g[] = match (true) {
            $n['thickness_mm'] >= 80 => "두께 {$n['thickness_mm']}mm — 차량이 올라가는 곳 두께 기준(사내 80mm 이상) 충족",
            $n['thickness_mm'] >= 60 => "두께 {$n['thickness_mm']}mm — 사람만 다니는 곳용(차량이 올라가면 80mm 이상)",
            default => "두께 {$n['thickness_mm']}mm",
        };
    }
    if ($n['attrs']['permeable'] === true) {
        $g[] = '투수형 — 빗물이 블록 사이·아래로 스며듦';
    } elseif ($n['attrs']['permeable'] === false) {
        $g[] = '불투수형 — 빗물이 스며들지 않음';
    }
    $g[] = match ($n['product_type']) {
        '인조화강블록' => '인조화강 — 화강석 느낌을 낸 콘크리트 블록',
        '점토블록'     => '점토 — 흙을 구워 만든 블록',
        '잔디블록'     => '잔디블록 — 구멍에 잔디를 심는 블록',
        '인터로킹블록' => '인터로킹 — 옆 블록과 서로 맞물리게 까는 블록',
        default        => '',
    };
    if ($n['product_type'] === 'PE관') {
        $g[] = preg_match('/유공/u', $productRow['name'] ?? '') ? '유공관 — 관에 구멍이 있어 땅속 물을 모아 빼는 용도'
            : (preg_match('/이중벽/u', $productRow['name'] ?? '') ? '이중벽관 — 바깥은 주름, 안쪽은 매끈한 관' : '');
    }
    if (preg_match('/\bKS\b/u', (string) ($productRow['name'] ?? '')) && !$n['attrs']['ks']) {
        $g[] = 'KS 표기 제품(상품명 기준)';
    }
    $g[] = match ($n['attrs']['load']) {
        '안전중하중' => '안전중하중 — 트럭·버스 등 대형차 통행',
        '중하중'     => '중하중 — 승용차·SUV 통행',
        default      => '',
    };
    foreach ($knowledge as $k) {
        $bits = array_filter([$k['maker'] ?? '', implode(', ', $k['ks'] ?? [])]);
        if ($bits) {
            $g[] = '참고 자료(판매 상품 아님): ' . implode(' · ', $bits) . ' — 출처 ' . ($k['source_name'] ?? $k['source_key'] ?? '');
            break;
        }
    }
    if ($n['unit'] === '㎡') {
        $g[] = '㎡(면적) 단위 판매 — 시공 면적만큼 주문';
    }
    return array_slice(array_values(array_filter($g)), 0, 4);
}

/** 외부 지식 테이블 존재 여부(요청당 1회). 운영 미적용이면 false → 외부 지식 없이 동작 */
function ext_tables_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $pdo = db();
        $ready = $pdo && $pdo->query("SHOW TABLES LIKE 'external_product_matches'")->fetchColumn() !== false;
    }
    return $ready;
}

/**
 * 상품별 확정(confirmed) 외부 자료 — 제조사·KS 표기·출처만(가격 제외). [product_id => [ {maker, ks, source_key}, ... ]]
 */
function ext_knowledge_for_products(array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$ids || !ext_tables_ready()) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT m.product_id, e.maker, e.attrs_json, e.source_key FROM external_product_matches m
                         JOIN external_products e ON e.id = m.external_product_id
                         WHERE m.status = 'confirmed' AND m.product_id IN ({$ph}) ORDER BY m.score DESC");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attrs = json_decode((string) $r['attrs_json'], true) ?: [];
        $out[(int) $r['product_id']][] = ['maker' => (string) $r['maker'], 'ks' => $attrs['ks'] ?? [], 'source_key' => (string) $r['source_key'],
                                          'source_name' => (string) ($attrs['source_name'] ?? $r['source_key'])];
    }
    return $out;
}

/** CSV 읽기(작은 파일·테스트용): ext_iter_table 결과를 배열로 */
function ext_read_csv(string $file): array
{
    return iterator_to_array(ext_iter_table($file), false);
}

/**
 * 표 파일(CSV·XLSX) 한 행씩 — 첫 비어 있지 않은 행을 머리글로 보고 표준 키(ext_csv_key)로 바꿔 돌려준다.
 * 큰 공공데이터 파일도 메모리를 일정하게 쓰도록 스트리밍. $headers 에 [원래 머리글 => 표준 키('' = 버림)] 기록.
 */
function ext_iter_table(string $file, ?array &$headers = null): Generator
{
    $head = null;
    foreach (preg_match('/\.xlsx$/i', $file) ? ext_iter_xlsx($file) : ext_iter_csv($file) as $r) {
        if ($head === null) {
            if (!array_filter($r, static fn($v) => trim((string) $v) !== '')) { continue; }
            $head = [];
            foreach ($r as $i => $h) { $head[$i] = ext_csv_key((string) $h); $headers[trim((string) $h)] = $head[$i]; }
            continue;
        }
        $row = [];
        foreach ($head as $i => $k) {
            if ($k !== '' && isset($r[$i]) && trim((string) $r[$i]) !== '') {
                $row[$k] = isset($row[$k]) ? $row[$k] . ' ' . $r[$i] : $r[$i];   // 같은 키로 모이는 열(규격명·세부규격 등)은 이어 붙임
            }
        }
        if ($row) { yield $row; }
    }
}

/** CSV 원시 행: UTF-8(BOM)·CP949 자동, 쉼표·탭 구분. CP949 는 칸 단위로 변환(구분자·따옴표 바이트와 겹치지 않음) */
function ext_iter_csv(string $file): Generator
{
    $fh = fopen($file, 'rb');
    if (!$fh) { throw new RuntimeException("파일을 열 수 없습니다: {$file}"); }
    $sample = (string) fread($fh, 65536);
    rewind($fh);
    if (str_starts_with($sample, "\xEF\xBB\xBF")) { fread($fh, 3); }
    $cut = strrpos($sample, "\n");
    $utf8 = mb_check_encoding($cut !== false ? substr($sample, 0, $cut) : $sample, 'UTF-8');
    $first = strtok($sample, "\n");
    $delim = substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',';
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null]) { continue; }
        yield $utf8 ? $r : array_map(static fn($v) => (string) mb_convert_encoding((string) $v, 'UTF-8', 'CP949'), $r);
    }
    fclose($fh);
}

/** XLSX 첫 시트 원시 행(공유 문자열·인라인 문자열 처리). 별도 라이브러리 없이 ZipArchive + XMLReader */
function ext_iter_xlsx(string $file): Generator
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
        throw new RuntimeException('XLSX 를 읽으려면 PHP zip·xmlreader 확장이 필요합니다 — 엑셀에서 CSV 로 저장해 주세요.');
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) { throw new RuntimeException("XLSX 파일을 열 수 없습니다: {$file}"); }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = new XMLReader();
        $x->XML($xml);
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') { $shared[] = trim($x->readString()); }
        }
        $x->close();
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) { throw new RuntimeException('XLSX 첫 시트(sheet1)를 찾지 못했습니다.'); }
    $x = new XMLReader();
    $x->XML($sheet);
    $col = static function (string $ref): int {
        $n = 0;
        foreach (str_split((string) preg_replace('/\d+/', '', $ref)) as $ch) { $n = $n * 26 + (ord(strtoupper($ch)) - 64); }
        return $n - 1;
    };
    while ($x->read()) {
        if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'row') { continue; }
        $node = $x->expand();
        $row = [];
        foreach ($node->getElementsByTagName('c') as $c) {
            $t = $c->getAttribute('t');
            $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
            $val = match ($t) {
                's'         => $shared[(int) $v] ?? '',
                'inlineStr' => $c->getElementsByTagName('t')->item(0)?->textContent ?? '',
                default     => $v,
            };
            $row[$col($c->getAttribute('r'))] = $val;
        }
        if ($row) {
            $max = max(array_keys($row));
            yield array_replace(array_fill(0, $max + 1, ''), $row);
        }
    }
    $x->close();
}

/** 머리글 → 표준 키 (모르는 열은 '' — 버림) */
function ext_csv_key(string $h): string
{
    $h = trim(str_replace(' ', '', $h));
    $map = [
        'name' => '/^(품명|상품명|물품명|제품명|품목명|세부품명|name)$/iu', 'spec' => '/^(규격|규격명|세부규격|모델|모델명|spec)$/iu',
        'maker' => '/^(제조사|제조사명|제조업체|제조업체명|업체명|계약업체명|공급업체명|브랜드|maker|brand)$/iu', 'price' => '/^(단가|계약단가|가격|판매가|공표가|price)$/iu',
        'unit' => '/^(단위|판매단위|unit)$/iu', 'external_id' => '/^(물품식별번호|식별번호|상품식별번호|상품번호|상품코드|제품코드|품목코드|id)$/iu',
        'category' => '/^(물품분류명|분류명|카테고리|category)$/iu', 'material' => '/^(재질|material)$/iu', 'use' => '/^(용도|사용처)$/u',
        'source_url' => '/^(url|상세url|링크)$/iu', 'note' => '/^(인증|인증정보|인증구분|인증명|ks|ks인증|비고)$/iu',
    ];
    foreach ($map as $k => $re) {
        if (preg_match($re, $h)) { return $k; }
    }
    return '';
}

/** JSON 배열 읽기. scrape-json: 기존 수집 스크립트(scripts/scrape-*.mjs) 결과 키(name·spec·brand·priceB2c·externalId·sourceUrl·categoryName·description) */
function ext_read_json(string $file, bool $scrape): array
{
    $d = json_decode((string) file_get_contents($file), true);
    $list = is_array($d) ? (array_is_list($d) ? $d : ($d['products'] ?? $d['items'] ?? [])) : [];
    if (!$scrape) { return $list; }
    return array_map(static fn($r) => [
        'name' => $r['name'] ?? '', 'spec' => $r['spec'] ?? '', 'maker' => $r['brand'] ?? '', 'unit' => $r['unit'] ?? '',
        'price' => $r['priceB2c'] ?? $r['price'] ?? '', 'external_id' => $r['externalId'] ?? '', 'source_url' => $r['sourceUrl'] ?? '',
        'category' => $r['categoryName'] ?? '', 'note' => $r['description'] ?? '',
    ], $list);
}

/**
 * 원문 문장이 설명하는 제품(순수 함수) — 문장 앞 '제품 이름 + 은/는'(예: '안전 중하중 그레이팅은 …'), 없으면 $fallback(행 이름).
 * 한 주제에 여러 제품 문장이 섞여 오면 GPT 가 다른 제품 특징을 옮겨 말하므로(안전 중하중의 작은 바퀴 → 중하중 전체) 문장마다 대상을 밝혀 준다.
 */
function ext_fact_subject(string $t, string $fallback): string
{
    return preg_match('/^\s*(?:\[[^\]]*\]\s*)?(.{1,30}?(그레이팅|블[록럭]|관|맨홀|경계석|제품|덮개|뚜껑|집수정|암거|트렌치))\s*(은|는)/u', $t, $m) ? trim($m[1]) : $fallback;
}

/**
 * 상담용 자재 참고 자료 — 사람이 승인한(review_status='approved') 외부 자료의 원문 문장만, 출처와 함께.
 * 주제(예: '인터로킹 블록', '투수블록')의 종류·단어로 찾아 종류별(용도·특징·주의·인증·강도) 최대 3문장. 가격·판매 여부는 넣지 않는다.
 * 테이블이 없거나 승인 자료가 없으면 빈 목록(상담은 기존처럼 일반 설명만).
 */
function ext_material_info(string $topic): array
{
    $none = ['topic' => $topic, 'facts' => [], 'sources' => [], 'note' => '승인된 참고 자료가 없습니다. 답 첫머리에 제조사 참고 자료가 없어 일반적인 설명이라고 밝히고, 수치 없이 확실한 일반 설명만 하세요. 확실하지 않은 제품의 구조·관경·용도는 지어내지 말고 정확한 사양은 제조사 자료 확인이 필요하다고 안내하세요. 상품·가격은 search_products 결과(토목몰)로만 안내하고, 답에 도구 이름은 쓰지 마세요.'];
    if (trim($topic) === '' || !ext_tables_ready()) { return $none; }
    $type = ext_product_type($topic);
    $words = array_values(array_filter(preg_split('/[\s,·]+/u', (string) preg_replace('/블럭/u', '블록', $topic)) ?: [], static fn($w) => mb_strlen($w) >= 2 && !preg_match('/^(차이|종류|어떤|무엇|추천|좋은|제품|자재)$/u', $w)));
    $st = db()->query("SELECT id, name, maker, product_type, attrs_json, source_url, source_key, fetched_at FROM external_products WHERE review_status = 'approved' AND attrs_json LIKE '%\"facts\"%' LIMIT 2000");
    $scored = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $facts = (array) ((json_decode((string) $r['attrs_json'], true) ?: [])['facts'] ?? []);   // JSON 키(permeable 등)가 아니라 이름·종류·원문 문장에서만 찾음
        $hay = str_replace('블럭', '블록', $r['name'] . ' ' . $r['product_type'] . ' ' . implode(' ', array_merge(...array_values(array_map('array_values', $facts)) ?: [[]])));
        $paving = ['인조화강블록', '보도블록', '인터로킹블록', '보차도블록', '점토블록'];   // 바닥 포장 블록끼리는 가까운 종류(제조사가 '보도블록'으로만 표기한 행도 찾음)
        $typeScore = $type !== '' && $r['product_type'] === $type ? 5 : ($type !== '' && in_array($type, $paving, true) && in_array($r['product_type'], $paving, true) ? 2 : 0);
        // 주제가 자재 종류를 말하면 다른 종류 행은 행 이름에 주제 낱말이 있을 때만(원문 문장의 '방지'·'규격'만 겹친 그레이팅 자료가 보도블록·흄관 답의 출처로 섞이지 않게)
        if ($type !== '' && $typeScore === 0 && !array_filter($words, static fn($w) => mb_stripos(str_replace('블럭', '블록', $r['name']), $w) !== false)) { continue; }
        $score = $typeScore + count(array_filter($words, static fn($w) => mb_stripos($hay, $w) !== false));
        if ($score > 0) { $scored[] = [$score, $r]; }
    }
    if (!$scored) { return $none; }
    usort($scored, static fn($a, $b) => $b[0] <=> $a[0]);
    $labels = ['uses' => '용도·시공', 'features' => '특징', 'cautions' => '주의', 'certs' => '인증', 'strength' => '강도'];
    // 문장 순서: 질문 낱말(유모차·휠체어 등)이 든 문장 먼저 → 행 점수 순. 종류별 3개·전체 12개
    $cand = [];
    foreach (array_slice($scored, 0, 40) as $ri => [$rs, $r]) {
        $a = json_decode((string) $r['attrs_json'], true) ?: [];
        foreach ($labels as $k => $label) {
            foreach ((array) ($a['facts'][$k] ?? []) as $t) {
                $hit = count(array_filter($words, static fn($w) => mb_stripos(str_replace('블럭', '블록', (string) $t), $w) !== false));
                $cand[] = [$hit, $rs, count($cand), $k, $label, (string) $t, $r, $a];
            }
        }
    }
    usort($cand, static fn($x, $y) => [$y[0], $y[1], $x[2]] <=> [$x[0], $x[1], $y[2]]);
    $facts = $seen = $sources = [];
    $per = [];
    foreach ($cand as [, , , $k, $label, $t, $r, $a]) {
        if (isset($seen[$t]) || ($per[$k] ?? 0) >= 3) { continue; }
        $seen[$t] = true;
        $per[$k] = ($per[$k] ?? 0) + 1;
        $facts[] = ['kind' => $label, 'text' => $t, 'about' => ext_fact_subject($t, (string) $r['name']), 'maker' => (string) $r['maker'], 'product' => (string) $r['name'], 'source_url' => (string) $r['source_url'], 'fetched_at' => substr((string) $r['fetched_at'], 0, 10)];
        $sources[$r['source_url'] ?: $r['source_key']] = ['maker' => (string) ($r['maker'] ?: ($a['source_name'] ?? $r['source_key'])), 'url' => (string) $r['source_url']];
        if (count($facts) >= 12) { break; }
    }
    return ['topic' => $topic, 'facts' => $facts, 'sources' => array_values($sources),
            'note' => '제조사 공개 자료 원문(사람이 승인한 것만) — 판매 상품·가격이 아님. 출처끼리 다르면 단정하지 말고 둘 다 출처와 함께 전하세요. '
                . '각 문장은 about(그 문장이 설명하는 제품)의 설명으로만 쓰고 다른 제품에 옮기지 마세요. about 이 고객이 물은 제품과 다르면 물은 제품의 설명에 섞지 말고 about 이름을 밝혀 따로 적으세요. '
                . 'KS·인증은 그 제품의 표기로만 전하고 \'이 규격을 쓰면 된다\'고 권하지 마세요. '
                . '토목몰 판매 상품이 궁금하면 찾아 드릴 수 있다고 안내하세요(답에 도구 이름은 쓰지 않고, 고객에게 직접 검색하라고 하지 않음).'];
}
