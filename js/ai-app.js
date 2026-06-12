(function () {
  var state = {
    sessionId: document.body.dataset.editSession || '',
    busy: false,
    lastUrl: '',
    user: null,
    currentPage: null,
    currentPageIsAdult: false,
    readyShown: false,
    publishCard: null,
    previewCard: null,
    previewTimer: null,
    awaitingEmail: false,
    pendingAutoPublish: null,
  };
  var SESSION_STORAGE_KEY = 'xlog:lastSessionId';
  var locale = normalizeLocale(window.XLOG_LOCALE || document.body.dataset.locale || '');
  var i18n = window.XLOG_I18N || {};

  var $ = function (s) { return document.querySelector(s); };
  var messages = $('#messages');
  var input = $('#messageInput');
  var sendBtn = document.querySelector('#composer button[type="submit"]');
  var coarsePointer = window.matchMedia('(pointer: coarse)').matches;

  function updateAppViewportHeight() {
    var height = window.visualViewport && window.visualViewport.height ? window.visualViewport.height : window.innerHeight;
    if (height > 0) document.documentElement.style.setProperty('--app-vh', height + 'px');
  }
  updateAppViewportHeight();
  window.addEventListener('resize', updateAppViewportHeight);
  window.addEventListener('orientationchange', updateAppViewportHeight);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', updateAppViewportHeight);
    window.visualViewport.addEventListener('scroll', updateAppViewportHeight);
  }

  var jumpBtn = document.createElement('button');
  jumpBtn.type = 'button';
  jumpBtn.className = 'jump-latest';
  jumpBtn.textContent = t('backLatest');
  jumpBtn.hidden = true;
  document.querySelector('.chat-canvas').appendChild(jumpBtn);
  jumpBtn.addEventListener('click', function () { scrollDown(true); });

  var follow = true;
  messages.addEventListener('scroll', function () {
    follow = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
    jumpBtn.hidden = follow;
  });

  function scrollDown(force) {
    if (force) follow = true;
    if (follow) {
      messages.scrollTop = messages.scrollHeight;
      jumpBtn.hidden = true;
    } else {
      jumpBtn.hidden = false;
    }
  }

  function setBusy(busy) {
    state.busy = busy;
    if (sendBtn) sendBtn.disabled = busy;
  }

  function normalizeLocale(value) {
    value = String(value || '').replace('_', '-');
    if (value === 'zh-TW' || value === 'en') return value;
    return 'zh-CN';
  }

  function t(key, vars) {
    var copy = (i18n[locale] || i18n['zh-CN'] || {});
    var text = copy[key] || (i18n['zh-CN'] && i18n['zh-CN'][key]) || key;
    vars = vars || {};
    Object.keys(vars).forEach(function (name) {
      text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), String(vars[name]));
    });
    return text;
  }

  function setLocaleCookie(nextLocale) {
    document.cookie = 'xlog_locale=' + encodeURIComponent(nextLocale) + '; Max-Age=31536000; Path=/; SameSite=Lax';
  }

  function addMessage(role, text) {
    var el = document.createElement('div');
    el.className = 'msg ' + role;
    el.textContent = text;
    messages.appendChild(el);
    scrollDown(role === 'user');
    return el;
  }

  function addActionCard(type, html) {
    var card = document.createElement('div');
    card.className = 'action-card ' + type;
    card.innerHTML = html;
    messages.appendChild(card);
    scrollDown(false);
    return card;
  }

  function disableCard(card) {
    if (!card) return;
    card.dataset.disabled = '1';
    Array.prototype.forEach.call(card.querySelectorAll('button, input'), function (el) {
      el.disabled = true;
    });
  }

  function renderPresetCard() {
    if (document.querySelector('.preset-card')) return;
    addActionCard('preset-card',
      '<div class="action-title">' + escapeHtml(t('presetTitle')) + '</div>' +
      '<div class="preset-row" aria-label="Page type presets">' +
      '<button data-prompt="' + escapeAttr(t('promptCard')) + '">' + escapeHtml(t('presetCard')) + '</button>' +
      '<button data-prompt="' + escapeAttr(t('promptPoster')) + '">' + escapeHtml(t('presetPoster')) + '</button>' +
      '<button data-prompt="' + escapeAttr(t('promptArticle')) + '">' + escapeHtml(t('presetArticle')) + '</button>' +
      '<button data-prompt="' + escapeAttr(t('promptEvent')) + '">' + escapeHtml(t('presetEvent')) + '</button>' +
      '<button data-prompt="' + escapeAttr(t('promptFree')) + '">' + escapeHtml(t('presetFree')) + '</button>' +
      '</div>');
  }

  function renderHeroLogo() {
    if (document.querySelector('.hero-logo-card')) return;
    var card = document.createElement('div');
    card.className = 'hero-logo-card';
    card.innerHTML = '<img src="/assets/brand/xlog-animation-v5.svg" alt="" aria-hidden="true">';
    messages.appendChild(card);
  }

  function showGenerateCard(reason) {
    if (!state.sessionId || state.readyShown) return;
    state.readyShown = true;
    addActionCard('generate-card',
      '<div class="action-title">' + escapeHtml(t('generateReadyTitle')) + '</div>' +
      '<p>' + escapeHtml(reason || t('generateReadyBody')) + '</p>' +
      '<div class="inline-actions">' +
      '<button type="button" class="publish-btn" data-open-publish="1">' + escapeHtml(t('generateButton')) + '</button>' +
      '</div>');
  }

  function showPublishConfirmCard() {
    if (!state.sessionId || state.busy) return;
    if (state.publishCard && document.body.contains(state.publishCard)) {
      scrollDown(true);
      return;
    }
    var turnstile = '';
    if (document.body.dataset.turnstileEnabled === '1') {
      turnstile = '<div class="turnstile-box"><div class="inline-turnstile"></div></div>';
    }
    state.publishCard = addActionCard('publish-confirm-card',
      '<div class="action-title">' + escapeHtml(t('publishConfirmTitle')) + '</div>' +
      '<p>' + escapeHtml(t('publishConfirmBody')) + '</p>' +
      '<label class="adult-toggle">' +
      '<input class="inline-adult-checkbox" type="checkbox"' + (state.currentPageIsAdult ? ' checked' : '') + '>' +
      '<span>' + escapeHtml(t('adultToggle')) + '</span>' +
      '</label>' +
      turnstile +
      '<div class="inline-actions">' +
      '<button type="button" class="publish-btn" data-confirm-publish="1">' + escapeHtml(t('confirmGenerate')) + '</button>' +
      '<button type="button" class="ghost-btn" data-continue-chat="1">' + escapeHtml(t('continueChat')) + '</button>' +
      '</div>');
    renderInlineTurnstile(state.publishCard);
  }

  function renderInlineTurnstile(card) {
    if (!card || document.body.dataset.turnstileEnabled !== '1') return;
    var sitekey = document.body.dataset.turnstileSitekey || '';
    var target = card.querySelector('.inline-turnstile');
    if (!sitekey || !target || !window.turnstile || card.dataset.turnstileWidget) return;
    card.dataset.turnstileWidget = window.turnstile.render(target, {
      sitekey: sitekey,
      theme: 'light'
    });
  }

  function buildPreviewPlaceholder() {
    return '' +
      '<div class="preview-placeholder" aria-label="' + escapeAttr(t('previewAria')) + '">' +
      '<img class="website-build-svg" src="/assets/brand/website-building-v2.svg" alt="" aria-hidden="true">' +
      '<div class="preview-placeholder-copy">' +
      '<strong>' + escapeHtml(t('previewTitle')) + '</strong>' +
      '<span>' + escapeHtml(t('previewBody')) + '</span>' +
      '</div>' +
      '</div>';
  }

  function ensureLivePreviewCard() {
    if (!state.previewCard || !document.body.contains(state.previewCard)) {
      state.previewCard = addActionCard('live-preview-card',
        '<div class="live-preview-head">' +
        '<div><span class="live-dot"></span><strong>Page Forge Stream</strong></div>' +
        '<span class="live-preview-status">' + escapeHtml(t('previewStarting')) + '</span>' +
        '</div>' +
        buildPreviewPlaceholder() +
        '<iframe class="live-preview-frame" title="' + escapeAttr(t('previewFrameTitle')) + '" sandbox="" referrerpolicy="no-referrer" hidden></iframe>' +
        '<div class="live-preview-note">' + escapeHtml(t('previewNote')) + '</div>' +
        '<div class="delivery-panel" hidden>' +
        '<div class="url-box"></div>' +
        '<div class="delivery-body">' +
        '<canvas class="qr-canvas" width="180" height="180"></canvas>' +
        '<div class="delivery-actions">' +
        '<button type="button" data-copy-url="1">' + escapeHtml(t('copyLink')) + '</button>' +
        '<button type="button" data-download-qr="1">' + escapeHtml(t('downloadQr')) + '</button>' +
        '<a data-open-page="1" href="#" target="_blank" rel="noopener">' + escapeHtml(t('openPage')) + '</a>' +
        '</div>' +
        '</div>' +
        '</div>');
    }
    scrollDown(false);
    return state.previewCard;
  }

  function startGenerationPreview() {
    if (state.previewCard && state.previewCard.classList.contains('is-final')) {
      state.previewCard = null;
    }
    var card = ensureLivePreviewCard();
    card.classList.remove('is-final', 'delivery-card', 'is-previewing', 'is-stopped');
    delete card.dataset.pageUrl;
    updateLivePreviewStatus(t('previewStarting'));
    var placeholder = card.querySelector('.preview-placeholder');
    if (placeholder) placeholder.hidden = false;
    var iframe = card.querySelector('.live-preview-frame');
    if (iframe) {
      iframe.hidden = true;
      iframe.src = 'about:blank';
      iframe.removeAttribute('data-preview-url');
      iframe.setAttribute('sandbox', '');
    }
    var note = card.querySelector('.live-preview-note');
    if (note) note.textContent = t('previewNote');
    var panel = card.querySelector('.delivery-panel');
    if (panel) panel.hidden = true;
    scrollDown(false);
  }

  function finalizeLivePreview(url) {
    if (!url) return;
    var card = ensureLivePreviewCard();
    stopLivePreview(t('pageOnline'));
    state.lastUrl = url;
    card.classList.add('is-final', 'delivery-card');
    card.classList.remove('is-previewing', 'is-stopped');
    card.dataset.pageUrl = url;
    var placeholder = card.querySelector('.preview-placeholder');
    if (placeholder) placeholder.hidden = true;
    var iframe = card.querySelector('.live-preview-frame');
    iframe.hidden = false;
    iframe.removeAttribute('data-preview-url');
    iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups');
    iframe.src = url;
    var note = card.querySelector('.live-preview-note');
    if (note) note.textContent = t('finalPreviewNote');
    var panel = card.querySelector('.delivery-panel');
    if (panel) panel.hidden = false;
    var urlBox = card.querySelector('.url-box');
    if (urlBox) urlBox.textContent = url;
    var openLink = card.querySelector('[data-open-page]');
    if (openLink) openLink.href = url;
    var canvas = card.querySelector('.qr-canvas');
    if (!drawQr(canvas, url)) {
      var downloadBtn = card.querySelector('button[data-download-qr]');
      if (downloadBtn) downloadBtn.hidden = true;
    }
    scrollDown(false);
  }

  function updateLivePreviewStatus(text) {
    if (!state.previewCard) return;
    var status = state.previewCard.querySelector('.live-preview-status');
    if (status) status.textContent = text;
  }

  function stopLivePreview(text) {
    if (state.previewTimer) clearInterval(state.previewTimer);
    state.previewTimer = null;
    if (text) updateLivePreviewStatus(text);
    if (state.previewCard) state.previewCard.classList.add('is-stopped');
  }

  function isPublishIntent(text) {
    text = text || '';
    if (/(不要|別|别|不用|不必|暫不|暂不|先不|無需|无需|取消|do not|don't|dont|no need|not now)\s*(生成|發布|发布|上線|上线|建立頁面|创建页面|開始生成|开始生成|開始做|开始做|重新生成|再做一个|再做一個|generate|publish|go live|create page)/i.test(text)) {
      return false;
    }
    return /(直接|立即|現在|现在|確認|确认|可以|重新|再次|再|開始|开始|please|now|confirm|ready to)?\s*(生成|發布|发布|上線|上线|建立頁面|创建页面|開始生成|开始生成|開始做|开始做|重新生成|再做一个|再做一個|generate|publish|go live|create page)/i.test(text);
  }

  function handleAction(action, userPublishIntent) {
    if (!action || !action.type) return;
    var params = action.params || {};
    if (action.type === 'upload') addUploadCard(params);
    else if (action.type === 'ready') showGenerateCard(params.reason || t('readyFallback'));
    else if (action.type === 'publish') {
      if (userPublishIntent) state.pendingAutoPublish = params;
      else showGenerateCard(params.reason || t('readyNeedsConfirm'));
    }
    else if (action.type === 'email') showEmailCard();
  }

  function runAutoPublish(params) {
    params = params || {};
    if (document.body.dataset.turnstileEnabled === '1') {
      addMessage('system', t('turnstileFirst'));
      state.readyShown = false;
      showPublishConfirmCard();
      return;
    }
    addMessage('system', params.reason ? t('publishConfirmedReason', { reason: params.reason }) : t('publishConfirmed'));
    publish({ isAdult: state.currentPageIsAdult });
  }

  function addUploadCard(params) {
    params = params || {};
    if (document.querySelector('.upload-card[data-active="1"]')) return;
    var card = document.createElement('div');
    card.className = 'upload-card';
    card.dataset.active = '1';
    card.dataset.slot = params.slot || '';
    card.innerHTML =
      '<strong>' + escapeHtml(t('uploadTitle')) + '</strong>' +
      '<p>' + escapeHtml(t('uploadBody')) + '</p>' +
      '<input class="upload-caption" type="text" placeholder="' + escapeAttr(t('uploadCaption')) + '" value="' + escapeAttr(params.hint || '') + '">' +
      '<div class="upload-card-actions">' +
      '<label>' + escapeHtml(t('chooseImage')) + '<input class="upload-file" type="file" accept="image/*"></label>' +
      '<button type="button" class="skip-upload">' + escapeHtml(t('skipUpload')) + '</button>' +
      '</div>';
    messages.appendChild(card);
    scrollDown(false);
    card.querySelector('.upload-file').addEventListener('change', function (e) {
      uploadImage(e.target.files[0], card.querySelector('.upload-caption').value.trim(), card.dataset.slot || '', card);
    });
    card.querySelector('.skip-upload').addEventListener('click', function () {
      card.dataset.active = '0';
      card.remove();
      sendMessage(t('skipUploadMessage'));
    });
  }

  function setQuota(q) {
    if (!q) return;
    var text = q.credits !== undefined ? t('quotaCredits', { credits: q.credits }) : t('quotaRemaining', { remaining: q.remaining, limit: q.limit });
    $('#quotaText').textContent = text;
  }

  var toastEl = null;
  var toastTimer = null;
  function toast(text) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = text;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2000);
  }

  function setUser(user) {
    state.user = user || null;
    var myPages = $('#myPagesToggle');
    var login = $('#loginToggle');
    if (myPages) {
      myPages.hidden = !state.user;
      if (window.matchMedia('(max-width: 760px)').matches) myPages.textContent = t('myPagesShort');
      else myPages.textContent = t('myPages');
    }
    if (login) {
      login.textContent = state.user ? (state.user.email || t('accountFallback')).split('@')[0] : t('login');
    }
    $('#loginStepEmail').hidden = !!state.user;
    $('#loginStepCode').hidden = true;
    $('#accountRow').hidden = !state.user;
    if (state.user) $('#accountEmail').textContent = state.user.email || '';
  }

  function api(path, body) {
    body = body || {};
    body.locale = locale;
    return fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function rememberSession(sessionId) {
    if (!sessionId) return;
    try { window.sessionStorage.setItem(SESSION_STORAGE_KEY, sessionId); } catch (e) {}
  }

  function forgetSession() {
    try { window.sessionStorage.removeItem(SESSION_STORAGE_KEY); } catch (e) {}
  }

  function storedSessionId() {
    try {
      var id = window.sessionStorage.getItem(SESSION_STORAGE_KEY) || '';
      return /^[a-f0-9]{32}$/.test(id) ? id : '';
    } catch (e) {
      return '';
    }
  }

  function renderStoredMessage(message) {
    var role = message.role || 'assistant';
    var content = message.content || '';
    if (role === 'system' && content.indexOf('[系统事件]') === 0) return;
    if (role === 'user' && content.indexOf('[当前页面信息]') === 0) return;
    if (role === 'user' && content.indexOf('[目前頁面資訊]') === 0) return;
    if (role === 'user' && content.indexOf('[Current page info]') === 0) return;
    if (role === 'user' && content.indexOf('[图片已上传:') === 0) return;
    addMessage(role === 'user' ? 'user' : (role === 'system' ? 'system' : 'assistant'), content);
  }

  function applySessionPayload(data, fresh) {
    if (!data || data.error || !data.session_id) {
      if (data && data.error && data.error.code === 'session_not_found') forgetSession();
      throw new Error(data && data.error ? data.error.message : 'session_failed');
    }
    state.sessionId = data.session_id;
    rememberSession(state.sessionId);
    setQuota(data.quota);
    state.currentPage = data.page || null;
    state.currentPageIsAdult = !!(data.page && data.page.is_adult);
    state.lastUrl = data.page && data.page.url ? data.page.url : '';
    state.awaitingEmail = false;
    state.pendingAutoPublish = null;
    messages.innerHTML = '';
    state.readyShown = false;
    state.publishCard = null;
    state.previewCard = null;
    if (state.previewTimer) clearInterval(state.previewTimer);
    state.previewTimer = null;

    if (data.messages && data.messages.length) {
      messages.classList.remove('is-hero');
      input.placeholder = t('composerPlaceholder');
      data.messages.forEach(renderStoredMessage);
    } else {
      messages.classList.add('is-hero');
      input.placeholder = t('heroComposerPlaceholder');
      renderHeroLogo();
      addMessage('assistant', data.greeting || t('greeting'));
      renderPresetCard();
    }

    if (data.page && data.page.url) {
      finalizeLivePreview(data.page.url);
      if (data.edit_mode) {
        addMessage('system', t('editModeCurrent'));
      }
    } else if (data.edit_mode && fresh) {
      addMessage('assistant', t('editModeEntered'));
    }
  }

  function createFreshSession() {
    return api('/api/session.php', {}).then(function (data) {
      applySessionPayload(data, true);
    });
  }

  function start() {
    api('/api/auth/me.php', {}).then(function (me) {
      setUser(me.user);
      setQuota(me.quota);
    }).catch(function () { setUser(null); });
    var resumeId = state.sessionId || storedSessionId();
    if (resumeId) {
      api('/api/session.php', { session_id: resumeId }).then(function (data) {
        applySessionPayload(data, false);
      }).catch(function () {
        forgetSession();
        return createFreshSession();
      }).catch(function () {
        addMessage('system', t('sessionFailed'));
      });
      return;
    }
    createFreshSession().catch(function () {
      addMessage('system', t('sessionFailed'));
    });
  }

  function readSse(response, handlers) {
    var reader = response.body.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    function pump() {
      return reader.read().then(function (result) {
        if (result.done) return;
        buffer += decoder.decode(result.value, { stream: true });
        var parts = buffer.split('\n\n');
        buffer = parts.pop();
        parts.forEach(function (part) {
          var event = 'message';
          var data = '{}';
          part.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) event = line.slice(6).trim();
            if (line.indexOf('data:') === 0) data = line.slice(5).trim();
          });
          var parsed = {};
          try { parsed = JSON.parse(data); } catch (e) {}
          if (handlers[event]) handlers[event](parsed);
        });
        return pump();
      });
    }
    return pump();
  }

  function sendMessage(text) {
    if (!text || state.busy) return;
    if (messages.classList.contains('is-hero')) {
      messages.classList.remove('is-hero');
      input.placeholder = t('composerPlaceholder');
    }
    var publishIntent = isPublishIntent(text);
    if (state.awaitingEmail) {
      var contextualEmail = extractEmail(text);
      var skipEmailIntent = /(不(用|要|留|绑定|綁定)|暫不|暂不|跳過|跳过|算了|不用了|不要了|skip|no need|not now|no thanks)/i.test(text);
      if (contextualEmail || skipEmailIntent) {
        setBusy(true);
        addMessage('user', text);
        input.value = '';
        autogrow();
        if (contextualEmail) {
          bindEmail(document.querySelector('.email-card[data-active="1"]'), contextualEmail).finally(function () {
            setBusy(false);
          });
        } else {
          skipEmailBinding(document.querySelector('.email-card[data-active="1"]'));
          setBusy(false);
        }
        return;
      }
      if (publishIntent) {
        completeEmailCard(document.querySelector('.email-card[data-active="1"]'));
        addMessage('system', t('emailClosedForNewPublish'));
      }
    }
    setBusy(true);
    addMessage('user', text);
    input.value = '';
    autogrow();
    var ai = addMessage('assistant', '');
    ai.classList.add('is-typing');
    fetch('/api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: state.sessionId, message: text, locale: locale })
    }).then(function (r) {
      return readSse(r, {
        delta: function (d) {
          if (d.text) ai.classList.remove('is-typing');
          ai.textContent += d.text || '';
          scrollDown(false);
        },
        action: function (d) { handleAction(d, publishIntent); },
        notice: function (d) { addMessage('system', d.message || t('systemNotice')); },
        error: function (d) { addMessage('system', '[err] ' + (d.message || t('aiChatFailed'))); }
      });
    }).then(function () {
      if (!state.pendingAutoPublish && publishIntent) showGenerateCard(t('mentionedGenerate'));
    }).finally(function () {
      ai.classList.remove('is-typing');
      setBusy(false);
      if (state.pendingAutoPublish) {
        var autoPublish = state.pendingAutoPublish;
        state.pendingAutoPublish = null;
        runAutoPublish(autoPublish);
      }
    });
  }

  function buildBar(chars) {
    var pct = Math.min(99, Math.round(chars / 35000 * 100));
    var filled = Math.round(pct * 16 / 100);
    var bar = '';
    for (var i = 0; i < 16; i++) bar += i < filled ? '#' : '.';
    return 'BUILD [' + bar + '] ' + pct + '%';
  }

  function publish(options) {
    if (!state.sessionId || state.busy) return;
    setBusy(true);
    state.lastUrl = '';
    var publishTerminal = false;
    var progress = addMessage('system', t('startGenerating'));
    startGenerationPreview();
    var turnstileToken = '';
    if (document.body.dataset.turnstileEnabled === '1') {
      if (options && options.card) renderInlineTurnstile(options.card);
      var widgetId = options && options.turnstileWidget !== undefined ? options.turnstileWidget : (options && options.card ? options.card.dataset.turnstileWidget : undefined);
      if (window.turnstile && widgetId !== undefined) {
        turnstileToken = window.turnstile.getResponse(widgetId);
      } else {
        var responseEl = document.querySelector('[name="cf-turnstile-response"]');
        turnstileToken = responseEl ? responseEl.value : '';
      }
      if (!turnstileToken) {
        addMessage('system', t('turnstileRequired'));
        setBusy(false);
        return;
      }
    }
    if (options && options.card) disableCard(options.card);
    fetch('/api/publish.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: state.sessionId,
        turnstile_token: turnstileToken,
        locale: locale,
        is_adult: !!(options && options.isAdult)
      })
    }).then(function (r) {
      var generatedChars = 0;
      var lastProgressAt = 0;
      return readSse(r, {
        stage: function (d) {
          if (d.stage === 'writing') {
            progress.textContent = 'BUILD [################] 100% · ' + t('stageWriting');
            updateLivePreviewStatus(t('writingFinal'));
          } else if (d.stage === 'done') {
            progress.textContent = '[ok] ' + t('stageDone');
            stopLivePreview(t('finalWritten'));
          } else {
            progress.textContent = '[..] ' + t('stage', { stage: d.stage });
          }
        },
        delta: function (d) {
          generatedChars += (d.text || '').length;
          var now = Date.now();
          if (now - lastProgressAt > 800) {
            progress.textContent = buildBar(generatedChars) + ' · ' + t('receivedCharsShort', { count: generatedChars });
            updateLivePreviewStatus(buildBar(generatedChars));
            lastProgressAt = now;
          }
        },
        error: function (d) {
          publishTerminal = true;
          state.readyShown = false;
          stopLivePreview(t('previewFailed'));
          addMessage('system', '[err] ' + (d.message || t('generationFailed')));
          if (window.turnstile) window.turnstile.reset();
        },
        result: function (d) {
          publishTerminal = true;
          state.lastUrl = d.url;
          state.currentPage = { url: d.url, slug: d.slug || '', is_adult: !!d.is_adult };
          state.currentPageIsAdult = !!d.is_adult;
          state.readyShown = false;
          state.publishCard = null;
          finalizeLivePreview(d.url);
          addMessage('assistant', t('publishedAskEmail'));
          showEmailCard();
          api('/api/auth/me.php', {}).then(function (me) { setUser(me.user); setQuota(me.quota); }).catch(function () {});
          if (window.turnstile) window.turnstile.reset();
        }
      });
    }).then(function () {
      if (!publishTerminal) {
        stopLivePreview(t('connectionEnded'));
        addMessage('system', '[err] ' + t('connectionEndedBody'));
      }
    }).catch(function () {
      state.readyShown = false;
      stopLivePreview(t('connectionInterrupted'));
      addMessage('system', '[err] ' + t('connectionInterruptedBody'));
      if (window.turnstile) window.turnstile.reset();
    }).finally(function () {
      setBusy(false);
      state.publishCard = null;
    });
  }

  function showEmailCard() {
    var existingCard = document.querySelector('.email-card[data-active="1"]');
    if (existingCard) {
      var existingInput = existingCard.querySelector('.owner-email');
      if (existingInput) existingInput.focus();
      return;
    }
    var card = addActionCard('email-card',
      '<div class="action-title">' + escapeHtml(t('emailTitle')) + '</div>' +
      '<p>' + escapeHtml(t('emailBody')) + '</p>' +
      '<div class="email-row"><input class="owner-email" type="email" placeholder="' + escapeAttr(t('emailPlaceholder')) + '"><button type="button" data-bind-email="1">' + escapeHtml(t('sendEditLink')) + '</button></div>' +
      '<div class="inline-actions"><button type="button" class="ghost-btn" data-skip-email="1">' + escapeHtml(t('skipEmail')) + '</button></div>');
    card.dataset.active = '1';
    state.awaitingEmail = true;
  }

  function bindEmail(card, explicitEmail) {
    var emailInput = card ? card.querySelector('.owner-email') : null;
    var email = explicitEmail || (emailInput ? emailInput.value.trim() : '');
    if (!email) return Promise.resolve(false);
    if (!extractEmail(email) || extractEmail(email) !== email) {
      addMessage('system', t('invalidEmail'));
      return Promise.resolve(false);
    }
    if (emailInput) emailInput.value = email;
    return api('/api/page-email.php', { session_id: state.sessionId, email: email }).then(function (r) {
      if (r.error) {
        addMessage('system', r.error.message);
        return false;
      }
      completeEmailCard(card);
      addMessage('assistant', t('editLinkSent', { email: email }));
      return true;
    }).catch(function () {
      addMessage('system', t('editLinkFailed'));
      return false;
    });
  }

  function completeEmailCard(card) {
    disableCard(card);
    if (card) card.dataset.active = '0';
    state.awaitingEmail = false;
  }

  function skipEmailBinding(card) {
    completeEmailCard(card);
    addMessage('system', t('emailSkipped'));
  }

  function toggleMyPages(open) {
    var panel = $('#myPagesPanel');
    panel.hidden = open === undefined ? !panel.hidden : !open;
    if (!panel.hidden) loadMyPages();
  }

  function loadMyPages() {
    var list = $('#myPagesList');
    if (!state.user) {
      list.innerHTML = '<span>' + escapeHtml(t('myPagesHint')) + '</span>';
      return;
    }
    list.innerHTML = '<span>' + escapeHtml(t('myPagesLoading')) + '</span>';
    api('/api/my-pages.php', {}).then(function (r) {
      if (r.error) {
        list.innerHTML = '<span>' + escapeHtml(r.error.message) + '</span>';
        return;
      }
      if (!r.pages || !r.pages.length) {
        list.innerHTML = '<span>' + escapeHtml(t('myPagesEmpty')) + '</span>';
        return;
      }
      list.innerHTML = r.pages.map(function (p) {
        var date = (p.updated_at || p.created_at || '').slice(0, 10);
        return '<div class="my-page-item" data-slug="' + escapeAttr(p.slug) + '">' +
          '<div class="my-page-main">' +
          '<div class="my-page-title">' + escapeHtml(p.title || p.slug) + '</div>' +
          '<div class="my-page-meta">' + escapeHtml((p.type || t('pageTypeFallback')) + ' · ' + date) + '</div>' +
          '</div>' +
          (p.is_adult ? '<span class="my-page-badge">18+</span>' : '<span></span>') +
          '<a href="' + escapeAttr(p.url) + '" target="_blank" rel="noopener">' + escapeHtml(t('openPage')) + '</a>' +
          '<button type="button" data-edit-slug="' + escapeAttr(p.slug) + '">' + escapeHtml(t('edit')) + '</button>' +
          '</div>';
      }).join('');
    });
  }

  function enterOwnerEdit(slug) {
    api('/api/edit-session.php', { slug: slug }).then(function (r) {
      if (r.error) {
        addMessage('system', r.error.message);
        return;
      }
      state.sessionId = r.session_id;
      toggleMyPages(false);
      api('/api/session.php', { session_id: state.sessionId }).then(function (data) {
        applySessionPayload(data, false);
      }).catch(function () {
        $('#messages').innerHTML = '';
        state.readyShown = false;
        state.publishCard = null;
        addMessage('assistant', t('editModeEntered'));
      });
    });
  }

  function uploadImage(file, caption, slot, card) {
    if (!state.sessionId || !file) return;
    var fd = new FormData();
    fd.append('session_id', state.sessionId);
    fd.append('caption', caption || '');
    fd.append('slot', slot || '');
    fd.append('locale', locale);
    fd.append('file', file);
    fetch('/api/upload.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { addMessage('system', d.error.message); return; }
      if (card) {
        card.dataset.active = '0';
        card.innerHTML = '<strong>' + escapeHtml(t('imageUploaded')) + '</strong><p>' + escapeHtml(caption || t('noCaption')) + '</p><img src="' + escapeAttr(d.url) + '" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:12px">';
      }
      toast(t('imageUploadedToast'));
    });
  }

  function drawQr(canvas, text) {
    if (window.qrcode) {
      var qr = window.qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      var count = qr.getModuleCount();
      var ctxReal = canvas.getContext('2d');
      var cellReal = canvas.width / count;
      ctxReal.fillStyle = '#fff';
      ctxReal.fillRect(0, 0, canvas.width, canvas.height);
      ctxReal.fillStyle = '#000';
      for (var row = 0; row < count; row++) {
        for (var col = 0; col < count; col++) {
          if (qr.isDark(row, col)) {
            ctxReal.fillRect(Math.floor(col * cellReal), Math.floor(row * cellReal), Math.ceil(cellReal), Math.ceil(cellReal));
          }
        }
      }
      return true;
    }
    if (!canvas) return false;
    var fallback = document.createElement('div');
    fallback.className = 'qr-fallback';
    fallback.textContent = t('qrFailed');
    canvas.replaceWith(fallback);
    return false;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; });
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }
  function extractEmail(text) {
    var match = String(text || '').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
    return match ? match[0] : '';
  }

  function autogrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  $('#composer').addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value.trim());
  });
  input.addEventListener('input', autogrow);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !coarsePointer && !e.isComposing) {
      e.preventDefault();
      sendMessage(input.value.trim());
    }
  });

  messages.addEventListener('click', function (e) {
    var promptBtn = e.target.closest('button[data-prompt]');
    if (promptBtn) {
      disableCard(promptBtn.closest('.action-card'));
      sendMessage(promptBtn.dataset.prompt);
      return;
    }
    var openPublish = e.target.closest('button[data-open-publish]');
    if (openPublish) {
      disableCard(openPublish.closest('.action-card'));
      showPublishConfirmCard();
      return;
    }
    var confirmPublish = e.target.closest('button[data-confirm-publish]');
    if (confirmPublish) {
      var publishCard = confirmPublish.closest('.action-card');
      var checkbox = publishCard ? publishCard.querySelector('.inline-adult-checkbox') : null;
      var widget = publishCard && publishCard.dataset.turnstileWidget !== undefined ? publishCard.dataset.turnstileWidget : undefined;
      publish({
        isAdult: !!(checkbox && checkbox.checked),
        turnstileWidget: widget,
        card: publishCard
      });
      return;
    }
    var continueChat = e.target.closest('button[data-continue-chat]');
    if (continueChat) {
      disableCard(continueChat.closest('.action-card'));
      state.readyShown = false;
      state.publishCard = null;
      addMessage('system', t('continuePrompt'));
      return;
    }
    var bindEmailBtn = e.target.closest('button[data-bind-email]');
    if (bindEmailBtn) {
      bindEmail(bindEmailBtn.closest('.email-card'));
      return;
    }
    var skipEmail = e.target.closest('button[data-skip-email]');
    if (skipEmail) {
      skipEmailBinding(skipEmail.closest('.email-card'));
      return;
    }
    var delivery = e.target.closest('.delivery-card');
    if (!delivery) return;
    var url = delivery.dataset.pageUrl || state.lastUrl;
    if (e.target.closest('button[data-copy-url]')) {
      var copyBtn = e.target.closest('button[data-copy-url]');
      navigator.clipboard.writeText(url).then(function () {
        toast(t('copied'));
        copyBtn.textContent = t('copiedButton');
        setTimeout(function () { copyBtn.textContent = t('copyLink'); }, 1500);
      }).catch(function () {
        toast(t('copyFailed'));
      });
      return;
    }
    if (e.target.closest('button[data-download-qr]')) {
      var canvas = delivery.querySelector('.qr-canvas');
      if (!canvas) return;
      var a = document.createElement('a');
      a.download = 'xlog-page-qr.png';
      a.href = canvas.toDataURL('image/png');
      a.click();
      return;
    }
  });
  $('#loginToggle').addEventListener('click', function () {
    var box = $('#accountBox');
    box.hidden = !box.hidden;
  });
  $('#myPagesToggle').addEventListener('click', function () { toggleMyPages(); });
  $('#closeMyPages').addEventListener('click', function () { toggleMyPages(false); });
  $('#myPagesList').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-edit-slug]');
    if (btn) enterOwnerEdit(btn.dataset.editSlug);
  });
  function loginHint(text) {
    var el = $('#loginHint');
    if (!text) { el.hidden = true; return; }
    el.textContent = text;
    el.hidden = false;
  }

  var codeTimer = null;
  function startCodeCountdown(seconds) {
    var btn = $('#sendCodeBtn');
    var left = seconds;
    btn.disabled = true;
    btn.textContent = t('resendSeconds', { seconds: left });
    clearInterval(codeTimer);
    codeTimer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(codeTimer);
        btn.disabled = false;
        btn.textContent = t('resend');
        return;
      }
      btn.textContent = t('resendSeconds', { seconds: left });
    }, 1000);
  }

  $('#sendCodeBtn').addEventListener('click', function () {
    var email = $('#loginEmail').value.trim();
    if (!email) { loginHint(t('enterEmailFirst')); return; }
    var btn = this;
    btn.disabled = true;
    api('/api/auth/send-code.php', { email: email }).then(function (r) {
      if (r.error) {
        btn.disabled = false;
        loginHint(r.error.message);
        return;
      }
      $('#loginStepCode').hidden = false;
      $('#loginCode').focus();
      loginHint(t('codeSent', { email: email }));
      startCodeCountdown(60);
    }).catch(function () {
      btn.disabled = false;
      loginHint(t('sendFailed'));
    });
  });
  $('#verifyCodeBtn').addEventListener('click', function () {
    api('/api/auth/verify.php', { email: $('#loginEmail').value.trim(), code: $('#loginCode').value.trim() }).then(function (r) {
      if (r.error) { loginHint(r.error.message); return; }
      loginHint('');
      $('#loginCode').value = '';
      toast(t('loginSuccess'));
      api('/api/auth/me.php', {}).then(function (me) { setUser(me.user); setQuota(me.quota); });
    });
  });
  $('#logoutBtn').addEventListener('click', function () {
    api('/api/auth/logout.php', {}).then(function () {
      loginHint('');
      $('#accountBox').hidden = true;
      toast(t('logoutSuccess'));
      api('/api/auth/me.php', {}).then(function (me) { setUser(me.user); setQuota(me.quota); }).catch(function () { setUser(null); });
    });
  });
  $('#loginEmail').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#sendCodeBtn').click(); }
  });
  $('#loginCode').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#verifyCodeBtn').click(); }
  });
  input.addEventListener('focus', function () {
    updateAppViewportHeight();
    setTimeout(function () { updateAppViewportHeight(); scrollDown(true); }, 80);
  });
  var localeSwitch = $('#localeSwitch');
  if (localeSwitch) {
    localeSwitch.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-locale-choice]');
      if (!btn) return;
      var nextLocale = normalizeLocale(btn.dataset.localeChoice);
      if (nextLocale === locale) return;
      setLocaleCookie(nextLocale);
      var url = new URL(window.location.href);
      url.searchParams.set('locale', nextLocale);
      window.location.href = url.toString();
    });
  }

  start();
})();
