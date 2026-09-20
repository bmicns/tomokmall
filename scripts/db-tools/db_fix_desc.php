<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// description 컬럼 추가
try { $pdo->exec("ALTER TABLE categories ADD COLUMN description VARCHAR(200) NULL AFTER name"); echo "컬럼 추가\n"; }
catch (Exception $e) { echo "컬럼 이미 존재\n"; }

// 카테고리별 설명 업데이트
$descs = [
    '타일 부자재'  => '접착제·줄눈재 등 타일 시공에 필요한 부자재를 공급합니다. 시공 품질을 높이는 전문 자재를 만나보세요.',
    '단열재'      => 'XPS·EPS·우레탄폼 등 다양한 단열 공법의 자재를 제공합니다. 지하·외벽·지붕 등 현장 조건에 맞게 선택하세요.',
    '방수재'      => '옥상·지하·베란다 방수 공사를 위한 전문 자재입니다. 우레탄·시트·도막 공법별 제품을 공급합니다.',
    '타일'        => '포세린·세라믹·천연석 타일을 다양한 규격으로 공급합니다. 주거 및 상업 공간 시공에 최적화된 제품입니다.',
    '철근/시멘트' => '구조물 기초 공사에 필수적인 철근과 시멘트입니다. 강도·규격별로 현장 수요에 맞는 제품을 제공합니다.',
    '배관/설비'   => '급수·배수·오수 배관에 필요한 파이프와 연결 부속입니다. 소재와 규격을 현장 조건에 맞게 선택하세요.',
    '전기/조명'   => '배선 자재부터 조명 기구까지 전기 공사 자재를 공급합니다. 안전 기준을 충족하는 검증된 제품입니다.',
    '창호/실란트' => '창문·문 설치와 기밀 보강에 사용되는 자재입니다. 다양한 실란트와 창호 부자재를 갖추고 있습니다.',
];
$stmt = $pdo->prepare("UPDATE categories SET description = ? WHERE name = ?");
$ok = 0;
foreach ($descs as $name => $desc) {
    $ok += $stmt->execute([$desc, $name]);
}
@unlink(__FILE__);
echo "✅ {$ok}개 카테고리 설명 등록 완료";
