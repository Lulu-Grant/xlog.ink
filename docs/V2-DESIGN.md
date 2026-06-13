# xlog.ink V2 — AI 驱动个人页面生成平台

> 设计定稿文档 · 2026-06-11
> 本文档是 V2 重建的唯一依据，包含：产品最终形态、系统架构、开发文档、实现方式与实施计划。
> ⚠️ 文中所有凭据均为占位符，真实凭据只存在于服务器 webroot 之外的配置文件中，严禁写入仓库。

---

## 第一部分 · 产品最终形态

### 1.1 一句话定位

用户打开网站即进入一个 AI 聊天界面，通过自然对话描述需求、上传图片，AI 生成一个高度自由的 HTML 页面，自动发布到独立二级域名 `{slug}.xlog.ink`，并通过邮箱 token 获得后续修改能力。

### 1.2 完整用户旅程（游客首次使用）

```
打开 xlog.ink
   │
   ▼
① 聊天界面（即首页）
   AI 主动开场：「你想创建什么类型的页面？」
   下方展示预置选项卡片：[名片] [宣传海报] [文章页面] [活动页面] [自由描述]
   —— 点击卡片 = 发送一条用户消息，本质只是提示词，无任何强制表单
   │
   ▼
② AI 引导收集（真实 AI 对话，gemma 驱动）
   AI 根据类型自然追问：活动名称、电话、地址、风格偏好……
   用户答不答、答得对不对都不拦截 —— 一切都是提示词
   │
   ▼
③ 文案与图片
   AI 提示：粘贴现成文案，或描述内容要求由 AI 代写
   聊天框侧边有图片上传按钮：
     · 每张图片可填一句说明（说明文字同样进入提示词）
     · 上传即时被后端转为 webp（最长边 1600px / 质量 80 / 去 EXIF）
     · 转换后的 URL + 说明注入对话上下文
   │
   ▼
④ 生成触发（双路径）
   路径 A：AI 判断信息足够，回复末尾输出 [[ACTION:READY ...]]
           → 后端剥离标记并发 action 事件，前端亮出「生成页面」按钮
   路径 B：用户任何时候都可点「直接生成」
   生成前过 Turnstile + 额度检查
   │
   ▼
⑤ 生成（Qwen/Qwen3.6-35B-A3B 驱动，gpt-5.4 备用）
   后端将整段对话历史 + 图片清单交给生成模型单次调用
   流式生成完整自由 HTML → 校验 → 落盘 /site/{slug}.html
   │
   ▼
⑥ 交付卡片（AI 以聊天消息形式回复）
   ┌─────────────────────────────┐
   │ 🎉 你的页面已上线              │
   │ https://k3x8a92mf1.xlog.ink  │
   │ [复制链接]  [下载二维码]       │
   │ ▣ 二维码预览（前端本地生成）    │
   └─────────────────────────────┘
   │
   ▼
⑦ 邮箱环节（AI 顺势询问）
   「要不要留个邮箱？以后可以随时修改这个页面」
   · 留邮箱 → 生成 edit token → 发送修改链接邮件
   · 不留   → 页面标记永久不可修改
```

### 1.3 三种用户形态

| 形态 | 每日生成额度 | 修改权 | 计数方式 | 说明 |
|---|---|---|---|---|
| **游客** | 10 页/天 | ❌ | IP + 浏览器 cookie 双计 | Turnstile 必过 |
| **游客 + 邮箱** | 同游客（共享 IP 额度） | ✅ 仅限自己留过邮箱的页面 | 同游客 | 邮箱只换 edit token，不提额 |
| **注册用户** | 50 页/天 | ✅ 名下所有页面 | 按 user_id，不受 IP 影响 | 预留积分制 / 充值接口 |

**AI 引导登录机制**（不是弹窗轰炸，是对话式导购）：

- 后端是裁判：每次生成前查额度。额度耗尽时，后端向对话注入系统事件，gemma 用自然语言告知「今天的游客额度用完了，登录后每天可生成 50 个页面」，前端同步弹出登录入口。
- AI 是导购：gemma 的 system prompt 中携带用户当前身份与剩余额度，在合适时机（如第 2、3 次生成后）顺势提及登录好处，但不纠缠。

