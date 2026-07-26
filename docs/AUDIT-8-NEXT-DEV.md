# 第八轮审计整改 — 下一步开发文档

> 依据：`docs/AUDIT-8-2026-07-26.md`（2026-07-26 综合复审）  
> 范围：生产 Nginx/TLS 上线阻断、发布 slug 原子性、Turnstile 与历史页风险、用户状态、密钥/迁移/消毒器、仓库可复现  
> 状态：可执行规格；按 Sprint A → D 排期  
> 相关：`AUDIT-8-2026-07-26.md`、`AUDIT-7-NEXT-DEV.md`（应用层多数已闭环）、`nginx-v2-snippet.conf`、`PAY-RUNBOOK.md`

---

## 1. 背景与目标

### 1.1 审计总判

| 维度 | 结论 |
|---|---|
| 应用层（AUDIT-7 A–D 主项） | **多数已修复并上生产**（认领、支付 soft-delete/金额 fail-closed、测试隔离、新发布 sanitize+CSP meta、mock 门闩等） |
| 生产运维 | **不满足上线闭环**：partials 源码泄漏、主站/通配符未拆、HTTP 未强制 HTTPS |
| 代码残留 | 普通发布 slug **TOCTOU**；停用用户可登录；sanitizer 仍 denylist；密钥明文 SQLite；无真迁移 |
| 仓库 | 本地/生产核心一致，但 **GitHub `main` 未含 61 个目标文件**；生产不可从仓库复现 |

### 1.2 本阶段目标

1. **立刻堵住 partials PHP 源码下载**（生产 Nginx，小时级）  
2. **拆分 apex / `*.xlog.ink` server**，wildcard 严 CSP + 路径白名单  
3. **强制 HTTPS**，禁用 TLS 1.1，HSTS 评估后加强  
4. **普通创建禁止误 UPDATE**；slug 事务预留 + 临时文件再 rename  
5. ~~**生产开启 Turnstile**~~（**2026-07-26 用户决定：暂不开启，放弃本阻断项**）  
6. **用户 status 一致拒绝**；密钥外置 / 迁移 baseline / DOM allowlist（分期）  
7. **修复后形成可复现提交并推送 GitHub**（在 P0/P1 代码项完成之后；**push 需另批**）

### 1.3 非目标（本期不做）

- 换框架 / 换 Postgres / 多租户 RBAC  
- 退款 API、发票、订阅、完整内容人审台  
- 无测试保障的大重构（`ai-app.js` / `pay.php` 全量拆分仅 Sprint D 渐进）  
- 批量重写全部历史 HTML 文件内容（以 Nginx CSP + 风险扫描隔离为主）  
- 本轮不要求在生产打真实支付费用；Turnstile 用真实 key 做发布链路验收即可

### 1.4 问题索引（审计 → 开发）

| 审计 ID | 级别 | 主题 | Sprint |
|---|---|---|---|
| **P0-1** | P0 | `/partials/` 暴露 PHP 源码 | **A**（立即） |
| **P0-2** | P0 | 主站与通配符共用 server；无响应头 CSP | **A** |
| **P0-3** | P0 | HTTP 不跳 HTTPS；TLS 1.1 仍开 | **A** |
| **P1-1** | P1 | 发布 slug TOCTOU → 跨用户覆盖 | **B** |
| **P1-2** | P1 | 生产 Turnstile 关闭 | **已放弃（用户 2026-07-26）** |
| **P1-3** | P1 | 61 文件仅暂存，GitHub 不可复现 | **B 末 / 全量后** |
| **P1-4** | P1 | 历史页风险扫描未在生产执行 | **B** |
| P2-1 | P2 | 停用用户仍可登录 | **C** |
| P2-2 | P2 | 支付密钥明文 SQLite | **C** |
| P2-3 | P2 | `schema_migrations` 空壳、无外键 | **C** |
| P2-4 | P2 | sanitizer 仍是 denylist | **C** |
| P3-1 | P3 | 动态审计写死本机路径 | **D** |
| P3-2 | P3 | 核心文件体积继续增长 | **D** |
| P3-3 | P3 | 缺 php-fpm 并发回归 | **D** |
| P3-4 | P3 | staged Markdown trailing whitespace | **B 提交前** |

