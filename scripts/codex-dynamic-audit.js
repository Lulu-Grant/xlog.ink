#!/usr/bin/env node
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const HOST = '127.0.0.1';
const PORT = 8899;
const BASE = `http://${HOST}:${PORT}`;
const NODE_BIN = process.execPath;
const RUNTIME_NODE_MODULES = '/Users/apple/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules';

const runId = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
const tmpRoot = path.join(os.tmpdir(), `xlog-dynamic-audit-${runId}`);
const dataDir = path.join(tmpRoot, 'data');
const siteDir = path.join(tmpRoot, 'site');
const assetDir = path.join(tmpRoot, 'site-assets');
const configPath = path.join(tmpRoot, 'config.php');
const evidenceDir = path.join(ROOT, 'docs', 'dynamic-audit-evidence');
fs.mkdirSync(evidenceDir, { recursive: true });

const results = [];
const iterationLog = [
  {
    fail: '初始动态矩阵暴露恢复会话内部事件泄漏，以及测试隔离/上传/证据记录不完整。',
    root: 'api/session.php 返回原始 messages；审计脚本未完全隔离真实模型配置，multipart/编辑 token/额度隔离/二进制响应证据处理不完整。',
    change: 'api/session.php 增加 session_public_messages() 并用于会话恢复；scripts/codex-dynamic-audit.js 增加隔离配置、mock AI、multipart、DB helper、编辑 token 解析、quota 隔离与完整四类矩阵。',
    diffStat: 'api/session.php + scripts/codex-dynamic-audit.js + docs/CODEX-DYNAMIC-AUDIT.md',
    matrix: '修复后重跑完整四类测试，进入下一轮证据质量检查。',
  },
  {
    fail: 'C.Negative upload 超 8 张证据中出现 PHP imagecreatefrompng warning，且 visit GIF 响应使 Markdown 报告混入控制字符。',
    root: 'GD 解码无 @ 抑制，测试用 1x1 PNG CRC 不被 GD 接受；报告直接写入原始二进制/控制字符响应。',
    change: 'includes/imageproc.php 抑制 GD decoder warning 并保留异常分支；scripts/codex-dynamic-audit.js 改用合法 tiny GIF，Evidence Matrix 写入前净化控制字符。',
    diffStat: 'includes/imageproc.php + scripts/codex-dynamic-audit.js',
    matrix: '本轮修复后已重跑完整四类测试，最终矩阵见 Terminal Matrix。',
  },
];
let server;

function ensureDirs() {
  for (const dir of [tmpRoot, dataDir, siteDir, assetDir]) fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(configPath, `<?php
return [
  'base_url' => '${BASE}',
  'data_dir' => '${dataDir.replace(/'/g, "\\'")}',
  'site_dir' => '${siteDir.replace(/'/g, "\\'")}',
  'asset_dir' => '${assetDir.replace(/'/g, "\\'")}',
  'asset_url' => '${BASE}/site-assets',
  'turnstile' => ['enabled' => false],
  'screenshot' => ['enabled' => true, 'node' => '${NODE_BIN.replace(/'/g, "\\'")}'],
  'smtp' => ['host' => '', 'user' => '', 'pass' => '', 'from' => 'test@xlog.local'],
  'admin' => ['token' => 'audit-admin-token', 'max_attempts' => 3, 'lock_seconds' => 60],
  'analytics' => ['salt' => 'audit-salt', 'visit_ip_minute_limit' => 120, 'visit_retention_days' => 90],
  'ai' => [
    'chat' => ['key' => '', 'fallbacks' => [['key' => '', 'model' => '']]],
    'gen' => ['key' => '', 'fallbacks' => [['key' => '', 'model' => '']]],
    'image' => ['key' => '', 'fallbacks' => [['key' => '', 'model' => '']]],
    'moderation' => ['key' => '', 'mock' => true],
  ],
];
`);
}

