<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 가격 데이터 보호 (app/remote_ops/price_guard.php)
 *
 * 정책(사용자 결정 2026-11-04, CLAUDE.md 12장): 원격 관리 API 로는 기존 DB 의 가격 관련 정보를 **어떤 경로로도 바꾸지 않는다.**
 *   관리자 승인을 받아도 이 차단은 유지된다. 가격 조회·분석은 허용. 가격 변경이 필요하면 실행하지 않고 사용자에게 보고.
 *
 * 차단 대상
 *   1) 가격 컬럼(이름 규칙 rops_is_price_column) 값 쓰기 — UPDATE·일괄 수정·INSERT(새 행 포함)·REPLACE·ON DUPLICATE KEY UPDATE·SQL 파일
 *   2) 가격 계산 입력값을 기존 행에서 바꾸기 — products.category_id(적용 마진율 결정), categories.parent_id(마진율 상속)
 *   3) 가격 컬럼이 있는 테이블의 행 삭제, 삭제가 그런 테이블로 번지는 경우(ON DELETE CASCADE/SET NULL)
 *   4) 가격 컬럼이 있는 테이블의 구조 변경 중 데이터가 바뀔 수 있는 것(DROP/TRUNCATE/RENAME, ALTER 의 ADD COLUMN·ADD/DROP INDEX 외)
 *   5) 트리거가 있는 테이블 쓰기, 저장 함수·프로시저 호출(다른 데이터를 몰래 바꿀 수 있음)
 *   6) 가격 컬럼이 있는 테이블의 백업 복원, 가격 컬럼을 되돌리는 되돌리기
 * 운영 구조 확인(2026-11-04, information_schema): products.price_b2c·price_b2b·cost_price, categories.discount_customer·discount_corporate,
 *   product_vendors.supply_price, estimates.shipping_fee·discount_amt, estimate_items.unit_price·adjusted_price, order_items.unit_price,
 *   orders.total_amount, payments.amount, external_products.ref_price·price_basis. 트리거·저장 루틴·뷰 0개.
 * 금지: 가격 계산 로직(app/pricing.php) 변경 — 이 파일은 차단만 한다.
 */

/** 이름 조각(밑줄로 나눈 단어)이 이 중 하나면 가격 컬럼 */
const ROPS_PRICE_SEGMENTS = ['price', 'prices', 'cost', 'costs', 'discount', 'discounts', 'margin', 'margins', 'markup',
    'fee', 'fees', 'amount', 'amounts', 'amt', 'tax', 'vat', 'surcharge'];

/** 가격 계산 입력값(기존 행에서 바꾸면 표시 가격이 달라짐 — app/pricing.php price_bundle·category_discount_map) */
const ROPS_PRICE_INPUT_COLUMNS = ['products.category_id', 'categories.parent_id'];

const ROPS_PRICE_REPORT = ' — 가격 관련 기존 데이터는 원격으로 바꾸지 않습니다(관리자 승인과 무관). 실행하지 않았으니 사용자에게 보고하세요.';

/** 가격 컬럼인지(이름 기준, 대소문자 무시): 'price'·'cost' 를 포함하거나, 밑줄 조각이 ROPS_PRICE_SEGMENTS 중 하나 */
function rops_is_price_column(string $col): bool
{
    $low = strtolower($col);
    if (str_contains($low, 'price') || str_contains($low, 'cost')) {
        return true;
    }
    foreach (preg_split('/_+/', $low) ?: [] as $seg) {
        if (in_array($seg, ROPS_PRICE_SEGMENTS, true)) {
            return true;
        }
    }
    return false;
}

function rops_is_price_input_column(string $table, string $col): bool
{
    return in_array(strtolower($table) . '.' . strtolower($col), ROPS_PRICE_INPUT_COLUMNS, true);
}

/** 기존 행에서 바꾸면 안 되는 컬럼(가격 값 + 가격 계산 입력값) 고르기 */
function rops_price_update_columns(string $table, array $cols): array
{
    return array_values(array_filter(array_map('strval', $cols), static fn($c) => rops_is_price_column($c) || rops_is_price_input_column($table, $c)));
}

/** 새 행에 값을 넣으면 안 되는 컬럼(가격 값) 고르기 */
function rops_price_value_columns(array $cols): array
{
    return array_values(array_filter(array_map('strval', $cols), 'rops_is_price_column'));
}