**登录方式**：邮箱验证码登录（passwordless）。无密码存储、无找回流程，注册即登录一步完成。

### 1.4 修改回路（最终形态）

1. 用户收到邮件：`https://xlog.ink/edit.php?t={token}`
2. token 校验通过 → 加载该页面的历史会话 + 当前 HTML → 进入聊天界面
3. AI 开场：「这是你的『XX』页面，想改哪里？」
4. 对话式修改 → 重新生成 → 覆盖原文件（slug 不变，URL 不变）
5. token 永久有效，仅与该页面绑定；可凭邮箱 + slug 重发邮件

### 1.5 积分制（本期预留，不开收费）

- `users.credits` 字段 + `credit_transactions` 流水表 + `orders` 充值订单表本期建好
- 所有生成动作统一经过 `consume_quota()`，当前按每日次数扣减；未来 SaaS 化只需将扣减逻辑切换到积分，调用方代码不动
- `api/pay/` 目录占位，对接支付渠道时再实现

---

## 第二部分 · 系统架构

### 2.1 技术栈

| 层 | 选型 | 理由 |
|---|---|---|
| 前端 | 原生 JS 聊天 SPA（无框架） | 与现有项目风格一致，体积小 |
| 后端 | PHP 8.x | 现有服务器 / Nginx / 部署链路全部就绪 |
| 数据 | SQLite | 单机够用，零运维；从 pages.jsonl 迁移 |
| 对话模型 | `google/gemma-4-E4B-it`，备用 `gpt-5.4-mini` | 便宜，承担引导与收集；备用保障可用性 |
| 生成模型 | `Qwen/Qwen3.6-35B-A3B`，备用 `gpt-5.4` | 单次大输出，生成自由 HTML |
| 模型网关 | `https://api.3s3.org` | 两个模型各用独立 API key |
| 图片 | PHP GD（有 Imagick 则优先） | webp 转换 |
| 邮件 | PHPMailer + 阿里云 DirectMail SMTPS | 验证码 / 修改链接 / 未来通知 |
| 人机验证 | Cloudflare Turnstile（沿用） | 生成动作前必过 |

### 2.2 架构图

```
浏览器（聊天 SPA）
   │ SSE 流式
   ▼
┌─ PHP 后端 ──────────────────────────────────────────┐
│ api/chat.php      会话代理（gemma / gpt-5.4-mini，流式转发）│
│ api/upload.php    图片上传 → webp                    │
│ api/publish.php   生成流水线（Qwen / gpt-5.4）→ 落盘 → 分配 slug│
│ api/auth/*.php    验证码登录                          │
│ edit.php          token 鉴权修改入口                  │
│ includes/ai.php   多模型适配层（anthropic/openai 双格式）│
│ includes/db.php   SQLite                             │
│ includes/quota.php 三级额度 consume_quota()           │
│ includes/mailer.php PHPMailer                        │
│ includes/imageproc.php webp 流水线                    │
└──────────────────────────────────────────────────────┘
   │                          │
   ▼                          ▼
/site/{slug}.html      api.3s3.org
/site-assets/{slug}/     ├ /v1/chat/completions (Qwen / gpt-5.4)
   │                     └ /v1/chat/completions (gemma / gpt-5.4-mini)
   ▼
Nginx 通配符 *.xlog.ink → slug 映射（沿用现有机制）
```

### 2.3 双模型路由（includes/ai.php）

适配层按用途路由，配置驱动：

