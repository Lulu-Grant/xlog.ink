const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const data = JSON.parse(fs.readFileSync(path.join(root, 'data.json'), 'utf8'));
const pagesDir = path.join(root, 'pages');
fs.mkdirSync(pagesDir, { recursive: true });

function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function atmosphere(page, prefix) {
  if (page.atmosphere) {
    return `${prefix}promo-materials/ai-images/${page.atmosphere}`;
  }
  return `${prefix}promo-materials/renders/${page.mockup}`;
}

function mockup(page, prefix) {
  return `${prefix}promo-materials/renders/${page.mockup}`;
}

function pageHtml(page, index) {
  const prev = data.pages[(index - 1 + data.pages.length) % data.pages.length];
  const next = data.pages[(index + 1) % data.pages.length];
  const prefix = '../../';
  const featureHtml = page.features.map(([title, body]) => (
    `<article class="feature-card"><strong>${esc(title)}</strong><span>${esc(body)}</span></article>`
  )).join('');
  const keywordHtml = page.keywords.map((keyword) => `<span>${esc(keyword)}</span>`).join('');

  return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${esc(page.title)} - xlog.ink</title>
  <meta name="description" content="${esc(page.subtitle)}">
  <link rel="stylesheet" href="../assets/promo-pages.css">
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="../index.html"><span class="mark"></span>${esc(data.brand)}</a>
      <nav class="navlinks">
        <span>${esc(page.nav)}</span>
        <span>案例 ${String(index + 1).padStart(2, '0')}</span>
      </nav>
    </header>

    <section class="hero">
      <div>
        <div class="kicker">${esc(page.kicker)}</div>
        <h1>${esc(page.title)}</h1>
        <p class="lead">${esc(page.subtitle)}</p>
        <div class="cta-row">
          <a class="btn" href="https://xlog.ink/">${esc(page.primaryCta)}</a>
          <a class="btn secondary" href="#example">${esc(page.secondaryCta)}</a>
        </div>
      </div>
      <div class="media-stack">
        <figure class="photo-frame"><img src="${atmosphere(page, prefix)}" alt="${esc(page.nav)}氛围图"></figure>
      </div>
    </section>

    <section class="section">
      <div class="story-grid">
        <div>
          <div class="section-title">为什么需要这个页面</div>
          <h2 class="story-title">${esc(page.storyTitle)}</h2>
        </div>
        <div>
          <p class="lead">${esc(page.story)}</p>
        </div>
      </div>
    </section>

    <section class="section" id="example">
      <div class="story-grid">
        <article class="prompt-card">
          <div class="section-title">用户输入示例</div>
          <p><strong>“${esc(page.prompt)}”</strong></p>
        </article>
        <article class="mock-frame"><img src="${mockup(page, prefix)}" alt="${esc(page.title)} mockup"></article>
      </div>
    </section>

    <section class="section">
      <div class="features">${featureHtml}</div>
    </section>

    <section class="section">
      <div class="delivery-card delivery">
        <div class="qr" aria-hidden="true"></div>
        <div>
          <div class="section-title">交付结果</div>
          <div class="url">${esc(page.url)}</div>
          <p class="lead">链接、二维码、页面截图和后续编辑入口都在对话里完成。</p>
        </div>
      </div>
    </section>

    <section class="section">
      <article class="seo-card">
        <div class="section-title">SEO 关键词</div>
        <div class="keywords">${keywordHtml}</div>
      </article>
    </section>

    <footer class="pager">
      <a class="btn secondary" href="${prev.id}.html">上一个：${esc(prev.nav)}</a>
      <a class="btn secondary" href="../index.html">返回总览</a>
      <a class="btn secondary" href="${next.id}.html">下一个：${esc(next.nav)}</a>
    </footer>
  </main>
</body>
</html>
`;
}

function indexHtml() {
  const cards = data.pages.map((page, index) => `
    <a class="index-card" href="pages/${page.id}.html">
      <img src="${page.atmosphere ? `../promo-materials/ai-images/${page.atmosphere}` : `../promo-materials/renders/${page.mockup}`}" alt="">
      <div>
        <b>案例 ${String(index + 1).padStart(2, '0')} / ${esc(page.nav)}</b>
        <h2>${esc(page.title)}</h2>
        <p>${esc(page.subtitle)}</p>
      </div>
    </a>
  `).join('');

  return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>xlog.ink 十个宣传页面</title>
  <meta name="description" content="xlog.ink 十个宣传页面示例：小店活动、二维码服务页、活动通知、个人介绍、小店宣传、AI 发布、活动链接、Logo 品牌页、朋友圈宣传、场景总览。">
  <link rel="stylesheet" href="assets/promo-pages.css">
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="https://xlog.ink/"><span class="mark"></span>${esc(data.brand)}</a>
      <nav class="navlinks"><span>10 个宣传页</span><span>本地 HTML mock</span></nav>
    </header>
    <section class="hero">
      <div>
        <div class="kicker">PROMO LANDING SET</div>
        <h1>十个能直接用于传播的 xlog.ink 宣传页面</h1>
        <p class="lead">每页都对应一个真实使用场景，配套 HTML mockup、AI 氛围图、二维码交付示意和 SEO 关键词。</p>
      </div>
      <figure class="photo-frame"><img src="../promo-materials/ai-images/use-case-grid-background.webp" alt="xlog.ink 场景总览"></figure>
    </section>
    <section class="index-grid">${cards}</section>
  </main>
</body>
</html>
`;
}

for (let i = 0; i < data.pages.length; i += 1) {
  const page = data.pages[i];
  fs.writeFileSync(path.join(pagesDir, `${page.id}.html`), pageHtml(page, i));
}
fs.writeFileSync(path.join(root, 'index.html'), indexHtml());
console.log(`Generated ${data.pages.length} promo pages.`);