/**
 * DML 한 문장이 값을 쓰는 컬럼(순수 함수).
 * @return array{type:string,table:?string,set:list<string>,insert_cols:list<string>,insert_all:bool,odku:list<string>}
 *   set=UPDATE 의 SET 대상, insert_cols=INSERT 컬럼 목록(또는 INSERT … SET 대상), insert_all=컬럼 목록 없는 INSERT(전 컬럼), odku=ON DUPLICATE KEY UPDATE 대상
 */
function rops_sql_write_targets(string $stmt): array
{
    $c = rops_sql_classify($stmt);
    $out = ['type' => $c['type'], 'table' => $c['tables'][0] ?? null, 'set' => [], 'insert_cols' => [], 'insert_all' => false, 'odku' => []];
    $tk = rops_sql_tokens($stmt);
    $depth = [];
    $d = 0;
    foreach ($tk as $k => $t) {
        if ($t['t'] === 'punct' && $t['v'] === ')') { $d--; }
        $depth[$k] = $d;
        if ($t['t'] === 'punct' && $t['v'] === '(') { $d++; }
    }
    $isWord = static fn(int $k, string $w): bool => isset($tk[$k]) && $tk[$k]['t'] === 'word' && $tk[$k]['u'] === $w;
    // 대입 목록(a = …, t.b = …) 의 대상 컬럼 이름. $from 부터 깊이 0 의 $stop 단어 전까지
    $assign = static function (int $from, array $stop) use ($tk, $depth): array {
        $names = [];
        $expectTarget = true;
        for ($k = $from; isset($tk[$k]); $k++) {
            $t = $tk[$k];
            if ($depth[$k] === 0 && $t['t'] === 'word' && in_array($t['u'], $stop, true)) { break; }
            if ($depth[$k] === 0 && $t['t'] === 'punct' && $t['v'] === ',') { $expectTarget = true; continue; }
            if ($expectTarget && in_array($t['t'], ['word', 'qident'], true)) {
                $name = $t['v'];
                while (isset($tk[$k + 2]) && $tk[$k + 1]['v'] === '.' && in_array($tk[$k + 2]['t'], ['word', 'qident'], true)) {
                    $k += 2;
                    $name = $tk[$k]['v'];
                }
                $names[] = $name;
                $expectTarget = false;
            }
        }
        return $names;
    };
    $odkuAt = null;
    foreach ($tk as $k => $t) {
        if ($depth[$k] === 0 && $isWord($k, 'ON') && $isWord($k + 1, 'DUPLICATE') && $isWord($k + 2, 'KEY') && $isWord($k + 3, 'UPDATE')) { $odkuAt = $k + 4; break; }
    }
    if ($c['type'] === 'update') {
        foreach ($tk as $k => $t) {
            if ($k > 0 && $depth[$k] === 0 && $isWord($k, 'SET')) { $out['set'] = $assign($k + 1, ['WHERE', 'ORDER', 'LIMIT']); break; }
        }
    } elseif (in_array($c['type'], ['insert', 'replace'], true)) {
        $k = 1;
        while (in_array($tk[$k]['u'] ?? '', ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'], true)) { $k++; }
        if ($isWord($k, 'INTO')) { $k++; }
        $nm = rops_sql_read_name($tk, $k);
        $k = $nm[1];
        if (isset($tk[$k]) && $tk[$k]['v'] === '(' && !$isWord($k + 1, 'SELECT') && !$isWord($k + 1, 'WITH')) {
            for ($j = $k + 1; isset($tk[$j]) && !($depth[$j] === 0 && $tk[$j]['v'] === ')'); $j++) {
                if ($depth[$j] === 1 && in_array($tk[$j]['t'], ['word', 'qident'], true)) { $out['insert_cols'][] = $tk[$j]['v']; }
            }
        } elseif ($isWord($k, 'SET')) {
            $out['insert_cols'] = $assign($k + 1, ['ON']);
        } else {
            $out['insert_all'] = true;
        }
        if ($odkuAt !== null) {
            $out['odku'] = $assign($odkuAt, []);
        }
    }
    return $out;
}

/** ALTER TABLE 가 덧붙이기만 하는지(ADD [COLUMN]·ADD INDEX/KEY/UNIQUE/FULLTEXT/SPATIAL·DROP INDEX/KEY 만). 순수 함수 */
function rops_sql_alter_is_additive(string $stmt): bool
{
    $tk = rops_sql_tokens($stmt);
    $k = 1;
    if (($tk[$k]['u'] ?? '') === 'IGNORE') { $k++; }
    if (($tk[$k]['u'] ?? '') !== 'TABLE') { return false; }
    $k = rops_sql_read_name($tk, $k + 1)[1];
    $d = 0;
    $clauseStart = true;
    $any = false;
    for (; isset($tk[$k]); $k++) {
        $t = $tk[$k];
        if ($t['t'] === 'punct' && $t['v'] === '(') { $d++; continue; }
        if ($t['t'] === 'punct' && $t['v'] === ')') { $d--; continue; }
        if ($d !== 0) { continue; }
        if ($t['t'] === 'punct' && $t['v'] === ',') { $clauseStart = true; continue; }
        if (!$clauseStart) { continue; }
        $clauseStart = false;
        $any = true;
        $w = $t['u'];
        $next = $tk[$k + 1]['u'] ?? '';
        $nextIsName = isset($tk[$k + 1]) && in_array($tk[$k + 1]['t'], ['word', 'qident'], true);
        if ($w === 'ADD') {
            if (in_array($next, ['CONSTRAINT', 'PRIMARY', 'FOREIGN', 'CHECK', 'PARTITION'], true)) { return false; }
            if (in_array($next, ['COLUMN', 'INDEX', 'KEY', 'UNIQUE', 'FULLTEXT', 'SPATIAL'], true) || $nextIsName || ($tk[$k + 1]['v'] ?? '') === '(') { continue; }
            return false;
        }
        if ($w === 'DROP' && in_array($next, ['INDEX', 'KEY'], true)) { continue; }
        return false;
    }
    return $any;
}

/**
 * SQL 파일 계획의 가격 보호 문제(DB 구조 정보 $schema 필요).
 * @param array{price_tables?:list<string>,cascade_price?:array<string,list<string>>,trigger_tables?:list<string>,routines?:list<string>} $schema
 *   price_tables=가격 컬럼이 있는 테이블, cascade_price=[테이블 => 삭제 시 함께 바뀌는 가격 테이블], trigger_tables, routines(저장 함수·프로시저 이름)
 * @return list<string>
 */
function rops_price_plan_problems(array $plan, array $schema): array
{
    $priceT = array_map('strtolower', $schema['price_tables'] ?? []);
    $trigT = array_map('strtolower', $schema['trigger_tables'] ?? []);
    $routines = array_map('strtolower', $schema['routines'] ?? []);
    $cascade = array_change_key_case($schema['cascade_price'] ?? [], CASE_LOWER);
    $out = [];
    foreach ($plan['statements'] as $s) {
        $no = $s['no'];
        $tables = array_map('strtolower', $s['tables']);
        if ($routines !== []) {
            $tk = rops_sql_tokens($s['sql']);
            foreach ($tk as $k => $t) {
                if (in_array($t['t'], ['word', 'qident'], true) && in_array(strtolower($t['v']), $routines, true) && (($tk[$k + 1]['v'] ?? '') === '(')) {
                    $out[] = "{$no}번 문장: 저장 함수({$t['v']}) 호출 금지(다른 데이터를 바꿀 수 있음)";
                    break;
                }
            }
        }
        if (in_array($s['type'], ['insert', 'update', 'delete', 'replace'], true)) {
            $w = rops_sql_write_targets($s['sql']);
            $t = strtolower((string) $w['table']);
            if (in_array($t, $trigT, true)) {
                $out[] = "{$no}번 문장: {$t} 에 트리거가 있어 다른 데이터(가격 포함)가 함께 바뀔 수 있음";
            }
            if ($bad = rops_price_update_columns($t, array_merge($w['set'], $w['odku']))) {
                $out[] = "{$no}번 문장: 가격 관련 컬럼(" . implode(', ', $bad) . ') 변경 금지';
            }
            if ($bad = rops_price_value_columns($w['insert_cols'])) {
                $out[] = "{$no}번 문장: 새 행에도 가격 컬럼(" . implode(', ', $bad) . ') 값을 넣지 않음';
            }
            if ($w['insert_all'] && in_array($t, $priceT, true)) {
                $out[] = "{$no}번 문장: 가격 컬럼이 있는 {$t} 는 INSERT 컬럼 목록을 적어야 함(가격 컬럼 제외)";
            }
            if ($s['type'] === 'replace' && in_array($t, $priceT, true)) {
                $out[] = "{$no}번 문장: REPLACE 는 기존 행을 지우고 다시 써서 가격이 바뀔 수 있음({$t})";
            }
            if ($s['type'] === 'delete' && in_array($t, $priceT, true)) {
                $out[] = "{$no}번 문장: 가격 컬럼이 있는 {$t} 의 행 삭제 금지(비활성화 컬럼 수정으로)";
            }
            if ($s['type'] === 'delete' && !empty($cascade[$t])) {
                $out[] = "{$no}번 문장: {$t} 삭제가 가격 테이블(" . implode(', ', $cascade[$t]) . ')로 번짐(CASCADE/SET NULL)';
            }
        } elseif ($s['type'] === 'ddl') {
            $hit = array_values(array_intersect($tables, $priceT));
            if ($hit === []) {
                continue;
            }
            if (in_array($s['kw'], ['DROP', 'TRUNCATE', 'RENAME'], true) && !preg_match('/^\s*DROP\s+INDEX\b/i', $s['sql'])) {
                $out[] = "{$no}번 문장: 가격 컬럼이 있는 테이블(" . implode(', ', $hit) . ") {$s['kw']} 금지";
            } elseif ($s['kw'] === 'ALTER' && !rops_sql_alter_is_additive($s['sql'])) {
                $out[] = "{$no}번 문장: 가격 컬럼이 있는 테이블(" . implode(', ', $hit) . ') 은 ADD COLUMN·ADD/DROP INDEX 만 허용(기존 컬럼 변경·삭제 금지)';
            }
        }
    }
    return $out;
}

/**
 * 운영 DB 구조에서 가격 보호에 필요한 정보(조회만).
 * @return array{price_tables:list<string>,price_columns:array<string,list<string>>,cascade_price:array<string,list<string>>,trigger_tables:list<string>,routines:list<string>}
 */
function rops_price_schema(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cols = [];
    foreach ($pdo->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
        if (rops_is_price_column((string) $c)) { $cols[(string) $t][] = (string) $c; }
    }
    $cascade = [];
    foreach ($pdo->query("SELECT REFERENCED_TABLE_NAME, TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND DELETE_RULE IN ('CASCADE', 'SET NULL')")->fetchAll(PDO::FETCH_NUM) as [$parent, $child]) {
        if (isset($cols[(string) $child])) { $cascade[(string) $parent][] = (string) $child; }
    }
    $trig = $pdo->query('SELECT DISTINCT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $rout = $pdo->query('SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    return $cache = ['price_tables' => array_keys($cols), 'price_columns' => $cols, 'cascade_price' => array_map('array_values', array_map('array_unique', $cascade)),
        'trigger_tables' => array_map('strval', $trig), 'routines' => array_map('strval', $rout)];
}

function rops_is_price_table(PDO $pdo, string $table): bool
{
    return in_array($table, rops_price_schema($pdo)['price_tables'], true);
}

/** 구조화 쓰기(db.update/batch/insert/delete) 가격 보호. 통과 못 하면 RopsError(price_protected) */
function rops_price_guard_structured(PDO $pdo, string $table, string $action, array $cols): void
{
    $sch = rops_price_schema($pdo);
    if (in_array($table, $sch['trigger_tables'], true)) {
        throw new RopsError('price_protected', "{$table} 에 트리거가 있어 원격 쓰기를 하지 않습니다(다른 데이터·가격이 함께 바뀔 수 있음)" . ROPS_PRICE_REPORT);
    }
    if (in_array($action, ['update', 'batch'], true) && ($bad = rops_price_update_columns($table, $cols))) {
        throw new RopsError('price_protected', '가격 관련 컬럼(' . implode(', ', $bad) . ') 변경 요청' . ROPS_PRICE_REPORT, ['columns' => $bad]);
    }
    if ($action === 'insert' && ($bad = rops_price_value_columns($cols))) {
        throw new RopsError('price_protected', '새 행에 가격 컬럼(' . implode(', ', $bad) . ') 값을 넣는 요청' . ROPS_PRICE_REPORT, ['columns' => $bad]);
    }
    if ($action === 'delete') {
        if (in_array($table, $sch['price_tables'], true)) {
            throw new RopsError('price_protected', "가격 컬럼이 있는 {$table} 의 행 삭제 요청(비활성화 컬럼 수정을 검토)" . ROPS_PRICE_REPORT);
        }
        if (!empty($sch['cascade_price'][$table])) {
            throw new RopsError('price_protected', "{$table} 삭제가 가격 테이블(" . implode(', ', $sch['cascade_price'][$table]) . ')로 번지는 요청' . ROPS_PRICE_REPORT);
        }
    }
}

/** SQL 파일 계획 가격 보호. 문제가 있으면 RopsError(price_protected) */
function rops_price_guard_plan(PDO $pdo, array $plan): void
{
    $problems = rops_price_plan_problems($plan, rops_price_schema($pdo));
    if ($problems !== []) {
        throw new RopsError('price_protected', 'SQL 파일 가격 보호: ' . implode(' / ', $problems) . ROPS_PRICE_REPORT, ['problems' => $problems]);
    }
}
