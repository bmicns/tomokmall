<?php
declare(strict_types=1);

// 프로덕션: PHP 에러가 출력에 섞여 JSON 파싱을 깨지 않도록 억제
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// E_STRICT 는 PHP 8.0 부터 발생하지 않고 8.4 에서 상수 자체가 사용 중단(요청마다 Deprecated 로그) — 빼도 동작 같음
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

// 세션 쿠키 보안: SameSite=Lax(교차사이트 POST=CSRF 차단) + HttpOnly(JS 접근 차단).
// Secure는 HTTPS일 때만(현재 dothome는 http라 강제 시 세션 유실 → 자동 감지). SSL 적용되면 자동 보안.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

/**
 * .env 파일을 읽어 환경변수로 주입(이미 설정된 값은 덮지 않음).
 * 닷홈처럼 쉘 env를 못 쓰는 환경에서 시크릿을 코드 밖으로 빼기 위함.
 * KEY=VALUE 형식, # 주석·따옴표 처리. 시크릿은 웹루트 밖(/app/.env)에 둔다.
 */
function load_env_file(string $file): void
{
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($key === '') {
            continue;
        }
        // 양끝 따옴표 제거
        $len = strlen($val);
        if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$val}");
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        } elseif (getenv($key) !== $val) {
            // 셸·서버 환경변수가 이미 있어 파일 값이 적용되지 않음 → 진단용으로 키 이름만 기록(값은 기록하지 않음)
            $GLOBALS['env_shadowed_keys'][$key] = true;
        }
    }
}

/**
 * 파일(.env / .env.local) 값 대신 기존 환경변수가 적용된 키 이름 목록(값 없음). DB 접속 실패 진단용.
 * @return string[]
 */
function env_shadowed_keys(string $prefix = ''): array
{
    $keys = array_keys($GLOBALS['env_shadowed_keys'] ?? []);
    return array_values(array_filter($keys, static fn($k) => $prefix === '' || strpos($k, $prefix) === 0));
}

/**
 * DB 접속 실패 원인 안내(서버 로그용). MySQL/MariaDB 오류 번호 → 점검할 항목. 인증정보는 포함하지 않는다.
 */
function db_connect_error_hint(int $code): string
{
    switch ($code) {
        case 1045: return '계정·비밀번호 불일치(DB_USER/DB_PASS)';
        case 1044: return '해당 DB 권한 없음(DB_USER 권한·DB_NAME)';
        case 1049: return 'DB 없음(DB_NAME)';
        case 2002: return 'DB 서버 접속 불가(DB_HOST·MySQL/MariaDB 실행 여부)';
        case 2005: return 'DB 호스트 이름을 찾을 수 없음(DB_HOST)';
        default:   return '접속 설정(DB_HOST/DB_NAME/DB_USER/DB_PASS) 확인';
    }
}

// 시크릿 로드: 운영은 /app/.env, 로컬 개발은 프로젝트 루트 .env.local
load_env_file(__DIR__ . '/.env');
load_env_file(__DIR__ . '/../.env.local');

$config = require __DIR__ . '/config.php';

// 검색어 오타 교정 유틸(자모 편집거리) — products.php·ai_tools.php에서 사용
require_once __DIR__ . '/search_correct.php';

// 화물 운임 견적 엔진 + 제품군별 출하 권역 매핑
require_once __DIR__ . '/freight.php';
require_once __DIR__ . '/freight-origins.php';

// 회원 등급별 차등 가격 유틸(계산·표시)
require_once __DIR__ . '/pricing.php';

// 공급업체(vendor) 표시 유틸
require_once __DIR__ . '/vendors.php';

// 상품 규격 구조화(spec_code·dim_* + category_spec_axes): 목록 표·필터의 축별 규격
require_once __DIR__ . '/spec_axes.php';

function db(): ?PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (\PDOException $e) {
        // 로컬 개발 환경: DB 없을 때 빈 stub PDO 대신 null 반환.
        // 원인은 서버 로그에만 남긴다(화면에는 일반 메시지). 오류 번호·안내·덮어쓴 키 이름만 — 계정·비밀번호는 기록하지 않음.
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            $code = (int) $m[1];
        }
        static $logged = false; // db()는 실패 시 매번 재시도되므로 요청당 1회만 기록
        if ($logged) {
            return null;
        }
        $logged   = true;
        $shadowed = env_shadowed_keys('DB_');
        error_log('[db] 접속 실패 (오류 ' . ($code ?: '?') . '): ' . db_connect_error_hint($code)
            . ($shadowed ? ' / .env 파일 대신 셸·서버 환경변수가 적용된 키: ' . implode(', ', $shadowed) : ''));
        return null;
    }

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $value): string
{
    return number_format($value) . '원';
}

function cart_items(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(array_map('intval', cart_items()));
}

function redirect(string $path): never
{
    header("Location: {$path}");
    exit;
}

function fetch_categories(): array
{
    $rows = db()->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY parent_id IS NOT NULL, sort_order, name')->fetchAll();
    $hide = category_empty_hidden_ids();
    return $hide ? array_values(array_filter($rows, static fn($c) => !isset($hide[(int) $c['id']]))) : $rows;
}

/**
 * 화면에서 숨길 빈 카테고리: 상용콘크리트 반영 카테고리(slug 'syc-…') 중 자신·하위 품목에 활성 상품이 하나도 없는 것.
 * DB 의 카테고리 행·활성 여부는 그대로 두고(사용자 결정 2026-11-12) 메뉴·빠른 견적·검색 추천에서만 뺀다 —
 * 실제 데이터로 상품이 들어오면 자동으로 다시 보인다. 케이토목 카테고리는 대상 아님(기존 화면 그대로).
 * 예외: CATEGORY_EMPTY_SHOW_ROOT_IDS 대분류 아래는 상품이 없어도 보인다(사용자 결정 2026-11-12 — PE/PVC 먼저,
 *       이어서 맨홀 안전 사다리·그레이팅 등 카탈로그 품목도 보이게 해 상용콘크리트 대분류 4개 모두). 대분류를 빼면 다시 숨김.
 * @return array<int,true> [category_id => true] (요청당 1회)
 */
const CATEGORY_EMPTY_SHOW_ROOT_IDS = [11, 12, 13, 15];   // 철근콘크리트·콘크리트·스틸·PE/PVC제품: 상품 없는 카탈로그 품목도 메뉴에 보임