```php
// /etc/xlog/config.php（webroot 外）
'ai' => [
    'base_url' => 'https://api.3s3.org',
    'chat' => [
        'model'  => 'google/gemma-4-E4B-it',
        'format' => 'openai',
        'key'    => '<CHAT_API_KEY>',
        'max_tokens' => 1024,        // 每轮上限，省 token
        'fallbacks' => [[
            'base_url' => 'https://api.3s3.org',
            'model' => 'gpt-5.4-mini',
            'format' => 'openai',
            'key' => '<CHAT_FALLBACK_API_KEY>',
            'max_tokens' => 1024,
        ]],
    ],
    'gen' => [
        'model'  => 'Qwen/Qwen3.6-35B-A3B',
        'format' => 'openai',
        'key'    => '<GEN_API_KEY>',
        'max_tokens' => 16384,       // 必须流式接收
        'stream' => true,
        'fallbacks' => [[
            'base_url' => 'https://api.3s3.org',
            'model' => 'gpt-5.4',
            'format' => 'openai',
            'key' => '<GEN_FALLBACK_API_KEY>',
            'max_tokens' => 16384,
            'stream' => true,
        ]],
    ],
],
```

适配层职责：

- 统一内部消息格式 `[{role, content}]`，按 format 转换为 Anthropic（`/v1/messages`，header `x-api-key` + `anthropic-version: 2023-06-01`）或 OpenAI（`/v1/chat/completions`，header `Authorization: Bearer`）两种线格式
- 统一流式接口：curl `CURLOPT_WRITEFUNCTION` 解析 SSE，回调逐块吐 token；两种格式的 delta 事件都归一化为纯文本块
- 统一非流式接口（用于辅助性短调用）
- 错误归一化：限流 / 超时 / 网关错误转为统一错误码，前端可读

**弱模型设计约束（重要）**：gemma 26B 的 tool-calling 不可靠，因此对话阶段**不使用工具调用**。UI 唤起采用 `[[ACTION:TYPE k=v]]` 内联动作标记协议 + 用户手动按钮双路径（见 §5.2）。

### 2.4 目录结构

```
/index.php              聊天 SPA 入口（新首页）
/edit.php               token 鉴权修改入口
/api/
   chat.php             SSE 对话代理（gemma）
   upload.php           图片上传转 webp
   publish.php          生成流水线（sonnet）
   session.php          会话创建/恢复
   auth/
      send-code.php     发送登录验证码
      verify.php        校验验证码、建立登录态
      logout.php
   pay/                 （占位，预留充值）
/includes/
   ai.php               多模型适配层
   db.php               SQLite 封装（PDO）
   quota.php            三级额度
   mailer.php           PHPMailer 封装
   imageproc.php        webp 流水线
   helpers.php          （沿用）
   ratelimit.php        （沿用，作为 IP 兜底层）
   turnstile.php        （沿用）
   i18n.php             （沿用）
   response.php         （沿用）
/prompts/
   chat-system.txt      对话阶段 system prompt
   gen-system.txt       生成阶段 system prompt
/site/                  生成页落盘（沿用）
/site-assets/{slug}/    每页图片资源
/data/
   xlog.db              SQLite
/assets/ /css/ /partials/   前端资源（沿用 tokens.css 设计体系）
/scripts/
   migrate-jsonl.php    pages.jsonl 一次性迁移
/docs/
   V2-DESIGN.md         本文档

退役文件：creat.php、creat-article.php、generate.php、generate-article.php、index.html
保留参考：site-samples/（风格回归对照）
```

---

## 第三部分 · 数据库设计（SQLite DDL）

