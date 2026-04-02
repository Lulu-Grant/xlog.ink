// app.js — 公共 UI 逻辑 + 链接模式表单交互
// 依赖：i18n.js（window.XLOG_I18N）

(function () {
  var I18N = window.XLOG_I18N || {};

  /* ==================== 公共工具 ==================== */

  function $(s, r) { return (r || document).querySelector(s); }
  function $all(s, r) { return Array.from((r || document).querySelectorAll(s)); }
  function applyThemeClass(theme) {
    document.body.classList.toggle('theme-dark', theme === 'dark');
    document.body.classList.toggle('theme-light', theme === 'light');
  }

  // 暴露到全局，供其他页面脚本复用
  window.XLOG = {
    $: $,
    $all: $all,
    I18N: I18N,
    curLang: 'zh-TW',
    curTheme: 'dark',

    /** 初始化语言/主题（从 URL 参数 → localStorage → 默认值） */
    initState: function () {
      var qs = new URLSearchParams(location.search);
      var LANGS = ['zh-TW', 'zh-CN', 'en'];
      var THEMES = ['light', 'dark'];

      var qLang = qs.get('lang');
      var qTheme = qs.get('theme');
      if (qLang && LANGS.indexOf(qLang) === -1) qLang = null;
      if (qTheme && THEMES.indexOf(qTheme) === -1) qTheme = null;

      this.curLang = qLang || localStorage.getItem('landing_lang') || 'zh-TW';
      this.curTheme = qTheme || localStorage.getItem('landing_theme') || 'dark';

      localStorage.setItem('landing_lang', this.curLang);
      localStorage.setItem('pg_lang', this.curLang);
      localStorage.setItem('landing_theme', this.curTheme);
      document.documentElement.setAttribute('lang', this.curLang);
      applyThemeClass(this.curTheme);
    },

    /** 应用 data-i18n 文案 */
    applyLang: function (lang) {
      var t = I18N[lang] || I18N['zh-TW'] || {};
      $all('[data-i18n]').forEach(function (el) {
        var key = el.getAttribute('data-i18n');
        if (!t[key]) return;
        if (/<[a-z][\s\S]*>/i.test(t[key])) {
          el.innerHTML = t[key];
        } else {
          el.textContent = t[key];
        }
      });
      $all('[data-ph]').forEach(function (el) {
        var key = el.getAttribute('data-ph');
        if (t[key]) el.setAttribute('placeholder', t[key]);
      });
    },

    /** 绑定顶部主题/语言下拉 + 隐藏字段同步 */
    bindControls: function (opts) {
      var self = this;
      opts = opts || {};

      var langSel = $('#lang');
      var themeSel = $('#theme');
      var hidLang = $('#hidden_ui_lang');
      var hidTheme = $('#hidden_theme');

      if (langSel) langSel.value = self.curLang;
      if (themeSel) themeSel.value = self.curTheme;
      if (hidLang) hidLang.value = self.curLang;
      if (hidTheme) hidTheme.value = self.curTheme;

      if (themeSel) {
        themeSel.addEventListener('change', function (e) {
          self.curTheme = e.target.value;
          localStorage.setItem('landing_theme', self.curTheme);
          applyThemeClass(self.curTheme);
          if (hidTheme) hidTheme.value = self.curTheme;
          if (opts.onThemeChange) opts.onThemeChange(self.curTheme);
        });
      }

      if (langSel) {
        langSel.addEventListener('change', function (e) {
          self.curLang = e.target.value;
          localStorage.setItem('landing_lang', self.curLang);
          localStorage.setItem('pg_lang', self.curLang);
          document.documentElement.setAttribute('lang', self.curLang);
          if (hidLang) hidLang.value = self.curLang;
          self.applyLang(self.curLang);
          self.loadFooter(self.curLang);
          if (opts.onLangChange) opts.onLangChange(self.curLang);
        });
      }
    },

    withParams: function (href) {
      try {
        var u = new URL(href, location.origin);
        u.searchParams.set('lang', this.curLang);
        u.searchParams.set('theme', this.curTheme);
        return u.pathname + u.search + u.hash;
      } catch (e) {
        return href;
      }
    },

    rewriteParamLinks: function (selector) {
      var self = this;
      $all(selector).forEach(function (a) {
        var href = a.getAttribute('href');
        if (!href) return;
        a.setAttribute('href', self.withParams(href));
      });
    },

    /** 加载并注入 Footer */
    loadFooter: function (lang) {
      var slot = $('#footer-slot');
      if (!slot) return;
      var footerLang = lang || this.curLang;
      var map = {
        'zh-TW': '/partials/footer.zh-TW.html',
        'zh-CN': '/partials/footer.zh-CN.html',
        'en': '/partials/footer.en.html'
      };
      var self = this;
      fetch(map[footerLang] || map['zh-TW'], { cache: 'force-cache' })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (html) {
          if (!html) return;
          slot.innerHTML = html;
          var y = slot.querySelector('#xlog-footer-year');
          if (y) y.textContent = new Date().getFullYear();
          slot.querySelectorAll('a[href="/"], a[href="/creat.php"], a[href="/creat-article.php"], a[href="/manual.html"], a[href="/recent.html"]').forEach(function (a) {
            a.setAttribute('href', self.withParams(a.getAttribute('href')));
          });
        }).catch(function () { });
    },

    /** 更新 SEO（title + meta description） */
    applySEO: function (titleKey, descKey) {
      var t = I18N[this.curLang] || {};
      if (t[titleKey]) document.title = t[titleKey];
      var title = t[titleKey];
      var desc = t[descKey];
      var md = $('meta[name="description"]');
      if (md && desc) md.setAttribute('content', desc);
      var ogTitle = $('meta[property="og:title"]');
      var ogDesc = $('meta[property="og:description"]');
      var twTitle = $('meta[name="twitter:title"]');
      var twDesc = $('meta[name="twitter:description"]');
      if (ogTitle && title) ogTitle.setAttribute('content', title);
      if (ogDesc && desc) ogDesc.setAttribute('content', desc);
      if (twTitle && title) twTitle.setAttribute('content', title);
      if (twDesc && desc) twDesc.setAttribute('content', desc);
    },

    /** Tab 切换绑定 */
    bindTabs: function (currentMode) {
      var self = this;
      var tabLinks = $('#tab-links');
      var tabArticle = $('#tab-article');
      if (!tabLinks || !tabArticle) return;

      if (currentMode === 'link') {
        tabLinks.classList.add('button--accent');
        tabLinks.setAttribute('aria-selected', 'true');
        tabArticle.setAttribute('aria-selected', 'false');
        tabArticle.addEventListener('click', function (e) {
          e.preventDefault();
          location.href = '/creat-article.php?lang=' + encodeURIComponent(self.curLang)
            + '&theme=' + encodeURIComponent(self.curTheme);
        });
      } else {
        tabArticle.classList.add('button--accent');
        tabArticle.setAttribute('aria-selected', 'true');
        tabLinks.setAttribute('aria-selected', 'false');
        tabLinks.addEventListener('click', function (e) {
          e.preventDefault();
          location.href = '/creat.php?lang=' + encodeURIComponent(self.curLang)
            + '&theme=' + encodeURIComponent(self.curTheme);
        });
      }
    }
  };

  /* ==================== 链接模式专用逻辑 ==================== */

  function addLinkItem(name, url) {
    name = name || '';
    url = url || '';
    var t = I18N[XLOG.curLang] || I18N['zh-TW'] || {};
    var wrap = document.createElement('div');
    wrap.className = 'link-item';
    wrap.innerHTML =
      '<input type="text" name="link_name[]" maxlength="30" placeholder="' + (t.name || '') + '" value="' + name.replace(/"/g, '&quot;') + '">'
      + '<input type="url" name="link_url[]" placeholder="' + (t.url || '') + '" value="' + url.replace(/"/g, '&quot;') + '">'
      + '<div class="link-actions">'
      + '<button type="button" class="button button-inline" data-act="up">' + (t.up || 'Up') + '</button>'
      + '<button type="button" class="button button-inline" data-act="down">' + (t.down || 'Down') + '</button>'
      + '<button type="button" class="button button-inline button--ghost" data-act="del">' + (t.del || 'Del') + '</button>'
      + '</div>';
    var container = $('#links');
    if (container) container.appendChild(wrap);
  }

  function onLinksClick(e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var item = btn.closest('.link-item');
    if (act === 'del') item.remove();
    if (act === 'up' && item.previousElementSibling) item.parentNode.insertBefore(item, item.previousElementSibling);
    if (act === 'down' && item.nextElementSibling) item.parentNode.insertBefore(item.nextElementSibling, item);
  }

  function validateLinkForm() {
    var titleEl = $('#title');
    if (!titleEl) return true;
    var title = titleEl.value.trim();
    if (!title || Array.from(title).length > 30) { alert('标题/標題/Title ≤ 30'); return false; }

    var bodyEl = $('#body');
    if (bodyEl) {
      var body = bodyEl.value.trim();
      if (Array.from(body).length > 500) { alert('正文/文字 ≤ 500'); return false; }
    }

    var names = $all('input[name="link_name[]"]').map(function (i) { return i.value.trim(); });
    var urls = $all('input[name="link_url[]"]').map(function (i) { return i.value.trim(); });
    var list = [];
    for (var i = 0; i < names.length; i++) {
      if (names[i] || urls[i]) list.push({ n: names[i], u: urls[i] });
    }

    if (list.length < 1) { alert('至少 1 条链接 / At least 1 link'); return false; }
    if (list.length > 20) { alert('最多 20 条链接 / Up to 20 links'); return false; }

    var urlRe = /^https?:\/\//i;
    for (var j = 0; j < list.length; j++) {
      if (!list[j].n || list[j].n.length > 30) { alert('链接名称必填且 ≤30'); return false; }
      if (!list[j].u || !urlRe.test(list[j].u)) { alert('URL 必须以 http/https 开头'); return false; }
    }
    return true;
  }

  // 暴露链接模式工具
  window.XLOG.addLinkItem = addLinkItem;
  window.XLOG.onLinksClick = onLinksClick;
  window.XLOG.validateLinkForm = validateLinkForm;

  /** 链接模式初始化（仅 creat.php 调用） */
  window.XLOG.initLinkMode = function () {
    var addBtn = $('#add-link');
    if (addBtn) addBtn.addEventListener('click', function () { addLinkItem(); });

    var linksWrap = $('#links');
    if (linksWrap) linksWrap.addEventListener('click', onLinksClick);

    addLinkItem();

    var form = $('#form');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (!validateLinkForm()) e.preventDefault();
      });
    }
  };

  /** 语言切换时刷新链接模式表单文案 */
  window.XLOG.refreshLinkI18N = function (lang) {
    var t = I18N[lang] || {};
    $all('#links .link-item .link-actions [data-act="up"]').forEach(function (b) { b.textContent = t.up || 'Up'; });
    $all('#links .link-item .link-actions [data-act="down"]').forEach(function (b) { b.textContent = t.down || 'Down'; });
    $all('#links .link-item .link-actions [data-act="del"]').forEach(function (b) { b.textContent = t.del || 'Del'; });
    $all('input[name="link_name[]"]').forEach(function (i) { i.setAttribute('placeholder', t.name || ''); });
    $all('input[name="link_url[]"]').forEach(function (i) { i.setAttribute('placeholder', t.url || ''); });
  };
})();