---

## 2. 里程碑总览

```text
Sprint A（运维阻断）  partials 封堵 + apex/wildcard 拆分 + HTTPS/TLS     0.5–1.5 天  ★ 立刻
Sprint B（应用 P1）   发布 slug 原子性 + Turnstile + 风险扫描 + 提交准备  1.5–3 天
Sprint C（加固）      用户 status + 密钥外置 + 迁移 baseline + DOM 消毒   2–4 天
Sprint D（卫生）      路径可移植、模块渐进拆、fpm 并发、文档归档         可后置
```

每个 Sprint：**实现清单 + 验收用例 + 回归命令**。  
**放量 / 对外宣称安全闭环门槛：Sprint A 全部验收通过**。  
~~扩大 AI 发布流量门槛：A + B（含 Turnstile）~~ → **Turnstile 已由用户放弃作为阻断项**（2026-07-26）；其余 B 项仍建议完成。

### 2.1 与 AUDIT-7 的关系

| AUDIT-7 项 | AUDIT-8 状态 | 本轮是否再做 |
|---|---|---|
| 会话认领 `session_access_allowed` | 已修复 | 否（回归保留） |
| 支付渠道软停用 / 金额 fail-closed | 已修复 | 否 |
| 测试库隔离 | 已修复 | 否（新测继续隔离） |
| 新发布 sanitize + CSP meta | 部分（历史页/响应头未闭环） | **P0-2 + P2-4** 补齐 |
| 主站/通配符 Nginx 拆分 | 文档有，**生产未应用** | **P0-2** |
| mock / HTTPS cookie 辅助 | 已修代码侧 | 配合 P0-3 |

---

## 3. Sprint A — 生产运维阻断（必做，优先于代码大改）

> 操作面：生产 `5.189.149.76`、Cloudflare、`/www/server/panel/vhost/nginx/`。  
> 本地仓库同步：`docs/nginx-v2-snippet.conf`、`docs/nginx-www-redirect.conf`、`docs/nginx-cloudflare-real-ip.conf`。

### 3.1 P0-1 立即关闭 `/partials/` 源码访问

**根因：** 生产存在

```nginx
location ^~ /partials/ {
    try_files $uri =404;
}
```

`^~` 阻止进入 PHP 处理器 → `.php` 当静态文件下载。实测 `GET /partials/admin/users.php` → `200` + 源码正文。

**改动（生产 Nginx，建议先改 conf 再 `nginx -t && reload`）：**

1. **删除** 整段公开 `/partials/` 的 `try_files` 规则。  
2. **至少**增加：

```nginx
# Admin partials: PHP only via require; never HTTP-served.
location ^~ /partials/admin/ {
    return 404;
}

# Do not serve any .php under /partials/ as static files.
location ^~ /partials/ {
    location ~ \.php$ {
        return 404;
    }
    # If legacy pages need footer HTML only, allow specific names:
    # location = /partials/footer.html { try_files $uri =404; }
    # Default: no directory listing; optional blanket 404 for entire tree:
    return 404;
}
```

3. 确认代码路径：后台仅 `require`/`include` partials，**无浏览器 fetch `/partials/*.php`**。  
4. 若确认无任何 HTTP 依赖，**整目录 404** 最安全。

**文档同步：** 将上述片段并入 `docs/nginx-v2-snippet.conf`「Shared path protections」一节。

**验收：**

| 用例 | 期望 |
|---|---|
| `curl -sI https://xlog.ink/partials/admin/users.php` | **404**（非 200） |
| `curl -s https://xlog.ink/partials/admin/users.php \| head -c 20` | **不含** `<?php` |
| 抽样 `partials/admin/*.php` 全部 | 404 |
| 主站 `/admin.php` 登录后各 Tab | 仍正常（服务端 include） |
| 主站首页 / AI 页 / 我的抽屉 | 无 404 依赖 partials |

