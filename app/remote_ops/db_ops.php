<?php
declare(strict_types=1);
/**
 * 원격 관리 API — DB 작업 (app/remote_ops/db_ops.php)
 *
 * 조회(승인 불필요, db.read): db.tables · db.describe · db.query(READ ONLY 트랜잭션) · db.search(상품·카테고리)
 * 쓰기(승인 필요, db.write):
 *   db.update · db.batch · db.insert · db.delete  — db/scope 승인 범위 안의 구조화 쓰기(매개변수 바인딩, 트랜잭션,
 *                                                   대상 PK 먼저 확정 → 영향 행 수 확인 → 변경 → 재조회 → 전후 비교 저장)
 *   db.execute                                    — db/sql 승인(본문 해시 고정)의 SQL 파일 실행(DML 은 한 트랜잭션, DDL 은 자동 백업 후 순서대로)
 *   db.revert                                     — 구조화 쓰기 1건 되돌리기(현재값이 변경 후 값과 같을 때만, force 로 무시 가능)
 * 기타: db.verify(변경 결과 재확인) · db.backup(테이블 덤프를 관리 테이블에 보관) · db.stage_sql / db.stage_restore(승인 요청 생성)
 *
 * 금지: remote_ops_* 관리 테이블 쓰기, 인증정보 컬럼 쓰기·조회, 다른 DB 접근, 사용자 입력을 SQL 에 직접 이어 붙이기(값은 모두 바인딩)
 */

const ROPS_QUERY_MAX_ROWS   = 2000;
const ROPS_BULK_DELETE_ROWS = 20;
const ROPS_DUMP_MAX_BYTES   = 20 * 1048576;

/** 관리 테이블(remote_ops_*)이면 거부 */
function rops_deny_ops_table(string $table): void
{
    if (stripos($table, 'remote_ops_') === 0) {
        throw new RopsError('forbidden_table', '관리 API 테이블(remote_ops_*)은 원격 작업으로 바꿀 수 없습니다');
    }
}

/** 테이블 구조(컬럼·PK·자동증가). 없는 테이블이면 오류 */
function rops_table_meta(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!rops_ident_ok($table)) {
        throw new RopsError('bad_params', '잘못된 테이블 이름');
    }
    $st = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT, COLUMN_COMMENT
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $st->execute([$table]);
    $cols = [];
    $auto = null;
    foreach ($st->fetchAll() as $c) {
        $cols[$c['COLUMN_NAME']] = $c;
        if (stripos((string) $c['EXTRA'], 'auto_increment') !== false) {
            $auto = $c['COLUMN_NAME'];
        }
    }
    if ($cols === []) {
        throw new RopsError('no_table', "테이블이 없습니다: {$table}");
    }
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $st->execute([$table]);
    $pk = $st->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$table] = ['table' => $table, 'columns' => $cols, 'names' => array_keys($cols), 'pk' => $pk, 'auto_inc' => $auto];
}

function rops_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

/** 값 정규화(바인딩용): bool→int, 배열·객체 거부 */
function rops_bind_value(mixed $v, string $col): mixed
{
    if (is_bool($v)) {
        return (int) $v;
    }
    if ($v === null || is_int($v) || is_float($v) || is_string($v)) {
        return $v;
    }
    throw new RopsError('bad_params', "{$col}: 값은 문자·숫자·null 만");
}

function rops_val_eq(mixed $a, mixed $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return (string) $a === (string) $b;
}

/** 결과 행 가리기: 인증정보는 항상, 개인정보는 reveal_pii 가 아닐 때 */
function rops_mask_row(array $row, string $table, bool $revealPii): array
{
    foreach ($row as $k => $v) {
        if ($v === null) {
            continue;
        }
        if (rops_is_secret_column((string) $k)) {
            $row[$k] = '[REDACTED]';
        } elseif (!$revealPii && rops_is_pii_column((string) $k, $table)) {
            $row[$k] = '[PII]';
        }
    }
    return $row;
}

/** 행 목록에서 인증정보 컬럼 제거(백업·스냅샷 저장용 — 비밀번호 해시를 관리 테이블에 남기지 않음) */
function rops_strip_secrets(array $rows): array
{
    foreach ($rows as $i => $r) {
        foreach (array_keys($r) as $k) {
            if (rops_is_secret_column((string) $k)) { unset($rows[$i][$k]); }
        }
    }
    return $rows;
}

/** PK 목록 → WHERE 조각. 단일 PK: `id` IN (?,?), 복합 PK: (`a`,`b`) IN ((?,?),…) */
function rops_pk_where(array $pk, array $keys, array &$params): string
{
    if ($keys === []) {
        return '0 = 1';
    }
    if (count($pk) === 1) {
        foreach ($keys as $k) { $params[] = $k[$pk[0]]; }
        return rops_qi($pk[0]) . ' IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    }
    $tuple = '(' . implode(',', array_fill(0, count($pk), '?')) . ')';
    foreach ($keys as $k) { foreach ($pk as $c) { $params[] = $k[$c]; } }
    return '(' . implode(',', array_map('rops_qi', $pk)) . ') IN (' . implode(',', array_fill(0, count($keys), $tuple)) . ')';
}

function rops_key_of(array $row, array $pk): array
{
    $k = [];
    foreach ($pk as $c) { $k[$c] = $row[$c] ?? null; }
    return $k;
}

function rops_key_str(array $key): string
{
    return implode('|', array_map(static fn($v) => (string) $v, $key));
}

/** PK 로 행 읽기 → [키문자열 => 행] */
function rops_fetch_by_keys(PDO $pdo, array $meta, array $keys, bool $lock = false): array
{
    $out = [];
    foreach (array_chunk($keys, 500) as $chunk) {
        $params = [];
        $st = $pdo->prepare('SELECT * FROM ' . rops_qi($meta['table']) . ' WHERE ' . rops_pk_where($meta['pk'], $chunk, $params) . ($lock ? ' FOR UPDATE' : ''));
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $out[rops_key_str(rops_key_of($r, $meta['pk']))] = $r;
        }
    }
    return $out;
}

/** 전후 비교 → [{key, change:'update'|'insert'|'delete', columns:{col:[전,후]}}] */
function rops_rows_diff(array $before, array $after, array $pk, string $table, bool $revealPii): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $ks) {
        $b = $before[$ks] ?? null;
        $a = $after[$ks] ?? null;
        $key = rops_key_of($a ?? $b, $pk);
        if ($b === null) {
            $out[] = ['key' => $key, 'change' => 'insert', 'row' => rops_mask_row($a, $table, $revealPii)];
            continue;
        }
        if ($a === null) {
            $out[] = ['key' => $key, 'change' => 'delete', 'row' => rops_mask_row($b, $table, $revealPii)];
            continue;
        }
        $cols = [];
        foreach ($a as $c => $v) {
            if (!rops_val_eq($b[$c] ?? null, $v)) {
                $mb = rops_mask_row([$c => $b[$c] ?? null], $table, $revealPii)[$c];
                $ma = rops_mask_row([$c => $v], $table, $revealPii)[$c];
                $cols[$c] = [$mb, $ma];
            }
        }
        $out[] = ['key' => $key, 'change' => $cols ? 'update' : 'unchanged', 'columns' => $cols];
    }
    return $out;
}

