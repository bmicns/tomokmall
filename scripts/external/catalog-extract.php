<?php
declare(strict_types=1);
/**
 * 제조사 카탈로그 PDF(또는 PDF 에서 뽑은 글) → 수집기와 같은 JSON(data/external/crawl/<출처>-catalog-*.json)
 *
 * 책임: 사용 허락을 받은 제조사 카탈로그(sources.json permission 'allowed' — basis 에 허락 날짜·방식·범위 기록)만 저장.
 *       쪽마다 ① 제품 제목(자재 종류가 읽히는 짧은 줄) ② 규격 줄(치수 표기가 있는 표 행) ③ 원문 문장(용도·특징·주의·인증·강도 —
 *       app/external_crawl.php ext_page_facts 와 같은 분류)을 뽑아 수집기 형식 레코드로 만든다. 출처 표시: catalog:<파일명>#p<쪽>.
 *       이후는 기존 순서 그대로: import-crawled.php(정리·중복·매칭·검증 구역) → review.md → approve-crawled.php(문장 단위 사람 승인)
 *       → 승인분만 상담 material_info 에 '○○ 자료에 따르면'으로. 운영 이관은 export-approved.php(승인 후).
 * 금지: 허락 전(pending·blocked) 출처 저장(--dry-run 분석만), 가격 열·가격 문장 저장(가져오기 단계에서도 버림), 문장 고쳐 쓰기·생성,
 *       이미지 추출. 엑셀·CSV 카탈로그는 이 도구가 아니라 import.php --source=… --file=…xlsx 로 바로 가져온다.
 * 사용: php scripts/external/catalog-extract.php --source=<출처키> --file=카탈로그.pdf [--file=두번째.pdf …] [--maker='제조사명'] [--out=폴더] [--dry-run]
 *       한 출처의 카탈로그가 여러 파일이면 --file 을 여러 번 — 한 JSON 으로 합친다(import-crawled.php 는 출처별 최신 JSON 1개만 읽음).
 *       출처 키는 제조사 공개 페이지 수집 키와 따로(예: dyhumepipe_catalog) — 허락 근거가 다르고, 같은 키면 최신 파일만 읽혀 한쪽이 빠진다.
 *       (PDF 는 pdftotext 필요 — 이 클라우드 세션에 설치됨. 사용자 PC 에 없으면 카탈로그를 세션에 올려 주시면 여기서 처리)
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";
ob_end_clean();
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";
require_once "{$root}/app/external_crawl.php";
require_once __DIR__ . '/catalog_lib.php';

$opt = getopt('', ['source:', 'file:', 'maker:', 'out:', 'dry-run']);
$die = static function (string $m, int $code = 2): never { fwrite(STDERR, "[중단] {$m}\n"); exit($code); };
if (empty($opt['source']) || empty($opt['file'])) {
    $die("사용법: --source=<출처키> --file=카탈로그.pdf|.txt [--maker=…] [--out=…] [--dry-run]");
}
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);
$src = array_column($reg['sources'] ?? [], null, 'key')[(string) $opt['source']] ?? null;
if ($src === null) {
    $die("등록부(data/external/sources.json)에 '{$opt['source']}' 출처가 없습니다. 허락 기록(제조사·날짜·방식·범위)과 함께 먼저 등록하세요.");
}
$dry = isset($opt['dry-run']);
if (($src['permission'] ?? '') !== 'allowed' && !$dry) {
    $die("'{$opt['source']}' 는 사용 허락 기록이 없습니다(permission: " . ($src['permission'] ?? '-') . "). 허락을 받으면 permission 을 allowed 로, basis 에 허락 근거를 적으세요. 지금은 --dry-run 분석만 가능합니다.");
}
$maker = (string) ($opt['maker'] ?? $src['maker'] ?? $src['name'] ?? $opt['source']);   // 상담에서 '○○ 자료에 따르면' 의 ○○ — 등록부 maker(제조사 이름)를 먼저
$records = [];
$pages = 0;
foreach ((array) $opt['file'] as $file) {
    $file = (string) $file;
    if (!is_readable($file)) { $die("파일을 읽을 수 없습니다: {$file}"); }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'csv'], true)) {
        $die("엑셀·CSV 카탈로그는 import.php 로 바로 가져오세요: php scripts/external/import.php --show-columns --file=" . escapeshellarg($file) . " → php scripts/external/import.php --source={$opt['source']} --file=" . escapeshellarg($file) . ' --license-checked');
    }
    if ($ext === 'pdf') {
        if (!trim((string) shell_exec('command -v pdftotext'))) { $die('pdftotext 가 없습니다(poppler-utils). 카탈로그를 클라우드 세션에 올려 주시면 여기서 처리합니다.'); }
        $text = (string) shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($file) . ' - 2>/dev/null');
    } elseif ($ext === 'txt') {
        $text = (string) file_get_contents($file);   // pdftotext -layout 으로 미리 뽑은 글 — 쪽 구분은 \f
    } else {
        $die("PDF(.pdf)·뽑은 글(.txt)만 처리합니다: {$file}" . ($ext === 'hwp' ? ' — 한글(HWP) 카탈로그는 PDF 로 저장해 주세요.' : ''));
    }
    if (trim($text) === '') { $die("글자를 뽑지 못했습니다(이미지로만 된 PDF 일 수 있음 — 글자 PDF 나 엑셀을 요청하세요): {$file}"); }
    $records = array_merge($records, catalog_records($text, $maker, 'catalog:' . basename($file)));
    $pages += count(catalog_pages($text));
}
$facts = array_sum(array_map(static fn($r) => array_sum(array_map('count', $r['facts'])), $records));
echo "쪽 {$pages} · 레코드 " . count($records) . " · 원문 문장(레코드별 합) {$facts}\n";
foreach (array_slice($records, 0, 5) as $r) {
    echo "- {$r['name']} | {$r['spec']} | {$r['source_url']}\n";
}
if ($dry) { echo "(dry-run: 저장하지 않음)\n"; exit(0); }
if (!$records) {   // 빈 JSON 을 저장하면 import-crawled.php 가 그 출처의 최신본으로 읽어 이전 자료가 빠진다
    $die('제품 레코드를 하나도 만들지 못해 저장하지 않았습니다 — 제품 제목(자재 종류 이름) 줄이 없는 카탈로그일 수 있습니다. --dry-run 결과와 함께 알려 주세요.');
}
$dir = rtrim((string) ($opt['out'] ?? (getenv('EXT_CRAWL_DIR') ?: "{$root}/data/external/crawl")), '/');
if (!is_dir($dir) && !mkdir($dir, 0777, true)) { $die("저장 폴더를 만들지 못했습니다: {$dir}"); }
$out = "{$dir}/{$opt['source']}-catalog-" . date('Ymd-His') . '.json';
if (file_put_contents($out, json_encode($records, JSON_UNESCAPED_UNICODE)) === false) { $die("저장하지 못했습니다: {$out}"); }
echo "저장: {$out}\n다음: php scripts/external/import-crawled.php --only={$opt['source']}  → review.md 검토 → approve-crawled.php\n";