**回滚：** 保留 conf 备份；reload 失败则恢复备份。partials 封堵**无应用回滚依赖**。

---

### 3.2 P0-2 拆分 apex / wildcard server + 严格 CSP

**根因：** `server_name xlog.ink *.xlog.ink` 共用一块；子域可打主站 API；生成页无 Nginx CSP；历史 HTML 含 script/外链。

**目标架构（与 `nginx-v2-snippet.conf` 对齐）：**

```text
server A: xlog.ink www.xlog.ink
  - 完整应用：index / admin / api / 上传 / Turnstile 所需脚本
  - 无「生成页专用」严 CSP（避免打断主站）
  - 共享 deny：data/includes/prompts/scripts、partials、dotfiles

server B: ~^(?<sub_slug>...)\.xlog\.ink$
  - 仅生成页：location = / → try_files /site/$sub_slug.html =404
  - 必要静态：/site-assets/（禁 PHP）
  - 禁止：/api、/admin.php、/partials、源码目录
  - 统一响应头：
    Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';
      script-src 'none'; img-src https://xlog.ink data:; font-src data:;
      base-uri 'none'; form-action 'none'; frame-ancestors *
```

**实现步骤：**

1. 在面板/服务器备份当前 `xlog.ink.conf`。  
2. 按 snippet 拆成两个 `server {}`（证书同一份 SAN：apex + `*.xlog.ink`）。  
3. wildcard **白名单路径**；其余 `return 404`。  
4. `nginx -t` → reload。  
5. 历史页**不依赖逐文件改写**：靠响应头 CSP 第二层防护。  
6. 新发布链路已有 meta CSP，与响应头并存可接受（以更严者为准或保持一致）。

**验收：**

| 用例 | 期望 |
|---|---|
| `https://{任意slug}.xlog.ink/api/auth/me.php` | **404** 或非主站 JSON |
| `https://{slug}.xlog.ink/` 响应头 | 含 `script-src 'none'`（或等价严 CSP） |
| `https://xlog.ink/` | Turnstile / 主站脚本仍可用 |
| `https://xlog.ink/api/auth/me.php` | 正常 JSON |
| 生成页 `<script>` 历史内容 | 浏览器不执行（CSP） |
| `curl -sI https://xlog.ink/data/xlog.db` | 404（既有保护不回退） |

---

### 3.3 P0-3 强制 HTTPS + 禁 TLS 1.1

**根因：** `http://xlog.ink/` 实测 200；`ssl_protocols` 含 `TLSv1.1`。

**改动：**

1. **Cloudflare：** Always Use HTTPS = On；建议 Automatic HTTPS Rewrites。  
2. **源站 80：** 独立 server，除 ACME `/.well-known/acme-challenge/` 外全部：

```nginx
return 301 https://$host$request_uri;
```

3. **SSL：** `ssl_protocols TLSv1.2 TLSv1.3;`（删除 1.1）。  
4. **HSTS：** 主站可先：

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

`includeSubDomains` **仅在确认所有子域 HTTPS 正常后**再加。

5. 应用侧：继续使用 `request_is_https()`（AUDIT-7 已做）发 Secure cookie。

**验收：**

| 用例 | 期望 |
|---|---|
| `curl -sI http://xlog.ink/` | **301/308** → `https://...` |
| `curl -sI http://www.xlog.ink/` | 跳转 HTTPS（及 www→apex 策略一致） |
| SSLLabs / `openssl s_client` | 无 TLS 1.1 |
| 登录后 cookie | 经 HTTPS 带 Secure（反代场景） |

---

### 3.4 Sprint A 完成定义

- [ ] 所有 `partials/admin/*.php` HTTP 404，响应体无 PHP 源码  
- [ ] apex 与 wildcard 分 server；子域不可达 `/api`、`/admin.php`  
- [ ] wildcard 带严 CSP 响应头  
- [ ] HTTP → HTTPS；无 TLS 1.1  
- [ ] 主站 AI 页、登录、后台 Tab 冒烟通过  
- [ ] conf 变更记入运维笔记 / 更新 `docs/nginx-*.conf` 片段

