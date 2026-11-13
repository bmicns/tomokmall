<?php
declare(strict_types=1);
/**
 * 제품관리 '업체'(상품 출처) 목록 — 관리자 제품관리 첫 번째 드롭다운(사용자 결정 2026-11-13)
 *
 * 책임: 업체 코드 ↔ 저장 위치를 정한다.
 *   - 케이토목(ktomok)   : 고객용 상품 표 products 의 상용콘크리트 반영본이 아닌 행(출처 ktomok·dacity·없음)
 *   - 상용콘크리트(syccrt): 원본 표 syc_products 의 출처 syccrt
 *   - 업체 목록(vendors)의 활성 업체: syc_products 의 출처 'vendor-<업체 id>'(DB 구조 변경 없이 출처로 구분)
 *   덮어쓰기(일괄 등록)는 같은 업체(출처) 안에서만 일어난다 — 다른 업체 상품은 건드리지 않는다.
 * 금지: 고객 화면 출처 바꾸기(고객 화면은 상용콘크리트 고정 — app/syc_publish.php 기능은 화면에서 뺐고 코드만 둠).
 * 사용: public/admin-products.php, public/api/admin/syc-products.php, public/api/admin/products.php
 */

const PRODUCT_SOURCE_KTOMOK = 'ktomok';
const PRODUCT_SOURCE_SYC    = 'syccrt';

/**
 * 활성/비활성 바꾸기(목록·편집 창·일괄 버튼) — 화면은 보이되 기능은 멈춤(사용자 결정 2026-11-13, 추후 개발).
 * true 로 바꾸면 기존 기능이 그대로 돌아온다.
 */
const PRODUCT_ACTIVE_TOGGLE_ENABLED = false;
const PRODUCT_ACTIVE_TOGGLE_OFF_MSG = '활성/비활성 변경은 추후 지원 예정입니다.';

/** 업체 목록 업체의 출처 코드 */
function product_source_vendor_code(int $vendorId): string
{
    return 'vendor-' . $vendorId;
}

/**
 * 드롭다운 순서대로: 케이토목 → 상용콘크리트 → 업체 목록의 활성 업체(등록 순).
 * @return list<array{code:string,name:string,store:string}> store = 'products'(케이토목) | 'syc'(syc_products)
 */
function product_sources(PDO $pdo): array
{
    $out = [
        ['code' => PRODUCT_SOURCE_KTOMOK, 'name' => '케이토목', 'store' => 'products'],
        ['code' => PRODUCT_SOURCE_SYC,    'name' => '상용콘크리트', 'store' => 'syc'],
    ];
    try {
        foreach ($pdo->query('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY id') as $v) {
            $out[] = ['code' => product_source_vendor_code((int) $v['id']), 'name' => (string) $v['name'], 'store' => 'syc'];
        }
    } catch (PDOException $e) {
        error_log('[product_sources] vendors 표를 읽지 못함: ' . $e->getCode());   // 표가 없으면 기본 2개만
    }
    return $out;
}

/** 코드로 업체 찾기(활성 업체만). 없으면 null */
function product_source_find(PDO $pdo, string $code): ?array
{
    foreach (product_sources($pdo) as $s) {
        if ($s['code'] === $code) { return $s; }
    }
    return null;
}

/** syc_products 에 저장하는 업체(상용콘크리트·업체 목록)만. 아니면 InvalidArgumentException */
function product_source_syc_or_fail(PDO $pdo, string $code): array
{
    $s = product_source_find($pdo, $code);
    if ($s === null || $s['store'] !== 'syc') { throw new InvalidArgumentException('업체를 다시 선택해 주세요.'); }
    return $s;
}

/**
 * 같은 업체 안에서 '같은 제품'을 가리는 열쇠(출처ID가 없을 때): 제품명 + 규격 + 옵션(사용자 결정 2026-11-13, S5 보조 기준).
 * 앞뒤 공백·연속 공백만 정리하고 대소문자·글자는 그대로. 순수 함수
 */
function product_match_key(?string $name, ?string $spec, ?string $option): string
{
    $n = static fn(?string $v): string => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    return $n($name) . "\0" . $n($spec) . "\0" . $n($option);
}

/** 출처ID 없이 들어온 상품에 붙일 출처ID(같은 열쇠 → 같은 ID, 다시 올려도 같은 행). 순수 함수 */
function product_auto_external_id(string $matchKey): string
{
    return 'auto-' . substr(hash('sha256', $matchKey), 0, 24);
}
