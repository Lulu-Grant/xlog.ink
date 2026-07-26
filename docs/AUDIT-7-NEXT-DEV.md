# 第七轮审计整改 — 下一步开发文档

> 依据：`docs/AUDIT-7-2026-07-26.md`（2026-07-26 综合审计）  
> 范围：会话认领授权、支付渠道与入账 hardening、测试库隔离、生成页脚本安全、P2 运维与仓库卫生  
> 状态：可执行规格；按 Sprint A → D 排期（对应审计阶段 A–D）  
> 相关：`PAY-CREDITS-NEXT-DEV.md`、`PAY-RUNBOOK.md`、`GUEST-USER-NEXT-DEV.md`、`ADMIN-NEXT-DEV.md`

---

## 1. 背景与目标

### 1.1 审计总判

| 维度 | 结论 |
|---|---|
| 主链路 | 可用（生成、额度、上传、成人审核 fail-closed、目录屏蔽等仍有效） |
| 放量阻塞 | **支付入账与会话认领** 存在 P1 级边界问题 |
| 敏感扫描 | 仓库内未发现已提交明文 API Key / 直接 SQL 注入 |

### 1.2 本阶段目标

1. **堵住账号认领绕过**（仅允许当前浏览器可访问的 session）  
2. **支付：渠道不可硬删丢验签 + 缺金额永不入账 + 回调不静默换渠**  
3. **测试禁止写生产/默认 SQLite**；提供污染数据 dry-run 清理  
4. **生成页默认无任意内联 JS**（allowlist + CSP 收紧）  
5. 按优先级推进 P2/P3，**不重写整体架构**

### 1.3 非目标（本期不做）

- 换框架 / 换 Postgres / 多租户 RBAC  
- 退款 API、发票、订阅  
- 完整 BI、内容人工审核台  
- 强制一次拆完 `ai-app.js` / `pay.php` / `i18n.php` 全量模块化（Sprint D 可渐进）  
- 本轮不要求连生产打真实支付/AI 费用

### 1.4 问题索引（审计 → 开发）

| 审计 ID | 级别 | 主题 | Sprint |
|---|---|---|---|
| **P1-1** | P1 | Session 认领未校验浏览器归属 | **A** |
| **P1-2** | P1 | 支付渠道硬删除 → 历史回调丢密钥 | **A** |
| **P1-3** | P1 | 缺金额仍可 fulfill | **A** |
| **P1-4** | P1 | 测试写真实 DB | **A** |
| **P1-5** | P1 | 生成页任意内联 JS + `unsafe-inline` | **B** |
| P2-1 | P2 | Nginx CSP 主域/通配符共用 | **B** |
| P2-2 | P2 | 截图固定 URL + immutable | **C** |
| P2-3 | P2 | 截图污染 visit 统计 | **C** |
| P2-4 | P2 | API 泄漏上游错误原文 | **B**（可并行） |
| P2-5 | P2 | 支付密钥明文 SQLite | **C**（可后置加固） |
| P2-6 | P2 | 缺 AI key 静默 mock | **B** |
| P2-7 | P2 | 无迁移版本 / 无外键 | **C** |
| P2-8 | P2 | Playwright 本机路径 / 无 lockfile | **C** |
| P2-9 | P2 | 普通 cookie HTTPS 不看反代 | **C** |
| E1–E3 | 环境 | Turnstile 关、HTTP 渠道、pay URL 未校验 | **B** |
| P3-* | P3 | 单体拆分、死代码、大图、文档归档 | **D** |

---

## 2. 里程碑总览

```text
Sprint A（阻断）  认领 + 支付渠道/金额 + 测试隔离     1.5–2.5 天  ★ 放量前门
Sprint B（安全）  生成页 sanitizer/CSP + mock/错误面 + Turnstile/E*  2–3 天
Sprint C（数据）  截图版本化/visit、迁移、HTTPS、密钥加固          2–4 天
Sprint D（卫生）  单体拆分、删死代码、文档归档、素材迁出           可并行后置
```

每个 Sprint：**实现清单 + 验收用例 + 回归命令**。  
**放量门槛：Sprint A 全部验收通过** 后才可扩大付费/登录流量。

---

## 3. Sprint A — 账号与支付阻断（必做）

### 3.1 P1-1 会话认领必须通过 session_access_allowed

**根因：** `verify` / `me` 接受任意 `session_id` 调用 `claim_pages_after_login`；`bind_session_to_user` 只看 `user_id` 是否空，不校验 cookie `client_id` / IP。

**改动文件：**

| 文件 | 改动 |
|---|---|
| `includes/page_edit.php` | `claim_pages_after_login` / `bind_session_to_user` / `claim_page_for_user` |
| `api/auth/verify.php` | 传入 session 前校验访问权 |
| `api/auth/me.php` | 同上 |
| `scripts/test-guest-user-flow.php` 或新建 `test-session-claim.php` | 负向用例 |