**回归（生产冒烟）：**

```bash
# 从可信网络执行
curl -sI "https://xlog.ink/partials/admin/users.php" | head -n 5
curl -sI "http://xlog.ink/" | head -n 10
curl -sI "https://xlog.ink/" | head -n 15
# 替换为真实存在的 slug
curl -sI "https://<slug>.xlog.ink/" | grep -i content-security-policy
curl -sI "https://<slug>.xlog.ink/api/auth/me.php" | head -n 5
```

---

## 4. Sprint B — 应用 P1（发布 / 人机验证 / 风险 / 可复现）

### 4.1 P1-1 普通发布 slug 原子预留，禁止误 UPDATE

**根因：** `generate_semantic_slug()` 先 `slug_exists()`；`publish.php` 先 `file_put_contents(/site/{slug}.html)`，再：

```php
if (db_one('SELECT slug FROM pages WHERE slug = ?', [$pageSlug])) {
    // UPDATE — 非编辑会话也会走进来
} else {
    // INSERT
}
```

并发同前缀时后写覆盖文件并对**他人 slug** 执行 UPDATE。

**改动文件：**

| 文件 | 改动 |
|---|---|
| `api/publish.php` | 写盘与 DB 顺序；创建 vs 编辑分支 |
| `includes/content_tools.php` 或 `includes/db.php` | 原子预留 slug 辅助函数 |
| `scripts/test-publish-slug.php`（新建）或扩 `test-logic-review.php` | 冲突 / 误 UPDATE 负向用例 |

**规则：**

```text
1. 仅当 $editPage 已通过编辑鉴权时，才允许 UPDATE 既有 slug 行与覆盖原 html_path
2. 普通创建路径：绝不因「磁盘/库已有 slug」转为 UPDATE
3. 在事务中预留 slug：
   - BEGIN IMMEDIATE
   - 尝试 INSERT 占位行（或专用 slug_reservations），依赖 PRIMARY KEY(slug) 冲突
   - 冲突则换后缀重试（有上限）
   - 成功后再写 HTML
4. HTML 写入：先写 /site/{slug}.html.tmp（或同目录唯一临时名），fsync 后 rename 成 .html
5. DB 失败：删除临时文件 / 不留下「库无文件有」的孤儿策略要明确（优先：先 DB 占位再 rename）
6. 推荐顺序（创建）：
   a. 事务内 INSERT pages（status=live 或 publishing→live）
   b. 写 tmp → rename html
   c. 必要时 UPDATE html_path / 元数据
   d. COMMIT
   若 b 失败：ROLLBACK 或标记失败并清理
```

**伪代码方向：**

```php
if ($editPage) {
    // 仅更新 $editPage['slug']；校验 current_user_can_edit_page
    write_html_atomic($path, $html);
    db_exec('UPDATE pages SET ... WHERE slug = ?', [..., $editPage['slug']]);
} else {
    $pageSlug = reserve_unique_slug_with_retry($desired, $title, $messages);
    // reserve = INSERT 成功才返回；冲突则 suffix 重试
    write_html_atomic($siteDir . '/' . $pageSlug . '.html', $html);
    // 若 reserve 已写入完整行则 UPDATE 元数据；勿再 SELECT 决定 UPDATE 他人行
}
```

**验收：**

| 用例 | 期望 |
|---|---|
| 编辑会话改已有页 | 同 slug UPDATE，文件更新 |
| 普通创建，slug 库中已存在 | **新后缀**，不 UPDATE 旧行 owner/内容 |
| 模拟「检查时可用、插入时冲突」 | 重试成功或明确失败，**不覆盖**已有 html |
| 他人 live 页 slug | 任何非编辑会话不可改 DB/文件 |
| 单测：两进程抢同一 desired 前缀（能跑则跑） | 至多一个获得裸前缀，另一个带 suffix |

---

### 4.2 P1-2 生产开启 Turnstile

**根因：** 生产日志 `Turnstile config - disabled`；自动化可换 IP 放大 AI 成本。