/** 이 테이블 행을 지우면 함께 바뀌는 자식 테이블(ON DELETE CASCADE / SET NULL) */
function rops_cascade_children(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND DELETE_RULE IN ('CASCADE', 'SET NULL')");
    $st->execute([$table]);
    return array_values(array_unique($st->fetchAll(PDO::FETCH_COLUMN)));
}

// ─────────────────────────────── 조회 ───────────────────────────────

function rops_db_tables(PDO $pdo, array $p): array
{
    $rows = $pdo->query('SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_estimate, DATA_LENGTH AS data_bytes,
        INDEX_LENGTH AS index_bytes, TABLE_COLLATION AS collation, CREATE_TIME AS created, UPDATE_TIME AS updated, TABLE_COMMENT AS comment
        FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll();
    if (!empty($p['exact_counts'])) {
        foreach ($rows as &$r) {
            if (rops_ident_ok((string) $r['name'])) {
                $r['rows_exact'] = (int) $pdo->query('SELECT COUNT(*) FROM ' . rops_qi((string) $r['name']))->fetchColumn();
            }
        }
        unset($r);
    }
    $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    return ['result' => ['database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'server_version' => $ver, 'tables' => $rows],
        'log' => ['target' => 'information_schema', 'affected_rows' => count($rows)]];
}

function rops_db_describe(PDO $pdo, array $p): array
{
    $table = (string) ($p['table'] ?? '');
    $meta = rops_table_meta($pdo, $table);
    $st = $pdo->prepare('SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS col, INDEX_TYPE AS type
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $st->execute([$table]);
    $idx = $st->fetchAll();
    $st = $pdo->prepare('SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_col,
            r.DELETE_RULE AS on_delete, r.UPDATE_RULE AS on_update
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
    $st->execute([$table]);
    $fks = $st->fetchAll();
    $create = $pdo->query('SHOW CREATE TABLE ' . rops_qi($table))->fetch(PDO::FETCH_NUM)[1] ?? '';
    $columns = array_map(static fn($c) => [
        'name' => $c['COLUMN_NAME'], 'type' => $c['COLUMN_TYPE'], 'nullable' => $c['IS_NULLABLE'] === 'YES', 'key' => $c['COLUMN_KEY'],
        'default' => $c['COLUMN_DEFAULT'], 'extra' => $c['EXTRA'], 'comment' => $c['COLUMN_COMMENT'],
    ], array_values($meta['columns']));
    return ['result' => ['table' => $table, 'primary_key' => $meta['pk'], 'columns' => $columns, 'indexes' => $idx, 'foreign_keys' => $fks,
        'referenced_by_cascade' => rops_cascade_children($pdo, $table), 'create_table' => $create],
        'log' => ['target' => $table]];
}

function rops_db_query(PDO $pdo, array $p): array
{
    $sql = (string) ($p['sql'] ?? '');
    if (strlen($sql) > 20000) {
        throw new RopsError('bad_params', 'SQL 이 너무 깁니다(20,000자 이하)');
    }
    if (($why = rops_sql_read_problem($sql, rops_secret_tables($pdo))) !== null) {
        throw new RopsError('sql_not_allowed', $why);
    }
    $sql = rops_sql_split($sql)[0];
    $limit = max(1, min(ROPS_QUERY_MAX_ROWS, (int) ($p['limit'] ?? 200)));
    $params = $p['params'] ?? [];
    if (!is_array($params) || !array_is_list($params)) {
        throw new RopsError('bad_params', 'params 는 값 목록(?, ? 순서)');
    }
    foreach ($params as $i => $v) { $params[$i] = rops_bind_value($v, "params[{$i}]"); }
    $reveal = !empty($p['reveal_pii']);

    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        try { $pdo->exec('SET SESSION max_execution_time = 15000'); } catch (\Throwable $e) { /* 권한 없으면 기본값 */ }
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (\PDOException $e) {
            // SHOW·EXPLAIN 일부는 서버 측 prepare 미지원(1295) → 텍스트 실행(검사 통과한 한 문장만, 여러 문장 실행은 연결 설정으로 꺼져 있음)
            if ((int) ($e->errorInfo[1] ?? 0) !== 1295 || $params !== []) {
                throw $e;
            }
            $st = $pdo->query($sql);
        }
        $cols = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $m = $st->getColumnMeta($i) ?: [];
            $cols[] = ['name' => $m['name'] ?? "col{$i}", 'table' => $m['table'] ?? '', 'type' => $m['native_type'] ?? ''];
        }
        $rows = [];
        $truncated = false;
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($rows) >= $limit) { $truncated = true; break; }
            $assoc = [];
            foreach ($r as $i => $v) {
                $c = $cols[$i];
                if ($v !== null && rops_is_secret_column($c['name'])) { $v = '[REDACTED]'; }
                elseif ($v !== null && !$reveal && rops_is_pii_column($c['name'], $c['table'])) { $v = '[PII]'; }
                $assoc[] = $v;
            }
            $rows[] = $assoc;
        }
        $st->closeCursor();
    } catch (\PDOException $e) {
        throw new RopsError('sql_error', rops_clean_message($e->getMessage()));
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e) { }
        try { $pdo->exec('SET SESSION max_execution_time = 0'); } catch (\Throwable $e) { }
    }
    return ['result' => ['columns' => array_column($cols, 'name'), 'rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated,
        'limit' => $limit, 'pii_revealed' => $reveal],
        'log' => ['target' => 'query', 'affected_rows' => count($rows)]];
}

