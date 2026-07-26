#!/usr/bin/env node
const fs = require('fs');

if (!process.env.PLAYWRIGHT_BROWSERS_PATH) {
  const candidates = [
    '/opt/xlog-playwright-browsers',
    `${process.env.HOME || ''}/Library/Caches/ms-playwright`,
    `${process.env.HOME || ''}/.cache/ms-playwright`
  ];
  const existing = candidates.find((path) => path && fs.existsSync(path));
  if (existing) process.env.PLAYWRIGHT_BROWSERS_PATH = existing;
}

function loadPlaywright() {
  // Prefer env override, then local node_modules, then server install path.
  // Do not hardcode developer machine absolute paths (AUDIT-7 P2-8).
  const candidates = [
    process.env.PLAYWRIGHT_MODULE,
    'playwright',
    '/opt/xlog-playwright/node_modules/playwright',
  ].filter(Boolean);
  for (const name of candidates) {
    try { return require(name); } catch (e) {}
  }
  throw new Error('playwright module not found; set PLAYWRIGHT_MODULE or install playwright');
}

(async () => {
  const target = process.argv[2];
  const out = process.argv[3];
  if (!target || !out) {
    console.error('Usage: node scripts/capture-page.js <url-or-file> <out.webp>');
    process.exit(2);
  }
  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({
      viewport: { width: 1200, height: 1600 },
      deviceScaleFactor: 1,
      userAgent: 'xlog-shot/1.0 HeadlessChrome',
    });
    await page.goto(target, { waitUntil: 'networkidle', timeout: 20000 });
    const lower = out.toLowerCase();
    const type = lower.endsWith('.jpg') || lower.endsWith('.jpeg') ? 'jpeg' : 'png';
    const options = { path: out, type, fullPage: true };
    if (type === 'jpeg') options.quality = 82;
    await page.screenshot(options);
    if (!fs.existsSync(out)) throw new Error('screenshot not written');
  } finally {
    await browser.close();
  }
})().catch((err) => {
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(1);
});