```sql
-- 用户
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    created_at    TEXT NOT NULL,            -- ISO8601 UTC
    daily_quota   INTEGER NOT NULL DEFAULT 50,
    credits       INTEGER NOT NULL DEFAULT 0,   -- 积分预留
    status        TEXT NOT NULL DEFAULT 'active' -- active | banned
);

-- 登录验证码
CREATE TABLE login_codes (
    email         TEXT NOT NULL,
    code_hash     TEXT NOT NULL,            -- sha256(code + salt)
    expires_at    TEXT NOT NULL,            -- 5 分钟
    attempts      INTEGER NOT NULL DEFAULT 0,   -- ≥5 次作废
    created_at    TEXT NOT NULL
);
CREATE INDEX idx_login_codes_email ON login_codes(email);

-- 页面
CREATE TABLE pages (
    slug            TEXT PRIMARY KEY,        -- 10 位 [a-z0-9]
    title           TEXT NOT NULL,
    type            TEXT NOT NULL,           -- card | poster | article | event | free
    lang            TEXT NOT NULL DEFAULT 'zh-CN',
    created_at      TEXT NOT NULL,
    updated_at      TEXT,
    owner_user_id   INTEGER,                 -- 注册用户创建时关联
    email           TEXT,                    -- 游客留邮箱
    editable        INTEGER NOT NULL DEFAULT 0,
    token_hash      TEXT,                    -- sha256(edit_token)，只存哈希
    is_adult        INTEGER NOT NULL DEFAULT 0,
    status          TEXT NOT NULL DEFAULT 'live',  -- live | removed
    cost_tokens     INTEGER DEFAULT 0        -- 生成消耗记录（成本观测）
);

-- 会话（对话状态）
CREATE TABLE sessions (
    id            TEXT PRIMARY KEY,          -- random 32 hex
    user_id       INTEGER,
    page_slug     TEXT,                      -- 修改模式时关联
    messages      TEXT NOT NULL DEFAULT '[]',-- JSON [{role, content, ts}]
    state         TEXT NOT NULL DEFAULT 'chatting', -- chatting | ready | generating | done
    ip            TEXT NOT NULL,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

-- 图片
CREATE TABLE images (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id    TEXT NOT NULL,
    slug          TEXT,                      -- 发布后回填
    path          TEXT NOT NULL,             -- /site-assets/...
    caption       TEXT DEFAULT '',           -- 用户填写的说明（提示词）
    width         INTEGER, height INTEGER,
    created_at    TEXT NOT NULL
);

-- 额度计数（按天）
CREATE TABLE quota_counters (
    key           TEXT NOT NULL,             -- 'ip:1.2.3.4' | 'user:42' | 'cookie:xxx'
    date          TEXT NOT NULL,             -- 'YYYY-MM-DD' (UTC)
    kind          TEXT NOT NULL,             -- 'generate' | 'chat_turns'
    count         INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, date, kind)
);

-- 积分流水（预留）
CREATE TABLE credit_transactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    delta         INTEGER NOT NULL,          -- 正充负耗
    reason        TEXT NOT NULL,             -- recharge | generate | refund | admin
    ref           TEXT,                      -- 订单号 / slug
    created_at    TEXT NOT NULL
);

-- 充值订单（预留）
CREATE TABLE orders (
    id            TEXT PRIMARY KEY,          -- 订单号
    user_id       INTEGER NOT NULL,
    amount_cents  INTEGER NOT NULL,
    credits       INTEGER NOT NULL,
    status        TEXT NOT NULL DEFAULT 'pending', -- pending | paid | failed | refunded
    pay_channel   TEXT,
    created_at    TEXT NOT NULL,
    paid_at       TEXT
);
```

迁移：`scripts/migrate-jsonl.php` 将 `data/pages.jsonl` 逐行导入 `pages`（editable=0、token_hash=NULL）。

---

## 第四部分 · API 设计

所有 API 返回 JSON（流式接口除外），统一错误体 `{"error": {"code": "...", "message": "..."}}`。
登录态：PHP session cookie（HttpOnly + Secure + SameSite=Lax）。

### 4.1 会话

```
POST /api/session.php          新建会话
  → { session_id, greeting }   greeting = AI 开场白（可缓存固定文案首条，省一次调用）

POST /api/session.php?resume=1 恢复会话（edit 模式由 edit.php 内部建立）
```

### 4.2 对话（核心）

```
POST /api/chat.php             Content-Type: application/json
  { session_id, message }      用户消息（预置卡片点击也走这里）
  ← SSE 流：
     event: delta   data: {"text": "..."}        增量 token
     event: action  data: {"type":"ready",...}    后端检测到动作标记，前端分发 UI
     event: notice  data: {"type":"quota", ...}   系统事件（额度提醒等）
     event: done    data: {"usage": {...}}
```

后端职责：追加用户消息 → 组装 gemma 请求（system prompt + 截断后的历史）→ 流式尾部缓冲 → 剥离 `[[ACTION:...]]` 标记存库 → 发 `action` SSE；若 type=ready 则置 `state=ready`。