**规则：**

```text
1. 加载 sessions 行；不存在 → 拒绝 bind/claim
2. 必须 session_access_allowed($session) === true
3. 仅当 session 未绑定或已绑定当前 user 时才 bind
4. claim 仅针对：
   - 同 session 的 page_slug（且 access_allowed）
   - 或 email_match（既有规则，不依赖他人 session）
5. 禁止：仅凭“session 未绑定 + 知道 id”完成归属
```

**实现要点：**

```php
// bind_session_to_user / claim 入口
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session || !session_access_allowed($session)) {
    return ['ok' => false, 'error' => 'forbidden_session'];
}
// 再执行原有 user_id 空或自有逻辑
```

**验收：**

| 用例 | 期望 |
|---|---|
| 同浏览器：游客生成 → 登录同 session | claim 成功 |
| 登录用户提交 **另一 client_id/IP 的未绑定 session** | bind/claim 失败，owner 仍 null |
| email_match 认领孤儿页 | 仍成功（不依赖 session 偷绑） |
| 已有 owner 页 | 永不改写 |

---

### 3.2 P1-2 支付渠道：禁硬删 + 回调不换渠

**根因：** Admin 可 `DELETE` 渠道；pending 回调需原密钥；找不到渠道时回退到同类型/首个启用渠道。

**改动文件：**

| 文件 | 改动 |
|---|---|
| `includes/pay.php` | `pay_channel_delete` → 软删或拒绝；查单/验签只按订单 `channel_id` |
| `partials/admin/channels.php` | UI 去掉硬删或改为「停用」；有 pending 时禁用删除 |
| `admin.php` | POST action 对齐 |
| 测试 | 软删后 pending 仍能用原渠道元数据验签（密钥仍在行内） |

**规则：**

1. **禁止物理 DELETE**；`enabled=0` 表示停用。  
2. 若 `orders` 存在该 `channel_id` 且 status 为 `pending`（或任意历史 paid），**禁止删除行**（仅可停用）。  
3. `pay_fulfill` / notify / query：**只用** `order.channel_id` 对应渠道；不存在则 **fail closed**（记 log），**禁止** fallback 到其他渠道。  
4. UI：操作列「删除」改为「停用」；停用确认文案说明历史订单仍可对账。

**验收：**

| 用例 | 期望 |
|---|---|
| 无订单渠道 | 可停用 |
| 有 pending 的渠道 | 不能物理删除；停用后行仍在 |
| 停用后 notify 带正确签名 | 仍能验签入账 |
| 删除密钥行 / 换错渠道查询 | 不得误入账 |

---

### 3.3 P1-3 缺金额永不入账

**根因：** `pay_money_equal` / fulfill 路径对空 `money` 视为匹配。

**改动文件：** `includes/pay.php`、`api/pay/notify.php`、查单入账路径、`scripts/test-pay-quota.php`（隔离 DB 后）。

**规则：**

```text
1. notify / query 入账前：money 必须可解析为合法分
2. 必须 pay_money_equal(money, order.amount_cents) === true
3. 空、缺失、非数字 → 拒绝 fulfill，order 保持 pending，写 pay-notify.log / reconcile 字段
4. 可选加强：校验 pid / channel_id / pay_type / trade_no 与订单一致（有则比对）
```

**代码方向：**

- 删除「`money === null || money === ''` → true」的 fail-open 分支。  
- `pay_parse_money_to_cents` 失败即 `money_missing` / `money_mismatch`。

**验收：**

| 用例 | 期望 |
|---|---|
| money=`10.00` 与 1000 分 | 入账成功 |
| money 缺失 / 空 | **不入账**，pending |
| money=`9.99` | 不入账 |
| 重复 notify | 幂等，不双发积分 |

---

### 3.4 P1-4 测试隔离 + 污染清理

**根因：** `test-admin-tabs` / `test-guest-user-flow` / 部分 pay 测试写 `data/xlog.db`。

**改动：**

1. **公共 fixture**（新建 `scripts/lib/test_bootstrap.php` 或仿 `test-logic-review.php`）：  
   - 临时目录 `sys_get_temp_dir()/xlog-test-*`  
   - 临时 `config.php` 指向独立 SQLite  
   - `XLOG_CONFIG_PATH` 或等价覆盖  
   - `try/finally` 删除临时目录  

2. **护栏：** 若检测到 `data_dir` 落在项目 `data/` 或配置路径含生产特征 → **exit 2** 并打印警告。  

3. **清理脚本** `scripts/cleanup-test-pollution.php`：  
   - 默认 `--dry-run`：列出匹配测试邮箱模式（如 `admin-tab-`、`guest-flow-`、`smoke-pay-`、`@example.com` 测试前缀）的 users/orders/tx  
   - `--execute` 需显式确认参数  
   - **先 dry-run 人工确认再删**

