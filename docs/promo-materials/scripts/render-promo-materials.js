const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const htmlPath = path.join(root, 'mockups', 'index.html');
const outDir = path.join(root, 'renders');

async function main() {
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  await page.goto(`file://${htmlPath}`, { waitUntil: 'networkidle' });
  const frames = await page.locator('.capture').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-file')));
  for (let i = 0; i < frames.length; i += 1) {
    const name = frames[i] || `frame-${String(i + 1).padStart(2, '0')}.png`;
    const locator = page.locator('.capture').nth(i);
    await locator.screenshot({ path: path.join(outDir, name) });
    console.log(`rendered ${name}`);
  }
  await page.screenshot({ path: path.join(outDir, 'contact-sheet.png'), fullPage: true });
  await browser.close();
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