对话轮次限制：游客每 IP 每日 200 轮（`quota_counters kind=chat_turns`），超限返回 `notice` 并由 AI 话术引导。

### 4.3 图片

```
POST /api/upload.php           multipart/form-data
  { session_id, file, caption }
  → { id, url, width, height }

限制：单张 ≤ 10MB；每会话 ≤ 8 张；类型 jpg/png/webp/gif(取首帧)
处理：最长边 1600px、webp q80、剥 EXIF → /site-assets/tmp/{session_id}/{n}.webp
上下文注入：上传成功后向 messages 追加一条 user 消息：
  「[图片#2 已上传: https://xlog.ink/site-assets/.../2.webp] 说明: 活动主视觉海报」
```

### 4.4 生成

```
POST /api/publish.php
  { session_id, turnstile_token }
  ← SSE 流：
     event: stage   data: {"stage": "generating" | "writing" | "done"}
     event: delta   data: {"text": "..."}        （可选：转发生成进度片段做动效）
     event: result  data: { "url": "https://{slug}.xlog.ink/",
                            "slug": "...", "qr_payload": "..." }
     event: error   data: {"code": "...", ...}

流程：
 1. Turnstile 校验 → consume_quota() → 失败即返回（额度/人机）
 2. 组装 sonnet 请求：gen-system.txt + 完整对话历史 + 图片清单
 3. 流式接收，拼出 ```html 代码块
 4. 校验：完整 <!DOCTYPE html> 文档；img src 仅允许 xlog.ink 域；无外链 script
 5. 注入 footer / 统计 → 写 /site/{slug}.html
 6. 移动图片 tmp/{session} → {slug}/，改写 HTML 内 URL
 7. 写 pages 记录、回填 images.slug、记 cost_tokens
 8. 会话 state=done，返回 result
```

### 4.5 邮箱与修改

```
POST /api/page-email.php       游客为页面绑定邮箱
  { session_id, email }
  → 生成 edit_token = bin2hex(random_bytes(32))
    pages.token_hash = sha256(token)，editable=1，email 记录
    发邮件：https://xlog.ink/edit.php?t={token}
  → { ok: true }

GET  /edit.php?t={token}       修改入口
  校验 sha256(t) → 命中 pages → 新建 edit 模式会话
  （messages 预载：原始会话历史摘要 + 当前 HTML + AI 开场「想改哪里？」）
  → 渲染聊天界面，后续走 chat/publish 同一链路，publish 时覆盖原 slug

POST /api/resend-edit.php      重发修改邮件（email + slug 匹配，限频）
```

### 4.6 登录

```
POST /api/auth/send-code.php   { email }       → 发 6 位验证码（5 分钟，限频 1次/分钟）
POST /api/auth/verify.php      { email, code } → 校验 → users 不存在则创建 → 建立 session
POST /api/auth/logout.php
GET  /api/auth/me.php          → { user: {email, daily_quota, used_today, credits} | null }
```

---

## 第五部分 · AI 设计

### 5.1 对话阶段 system prompt（prompts/chat-system.txt 框架）

```
你是 xlog.ink 的页面创建助手。你的任务是通过自然对话引导用户完成一个网页的需求收集。

【流程】
1. 用户会先选择或描述页面类型（名片/宣传海报/文章页/活动页/自由）。
2. 根据类型追问必要信息（如活动页：活动名、时间、地点、联系方式），
   但用户拒答或乱答也接受 —— 这些只是生成提示词，不做校验。
3. 引导用户提供文案（粘贴现成的，或说出要求由生成环节代写），
   并提醒可以上传图片、为每张图写说明。
4. 当你的回复需要唤起 UI 组件时，在回复最末尾单独一行输出动作标记：
   - 上传图片：[[ACTION:UPLOAD slot=hero hint=活动主视觉]]
   - 信息足够生成：[[ACTION:READY reason=核心信息已完整]]
   - 引导留邮箱：[[ACTION:EMAIL]]
   标记只作为 UI 信号，不向用户解释。

【身份与额度】（由系统每轮注入最新值）
当前用户：{guest|user}，今日剩余生成额度：{n}
- 若用户是游客且已多次生成，可自然地提一句「登录后每天可生成 50 个页面」，不纠缠。
- 若系统注入了额度耗尽事件，用友好的语言告知并引导登录。