4. README：标明集成测试必须隔离；禁止在生产机对默认 DB 跑测试。

**验收：**

| 用例 | 期望 |
|---|---|
| 跑全套隔离测试前后 | 默认 `data/xlog.db` 用户/订单行数不变 |
| 故意指向默认 data_dir | 测试拒绝启动 |
| dry-run 清理 | 列出已知污染模式，不删 |

---

### 3.5 Sprint A 完成定义

- [ ] P1-1 负向认领测试通过  
- [ ] P1-2 无硬删；回调不换渠  
- [ ] P1-3 缺金额不入账 + 测试断言  
- [ ] P1-4 测试全隔离；护栏生效  
- [ ] 可选：dry-run 清理报告已产出（删除需人工）  

**回归：**

```bash
# 均须在隔离 DB 下
php scripts/test-logic-review.php
php scripts/test-pay-quota.php
php scripts/test-guest-user-flow.php
php scripts/test-admin-tabs.php
php scripts/test-ui-drawer.php
```

---

## 4. Sprint B — 生成页安全与生产边界

### 4.1 P1-5 生成 HTML 消毒 + 去自由脚本

**目标：** 模型输出视为不可信；默认 **纯 HTML/CSS**。

| 步骤 | 内容 |
|---|---|
| 1 | 新增 `includes/html_sanitize.php`：DOMDocument 解析 + **标签/属性 allowlist** |
| 2 | 默认剥离：`<script>`、`on*`、`javascript:`、`data:text/html`、`<iframe>`、`<form>`、`<meta http-equiv=refresh>`、外链 script |
| 3 | `api/publish.php` 写盘前强制 sanitize；失败则拒绝发布 |
| 4 | `prompts/gen-system.txt`：禁止输出 script/事件处理器；交互改用静态 CSS 或平台组件说明 |
| 5 | 生成页 CSP：去掉 `script-src 'unsafe-inline'`；若需固定脚本用 hash/nonce 或平台单一 `xlog-page.js` |
| 6 | 测试：构造含 script/onclick 的 HTML 片段 → sanitize 后为空/无脚本 |

**验收：** 审计列出的 script / onerror / javascript: / meta refresh / form / iframe **均不可存活**。

### 4.2 P2-1 / E* Nginx 与 Turnstile、支付 URL

| 项 | 动作 |
|---|---|
| CSP | `docs/nginx-v2-snippet.conf` 拆分：仅 `*.xlog.ink` 生成页用严 CSP；主站 `xlog.ink` 保留 Turnstile/CDN |
| E1 | 生产 `turnstile.enabled=true`；部署检查 live verify |
| E2 | `pay_channel_save` 生产强制 `https://` API base |
| E3 | 前端/后端只允许 `https:` 支付 URL；拒绝 `javascript:` / `data:` / 非白名单 host |
| P2-6 | mock 仅 `app.env=local && ai.mock=true`；生产缺 key **fail closed** |
| P2-4 | API 对客户端只返回 i18n 错误码；细节 `error_log` + 可选 `request_id` |

**验收：** 主站不被生成页 CSP 打断；缺 AI key 生产不发布假页；create 支付 URL 非法则拒绝。

---

## 5. Sprint C — 数据、截图、可复现性

### 5.1 P2-2 / P2-3 截图

1. 截图路径改为内容 hash 或 `page-shot.{hash}.png`，DB/HTML 引用更新。  
2. 或：immutable 仅作用于上传 WebP，不对 `page-shot.png` 设 immutable。  
3. 截图请求：`visit.php` 识别内部 UA/header/query（如 `xlog_internal_shot=1` + HMAC）并 **不计 visit**；或先截图再注入像素。

### 5.2 P2-7 迁移

1. 表 `schema_migrations(version, applied_at)`。  
2. `db_init` 改为：基线 schema + 按版本迁移脚本目录 `migrations/*.sql.php`。  
3. 新外键逐步加（orders.user_id、pages.owner_user_id 等），注意存量孤儿先清理。

### 5.3 P2-8 / P2-9

| 项 | 动作 |
|---|---|
| Playwright | 最小 `package.json` + lock；路径只来自 env |
| HTTPS | 统一 `request_is_https()`（仅信任配置代理），session/cid/locale/admin cookie 共用 |

### 5.4 P2-5 密钥（可分期）

- **C1：** 文档强调 `data/` deny + 文件权限 660。  
- **C2：** 密钥迁 `/etc/xlog/secrets` 或 config，DB 仅存 ref。  
- **C3：** 应用层加密 + 轮换 runbook。

**验收：** 编辑后截图立即新；shot 不计 visit；空机可按文档装 Playwright；cookie Secure 在反代 HTTPS 下正确。

---