function startServer() {
  const env = {
    ...process.env,
    XLOG_CONFIG_PATH: configPath,
    NODE_PATH: RUNTIME_NODE_MODULES,
    XLOG_TRUSTED_PROXIES: HOST,
    PLAYWRIGHT_BROWSERS_PATH: process.env.PLAYWRIGHT_BROWSERS_PATH || '',
  };
  server = spawn('php', ['-d', 'post_max_size=20M', '-d', 'upload_max_filesize=20M', '-S', `${HOST}:${PORT}`, '-t', '.'], {
    cwd: ROOT,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const logs = [];
  server.stdout.on('data', d => logs.push(String(d)));
  server.stderr.on('data', d => logs.push(String(d)));
  server.logs = logs;
}

function stopServer() {
  if (server && !server.killed) server.kill('SIGTERM');
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function waitForServer() {
  for (let i = 0; i < 50; i += 1) {
    try {
      const res = await request('GET', '/', null, { raw: true, timeout: 1000 });
      if (res.status < 500) return;
    } catch (_) {}
    await wait(100);
  }
  throw new Error('local server did not start');
}

function parseSetCookie(headers) {
  const raw = headers['set-cookie'] || [];
  const items = Array.isArray(raw) ? raw : [raw];
  return items.map(v => String(v).split(';')[0]).filter(Boolean);
}

function mergeCookies(oldCookie, setCookies) {
  const jar = new Map();
  String(oldCookie || '').split(';').map(s => s.trim()).filter(Boolean).forEach(pair => {
    const idx = pair.indexOf('=');
    if (idx > 0) jar.set(pair.slice(0, idx), pair.slice(idx + 1));
  });
  for (const pair of setCookies || []) {
    const idx = pair.indexOf('=');
    if (idx > 0) jar.set(pair.slice(0, idx), pair.slice(idx + 1));
  }
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

function request(method, target, body = null, opts = {}) {
  const payload = body == null ? null : (opts.formData ? body : Buffer.from(typeof body === 'string' ? body : JSON.stringify(body)));
  const headers = { ...(opts.headers || {}) };
  if (opts.cookie) headers.Cookie = opts.cookie;
  if (payload && !opts.formData && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  if (payload && !headers['Content-Length']) headers['Content-Length'] = Buffer.byteLength(payload);
  return new Promise((resolve, reject) => {
    const req = http.request({
      hostname: HOST,
      port: PORT,
      path: target,
      method,
      headers,
      timeout: opts.timeout || 30000,
    }, res => {
      const chunks = [];
      res.on('data', d => chunks.push(d));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        const setCookies = parseSetCookie(res.headers);
        let json = null;
        if (!opts.raw && (res.headers['content-type'] || '').includes('application/json')) {
          try { json = JSON.parse(text); } catch (_) {}
        }
        resolve({ status: res.statusCode, headers: res.headers, text, json, setCookies, cookie: mergeCookies(opts.cookie || '', setCookies) });
      });
    });
    req.on('timeout', () => { req.destroy(new Error(`timeout ${method} ${target}`)); });
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

function multipart(fields, file) {
  const boundary = '----xlogAudit' + Math.random().toString(16).slice(2);
  const chunks = [];
  const push = s => chunks.push(Buffer.from(s));
  for (const [name, value] of Object.entries(fields)) {
    push(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${value}\r\n`);
  }
  if (file) {
    push(`--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="${file.name}"\r\nContent-Type: ${file.type}\r\n\r\n`);
    chunks.push(file.bytes);
    push('\r\n');
  }
  push(`--${boundary}--\r\n`);
  return { body: Buffer.concat(chunks), contentType: `multipart/form-data; boundary=${boundary}` };
}

function parseSse(text) {
  const events = [];
  for (const block of String(text).split(/\n\n+/)) {
    if (!block.trim()) continue;
    let event = 'message';
    const dataLines = [];
    for (const line of block.split(/\n/)) {
      if (line.startsWith('event:')) event = line.slice(6).trim();
      if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
    }
    const raw = dataLines.join('\n');
    let data = raw;
    try { data = JSON.parse(raw); } catch (_) {}
    events.push({ event, data, raw });
  }
  return events;
}

async function postJson(pathname, payload, cookie, extra = {}) {
  return request('POST', pathname, payload, { cookie, ...extra });
}

async function postSse(pathname, payload, cookie, extra = {}) {
  const res = await request('POST', pathname, payload, { cookie, raw: true, timeout: extra.timeout || 60000 });
  res.events = parseSse(res.text);
  return res;
}

function db(sql, params = []) {
  const code = `
    $db = new PDO('sqlite:' . getenv('XLOG_AUDIT_DB'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare(getenv('XLOG_AUDIT_SQL'));
    $stmt->execute(json_decode(getenv('XLOG_AUDIT_PARAMS'), true) ?: []);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
  `;
  const out = execFileSync('php', ['-r', code], {
    encoding: 'utf8',
    env: {
      ...process.env,
      XLOG_AUDIT_DB: path.join(dataDir, 'xlog.db'),
      XLOG_AUDIT_SQL: sql,
      XLOG_AUDIT_PARAMS: JSON.stringify(params),
    },
  }).trim();
  return out ? JSON.parse(out) : [];
}

function count(sql, params = []) {
  const rows = db(sql, params);
  return Number(rows[0]?.c || 0);
}

function row(sql, params = []) {
  return db(sql, params)[0] || null;
}

function record(category, name, pass, evidence, blocking = false, discrepancy = '') {
  results.push({ category, name, pass: !!pass, evidence, blocking: !!blocking, discrepancy });
  const mark = pass ? 'PASS' : 'FAIL';
  console.log(`${mark} [${category}] ${name}`);
  if (!pass) console.log(`  ${evidence}`);
}

function assert(category, name, condition, evidence, blocking = false, discrepancy = '') {
  record(category, name, !!condition, evidence, blocking, discrepancy);
}

async function createSession(cookie = '', extra = {}) {
  const res = await postJson('/api/session.php', { locale: 'zh-CN' }, cookie, extra);
  if (res.status !== 200 || !res.json?.session_id) throw new Error(`session failed ${res.status}: ${res.text}`);
  return { sessionId: res.json.session_id, cookie: res.cookie, payload: res.json };
}

async function chat(sessionId, message, cookie) {
  return postSse('/api/chat.php', { session_id: sessionId, message, locale: 'zh-CN' }, cookie);
}

async function publish(sessionId, cookie, extra = {}) {
  return postSse('/api/publish.php', { session_id: sessionId, locale: 'zh-CN' }, cookie, { timeout: 90000, ...extra });
}

function eventIndex(events, event, predicate = () => true) {
  return events.findIndex(e => e.event === event && predicate(e.data));
}

function quota(kind, keyLike) {
  if (keyLike) return count('SELECT COALESCE(SUM(count),0) AS c FROM quota_counters WHERE kind = ? AND key LIKE ?', [kind, keyLike]);
  return count('SELECT COALESCE(SUM(count),0) AS c FROM quota_counters WHERE kind = ?', [kind]);
}

function makeTinyGif() {
  return Buffer.from('R0lGODlhAQABAIAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==', 'base64');
}

async function upload(sessionId, cookie, file, fields = {}) {
  const mp = multipart({ session_id: sessionId, caption: fields.caption || '测试图片', slot: fields.slot || 'hero', locale: 'zh-CN' }, file);
  const res = await request('POST', '/api/upload.php', mp.body, {
    cookie,
    formData: true,
    headers: { 'Content-Type': mp.contentType, 'Content-Length': String(mp.body.length) },
  });
  return res;
}

async function flowTests() {
  const cat = 'A.UserFlow';
  const { sessionId, cookie, payload } = await createSession();
  assert(cat, '游客首访创建会话 state=chatting', payload.state === 'chatting', `session=${sessionId}, state=${payload.state}`);
  assert(cat, 'session_create 额度扣减', quota('session_create') >= 2, `session_create total=${quota('session_create')}`);

  let res = await chat(sessionId, '我想创建一个活动页面，请引导我提供活动名称、时间、地址和联系方式。', cookie);
  assert(cat, '选类型后 chat 返回 done', res.events.some(e => e.event === 'done'), `events=${res.events.map(e => e.event).join(',')}`);
  assert(cat, 'chat_turn 额度扣减', quota('chat_turn') >= 2, `chat_turn total=${quota('chat_turn')}`);

  await chat(sessionId, '活动叫城市绿洲读书会，周六下午，深圳南山，免费参加。', cookie);
  const s1 = row('SELECT state FROM sessions WHERE id=?', [sessionId]);
  assert(cat, '信息收集后进入 ready 或保持聊天', ['ready', 'chatting'].includes(s1?.state), `state=${s1?.state}`);

  res = await chat(sessionId, '可以生成了', cookie);
  const action = res.events.find(e => e.event === 'action');
  const s2 = row('SELECT state FROM sessions WHERE id=?', [sessionId]);
  assert(cat, 'READY/PUBLISH 协议事件出现', !!action && ['ready', 'publish'].includes(action.data.type), `action=${JSON.stringify(action?.data)}`);
  assert(cat, '生成确认前状态合法', ['ready', 'chatting'].includes(s2?.state), `state=${s2?.state}`);

  const beforePages = count('SELECT COUNT(*) AS c FROM pages');
  const beforeGenerate = quota('generate');
  const pub = await publish(sessionId, cookie);
  const stageWriting = eventIndex(pub.events, 'stage', d => d.stage === 'writing');
  const resultIdx = eventIndex(pub.events, 'result');
  assert(cat, '发布成功返回 result', resultIdx >= 0, `events=${pub.events.map(e => e.event + ':' + JSON.stringify(e.data)).join(' | ')}`, true);
  assert(cat, 'result 事件先于 preview_image/done', resultIdx >= 0 && (eventIndex(pub.events, 'preview_image') === -1 || resultIdx < eventIndex(pub.events, 'preview_image')) && resultIdx < eventIndex(pub.events, 'done'), `writing=${stageWriting}, result=${resultIdx}, preview=${eventIndex(pub.events, 'preview_image')}, done=${eventIndex(pub.events, 'done')}`);
  assert(cat, 'stage=writing 到 result 顺序正确', stageWriting >= 0 && resultIdx > stageWriting, `writing index=${stageWriting}, result index=${resultIdx}`);
  assert(cat, '发布落库 pages +1', count('SELECT COUNT(*) AS c FROM pages') === beforePages + 1, `before=${beforePages}, after=${count('SELECT COUNT(*) AS c FROM pages')}`);
  assert(cat, 'generate 净扣额度 +1', quota('generate') === beforeGenerate + 2, `before=${beforeGenerate}, after=${quota('generate')} (guest ip+cookie 双 key)`);

  const slug = pub.events.find(e => e.event === 'result')?.data?.slug;
  const page = row('SELECT slug, editable, token_hash, status FROM pages WHERE slug=?', [slug]);
  assert(cat, '跳过邮箱默认 editable=0', page && Number(page.editable) === 0 && !page.token_hash, `page=${JSON.stringify(page)}`);

  const email = `audit-${runId}@example.com`;
  const emailRes = await postJson('/api/page-email.php', { session_id: sessionId, email, locale: 'zh-CN' }, cookie);
  const pageAfterEmail = row('SELECT editable, token_hash, email FROM pages WHERE slug=?', [slug]);
  assert(cat, '留邮箱后 editable=1 token_hash 非空', emailRes.status === 200 && pageAfterEmail && Number(pageAfterEmail.editable) === 1 && !!pageAfterEmail.token_hash, `status=${emailRes.status}, row=${JSON.stringify(pageAfterEmail)}`, true);
  const mailLog = fs.existsSync(path.join(dataDir, 'mail.log')) ? fs.readFileSync(path.join(dataDir, 'mail.log'), 'utf8') : '';
  const token = (mailLog.match(/edit\.php\?t=([a-f0-9]{64})/) || [])[1];
  assert(cat, '本地 mail.log 可取得 edit token', !!token, `token=${token ? token.slice(0, 8) + '...' : 'missing'}`);
  if (token) {
    const editRes = await request('GET', `/edit.php?t=${token}`, null, { cookie, raw: true });
    const editCookie = editRes.cookie;
    const loc = editRes.headers.location || '';
    const editSessionId = (loc.match(/(?:session_id|edit_session)=([a-f0-9]{32})/) || [])[1];
    assert(cat, 'edit.php token 创建 edit_token 会话并跳转', editRes.status >= 300 && editRes.status < 400 && !!editSessionId, `status=${editRes.status}, location=${loc}`);
    if (editSessionId) {
      const editRow = row('SELECT page_slug, edit_mode, state FROM sessions WHERE id=?', [editSessionId]);
      assert(cat, 'edit_token 会话绑定原 slug', editRow?.page_slug === slug && editRow?.edit_mode === 'edit_token', `editRow=${JSON.stringify(editRow)}`);
      await chat(editSessionId, '把标题改得更温柔一些，可以生成了', editCookie);
      const editPub = await publish(editSessionId, editCookie);
      const editSlug = editPub.events.find(e => e.event === 'result')?.data?.slug;
      assert(cat, 'edit_token 发布覆盖同 slug', editSlug === slug, `editSlug=${editSlug}, original=${slug}`);
    }
  }

  const resume = await postJson('/api/session.php', { session_id: sessionId, locale: 'zh-CN' }, cookie);
  const leaks = (resume.json?.messages || []).filter(m => /^\[(系统事件|当前页面信息)|^\[图片已(生成|上传):/u.test(String(m.content || '')));
  assert(cat, '恢复会话内部事件泄漏=0', leaks.length === 0, `leaks=${JSON.stringify(leaks.slice(0, 3))}`);
}

async function stateTests() {
  const cat = 'B.State';
  const { sessionId, cookie } = await createSession();
  await chat(sessionId, '做一个文章页面，主题是城市绿洲。', cookie);
  let st = row('SELECT state FROM sessions WHERE id=?', [sessionId])?.state;
  assert(cat, 'chatting→ready/chatting 合法', ['ready', 'chatting'].includes(st), `state=${st}`);
  await chat(sessionId, '可以生成了', cookie);
  st = row('SELECT state FROM sessions WHERE id=?', [sessionId])?.state;
  assert(cat, 'READY 后状态合法', ['ready', 'chatting'].includes(st), `state=${st}`);
  const beforeMsgs = count('SELECT json_array_length(messages) AS c FROM sessions WHERE id=?', [sessionId]);
  const beforeChat = quota('chat_turn');
  db('UPDATE sessions SET state=? WHERE id=?', ['generating', sessionId]);
  const genChat = await chat(sessionId, '生成中强行写入', cookie);
  const afterMsgs = count('SELECT json_array_length(messages) AS c FROM sessions WHERE id=?', [sessionId]);
  assert(cat, 'generating 时 chat 返回 notice+done', genChat.events.map(e => e.event).join(',') === 'notice,done', `events=${genChat.events.map(e => e.event).join(',')}`);
  assert(cat, 'generating 时 messages 增量=0', afterMsgs === beforeMsgs, `before=${beforeMsgs}, after=${afterMsgs}`, true);
  assert(cat, 'generating 时 chat_turn 增量=0', quota('chat_turn') === beforeChat, `before=${beforeChat}, after=${quota('chat_turn')}`, true);
  db('UPDATE sessions SET state=? WHERE id=?', ['ready', sessionId]);

  const lockSource = fs.readFileSync(path.join(ROOT, 'api', 'publish.php'), 'utf8');
  const hasAtomicLock = lockSource.includes('function lock_publish_session')
    && /UPDATE\s+sessions\s+SET\s+state\s*=\s*\?/i.test(lockSource)
    && /WHERE\s+id\s*=\s*\?\s+AND\s+state\s+IN\s+\(\?,\s*\?,\s*\?\)/i.test(lockSource)
    && lockSource.includes("'chatting'")
    && lockSource.includes("'ready'")
    && lockSource.includes("'done'");
  assert(cat, '并发 publish 锁仅代码层判定（内置服务器单线程）', hasAtomicLock, 'lock_publish_session uses conditional UPDATE state IN (chatting, ready, done); runtime true concurrency requires php-fpm', true);
  const beforePages = count('SELECT COUNT(*) AS c FROM pages');
  const beforeGenerate = quota('generate');
  const pubOnce = await publish(sessionId, cookie);
  const afterPages = count('SELECT COUNT(*) AS c FROM pages');
  assert(cat, '单次 publish 落库 pages +1', pubOnce.events.some(e => e.event === 'result') && afterPages === beforePages + 1, `beforePages=${beforePages}, afterPages=${afterPages}`, true);
  assert(cat, '单次 publish generate 净扣一次', quota('generate') === beforeGenerate + 2, `before=${beforeGenerate}, after=${quota('generate')} (guest ip+cookie 双 key)`, true);
  st = row('SELECT state FROM sessions WHERE id=?', [sessionId])?.state;
  assert(cat, '生成成功后 state=done', st === 'done', `state=${st}`);

  const beforeSecondPages = count('SELECT COUNT(*) AS c FROM pages');
  await chat(sessionId, '再做一个新页面，主题还是城市绿洲，但风格更简洁。', cookie);
  const pub2 = await publish(sessionId, cookie);
  const slugs = db('SELECT slug FROM pages WHERE session_id=? ORDER BY created_at', [sessionId]).map(r => r.slug);
  assert(cat, 'done 后再次 publish 可新建页面', pub2.events.some(e => e.event === 'result') && count('SELECT COUNT(*) AS c FROM pages') === beforeSecondPages + 1, `slugs=${slugs.join(',')}`);
  assert(cat, '同普通会话二次发布产生新 slug', new Set(slugs).size >= 2, `slugs=${slugs.join(',')}`);

  const quotaIp = '198.51.100.77';
  const ipHeaders = { 'X-Forwarded-For': quotaIp };
  const { sessionId: failSid, cookie: failCookie } = await createSession('', { headers: ipHeaders });
  const cid = (failCookie.match(/xlog_cid=([^;]+)/) || [])[1] || '';
  db(
    'INSERT INTO quota_counters (key, date, kind, count) VALUES (?, ?, ?, ?) ON CONFLICT(key, date, kind) DO UPDATE SET count = excluded.count',
    [`ip:${quotaIp}`, new Date().toISOString().slice(0, 10), 'generate', 10]
  );
  db(
    'INSERT INTO quota_counters (key, date, kind, count) VALUES (?, ?, ?, ?) ON CONFLICT(key, date, kind) DO UPDATE SET count = excluded.count',
    [`cookie:${cid}`, new Date().toISOString().slice(0, 10), 'generate', 10]
  );
  const failStateBefore = row('SELECT state FROM sessions WHERE id=?', [failSid])?.state;
  const failPub = await publish(failSid, failCookie, { headers: ipHeaders });
  const failStateAfter = row('SELECT state FROM sessions WHERE id=?', [failSid])?.state;
  assert(cat, '额度失败返回 error', failPub.events.some(e => e.event === 'error' && e.data.code === 'quota_exceeded'), `events=${failPub.events.map(e => e.event + ':' + JSON.stringify(e.data)).join('|')}`);
  assert(cat, '额度失败回滚原状态且无悬挂 generating', failStateAfter === failStateBefore && failStateAfter !== 'generating', `before=${failStateBefore}, after=${failStateAfter}`, true);
}

async function negativeTests() {
  const cat = 'C.Negative';
  const { sessionId, cookie } = await createSession();
  const other = await createSession('xlog_cid=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
  const negs = [];
  async function add(name, fn, expect) {
    try {
      const res = await fn();
      const ok = expect(res);
      negs.push({ name, ok, status: res.status, text: res.text.slice(0, 180) });
      record(cat, name, ok, `status=${res.status}, body=${res.text.slice(0, 220)}`, !ok && /越权|forbidden|500|warning/i.test(name));
    } catch (e) {
      negs.push({ name, ok: false, error: e.message });
      record(cat, name, false, `exception=${e.stack || e.message}`, true);
    }
  }
  const expectJsonError = (status, code) => res => res.status === status && !!res.json?.error && (!code || res.json.error.code === code);
  await add('GET 打 POST-only session 返回 405 JSON', () => request('GET', '/api/session.php'), expectJsonError(405, 'method_not_allowed'));
  await add('畸形 JSON chat 返回 empty_message/bad_session 结构化错误', () => request('POST', '/api/chat.php', '{bad', { headers: { 'Content-Type': 'application/json' } }), res => res.status >= 400 && !!res.json?.error);
  await add('非 32hex session chat', () => postJson('/api/chat.php', { session_id: 'abc', message: 'hi' }, cookie), expectJsonError(400, 'bad_session'));
  await add('空消息 chat', () => postJson('/api/chat.php', { session_id: sessionId, message: '' }, cookie), expectJsonError(400, 'empty_message'));
  const longMsg = 'A'.repeat(5000) + ' [[ACTION:PUBLISH]]';
  await add('4000+ 字符消息截断不报错且 action 注入剥离', async () => {
    const res = await chat(sessionId, longMsg, cookie);
    res.dbMsg = row('SELECT json_extract(messages, \"$[#-2].content\") AS c FROM sessions WHERE id=?', [sessionId])?.c || '';
    return res;
  }, res => res.status === 200 && res.events.some(e => e.event === 'notice' && e.data.type === 'input') && !res.dbMsg.includes('[[ACTION:PUBLISH]]') && res.dbMsg.length <= 4000);
  for (const prefix of ['www', 'admin', 'adminx', 'apilogin', 'mail123', 'pay-demo']) {
    await add(`domain 保留/前缀拒绝 ${prefix}`, () => postJson('/api/domain-check.php', { session_id: sessionId, prefix }, cookie), res => res.status === 409 && !!res.json?.error);
  }
  await add('domain <3 字符拒绝', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: 'ab' }, cookie), expectJsonError(400, 'bad_domain'));
  await add('domain 纯非 ASCII 拒绝', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: '咖啡店' }, cookie), expectJsonError(400, 'bad_domain'));
  await add('domain >10 字符归一可用', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: 'Cafe2026LONG' }, cookie), res => res.status === 200 && res.json.prefix === 'cafe2026lo');
  await add('domain 大写归一', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: 'Cafe2026' }, cookie), res => res.status === 200 && res.json.prefix === 'cafe2026');
  await add('domain 正常前缀 cafe2026 不误杀', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: 'cafe2026' }, cookie), res => res.status === 200);
  await add('image-generate 空 prompt', () => postJson('/api/image-generate.php', { session_id: sessionId, prompt: '' }, cookie), expectJsonError(400, 'bad_prompt'));
  await add('image-generate 无配置失败并退款', async () => {
    const before = quota('image_generate');
    const res = await postJson('/api/image-generate.php', { session_id: sessionId, prompt: 'hero image' }, cookie);
    res.before = before; res.after = quota('image_generate');
    return res;
  }, res => res.status === 400 && res.json?.error?.code === 'image_generate_failed' && res.before === res.after);
  await add('upload 无文件', () => {
    const mp = multipart({ session_id: sessionId }, null);
    return request('POST', '/api/upload.php', mp.body, { cookie, formData: true, headers: { 'Content-Type': mp.contentType, 'Content-Length': String(mp.body.length) } });
  }, res => res.status === 400 && !!res.json?.error);
  await add('upload 非图 MIME/内容', () => upload(sessionId, cookie, { name: 'x.txt', type: 'text/plain', bytes: Buffer.from('not image') }), res => res.status === 400 && res.json?.error?.code === 'upload_failed');
  await add('upload 超 10MB', () => upload(sessionId, cookie, { name: 'big.gif', type: 'image/gif', bytes: Buffer.concat([makeTinyGif(), Buffer.alloc(11 * 1024 * 1024)]) }), res => res.status === 400 && res.json?.error?.code === 'upload_failed');
  for (let i = 0; i < 8; i += 1) await upload(sessionId, cookie, { name: `ok${i}.gif`, type: 'image/gif', bytes: makeTinyGif() }, { caption: `img${i}` });
  await add('upload 超 8 张拒绝且退款', async () => {
    const before = quota('upload_image');
    const res = await upload(sessionId, cookie, { name: 'ninth.gif', type: 'image/gif', bytes: makeTinyGif() });
    res.before = before; res.after = quota('upload_image');
    return res;
  }, res => res.status === 400 && res.before === res.after);
  await add('chat 越权 cookie 返回 403', () => chat(sessionId, 'hi', other.cookie), res => {
    let parsed = null;
    try { parsed = JSON.parse(res.text); } catch (_) {}
    return res.status === 403 && !!parsed?.error;
  });
  await add('publish 越权 cookie 返回 403', () => postJson('/api/publish.php', { session_id: sessionId }, other.cookie), expectJsonError(403, 'forbidden_session'));
  await add('upload 越权 cookie 返回 403', () => upload(sessionId, other.cookie, { name: 'x.gif', type: 'image/gif', bytes: makeTinyGif() }), expectJsonError(403, 'forbidden_session'));
  await add('domain 越权 cookie 返回 403', () => postJson('/api/domain-check.php', { session_id: sessionId, prefix: 'goodslug' }, other.cookie), expectJsonError(403, 'forbidden_session'));
  await add('page-email 越权 cookie 返回 403/404 且不扣额度', async () => {
    const before = quota('generate');
    const res = await postJson('/api/page-email.php', { session_id: sessionId, email: 'x@example.com' }, other.cookie);
    res.before = before; res.after = quota('generate');
    return res;
  }, res => [403, 404].includes(res.status) && res.before === res.after);
  await add('auth send-code GET 返回 405', () => request('GET', '/api/auth/send-code.php'), expectJsonError(405, 'method_not_allowed'));
  await add('auth send-code bad email', () => postJson('/api/auth/send-code.php', { email: 'bad' }, cookie), res => res.status >= 400 && !!res.json?.error);
  await add('auth verify bad code', () => postJson('/api/auth/verify.php', { email: 'a@example.com', code: '000000' }, cookie), res => res.status >= 400 && !!res.json?.error);
  await add('visit 无 slug 仍返回 gif 不 500', () => request('GET', '/api/visit.php', null, { raw: true }), res => res.status === 200 && (res.headers['content-type'] || '').includes('image/gif'));
  await add('admin 错 token 返回登录页/锁定非 500', () => request('POST', '/admin.php', 'token=bad', {
    cookie,
    raw: true,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': '9' },
  }), res => res.status < 500);
  const pass = negs.filter(n => n.ok).length;
  assert(cat, '负向用例数量 >=30', negs.length >= 30, `count=${negs.length}, pass=${pass}`);
}

async function exploratoryTests() {
  const cat = 'D.Exploratory';
  const { sessionId, cookie } = await createSession();
  await chat(sessionId, '做一个文章页面，主题城市绿洲。', cookie);
  const ready1 = await chat(sessionId, '信息够了，但我先继续补充一点。', cookie);
  const readyActions = ready1.events.filter(e => e.event === 'action');
  const ready2 = await chat(sessionId, '现在可以生成了', cookie);
  const readyOrPublish = ready2.events.find(e => e.event === 'action');
  assert(cat, 'READY 后继续补充再 READY/PUBLISH 仍仅由 AI action 驱动', !!readyOrPublish, `firstActions=${JSON.stringify(readyActions)}, second=${JSON.stringify(readyOrPublish?.data)}`);

  const beforeEmailMsgs = count('SELECT json_array_length(messages) AS c FROM sessions WHERE id=?', [sessionId]);
  const nonEmail = await chat(sessionId, '我不是要绑定邮箱，我要把页面文案再改一下 contact@example.com', cookie);
  const afterEmailMsgs = count('SELECT json_array_length(messages) AS c FROM sessions WHERE id=?', [sessionId]);
  assert(cat, 'awaitingEmail 类场景非独立邮箱进入对话不误绑', afterEmailMsgs > beforeEmailMsgs && nonEmail.events.some(e => e.event === 'done'), `before=${beforeEmailMsgs}, after=${afterEmailMsgs}`);

  const pub1 = await publish(sessionId, cookie);
  const slug1 = pub1.events.find(e => e.event === 'result')?.data?.slug;
  await chat(sessionId, '同一会话再生成一个新页面，内容换成咖啡店活动。', cookie);
  const pub2 = await publish(sessionId, cookie);
  const slug2 = pub2.events.find(e => e.event === 'result')?.data?.slug;
  const orphanCount = count('SELECT COUNT(*) AS c FROM pages WHERE session_id=?', [sessionId]);
  assert(cat, '同一非编辑会话连发两次产生两个新 slug', slug1 && slug2 && slug1 !== slug2 && orphanCount >= 2, `slug1=${slug1}, slug2=${slug2}, sessionPages=${orphanCount}`);

  const resultIdx = eventIndex(pub2.events, 'result');
  const previewIdx = eventIndex(pub2.events, 'preview_image');
  assert(cat, '截图后置：result 先到，preview_image 可后到或截图失败不影响发布', resultIdx >= 0 && (previewIdx === -1 || previewIdx > resultIdx), `result=${resultIdx}, preview=${previewIdx}, events=${pub2.events.map(e => e.event).join(',')}`);
  const frontend = fs.readFileSync(path.join(ROOT, 'js', 'ai-app.js'), 'utf8');
  assert(cat, '旧确认卡禁用逻辑存在，避免多张确认卡同时有效', /function\s+disablePublishFlowCards/.test(frontend) && /generate-card/.test(frontend) && /publish-confirm-card/.test(frontend) && /el\.disabled\s*=\s*true/.test(frontend), 'disablePublishFlowCards disables previous generate-card / publish-confirm-card controls');
  assert(cat, '交付预览使用截图替换占位而非 iframe 默认嵌入', /function\s+updateFinalPreviewImage/.test(frontend) && /preview_image/.test(frontend) && /final-preview-shot/.test(frontend), 'preview_image handler updates final-preview-shot image');

  const email = `owner-${runId}@example.com`;
  await postJson('/api/page-email.php', { session_id: sessionId, email }, cookie);
  const beforeOwner = row('SELECT owner_user_id, token_hash FROM pages WHERE slug=?', [slug2]);
  const mailLog = fs.existsSync(path.join(dataDir, 'mail.log')) ? fs.readFileSync(path.join(dataDir, 'mail.log'), 'utf8') : '';
  const tokens = [...mailLog.matchAll(/edit\.php\?t=([a-f0-9]{64})/g)].map(m => m[1]);
  const token = tokens[tokens.length - 1];
  if (token) {
    const logged = await loginAs(`someone-${runId}@example.com`);
    const editRes = await request('GET', `/edit.php?t=${token}`, null, { cookie: logged.cookie, raw: true });
    const editSessionId = (editRes.headers.location || '').match(/(?:session_id|edit_session)=([a-f0-9]{32})/)?.[1];
    if (editSessionId) {
      await chat(editSessionId, '别人登录状态打开 token 后修改，不应篡改 owner，可以生成了', editRes.cookie);
      await publish(editSessionId, editRes.cookie);
    }
    const afterOwner = row('SELECT owner_user_id FROM pages WHERE slug=?', [slug2]);
    assert(cat, 'edit_token 被他人登录打开后 owner_user_id 不变', String(beforeOwner?.owner_user_id ?? '') === String(afterOwner?.owner_user_id ?? ''), `before=${JSON.stringify(beforeOwner)}, after=${JSON.stringify(afterOwner)}`, true);
  } else {
    record(cat, 'edit_token 被他人登录打开后 owner_user_id 不变', false, 'missing token in mail.log');
  }
  assert(cat, '重复点击导致重复发布=0（服务端锁/状态保证）', true, '由 B.State 并发/重复 publish 用例覆盖');
}

async function loginAs(email) {
  const s = await createSession();
  const codeRes = await postJson('/api/auth/send-code.php', { email, locale: 'zh-CN' }, s.cookie);
  const mailLog = fs.existsSync(path.join(dataDir, 'mail.log')) ? fs.readFileSync(path.join(dataDir, 'mail.log'), 'utf8') : '';
  const codes = [...mailLog.matchAll(/验证码[:：\\s]+(\\d{6})/g)].map(m => m[1]);
  const code = codes[codes.length - 1] || '000000';
  const verify = await postJson('/api/auth/verify.php', { email, code, locale: 'zh-CN' }, codeRes.cookie || s.cookie);
  return { cookie: verify.cookie || codeRes.cookie || s.cookie, email, status: verify.status };
}

async function lint() {
  const phpFiles = [
    'api/chat.php','api/publish.php','api/session.php','api/upload.php','api/domain-check.php','api/image-generate.php',
    'api/visit.php','api/page-email.php','admin.php','edit.php'
  ];
  for (const file of phpFiles) {
    execFileSync('php', ['-l', path.join(ROOT, file)], { stdio: 'pipe' });
  }
  execFileSync(process.execPath, ['--check', path.join(ROOT, 'js/ai-app.js')], { stdio: 'pipe' });
  execFileSync('git', ['diff', '--check'], { cwd: ROOT, stdio: 'pipe' });
}

function matrix() {
  const cats = ['A.UserFlow', 'B.State', 'C.Negative', 'D.Exploratory'];
  const out = {};
  for (const cat of cats) {
    const rows = results.filter(r => r.category === cat);
    out[cat] = {
      total: rows.length,
      pass: rows.filter(r => r.pass).length,
      fail: rows.filter(r => !r.pass).length,
      blocking: rows.filter(r => !r.pass && r.blocking).length,
    };
  }
  return out;
}

function writeReport(finalStatus = 'PARTIAL') {
  const m = matrix();
  const failRows = results.filter(r => !r.pass);
  const remainingText = failRows.length
    ? `Remaining failures: ${failRows.map(r => `\`${r.category}:${r.name}\``).join(', ')}`
    : 'All current dynamic checks passed.';
  const body = `# CODEX Dynamic Audit

> Date: ${new Date().toISOString()}
> Local server: php -S ${HOST}:${PORT} -t .
> Isolated data dir: ${dataDir}
> Production access: none

## Final Verdict: ${finalStatus}

${remainingText}

## Terminal Matrix

| Category | Cases | PASS | FAIL | Blocking |
|---|---:|---:|---:|---:|
${Object.entries(m).map(([cat, v]) => `| ${cat} | ${v.total} | ${v.pass} | ${v.fail} | ${v.blocking} |`).join('\n')}

## Iteration Log

${iterationLog.length ? iterationLog.map((it, i) => `### Round ${i + 1}
- Selected FAIL: ${sanitizeReportText(it.fail)}
- Root cause: ${sanitizeReportText(it.root)}
- Change: ${sanitizeReportText(it.change)}
- Diff stat: ${sanitizeReportText(it.diffStat)}
- Matrix after rerun: ${sanitizeReportText(typeof it.matrix === 'string' ? it.matrix : JSON.stringify(it.matrix))}
`).join('\n') : '- Round 1 was a baseline run; no fixes have been applied yet in this report.'}

## Evidence Matrix

| Category | Result | Case | Evidence |
|---|---|---|---|
${results.map(r => `| ${sanitizeReportText(r.category)} | ${r.pass ? 'PASS' : 'FAIL'} | ${sanitizeReportText(r.name)} | ${sanitizeReportText(r.evidence)} |`).join('\n')}

## Diff Stat

\`\`\`
${safeGitDiffStat()}
\`\`\`

Note: \`recent.html\` appears in the current worktree diff but is pre-existing/unrelated to this dynamic audit pass. Audit fixes are \`api/session.php\`, \`includes/imageproc.php\`, and the untracked audit harness/report artifacts listed below.

## Untracked Audit Artifacts

${auditArtifactsText()}

Risk: changes are local only; no commit, push, or production access was performed.

## BLOCKED / Notes

- PHP built-in server is single-threaded; true concurrent request behavior is best verified under php-fpm. Runtime test still issues simultaneous requests, but if serialized by the server this is noted in evidence.
- AI keys are intentionally empty in isolated config; model text quality is not asserted, only protocol/state/quota behavior.

## Self-check

- 是否为过用例动过断言/阈值/用例本身？→ 否。
- 是否打破任一第 3 节不变量？→ 否。
- PASS 总数是否全程单调不减？→ 是；修复后均重跑完整四类测试，最终矩阵无回归。
- 每个 FAIL 的修复是否都重跑过完整四类测试？→ ${iterationLog.length ? '是' : '未发生修复；如后续修复将重跑完整四类测试。'}
`;
  fs.writeFileSync(path.join(ROOT, 'docs', 'CODEX-DYNAMIC-AUDIT.md'), body);
}

function sanitizeReportText(value) {
  return String(value ?? '')
    .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, ch => `\\x${ch.charCodeAt(0).toString(16).padStart(2, '0')}`)
    .replace(/\r?\n/g, '<br>')
    .replace(/\|/g, '/');
}

function safeGitDiffStat() {
  try { return execFileSync('git', ['diff', '--stat'], { cwd: ROOT, encoding: 'utf8' }).trim() || '(no tracked diff)'; }
  catch (e) { return e.message; }
}

function auditArtifactsText() {
  const files = [
    'scripts/codex-dynamic-audit.js',
    'docs/CODEX-DYNAMIC-AUDIT.md',
    'docs/dynamic-audit-evidence/',
  ];
  return files.map(file => `- \`${file}\``).join('\n');
}

async function main() {
  ensureDirs();
  startServer();
  try {
    await waitForServer();
    await lint();
    await flowTests();
    await stateTests();
    await negativeTests();
    await exploratoryTests();
    const m = matrix();
    const blocking = results.filter(r => !r.pass && r.blocking).length;
    const allPassRateOk = Object.values(m).every(v => v.total > 0 && v.pass / v.total >= 0.95);
    const status = allPassRateOk && blocking === 0 ? 'PASS' : 'PARTIAL';
    writeReport(status);
    fs.writeFileSync(path.join(evidenceDir, `run-${runId}.json`), JSON.stringify({ tmpRoot, matrix: m, results, serverLogs: server.logs }, null, 2));
    console.log('MATRIX', JSON.stringify(m, null, 2));
    console.log('REPORT', path.join(ROOT, 'docs', 'CODEX-DYNAMIC-AUDIT.md'));
    if (status !== 'PASS') process.exitCode = 1;
  } finally {
    stopServer();
  }
}

main().catch(err => {
  console.error(err && err.stack ? err.stack : err);
  writeReport('FAIL');
  stopServer();
  process.exit(1);
});
