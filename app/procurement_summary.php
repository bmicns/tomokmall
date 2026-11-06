<?php
declare(strict_types=1);
/**
 * 자재 종류별 공공조달 등록 현황(집계) — 생성: scripts/external/g2b_summary.php (손으로 고치지 않음)
 * 출처: 조달청 조달데이터허브 「나라장터쇼핑몰 품목 등록 내역」(보고서 UI-ADOXFA-183R, 공공데이터포털 15142663 — 이용허락범위 제한 없음), 출력일자 2026-11-05
 * 사용: app/ai_tools.php ai_procurement_summary — 가격·업체 이름 없음
 */
return array (
  'source' => '조달청 조달데이터허브 「나라장터쇼핑몰 품목 등록 내역」(보고서 UI-ADOXFA-183R, 공공데이터포털 15142663 — 이용허락범위 제한 없음)',
  'url' => 'https://www.data.go.kr/data/15142663/fileData.do',
  'scope' => '대분류 토목/건축/자재 · 쇼핑몰 계약 해당 품목 — 물품식별번호 기준 중복 제거, 우리 취급 자재 세부품명만',
  'as_of' => '2026-11-05',
  'note' => '공공기관 구매용 조달몰에 등록된 다른 회사 품목의 집계 — 토목몰 상품의 인증·조달 등록 여부가 아니다. 가격·업체 이름은 넣지 않음.',
  'groups' => 
  array (
    'steel_grating' => 
    array (
      'detail' => '스틸그레이팅',
      'label' => '스틸그레이팅',
      'words' => 
      array (
        0 => '그레이팅',
      ),
      'items' => 5491,
      'makers' => 25,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 2102,
        'excellent' => 107,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2025-09-10',
      'registered_to' => '2026-10-29',
    ),
    'manhole_block' => 
    array (
      'detail' => '콘크리트맨홀블록',
      'label' => '콘크리트 맨홀블록',
      'words' => 
      array (
        0 => '맨홀블록',
        1 => '원형맨홀',
        2 => '사각맨홀',
        3 => '조립식맨홀',
        4 => '맨홀',
      ),
      'items' => 1968,
      'makers' => 18,
      'certs' => 
      array (
        'ks' => 906,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 25,
      ),
      'registered_from' => '2025-04-21',
      'registered_to' => '2026-10-28',
    ),
    'curb_stone' => 
    array (
      'detail' => '자연석경계석',
      'label' => '자연석 경계석',
      'words' => 
      array (
        0 => '경계석',
        1 => '화강경계',
      ),
      'items' => 1507,
      'makers' => 22,
      'certs' => 
      array (
        'ks' => 53,
        'group_standard' => 617,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2025-02-20',
      'registered_to' => '2026-11-02',
    ),
    'culvert_block' => 
    array (
      'detail' => '조립식철근콘크리트암거블록',
      'label' => '조립식 철근콘크리트 암거블록',
      'words' => 
      array (
        0 => '암거',
      ),
      'items' => 1138,
      'makers' => 10,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 791,
        'excellent' => 202,
        'quality_assured' => 345,
        'eco_label' => 0,
      ),
      'registered_from' => '2024-03-05',
      'registered_to' => '2026-10-27',
    ),
    'drain_channel' => 
    array (
      'detail' => '철근콘크리트용배수로관',
      'label' => '철근콘크리트 배수로관(수로관)',
      'words' => 
      array (
        0 => '수로관',
        1 => '배수로관',
        2 => 'U형',
        3 => '측구',
      ),
      'items' => 683,
      'makers' => 20,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 582,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 107,
      ),
      'registered_from' => '2025-04-07',
      'registered_to' => '2026-09-21',
    ),
    'paving_block' => 
    array (
      'detail' => '보차도용콘크리트블록',
      'label' => '보차도용 콘크리트블록',
      'words' => 
      array (
        0 => '보도블록',
        1 => '보차도',
        2 => '인터로킹',
        3 => '블록포장',
        4 => '보도블럭',
      ),
      'items' => 631,
      'makers' => 23,
      'certs' => 
      array (
        'ks' => 548,
        'group_standard' => 0,
        'excellent' => 26,
        'quality_assured' => 81,
        'eco_label' => 118,
      ),
      'registered_from' => '2024-08-16',
      'registered_to' => '2026-10-27',
    ),
    'retaining_block' => 
    array (
      'detail' => '콘크리트호안및옹벽블록',
      'label' => '콘크리트 호안·옹벽블록',
      'words' => 
      array (
        0 => '호안',
        1 => '옹벽',
        2 => '보강토',
        3 => '식생블록',
        4 => '식생축조',
      ),
      'items' => 482,
      'makers' => 45,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 344,
        'excellent' => 18,
        'quality_assured' => 21,
        'eco_label' => 24,
      ),
      'registered_from' => '2024-01-11',
      'registered_to' => '2026-11-04',
    ),
    'corrugated_pipe' => 
    array (
      'detail' => '파형강관',
      'label' => '파형강관',
      'words' => 
      array (
        0 => '파형강관',
      ),
      'items' => 466,
      'makers' => 2,
      'certs' => 
      array (
        'ks' => 465,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2026-07-03',
      'registered_to' => '2026-10-27',
    ),
    'pe_pipe' => 
    array (
      'detail' => '일반용폴리에틸렌관',
      'label' => '일반용 폴리에틸렌관(PE관)',
      'words' => 
      array (
        0 => 'PE관',
        1 => '이중벽관',
        2 => '다중벽관',
        3 => '폴리에틸렌관',
        4 => 'HDPE',
        5 => '유공관',
      ),
      'items' => 282,
      'makers' => 9,
      'certs' => 
      array (
        'ks' => 70,
        'group_standard' => 41,
        'excellent' => 26,
        'quality_assured' => 82,
        'eco_label' => 14,
      ),
      'registered_from' => '2024-05-22',
      'registered_to' => '2026-09-03',
    ),
    'bench_flume' => 
    array (
      'detail' => '철근콘크리트벤치플룸관',
      'label' => '철근콘크리트 벤치플룸관',
      'words' => 
      array (
        0 => '벤치플룸',
        1 => '벤치플륨',
        2 => '벤치',
      ),
      'items' => 197,
      'makers' => 17,
      'certs' => 
      array (
        'ks' => 163,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 18,
      ),
      'registered_from' => '2025-05-09',
      'registered_to' => '2026-09-21',
    ),
    'manhole_cover' => 
    array (
      'detail' => '주철맨홀뚜껑',
      'label' => '주철 맨홀뚜껑',
      'words' => 
      array (
        0 => '맨홀뚜껑',
        1 => '주철뚜껑',
        2 => '맨홀 뚜껑',
      ),
      'items' => 190,
      'makers' => 8,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 169,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2025-12-03',
      'registered_to' => '2026-11-04',
    ),
    'hume_pipe' => 
    array (
      'detail' => '원심력철근콘크리트관',
      'label' => '원심력 철근콘크리트관(흄관)',
      'words' => 
      array (
        0 => '흄관',
        1 => '원심력',
      ),
      'items' => 176,
      'makers' => 9,
      'certs' => 
      array (
        'ks' => 146,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2026-07-03',
      'registered_to' => '2026-10-21',
    ),
    'pvc_pressure_pipe' => 
    array (
      'detail' => '압력용경질폴리염화비닐관',
      'label' => '압력용 경질 PVC관',
      'words' => 
      array (
        0 => 'PVC관',
        1 => 'PVC 관',
        2 => '수도관',
      ),
      'items' => 117,
      'makers' => 4,
      'certs' => 
      array (
        'ks' => 54,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 80,
        'eco_label' => 0,
      ),
      'registered_from' => '2026-09-08',
      'registered_to' => '2026-10-01',
    ),
    'grating_cover' => 
    array (
      'detail' => '그레이팅덮개',
      'label' => '그레이팅덮개',
      'words' => 
      array (
        0 => '그레이팅덮개',
        1 => '그레이팅 덮개',
      ),
      'items' => 103,
      'makers' => 15,
      'certs' => 
      array (
        'ks' => 0,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2024-02-29',
      'registered_to' => '2026-10-04',
    ),
    'pvc_pipe' => 
    array (
      'detail' => '비압력용경질폴리염화비닐관',
      'label' => '비압력용 경질 PVC관',
      'words' => 
      array (
        0 => 'PVC관',
        1 => 'PVC 관',
        2 => '경질염화비닐',
        3 => '경질폴리염화비닐',
      ),
      'items' => 91,
      'makers' => 4,
      'certs' => 
      array (
        'ks' => 27,
        'group_standard' => 24,
        'excellent' => 0,
        'quality_assured' => 59,
        'eco_label' => 0,
      ),
      'registered_from' => '2026-09-08',
      'registered_to' => '2026-09-08',
    ),
    'tactile_block' => 
    array (
      'detail' => '안전유도블록',
      'label' => '안전유도블록(점형·선형)',
      'words' => 
      array (
        0 => '점자블록',
        1 => '점자블럭',
        2 => '유도블록',
        3 => '점형블록',
        4 => '선형블록',
      ),
      'items' => 70,
      'makers' => 11,
      'certs' => 
      array (
        'ks' => 61,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 9,
      ),
      'registered_from' => '2026-02-12',
      'registered_to' => '2026-10-22',
    ),
    'curb_block' => 
    array (
      'detail' => '콘크리트경계블록',
      'label' => '콘크리트 경계블록',
      'words' => 
      array (
        0 => '경계석',
        1 => '경계블록',
        2 => '경계블럭',
      ),
      'items' => 17,
      'makers' => 4,
      'certs' => 
      array (
        'ks' => 16,
        'group_standard' => 0,
        'excellent' => 0,
        'quality_assured' => 0,
        'eco_label' => 0,
      ),
      'registered_from' => '2025-01-13',
      'registered_to' => '2026-10-14',
    ),
  ),
);