**改动（配置 + 验收，代码路径已存在）：**

1. 生产 `config.php`（或密钥文件）：

```php
'turnstile' => [
    'enabled' => true,
    'site_key' => '...',
    'secret_key' => '...', // 勿提交仓库
],
```

2. 确认 Cloudflare 代理下 `client_ip()` 仍为访客 IP（`nginx-cloudflare-real-ip.conf` 已加载）。  
3. 发布 / 会话创建等入口：无 token / 无效 token **不进模型、不扣额度**（既有逻辑回归）。

**验收：**

| 用例 | 期望 |
|---|---|
| 无 token 发布 | 失败，`turnstile_failed`，不扣额度 |
| 无效 token | 同上，不进模型 |
| 有效 token | 正常发布 |
| 关闭 enabled 的 local | 开发仍可测（仅 local） |

---

### 4.3 P1-4 历史页面风险扫描（生产只读优先）

**脚本：** `scripts/audit-page-risk.php`（已存在）。

**上线动作：**

1. 生产以**报告模式**全量扫描 → 输出 `data/reports/page-risk-YYYYMMDD.csv`（确保 `data/` 仍不可 HTTP 访问）。  
2. CSV 列至少：`slug, risk_class, adult_gate, evidence_summary, review_status`。  
3. 高风险：先 **下线/隔离**（`status` 非 live 或移出 web 可达路径），再人工复核。  
4. **禁止**扫描失败自动批量删除。  
5. 扫描后保留 CSV；运维笔记记录处理数量。

**验收：**

| 用例 | 期望 |
|---|---|
| 生产 `data/reports/` 存在当次 CSV | 是 |
| 高风险抽样 | 不可公网浏览或已标注复核 |
| 误伤检查 | 正常页仍 200 |

---

### 4.4 P1-3 + P3-4 可复现提交与推送 GitHub

**时机：** Sprint A 运维完成 + Sprint B 代码项（至少 P1-1）合并进工作树并验收后。

**步骤：**

1. `git diff --cached --check`：清理 Markdown **行尾双空格** / EOF 空行（P3-4）。  
2. 确认无密钥、无 `config.php`、无生产 DB 进入提交。  
3. 逻辑提交建议拆分（示例）：  
   - `fix(security): publish slug reserve + no blind UPDATE`  
   - 既有 AUDIT-7 改动可按主题 squash/拆 commit（支付/认领/sanitize/测试隔离）  
4. 完整本地回归后再 `push` `main`（或 PR）。  
5. 推送后：空目录 clone + 文档可还原生产**代码**（密钥/ DB 仍按 runbook 外置）。

**验收：**

| 用例 | 期望 |
|---|---|
| GitHub `main` HEAD | 含支付/后台/sanitize/额度/访客/测试等目标文件 |
| 新 clone 无密钥 | 是 |
| `git diff --check` | 干净 |

---

### 4.5 Sprint B 完成定义

- [ ] P1-1：创建路径无误 UPDATE；原子预留 + 测试  
- [ ] P1-2：生产 Turnstile on + 负向用例  
- [ ] P1-4：生产风险 CSV 产出 + 高风险处理策略落地  
- [ ] P1-3：目标文件已提交并推送（在 A+B 代码验收后）  
- [ ] P3-4：whitespace check 通过  

**回归：**

```bash
php scripts/test-logic-review.php
php scripts/test-audit7-hardening.php
php scripts/test-pay-quota.php
php scripts/test-guest-user-flow.php
php scripts/test-admin-tabs.php
php scripts/test-ui-drawer.php
# 新增
php scripts/test-publish-slug.php   # 若已添加
php -l api/publish.php
```

---

## 5. Sprint C — 中优先级加固

### 5.1 P2-1 停用用户不得保持有效身份

**根因：** `verify.php` 不查 `status`；`current_user_id()` / `me` / `my-pages` 不限制 active；额度层过滤 active →「能登录但额度怪」。

**规则：**