function category_empty_hidden_ids(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $ids = [];
    if (!db()) { return $ids; }
    try {
        $keep = implode(',', array_map('intval', CATEGORY_EMPTY_SHOW_ROOT_IDS)) ?: '0';
        foreach (db()->query("SELECT c.id FROM categories c
                                LEFT JOIN categories pa ON pa.id = c.parent_id
                               WHERE c.is_active = 1 AND c.slug LIKE 'syc-%'
                                 AND NOT (c.parent_id IN ({$keep}) OR pa.parent_id IN ({$keep}))
                                 AND NOT EXISTS (SELECT 1 FROM products p JOIN categories pc ON pc.id = p.category_id
                                                  WHERE p.is_active = 1 AND (pc.id = c.id OR pc.parent_id = c.id))")->fetchAll() as $r) {
            $ids[(int) $r['id']] = true;
        }
    } catch (\Throwable $e) {
        $ids = [];
    }
    return $ids;
}

function fetch_categories_with_thumb(): array
{
    if (!db()) return [];
    try {
        return db()->query(
            'SELECT c.id, c.name, c.slug, c.sort_order, c.description,
                (SELECT p.image_url FROM products p
                 WHERE p.category_id = c.id AND p.is_active = 1 AND p.image_url IS NOT NULL
                 ORDER BY p.id LIMIT 1) AS image_url,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
             FROM categories c
             WHERE c.is_active = 1 AND c.parent_id IS NULL
             ORDER BY c.sort_order, c.id'
        )->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

function fetch_products(?int $categoryId = null, ?string $query = null, string $filterSql = '', array $filterParams = [], string $sortSql = ''): array
{
    $sql = 'SELECT p.*, c.name AS category_name, ' . vendor_names_subquery() . ' AS vendor_names
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1';
    $params = [];

    if ($categoryId) {
        // 해당 카테고리 및 모든 하위(임의 깊이) 카테고리 상품 포함
        $ids = category_descendants($categoryId);
        $ph = [];
        foreach (array_values($ids) as $i => $cid) { $k = ":c{$i}"; $ph[] = $k; $params[$k] = $cid; }
        $sql .= ' AND p.category_id IN (' . implode(',', $ph) . ')';
    }

    if ($query) {
        $sql .= ' AND (p.name LIKE :q1 OR p.brand LIKE :q2 OR p.spec LIKE :q3 OR p.description LIKE :q4)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $query . '%';
    }

    // 목록 필터(재질·폭·형상·하중) 주입
    if ($filterSql !== '') {
        $sql   .= $filterSql;
        $params = array_merge($params, $filterParams);
    }

    $sql .= ' ORDER BY ' . ($sortSql !== '' ? $sortSql : 'p.id DESC');

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 검색 동의어 사전 로드 → [소문자단어 => [그룹 전체 멤버]] 맵 (1회 캐시)
 */
function load_synonyms(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map  = [];
    $file = __DIR__ . '/synonyms.php';
    if (is_file($file)) {
        $data = require $file;
        if (is_array($data)) {
            // 신 구조(groups/aliases) 또는 구 구조(평면 그룹 배열) 모두 지원
            $groups  = $data['groups']  ?? (isset($data[0]) ? $data : []);
            $aliases = $data['aliases'] ?? [];

            // 대칭 그룹: 모든 멤버가 서로를 확장
            foreach ($groups as $group) {
                if (!is_array($group) || count($group) < 2) {
                    continue;
                }
                foreach ($group as $term) {
                    $key = mb_strtolower(trim((string) $term));
                    if ($key === '') {
                        continue;
                    }
                    $map[$key] = array_values(array_unique(array_merge($map[$key] ?? [], $group)));
                }
            }

            // 단방향 별칭: key 로 검색할 때만 values 로 확장 (values→key 역확장 없음)
            foreach ($aliases as $aliasKey => $targets) {
                if (!is_array($targets) || $targets === []) {
                    continue;
                }
                $key = mb_strtolower(trim((string) $aliasKey));
                if ($key === '') {
                    continue;
                }
                $map[$key] = array_values(array_unique(array_merge($map[$key] ?? [$aliasKey], [$aliasKey], $targets)));
            }
        }
    }
    return $map;
}

/**
 * 단방향 별칭 원본 맵 반환 (소문자 key → 확장 대상 배열). 부분일치(복합어)용.
 */
function load_aliases(): array
{
    static $aliases = null;
    if ($aliases !== null) {
        return $aliases;
    }
    $aliases = [];
    $file = __DIR__ . '/synonyms.php';
    if (is_file($file)) {
        $data = require $file;
        foreach (($data['aliases'] ?? []) as $k => $targets) {
            $key = mb_strtolower(trim((string) $k));
            if ($key !== '' && is_array($targets) && $targets) {
                $aliases[$key] = $targets;
            }
        }
    }
    return $aliases;
}

/**
 * 복합어 분해 사전 (긴 용어 먼저 매칭하도록 길이 내림차순). 1회 캐시.
 */
function load_segments(): array
{
    static $segs = null;
    if ($segs !== null) {
        return $segs;
    }
    $segs = [];
    $file = __DIR__ . '/synonyms.php';
    if (is_file($file)) {
        $data = require $file;
        $segs = array_values(array_unique(array_filter(array_map(
            static fn($s) => trim((string) $s),
            $data['segments'] ?? []
        ))));
        usort($segs, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    }
    return $segs;
}

/**
 * 복합어를 카탈로그 용어들로 분해(완전히 덮일 때만). 예: 'PE배수관' → ['PE','배수관'].
 * 일부만 덮이면 분해 실패로 보고 원본 토큰 1개를 그대로 반환(오분해 방지).
 */
function segment_compound(string $token): array
{
    $n = mb_strlen($token);
    if ($n < 4) {
        return [$token]; // 짧은 토큰은 분해하지 않음
    }
    $segs = load_segments();
    $out  = [];
    $i    = 0;
    while ($i < $n) {
        $matched = null;
        foreach ($segs as $d) {
            $dl = mb_strlen($d);
            if ($dl > 0 && $dl <= $n - $i
                && mb_strtolower(mb_substr($token, $i, $dl)) === mb_strtolower($d)) {
                $matched = mb_substr($token, $i, $dl);
                $i      += $dl;
                $out[]   = $matched;
                break;
            }
        }
        if ($matched === null) {
            return [$token]; // 남는 글자가 사전에 없음 → 분해 실패, 원본 유지
        }
    }
    return count($out) >= 2 ? $out : [$token];
}

/**
 * 검색 토큰 1개 → 확장 멤버 목록(자기 포함).
 * 1) 정확 일치 동의어/별칭  2) 별칭 키가 토큰에 포함된 복합어(예: 'PE배수관' ⊃ '배수관')
 */
function expand_search_token(string $token): array
{
    $syn = load_synonyms();
    $kl  = mb_strtolower($token);
    $members = $syn[$kl] ?? [$token];
    foreach (load_aliases() as $ak => $targets) {
        if ($ak !== $kl && mb_strpos($kl, $ak) !== false) {
            $members = array_merge($members, [$token], $targets);
        }
    }
    return array_values(array_unique($members));
}

/**
 * 선택한 루트 카테고리(재질) 하위의 모든 카테고리 ID (자기 포함).
 * 상품의 category_id를 루트까지 거슬러 매칭. 카테고리 트리는 1회 캐시.
 */
/**
 * 주어진 카테고리의 모든 하위(임의 깊이) + 자기 자신 ID 목록. 자식맵 1회 캐시.
 */
function category_descendants(int $id): array
{
    static $children = null;
    if ($children === null) {
        $children = [];
        if (db()) {
            foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
                if ($c['parent_id'] !== null) { $children[(int) $c['parent_id']][] = (int) $c['id']; }
            }
        }
    }
    $out = [$id]; $stack = [$id]; $seen = [$id => 1];
    while ($stack) {
        $cur = array_pop($stack);
        foreach ($children[$cur] ?? [] as $ch) {
            if (!isset($seen[$ch])) { $seen[$ch] = 1; $out[] = $ch; $stack[] = $ch; }
        }
    }
    return $out;
}

function category_ids_under_roots(array $roots): array
{
    static $par = null;
    if ($par === null) {
        $par = [];
        if (db()) {
            foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
                $par[(int) $c['id']] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
            }
        }
    }
    $rootset = array_flip(array_map('intval', $roots));
    $out = [];
    foreach ($par as $id => $_) {
        $r = $id; $seen = [];
        while ($par[$r] !== null && !isset($seen[$r])) { $seen[$r] = 1; $r = $par[$r]; }
        if (isset($rootset[$r])) { $out[] = $id; }
    }
    return $out;
}

/**
 * 카테고리 id → 최상위 루트 id (부모 체인 끝). 없거나 미존재면 null.
 */
function category_root_of(int $id): ?int
{
    static $par = null;
    if ($par === null) {
        $par = [];
        if (db()) {
            foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
                $par[(int) $c['id']] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
            }
        }
    }
    if (!array_key_exists($id, $par)) { return null; }
    $r = $id; $seen = [];
    while ($par[$r] !== null && !isset($seen[$r])) { $seen[$r] = 1; $r = $par[$r]; }
    return $r;
}

/**
 * 카테고리 표시 경로: 최상위부터 자기까지 이름을 ' › '로 연결 (임의 깊이, 부모 순환·누락 시 거기서 멈춤).
 * 최상위 이름의 '제품' 접미사는 GNB 표시(layout.php)와 같이 뗀다. 예) '철근콘크리트 › 수로관 › U형수로관'
 *
 * @param array<int|string, array{parent_id:int|string|null, name:string}> $rows categories 전체(id => 행)
 */
function category_path_label(int $id, array $rows): string
{
    if (!isset($rows[$id])) { return ''; }
    $names = []; $seen = []; $cur = $id; $isRoot = false;
    while (isset($rows[$cur]) && !isset($seen[$cur])) {
        $seen[$cur] = 1;
        $names[] = (string) $rows[$cur]['name'];
        $pid = $rows[$cur]['parent_id'];
        if ($pid === null) { $isRoot = true; break; }
        $cur = (int) $pid;
    }
    if ($isRoot) {
        $last = count($names) - 1;
        $names[$last] = (string) preg_replace('/제품$/u', '', $names[$last]);
    }
    return implode(' › ', array_reverse($names));
}

/**
 * 규격 종류 수: spec의 '규격:' 값(parse_spec_field) 중 비어 있지 않은 서로 다른 값의 개수.
 * 상품 목록의 규격 필터(product_facets)와 같은 기준 — spec 전체 문자열(중량·운반비 포함)로 세지 않는다.
 *
 * @param list<string|null> $specs
 */
function spec_variant_count(array $specs): int
{
    $set = [];
    foreach ($specs as $spec) {
        $v = parse_spec_field((string) $spec)['spec'];
        if ($v !== '') { $set[$v] = 1; }
    }
    return count($set);
}

/**
 * 동적 파세트: 현재 검색/카테고리 결과 집합(필터 미적용)에서 각 필터 옵션의 매칭 개수를 계산.
 *   - 재질(카테고리 루트)·형상/옵션(상품명)·하중(상품명)·규격(spec의 '규격:' 값) — product_filter_sql과 동일 기준.
 *   - 0건 옵션·단일값 수렴 그룹은 렌더러(product_filters_visible)가 숨긴다.
 * 반환: ['mat'=>[루트id=>cnt], 'shape'=>[명=>cnt], 'load'=>[명=>cnt], 'spec'=>[규격값=>cnt], 'total'=>int,
 *        'axes'=>[] 또는 축 모드 정보(아래)]
 *   축 모드: 결과의 카테고리가 모두 같은 규격 이름표(category_spec_axes)를 쓸 때만.
 *     'axes' => ['defs'=>이름표 목록, 'vals'=>[축키=>[값=>cnt]], 'all'=>[축키=>[값…]](필터 무관 전체 값),
 *                'type'=>[모델 코드 값=>cnt], 'type_all'=>[값…], 'code_key'=>'type'(끝 글자 B·C)|'code'(코드 전체 K2·BS),
 *                'combos'=>['keys'=>[축키…], 'rows'=>[[값…, 재고1/0]…]] 또는 null(축 외 적용 필터만 반영한 값 조합)]
 *                — 개수는 다른 필터와 같은 '자기 축 제외' 기준
 */
function product_facets(?int $categoryId, ?string $query, array $active = []): array
{
    $empty = ['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 0, 'axes' => []];
    if (!db()) { return $empty; }

    // base 결과(필터 미적용)의 경량 컬럼만. 검색은 search_products 재사용(동의어·복합어·규격 매칭 일치).
    $rows = [];
    if ($categoryId) {
        try {
            $ids = category_descendants($categoryId);
            if (!$ids) { return $empty; }
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $axCols = spec_axes_ready() ? ', ' . implode(', ', array_merge(SPEC_AXES_NEW_COLUMNS, spec_axes_extra_ready() ? SPEC_AXES_EXTRA_COLUMNS : [])) : '';
            $st  = db()->prepare("SELECT id, category_id, name, spec, stock_qty{$axCols} FROM products WHERE is_active = 1 AND category_id IN ($ph)");
            $st->execute(array_values($ids));
            $rows = $st->fetchAll();
        } catch (\Throwable $e) { return $empty; }
    } elseif ($query !== null && $query !== '') {
        $t = 0;
        try { $rows = search_products($query, 5000, 0, $t, '', [], ''); } catch (\Throwable $e) { return $empty; }
    } else {
        return $empty;
    }
    if (!$rows) { return $empty; }

    $shapes = ['사각', '원형', 'U형', '유공', '앵글부착', '인터로킹'];
    $loads  = ['중하중', '안전중하중'];
    $matCnt = [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0, 16 => 0];
    $shCnt  = array_fill_keys($shapes, 0);
    $ldCnt  = array_fill_keys($loads, 0);
    $specCnt = [];  // 규격값 => 개수 (spec의 '규격:' 항목 기준)

    // 활성 필터: 각 축 개수는 '자기 축을 제외한' 나머지 필터를 통과한 상품만 집계한다.
    // (그래야 규격 선택 후 보이는 형상 개수가 규격과 교집합 기준 → 눌러도 0건이 안 됨)
    $selMat   = array_map('intval', (array) ($active['mat']   ?? []));
    $selSpec  = array_map('strval', (array) ($active['spec']  ?? []));
    $selShape = array_map('strval', (array) ($active['shape'] ?? []));
    $selLoad  = array_map('strval', (array) ($active['load']  ?? []));
    $nameHas  = static function (string $name, array $sel): bool {
        if (!$sel) { return true; }
        foreach ($sel as $s) { if ($s !== '' && mb_strpos($name, $s) !== false) { return true; } }
        return false;
    };

    // 재고 조건은 축이 아니라 전제 조건 → 재고 없는 상품은 모든 축 개수에서 제외
    $inStockOnly = !empty($active['instock']);

    // 규격 축(spec_code·dim_*): 결과 카테고리가 모두 같은 이름표일 때만 축 모드(섞이면 기존 규격 원문 필터).
    // 선택된 축 값은 모드와 관계없이 SQL(spec_axes_filter_sql)과 같은 기준으로 개수에 반영한다.
    $axFull  = spec_axes_ready() ? spec_axes_common(array_map('intval', array_column($rows, 'category_id'))) : [];
    $codeKey = $axFull ? spec_axes_code_key($axFull) : SPEC_AXIS_TYPE;   // 모델 코드 필터: 끝 글자(B·C) 또는 코드 전체(K2·BS·#377)
    $axDefs  = array_values(array_filter($axFull, static fn($a) => $a['axis_key'] !== SPEC_AXIS_SKIP));   // skip(저장 안 하는 숫자 자리)은 화면에 없음
    $axKeys  = array_column($axDefs, 'axis_key');
    if ($axDefs) { $axKeys[] = $codeKey; }
    $selAx = [];
    if (spec_axes_ready()) {   // 새 칸이 없으면 SQL 도 축 조건을 무시하므로 개수에도 넣지 않는다
        foreach ((array) ($active['ax'] ?? []) as $k => $vals) { if ($vals) { $selAx[(string) $k] = array_map('strval', (array) $vals); } }
        if (!empty($active['ax_type'])) { $selAx[SPEC_AXIS_TYPE] = array_map('strval', (array) $active['ax_type']); }
        if (!empty($active['ax_code'])) { $selAx[SPEC_AXIS_CODE] = array_map('strval', (array) $active['ax_code']); }
    }
    $axCnt = array_fill_keys($axKeys, []);   // 축 => [값 => 개수] (자기 축 제외 기준)
    $axAll = array_fill_keys($axKeys, []);   // 축 => [값 => true] (필터·재고 무관 전체 — 값이 하나뿐인 축 판단용)
    $axUse = array_values(array_unique(array_merge($axKeys, array_keys($selAx))));

    $matched = 0;
    $combos  = [];   // 축 값 조합(+재고 여부) — 화면에서 값 버튼을 누르는 즉시 0건 값을 비활성할 때 씀(재고 체크·축 선택은 화면 쪽에서 계산)
    foreach ($rows as $p) {
        $axv = [];
        foreach ($axUse as $k) { $axv[$k] = spec_axes_row_value($p, $k); }
        foreach ($axKeys as $k) { if ($axv[$k] !== null) { $axAll[$k][$axv[$k]] = true; } }

        $name = (string) ($p['name'] ?? '');
        $spec = (string) ($p['spec'] ?? '');
        $root = category_root_of((int) ($p['category_id'] ?? 0));
        $sv   = parse_spec_field($spec)['spec'];

        $mMat   = !$selMat  || ($root !== null && in_array($root, $selMat, true));
        $mSpec  = !$selSpec || ($sv !== '' && in_array($sv, $selSpec, true));
        $mShape = $nameHas($name, $selShape);
        $mLoad  = $nameHas($name, $selLoad);
        $hasStock = (int) ($p['stock_qty'] ?? 0) > 0;
        if ($axKeys && $mMat && $mSpec && $mShape && $mLoad) {
            $tuple = [];
            foreach ($axKeys as $k) { $tuple[] = $axv[$k] ?? ''; }
            $tuple[] = $hasStock ? 1 : 0;
            $combos[implode("\x1f", $tuple)] = $tuple;
        }

        if ($inStockOnly && !$hasStock) { continue; }
        $mAx    = [];
        foreach ($selAx as $k => $sel) { $mAx[$k] = $axv[$k] !== null && in_array($axv[$k], $sel, true); }
        $mAxAll = !in_array(false, $mAx, true);

        if ($mSpec && $mShape && $mLoad && $mAxAll && $root !== null && isset($matCnt[$root])) { $matCnt[$root]++; }
        if ($mMat && $mShape && $mLoad && $mAxAll && $sv !== '') { $specCnt[$sv] = ($specCnt[$sv] ?? 0) + 1; }
        if ($mMat && $mSpec && $mLoad && $mAxAll) { foreach ($shapes as $s) { if (mb_strpos($name, $s) !== false) { $shCnt[$s]++; } } }
        if ($mMat && $mSpec && $mShape && $mAxAll) { foreach ($loads as $s) { if (mb_strpos($name, $s) !== false) { $ldCnt[$s]++; } } }
        if ($mMat && $mSpec && $mShape && $mLoad) {
            foreach ($axKeys as $k) {
                if ($axv[$k] === null) { continue; }
                $others = $mAx; unset($others[$k]);
                if (!in_array(false, $others, true)) { $axCnt[$k][$axv[$k]] = ($axCnt[$k][$axv[$k]] ?? 0) + 1; }
            }
            if ($mAxAll) { $matched++; }
        }
    }
    // 규격 옵션: 자연 정렬(숫자 인식)로 사람이 읽기 좋게
    uksort($specCnt, static fn($a, $b) => strnatcasecmp((string) $a, (string) $b));

    $axes = [];
    if ($axDefs) {
        $byNum  = static fn($a, $b) => (float) $a <=> (float) $b;
        $keysOf = static function (array $set, ?callable $cmp): array {
            $ks = array_map('strval', array_keys($set));
            $cmp ? usort($ks, $cmp) : sort($ks, SORT_STRING);
            return $ks;
        };
        $vals = []; $all = [];
        foreach (array_column($axDefs, 'axis_key') as $k) {
            $all[$k]  = $keysOf($axAll[$k], in_array($k, SPEC_AXIS_TEXT, true) ? 'strnatcmp' : $byNum);   // 글자 값(VG150·DC200)은 자연 정렬
            $vals[$k] = [];
            foreach ($all[$k] as $v) { $vals[$k][$v] = (int) ($axCnt[$k][$v] ?? 0); }
        }
        $typeAll = $keysOf($axAll[$codeKey], $codeKey === SPEC_AXIS_CODE ? 'strnatcmp' : null);
        $typeCnt = [];
        foreach ($typeAll as $v) { $typeCnt[$v] = (int) ($axCnt[$codeKey][$v] ?? 0); }
        $axes = ['defs' => $axDefs, 'vals' => $vals, 'all' => $all, 'type' => $typeCnt, 'type_all' => $typeAll, 'code_key' => $codeKey,
                 // 'keys' 순서의 축 값 + 마지막 칸 재고(1/0). 너무 많으면 생략(화면은 '필터 적용' 후 서버 계산만 사용)
                 'combos' => count($combos) <= 3000 ? ['keys' => $axKeys, 'rows' => array_values($combos)] : null];
    }

    return [
        'mat'   => array_filter($matCnt),
        'shape' => array_filter($shCnt),
        'load'  => array_filter($ldCnt),
        'spec'  => $specCnt,
        'total' => $matched,
        'axes'  => $axes,
    ];
}

/**
 * 목록 필터 → SQL 조각(AND ...) + 바인딩 파라미터(참조).
 * 지원: mat(재질=루트), spec[](규격 선택), shape[](형상/옵션 상품명), load[](하중 상품명),
 *       instock(재고 있는 상품만: stock_qty > 0), ax[축]=[값](규격 축 dim_*), ax_type[](규격 코드 끝 글자), ax_code[](규격 코드 전체).
 * 상품 테이블 별칭은 p. 로 가정.
 */
function product_filter_sql(array $f, array &$params): string
{
    $c = [];
    if (!empty($f['mat'])) {
        $ids = category_ids_under_roots((array) $f['mat']);
        if ($ids) {
            $ph = [];
            foreach (array_values($ids) as $i => $id) { $k = ":fmat{$i}"; $ph[] = $k; $params[$k] = $id; }
            $c[] = 'p.category_id IN (' . implode(',', $ph) . ')';
        } else {
            $c[] = '1=0';
        }
    }
    // 규격 선택 필터: spec의 '규격:' 항목 값이 정확히 일치하는 상품만.
    // (규격 표기가 상품군마다 제각각이라 숫자 폭 범위 대신 실제 값 매칭)
    // 운영 spec 형식 '규격: 값 | 중량:...' → 앞('규격: ')·뒤(' |' 또는 끝) 경계로
    // 부분집합 오매칭(200↔1200/2000) 방지.
    if (!empty($f['spec'])) {
        $or = [];
        foreach (array_values((array) $f['spec']) as $i => $v) {
            $v = trim((string) $v);
            if ($v === '') { continue; }
            $k1 = ":fsp{$i}a"; $k2 = ":fsp{$i}b";
            $or[] = "(p.spec LIKE {$k1} OR p.spec LIKE {$k2})";
            $params[$k1] = '%규격: ' . $v . ' |%';
            $params[$k2] = '%규격: ' . $v;
        }
        if ($or) { $c[] = '(' . implode(' OR ', $or) . ')'; }
    }
    foreach (['shape' => 'fsh', 'load' => 'fld'] as $key => $pfx) {
        if (!empty($f[$key])) {
            $or = [];
            foreach (array_values((array) $f[$key]) as $i => $v) {
                $v = trim((string) $v);
                if ($v === '') { continue; }
                $k = ":{$pfx}{$i}"; $or[] = "p.name LIKE {$k}"; $params[$k] = '%' . $v . '%';
            }
            if ($or) { $c[] = '(' . implode(' OR ', $or) . ')'; }
        }
    }
    // 재고 있는 상품만: products.stock_qty > 0 (0·음수는 재고 없음)
    if (!empty($f['instock'])) {
        $c[] = 'p.stock_qty > 0';
    }
    // 규격 축(ax[축]=[값], ax_type=[형식]) — 새 칸이 없으면 조건 없음(spec_axes.php)
    return ($c ? ' AND ' . implode(' AND ', $c) : '') . spec_axes_filter_sql($f, $params);
}

/**
 * 목록검색(products.php ?s=) → SQL 조각(AND ...) + 바인딩 파라미터(참조).
 * 품명·규격·브랜드·설명·업체명 부분일치. 조회 SQL에 넣어야 COUNT(검색결과 N개)·페이지네이션과 일치한다.
 * (예전엔 현재 페이지 60건만 PHP로 걸러 개수·페이지가 실제 목록과 어긋났음)
 * LIKE 와일드카드(%, _)는 문자 그대로 찾도록 이스케이프. 상품 테이블 별칭은 p. 로 가정.
 */
function product_list_search_sql(string $s, array &$params): string
{
    $s = trim($s);
    if ($s === '') { return ''; }
    $like = '%' . addcslashes($s, '%_\\') . '%';
    $cols = ['p.name', 'p.spec', 'p.brand', 'p.description'];
    $vendorSel = vendor_names_subquery();
    if ($vendorSel !== 'NULL') { $cols[] = $vendorSel; }
    $or = [];
    foreach ($cols as $i => $col) {
        $k = ":ls{$i}"; $or[] = "{$col} LIKE {$k}"; $params[$k] = $like;
    }
    return ' AND (' . implode(' OR ', $or) . ')';
}

/**
 * 정렬 키 → ORDER BY 조각(별칭 p.). 빈 문자열이면 각 함수 기본 정렬 사용.
 */
function product_sort_sql(string $sort): string
{
    switch ($sort) {
        // 가격순: 0원(견적문의)은 항상 뒤로
        case 'price_asc':  return "(p.price_b2c = 0) ASC, p.price_b2c ASC, p.id DESC";
        case 'price_desc': return "p.price_b2c DESC, p.id DESC";
        case 'stock_desc': return "p.stock_qty DESC, p.id DESC";
        case 'newest':     return 'p.id DESC';
        default:           return '';
    }
}

/**
 * 검색어 → 하이라이트 대상 단어 목록 (토큰 + 동의어 멤버, 2글자 이상)
 */
function search_expand_terms(string $query): array
{
    $clean    = preg_replace('/[^\w가-힣\s]/u', ' ', $query) ?? '';
    $keywords = array_values(array_filter(
        preg_split('/\s+/u', trim($clean)) ?: [],
        static fn($k) => mb_strlen($k) >= 2
    ));
    $terms = [];
    foreach ($keywords as $k) {
        foreach (expand_search_token($k) as $m) {
            $terms[] = (string) $m;
        }
    }
    $terms = array_values(array_unique(array_filter(array_map('trim', $terms), static fn($t) => $t !== '')));
    // 긴 단어 먼저(겹칠 때 긴 매칭 우선)
    usort($terms, static fn($a, $b) => mb_strlen($b) - mb_strlen($a));
    return $terms;
}

/**
 * 텍스트를 HTML 이스케이프한 뒤, 검색어에 해당하는 부분을 <mark>로 감싼다.
 * (단일 pass 치환 → 삽입된 마크업이 다시 매칭되지 않음)
 */
function highlight_terms(?string $text, array $terms): string
{
    $esc = e((string) $text);
    if (!$terms) {
        return $esc;
    }
    $parts = array_filter(array_map(static fn($t) => preg_quote(e((string) $t), '/'), $terms), static fn($p) => $p !== '');
    if (!$parts) {
        return $esc;
    }
    $pattern = '/(' . implode('|', $parts) . ')/iu';
    return preg_replace($pattern, '<mark class="hl">$1</mark>', $esc) ?? $esc;
}

/**
 * 인기검색어 로그 테이블 보장(최초 사용 시 자동 생성). 요청당 1회만 시도.
 * ⚠️ 닷홈 원격 DB에 사전 마이그레이션이 어려워 앱에서 IF NOT EXISTS로 자가 생성.
 *    실패(권한 등)해도 검색 기능은 무손상(호출부가 try/catch).
 */
function ensure_search_logs_table(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS search_logs (
            term VARCHAR(100) NOT NULL PRIMARY KEY,
            cnt INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * 검색어 1건 집계(인기검색어용). 정규화 후 카운트 증가. 실패는 조용히 무시.
 * - 2~50자만, 앞뒤 공백 정리. 순수 숫자(규격)만인 검색은 인기어로 부적절해 제외.
 */
function log_search(string $query): void
{
    $pdo = db();
    if (!$pdo) { return; }
    $term = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    $len  = mb_strlen($term);
    if ($len < 2 || $len > 50 || preg_match('/^\d+$/', $term)) { return; }
    try {
        ensure_search_logs_table($pdo);
        $st = $pdo->prepare(
            'INSERT INTO search_logs (term, cnt) VALUES (:t, 1)
             ON DUPLICATE KEY UPDATE cnt = cnt + 1'
        );
        $st->execute([':t' => $term]);
    } catch (\Throwable $e) {
        // 인기검색어 집계 실패는 검색에 영향 주지 않음
    }
}

/**
 * 인기검색어 상위 N개(카운트 내림차순, 동수는 최근순). 실패 시 빈 배열.
 */
function popular_searches(int $limit = 8): array
{
    $pdo = db();
    if (!$pdo) { return []; }
    $limit = max(1, min(20, $limit));
    try {
        ensure_search_logs_table($pdo);
        $st = $pdo->prepare(
            'SELECT term FROM search_logs ORDER BY cnt DESC, updated_at DESC LIMIT :lim'
        );
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 상품 통합 검색 (FULLTEXT ngram + 토큰화 + 관련도 정렬)
 *
 * 동작:
 *   1) 검색어를 공백/쉼표 등으로 토큰화(불린 연산자 살균, 2글자 이상만)
 *   2) FULLTEXT BOOLEAN 모드로 "모든 키워드 포함(AND)" 우선 조회, 관련도(score) 정렬
 *   3) AND 결과가 0건이면 "키워드 하나라도 포함(OR)"로 폴백
 *   4) 유효 토큰이 없거나 FULLTEXT가 0건이면 LIKE 폴백
 *   - 상품명에 검색어가 들어간 항목을 최상단으로 부스트
 *
 * @param string   $query  사용자 검색어(다중 키워드 가능)
 * @param int      $limit  페이지당 개수
 * @param int      $offset 시작 위치
 * @param int|null $total  (참조) 전체 매칭 개수
 * @return array 상품 행 배열
 */
function search_products(string $query, int $limit = 60, int $offset = 0, ?int &$total = null, string $filterSql = '', array $filterParams = [], string $sortSql = ''): array
{
    $total = 0;
    $pdo = db();
    if (!$pdo) {
        return [];
    }

    // 토큰화: 한글/영숫자/_ 외 문자(쉼표·불린연산자 +-<>()~*"@ 포함) 제거 → 공백 분리 → 2글자 이상만
    $clean  = preg_replace('/[^\w가-힣\s]/u', ' ', $query) ?? '';
    $tokens = array_values(array_unique(array_filter(
        preg_split('/\s+/u', trim($clean)) ?: [],
        static fn($k) => mb_strlen($k) >= 2
    )));

    // 순수 숫자(규격 치수) 토큰 분리
    $dimTokens = [];
    $keywords  = [];
    foreach ($tokens as $t) {
        if (preg_match('/^\d+$/', $t)) {
            $dimTokens[] = $t;
        } else {
            $keywords[] = $t;
        }
    }

    // 복합어 분해: 카탈로그 용어 사전으로 완전히 분해되면 조각으로 쪼갠다.
    //   예: 'PE배수관'→[PE,배수관], '콘크리트수로관'→[콘크리트,수로관], '중하중그레이팅수로용'→[중하중,그레이팅,수로용]
    //   각 조각은 이후 expand_search_token()에서 동의어/별칭 확장까지 받는다.
    $split = [];
    foreach ($keywords as $k) {
        foreach (segment_compound($k) as $seg) {
            $split[] = $seg;
        }
    }
    $keywords = array_values(array_unique($split));

    // 상품명 부스트: 확장 용어(동의어 포함) 중 상품명에 들어간 개수만큼 가점.
    //   기존엔 원쿼리 통짜(:nl)만 부스트해 '블럭' 검색이 '보강토블록'을 부스트 못했음.
    //   → 각 확장 용어를 CASE 합산해 동의어명 매칭도 상위로 끌어올린다.
    $nbTerms = [];
    foreach ($keywords as $k) {
        foreach (expand_search_token($k) as $m) {
            $m = trim((string) $m);
            if (mb_strlen($m) >= 2) { $nbTerms[] = $m; }
        }
    }
    $nbTerms = array_slice(array_values(array_unique($nbTerms)), 0, 12);
    if (!$nbTerms && $query !== '') { $nbTerms = [$query]; }
    $nbParts  = [];
    $nbParams = [];
    foreach ($nbTerms as $i => $t) {
        $nbParts[] = "CASE WHEN p.name LIKE :nb{$i} THEN 1 ELSE 0 END";
        $nbParams[":nb{$i}"] = '%' . $t . '%';
    }
    $nbSql = $nbParts ? '(' . implode(' + ', $nbParts) . ')' : '0';

    // 규격 조건: 숫자를 "치수"로 인지.
    // 치수는 항상 '*'로 연결(예: 400*400*2000*120T)되므로, 숫자가 '*'에 인접할 때만 매칭.
    // → 중량(950kg)·상차수량 등 비치수 숫자 제외, 4000·1400 같은 부분일치도 제외.
    $dimWhere  = '';
    $dimParams = [];
    foreach ($dimTokens as $i => $d) {
        $dimWhere .= " AND p.spec REGEXP :dim{$i}";
        $dimParams[":dim{$i}"] = '(^|[^0-9])' . $d . '[*]|[*]' . $d . '([^0-9]|$)';
    }

    $nameLike = '%' . $query . '%';

    // 실행기: $ftExpr가 있으면 FULLTEXT 포함, 없으면 규격/기본 조건만 → [rows, total]
    // ⚠️ EMULATE_PREPARES=false 이므로 각 쿼리에 실제 쓰는 파라미터만 정확히 바인딩해야 함
    $vendorSel = vendor_names_subquery();
    $run = static function (?string $ftExpr) use ($pdo, $dimWhere, $dimParams, $nbSql, $nbParams, $limit, $offset, $filterSql, $filterParams, $sortSql, $vendorSel): array {
        $where    = 'p.is_active = 1';
        $scoreSel = '0 AS score';
        $orderSc  = '';
        $ftOn     = ($ftExpr !== null && $ftExpr !== '');
        if ($ftOn) {
            $where   .= ' AND MATCH(p.name, p.spec, p.description) AGAINST(:ft IN BOOLEAN MODE)';
            $scoreSel = 'MATCH(p.name, p.spec, p.description) AGAINST(:ft2 IN BOOLEAN MODE) AS score';
            $orderSc  = 'score DESC, ';
        }
        $where .= $dimWhere . $filterSql;
        // 정렬 override(폭·최신 등) 시 관련도 정렬 대신 사용. 기본은 동의어명 부스트 → 관련도 → 최신.
        $orderBy = $sortSql !== ''
            ? $sortSql
            : "{$nbSql} DESC, {$orderSc}p.id DESC";

        // COUNT: 규격 조건 + 필터 + (있으면) :ft
        $countParams = array_merge($dimParams, $filterParams);
        if ($ftOn) {
            $countParams[':ft'] = $ftExpr;
        }
        $cst = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
        $cst->execute($countParams);
        $cnt = (int) $cst->fetchColumn();

        // SELECT
        $st = $pdo->prepare(
            "SELECT p.*, c.name AS category_name, {$vendorSel} AS vendor_names, {$scoreSel}
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT :lim OFFSET :off"
        );
        foreach ($dimParams as $k => $v)   { $st->bindValue($k, $v); }
        foreach ($filterParams as $k => $v){ $st->bindValue($k, $v); }
        if ($ftOn) {
            $st->bindValue(':ft', $ftExpr);
            $st->bindValue(':ft2', $ftExpr);
        }
        // :nb* 는 정렬 override 시 SQL(ORDER BY)에 없으므로 조건부 바인딩
        if ($sortSql === '') {
            foreach ($nbParams as $k => $v) { $st->bindValue($k, $v); }
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return [$st->fetchAll(), $cnt];
    };

    if ($keywords) {
        // 동의어/별칭 확장: 키워드마다 확장 그룹을 OR로 묶어 함께 찾는다(복합어 부분일치 포함)
        $andParts  = [];   // 그룹 간 AND (각 키워드는 반드시 매칭)
        $orMembers = [];   // 폴백용 OR 전체 멤버
        foreach ($keywords as $k) {
            $group   = expand_search_token($k);
            $members = array_values(array_filter(array_unique(array_map(
                static fn($m) => trim((string) preg_replace('/[+\-<>()~*"@]/u', ' ', $m)),
                $group
            ))));
            if (count($members) > 1) {
                $andParts[]  = '+(' . implode(' ', $members) . ')';
                $orMembers   = array_merge($orMembers, $members);
            } else {
                $andParts[]  = '+' . $k;
                $orMembers[] = $k;
            }
        }
        $andExpr = implode(' ', $andParts);
        $orExpr  = implode(' ', array_values(array_unique($orMembers)));

        // 1) AND (모든 키워드/그룹) + 규격 조건
        try {
            [$rows, $cnt] = $run($andExpr);
        } catch (\Throwable $e) {
            $rows = [];
            $cnt = 0;
        }
        // 2) OR 폴백 (키워드/동의어 하나라도) + 규격 조건
        if (!$rows) {
            try {
                [$rows, $cnt] = $run($orExpr);
            } catch (\Throwable $e) {
                $rows = [];
                $cnt = 0;
            }
        }
        if ($rows) {
            $total = $cnt;
            return $rows;
        }
    } elseif ($dimTokens) {
        // 숫자(규격)만 검색 → 규격 조건만으로 조회
        try {
            [$rows, $cnt] = $run(null);
            if ($rows) {
                $total = $cnt;
                return $rows;
            }
        } catch (\Throwable $e) {
        }
    }

    // 3) LIKE 최종 폴백 (1글자 검색 등 토큰이 없거나 FULLTEXT 0건)
    try {
        $likeWhere = "p.is_active = 1 AND (p.name LIKE :a OR p.spec LIKE :b OR p.brand LIKE :c OR p.description LIKE :d)" . $filterSql;
        $likeParams = [':a' => $nameLike, ':b' => $nameLike, ':c' => $nameLike, ':d' => $nameLike] + $filterParams;

        $cst = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$likeWhere}");
        $cst->execute($likeParams);
        $total = (int) $cst->fetchColumn();

        $orderBy = $sortSql !== '' ? $sortSql : "{$nbSql} DESC, p.id DESC";
        $st = $pdo->prepare(
            "SELECT p.*, c.name AS category_name, {$vendorSel} AS vendor_names
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE {$likeWhere}
             ORDER BY {$orderBy}
             LIMIT :lim OFFSET :off"
        );
        foreach ($likeParams as $k => $v) { $st->bindValue($k, $v); }
        if ($sortSql === '') {
            foreach ($nbParams as $k => $v) { $st->bindValue($k, $v); }
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

function parse_spec_field(string $spec): array
{
    $result = ['spec' => '', 'unit' => '', 'weight' => '', 'load' => '', 'freight' => '', 'install' => ''];
    foreach (explode('|', $spec) as $part) {
        $part = trim($part);
        if      (preg_match('/^규격:\s*(.+)/u',      $part, $m)) { $result['spec']    = trim($m[1]); }
        elseif  (preg_match('/^단위:\s*(.+)/u',      $part, $m)) { $result['unit']    = trim($m[1]); }
        elseif  (preg_match('/^중량:\s*(.+)/u',      $part, $m)) { $result['weight']  = trim($m[1]); }
        elseif  (preg_match('/^상차수량:\s*(.+)/u',  $part, $m)) { $result['load']    = trim($m[1]); }
        elseif  (preg_match('/^운반비예상:\s*(.+)/u',$part, $m)) { $result['freight'] = trim($m[1]); }
        elseif  (preg_match('/^설치비예상:\s*(.+)/u',$part, $m)) { $result['install'] = trim($m[1]); }
    }
    return $result;
}

/**
 * 화면 표시용 규격 치수: spec의 '규격:' 값에서 숫자 사이 곱셈 기호만 × 로 통일.
 * 예) '규격: 200*150*2000*35T | 단위: 본' → '200×150×2000×35T'
 */
function spec_size_label(string $spec): string
{
    return (string) preg_replace('/(?<=\d)\s*[*xX]\s*(?=\d)/u', '×', parse_spec_field($spec)['spec']);
}

/**
 * 화면 표시용 단위: 제곱미터 표기(m 2·m2·m²·㎡)를 ㎡ 로 통일. 그 밖의 단위(개·본·M 등)는 그대로.
 * DB 값은 바꾸지 않는다(관리자 상품 수정 화면은 저장된 값을 그대로 보여 줌).
 */
function unit_display_label(string $unit): string
{
    $u = trim($unit);
    return preg_match('/^(?:m\s*[2²]|㎡)$/iu', $u) ? '㎡' : $u;
}

/**
 * 목록 표 '상세설명' 칸(관리자·직원 전용)용 불렛 줄. DB 값은 그대로, 화면 표시만 나눈다(말줄임 없음 — 사용자 요청 2026-11-13).
 *  - 줄바꿈·' / '·문장 끝('. ')마다 항목을 나눈다. '[임시값]…' 항목(데이터 정리 메모)은 줄에서 빼고 temp=true 만(목록에는 표시 안 함)
 *  - 괄호 보충 설명((mm)·(원문, 단위 미표기) 등)은 뺀다
 *  - 한 줄이 칸 폭($max: 한글 2·그 밖 1로 센 너비)을 넘으면 ', ' → '·' 경계에서 나눠 여러 줄로(내용은 모두 남김)
 * 예) '[임시값] 소켓부 … / 균열하중 2종 1700 · 파괴하중 2종 2600(원문, 단위 미표기)'
 *     → ['lines' => ['균열하중 2종 1700 · 파괴하중 2종 2600'], 'temp' => true]
 * @return array{lines: list<string>, temp: bool}
 */
function product_desc_summary(string $desc, int $max = 50): array
{
    $lines = []; $temp = false;
    $width = static fn(string $s): int => mb_strlen($s) + (int) preg_match_all('/[\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7A3}\x{4E00}-\x{9FFF}]/u', $s);
    // 조각들을 $sep 로 이어 붙이되 한 줄 너비가 $max 를 넘지 않게 묶는다
    $pack = static function (array $parts, string $sep) use ($width, $max): array {
        $out = []; $cur = '';
        foreach ($parts as $p) {
            if ($cur === '') { $cur = $p; continue; }
            if ($width($cur . $sep . $p) <= $max) { $cur .= $sep . $p; continue; }
            $out[] = $cur; $cur = $p;
        }
        if ($cur !== '') { $out[] = $cur; }
        return $out;
    };
    $segs = preg_split('/\s*(?:\R|\s\/\s)\s*/u', trim($desc)) ?: [];
    foreach ($segs as $seg) {
        $seg = trim($seg);
        if ($seg === '') { continue; }
        if (str_starts_with($seg, '[임시값]')) { $temp = true; continue; }
        $seg = (string) preg_replace('/\s*\([^()]*\)/u', '', $seg);   // 괄호 보충 설명 빼기
        foreach (preg_split('/(?<=[^\d\s])\.\s+/u', $seg) ?: [] as $sent) {   // 문장 끝('몸체. 유출구…'), 소수점(4.0)은 그대로
            $s = trim((string) preg_replace('/\s+/u', ' ', $sent), " \t.,;");
            if ($s === '') { continue; }
            if ($width($s) <= $max) { $lines[] = $s; continue; }
            $commas = array_map('trim', explode(', ', $s));
            foreach ($pack($commas, ', ') as $chunk) {
                if ($width($chunk) <= $max) { $lines[] = $chunk; continue; }
                $dots = array_values(array_filter(array_map('trim', preg_split('/\s*·\s*/u', $chunk) ?: []), 'strlen'));
                foreach ($pack($dots, ' · ') as $piece) { $lines[] = $piece; }   // 그래도 길면 그대로(화면에서 줄바꿈)
            }
        }
    }
    return ['lines' => $lines, 'temp' => $temp];
}

function fetch_related_categories(int $categoryId, string $type): array
{
    $stmt = db()->prepare(
        'SELECT c.*
         FROM category_relations r
         JOIN categories c ON c.id = r.target_category_id
         WHERE r.source_category_id = :category_id
           AND r.relation_type = :type
           AND r.is_active = 1
         ORDER BY r.display_order, c.name'
    );
    $stmt->execute(['category_id' => $categoryId, 'type' => $type]);
    return $stmt->fetchAll();
}

function fetch_products_by_category_ids(array $ids): array
{
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE is_active = 1 AND category_id IN ({$placeholders}) ORDER BY id DESC LIMIT 8");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