/** 상품·카테고리 검색(띄어쓰기로 나눈 단어가 모두 들어간 것) */
function rops_db_search(PDO $pdo, array $p): array
{
    $kind = (string) ($p['kind'] ?? 'products');
    $q = trim((string) ($p['q'] ?? ''));
    $limit = max(1, min(500, (int) ($p['limit'] ?? 50)));
    $terms = array_slice(array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn($t) => $t !== '')), 0, 6);
    if ($terms === []) {
        throw new RopsError('bad_params', '검색어(q)가 필요합니다');
    }
    $like = static fn(string $t): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%';
    $cat = rops_table_meta($pdo, 'categories');

    if ($kind === 'categories') {
        $where = [];
        $params = [];
        foreach ($terms as $t) {
            $where[] = '(c.name LIKE ?' . (isset($cat['columns']['slug']) ? ' OR c.slug LIKE ?' : '') . ')';
            $params[] = $like($t);
            if (isset($cat['columns']['slug'])) { $params[] = $like($t); }
        }
        $extra = array_values(array_intersect(['slug', 'sort_order', 'is_active', 'discount_customer', 'discount_corporate'], $cat['names']));
        $sel = implode(', ', array_map(static fn($c) => 'c.' . rops_qi($c), $extra));
        $st = $pdo->prepare('SELECT c.id, c.parent_id, c.name' . ($sel ? ', ' . $sel : '') . ',
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
            FROM categories c WHERE ' . implode(' AND ', $where) . " ORDER BY c.id LIMIT {$limit}");
        $st->execute($params);
        $rows = $st->fetchAll();
        $all = [];
        foreach ($pdo->query('SELECT id, parent_id, name FROM categories')->fetchAll() as $c) { $all[(int) $c['id']] = $c; }
        foreach ($rows as &$r) {
            $path = [];
            $id = (int) $r['id'];
            for ($n = 0; $id && isset($all[$id]) && $n < 10; $n++) { array_unshift($path, $all[$id]['name']); $id = (int) $all[$id]['parent_id']; }
            $r['path'] = implode(' > ', $path);
        }
        unset($r);
        return ['result' => ['kind' => 'categories', 'terms' => $terms, 'rows' => $rows, 'row_count' => count($rows)],
            'log' => ['target' => 'categories', 'affected_rows' => count($rows)]];
    }
    if ($kind !== 'products') {
        throw new RopsError('bad_params', 'kind 는 products 또는 categories');
    }
    $prod = rops_table_meta($pdo, 'products');
    $hasVendors = rops_table_exists($pdo, 'product_vendors') && rops_table_exists($pdo, 'vendors');
    $where = [];
    $params = [];
    foreach ($terms as $t) {
        $or = ['p.name LIKE ?', 'p.brand LIKE ?', 'p.spec LIKE ?', 'c.name LIKE ?'];
        if ($hasVendors) {
            $or[] = 'EXISTS (SELECT 1 FROM product_vendors pv JOIN vendors v ON v.id = pv.vendor_id WHERE pv.product_id = p.id AND v.name LIKE ?)';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
        foreach ($or as $_) { $params[] = $like($t); }
    }
    if (empty($p['include_inactive']) && isset($prod['columns']['is_active'])) {
        $where[] = 'p.is_active = 1';
    }
    $want = ['brand', 'spec', 'category_id', 'price_b2c', 'price_b2b', 'cost_price', 'stock_qty', 'is_active', 'external_source'];
    $sel = implode(', ', array_map(static fn($c) => 'p.' . rops_qi($c), array_values(array_intersect($want, $prod['names']))));
    $catExtra = array_values(array_intersect(['discount_customer', 'discount_corporate'], $cat['names']));
    $catSel = $catExtra ? ', ' . implode(', ', array_map(static fn($c) => 'c.' . rops_qi($c) . ' AS ' . rops_qi('category_' . $c), $catExtra)) : '';
    $vendorSel = $hasVendors ? ", (SELECT GROUP_CONCAT(v.name ORDER BY pv.is_primary DESC SEPARATOR ', ') FROM product_vendors pv JOIN vendors v ON v.id = pv.vendor_id WHERE pv.product_id = p.id) AS vendors" : '';
    $st = $pdo->prepare("SELECT p.id, p.name, {$sel}, c.name AS category{$catSel}{$vendorSel}
        FROM products p LEFT JOIN categories c ON c.id = p.category_id
        WHERE " . implode(' AND ', $where) . " ORDER BY p.id LIMIT {$limit}");
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        if (isset($r['spec']) && is_string($r['spec']) && mb_strlen($r['spec']) > 200) { $r['spec'] = mb_substr($r['spec'], 0, 200) . '…'; }
    }
    unset($r);
    return ['result' => ['kind' => 'products', 'terms' => $terms, 'rows' => $rows, 'row_count' => count($rows), 'limit' => $limit,
        'note' => '회원 등급별 표시 가격(개인·기업회원가)은 app/pricing.php 가 cost_price 와 카테고리 마진율로 계산합니다. 어떤 컬럼을 바꿀지 확인 후 수정하세요.'],
        'log' => ['target' => 'products', 'affected_rows' => count($rows)]];
}

// ─────────────────────────────── 구조화 쓰기 ───────────────────────────────

/** 승인 범위(db/scope) 공통 확인 */
function rops_scope_check(array $g, string $table, string $action): array
{
    $s = $g['scope'];
    if (!in_array($table, (array) ($s['tables'] ?? []), true)) {
        throw new RopsError('out_of_scope', "승인 범위에 없는 테이블: {$table}");
    }
    if (!in_array($action, (array) ($s['actions'] ?? []), true)) {
        throw new RopsError('out_of_scope', "승인 범위에 없는 작업: {$action}");
    }
    return $s;
}

function rops_scope_rows_check(array $g, array $s, string $table, array $meta, array $keys, string $action): void
{
    $n = count($keys);
    $perOp = (int) ($s['max_rows_per_op'] ?? 0);
    $total = (int) ($s['max_rows_total'] ?? 0);
    if ($perOp > 0 && $n > $perOp) {
        throw new RopsError('too_many_rows', "대상 {$n}행 — 승인된 1회 최대 {$perOp}행 초과", ['rows' => $n]);
    }
    if ($total > 0 && $g['used_rows'] + $n > $total) {
        throw new RopsError('too_many_rows', "누적 " . ($g['used_rows'] + $n) . "행 — 승인된 전체 최대 {$total}행 초과", ['rows' => $n, 'used_rows' => $g['used_rows']]);
    }
    $ids = $s['ids'][$table] ?? null;
    if (is_array($ids) && count($meta['pk']) === 1) {
        $allowed = array_map('strval', $ids);
        foreach ($keys as $k) {
            if (!in_array((string) $k[$meta['pk'][0]], $allowed, true)) {
                throw new RopsError('out_of_scope', "승인 범위에 없는 행: {$meta['pk'][0]}=" . $k[$meta['pk'][0]]);
            }
        }
    }
    if ($action === 'delete' && $n > ROPS_BULK_DELETE_ROWS && empty($s['allow_bulk_delete'])) {
        throw new RopsError('bulk_delete_not_approved', "{$n}행 삭제 — " . ROPS_BULK_DELETE_ROWS . '행 초과 삭제는 대량 삭제 승인이 필요합니다', ['rows' => $n]);
    }
}

/** 바꿀 컬럼 확인(존재·PK 아님·인증정보 아님·승인 컬럼) */
function rops_scope_columns_check(array $s, array $meta, array $cols, bool $isInsert): void
{
    $allowed = $s['columns'][$meta['table']] ?? null;
    foreach ($cols as $c) {
        if (!isset($meta['columns'][$c])) {
            throw new RopsError('bad_params', "없는 컬럼: {$c}");
        }
        if (rops_is_secret_column($c)) {
            throw new RopsError('forbidden_column', "인증정보 컬럼({$c})은 원격으로 바꿀 수 없습니다");
        }
        if (!$isInsert && in_array($c, $meta['pk'], true)) {
            throw new RopsError('bad_params', "기본키({$c})는 수정할 수 없습니다");
        }
        if (is_array($allowed) && !in_array($c, $allowed, true)) {
            throw new RopsError('out_of_scope', "승인 범위에 없는 컬럼: {$c}");
        }
    }
}

/**
 * db.update / db.batch / db.insert / db.delete 공통 진입점
 */
function rops_db_structured(PDO $pdo, array $ctx, string $action, array $p): array
{
    $code = (string) ($p['grant'] ?? '');
    $table = (string) ($p['table'] ?? '');
    $dry = !empty($p['dry_run']);
    $reveal = !empty($p['reveal_pii']);
    rops_deny_ops_table($table);
    $meta = rops_table_meta($pdo, $table);
    if ($meta['pk'] === []) {
        throw new RopsError('no_primary_key', "기본키가 없는 테이블은 구조화 쓰기를 지원하지 않습니다: {$table}");
    }
    $T = rops_qi($table);
    $warnings = [];

    $pdo->beginTransaction();
    try {
        $g = rops_grant_require($pdo, $code, 'db', 'scope');
        $s = rops_scope_check($g, $table, $action === 'batch' ? 'update' : $action);
        $keys = [];
        $sets = [];       // [키문자열 => [col => val]]
        $expect = [];     // [키문자열 => [col => val]]
        $inserted = [];

        if ($action === 'update' || $action === 'delete') {
            $where = $p['where'] ?? null;
            if (!is_array($where) || $where === []) {
                throw new RopsError('bad_params', 'where 조건이 필요합니다(전체 행 변경은 지원하지 않음)');
            }
            $params = [];
            // 인증정보 컬럼은 조건에도 못 씀(드라이런 반복으로 값을 알아내는 것 차단)
            $whereCols = array_values(array_filter($meta['names'], static fn($c) => !rops_is_secret_column((string) $c)));
            $wsql = rops_where_compile($where, $whereCols, $params);
            $st = $pdo->prepare('SELECT ' . implode(',', array_map('rops_qi', $meta['pk'])) . " FROM {$T} WHERE {$wsql} FOR UPDATE");
            $st->execute($params);
            $keys = array_map(static fn($r) => rops_key_of($r, $meta['pk']), $st->fetchAll());
            if (isset($p['expect_rows']) && (int) $p['expect_rows'] !== count($keys)) {
                throw new RopsError('row_count_mismatch', '대상 ' . count($keys) . "행 — 예상(expect_rows) {$p['expect_rows']}행과 다름", ['rows' => count($keys)]);
            }
            if ($action === 'update') {
                $set = $p['set'] ?? null;
                if (!is_array($set) || $set === [] || array_is_list($set)) {
                    throw new RopsError('bad_params', 'set 은 {컬럼: 값} 형식');
                }
                rops_scope_columns_check($s, $meta, array_keys($set), false);
                foreach ($keys as $k) { $sets[rops_key_str($k)] = $set; }
            } else {
                if (in_array($table, rops_secret_tables($pdo), true)) {
                    throw new RopsError('forbidden_table', "{$table} 는 인증정보 컬럼이 있어 원격 삭제를 지원하지 않습니다(비활성화 컬럼 수정 권장)");
                }
                $children = rops_cascade_children($pdo, $table);
                if ($children !== [] && $keys !== []) {
                    if (empty($s['allow_cascade_delete'])) {
                        throw new RopsError('cascade_not_approved', '삭제하면 연결된 행도 함께 지워지거나 바뀝니다(ON DELETE CASCADE/SET NULL: ' . implode(', ', $children) . '). 되돌리기로 복구되지 않으므로 별도 승인이 필요합니다(비활성화 처리 권장).');
                    }
                    $warnings[] = 'CASCADE/SET NULL 로 함께 바뀌는 테이블: ' . implode(', ', $children) . ' (되돌리기 대상 아님)';
                }
            }
        } elseif ($action === 'batch') {
            $rows = $p['rows'] ?? null;
            if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
                throw new RopsError('bad_params', 'rows 는 [{key:{…}, set:{…}, expect?:{…}}] 목록');
            }
            foreach ($rows as $i => $r) {
                $key = $r['key'] ?? null;
                $set = $r['set'] ?? null;
                if (!is_array($key) || count(array_intersect($meta['pk'], array_keys($key))) !== count($meta['pk'])) {
                    throw new RopsError('bad_params', "rows[{$i}].key 는 기본키(" . implode(',', $meta['pk']) . ') 전체');
                }
                if (!is_array($set) || $set === [] || array_is_list($set)) {
                    throw new RopsError('bad_params', "rows[{$i}].set 은 {컬럼: 값}");
                }
                rops_scope_columns_check($s, $meta, array_keys($set), false);
                $k = rops_key_of($key, $meta['pk']);
                $ks = rops_key_str($k);
                if (isset($sets[$ks])) {
                    throw new RopsError('bad_params', "같은 행이 두 번 있습니다: {$ks}");
                }
                $keys[] = $k;
                $sets[$ks] = $set;
                if (isset($r['expect']) && is_array($r['expect'])) {
                    rops_scope_columns_check(['columns' => null], $meta, array_keys($r['expect']), true);
                    $expect[$ks] = $r['expect'];
                }
            }
        } elseif ($action === 'insert') {
            $rows = $p['rows'] ?? null;
            if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
                throw new RopsError('bad_params', 'rows 는 [{컬럼: 값}] 목록');
            }
            if (count($rows) > max(1, (int) ($s['max_rows_per_op'] ?? 0))) {
                throw new RopsError('too_many_rows', count($rows) . '행 — 승인된 1회 최대 ' . (int) ($s['max_rows_per_op'] ?? 0) . '행 초과');
            }
        } else {
            throw new RopsError('bad_params', '알 수 없는 작업');
        }

        // 영향 행 수·범위 확인(insert 는 실행 후 키 확정)
        if ($action !== 'insert') {
            rops_scope_rows_check($g, $s, $table, $meta, $keys, $action);
        }
        $before = $action === 'insert' ? [] : rops_fetch_by_keys($pdo, $meta, $keys, true);
        if ($action === 'batch') {
            foreach ($keys as $k) {
                $ks = rops_key_str($k);
                if (!isset($before[$ks])) {
                    throw new RopsError('row_not_found', "행이 없습니다: {$ks}");
                }
                foreach ($expect[$ks] ?? [] as $c => $v) {
                    if (!rops_val_eq($before[$ks][$c], $v)) {
                        throw new RopsError('expect_mismatch', "현재값이 예상과 다릅니다({$ks}.{$c}) — 다른 곳에서 바뀌었을 수 있으니 다시 조회하세요",
                            ['key' => $k, 'column' => $c]);
                    }
                }
            }
        }

        // 실행
        $changed = 0;
        if ($action === 'update' || $action === 'batch') {
            foreach ($keys as $k) {
                $set = $sets[rops_key_str($k)];
                $params = [];
                $parts = [];
                foreach ($set as $c => $v) { $parts[] = rops_qi((string) $c) . ' = ?'; $params[] = rops_bind_value($v, (string) $c); }
                $w = rops_pk_where($meta['pk'], [$k], $params);
                $st = $pdo->prepare("UPDATE {$T} SET " . implode(', ', $parts) . " WHERE {$w}");
                $st->execute($params);
                $changed += $st->rowCount();
            }
        } elseif ($action === 'delete') {
            foreach (array_chunk($keys, 500) as $chunk) {
                $params = [];
                $st = $pdo->prepare("DELETE FROM {$T} WHERE " . rops_pk_where($meta['pk'], $chunk, $params));
                $st->execute($params);
                $changed += $st->rowCount();
            }
        } else {
            foreach ($p['rows'] as $i => $row) {
                if (!is_array($row) || $row === [] || array_is_list($row)) {
                    throw new RopsError('bad_params', "rows[{$i}] 는 {컬럼: 값}");
                }
                rops_scope_columns_check($s, $meta, array_keys($row), true);
                $cols = array_keys($row);
                $vals = [];
                foreach ($row as $c => $v) { $vals[] = rops_bind_value($v, (string) $c); }
                $st = $pdo->prepare("INSERT INTO {$T} (" . implode(',', array_map('rops_qi', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                $st->execute($vals);
                $changed += $st->rowCount();
                $k = [];
                foreach ($meta['pk'] as $c) {
                    $k[$c] = array_key_exists($c, $row) ? $row[$c] : ($c === $meta['auto_inc'] ? (int) $pdo->lastInsertId() : null);
                }
                $keys[] = $k;
            }
            rops_scope_rows_check($g, $s, $table, $meta, $keys, $action);
        }

        $after = $action === 'delete' ? [] : rops_fetch_by_keys($pdo, $meta, $keys);
        $diff = rops_rows_diff($before, $after, $meta['pk'], $table, $reveal);
        $doc = ['table' => $table, 'pk' => $meta['pk'], 'action' => $action, 'keys' => $keys];
        rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'rows_before', $table, rops_json($doc + ['rows' => rops_strip_secrets(array_values($before))]));
        rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'rows_after', $table, rops_json($doc + ['rows' => rops_strip_secrets(array_values($after))]));
        rops_grant_use($pdo, $g, count($keys), false);
        if ($dry) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw rops_wrap_pdo($e);
    }
    return ['result' => ['dry_run' => $dry, 'committed' => !$dry, 'table' => $table, 'action' => $action, 'matched_rows' => count($keys),
        'changed_rows' => $changed, 'diff' => $diff, 'warnings' => $warnings, 'grant' => $code,
        'grant_used_rows' => $g['used_rows'] + ($dry ? 0 : count($keys))],
        'log' => ['grant_id' => $g['id'], 'target' => $table, 'affected_rows' => $changed, 'status' => $dry ? 'dry_run' : 'ok']];
}

