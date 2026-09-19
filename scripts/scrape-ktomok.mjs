#!/usr/bin/env node
/**
 * ktomok.com 전체 상품 스크래퍼
 * 실행: node scripts/scrape-ktomok.mjs
 * 출력: data/ktomok/categories.json, products.json, import.sql
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';

const BASE = 'https://ktomok.com';
const OUT_DIR = new URL('../data/ktomok/', import.meta.url);
const REQUEST_DELAY_MS = Number.parseInt(process.env.REQUEST_DELAY_MS || '500', 10);
const MAX_CATEGORIES = Number.parseInt(process.env.MAX_CATEGORIES || '0', 10);

// 대분류 → { ca_id 접두사, DB slug } 매핑
const MAIN_CATEGORIES = {
  '철근콘크리트제품': { prefix: '1', slug: 'civil-rc'       },
  '콘크리트제품':     { prefix: '2', slug: 'civil-concrete'  },
  '스틸제품':         { prefix: '3', slug: 'civil-steel'     },
  '석재제품':         { prefix: '4', slug: 'civil-stone'     },
  'PE/PVC제품':       { prefix: '5', slug: 'civil-pepvc'     },
};

function cleanText(html = '') {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

function sqlStr(v) {
  if (v === null || v === undefined || v === '') return 'NULL';
  return `'${String(v).replaceAll('\\', '\\\\').replaceAll("'", "''")}'`;
}

function sqlNum(v) {
  const n = Number.parseInt(String(v ?? '').replace(/[^\d]/g, ''), 10);
  return Number.isFinite(n) ? n : 0;
}

async function fetchText(url) {
  const res = await fetch(url, {
    headers: {
      'User-Agent': 'bmicns-materials-importer/1.0 (+http://bmicns.dothome.co.kr)',
      'Accept': 'text/html,application/xhtml+xml',
      'Accept-Language': 'ko-KR,ko;q=0.9',
    },
  });
  if (!res.ok) throw new Error(`${res.status} ${url}`);
  const buf = await res.arrayBuffer();

  // Content-Type 헤더로 인코딩 확인
  const ct = res.headers.get('content-type') || '';
  if (/euc-kr|euc_kr/i.test(ct)) {
    return new TextDecoder('euc-kr').decode(buf);
  }

  // UTF-8로 디코딩 후 meta charset 확인
  const utf8 = new TextDecoder('utf-8').decode(buf);
  const metaCharset = utf8.match(/charset=["']?([^"'\s;>]+)/i)?.[1] ?? '';
  if (/euc-kr|euc_kr/i.test(metaCharset)) {
    try { return new TextDecoder('euc-kr').decode(buf); } catch { /* fall through */ }
  }
  return utf8;
}

/** 홈페이지 네비에서 전체 카테고리 추출 */
function extractCategories(html) {
  const categories = new Map();
  const pattern = /href=["']([^"']*\/shop\/list_search\.php\?ca_id=(\d+)[^"']*)["'][^>]*>([\s\S]*?)<\/a>/gi;
  let match;

  while ((match = pattern.exec(html))) {
    const [, href, id, labelHtml] = match;
    const name = cleanText(labelHtml);
    if (!name || categories.has(id)) continue;

    // 대분류 판단
    const mainEntry = Object.entries(MAIN_CATEGORIES)
      .find(([, v]) => id.startsWith(v.prefix));
    const mainCat  = mainEntry?.[0]         ?? '기타';
    const mainSlug = mainEntry?.[1].slug    ?? 'civil-etc';

    categories.set(id, {
      externalId: id,
      name,
      mainCategory: mainCat,
      mainSlug,
      slug: `ktomok-${id}`,
      url: `${BASE}/shop/list_search.php?ca_id=${id}`,
    });
  }

  return [...categories.values()];
}

/** 상품 목록 페이지 테이블 파싱
 *
 * ktomok 실제 컬럼 순서 (thead th 기준):
 * [0]번호 | [1]품명 | [2]규격 | [3]단위 | [4]중량(kg) | [5]판매가(VAT별도) |
 * [6]상차수량(본/25톤) | [7]운반비예상 | [8]설치비예상(원/M) | [9]사진 | [10]도면보기
 */
