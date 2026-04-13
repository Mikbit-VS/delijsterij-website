import { mkdir, writeFile, stat } from 'node:fs/promises';
import path from 'node:path';

const ORIGIN = 'https://delijsterij.nl';
const ALLOWED_ORIGINS = new Set(['https://delijsterij.nl', 'http://delijsterij.nl']);
const OUT_DIR = path.resolve('current-site', 'snapshot');

const MAX_PAGES = 5000;
const CONCURRENCY = 8;

function normalizeUrl(input) {
  const u = new URL(input, ORIGIN);
  u.hash = '';
  // strip common tracking params
  for (const k of [...u.searchParams.keys()]) {
    if (/^(utm_|fbclid|gclid)/i.test(k)) u.searchParams.delete(k);
  }
  // keep search only if non-empty after stripping
  if ([...u.searchParams.keys()].length === 0) u.search = '';
  return u;
}

function isInternal(u) {
  return ALLOWED_ORIGINS.has(u.origin);
}

function isProbablyHtml(u) {
  return !path.extname(u.pathname) || u.pathname.endsWith('/') || u.pathname.endsWith('.html');
}

function urlToLocalPath(u, { html = false } = {}) {
  let p = decodeURIComponent(u.pathname);
  if (p.startsWith('/')) p = p.slice(1);
  if (p === '') p = 'index.html';

  if (html) {
    if (p.endsWith('/')) p = path.posix.join(p, 'index.html');
    else if (!path.posix.extname(p)) p = `${p}.html`;
  }

  // Windows-safe join
  return path.join(OUT_DIR, ...p.split('/'));
}

async function ensureDirForFile(filePath) {
  await mkdir(path.dirname(filePath), { recursive: true });
}

async function fetchBuffer(url) {
  const res = await fetch(url, {
    redirect: 'follow',
    headers: {
      'user-agent':
        'DeLijsterijSnapshotBot/1.0 (local migration reference; contact: info@delijsterij.nl)',
      accept: '*/*',
    },
  });
  if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
  const ab = await res.arrayBuffer();
  return { buffer: Buffer.from(ab), contentType: res.headers.get('content-type') || '' };
}

function extractUrlsFromHtml(html, baseUrl) {
  const out = new Set();

  // href/src attributes
  const attrRe = /\s(?:href|src)\s*=\s*(?:"([^"]+)"|'([^']+)'|([^\s>]+))/gi;
  let m;
  while ((m = attrRe.exec(html))) {
    const raw = (m[1] ?? m[2] ?? m[3] ?? '').trim();
    if (!raw || raw.startsWith('mailto:') || raw.startsWith('tel:') || raw.startsWith('javascript:'))
      continue;
    try {
      const u = normalizeUrl(new URL(raw, baseUrl).toString());
      if (isInternal(u)) out.add(u.toString());
    } catch {
      // ignore
    }
  }

  // srcset="url 1x, url 2x"
  const srcsetRe = /\ssrcset\s*=\s*(?:"([^"]+)"|'([^']+)')/gi;
  while ((m = srcsetRe.exec(html))) {
    const raw = (m[1] ?? m[2] ?? '').trim();
    for (const part of raw.split(',')) {
      const candidate = part.trim().split(/\s+/)[0];
      if (!candidate) continue;
      try {
        const u = normalizeUrl(new URL(candidate, baseUrl).toString());
        if (isInternal(u)) out.add(u.toString());
      } catch {
        // ignore
      }
    }
  }

  return [...out];
}

function rewriteInternalLinks(html, pageUrl) {
  const base = new URL(pageUrl);

  const replaceAttr = (match, q1, q2, q3) => {
    const raw = (q1 ?? q2 ?? q3 ?? '').trim();
    if (!raw) return match;
    if (raw.startsWith('mailto:') || raw.startsWith('tel:') || raw.startsWith('javascript:')) return match;

    try {
      const u = normalizeUrl(new URL(raw, base).toString());
      if (!isInternal(u)) return match;

      // Keep anchor-only links intact
      if (raw.startsWith('#')) return match;

      // Convert to relative local file path
      const localTarget = isProbablyHtml(u)
        ? urlToLocalPath(u, { html: true })
        : urlToLocalPath(u, { html: false });

      const fromDir = path.dirname(urlToLocalPath(base, { html: true }));
      let rel = path.relative(fromDir, localTarget).split(path.sep).join('/');
      if (!rel || rel === '') rel = './';
      return match.replace(raw, rel);
    } catch {
      return match;
    }
  };

  return html
    .replace(/\s(href)\s*=\s*(?:"([^"]+)"|'([^']+)'|([^\s>]+))/gi, replaceAttr)
    .replace(/\s(src)\s*=\s*(?:"([^"]+)"|'([^']+)'|([^\s>]+))/gi, replaceAttr);
}