/** PDOException → RopsError(sql_error) (나머지는 그대로) */
function rops_wrap_pdo(\Throwable $e): \Throwable
{
    if ($e instanceof \PDOException) {
        return new RopsError('sql_error', rops_clean_message($e->getMessage()));
    }
    return $e;
}

/** 작업 이력 + 행 스냅샷 로드 */
function rops_load_row_op(PDO $pdo, int $opId): array
{
    $log = rops_log_get($pdo, $opId);
    if ($log === null) {
        throw new RopsError('op_not_found', "작업을 찾을 수 없음: {$opId}");
    }
    $snaps = [];
    foreach (rops_snapshots($pdo, 'log', $opId) as $s) {
        $snaps[$s['kind']] = $s;
    }
    return [$log, $snaps];
}

function rops_db_verify(PDO $pdo, array $p): array
{
    $opId = (int) ($p['operation'] ?? 0);
    [$log, $snaps] = rops_load_row_op($pdo, $opId);
    $reveal = !empty($p['reveal_pii']);
    if ($log['op'] === 'db.execute') {
        if (!isset($snaps['verify_after'])) {
            return ['result' => ['operation' => $opId, 'op' => $log['op'], 'verifiable' => false, 'note' => '@verify 조회가 없던 SQL 실행입니다'], 'log' => ['target' => "op:{$opId}"]];
        }
        $saved = json_decode((string) $snaps['verify_after']['content'], true) ?: [];
        $now = rops_run_verify_queries($pdo, array_column($saved, 'sql'));
        $same = rops_json(array_column($saved, 'rows')) === rops_json(array_column($now, 'rows'));
        return ['result' => ['operation' => $opId, 'op' => $log['op'], 'matches' => $same, 'saved' => $saved, 'current' => $now], 'log' => ['target' => "op:{$opId}"]];
    }
    if (!isset($snaps['rows_after'], $snaps['rows_before'])) {
        throw new RopsError('not_verifiable', '행 변경 기록이 없는 작업입니다');
    }
    $docA = json_decode((string) $snaps['rows_after']['content'], true);
    $meta = rops_table_meta($pdo, $docA['table']);
    $expected = [];
    foreach ($docA['rows'] as $r) { $expected[rops_key_str(rops_key_of($r, $docA['pk']))] = $r; }
    $current = rops_fetch_by_keys($pdo, $meta, $docA['keys']);
    $rows = [];
    $all = true;
    foreach ($docA['keys'] as $k) {
        $ks = rops_key_str($k);
        $e = $expected[$ks] ?? null;
        $c = $current[$ks] ?? null;
        $diffCols = [];
        if ($e === null || $c === null) {
            $ok = $e === null && $c === null;
        } else {
            foreach ($e as $col => $v) {
                if (!rops_val_eq($c[$col] ?? null, $v)) { $diffCols[] = $col; }
            }
            $ok = $diffCols === [];
        }
        $all = $all && $ok;
        $rows[] = ['key' => $k, 'matches' => $ok, 'expected_exists' => $e !== null, 'current_exists' => $c !== null, 'differs' => $diffCols,
            'current' => $c !== null ? rops_mask_row($c, $docA['table'], $reveal) : null];
    }
    return ['result' => ['operation' => $opId, 'op' => $log['op'], 'status' => $log['status'], 'table' => $docA['table'], 'matches' => $all,
        'rows' => $rows, 'reverted_by' => $log['reverted_by']], 'log' => ['target' => "op:{$opId}", 'affected_rows' => count($rows)]];
}

