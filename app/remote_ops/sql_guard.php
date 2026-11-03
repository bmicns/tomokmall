<?php
declare(strict_types=1);
/**
 * 원격 관리 API — SQL 검사기 (app/remote_ops/sql_guard.php)
 *
 * 책임: SQL 문자열을 토큰으로 나눠(문자열·주석 안의 내용은 코드로 보지 않음)
 *       - 여러 문장 분리(;), `-- @verify <SELECT…>` 검증 지시문 추출
 *       - 문장 종류 분류(조회/DML/DDL/금지)와 대상 테이블 추출
 *       - 조회 전용 검사(쓰기·잠금·파일 내보내기·인증정보 컬럼·시스템 스키마 차단)
 * 금지: DB 접속·실행(순수 함수만). 실제 실행 안전장치는 db_ops.php 의 READ ONLY 트랜잭션·서버 측 prepare 가 한 번 더 막는다.
 *
 * 주의: 완전한 SQL 파서가 아니다. 판단이 애매하면 거부(안전한 쪽)로 처리한다.
 */

/** 조회에서 이름이 나오면 거부하는 인증정보 컬럼(대소문자 무시). 결과 컬럼 이름에도 같은 목록으로 가린다. */
const ROPS_SECRET_COLUMNS = ['password_hash', 'password', 'passwd', 'pass_hash', 'api_key', 'secret', 'token',
    'access_token', 'refresh_token', 'remember_token', 'otp_secret', 'auth_token', 'reset_token'];

/** 조회에서 접두로 쓰면 거부하는 시스템 스키마 */
const ROPS_BLOCKED_SCHEMAS = ['mysql', 'sys', 'performance_schema'];

/**
 * 토큰 목록. 각 토큰: ['t'=>종류, 'v'=>원문(따옴표 식별자는 내용), 'u'=>대문자(word만), 'p'=>시작 위치]
 * 종류: word, qident(`…`), str('…' "…"), num, var(@x @@x), ph(?), punct, semi
 * 실행 주석 /*! … *\/ 은 내용을 코드로 토큰화한다(MySQL 이 실행하므로).
 * @return list<array{t:string,v:string,u:string,p:int}>
 */