async function getSitemapUrls() {
  const candidates = [
    'https://delijsterij.nl/sitemap_index.xml',
    'https://delijsterij.nl/sitemap.xml',
    'https://delijsterij.nl/sitemap.xml.gz',
  ];

  for (const url of candidates) {
    try {
      const { buffer, contentType } = await fetchBuffer(url);
      if (url.endsWith('.gz') || /gzip/i.test(contentType)) {
        // If gzipped, skip for now (keep it simple)
        continue;
      }
      const xml = buffer.toString('utf8');
      const locs = [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)].map((m) => m[1]);
      const urls = locs
        .map((l) => {
          try {
            const u = normalizeUrl(l);
            return isInternal(u) ? u.toString() : null;
          } catch {
            return null;
          }
        })
        .filter(Boolean);

      // If index sitemap, include child sitemaps too (best effort)
      const looksLikeIndex = /sitemapindex/i.test(xml);
      if (looksLikeIndex) {
        const pageUrls = [];
        for (const sm of urls.slice(0, 50)) {
          try {
            const { buffer: b } = await fetchBuffer(sm);
            const child = b.toString('utf8');
            const childLocs = [...child.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/gi)].map((m) => m[1]);
            for (const l of childLocs) {
              try {
                const u = normalizeUrl(l);
                if (isInternal(u)) pageUrls.push(u.toString());
              } catch {
                // ignore
              }
            }
          } catch {
            // ignore
          }
        }
        return [...new Set(pageUrls)];
      }

      return [...new Set(urls)];
    } catch {
      // try next
    }
  }

  return [];
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });

  const seed = new Set([normalizeUrl(ORIGIN).toString()]);
  const sitemapUrls = await getSitemapUrls();
  for (const u of sitemapUrls) seed.add(u);

  const queue = [...seed];
  const seen = new Set();
  const assetsToFetch = new Set();

  async function processUrl(url) {
    const u = normalizeUrl(url);
    if (!isInternal(u)) return;
    const key = u.toString();
    if (seen.has(key)) return;
    seen.add(key);

    if (isProbablyHtml(u)) {
      const { buffer } = await fetchBuffer(key);
      const html = buffer.toString('utf8');

      // discover links/assets from this page
      for (const discovered of extractUrlsFromHtml(html, key)) {
        const du = normalizeUrl(discovered);
        if (!isInternal(du)) continue;
        if (isProbablyHtml(du)) {
          if (seen.size < MAX_PAGES) queue.push(du.toString());
        } else {
          assetsToFetch.add(du.toString());
        }
      }

      const rewritten = rewriteInternalLinks(html, key);
      const outPath = urlToLocalPath(u, { html: true });
      await ensureDirForFile(outPath);
      await writeFile(outPath, rewritten);
      return;
    }

    // non-html: treat as asset
    assetsToFetch.add(key);
  }

  // simple worker pool
  const workers = Array.from({ length: CONCURRENCY }, async () => {
    while (queue.length && seen.size < MAX_PAGES) {
      const next = queue.shift();
      if (!next) break;
      try {
        await processUrl(next);
      } catch {
        // ignore failures (snapshot is best-effort)
      }
    }
  });

  await Promise.all(workers);

  // fetch assets (best-effort, dedupe)
  const assetList = [...assetsToFetch];
  let i = 0;
  const assetWorkers = Array.from({ length: CONCURRENCY }, async () => {
    while (i < assetList.length) {
      const idx = i++;
      const url = assetList[idx];
      try {
        const u = normalizeUrl(url);
        const outPath = urlToLocalPath(u, { html: false });
        try {
          await stat(outPath);
          continue;
        } catch {
          // not exists
        }
        const { buffer } = await fetchBuffer(url);
        await ensureDirForFile(outPath);
        await writeFile(outPath, buffer);
      } catch {
        // ignore
      }
    }
  });

  await Promise.all(assetWorkers);

  const summary = {
    origin: ORIGIN,
    outDir: OUT_DIR,
    pagesDownloaded: seen.size,
    assetsQueued: assetsToFetch.size,
    sitemapSeedCount: sitemapUrls.length,
  };

  await writeFile(path.join(OUT_DIR, '_snapshot-summary.json'), JSON.stringify(summary, null, 2));
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
}

await main();