function rops_db_revert(PDO $pdo, array $ctx, array $p): array
{
    $opId = (int) ($p['operation'] ?? 0);
    $dry = !empty($p['dry_run']);
    $force = !empty($p['force']);
    [$log, $snaps] = rops_load_row_op($pdo, $opId);
    if (!in_array($log['op'], ['db.update', 'db.batch', 'db.insert', 'db.delete'], true) || $log['status'] !== 'ok') {
        throw new RopsError('not_revertible', '되돌릴 수 있는 작업이 아닙니다(구조화 쓰기 성공 건만). SQL 파일 실행은 db.backup 스냅샷 복원(db.stage_restore)을 쓰세요.');
    }
    if ($log['reverted_by'] !== null) {
        throw new RopsError('already_reverted', "이미 되돌린 작업입니다(작업 {$log['reverted_by']})");
    }
    $docB = json_decode((string) $snaps['rows_before']['content'], true);
    $docA = json_decode((string) $snaps['rows_after']['content'], true);
    $table = $docA['table'];
    rops_deny_ops_table($table);
    $meta = rops_table_meta($pdo, $table);
    $T = rops_qi($table);
    $grantRow = $log['grant_id'] !== null ? rops_grant_get_by_id($pdo, (int) $log['grant_id']) : null;
    if ($grantRow === null) {
        throw new RopsError('grant_not_found', '원래 승인을 찾을 수 없습니다');
    }
    $byKey = static function (array $rows, array $pk): array { $o = []; foreach ($rows as $r) { $o[rops_key_str(rops_key_of($r, $pk))] = $r; } return $o; };
    $before = $byKey($docB['rows'], $docB['pk']);
    $after = $byKey($docA['rows'], $docA['pk']);

    $pdo->beginTransaction();
    try {
        $g = rops_grant_require($pdo, $grantRow['code'], 'db', 'scope', true);
        // 되돌림 선점(행 잠금): 동시에 두 번 되돌리는 것 방지. 실패·드라이런이면 롤백으로 함께 취소됨
        $claim = $pdo->prepare('UPDATE remote_ops_log SET reverted_by = ? WHERE id = ? AND reverted_by IS NULL');
        $claim->execute([$ctx['log_id'], $opId]);
        if ($claim->rowCount() !== 1) {
            throw new RopsError('already_reverted', '이미 되돌렸거나 되돌리는 중인 작업입니다');
        }
        $current = rops_fetch_by_keys($pdo, $meta, $docA['keys'], true);
        $conflicts = [];
        foreach ($docA['keys'] as $k) {
            $ks = rops_key_str($k);
            $a = $after[$ks] ?? null;
            $c = $current[$ks] ?? null;
            if ($a === null && $c !== null) { $conflicts[] = ['key' => $k, 'reason' => '삭제했던 행이 다시 생겼음']; continue; }
            if ($a !== null && $c === null) { $conflicts[] = ['key' => $k, 'reason' => '변경 후 행이 지금은 없음']; continue; }
            if ($a !== null) {
                foreach ($a as $col => $v) {
                    if (!rops_val_eq($c[$col] ?? null, $v)) { $conflicts[] = ['key' => $k, 'reason' => "{$col} 이(가) 이후에 다시 바뀜"]; break; }
                }
            }
        }
        if ($conflicts !== [] && !$force) {
            throw new RopsError('revert_conflict', '작업 이후 값이 바뀐 행이 있어 되돌리지 않았습니다(덮어쓰려면 force)', ['conflicts' => $conflicts]);
        }
        $changed = 0;
        foreach ($docA['keys'] as $k) {
            $ks = rops_key_str($k);
            $b = $before[$ks] ?? null;
            $params = [];
            if ($log['op'] === 'db.insert') {
                $st = $pdo->prepare("DELETE FROM {$T} WHERE " . rops_pk_where($meta['pk'], [$k], $params));
                $st->execute($params);
            } elseif ($log['op'] === 'db.delete') {
                if ($b === null) { continue; }
                $cols = array_values(array_intersect(array_keys($b), $meta['names']));
                $st = $pdo->prepare("INSERT INTO {$T} (" . implode(',', array_map('rops_qi', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                $st->execute(array_map(static fn($c) => $b[$c], $cols));
            } else {
                if ($b === null) { continue; }
                $cols = [];
                foreach ($after[$ks] ?? [] as $col => $v) {
                    if (!rops_val_eq($b[$col] ?? null, $v) && !in_array($col, $meta['pk'], true)) { $cols[] = $col; }
                }
                if ($cols === []) { continue; }
                $parts = [];
                foreach ($cols as $col) { $parts[] = rops_qi($col) . ' = ?'; $params[] = $b[$col]; }
                $w = rops_pk_where($meta['pk'], [$k], $params);
                $st = $pdo->prepare("UPDATE {$T} SET " . implode(', ', $parts) . " WHERE {$w}");
                $st->execute($params);
            }
            $changed += $st->rowCount();
        }
        $now = rops_fetch_by_keys($pdo, $meta, $docA['keys']);
        $diff = rops_rows_diff($current, $now, $meta['pk'], $table, !empty($p['reveal_pii']));
        $doc = ['table' => $table, 'pk' => $meta['pk'], 'action' => 'revert', 'keys' => $docA['keys']];
        rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'rows_before', $table, rops_json($doc + ['rows' => rops_strip_secrets(array_values($current))]));
        rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'rows_after', $table, rops_json($doc + ['rows' => rops_strip_secrets(array_values($now))]));
        $dry ? $pdo->rollBack() : $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw rops_wrap_pdo($e);
    }
    return ['result' => ['dry_run' => $dry, 'reverted_operation' => $opId, 'table' => $table, 'changed_rows' => $changed, 'forced' => $force && $conflicts !== [],
        'conflicts' => $conflicts, 'diff' => $diff],
        'log' => ['grant_id' => $g['id'], 'target' => $table, 'affected_rows' => $changed, 'revert_of' => $opId, 'status' => $dry ? 'dry_run' : 'ok']];
}

// ─────────────────────────────── SQL 파일 ───────────────────────────────

/** @verify 조회 실행 → [{sql, rows}] (최대 200행씩, 가림 적용) */
function rops_run_verify_queries(PDO $pdo, array $queries): array
{
    $out = [];
    foreach ($queries as $q) {
        $st = $pdo->prepare($q);
        $st->execute();
        $rows = [];
        while (($r = $st->fetch()) !== false && count($rows) < 200) { $rows[] = rops_mask_row($r, '', false); }
        $st->closeCursor();
        $out[] = ['sql' => $q, 'rows' => $rows];
    }
    return $out;
}

/** 인증정보 컬럼(이름 기준)이 있는 테이블 목록 */
function rops_secret_tables(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = [];
    foreach ($pdo->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
        if (rops_is_secret_column((string) $c)) { $out[(string) $t] = true; }
    }
    return $cache = array_keys($out);
}

/** 읽기 전용 트랜잭션 안에서 실행(MySQL 이 쓰기를 거부). 승인 전 '미리 보기'는 모두 이 안에서만 */
function rops_readonly(PDO $pdo, callable $fn): mixed
{
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        try { $pdo->exec('SET SESSION max_execution_time = 15000'); } catch (\Throwable $e) { /* 권한 없으면 기본값 */ }
        return $fn();
    } finally {
        try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e) { }
        try { $pdo->exec('SET SESSION max_execution_time = 0'); } catch (\Throwable $e) { }
    }
}