function rops_sql_tokens(string $sql): array
{
    $out = [];
    $n = strlen($sql);
    $i = 0;
    $execDepth = 0;
    while ($i < $n) {
        $c = $sql[$i];
        $c2 = $i + 1 < $n ? $sql[$i + 1] : '';
        // 공백
        if (ctype_space($c)) { $i++; continue; }
        // 실행 주석 닫기
        if ($execDepth > 0 && $c === '*' && $c2 === '/') { $execDepth--; $i += 2; continue; }
        // 주석
        if ($c === '#' || ($c === '-' && $c2 === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2])))) {
            $e = strpos($sql, "\n", $i);
            $i = $e === false ? $n : $e + 1;
            continue;
        }
        if ($c === '/' && $c2 === '*') {
            if ($i + 2 < $n && $sql[$i + 2] === '!') {
                // /*!40101 … */ → 버전 숫자 건너뛰고 내용은 코드
                $i += 3;
                while ($i < $n && ctype_digit($sql[$i])) { $i++; }
                $execDepth++;
                continue;
            }
            $e = strpos($sql, '*/', $i + 2);
            $i = $e === false ? $n : $e + 2;
            continue;
        }
        $p = $i;
        // 문자열
        if ($c === "'" || $c === '"') {
            $q = $c; $i++; $buf = '';
            while ($i < $n) {
                $ch = $sql[$i];
                if ($ch === '\\' && $i + 1 < $n) { $buf .= $sql[$i + 1]; $i += 2; continue; }
                if ($ch === $q) {
                    if ($i + 1 < $n && $sql[$i + 1] === $q) { $buf .= $q; $i += 2; continue; }
                    $i++;
                    break;
                }
                $buf .= $ch; $i++;
            }
            $out[] = ['t' => 'str', 'v' => $buf, 'u' => '', 'p' => $p];
            continue;
        }
        // 따옴표 식별자
        if ($c === '`') {
            $i++; $buf = '';
            while ($i < $n) {
                if ($sql[$i] === '`') {
                    if ($i + 1 < $n && $sql[$i + 1] === '`') { $buf .= '`'; $i += 2; continue; }
                    $i++;
                    break;
                }
                $buf .= $sql[$i]; $i++;
            }
            $out[] = ['t' => 'qident', 'v' => $buf, 'u' => strtoupper($buf), 'p' => $p];
            continue;
        }
        // 변수
        if ($c === '@') {
            $i++;
            if ($i < $n && $sql[$i] === '@') { $i++; }
            while ($i < $n && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '.' || $sql[$i] === '$')) { $i++; }
            $out[] = ['t' => 'var', 'v' => substr($sql, $p, $i - $p), 'u' => '', 'p' => $p];
            continue;
        }
        if ($c === ';') { $out[] = ['t' => 'semi', 'v' => ';', 'u' => '', 'p' => $p]; $i++; continue; }
        if ($c === '?') { $out[] = ['t' => 'ph', 'v' => '?', 'u' => '', 'p' => $p]; $i++; continue; }
        // 숫자
        if (ctype_digit($c) || ($c === '.' && ctype_digit($c2))) {
            while ($i < $n && (ctype_alnum($sql[$i]) || $sql[$i] === '.')) { $i++; }
            $out[] = ['t' => 'num', 'v' => substr($sql, $p, $i - $p), 'u' => '', 'p' => $p];
            continue;
        }
        // 단어(키워드·식별자). 한글 등 비ASCII 바이트도 식별자로 본다.
        if (ctype_alpha($c) || $c === '_' || $c === '$' || ord($c) >= 0x80) {
            while ($i < $n && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$' || ord($sql[$i]) >= 0x80)) { $i++; }
            $w = substr($sql, $p, $i - $p);
            $out[] = ['t' => 'word', 'v' => $w, 'u' => strtoupper($w), 'p' => $p];
            continue;
        }
        $out[] = ['t' => 'punct', 'v' => $c, 'u' => '', 'p' => $p];
        $i++;
    }
    return $out;
}

/**
 * 여러 문장 분리. 빈 문장은 버린다. 각 원문은 앞뒤 공백 제거.
 * @return list<string>
 */
function rops_sql_split(string $sql): array
{
    $stmts = [];
    $start = 0;
    foreach (rops_sql_tokens($sql) as $t) {
        if ($t['t'] === 'semi') {
            $s = trim(substr($sql, $start, $t['p'] - $start));
            if ($s !== '' && rops_sql_tokens($s) !== []) { $stmts[] = $s; }
            $start = $t['p'] + 1;
        }
    }
    $s = trim(substr($sql, $start));
    if ($s !== '' && rops_sql_tokens($s) !== []) { $stmts[] = $s; }
    return $stmts;
}

/**
 * `-- @verify SELECT …` 줄(변경 전후 비교용 조회) 추출.
 * @return list<string>
 */
function rops_sql_verify_directives(string $sql): array
{
    $out = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*--\s*@verify\s+(.+?)\s*;?\s*$/i', $line, $m)) {
            $out[] = $m[1];
        }
    }
    return $out;
}

/** 토큰 i 위치의 테이블 이름 읽기 → [이름, 다음 위치, 스키마 접두 여부] */
function rops_sql_read_name(array $tk, int $i): array
{
    if (!isset($tk[$i]) || !in_array($tk[$i]['t'], ['word', 'qident'], true)) {
        return [null, $i, false];
    }
    $name = $tk[$i]['v'];
    if (isset($tk[$i + 1], $tk[$i + 2]) && $tk[$i + 1]['v'] === '.' && $tk[$i + 1]['t'] === 'punct'
        && in_array($tk[$i + 2]['t'], ['word', 'qident'], true)) {
        return [$tk[$i + 2]['v'], $i + 3, true];
    }
    return [$name, $i + 1, false];
}

