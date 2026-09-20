<?php
declare(strict_types=1);

// 프로덕션: PHP 에러가 출력에 섞여 JSON 파싱을 깨지 않도록 억제
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);

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
        }
    }
}

// 시크릿 로드: 운영은 /app/.env, 로컬 개발은 프로젝트 루트 .env.local
load_env_file(__DIR__ . '/.env');
load_env_file(__DIR__ . '/../.env.local');

$config = require __DIR__ . '/config.php';

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
        // 로컬 개발 환경: DB 없을 때 빈 stub PDO 대신 null 반환
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
    return db()->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY parent_id IS NOT NULL, sort_order, name')->fetchAll();
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

function fetch_products(?int $categoryId = null, ?string $query = null): array
{
    $sql = 'SELECT p.*, c.name AS category_name
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1';
    $params = [];

    if ($categoryId) {
        // 해당 카테고리 및 하위 카테고리 상품 포함
        $sql .= ' AND p.category_id IN (
                    SELECT c2.id FROM categories c2
                    WHERE c2.id = :cid OR c2.parent_id = :pid
                  )';
        $params['cid'] = $categoryId;
        $params['pid'] = $categoryId;
    }

    if ($query) {
        $sql .= ' AND (p.name LIKE :q1 OR p.brand LIKE :q2 OR p.spec LIKE :q3 OR p.description LIKE :q4)';
        $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $query . '%';
    }

    $sql .= ' ORDER BY p.id DESC';

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
function search_products(string $query, int $limit = 60, int $offset = 0, ?int &$total = null): array
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
    $run = static function (?string $ftExpr) use ($pdo, $dimWhere, $dimParams, $nameLike, $limit, $offset): array {
        $where    = 'p.is_active = 1';
        $scoreSel = '0 AS score';
        $orderSc  = '';
        $ftOn     = ($ftExpr !== null && $ftExpr !== '');
        if ($ftOn) {
            $where   .= ' AND MATCH(p.name, p.spec, p.description) AGAINST(:ft IN BOOLEAN MODE)';
            $scoreSel = 'MATCH(p.name, p.spec, p.description) AGAINST(:ft2 IN BOOLEAN MODE) AS score';
            $orderSc  = 'score DESC, ';
        }
        $where .= $dimWhere;

        // COUNT: 규격 조건 + (있으면) :ft 만
        $countParams = $dimParams;
        if ($ftOn) {
            $countParams[':ft'] = $ftExpr;
        }
        $cst = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
        $cst->execute($countParams);
        $cnt = (int) $cst->fetchColumn();

        // SELECT: 규격 조건 + (있으면) :ft(WHERE) + :ft2(score)
        $st = $pdo->prepare(
            "SELECT p.*, c.name AS category_name, {$scoreSel}
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE {$where}
             ORDER BY (CASE WHEN p.name LIKE :nl THEN 1 ELSE 0 END) DESC, {$orderSc}p.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($dimParams as $k => $v) {
            $st->bindValue($k, $v);
        }
        if ($ftOn) {
            $st->bindValue(':ft', $ftExpr);
            $st->bindValue(':ft2', $ftExpr);
        }
        $st->bindValue(':nl', $nameLike);
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
        $cst = $pdo->prepare(
            "SELECT COUNT(*) FROM products
             WHERE is_active = 1
               AND (name LIKE :a OR spec LIKE :b OR brand LIKE :c OR description LIKE :d)"
        );
        $cst->execute([':a' => $nameLike, ':b' => $nameLike, ':c' => $nameLike, ':d' => $nameLike]);
        $total = (int) $cst->fetchColumn();

        $st = $pdo->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND (p.name LIKE :a OR p.spec LIKE :b OR p.brand LIKE :c OR p.description LIKE :d)
             ORDER BY (CASE WHEN p.name LIKE :nl THEN 1 ELSE 0 END) DESC, p.id DESC
             LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':a', $nameLike);
        $st->bindValue(':b', $nameLike);
        $st->bindValue(':c', $nameLike);
        $st->bindValue(':d', $nameLike);
        $st->bindValue(':nl', $nameLike);
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

