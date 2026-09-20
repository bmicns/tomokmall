<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);

// description 컬럼 추가 (없을 때만)
try {
    $pdo->exec("ALTER TABLE home_featured ADD COLUMN description VARCHAR(200) DEFAULT '' AFTER item_name");
} catch (\Throwable $e) {}

// 기본 설명 업데이트
$items = [
    ['플륨관',      'U형·AN형·벤치플륨관 등 콘크리트 수로관 전 규격을 공급합니다.'],
    ['수로관',      '원심력·벤치·각형수로관, 현장 조건에 맞는 제품을 선택하세요.'],
    ['옹벽블록',    '보강토옹벽블록·L형옹벽, 사면 안정과 경관을 동시에 확보합니다.'],
    ['블록',        '인조화강·보강토·호안·잔디블록 등 다양한 블록 제품을 공급합니다.'],
    ['벽돌',        '시멘트벽돌·적벽돌, 건축·조경 시공에 필요한 벽돌 전 규격 공급.'],
    ['그레이팅',    '평판·수로용·U타입 날개형 스틸 그레이팅을 규격별로 공급합니다.'],
    ['돌망태',      '원통형·직사각형·매트형 개비온, 호안·사면 보호 시공에 사용됩니다.'],
    ['PE이중벽관',  '200mm~500mm 이상 PE이중벽관, 하수·배수 배관에 최적화된 제품.'],
];

$st = $pdo->prepare("UPDATE home_featured SET description=? WHERE item_name=?");
foreach ($items as [$name, $desc]) {
    $st->execute([$desc, $name]);
}

echo "✅ description 컬럼 추가 및 기본 설명 입력 완료";