【边界】
- 你只负责收集需求，不输出 HTML，不承诺具体视觉效果细节。
- 拒绝协助违法、侵权内容；色情擦边内容提醒将标记为成人页面。
- 回复使用用户的语言，简洁、一次只问 1-2 个问题。
```

实现细节：

- 身份/额度行作为 system prompt 末尾动态段注入（每轮更新）。固定部分在前、动态部分在后，给将来切换支持 prompt caching 的模型留好前缀稳定性。
- 历史截断：超过 30 轮时保留「前 2 轮 + 最近 20 轮」，中间替换为一行摘要占位。
- `[[ACTION:...]]` 协议：后端在流转发时保留尾部缓冲，完成后剥离标记并发 `event: action`，前端按 `type` 分发 UI，用户永远不看到标记原文。

### 5.2 生成触发为何不用 tool-calling

gemma-4-26B 是小模型，函数调用的可靠性不足以承担流程控制。流程控制权完全在后端：

- AI 输出 `[[ACTION:READY ...]]` → 仅仅是前端亮按钮的信号
- 真正的生成必须由用户点击按钮 → `POST /api/publish.php` → 后端裁决（Turnstile、额度）
- 即使 AI 永远不输出 `READY` 动作，用户也始终有「直接生成」按钮兜底

### 5.3 生成阶段 system prompt（prompts/gen-system.txt 框架）

```
你是一位顶尖的网页设计师与前端工程师。根据下面的对话记录，
为用户生成一个完整的单文件 HTML 页面。

【硬性要求】
- 输出一个完整的 HTML 文档，包裹在 ```html 代码块中，除此之外不输出任何内容。
- 所有 CSS 内联在 <style> 中；可以使用内联 <script> 做轻量交互。
- 禁止引用任何外部资源（外链 JS/CSS/字体/图片），
  唯一例外：对话中给出的 https://xlog.ink/site-assets/ 图片 URL。
- 移动优先、响应式；语义化标签；<head> 含 title/description/og 标签。
- 图片使用对话中提供的 URL，按说明文字安排位置与用途。

【设计要求】
- 充分发挥创造力，根据页面类型与用户描述选择气质相符的版式、配色、字体层级。
- 避免千篇一律的模板感；细节（间距、阴影、微交互）要讲究。
- 用户没给的内容可以合理代写占位文案，但联系方式等事实信息不得编造，
  没提供就不展示。

【安全】
- 若需求涉及违法内容，仅输出 <!-- REFUSED: 原因 --> 注释，不输出页面。
```

后端对 `REFUSED` 注释做检测，转为友好的对话提示，不扣额度。

### 5.4 Token 成本控制汇总

| 环节 | 措施 |
|---|---|
| 对话 | gemma（便宜）；每轮 max_tokens 1024；历史截断；每 IP 每日 200 轮 |
| 生成 | 每 IP/用户 每日次数额度；单次 max_tokens 上限；REFUSED 不扣额度 |
| 观测 | pages.cost_tokens 记录每页消耗，后台可统计单页成本 |

---

## 第六部分 · 图片流水线（includes/imageproc.php）

1. 校验：MIME 白名单（jpeg/png/webp/gif）、≤10MB、像素炸弹防护（`getimagesize` 先查尺寸，> 8000×8000 拒绝）
2. 解码：GD `imagecreatefrom*`（gif 取首帧）；EXIF orientation 先转正
3. 缩放：最长边 > 1600px 等比缩小
4. 输出：`imagewebp($im, $path, 80)`；文件名 `1.webp, 2.webp...` 按会话内序号
5. 存储：会话期 `/site-assets/tmp/{session_id}/`，发布时 `rename()` 到 `/site-assets/{slug}/` 并同步改写 HTML 中 URL
6. 清理：cron 每日删除 48h 前未发布的 tmp 目录

URL 策略：生成 HTML 中统一使用主域绝对路径 `https://xlog.ink/site-assets/{slug}/n.webp`，子域页面无需额外 Nginx 规则即可加载。