```text
1. 验证码登录：仅 status=active 可写入 $_SESSION['user_id']
2. current_user() / current_user_id()：每次敏感请求校验 status；非 active 清 session 并视为未登录
3. Admin 停用用户时：可选立即失效该用户 session（DB sessions 或 PHP session 侧策略写清）
4. me / my-pages / publish 编辑：统一走校验
```

**改动文件：** `api/auth/verify.php`、`includes/bootstrap.php`（或 auth helper）、`api/auth/me.php`、`api/my-pages.php`、测试。

**验收：**

| 用例 | 期望 |
|---|---|
| active 登录 | 成功 |
| status=disabled 再 verify | 拒绝 |
| 已登录后被停用再调 me | 未登录 / 401 |
| 停用用户额度接口 | 不出现「半登录」态 |

---

### 5.2 P2-2 支付密钥外置

**阶段建议：**

| 阶段 | 内容 |
|---|---|
| C2-1 | Runbook：备份权限、禁止把 `data/*.db` 拷到 web 可读位置；660 + deny 复查 |
| C2-2 | DB `pay_channels` 改存 `secret_ref`；真密钥在 `/etc/xlog/config.php` 或 env |
| C2-3 | 若短期必须入库：webroot 外 master key 应用层加密 + 轮换文档 |

**验收：** 新部署可不在 SQLite 明文见 `md5_key`/私钥；回调验签仍用正确密钥；备份泄露面缩小说明写入 `PAY-RUNBOOK.md`。

---

### 5.3 P2-3 版本迁移 baseline

1. 使用已有 `schema_migrations(version, applied_at)`。  
2. 目录 `migrations/`：`001_baseline.sql`（或 `.php`）描述**当前**结构。  
3. `db_init`：执行未应用版本；禁止只靠无限 `db_ensure_column` 作为唯一真相。  
4. 外键：对可清理的关系逐步加；先报告孤儿再 `ON DELETE` 策略。  

**验收：** 空库一键到当前版本；二次启动不重复迁移；版本号可查。

---

### 5.4 P2-4 DOM allowlist sanitizer

**目标：** 从正则 denylist → DOM 解析 + **标签/属性/协议 allowlist**。

| 项 | 要求 |
|---|---|
| 解析 | DOMDocument（或等价） |
| 默认剥除 | script、on\*、javascript:、data:text/html、iframe、form、meta refresh |
| 额外 | 未加引号 URL、`srcset`、style 内 `@import` / `url(` 外链 |
| 发布 | `publish.php` 写盘前强制；失败拒绝 |
| 第二层 | Nginx wildcard CSP **保留** |

**验收：** 审计所列 evil img / srcset / @import **不可存活**；正常页面样式保留；单测覆盖。

---

### 5.5 Sprint C 完成定义

- [ ] 停用用户全链路拒绝  
- [ ] 密钥外置至少完成 C2-1 + C2-2 方案落地或排期文档  
- [ ] migrations 可跑 baseline  
- [ ] sanitizer allowlist + 测试  

---

## 6. Sprint D — 工程卫生与并发（后置）

| ID | 动作 |
|---|---|
| P3-1 | `codex-dynamic-audit.js`：RUNTIME 改 env / 探测；缺依赖打印安装说明 |
| P3-2 | 渐进拆分：`js/` auth/billing/drawer；`pay` 渠道 vs 订单；**有测再拆** |
| P3-3 | staging **php-fpm** 并发：同 slug 双 publish、双回调、SQLite busy |
| P3-4 | 已在 B 提交前处理 whitespace |
| 文档 | `docs/README.md` 索引保持 AUDIT-8 + 本文为当前入口；完成项可标 archive |

**并发验收最小集（staging）：**

```text
1. 两请求同时 publish 相同 desired_slug → 无交叉覆盖
2. 同 session 双 publish → 第二请求 session_generating 或安全失败
3. notify + query 同时 fulfill → 积分不双发
```

---

## 7. 测试与证据

### 7.1 脚本矩阵

