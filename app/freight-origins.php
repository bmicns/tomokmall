<?php
declare(strict_types=1);
/**
 * 제품군(대분류) → 출하 권역(시도) 매핑.
 *
 * 근거(2026-09-21, 케이토목 ktomok.com 실사): 케이토목은 상품별 제조사/출하지를
 *   구분하지 않고 **단일 출하 기준지 = 경기 수원**(본사 권선구 매송고색로 526)에서
 *   출고하며, 운반비도 "본당 3만원(수도권·충청·강원 기준)"으로 이 출하지 기준이다.
 *   → tomokmall 카탈로그도 이 소스를 따르므로 **출하지 = 경기 단일**로 확정.
 *   (제조사별 출하지=옵션 B는 케이토목에도 데이터가 없어 불가함을 확인.)
 *
 * 향후 특정 제품군 공장이 타 권역이면 FREIGHT_ORIGIN_BY_MID / _BY_ROOT 로 override.
 * 시도명은 freight.php 의 FREIGHT_SIDO_COORDS 키와 일치해야 함.
 */

const FREIGHT_DEFAULT_ORIGIN = '경기'; // 케이토목 출하 기준지(수원)

// 대분류명 → 출하 시도 (현재 전부 경기 단일 출하; 세분화 시 개별 수정)
const FREIGHT_ORIGIN_BY_ROOT = [
    '철근콘크리트제품' => '경기',
    '콘크리트제품'     => '경기',
    '석재제품'         => '경기',
    'PE/PVC제품'       => '경기',
    '스틸제품'         => '경기',
    '기타'             => '경기',
];

// 중분류명 → 출하 시도 (선택적 세분화 override. 대분류보다 우선)
const FREIGHT_ORIGIN_BY_MID = [
    // 예) '그레이팅수로용' => '경남',
];

/**
 * 상품의 출하 시도 반환. 중분류 override > 대분류 > 기본값.
 */
function freight_origin_sido(?string $rootName, ?string $midName = null): string
{
    if ($midName !== null && isset(FREIGHT_ORIGIN_BY_MID[$midName])) {
        return FREIGHT_ORIGIN_BY_MID[$midName];
    }
    if ($rootName !== null && isset(FREIGHT_ORIGIN_BY_ROOT[$rootName])) {
        return FREIGHT_ORIGIN_BY_ROOT[$rootName];
    }
    return FREIGHT_DEFAULT_ORIGIN;
}