## 6. Sprint D — 结构与仓库卫生

| 项 | 动作 |
|---|---|
| P3-1 | 渐进拆分：`js/` 按 auth/billing/drawer；`pay` 渠道 vs 订单；i18n 按 locale 文件 |
| P3-2 | 确认无引用后删除 `includes/markdown.php` |
| P3-3 | 删除 `scripts/_tmp_admin_post.php`；gitignore `scripts/_tmp_*` |
| P3-4 | 运行时仓库只留 WebP/成品；PNG 母版 LFS 或外仓（**不擅自删未确认母版**） |
| P3-5 | `docs/README.md` 索引；`current/` + `archive/audits|plans/` |
| P3-6 | `deploy.example.env`；默认不写死 IP；`--check` 扩 Nginx deny/config 权限 |

**验收：** 无本机绝对路径进主路径；无未说明临时脚本；文档入口可读。

---

## 7. 测试与证据

### 7.1 新增/扩展脚本（建议）

| 脚本 | 覆盖 |
|---|---|
| `scripts/test-session-claim.php` | P1-1 正/负向（隔离 DB） |
| `scripts/test-pay-quota.php` | P1-3 金额；P1-2 渠道解析（隔离） |
| `scripts/test-html-sanitize.php` | P1-5 向量 |
| `scripts/cleanup-test-pollution.php` | P1-4 dry-run |
| 既有 `test-logic-review.php` | 基线隔离范本 |

### 7.2 禁止

- 在生产 `data/xlog.db` 上跑会写库的 smoke。  
- 测试留下 `@example.com` 永久用户。

### 7.3 手工（放量前）

1. 双浏览器：A 游客生成 → B 登录带 A 的 session_id → **不得** 认领。  
2. 停用渠道后补回调/查单（测试环境）。  
3. 篡改/缺 money 的 notify 仿真 → 不入账。  
4. 发布含 script 的提示词 → 成页无脚本。

---

## 8. 文件索引（高频）

| 路径 | 职责 |
|---|---|
| `includes/page_edit.php` | claim / bind |
| `includes/db.php` | `session_access_allowed` |
| `api/auth/verify.php` / `me.php` | 登录认领入口 |
| `includes/pay.php` | 渠道、金额、fulfill、查单 |
| `api/pay/notify.php` | 回调 |
| `api/publish.php` | 消毒 + CSP 相关 |
| `prompts/gen-system.txt` | 禁止自由 JS |
| `includes/ai.php` | mock 门闩 |
| `docs/nginx-v2-snippet.conf` | CSP 拆分 |
| `scripts/test-*.php` | 隔离测试 |
| `docs/AUDIT-7-2026-07-26.md` | 审计原文 |
| `docs/AUDIT-7-NEXT-DEV.md` | **本文** |

---

## 9. 决策记录

| 决策 | 选择 |
|---|---|
| 放量门槛 | **Sprint A 完成** |
| 渠道删除 | **软停用**；有订单禁止删行 |
| 金额 | **强制**；空 = 拒入账 |
| 生成页脚本 | **默认全禁**；交互走平台组件（若以后要做） |
| 测试 DB | **强制隔离**；护栏拒绝默认 data_dir |
| 污染数据 | dry-run 后 **人工确认** 再删 |

勾选落地：

- [x] Sprint A（P1-1～P1-4）  
- [x] Sprint B（P1-5 + P2-1/4/6 + E* 代码侧；Turnstile 生产开关仍为配置项）  
- [x] Sprint C（截图 cache-bust + visit 跳过 shot；request_is_https；schema_migrations 表；nginx page-shot 非 immutable）  
- [x] Sprint D（删 markdown/_tmp；gitignore；docs/README；capture 去本机路径；deploy.example.env）  
- [x] 污染数据 dry-run 工具：`scripts/cleanup-test-pollution.php`（默认 dry-run）  
- [ ] P2-5 C2/C3 密钥迁 vault / 应用层加密（延后，C1 文档+deny 仍有效）  
- [ ] 单体完整拆分 ai-app/pay/i18n（延后渐进）  

**实现状态（2026-07-26）：** 阻断项与安全消毒已合入；`scripts/test-audit7-hardening.php` + 隔离后的 `test-pay-quota.php` 通过。

---

## 10. 实现顺序建议（执行者）

1. **测试护栏 + 隔离 bootstrap**（先停出血）  
2. **P1-3 金额 fail-closed**（资金）  
3. **P1-2 渠道软删 + 禁换渠**（到账）  
4. **P1-1 session_access_allowed 认领**（账号）  
5. 补测试与 dry-run 清理  
6. 再进 Sprint B 生成页消毒  

原则：**先资金与归属，再内容安全，后结构整洁。**

---

*文档版本：2026-07-26 · 依据 AUDIT-7 全文落地为可执行规格，尚未编码整改*