/** DML 한 문장의 대상 행 수(실행하지 않고 셈). 셀 수 없으면 오류 */
function rops_sql_estimate(PDO $pdo, array $s): int
{
    $q = rops_sql_count_query($s['sql']);
    if ($q === null) {
        throw new RopsError('sql_not_allowed', "{$s['no']}번 문장: 대상 행 수를 미리 셀 수 없는 형식입니다(한 테이블 UPDATE/DELETE, INSERT VALUES/SET/SELECT 만)");
    }
    if (isset($q['rows'])) {
        return (int) $q['rows'];
    }
    try {
        $st = $pdo->prepare($q['sql']);
        $st->execute();
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    } catch (\PDOException $e) {
        throw new RopsError('sql_error', "{$s['no']}번 문장 실패: " . rops_clean_message($e->getMessage()), ['statement' => $s['no']]);
    }
}

/** SQL 파일 검사(형식·크기·인코딩·금지 문장). 문제가 있으면 sql_not_allowed */
function rops_sql_plan_checked(PDO $pdo, string $sql, string $source = 'file'): array
{
    if ($sql === '' || strlen($sql) > 4 * 1048576) {
        throw new RopsError('bad_params', 'SQL 은 1바이트~4MB');
    }
    if (!mb_check_encoding($sql, 'UTF-8') || str_contains($sql, "\0")) {
        throw new RopsError('bad_params', 'SQL 파일은 UTF-8 텍스트여야 합니다');
    }
    if ($source === 'file' && (strlen($sql) > 1048576 || substr_count($sql, "\n") > 2000)) {
        throw new RopsError('bad_params', 'SQL 파일은 1MB·2,000줄 이하(승인 화면에서 전부 볼 수 있게). 큰 이관은 나눠서 요청하세요');
    }
    $plan = rops_sql_plan($sql, rops_secret_tables($pdo));
    if ($plan['problems'] !== []) {
        throw new RopsError('sql_not_allowed', 'SQL 파일 검사 실패: ' . implode(' / ', $plan['problems']), ['problems' => $plan['problems']]);
    }
    return $plan;
}

