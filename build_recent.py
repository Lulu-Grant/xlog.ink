#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build_recent.py — 生成 /recent.html
优先读取 data/pages.jsonl 索引（O(1)），回退到扫描 /site/ 目录
"""

import json
import os
import re
import datetime
from html import escape
from pathlib import Path

SITE_DIR = Path("site")
INDEX_FILE = Path("data/pages.jsonl")
OUTPUT_FILE = Path("recent.html")
MAX_ITEMS = 100

# ── 数据加载 ──────────────────────────────────────────────

def load_from_index(index_file=INDEX_FILE):
    """从 pages.jsonl 索引加载（追加写入，需去重取最新）"""
    if not index_file.is_file():
        return None

    items = []
    seen = set()
    for line in index_file.read_text(encoding="utf-8").strip().splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            entry = json.loads(line)
            slug = entry.get("slug", "")
            if slug and slug not in seen:
                seen.add(slug)
                items.append({
                    "title": entry.get("title", slug),
                    "url": f"https://{slug}.xlog.ink/",
                    "time": entry.get("time", ""),
                })
        except json.JSONDecodeError:
            continue

    return items if items else None


TITLE_RE = re.compile(r"<title>(.*?)</title>", re.IGNORECASE | re.DOTALL)
URL_RE = re.compile(
    r'<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']',
    re.IGNORECASE,
)
TIME_RE = re.compile(
    r'<span class="(?:code|text-code)"[^>]*title=["\']UTC ISO8601["\'][^>]*>([^<]+)</span>',
    re.IGNORECASE,
)


def load_from_scan(site_dir=SITE_DIR):
    """回退：扫描 /site/ 目录（兼容旧数据）"""
    items = []
    for html_file in sorted(site_dir.glob("*.html")):
        try:
            text = html_file.read_text(encoding="utf-8", errors="ignore")

            m_title = TITLE_RE.search(text)
            m_url = URL_RE.search(text)
            m_time = TIME_RE.search(text)

            if not (m_title and m_url):
                continue

            title_text = m_title.group(1).strip()
            url_text = m_url.group(1).strip()
            time_text = m_time.group(1).strip() if m_time else ""

            try:
                dt = datetime.datetime.fromisoformat(
                    time_text.replace("Z", "+00:00")
                )
            except Exception:
                dt = datetime.datetime.utcfromtimestamp(
                    os.path.getmtime(html_file)
                )

            items.append({
                "title": title_text,
                "url": url_text,
                "time": dt.isoformat(),
            })
        except Exception as e:
            print(f"[WARN] 跳过 {html_file}: {e}")

    return items


def render_recent_html(recent_list):
    list_html = "\n".join(
        f'<li>'
        f'  <a href="{escape(it["url"], quote=True)}" target="_blank" rel="noopener">{escape(it["title"])}</a>'
        f'  <span class="text-code" title="{escape(it["time"], quote=True)}">{escape(it["time"][:10])}</span>'
        f'</li>'
        for it in recent_list
    )

    # 注意：f-string 中的花括号需要 {{ }} 转义
    return f"""<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title data-i18n="recentTitle">近期建立的頁面 - XLOG</title>
<meta name="description" data-i18n="recentDesc" content="瀏覽最近建立的 XLOG 個人頁面。">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://xlog.ink/recent.html">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f1115">
<meta property="og:site_name" content="XLOG">
<meta property="og:type" content="website">
<meta property="og:title" content="近期建立的頁面 - XLOG">
<meta property="og:description" content="瀏覽最近建立的 XLOG 個人頁面。">
<meta property="og:url" content="https://xlog.ink/recent.html">
<meta property="og:image" content="/assets/og/cover.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="近期建立的頁面 - XLOG">
<meta name="twitter:description" content="瀏覽最近建立的 XLOG 個人頁面。">
<meta name="twitter:image" content="/assets/og/cover.jpg">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" href="/favicon.ico" sizes="180x180">
<link rel="manifest" href="/site.webmanifest">
</head>
<body class="theme-dark page-recent">
<div class="page-bg-orb"></div><div class="page-bg-grid"></div>
<div class="container">
  <header class="page-header" aria-label="Site header">
    <div class="page-header__title"><a href="/" class="logo-link">XLOG</a></div>
    <div class="page-header__controls">
      <label><span data-i18n="themeLabel">主題</span>
        <select id="theme" aria-label="Theme">
          <option value="dark" data-i18n="dark">暗色</option>
          <option value="light" data-i18n="light">明亮</option>
        </select>
      </label>
      <label><span data-i18n="langLabel">語言</span>
        <select id="lang" aria-label="Language">
          <option value="zh-TW">繁體中文</option>
          <option value="zh-CN">简体中文</option>
          <option value="en">English</option>
        </select>
      </label>
    </div>
  </header>
  <main class="site-main">
  <section class="page-intro">
    <div class="hero-kicker" data-i18n="recentIntroKicker">Recent Pages · Public Feed</div>
    <h1 data-i18n="recentIntroTitle">近期建立的頁面</h1>
    <p data-i18n="recentIntroDesc">這裡展示最近公開生成的 XLOG 頁面，方便瀏覽目前的建立成果與內容方向。</p>
  </section>

  <div class="page-section ui-card">
    <h2 data-i18n="recentTitle">近期建立的頁面</h2>
    <ol class="recent-list" reversed>
{list_html}
    </ol>
  </div>

  <div class="page-section action-group">
    <a class="button button--accent" href="/creat.php" data-i18n="recentCreateBtn">建立你的頁面</a>
    <a class="button" href="/" data-i18n="homeBtn">返回首頁</a>
  </div>
  </main>
  <div id="footer-slot" class="footer-slot"></div>
</div>

<script src="/assets/js/i18n.js"></script>
<script src="/assets/js/app.js"></script>
<script>
(function(){{
  // 复用 XLOG 公共初始化
  if (window.XLOG) {{
    XLOG.initState();
    XLOG.applyLang(XLOG.curLang);
    XLOG.bindControls();
    XLOG.loadFooter();
  }}
}})();
</script>
</body>
</html>"""


def build_recent(index_file=INDEX_FILE, site_dir=SITE_DIR, output_file=OUTPUT_FILE, max_items=MAX_ITEMS):
    print("[INFO] 尝试从索引加载...")
    items = load_from_index(index_file)

    if items is None:
        print("[INFO] 索引不存在或为空，回退到扫描 /site/ ...")
        items = load_from_scan(site_dir)
    else:
        print(f"[INFO] 从索引加载了 {len(items)} 条记录")

    items.sort(key=lambda x: x["time"], reverse=True)
    recent_list = items[:max_items]

    print(f"[INFO] 输出 {len(recent_list)} 条到 {output_file}")
    output_file.write_text(render_recent_html(recent_list), encoding="utf-8")
    print("[OK] recent.html 已生成")


def main():
    build_recent()


if __name__ == "__main__":
    main()