function parseProductTable(html, category) {
  const products = [];

  // list-tbl 클래스 테이블 우선, 없으면 품명/규격 포함 테이블 탐색
  // 비재귀 방식: 태그 스택으로 올바른 table 범위 추출
  const tableRanges = [];
  const tagRe = /<\/?table[^>]*>/gi;
  let depth = 0, start = -1;
  let m;
  while ((m = tagRe.exec(html)) !== null) {
    if (m[0][1] !== '/') { // opening tag
      if (depth === 0) start = m.index;
      depth++;
    } else { // closing tag
      depth--;
      if (depth === 0 && start >= 0) {
        tableRanges.push(html.slice(start, m.index + m[0].length));
        start = -1;
      }
    }
  }

  for (const tableHtml of tableRanges) {
    if (!tableHtml.includes('품명') || !tableHtml.includes('규격')) continue;

    // tbody 영역만 추출 (없으면 전체 사용)
    const bodyMatch = tableHtml.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/i);
    const bodyHtml = bodyMatch ? bodyMatch[1] : tableHtml;

    const rows = [...bodyHtml.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gi)].map(m => m[0]);

    for (const row of rows) {
      const cells = [...row.matchAll(/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/gi)]
        .map(m => cleanText(m[1]));

      if (cells.length < 6) continue;

      // 헤더 행 스킵: cells[0]='번호' 또는 cells[1]='품명'
      if (cells[0] === '번호' || cells[1] === '품명') continue;
      // 빈 행 스킵
      if (!cells[1]) continue;

      const name      = cells[1].replace(/\s+/g, ' ');
      const spec      = cells[2] ?? '';
      const unit      = cells[3] ?? '본';
      const weight    = cells[4] ?? '';
      const priceText = cells[5] ?? '';
      const loadQty   = cells[6] ?? '';
      const transport = cells[7] ?? '';
      const install   = cells[8] ?? '';

      const price = sqlNum(priceText);
      if (price === 0 && !priceText.includes('문의')) continue;

      const imgMatch = row.match(/<img[^>]+src=["']([^"']+)["']/i);
      const imageUrl = imgMatch ? (new URL(imgMatch[1], BASE).toString()) : '';

      products.push({
        externalId:         `${category.externalId}-${products.length + 1}`,
        categoryExternalId: category.externalId,
        categoryName:       category.name,
        mainCategory:       category.mainCategory,
        name,
        spec:               spec || '',
        unit:               unit || '본',
        weight:             weight || '',
        loadQty:            loadQty || '',
        transport:          transport || '',
        install:            install || '',
        price,
        imageUrl,
        sourceUrl:          category.url,
      });
    }
  }

  return products;
}

function buildSql(categories, products) {
  const lines = ['START TRANSACTION;', ''];

  // 소분류 카테고리 INSERT — parent_id는 대분류 slug로 연결
  lines.push('-- Sub-categories (parent_id = 대분류 id)');
  for (const [i, cat] of categories.entries()) {
    lines.push(
      `INSERT INTO categories (name, slug, parent_id, sort_order, is_active) ` +
      `SELECT ${sqlStr(cat.name)}, ${sqlStr(cat.slug)}, id, ${2000 + i * 10}, 1 ` +
      `FROM categories WHERE slug = ${sqlStr(cat.mainSlug)} LIMIT 1 ` +
      `ON DUPLICATE KEY UPDATE name=VALUES(name), parent_id=VALUES(parent_id), sort_order=VALUES(sort_order);`
    );
  }
  lines.push('');

  // 상품 INSERT
  lines.push('-- Products');
  for (const p of products) {
    // spec: 규격 + 중량 + 단위 + 물류 정보 합산
    const specParts = [
      p.spec && `규격: ${p.spec}`,
      p.weight && `중량: ${p.weight}kg`,
      p.unit && `단위: ${p.unit}`,
      p.loadQty && `상차수량: ${p.loadQty}본/25톤`,
      p.transport && `운반비예상: ${p.transport}원`,
      p.install && `설치비예상: ${p.install}원/M`,
    ].filter(Boolean).join(' | ');

    lines.push(
      `INSERT INTO products ` +
      `(category_id, name, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active) ` +
      `SELECT c.id, ${sqlStr(p.name)}, ${sqlStr(specParts)}, NULL, ` +
      `${p.price}, ${p.price}, 0, 'ktomok', ${sqlStr(p.externalId)}, ${sqlStr(p.sourceUrl)}, ${sqlStr(p.imageUrl)}, 1 `+
      `FROM categories c WHERE c.slug = ${sqlStr(`ktomok-${p.categoryExternalId}`)} ` +
      `ON DUPLICATE KEY UPDATE name=VALUES(name), spec=VALUES(spec), description=VALUES(description), ` +
      `price_b2c=VALUES(price_b2c), price_b2b=VALUES(price_b2b), source_url=VALUES(source_url), image_url=VALUES(image_url), is_active=1;`
    );
  }

  lines.push('', 'COMMIT;');
  return lines.join('\n');
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });

  console.log('홈페이지에서 카테고리 목록 수집 중...');
  const homeHtml = await fetchText(BASE);
  let categories = extractCategories(homeHtml);
  console.log(`  카테고리 ${categories.length}개 발견`);

  if (MAX_CATEGORIES > 0) {
    categories = categories.slice(0, MAX_CATEGORIES);
    console.log(`  (제한: ${MAX_CATEGORIES}개만 처리)`);
  }

  const products = [];
  let catIndex = 0;

  for (const cat of categories) {
    catIndex++;
    process.stdout.write(`[${catIndex}/${categories.length}] ${cat.name} ... `);

    try {
      const html = await fetchText(cat.url);
      const catProducts = parseProductTable(html, cat);
      products.push(...catProducts);
      console.log(`${catProducts.length}개 상품`);
    } catch (err) {
      console.log(`오류: ${err.message}`);
    }

    await delay(REQUEST_DELAY_MS);
  }

  console.log(`\n총 카테고리 ${categories.length}개, 상품 ${products.length}개`);

  await writeFile(new URL('categories.json', OUT_DIR), JSON.stringify(categories, null, 2));
  await writeFile(new URL('products.json', OUT_DIR), JSON.stringify(products, null, 2));
  await writeFile(new URL('import.sql', OUT_DIR), buildSql(categories, products));

  console.log(`저장 완료: ${OUT_DIR.pathname}`);
  console.log('다음 단계: phpMyAdmin에서 data/ktomok/import.sql 실행');
}

main().catch(err => { console.error(err); process.exit(1); });
