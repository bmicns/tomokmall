#!/usr/bin/env node
import { mkdir, writeFile } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';

const BASE = 'https://www.dacity.kr';
const OUT_DIR = new URL('../data/dacity/', import.meta.url);
const MAX_CATEGORIES = Number.parseInt(process.env.MAX_CATEGORIES || '0', 10);
const MAX_PAGES = Number.parseInt(process.env.MAX_PAGES || '30', 10);
const MAX_PRODUCTS = Number.parseInt(process.env.MAX_PRODUCTS || '0', 10);
const REQUEST_DELAY_MS = Number.parseInt(process.env.REQUEST_DELAY_MS || '350', 10);

const seenUrls = new Set();

function absUrl(href, base = BASE) {
  return new URL(href.replaceAll('&amp;', '&'), base).toString();
}

function cleanText(value = '') {
  return decodeEntities(value)
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function decodeEntities(value = '') {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

function sqlString(value) {
  if (value === null || value === undefined || value === '') return 'NULL';
  return `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "''")}'`;
}

function sqlNumber(value) {
  const number = Number.parseInt(String(value || '').replace(/[^\d-]/g, ''), 10);
  return Number.isFinite(number) && number > 0 ? String(number) : '0';
}

async function fetchText(url) {
  const res = await fetch(url, {
    headers: {
      'User-Agent': 'bmicns-materials-mall-importer/1.0 (+http://bmicns.dothome.co.kr)',
      'Accept': 'text/html,application/xhtml+xml',
    },
  });

  if (!res.ok) {
    throw new Error(`${res.status} ${res.statusText} ${url}`);
  }

  return res.text();
}

function extractCategories(html) {
  const categories = new Map();
  const pattern = /<a\b[^>]*href=["']([^"']*\/shop\/list_search\.php\?ca_id=(\d+)[^"']*)["'][^>]*>([\s\S]*?)<\/a>/gi;
  let match;

  while ((match = pattern.exec(html))) {
    const [, href, id, labelHtml] = match;
    const name = cleanText(labelHtml).replace(/^-+\s*/, '');
    if (name) {
      categories.set(id, {
        externalId: id,
        name,
        slug: `dacity-${id}`,
        url: absUrl(href),
      });
    }
  }

  return [...categories.values()];
}

function extractItemUrls(html, categoryId, page) {
  const urls = new Set();
  const pattern = /href=["']([^"']*\/?item\.php\?[^"']*it_id=(\d+)[^"']*)["']/gi;
  let match;

  while ((match = pattern.exec(html))) {
    const url = absUrl(match[1], `${BASE}/shop/list.php?ca_id=${categoryId}&page=${page}`);
    urls.add(url);
  }

  return [...urls];
}

function meta(html, name) {
  const patterns = [
    new RegExp(`<meta\\s+name=["']${name}["']\\s+content=["']([^"']*)["']`, 'i'),
    new RegExp(`<meta\\s+property=["']${name}["']\\s+content=["']([^"']*)["']`, 'i'),
  ];

  for (const pattern of patterns) {
    const match = html.match(pattern);
    if (match) return decodeEntities(match[1]).trim();
  }

  return '';
}

function parseProduct(html, url, category) {
  const itemId = new URL(url).searchParams.get('it_id') || '';
  const h1 = html.match(/<h1[^>]*>\s*([^<]+?)\s*<\/h1>/i)?.[1] || '';
  const title = cleanText(meta(html, 'title') || h1 || itemId);
  const description = cleanText(meta(html, 'description') || meta(html, 'og:description'));
  const imageUrl = meta(html, 'og:image') || '';
  const price = html.match(/id=["']it_price["']\s+value=["']([^"']*)["']/i)?.[1] || '0';
  const weight = cleanText(html.match(/<th[^>]*>\s*중량\/단위\s*<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>/i)?.[1] || '');
  const loadQty = cleanText(html.match(/<th[^>]*>\s*상차수량[\s\S]*?<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>/i)?.[1] || '');
  const freight = cleanText(html.match(/<th[^>]*>\s*운반비예상[\s\S]*?<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>/i)?.[1] || '');
  const specParts = [weight && `중량/단위: ${weight}`, loadQty && `상차수량: ${loadQty}`, freight && `운반비예상: ${freight}`].filter(Boolean);

  return {
    externalId: itemId,
    sourceUrl: url,
    categoryExternalId: category.externalId,
    categoryName: category.name,
    name: title,
    brand: '동아산업',
    spec: specParts.join(' | '),
    description,
    priceB2c: sqlNumber(price),
    priceB2b: sqlNumber(price),
    imageUrl,
  };
}

function buildSql(categories, products) {
  const lines = [
    'START TRANSACTION;',
    'INSERT IGNORE INTO categories (name, slug, sort_order, is_active) VALUES',
    categories.map((category, index) => `(${sqlString(category.name)}, ${sqlString(category.slug)}, ${1000 + index}, 1)`).join(',\n') + ';',
    '',
  ];

  for (const product of products) {
    lines.push(`INSERT INTO products
    (category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active)
SELECT
    c.id, ${sqlString(product.name)}, ${sqlString(product.brand)}, ${sqlString(product.spec)}, ${sqlString(product.description)},
    ${product.priceB2c}, ${product.priceB2b}, 0, 'dacity', ${sqlString(product.externalId)}, ${sqlString(product.sourceUrl)}, ${sqlString(product.imageUrl)}, 1
FROM categories c
WHERE c.slug = ${sqlString(`dacity-${product.categoryExternalId}`)}
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    name = VALUES(name),
    brand = VALUES(brand),
    spec = VALUES(spec),
    description = VALUES(description),
    price_b2c = VALUES(price_b2c),
    price_b2b = VALUES(price_b2b),
    source_url = VALUES(source_url),
    image_url = VALUES(image_url),
    is_active = 1;`);
  }

  lines.push('COMMIT;');
  return lines.join('\n');
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });

  const home = await fetchText(BASE);
  let categories = extractCategories(home);
  if (MAX_CATEGORIES > 0) categories = categories.slice(0, MAX_CATEGORIES);

  const products = [];

  for (const category of categories) {
    for (let page = 1; page <= MAX_PAGES; page += 1) {
      const listUrl = `${BASE}/shop/list.php?ca_id=${category.externalId}&page=${page}`;
      const listHtml = await fetchText(listUrl);
      const itemUrls = extractItemUrls(listHtml, category.externalId, page).filter((url) => !seenUrls.has(url));

      if (itemUrls.length === 0) break;

      for (const itemUrl of itemUrls) {
        seenUrls.add(itemUrl);
        const detailHtml = await fetchText(itemUrl);
        products.push(parseProduct(detailHtml, itemUrl, category));
        if (MAX_PRODUCTS > 0 && products.length >= MAX_PRODUCTS) break;
        await delay(REQUEST_DELAY_MS);
      }

      if (MAX_PRODUCTS > 0 && products.length >= MAX_PRODUCTS) break;
      await delay(REQUEST_DELAY_MS);
    }

    if (MAX_PRODUCTS > 0 && products.length >= MAX_PRODUCTS) break;
  }

  await writeFile(new URL('categories.json', OUT_DIR), JSON.stringify(categories, null, 2));
  await writeFile(new URL('products.json', OUT_DIR), JSON.stringify(products, null, 2));
  await writeFile(new URL('import.sql', OUT_DIR), buildSql(categories, products));

  console.log(`categories=${categories.length}`);
  console.log(`products=${products.length}`);
  console.log(`output=${OUT_DIR.pathname}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});

