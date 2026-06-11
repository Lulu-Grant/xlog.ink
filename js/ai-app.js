(function () {
  var state = {
    sessionId: document.body.dataset.editSession || '',
    busy: false,
    lastUrl: '',
    user: null,
    readyShown: false,
    publishCard: null,
  };

  var $ = function (s) { return document.querySelector(s); };
  var messages = $('#messages');
  var input = $('#messageInput');

  function addMessage(role, text) {
    var el = document.createElement('div');
    el.className = 'msg ' + role;
    el.textContent = text;
    messages.appendChild(el);
    messages.scrollTop = messages.scrollHeight;
    return el;
  }

  function addActionCard(type, html) {
    var card = document.createElement('div');
    card.className = 'action-card ' + type;
    card.innerHTML = html;
    messages.appendChild(card);
    messages.scrollTop = messages.scrollHeight;
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
      '<div class="action-title">先选一个方向，或者直接输入你的想法</div>' +
      '<div class="preset-row" aria-label="Page type presets">' +
      '<button data-prompt="我想创建一个个人名片页面，请引导我补充必要信息。">名片</button>' +
      '<button data-prompt="我想创建一个产品或服务宣传海报页面，请帮我整理需求。">宣传海报</button>' +
      '<button data-prompt="我想创建一篇文章页面，请问我需要哪些内容。">文章页面</button>' +
      '<button data-prompt="我想创建一个活动页面，请引导我提供活动名称、时间、地址和联系方式。">活动页面</button>' +
      '<button data-prompt="我想自由创建一个页面，我会直接描述。">自由描述</button>' +
      '</div>');
  }

  function showGenerateCard(reason) {
    if (!state.sessionId || state.readyShown) return;
    state.readyShown = true;
    addActionCard('generate-card',
      '<div class="action-title">现在可以生成页面</div>' +
      '<p>' + escapeHtml(reason || '信息已经足够，也可以继续补充细节。') + '</p>' +
      '<div class="inline-actions">' +
      '<button type="button" class="publish-btn" data-open-publish="1">生成页面</button>' +
      '</div>');
  }

  function showPublishConfirmCard() {
    if (!state.sessionId || state.busy) return;
    if (state.publishCard && document.body.contains(state.publishCard)) {
      messages.scrollTop = messages.scrollHeight;
      return;
    }
    var turnstile = '';
    if (document.body.dataset.turnstileEnabled === '1') {
      turnstile = '<div class="turnstile-box"><div class="inline-turnstile"></div></div>';
    }
    state.publishCard = addActionCard('publish-confirm-card',
      '<div class="action-title">生成前确认</div>' +
      '<p>确认后会调用生成模型并发布到专属二级域名。生成期间请保持当前窗口打开。</p>' +
      '<label class="adult-toggle">' +
      '<input class="inline-adult-checkbox" type="checkbox">' +
      '<span>此页面包含 18+ 成人内容，发布后先显示确认页</span>' +
      '</label>' +
      turnstile +
      '<div class="inline-actions">' +
      '<button type="button" class="publish-btn" data-confirm-publish="1">确认生成</button>' +
      '<button type="button" class="ghost-btn" data-continue-chat="1">继续补充</button>' +
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

  function maybeShowUploadCard(text) {
    if (!/(上传|图片|照片|资料图|主视觉|头像|产品图|活动图|image|photo)/i.test(text || '')) return;
    if (document.querySelector('.upload-card[data-active="1"]')) return;
    addUploadCard();
  }

  function addUploadCard() {
    var card = document.createElement('div');
    card.className = 'upload-card';
    card.dataset.active = '1';
    card.innerHTML =
      '<strong>上传资料图</strong>' +
      '<p>选择图片并写一句用途说明，例如“活动主视觉”“产品细节”“头像”。图片会自动转成适合网页分发的 WebP。</p>' +
      '<input class="upload-caption" type="text" placeholder="图片说明">' +
      '<div class="upload-card-actions">' +
      '<label>选择图片<input class="upload-file" type="file" accept="image/*"></label>' +
      '<button type="button" class="skip-upload">没有图片，跳过</button>' +
      '</div>';
    messages.appendChild(card);
    messages.scrollTop = messages.scrollHeight;
    card.querySelector('.upload-file').addEventListener('change', function (e) {
      uploadImage(e.target.files[0], card.querySelector('.upload-caption').value.trim(), card);
    });
    card.querySelector('.skip-upload').addEventListener('click', function () {
      card.dataset.active = '0';
      card.remove();
      sendMessage('没有图片，跳过图片上传，可以继续生成。');
    });
  }

  function setQuota(q) {
    if (!q) return;
    var text = q.credits !== undefined ? '积分 ' + q.credits : '剩余 ' + q.remaining + '/' + q.limit;
    $('#quotaText').textContent = text;
    if ($('#brandLink') && window.matchMedia('(max-width: 760px)').matches) {
      $('#brandLink').textContent = 'xlog.ink · ' + text;
    }
  }

  function setUser(user) {
    state.user = user || null;
    var myPages = $('#myPagesToggle');
    var login = $('#loginToggle');
    if (myPages) {
      myPages.hidden = !state.user;
      if (window.matchMedia('(max-width: 760px)').matches) myPages.textContent = '页面';
      else myPages.textContent = '我的页面';
    }
    if (login) login.textContent = state.user ? '已登录' : '登录';
  }

  function api(path, body) {
    return fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function start() {
    api('/api/auth/me.php', {}).then(function (me) {
      setUser(me.user);
      setQuota(me.quota);
    }).catch(function () { setUser(null); });
    if (state.sessionId) {
      addMessage('assistant', '已进入修改模式。告诉我你想调整哪里，完成后点击生成会覆盖原页面。');
      return;
    }
    api('/api/session.php', {}).then(function (data) {
      state.sessionId = data.session_id;
      setQuota(data.quota);
      addMessage('assistant', data.greeting);
      renderPresetCard();
    }).catch(function () {
      addMessage('system', '会话创建失败，请稍后刷新重试。');
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
    var publishIntent = /(直接)?(生成|发布|上线|创建页面|开始生成|开始做)/.test(text);
    state.busy = true;
    addMessage('user', text);
    input.value = '';
    var ai = addMessage('assistant', '');
    fetch('/api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: state.sessionId, message: text })
    }).then(function (r) {
      return readSse(r, {
        delta: function (d) {
          ai.textContent += d.text || '';
          ai.textContent = ai.textContent.replace(/\s*\[READY\]\s*$/g, '');
          messages.scrollTop = messages.scrollHeight;
        },
        ready: function () {
          showGenerateCard('AI 已经判断信息足够，可以开始生成。');
        },
        notice: function (d) { addMessage('system', d.message || '系统提醒'); },
        error: function (d) { addMessage('system', d.message || 'AI 对话失败'); }
      });
    }).then(function () {
      maybeShowUploadCard(ai.textContent);
      if (publishIntent) showGenerateCard('你刚才提到了生成或发布，可以从这里确认生成。');
    }).finally(function () { state.busy = false; });
  }

  function publish(options) {
    if (!state.sessionId || state.busy) return;
    state.busy = true;
    state.lastUrl = '';
    var publishTerminal = false;
    addMessage('system', '开始生成页面，请保持当前窗口打开。');
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
        addMessage('system', '请先完成人机验证，再生成页面。');
        state.busy = false;
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
        is_adult: !!(options && options.isAdult)
      })
    }).then(function (r) {
      var generatedChars = 0;
      var lastProgressAt = 0;
      var progress = addMessage('system', 'AI 正在生成页面结构与样式...');
      return readSse(r, {
        stage: function (d) {
          progress.textContent = d.stage === 'writing' ? '正在写入页面文件...' : (d.stage === 'done' ? '页面写入完成。' : '阶段：' + d.stage);
        },
        delta: function (d) {
          generatedChars += (d.text || '').length;
          var now = Date.now();
          if (now - lastProgressAt > 800) {
            progress.textContent = 'AI 正在生成页面，已接收约 ' + generatedChars + ' 字符...';
            lastProgressAt = now;
          }
        },
        error: function (d) {
          publishTerminal = true;
          addMessage('system', d.message || '生成失败');
          if (window.turnstile) window.turnstile.reset();
        },
        result: function (d) {
          publishTerminal = true;
          state.lastUrl = d.url;
          addMessage('assistant', '你的页面已上线。要不要留个邮箱？以后可以用邮件里的链接修改这个页面。');
          renderDelivery(d.url);
          api('/api/auth/me.php', {}).then(function (me) { setUser(me.user); setQuota(me.quota); }).catch(function () {});
          if (window.turnstile) window.turnstile.reset();
        }
      });
    }).then(function () {
      if (!publishTerminal) addMessage('system', '生成连接已结束但没有收到页面地址。请再点一次生成；如果重复出现，说明模型输出超时。');
    }).catch(function () {
      addMessage('system', '生成连接中断。请稍后重试，或减少页面内容后再生成。');
      if (window.turnstile) window.turnstile.reset();
    }).finally(function () {
      state.busy = false;
      state.publishCard = null;
    });
  }

  function renderDelivery(url) {
    var card = addActionCard('delivery-card',
      '<div class="url-box">' + escapeHtml(url) + '</div>' +
      '<canvas class="qr-canvas" width="180" height="180"></canvas>' +
      '<div class="delivery-actions">' +
      '<button type="button" data-copy-url="1">复制链接</button>' +
      '<button type="button" data-download-qr="1">下载二维码</button>' +
      '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener">打开页面</a>' +
      '</div>' +
      '<div class="email-row"><input class="owner-email" type="email" placeholder="输入邮箱，获得后续修改链接"><button type="button" data-bind-email="1">发送修改链接</button></div>');
    card.dataset.pageUrl = url;
    drawPseudoQr(card.querySelector('.qr-canvas'), url);
  }

  function bindEmail(card) {
    var emailInput = card ? card.querySelector('.owner-email') : null;
    var email = emailInput ? emailInput.value.trim() : '';
    if (!email) return;
    api('/api/page-email.php', { session_id: state.sessionId, email: email }).then(function (r) {
      if (r.error) addMessage('system', r.error.message);
      else addMessage('assistant', '修改链接已经发送到 ' + email + '。');
    });
  }

  function toggleMyPages(open) {
    var panel = $('#myPagesPanel');
    panel.hidden = open === undefined ? !panel.hidden : !open;
    if (!panel.hidden) loadMyPages();
  }

  function loadMyPages() {
    var list = $('#myPagesList');
    if (!state.user) {
      list.innerHTML = '<span>登录后可以查看并修改你名下的页面。</span>';
      return;
    }
    list.innerHTML = '<span>读取中...</span>';
    api('/api/my-pages.php', {}).then(function (r) {
      if (r.error) {
        list.innerHTML = '<span>' + escapeHtml(r.error.message) + '</span>';
        return;
      }
      if (!r.pages || !r.pages.length) {
        list.innerHTML = '<span>你还没有登录后创建的页面。当前会话生成的新页面会自动归到这里。</span>';
        return;
      }
      list.innerHTML = r.pages.map(function (p) {
        var date = (p.updated_at || p.created_at || '').slice(0, 10);
        return '<div class="my-page-item" data-slug="' + escapeAttr(p.slug) + '">' +
          '<div class="my-page-main">' +
          '<div class="my-page-title">' + escapeHtml(p.title || p.slug) + '</div>' +
          '<div class="my-page-meta">' + escapeHtml((p.type || 'page') + ' · ' + date) + '</div>' +
          '</div>' +
          (p.is_adult ? '<span class="my-page-badge">18+</span>' : '<span></span>') +
          '<a href="' + escapeAttr(p.url) + '" target="_blank" rel="noopener">打开</a>' +
          '<button type="button" data-edit-slug="' + escapeAttr(p.slug) + '">修改</button>' +
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
      state.lastUrl = '';
      $('#messages').innerHTML = '';
      state.readyShown = false;
      state.publishCard = null;
      toggleMyPages(false);
      addMessage('assistant', '已进入修改模式。告诉我你想调整哪里，完成后点击生成会覆盖原页面。');
    });
  }

  function uploadImage(file, caption, card) {
    if (!state.sessionId || !file) return;
    var fd = new FormData();
    fd.append('session_id', state.sessionId);
    fd.append('caption', caption || '');
    fd.append('file', file);
    fetch('/api/upload.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { addMessage('system', d.error.message); return; }
      if (card) {
        card.dataset.active = '0';
        card.innerHTML = '<strong>图片已上传</strong><p>' + escapeHtml(caption || '无说明') + '</p><img src="' + escapeAttr(d.url) + '" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:12px">';
      }
      addMessage('system', '图片已上传并转换为 WebP。');
    });
  }

  function drawPseudoQr(canvas, text) {
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
      return;
    }
    var ctx = canvas.getContext('2d');
    var size = 29;
    var cell = canvas.width / size;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#000';
    function square(x, y, w) { ctx.fillRect(x * cell, y * cell, w * cell, w * cell); }
    [[1,1],[21,1],[1,21]].forEach(function (p) {
      square(p[0], p[1], 7); ctx.fillStyle = '#fff'; square(p[0]+1, p[1]+1, 5); ctx.fillStyle = '#000'; square(p[0]+2, p[1]+2, 3);
    });
    var seed = 0;
    for (var i = 0; i < text.length; i++) seed = (seed * 31 + text.charCodeAt(i)) >>> 0;
    for (var y = 0; y < size; y++) for (var x = 0; x < size; x++) {
      if ((x < 9 && y < 9) || (x > 19 && y < 9) || (x < 9 && y > 19)) continue;
      seed = (seed * 1664525 + 1013904223) >>> 0;
      if (seed % 3 === 0) ctx.fillRect(Math.floor(x * cell), Math.floor(y * cell), Math.ceil(cell), Math.ceil(cell));
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; });
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }

  $('#composer').addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value.trim());
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
      addMessage('system', '可以继续补充需求，准备好后再说“生成页面”。');
      return;
    }
    var delivery = e.target.closest('.delivery-card');
    if (!delivery) return;
    var url = delivery.dataset.pageUrl || state.lastUrl;
    if (e.target.closest('button[data-copy-url]')) {
      navigator.clipboard.writeText(url);
      addMessage('system', '链接已复制。');
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
    if (e.target.closest('button[data-bind-email]')) {
      bindEmail(delivery);
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
  $('#sendCodeBtn').addEventListener('click', function () {
    api('/api/auth/send-code.php', { email: $('#loginEmail').value.trim() }).then(function (r) {
      addMessage('system', r.error ? r.error.message : '验证码已发送。');
    });
  });
  $('#verifyCodeBtn').addEventListener('click', function () {
    api('/api/auth/verify.php', { email: $('#loginEmail').value.trim(), code: $('#loginCode').value.trim() }).then(function (r) {
      if (r.error) addMessage('system', r.error.message);
      else {
        addMessage('system', '已登录：' + r.user.email);
        $('#accountBox').hidden = true;
        api('/api/auth/me.php', {}).then(function (me) { setUser(me.user); setQuota(me.quota); });
      }
    });
  });

  start();
})();