/** db.stage_sql: SQL 파일 승인 요청. 승인 전에는 SQL 을 실행하지 않는다 — 대상 행 수는 COUNT 조회로(READ ONLY 트랜잭션) */
function rops_db_stage_sql(PDO $pdo, array $p, ?string $sqlOverride = null, string $source = 'file'): array
{
    $sql = $sqlOverride ?? (string) ($p['sql'] ?? '');
    $plan = rops_sql_plan_checked($pdo, $sql, $source === 'file' ? 'file' : 'snapshot');
    $preview = ['source' => $source, 'statements' => count($plan['statements']), 'warnings' => $plan['warnings'], 'has_ddl' => $plan['has_ddl'],
        'tables' => $plan['tables'], 'hidden_chars' => rops_hidden_chars($sql)];
    $total = null;
    $deletes = 0;
    if (!$plan['has_ddl']) {
        [$est, $vb] = rops_readonly($pdo, static function () use ($pdo, $plan): array {
            $est = [];
            foreach ($plan['statements'] as $s) {
                $est[] = ['no' => $s['no'], 'type' => $s['type'], 'tables' => $s['tables'], 'target_rows' => rops_sql_estimate($pdo, $s)];
            }
            return [$est, rops_run_verify_queries($pdo, $plan['verify'])];
        });
        $total = array_sum(array_column($est, 'target_rows'));
        foreach ($est as $e) { if ($e['type'] === 'delete') { $deletes += $e['target_rows']; } }
        $preview += ['estimates' => $est, 'total_rows' => $total, 'verify_before' => $vb,
            'note' => '승인 전에는 실행하지 않고 현재 데이터 기준 대상 행 수만 셌습니다(앞 문장의 영향은 반영 안 됨). 실행 때 문장마다 다시 세어 승인 행 수를 넘으면 전체 롤백합니다.'];
    } else {
        $preview['note'] = 'DDL(구조 변경) 파일: 미리 실행할 수 없습니다. 실행 직전에 대상 테이블을 자동 백업합니다(ALTER/DROP/TRUNCATE/RENAME).';
    }
    $maxRows = isset($p['max_rows']) ? max(0, (int) $p['max_rows']) : ($total ?? 0);
    if ($total !== null && $total > $maxRows) {
        throw new RopsError('bad_params', "대상 {$total}행이 max_rows({$maxRows})보다 많습니다");
    }
    $scope = [
        'sql_sha256' => hash('sha256', $sql), 'bytes' => strlen($sql), 'has_ddl' => $plan['has_ddl'], 'tables' => $plan['tables'],
        'statements' => count($plan['statements']), 'max_rows_total' => $maxRows, 'source' => $source,
        'needs_extra_confirm' => $plan['has_ddl'] || $plan['warnings'] !== [] || $deletes > ROPS_BULK_DELETE_ROWS || $preview['hidden_chars'] > 0,
    ];
    $g = rops_grant_create($pdo, ['kind' => 'db', 'mode' => 'sql', 'title' => (string) ($p['title'] ?? ''), 'detail' => $p['detail'] ?? null,
        'scope' => $scope, 'payload' => $sql, 'preview' => $preview, 'ttl_minutes' => (int) ($p['ttl_minutes'] ?? 60), 'max_ops' => 1]);
    return ['result' => ['grant' => rops_grant_public($g), 'preview' => $preview, 'approve_url' => rops_approve_url($g['code'])],
        'log' => ['grant_id' => $g['id'], 'target' => 'grant:' . $g['code'], 'affected_rows' => $total]];
}

function rops_approve_url(string $code): string
{
    return rops_settings()['base_url'] . '/admin-remote-ops.php?grant=' . rawurlencode($code);
}

/** 보이지 않는 문자(양방향 제어·폭 없는 문자) 개수 — 승인 화면에서 숨은 코드 경고용 */
function rops_hidden_chars(string $s): int
{
    return (int) preg_match_all('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{00AD}\x{FEFF}]/u', $s);
}