| 脚本 | 覆盖 |
|---|---|
| `test-logic-review.php` | 既有逻辑回归 |
| `test-audit7-hardening.php` | 认领 / 支付 / sanitize 等 |
| `test-pay-quota.php` | 支付额度 |
| `test-guest-user-flow.php` | 游客认领 |
| `test-admin-tabs.php` | 后台 |
| `test-ui-drawer.php` | 抽屉 UI |
| **`test-publish-slug.php`**（新建） | TOCTOU / 误 UPDATE |
| **`test-user-status.php`**（新建，C） | disabled 登录 |
| `audit-page-risk.php` | 历史页报告（生产只读） |

### 7.2 生产证据清单（完成 A/B 后归档）

```text
- curl partials 404 截图或日志
- curl HTTP 301 日志
- curl wildcard CSP 头
- curl wildcard /api 404
- Turnstile 开关配置确认（脱敏）
- page-risk CSV 路径与高风险处理表
- GitHub commit SHA 与生产代码 hash 对照表
```

---

## 8. 推荐修复顺序（执行清单）

| 顺序 | 项 | 负责面 | 预估 |
|---|---|---|---|
| 1 | **P0-1** partials 404 | 运维 Nginx | 30–60 min |
| 2 | **P0-2** 拆 server + CSP | 运维 Nginx | 2–4 h |
| 3 | **P0-3** HTTPS + TLS | CF + Nginx | 1–2 h |
| 4 | **P1-1** slug 原子发布 | 应用代码 | 0.5–1.5 d |
| 5 | **P1-2** Turnstile on | 配置 | 1–2 h |
| 6 | **P1-4** 风险扫描 | 运维 + 产品 | 0.5–1 d |
| 7 | **P2-1** 用户 status | 应用代码 | 0.5 d |
| 8 | **P2-2/3/4** 密钥/迁移/DOM | 应用 | 2–4 d |
| 9 | **P1-3** 提交推送 | 仓库 | 在 1–4 后 |
| 10 | P3 并发与卫生 | 工程 | 后置 |

---

## 9. 风险与回滚

| 变更 | 风险 | 回滚 |
|---|---|---|
| partials 404 | 若有未知前端依赖 partials URL | 恢复旧 location（应极罕见） |
| server 拆分 | 证书/server_name 写错导致子域 502 | conf 备份 + reload 旧文件 |
| 严 CSP | 极少数合法生成页若仍需 script | 新发布已禁 script；历史页本应无脚本能力 |
| HTTPS 强制 | 监控/健康检查仍用 HTTP | 改检查 URL 为 HTTPS |
| slug 预留 | INSERT 占位失败路径留脏数据 | 事务 + 失败清理临时文件 |
| Turnstile on | 前端未加载 widget 时全员无法发布 | 确认主站脚本与 site_key；紧急 `enabled=false` 仅作事故开关并记审计 |

---

## 10. 完成定义（Definition of Done）

**运维闭环（Sprint A）：**

- 公网无法下载任何 `partials/**/*.php` 源码  
- 子域无法访问主站 API/后台  
- 全站 HTTP 跳 HTTPS，无 TLS 1.1  

**应用闭环（Sprint B）：**

- 普通发布无法覆盖他人页面  
- 生产发布必须 Turnstile  
- 历史高风险页有报告与处理  
- GitHub 可复现当前生产代码基线  

**加固闭环（Sprint C，可迭代）：**

- 停用用户零有效会话  
- 密钥与迁移/消毒器达到文档阶段目标  

---

## 11. 附录：关键路径速查

| 路径 | 说明 |
|---|---|
| 生产 Nginx | `/www/server/panel/vhost/nginx/xlog.ink.conf` |
| 片段文档 | `docs/nginx-v2-snippet.conf` |
| 发布 | `api/publish.php` |
| slug 工具 | `includes/content_tools.php` / `slug_exists` in `db.php` |
| 消毒 | `includes/html_sanitize.php` |
| 认证 | `api/auth/verify.php`、`includes/bootstrap.php` |
| 风险扫描 | `scripts/audit-page-risk.php` |
| 审计原文 | `docs/AUDIT-8-2026-07-26.md` |
| 上轮整改 | `docs/AUDIT-7-NEXT-DEV.md` |