---

## 第七部分 · 子域名分发与页面服务

沿用现有机制：通配符 DNS `*.xlog.ink` → Nginx 按 host 提取 slug → 映射 `/site/{slug}.html`。

V2 增补（Nginx，对 `*.xlog.ink` 虚拟主机统一下发）：

```nginx
# 生成页 CSP：允许自身内联，禁止一切第三方外联
add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src https://xlog.ink data:; font-src data:; base-uri 'none'; form-action 'none'; frame-ancestors *" always;
```

安全模型：每个生成页在独立子域，与主站及其他页面 cookie 隔离；CSP 阻断外联与表单提交，AI 生成的内联 script 只能做本页动效，无法外传数据。

二维码：纯前端实现（qrcode 生成库本地打包进 assets，不走 CDN），对 `https://{slug}.xlog.ink/` 生成，canvas 转 PNG 下载。

---

## 第八部分 · 额度体系（includes/quota.php）

```php
// 唯一入口，所有生成动作必须经过
function consume_quota(string $kind /* generate|chat_turn */): array
// 返回 ['ok'=>bool, 'remaining'=>int, 'identity'=>'guest|user', 'reason'=>?string]
```

判定顺序：

1. 已登录 → `key = user:{id}`，限额 `users.daily_quota`（默认 50）
2. 游客 → 同时检查 `ip:{ip}` 与 `cookie:{cid}` 两个 key（cid 为首访发放的随机 cookie），任一达到 10 即拒；两个计数都 +1
3. SQLite 事务内 `INSERT ... ON CONFLICT DO UPDATE count=count+1` 原子递增
4. 日期按 UTC 切换

积分预留：`consume_quota` 内部留一个分支桩 —— `if (CREDIT_MODE) { ... 扣 credits 并写 credit_transactions ... }`，当前恒为 false。

现有 `includes/ratelimit.php` 保留作为更底层的瞬时频率兜底（防脚本爆刷），与每日额度互补。

---

## 第九部分 · 邮件模块（includes/mailer.php）

配置（`/etc/xlog/config.php`，占位示例）：

```php
'smtp' => [
    'host'   => 'smtpdm-ap-southeast-1.aliyun.com',
    'port'   => 465,
    'secure' => 'ssl',
    'user'   => 'vip@glsmail.xlog.ink',
    'pass'   => '<SMTP_PASSWORD>',          // 真实值仅存服务器配置
    'from'   => 'vip@glsmail.xlog.ink',
    'from_name' => 'xlog.ink',
],
```

三类模板，统一 `send_mail($to, $template, $vars)`：

| 模板 | 用途 | 限频 |
|---|---|---|
| `edit-link` | 页面修改链接 | 同一 email+slug 1 次/10 分钟 |
| `login-code` | 登录验证码 | 同一 email 1 次/分钟，10 次/天 |
| `notice` | 预留（订单等） | — |

注意事项：阿里云 DirectMail 需在控制台完成 `glsmail.xlog.ink` 发信域名的 SPF/DKIM/DMARC 验证，否则进垃圾箱；PHPMailer 用 SMTPS（465 隐式 TLS）。

---

## 第十部分 · 安全清单

| # | 项 | 措施 |
|---|---|---|
| 1 | 凭据管理 | 所有 key/密码存 `/etc/xlog/config.php`（webroot 外，0600，php-fpm 用户可读）；**当前两把 AI key 与 SMTP 密码已在沟通中明文出现过，上线前全部轮换** |
| 2 | API key 不出后端 | 浏览器只与本站 API 通信 |
| 3 | 提示词注入 | 流程控制（能否生成、扣额）全在后端状态机；AI 输出仅作 UI 信号 |
| 4 | 生成页 XSS/外联 | 独立子域隔离 + CSP 白名单 + 落盘前校验（无外链 script、img 域白名单） |
| 5 | 上传安全 | MIME 白名单、尺寸限制、重编码为 webp（天然消毒）、目录不可执行 PHP（Nginx 规则） |
| 6 | token 安全 | edit token 与登录 cookie 均只存哈希/服务端态；token 256-bit 随机 |
| 7 | 验证码爆破 | 5 次错误作废、发送限频、hash 存储 |
| 8 | 滥用 | Turnstile + 每日额度 + 对话轮次限制 + 瞬时限流四层 |
| 9 | 内容合规 | 生成 prompt 内置拒绝;沿用 adult 标记/确认门机制 |
| 10 | 注入类 | PDO 预处理；输出一律 `h()` 转义（生成 HTML 除外，它有自己的校验链） |