/** db.execute: 승인된 SQL 파일 실행 */
function rops_db_execute(PDO $pdo, array $ctx, array $p): array
{
    $code = (string) ($p['grant'] ?? '');
    $dry = !empty($p['dry_run']);
    $g0 = rops_grant_get($pdo, $code);
    if ($g0 === null || $g0['kind'] !== 'db' || $g0['mode'] !== 'sql') {
        throw new RopsError('grant_not_found', "SQL 실행 승인을 찾을 수 없음: {$code}");
    }
    $sql = (string) $g0['payload'];
    if (!hash_equals((string) ($g0['scope']['sql_sha256'] ?? ''), hash('sha256', $sql))) {
        throw new RopsError('grant_corrupt', '승인된 SQL 본문의 해시가 맞지 않습니다');
    }
    $plan = rops_sql_plan_checked($pdo, $sql, ($g0['scope']['source'] ?? 'file') === 'file' ? 'file' : 'snapshot');
    $maxRows = (int) ($g0['scope']['max_rows_total'] ?? 0);

    if (!$plan['has_ddl']) {
        $pdo->beginTransaction();
        try {
            $g = rops_grant_require($pdo, $code, 'db', 'sql');
            $vb = rops_run_verify_queries($pdo, $plan['verify']);
            $res = [];
            $total = 0;
            $changed = 0;
            foreach ($plan['statements'] as $s) {
                // 실행 직전 대상 행 수를 다시 세어 승인 범위 확인
                $target = rops_sql_estimate($pdo, $s);
                $total += $target;
                if ($total > $maxRows) {
                    throw new RopsError('too_many_rows', "{$s['no']}번 문장까지 대상 {$total}행 — 승인된 최대 {$maxRows}행 초과(전체 롤백)", ['results' => $res]);
                }
                try {
                    $st = $pdo->prepare($s['sql']);
                    $st->execute();
                    $n = $st->rowCount();
                    $st->closeCursor();
                } catch (\PDOException $e) {
                    throw new RopsError('sql_error', "{$s['no']}번 문장 실패: " . rops_clean_message($e->getMessage()), ['statement' => $s['no'], 'results' => $res]);
                }
                if (!$pdo->inTransaction()) {
                    // 문장이 트랜잭션을 끝냈다면(있어선 안 됨) 즉시 멈춘다
                    throw new RopsError('tx_broken', "{$s['no']}번 문장 뒤 트랜잭션이 끝났습니다 — 실행을 멈췄습니다. 이력·데이터를 확인하세요", ['results' => $res]);
                }
                $changed += $n;
                $res[] = ['no' => $s['no'], 'type' => $s['type'], 'tables' => $s['tables'], 'target_rows' => $target, 'changed_rows' => $n];
            }
            $va = rops_run_verify_queries($pdo, $plan['verify']);
            rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'verify_before', 'sql', rops_json($vb));
            rops_snapshot_put($pdo, $ctx['log_id'], $g['id'], 'verify_after', 'sql', rops_json($va));
            rops_grant_use($pdo, $g, $total, true);
            $dry ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw rops_wrap_pdo($e);
        }
        return ['result' => ['dry_run' => $dry, 'committed' => !$dry, 'transaction' => true, 'results' => $res, 'total_rows' => $total,
            'changed_rows' => $changed, 'verify_before' => $vb, 'verify_after' => $va],
            'log' => ['grant_id' => $g['id'], 'target' => implode(',', $plan['tables']), 'affected_rows' => $changed, 'status' => $dry ? 'dry_run' : 'ok']];
    }

    if ($dry) {
        throw new RopsError('ddl_no_dry_run', 'DDL 이 있는 SQL 은 미리 실행할 수 없습니다(승인 후 실제 실행만)');
    }
    // DDL 파일(DML 없음 — 계획 검사에서 보장): 승인 사용 확정(중복 실행 방지) → 자동 백업 → 문장 순서대로(각 DDL 은 MySQL 이 즉시 커밋)
    $pdo->beginTransaction();
    try {
        $g = rops_grant_require($pdo, $code, 'db', 'sql');
        rops_grant_use($pdo, $g, 0, true);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $backups = [];
    foreach ($plan['statements'] as $s) {
        if ($s['type'] === 'ddl' && in_array($s['kw'], ['ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true)) {
            foreach ($s['tables'] as $t) {
                if (!isset($backups[$t]) && rops_ident_ok($t) && rops_table_exists($pdo, $t)) {
                    $backups[$t] = rops_table_dump_snapshot($pdo, $ctx['log_id'], $g['id'], $t);
                }
            }
        }
    }
    $res = [];
    foreach ($plan['statements'] as $s) {
        try {
            $st = $pdo->prepare($s['sql']);
            $st->execute();
            $st->closeCursor();
        } catch (\PDOException $e) {
            throw new RopsError('sql_error', "{$s['no']}번 문장 실패(앞 문장은 이미 반영됨): " . rops_clean_message($e->getMessage()),
                ['statement' => $s['no'], 'results' => $res, 'backups' => $backups]);
        }
        $res[] = ['no' => $s['no'], 'type' => $s['type'], 'kw' => $s['kw'], 'tables' => $s['tables']];
    }
    return ['result' => ['dry_run' => false, 'committed' => true, 'transaction' => false, 'results' => $res, 'backups' => $backups,
        'note' => 'DDL 이라 문장마다 즉시 반영되었습니다. 복구는 backups 의 스냅샷으로 db.stage_restore 를 사용하세요.'],
        'log' => ['grant_id' => $g['id'], 'target' => implode(',', $plan['tables']), 'affected_rows' => 0]];
}

// ─────────────────────────────── 백업·복원 ───────────────────────────────

/** SQL 값 리터럴 */
function rops_sql_literal(PDO $pdo, mixed $v, string $dataType): string
{
    if ($v === null) { return 'NULL'; }
    if (in_array($dataType, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit', 'geometry', 'point', 'linestring', 'polygon'], true)) {
        return $v === '' ? "''" : '0x' . bin2hex((string) $v);
    }
    if (is_int($v) || is_float($v)) { return (string) $v; }
    return $pdo->quote((string) $v);
}

/** 테이블 덤프(복원용 upsert SQL) → 스냅샷 저장. 반환: {snapshot, table, rows, bytes} */
function rops_table_dump_snapshot(PDO $pdo, ?int $logId, ?int $grantId, string $table): array
{
    rops_deny_ops_table($table);
    $meta = rops_table_meta($pdo, $table);
    $st = $pdo->prepare('SELECT DATA_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    if ((int) $st->fetchColumn() > 3 * ROPS_DUMP_MAX_BYTES) {
        throw new RopsError('table_too_large', "{$table} 이(가) 너무 커서 자동 백업할 수 없습니다. phpMyAdmin 으로 내보낸 뒤 진행하세요.");
    }
    $create = $pdo->query('SHOW CREATE TABLE ' . rops_qi($table))->fetch(PDO::FETCH_NUM)[1] ?? '';
    $T = rops_qi($table);
    // 인증정보 컬럼은 백업하지 않음(복원 때도 기존 값 유지). 그 컬럼이 있는 테이블의 '삭제된 행'은 이 백업으로 되살릴 수 없음
    $cols = array_values(array_filter($meta['names'], static fn($c) => !rops_is_secret_column((string) $c)));
    $skipped = array_values(array_diff($meta['names'], $cols));
    $colList = implode(',', array_map('rops_qi', $cols));
    $upd = implode(', ', array_map(static fn($c) => rops_qi($c) . '=VALUES(' . rops_qi($c) . ')', array_diff($cols, $meta['pk'])));
    $out = "-- @table {$table}\n-- 원격 관리 API 자동 백업 " . date('Y-m-d H:i:s') . " — 복원: 행 값을 백업 시점으로 되돌림(이후 추가된 행은 남음, 구조 변경은 아래 CREATE 참고해 수동 복구)\n";
    if ($skipped !== []) {
        $out .= '-- 인증정보 컬럼 제외: ' . implode(', ', $skipped) . "\n";
    }
    $out .= '-- ' . str_replace("\n", "\n-- ", $create) . "\n";
    $order = $meta['pk'] ? ' ORDER BY ' . implode(',', array_map('rops_qi', $meta['pk'])) : '';
    $rows = $pdo->query("SELECT * FROM {$T}{$order}");
    $n = 0;
    $batch = [];
    $flush = static function () use (&$batch, &$out, $T, $colList, $upd): void {
        if ($batch === []) { return; }
        $out .= "INSERT INTO {$T} ({$colList}) VALUES\n" . implode(",\n", $batch) . ($upd !== '' ? "\nON DUPLICATE KEY UPDATE {$upd}" : '') . ";\n";
        $batch = [];
    };
    while (($r = $rows->fetch()) !== false) {
        $vals = [];
        foreach ($cols as $c) { $vals[] = rops_sql_literal($pdo, $r[$c], strtolower((string) $meta['columns'][$c]['DATA_TYPE'])); }
        $batch[] = '(' . implode(',', $vals) . ')';
        $n++;
        if (count($batch) >= 200) { $flush(); }
        if (strlen($out) > ROPS_DUMP_MAX_BYTES) {
            throw new RopsError('table_too_large', "{$table} 백업이 " . (ROPS_DUMP_MAX_BYTES >> 20) . 'MB 를 넘습니다. phpMyAdmin 으로 내보내세요.');
        }
    }
    $flush();
    rops_storage_guard($pdo, strlen($out));
    $id = rops_snapshot_put($pdo, $logId, $grantId, 'table_dump', $table, $out);
    return ['snapshot' => $id, 'table' => $table, 'rows' => $n, 'bytes' => strlen($out), 'skipped_secret_columns' => $skipped];
}

function rops_db_backup(PDO $pdo, array $ctx, array $p): array
{
    $tables = $p['tables'] ?? [];
    if (!is_array($tables) || $tables === [] || count($tables) > 10) {
        throw new RopsError('bad_params', 'tables: 1~10개');
    }
    $out = [];
    foreach ($tables as $t) {
        $out[] = rops_table_dump_snapshot($pdo, $ctx['log_id'], null, (string) $t);
    }
    return ['result' => ['backups' => $out], 'log' => ['target' => implode(',', array_map('strval', $tables)), 'affected_rows' => array_sum(array_column($out, 'rows'))]];
}

/** 백업 스냅샷 → 복원 SQL 승인 요청(db/sql) */
function rops_db_stage_restore(PDO $pdo, array $p): array
{
    $id = (int) ($p['snapshot'] ?? 0);
    $st = $pdo->prepare("SELECT id FROM remote_ops_snapshots WHERE id = ? AND kind = 'table_dump'");
    $st->execute([$id]);
    if (!$st->fetchColumn()) {
        throw new RopsError('snapshot_not_found', "테이블 백업을 찾을 수 없음: {$id}");
    }
    $st = $pdo->prepare('SELECT encoding, body, ref FROM remote_ops_snapshots WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    $sql = (string) rops_unpack($r['encoding'], $r['body']);
    $p['title'] = (string) ($p['title'] ?? '') !== '' ? $p['title'] : "테이블 복원: {$r['ref']} (백업 #{$id})";
    return rops_db_stage_sql($pdo, $p, $sql, "snapshot:{$id}");
}