/**
 * 한 문장 분류.
 * @return array{type:string,kw:string,tables:list<string>,multi:bool,qualified:bool,has_where:bool,reason:?string}
 *   type: select | show | set_var | insert | update | delete | replace | ddl | denied
 */
function rops_sql_classify(string $stmt): array
{
    $tk = rops_sql_tokens($stmt);
    $r = ['type' => 'denied', 'kw' => '', 'tables' => [], 'multi' => false, 'qualified' => false, 'has_where' => false, 'reason' => null];
    $i = 0;
    while (isset($tk[$i]) && $tk[$i]['v'] === '(' && $tk[$i]['t'] === 'punct') { $i++; }
    if (!isset($tk[$i]) || $tk[$i]['t'] !== 'word') {
        $r['reason'] = '문장 시작을 알 수 없음';
        return $r;
    }
    $u = static fn(int $k): string => isset($tk[$k]) && $tk[$k]['t'] === 'word' ? $tk[$k]['u'] : '';
    $kw = $u($i);
    $r['kw'] = $kw;

    // 깊이 0 의 WHERE 존재 여부
    $depth = 0;
    foreach ($tk as $t) {
        if ($t['t'] === 'punct' && $t['v'] === '(') { $depth++; }
        elseif ($t['t'] === 'punct' && $t['v'] === ')') { $depth--; }
        elseif ($depth === 0 && $t['t'] === 'word' && $t['u'] === 'WHERE') { $r['has_where'] = true; }
    }

    $addName = static function (array $nm) use (&$r): void {
        if ($nm[0] !== null) { $r['tables'][] = $nm[0]; }
        if ($nm[2]) { $r['qualified'] = true; }
    };

    switch ($kw) {
        case 'SELECT':
        case 'WITH':
            // WITH … 다음 본문이 SELECT 인지 확인(깊이 0 의 첫 DML/SELECT 키워드)
            if ($kw === 'WITH') {
                $d = 0; $main = '';
                for ($k = $i + 1; isset($tk[$k]); $k++) {
                    if ($tk[$k]['v'] === '(' && $tk[$k]['t'] === 'punct') { $d++; continue; }
                    if ($tk[$k]['v'] === ')' && $tk[$k]['t'] === 'punct') { $d--; continue; }
                    if ($d === 0 && in_array($u($k), ['SELECT', 'UPDATE', 'DELETE', 'INSERT', 'REPLACE'], true)) { $main = $u($k); break; }
                }
                if ($main !== 'SELECT') { $r['reason'] = 'WITH 문은 조회(SELECT)만 허용'; return $r; }
            }
            $r['type'] = 'select';
            return $r;
        case 'SHOW':
        case 'DESCRIBE':
        case 'DESC':
        case 'EXPLAIN':
            $r['type'] = 'show';
            return $r;
        case 'SET':
            if (isset($tk[$i + 1]) && $tk[$i + 1]['t'] === 'var' && !str_starts_with($tk[$i + 1]['v'], '@@')) {
                $r['type'] = 'set_var';
                return $r;
            }
            $r['reason'] = 'SET 은 사용자 변수(@이름)만 허용';
            return $r;
        case 'INSERT':
        case 'REPLACE':
            $k = $i + 1;
            while (in_array($u($k), ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'], true)) { $k++; }
            if ($u($k) === 'INTO') { $k++; }
            $nm = rops_sql_read_name($tk, $k);
            if ($nm[0] === null) { $r['reason'] = '대상 테이블을 알 수 없음'; return $r; }
            $addName($nm);
            $r['type'] = strtolower($kw);
            return $r;
        case 'UPDATE':
            $k = $i + 1;
            while (in_array($u($k), ['LOW_PRIORITY', 'IGNORE'], true)) { $k++; }
            $nm = rops_sql_read_name($tk, $k);
            if ($nm[0] === null) { $r['reason'] = '대상 테이블을 알 수 없음'; return $r; }
            $addName($nm);
            // SET 전까지 쉼표·JOIN 이 있으면 여러 테이블 UPDATE
            for ($k = $nm[1]; isset($tk[$k]) && $u($k) !== 'SET'; $k++) {
                if ($tk[$k]['v'] === ',' || in_array($u($k), ['JOIN', 'STRAIGHT_JOIN'], true)) { $r['multi'] = true; }
            }
            $r['type'] = 'update';
            return $r;
        case 'DELETE':
            $k = $i + 1;
            while (in_array($u($k), ['LOW_PRIORITY', 'QUICK', 'IGNORE'], true)) { $k++; }
            if ($u($k) !== 'FROM') { $r['multi'] = true; $r['type'] = 'delete'; $r['reason'] = null; return $r; }
            $nm = rops_sql_read_name($tk, $k + 1);
            if ($nm[0] === null) { $r['reason'] = '대상 테이블을 알 수 없음'; return $r; }
            $addName($nm);
            for ($k = $nm[1]; isset($tk[$k]) && $u($k) !== 'WHERE'; $k++) {
                if ($tk[$k]['v'] === ',' || in_array($u($k), ['JOIN', 'USING', 'STRAIGHT_JOIN'], true)) { $r['multi'] = true; }
            }
            $r['type'] = 'delete';
            return $r;
        case 'CREATE':
            $k = $i + 1;
            if ($u($k) === 'TEMPORARY') { $r['reason'] = 'CREATE TEMPORARY TABLE 은 허용하지 않음'; return $r; }
            if ($u($k) === 'TABLE') {
                $k++;
                if ($u($k) === 'IF' && $u($k + 1) === 'NOT' && $u($k + 2) === 'EXISTS') { $k += 3; }
                $addName(rops_sql_read_name($tk, $k));
                $r['type'] = 'ddl';
                return $r;
            }
            while (in_array($u($k), ['UNIQUE', 'FULLTEXT', 'SPATIAL'], true)) { $k++; }
            if ($u($k) === 'INDEX') {
                for ($k++; isset($tk[$k]) && $u($k) !== 'ON'; $k++) { }
                $addName(rops_sql_read_name($tk, $k + 1));
                $r['type'] = 'ddl';
                return $r;
            }
            $r['reason'] = 'CREATE 는 TABLE·INDEX 만 허용';
            return $r;
        case 'ALTER':
            $k = $i + 1;
            if ($u($k) === 'IGNORE') { $k++; }
            if ($u($k) !== 'TABLE') { $r['reason'] = 'ALTER 는 TABLE 만 허용'; return $r; }
            $addName(rops_sql_read_name($tk, $k + 1));
            $r['type'] = 'ddl';
            return $r;
        case 'DROP':
            $k = $i + 1;
            if ($u($k) === 'TABLE') {
                $k++;
                if ($u($k) === 'IF' && $u($k + 1) === 'EXISTS') { $k += 2; }
                do {
                    $nm = rops_sql_read_name($tk, $k);
                    $addName($nm);
                    $k = $nm[1];
                    $more = isset($tk[$k]) && $tk[$k]['v'] === ',';
                    $k++;
                } while ($more && $nm[0] !== null);
                $r['type'] = 'ddl';
                return $r;
            }
            if ($u($k) === 'INDEX') {
                for ($k++; isset($tk[$k]) && $u($k) !== 'ON'; $k++) { }
                $addName(rops_sql_read_name($tk, $k + 1));
                $r['type'] = 'ddl';
                return $r;
            }
            $r['reason'] = 'DROP 은 TABLE·INDEX 만 허용';
            return $r;
        case 'TRUNCATE':
            $k = $i + 1;
            if ($u($k) === 'TABLE') { $k++; }
            $addName(rops_sql_read_name($tk, $k));
            $r['type'] = 'ddl';
            return $r;
        case 'RENAME':
            if ($u($i + 1) !== 'TABLE') { $r['reason'] = 'RENAME 은 TABLE 만 허용'; return $r; }
            $k = $i + 2;
            while (true) {
                $a = rops_sql_read_name($tk, $k);
                $addName($a);
                if ($u($a[1]) !== 'TO') { break; }
                $b = rops_sql_read_name($tk, $a[1] + 1);
                $addName($b);
                $k = $b[1];
                if (!isset($tk[$k]) || $tk[$k]['v'] !== ',') { break; }
                $k++;
            }
            $r['type'] = 'ddl';
            return $r;
    }
    $r['reason'] = "허용하지 않는 문장: {$kw}";
    return $r;
}

/** 모든 문장 공통 금지: 파일 내보내기·읽기 */
function rops_sql_common_problem(array $tk): ?string
{
    foreach ($tk as $k => $t) {
        if ($t['t'] !== 'word') { continue; }
        $next = $tk[$k + 1]['u'] ?? '';
        if ($t['u'] === 'INTO' && in_array($next, ['OUTFILE', 'DUMPFILE'], true)) { return 'INTO OUTFILE/DUMPFILE 금지'; }
        if ($t['u'] === 'LOAD_FILE') { return 'LOAD_FILE 금지'; }
        if (in_array($t['u'], ['SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'MASTER_POS_WAIT', 'SOURCE_POS_WAIT'], true)
            && (($tk[$k + 1]['v'] ?? '') === '(')) {
            return "{$t['u']}() 금지";
        }
    }
    return null;
}

/**
 * 조회 전용 SQL 검사(db.query). 통과하면 null, 아니면 이유.
 */
function rops_sql_read_problem(string $sql): ?string
{
    $stmts = rops_sql_split($sql);
    if (count($stmts) !== 1) {
        return count($stmts) === 0 ? 'SQL 이 비어 있음' : '조회는 한 번에 한 문장만';
    }
    $c = rops_sql_classify($stmts[0]);
    if (!in_array($c['type'], ['select', 'show'], true)) {
        return $c['reason'] ?? '조회(SELECT·SHOW·DESCRIBE·EXPLAIN)만 허용';
    }
    $tk = rops_sql_tokens($stmts[0]);
    if (($p = rops_sql_common_problem($tk)) !== null) { return $p; }
    $secret = array_flip(ROPS_SECRET_COLUMNS);
    foreach ($tk as $k => $t) {
        if ($t['t'] === 'word' || $t['t'] === 'qident') {
            $low = strtolower($t['v']);
            if (isset($secret[$low])) { return "인증정보 컬럼({$low})은 조회할 수 없음"; }
            if (in_array($low, ROPS_BLOCKED_SCHEMAS, true) && (($tk[$k + 1]['v'] ?? '') === '.')) { return "시스템 스키마({$low}) 조회 금지"; }
        }
        if ($t['t'] !== 'word') { continue; }
        // INSERT( · REPLACE( 는 문자열 함수라 허용, 그 외 위치의 DML 키워드는 거부
        $isCall = (($tk[$k + 1]['v'] ?? '') === '(');
        if ((in_array($t['u'], ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true) && !$isCall)
            || in_array($t['u'], ['INTO', 'LOCK', 'CALL', 'HANDLER'], true)) {
            return "조회에 쓸 수 없는 키워드: {$t['u']}";
        }
        if ($t['u'] === 'FOR' && in_array($tk[$k + 1]['u'] ?? '', ['UPDATE', 'SHARE'], true)) { return 'FOR UPDATE/SHARE 잠금 금지'; }
        if ($t['u'] === 'ANALYZE' && $c['kw'] === 'EXPLAIN') { return 'EXPLAIN ANALYZE 금지(실제 실행됨)'; }
    }
    if ($c['kw'] === 'SHOW') {
        // SHOW 는 구조·상태 조회만. 계정·권한·바이너리 로그 등은 거부
        $second = '';
        foreach ($tk as $k => $t) { if ($k > 0 && $t['t'] === 'word') { $second = $t['u']; break; } }
        if (in_array($second, ['GRANTS', 'PROCESSLIST', 'BINLOG', 'BINARY', 'MASTER', 'SLAVE', 'REPLICA', 'REPLICAS', 'RELAYLOG', 'ENGINE', 'PRIVILEGES', 'PROFILE', 'PROFILES'], true)) {
            return "SHOW {$second} 금지";
        }
    }
    return null;
}

/**
 * SQL 파일 실행 계획(db.execute / 승인 요청). 실행하지 않고 분류만 한다.
 * @return array{statements:list<array>,verify:list<string>,has_ddl:bool,problems:list<string>,warnings:list<string>,tables:list<string>}
 */
function rops_sql_plan(string $sql): array
{
    $plan = ['statements' => [], 'verify' => [], 'has_ddl' => false, 'problems' => [], 'warnings' => [], 'tables' => []];
    foreach (rops_sql_verify_directives($sql) as $v) {
        if (($p = rops_sql_read_problem($v)) !== null) {
            $plan['problems'][] = "@verify 조회 오류: {$p}";
        } else {
            $plan['verify'][] = $v;
        }
    }
    $stmts = rops_sql_split($sql);
    if ($stmts === []) {
        $plan['problems'][] = '실행할 문장이 없음';
    }
    if (preg_match('/^\s*DELIMITER\b/im', $sql)) {
        $plan['problems'][] = 'DELIMITER(프로시저·트리거 정의)는 지원하지 않음';
    }
    foreach ($stmts as $n => $s) {
        $c = rops_sql_classify($s);
        $no = $n + 1;
        if (($p = rops_sql_common_problem(rops_sql_tokens($s))) !== null) {
            $plan['problems'][] = "{$no}번 문장: {$p}";
        }
        if ($c['type'] === 'denied') {
            $plan['problems'][] = "{$no}번 문장: " . ($c['reason'] ?? '허용하지 않는 문장');
        } elseif ($c['type'] === 'show') {
            $plan['problems'][] = "{$no}번 문장: SHOW/DESCRIBE/EXPLAIN 은 SQL 파일 실행에 쓰지 않음(db query 사용)";
        }
        if ($c['qualified']) {
            $plan['problems'][] = "{$no}번 문장: 다른 DB(스키마.테이블) 지정 금지";
        }
        if ($c['type'] === 'ddl') {
            $plan['has_ddl'] = true;
        }
        if (in_array($c['type'], ['update', 'delete'], true)) {
            if ($c['multi']) {
                $plan['warnings'][] = "{$no}번 문장: 여러 테이블 {$c['kw']}";
            }
            if (!$c['has_where']) {
                $plan['warnings'][] = "{$no}번 문장: WHERE 없는 {$c['kw']} (테이블 전체 대상)";
            }
        }
        foreach ($c['tables'] as $t) {
            if (!in_array($t, $plan['tables'], true)) { $plan['tables'][] = $t; }
        }
        $plan['statements'][] = ['no' => $no, 'sql' => $s] + $c;
    }
    return $plan;
}

/** 식별자(테이블·컬럼) 이름 형식 검사 — 실제 존재 여부는 information_schema 로 따로 확인 */
function rops_ident_ok(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name);
}

function rops_qi(string $name): string
{
    if (!rops_ident_ok($name)) {
        throw new \InvalidArgumentException('잘못된 식별자');
    }
    return '`' . $name . '`';
}

/** 결과 컬럼 이름이 인증정보인지(가림 대상) */
function rops_is_secret_column(string $name): bool
{
    $low = strtolower($name);
    return in_array($low, ROPS_SECRET_COLUMNS, true) || (bool) preg_match('/pass(word|wd)?(_hash)?$|secret|token|api_?key/', $low);
}

/** 개인정보로 보고 기본으로 가리는 컬럼 이름(--reveal-pii 로만 표시) */
function rops_is_pii_column(string $name, string $table = ''): bool
{
    $low = strtolower($name);
    $pii = ['email', 'phone', 'mobile', 'tel', 'fax', 'address', 'addr', 'address_detail', 'site_address', 'customer_name',
        'contact', 'contact_name', 'recipient', 'recipient_name', 'receiver', 'receiver_name', 'receiver_phone',
        'zipcode', 'postcode', 'zip', 'biz_no', 'business_no', 'client_ip', 'ip'];
    if (in_array($low, $pii, true)) {
        return true;
    }
    return $low === 'name' && in_array(strtolower($table), ['users', 'u'], true);
}

/**
 * 구조화 WHERE → SQL 조각(자리표시자 ?). 컬럼은 $columns(실제 존재하는 컬럼)만 허용.
 * 입력: {"col": 값} (값이 배열이면 IN, null 이면 IS NULL)  또는  [["col","연산자",값], …]
 * 연산자: = != <> < <= > >= LIKE NOT LIKE IN NOT IN IS NULL IS NOT NULL BETWEEN
 * @param array<string> $columns
 * @param list<mixed> $params 바인딩 값이 뒤에 추가된다
 */
function rops_where_compile(array $where, array $columns, array &$params): string
{
    $conds = [];
    $isList = array_is_list($where);
    $items = [];
    if ($isList) {
        foreach ($where as $w) {
            if (!is_array($w) || count($w) < 2) { throw new \InvalidArgumentException('where 항목 형식: [컬럼, 연산자, 값]'); }
            $items[] = [(string) $w[0], strtoupper(trim((string) $w[1])), $w[2] ?? null];
        }
    } else {
        foreach ($where as $col => $v) {
            $items[] = [(string) $col, is_array($v) ? 'IN' : ($v === null ? 'IS NULL' : '='), $v];
        }
    }
    foreach ($items as [$col, $op, $v]) {
        if (!in_array($col, $columns, true)) { throw new \InvalidArgumentException("없는 컬럼: {$col}"); }
        $q = rops_qi($col);
        switch ($op) {
            case '=': case '!=': case '<>': case '<': case '<=': case '>': case '>=': case 'LIKE': case 'NOT LIKE':
                if (!is_scalar($v)) { throw new \InvalidArgumentException("{$col} {$op}: 값은 하나(문자·숫자)"); }
                $conds[] = "{$q} {$op} ?";
                $params[] = is_bool($v) ? (int) $v : $v;
                break;
            case 'IN': case 'NOT IN':
                if (!is_array($v) || $v === [] || count($v) > 5000) { throw new \InvalidArgumentException("{$col} {$op}: 값 목록 1~5000개"); }
                foreach ($v as $x) { if (!is_scalar($x)) { throw new \InvalidArgumentException("{$col} {$op}: 목록 값은 문자·숫자"); } $params[] = $x; }
                $conds[] = "{$q} {$op} (" . implode(',', array_fill(0, count($v), '?')) . ')';
                break;
            case 'IS NULL': case 'IS NOT NULL':
                $conds[] = "{$q} {$op}";
                break;
            case 'BETWEEN':
                if (!is_array($v) || count($v) !== 2 || !is_scalar($v[0]) || !is_scalar($v[1])) { throw new \InvalidArgumentException("{$col} BETWEEN: [최소, 최대]"); }
                $conds[] = "{$q} BETWEEN ? AND ?";
                $params[] = $v[0];
                $params[] = $v[1];
                break;
            default:
                throw new \InvalidArgumentException("허용하지 않는 연산자: {$op}");
        }
    }
    if ($conds === []) { throw new \InvalidArgumentException('where 조건이 비어 있음'); }
    return implode(' AND ', $conds);
}
