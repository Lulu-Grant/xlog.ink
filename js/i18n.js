// i18n.js — 前端三语文案统一管理（单一来源）
// 所有页面共用此文件，不再各自内嵌 I18N 对象

window.XLOG_I18N = {
  'zh-CN': {
    // 通用 UI
    appTitle: 'XLOG', themeLabel: '主题', light: '明亮', dark: '暗色', langLabel: '语言',

    // 链接模式表单
    title: '标题（必填，≤30字）', body: '文字模块（≤500字，可留空）',
    links: '链接模块（1–20 条）', name: '链接名称', url: '链接地址（含 http/https）',
    addLink: '新增链接', del: '删除', up: '上移', down: '下移',
    generate: '确认创建',
    tipLimits: '标题≤30字；正文≤500字；最多 20 条链接；URL 需以 http/https 开头。',
    titleHelp: '请输入页面标题，不超过 30 个字符。',
    bodyHelp: '可补充个人介绍、活动说明或页面摘要。',
    adultContentLabel: '此页面包含 18+ 成人内容',
    adultContentHelp: '勾选后，访问者首次打开该页面时会先看到 18+ 确认提示。',
    verificationTitle: '安全验证',
    verificationHelp: '提交前请完成人机验证，避免滥用与批量生成。',
    linkIntroKicker: 'Link Page · Fast Publish',
    linkIntroTitle: '创建一个干净、适合分享的链接页',
    linkIntroDesc: '填好标题、简介和链接，立即生成稳定可访问的个人页面，适合社交入口、简历和活动页。',
    linkMeta1: '免费二级域名',
    linkMeta2: '提交后长期保留',
    linkMeta3: '支持三语界面',
    articleIntroKicker: 'Markdown Article · Fast Publish',
    articleIntroTitle: '把文章整理成一个干净的静态页',
    articleIntroDesc: '使用 Markdown 编写内容、预览排版，再一键生成稳定可分享的文章页，适合长文和公告。',
    articleMeta1: 'Markdown 编辑',
    articleMeta2: '16 色文字工具',
    articleMeta3: '生成后长期保留',

    // 文章模式
    tabLinks: '链接模式', tabArticle: '文章模式（Markdown）',
    seoTitle_links: '页面生成 - XLOG｜免费二级域名・移动优先・永久不删除',
    seoTitle_article: '文章模式 - XLOG',
    seoDesc_links: '在 XLOG 一键生成你的个人主页：输入标题、简介与链接，选择主题与语言，即可获得免费二级域名的个人页面。页面生成后永久不删除。',
    seoDesc_article: '使用 Markdown 撰写文章并生成你的个人页面，支持多语言与主题。',
    titleLabel: '标题（必填，≤30字）',
    mdLabel: '正文（支持 Markdown）',
    mdHelp: '支持标题/粗斜体/链接/列表/代码/引用/分隔线等格式，可切换预览。',
    tipLimits_article: '标题≤30字；正文建议≤5000字；提交后不可修改或删除。',
    generateBtn: '生成文章页面',
    manual: '用户手册',

    // 色彩面板
    colorTitle: '文字颜色', colorSample: '彩色文字',

    // 首页
    heroTitle: 'XLOG — 免费个人主页生成器',
    heroDesc: '输入标题和链接，一键生成专属个人页面。免费二级域名 · 移动优先 · 页面永久不删除。',
  },

  'zh-TW': {
    appTitle: 'XLOG', themeLabel: '主題', light: '明亮', dark: '暗色', langLabel: '語言',

    title: '標題（必填，≤30字）', body: '文字模塊（≤500字，可留空）',
    links: '連結模塊（1–20 條）', name: '連結名稱', url: '連結位址（含 http/https）',
    addLink: '新增連結', del: '刪除', up: '上移', down: '下移',
    generate: '立即創建',
    tipLimits: '標題≤30字；正文≤500字；最多 20 條連結；URL 需以 http/https 開頭。',
    titleHelp: '請輸入頁面標題，不超過 30 個字元。',
    bodyHelp: '可補充個人介紹、活動說明或頁面摘要。',
    adultContentLabel: '此頁面包含 18+ 成人內容',
    adultContentHelp: '勾選後，訪客首次打開該頁面時會先看到 18+ 確認提示。',
    verificationTitle: '安全驗證',
    verificationHelp: '提交前請完成人機驗證，避免濫用與批量生成。',
    linkIntroKicker: 'Link Page · Fast Publish',
    linkIntroTitle: '建立一個乾淨、可分享的連結頁',
    linkIntroDesc: '填好標題、簡介與連結，立即生成一個穩定的個人頁面，適合社交入口、履歷和活動頁。',
    linkMeta1: '免費二級網域',
    linkMeta2: '提交後永久保留',
    linkMeta3: '支援三語界面',
    articleIntroKicker: 'Markdown Article · Fast Publish',
    articleIntroTitle: '把文章整理成一個乾淨的靜態頁',
    articleIntroDesc: '使用 Markdown 編寫內容、預覽排版，再一鍵生成穩定可分享的文章頁，適合長文與公告。',
    articleMeta1: 'Markdown 編輯',
    articleMeta2: '16 色文字工具',
    articleMeta3: '生成後永久保留',

    tabLinks: '連結模式', tabArticle: '文章模式（Markdown）',
    seoTitle_links: '頁面生成 - XLOG｜免費二級網域・行動優先・永久不刪除',
    seoTitle_article: '文章模式 - XLOG',
    seoDesc_links: '在 XLOG 一鍵生成你的個人主頁：輸入標題、簡介與連結，選擇主題與語言，即可獲得免費二級網域的個人頁面。頁面生成後永久不刪除。',
    seoDesc_article: '使用 Markdown 撰寫文章並生成你的個人頁面，支援多語與主題。',
    titleLabel: '標題（必填，≤30字）',
    mdLabel: '正文（支援 Markdown）',
    mdHelp: '支援標題/粗斜體/連結/列表/程式碼/引用/分隔線等格式，右上角可切換預覽。',
    tipLimits_article: '標題≤30字；正文建議≤5000字；提交後不可修改或刪除。',
    generateBtn: '生成文章頁面',
    manual: '使用手冊',

    colorTitle: '文字顏色', colorSample: '彩色文字',

    heroTitle: 'XLOG — 免費個人主頁產生器',
    heroDesc: '輸入標題和連結，一鍵生成專屬個人頁面。免費二級網域 · 行動優先 · 頁面永久不刪除。',
  },

  'en': {
    appTitle: 'XLOG', themeLabel: 'Theme', light: 'Light', dark: 'Dark', langLabel: 'Language',

    title: 'Title (required, ≤30 chars)', body: 'Text module (≤500 chars, optional)',
    links: 'Links (1–20 items)', name: 'Link name', url: 'Link URL (http/https)',
    addLink: 'Add link', del: 'Delete', up: 'Up', down: 'Down',
    generate: 'Create Now',
    tipLimits: 'Title ≤30; text ≤500; up to 20 links; URL must start with http/https.',
    titleHelp: 'Enter a page title, up to 30 characters.',
    bodyHelp: 'Add a short intro, event details, or a brief summary.',
    adultContentLabel: 'This page contains 18+ adult content',
    adultContentHelp: 'If checked, first-time visitors to this page will see an 18+ confirmation gate before viewing it.',
    verificationTitle: 'Security Check',
    verificationHelp: 'Please complete the human verification before submitting to prevent abuse and bulk generation.',
    linkIntroKicker: 'Link Page · Fast Publish',
    linkIntroTitle: 'Create a clean link page you can share anywhere',
    linkIntroDesc: 'Add a title, short intro, and your links to publish a stable page for bios, resumes, and campaigns.',
    linkMeta1: 'Free subdomain',
    linkMeta2: 'Kept online after publish',
    linkMeta3: 'Three UI languages',
    articleIntroKicker: 'Markdown Article · Fast Publish',
    articleIntroTitle: 'Turn your writing into a clean static article page',
    articleIntroDesc: 'Write in Markdown, preview the layout, and publish a stable article page for essays, updates, and announcements.',
    articleMeta1: 'Markdown editor',
    articleMeta2: '16 text colors',
    articleMeta3: 'Kept online after publish',

    tabLinks: 'Links Mode', tabArticle: 'Article Mode (Markdown)',
    seoTitle_links: 'Create Page - XLOG | Free Subdomain · Mobile-first · Never Deleted',
    seoTitle_article: 'Article Mode - XLOG',
    seoDesc_links: 'Generate your personal page on XLOG: add a title, text and links, choose theme and language, and get a free subdomain. Pages are never deleted.',
    seoDesc_article: 'Write in Markdown and generate a personal page. Multilingual & theme-ready.',
    titleLabel: 'Title (required, ≤30 chars)',
    mdLabel: 'Body (Markdown supported)',
    mdHelp: 'Headings/bold/italic/links/lists/code/blockquote/hr. Use preview in the toolbar.',
    tipLimits_article: 'Title ≤ 30 chars; body ≤ ~5000 recommended; immutable after submission.',
    generateBtn: 'Generate Article Page',
    manual: 'Manual',

    colorTitle: 'Text Color', colorSample: 'colored text',

    heroTitle: 'XLOG — Free Personal Page Generator',
    heroDesc: 'Enter a title and links to generate your personal page. Free subdomain · Mobile-first · Pages are never deleted.',
  }
};