---

## 第十一部分 · 实施计划

| 里程碑 | 内容 | 验收标准 |
|---|---|---|
| **M1 地基** | 配置外置；SQLite 建表 + db.php；ai.php 适配层；**实测 api.3s3.org 上 gemma 与 sonnet 的端点格式与流式行为**；migrate-jsonl 脚本 | CLI 脚本能分别流式调通两个模型；旧数据进库 |
| **M2 聊天** | 聊天 SPA（消息流、预置卡片、SSE 渲染）；session.php / chat.php；chat-system.txt 调教；`ACTION` 动作标记；轮次限制 | 浏览器内与 gemma 完整对话，按钮按协议出现 |
| **M3 图片** | upload.php + imageproc.php；前端上传组件 + 说明输入；上下文注入；tmp 清理 cron | 上传任意 jpg/png 得到合规 webp URL，AI 能在对话中引用 |
| **M4 生成交付** | publish.php 全流水线；gen-system.txt；HTML 校验链；落盘 + 资产迁移；交付卡片（复制 + 本地二维码） | 端到端：对话 → 生成 → 子域可访问 → 二维码可下载 |
| **M5 用户体系** | 验证码登录全链路；quota.php 三级额度；AI 身份/额度注入与引导话术；me 接口与前端登录态 | 游客 10/天、用户 50/天准确执行；额度耗尽时 AI 正确引导 |
| **M6 修改回路** | page-email.php；mailer.php；edit.php；edit 模式会话与覆盖发布 | 留邮箱 → 收到邮件 → 链接进入 → 对话修改 → 原 URL 内容更新 |
| **M7 收尾** | CSP 上线；adult 流程接回；recent 页适配新库；成本报表 SQL；积分桩自检；压测与上线 checklist | 全安全清单逐项核对通过 |

依赖关系：M1 → M2 → M3/M4 可并行 → M5 → M6 → M7。

---

## 第十二部分 · 已确认的决策记录

| 决策 | 结论 |
|---|---|
| 后端技术栈 | 继续 PHP，复用现有部署 |
| 对话模型 | google/gemma-4-E4B-it，备用 gpt-5.4-mini（省 token + 可用性兜底） |
| 生成模型 | Qwen/Qwen3.6-35B-A3B，备用 gpt-5.4，`/v1/chat/completions`，流式 |
| 对话阶段工具调用 | 不用；会话模型输出内联动作标记 `[[ACTION:TYPE k=v]]` + 手动按钮双路径 |
| 独立前置路由模型 | 不引入；避免每轮延迟叠加、成本倒挂和判断权错位 |
| UI 唤起机制 | 语义路由由会话模型打 `UPLOAD/READY/EMAIL` 标记；确定性路由由前端事件直接响应 |
| 标记剥离责任 | 后端流尾缓冲并剥离动作标记，前端只接收干净正文与 `action` SSE |
| 生成前是否让弱模型总结简报 | 不总结，完整对话直接交给生成模型（避免丢信息） |
| 登录方式 | 邮箱验证码（passwordless） |
| 游客/用户额度 | 10 / 50 页每天；邮箱用户=游客待遇+修改权 |
| 积分与充值 | 本期只建表与桩，不开收费 |
| edit token | 永久有效，绑定单页，只存哈希 |
| 新入口 | 聊天界面直接作为新首页；旧 creat 系列下线 |
| 数据库 | SQLite，jsonl 一次性迁移 |
| 二维码 | 前端本地生成，不依赖外部服务 |
| 图片 URL | 主域绝对路径 `https://xlog.ink/site-assets/{slug}/` |
