# 游客 / 登录用户体验 — 下一步开发文档

> 依据：游客 vs 登录用户全链路审计（2026-07-26）  
> 范围：额度双轨、页面归属与认领、顶部导航、文案与模型引导、再生成语义  
> 状态：可执行规格；按 P0 → P1 → P2 排期  
> 相关文档：`PAY-CREDITS-NEXT-DEV.md`（支付/积分资金正确性）、`PAY-RUNBOOK.md`（运营手册）

---

## 1. 背景与目标

### 1.1 产品双轨模型（当前已上线）

| 身份 | 生成计费 | 页面归属 | 后续修改 |
|---|---|---|---|
| **游客** | 每日免费次数（默认 **5**，IP + cookie 双计） | 无 `owner_user_id` | 仅当发布后留下邮箱 → edit token 邮件链接 |
| **登录用户** | `credit_mode` 钱包积分（注册送 **10**，每次生成扣 `generate_credit_cost`，默认 1） | 新建页写入 `owner_user_id` | 右上角 **「我的」→「我的页面」** |

配置入口：`config.php` → `billing.guest_generate_quota` / `signup_credits` / `credit_mode` / 套餐。

### 1.2 本阶段目标

1. **消除「登录后体验劣于游客」的反转化点**（0 积分锁死、游客页无法认领等）  
2. **顶部导航更疏朗**：登录态只保留「我的」，不再显示用户名，页面入口收敛进「我的」  
3. **文案 / 模型状态与真实计费一致**（积分 vs「今日剩余额度」）  
4. **不重写架构**，在现有 `quota.php` / 发布 / 会话 / 前端 `ai-app.js` 上迭代  

### 1.3 非目标（本期不做）

- 订阅制、企业子账号、自定义域名  
- 退款 / 发票 / 优惠券引擎  
- 游客与登录完全统一成单一计费模型（双轨刻意保留）  
- 大改聊天 UI 或换前端框架  

### 1.4 已完成相关项（审计后 / 同期）

| 项 | 状态 | 说明 |
|---|---|---|
| 登录用户发布后不再弹邮箱绑定 | ✅ | `publishedLoggedIn`，跳过 `EMAIL` 动作 |
| 支付入账金额规范化 / 积分原子扣减 | ✅ | 见支付文档 |
| 充值 UI + 微信 / 支付宝渠道 | ✅ | |
| **顶部「我的」收敛「页面」** | ✅ 本期 UI | 见 §4 Sprint 0 |

---

## 2. 问题回溯（审计摘要 G1–G11）

| ID | 级别 | 问题 | 业务影响 | 建议 Sprint |
|---|---|---|---|---|
| **G1** | **P0** | 登录用户积分耗尽后 **完全不能生成**，而游客仍有日 5 次；0 积分登录用户体验 **劣于游客** | 转化反噬：用尽后宁可退出登录当游客 | A |
| **G2** | **P0** | 游客期生成的页面 **不会** 在登录后自动 `owner_user_id` 认领；「我的页面」只看登录后创建的页 | 用户觉得「登录了页面丢了」 | A |
| **G3** | **P1** | 「重新生成 / 再做一版」与 NEW_SESSION、覆盖编辑语义边界仍易误判（依赖模型 + prompt） | 误开新会话丢上下文 / 误新建 slug | B |
| **G4** | **P0** | 聊天注入文案 `今日剩余生成额度：{remaining}` 对积分用户 **语义错误**（remaining 是可生成次数估算，不是「今日」日额度） | 模型乱说、客服口径乱 | A |
| **G5** | P1 | 顶部曾同时展示：额度 pill +「页面」+ 用户名，窄屏拥挤 | 可用性 | **0（已做 UI）** |
| **G6** | P1 | `users.daily_quota` 与 `credit_mode` 并存，非 credit 路径易误解 | 运营 / 排障 | B |
| **G7** | P1 | 游客额度用尽 CTA 引导登录，但登录后若老号 0 积分立刻死锁（与 G1 联动） | 转化漏斗断裂 | A（随 G1） |
| **G8** | P2 | 登录瞬间是否绑定当前 `session.user_id`、历史消息归属是否完整 | 资产连贯性 | C |
| **G9** | P2 | 编辑再生成与新建生成均扣积分，文案未始终说清 | 感知「改一改也扣费」 | B |
| **G10** | P2 | 游客 email token 与账号邮箱可相同但无合并策略 | 双入口改同页 | C |
| **G11** | P2 | 发布系统事件文案仍提「邮箱修改链接」，对登录用户偏噪音 | 模型二次引导偏差 | B |

### 2.1 差异对照表（实现现状）

| 能力 | 游客 | 登录用户 |
|---|---|---|
| 顶部入口 | `[登录]` | `[我的]`（内含：邮箱、我的页面、充值、退出） |
| 额度 pill | `剩余 r/5` | `积分 N`（可点开充值） |
| 生成扣费 | 日计数 `quota_counters` | `users.credits` 原子扣减 |
| 额度耗尽 | 引导登录 | 引导充值（`credits_exhausted` 卡） |
| 发布归属 | `owner_user_id = null` | `owner_user_id = 当前用户` |
| 发布后改权 | 邮箱卡片 + token | 直接归账号；不弹邮箱 |
| 列表 | 无 | `api/my-pages.php` by owner |
| 聊天状态注入 | mode=guest_daily + 今日免费文案 | mode=user_credits / user_fallback，按模式注入 |

### 2.2 总评（审计时点）

| 维度 | 分 | 说明 |
|---|---|---|
| 双轨架构清晰度 | 8/10 | 游客试用 / 登录资产+付费，主线清楚 |
| 登录用户体验一致性 | 6.5/10 | 邮箱已修；0 积分劣于游客、页认领、再生成语义仍弱 |
| 游客体验 | 7.5/10 | 5 次试用够用；丢修改权提示够，转化登录后认领弱 |
| 安全边界 | 8/10 | 会话与编辑鉴权基本稳妥 |
| 文案 / 模型引导 | 6/10 | 状态注入与 UI 入口文案需对齐「我的」与积分语义 |

---

## 3. 里程碑总览

```text
Sprint 0（UI）  顶部「我的」收敛「页面」           已完成 / 本期交付
Sprint A（P0）  G1 额度策略 + G2 认领 + G4 文案     1–1.5 天
Sprint B（P1）  G3/G6/G9/G11 语义与运营文档         1–2 天
Sprint C（P2）  G8/G10 会话绑定与资产合并           2–3 天（可后置）
```

每个 Sprint 必须有：**实现清单 + 验收用例**。支付资金类问题不在本文重复，见 `PAY-CREDITS-NEXT-DEV.md`。

---

## 4. Sprint 0 — 顶部导航「我的」（本期交付）

### 4.1 目标

登录用户右上角 **只保留一个账号入口「我的」**：

- **不再** 在 topbar 显示邮箱前缀 / 用户名  
- **不再** 在 topbar 单独放「页面 / 我的页面」按钮  
- 「我的页面」「充值积分」「退出登录」收敛进「我的」面板  

游客仍只显示「登录」。

### 4.2 信息架构

```text
游客 topbar:   [+] [简|繁|EN] [剩余 r/5] [登录]
登录 topbar:   [+] [简|繁|EN] [积分 N]   [我的]

点击「我的」→ account 面板：
  · 完整邮箱
  · [我的页面]  → 打开 my-pages 列表面板
  · [充值积分]  → 打开购买面板（pay_enabled 时）
  · [退出登录]
```

互斥：打开「我的 / 我的页面 / 充值」任一，关闭另外两个，避免顶栏下多层面板叠满。

### 4.3 改动文件

| 文件 | 改动 |
|---|---|
| `index.php` | 移除 topbar `#myPagesToggle`；`#accountRow` 改为 account-actions（含 `#openMyPagesBtn`） |
| `js/ai-app.js` | `setUser` 显示 `t('myAccount')`；`toggleMyPages` / `toggleBuyCredits` 与 account 互斥；绑定 openMyPagesBtn |
| `css/page-ai.css` | `.account-row` / `.account-actions` 布局 |
| `includes/i18n.php` | `myAccount`；`publishedLoggedIn` 指引改为「我的」→「我的页面」 |
| `prompts/chat-system.txt` | 登录用户修改引导同步 |

### 4.4 验收

| 用例 | 期望 |
|---|---|
| 游客 | topbar 无「页面」「我的」，仅「登录」 |
| 登录 | topbar 显示「我的」，**不**显示用户名与独立「页面」 |
| 点「我的」 | 展开邮箱 + 我的页面 + 充值 + 退出 |
| 点「我的页面」 | 关闭 account，打开页面列表，可打开 / 修改 |
| 点「充值积分」或额度 pill | 打开充值面板，account 关闭 |
| 退出 | 回到游客 topbar，关闭所有面板 |
| 窄屏（≤760px） | topbar 不溢出；account-actions 可换行 |
| 三语 | zh-CN「我的」/ zh-TW「我的」/ en「Account」 |

---

## 5. Sprint A — P0 产品正确性（G1 / G2 / G4）

### 5.1 G1：登录 0 积分不得劣于游客

**问题根因**

- `credit_mode && userId` 时 `consume_quota('generate')` **只扣积分**，不再走游客日额度。  
- 积分 < cost → `credits_exhausted`，用户无法生成。  
- 同设备若退出登录，仍可能有游客日剩余 → 体验倒挂。

**可选策略（实现前产品二选一，推荐 A1）**

| 方案 | 行为 | 优点 | 风险 |
|---|---|---|---|
| **A1 登录保底日试用（推荐）** | 积分不足时，允许登录用户使用与游客同结构的 **日免费次数**（可配置 `billing.user_fallback_daily_generate`，默认与 guest 同 5 或更低如 1–2） | 不倒挂；仍鼓励充值 | 需防「注册号刷日免费」；与 IP 游客额度是否共享要定 |
| **A2 硬引导充值** | 维持现状，但 **禁止** 用尽后靠退出登录白嫖：登录过的 cookie 设备禁止再吃游客额度 | 商业更硬 | 实现复杂，易误伤家庭共网 |
| **A3 0 积分自动降级游客 UI** | 显示「今日可免费 n 次」并走 guest 计数键 | 实现近 A1 | 身份文案混乱 |

**推荐实现要点（A1）**

文件：`includes/quota.php`

1. `consume_quota('generate')` credit 分支：  
   - 若 `credits >= cost` → 原子扣积分（现状）。  
   - 若不足且 `user_fallback_daily_generate > 0` → 走 `user:{id}` 日计数（或独立 kind `generate_free`），成功则不扣积分，返回 `reason: free_daily`。  
   - 两者皆尽 → `credits_exhausted`（前端仍出充值卡，文案区分「免费次数也用完」）。  
2. `quota_status` 同步：`remaining` / pill 展示优先积分；积分为 0 时展示「今日免费 r/n」或组合文案。  
3. i18n：`creditsExhaustedBody` 补充「今日免费次数也已用完」分支。

**验收**

| 用例 | 期望 |
|---|---|
| 新注册 10 积分 | 可生成；扣积分 |
| 积分扣到 0，fallback=2 未用 | 仍可生成 2 次/日，不扣积分 |
| fallback 用尽 | 充值卡，不能生成 |
| 游客 5 次 | 行为不变 |

**配置**

```php
// config.php billing
'user_fallback_daily_generate' => 2, // 0 = 关闭保底（恢复纯积分）
```

### 5.2 G2：游客页登录后认领

**问题根因**

- `publish.php` 仅在 **INSERT** 时写 `owner_user_id`；游客页永久 null。  
- `my-pages.php`：`WHERE owner_user_id = ?`。  
- 邮箱 token 只证明可编辑，**不**写 owner。

**推荐策略（最小安全）**

| 触发 | 规则 |
|---|---|
| 登录成功 / 每次 `me` | 可选：扫描「当前会话已发布且 owner 为空」的 slug，写入当前 user |
| 用户打开 email edit 链接且已登录 | 若 `pages.email` 与账号邮箱一致（大小写不敏感）且 `owner_user_id` 空 → 认领 |
| 显式「认领此页」按钮 | 在会话交付卡或我的页面空态提供；校验 session 发布记录或 token |

**禁止**

- 仅凭 IP 批量认领历史页（可抢别人页）  
- 覆盖已有非空 `owner_user_id`

**实现草图**

```php
// includes/page_edit.php 或 helpers
function claim_page_for_user(string $slug, int $userId, array $opts = []): array {
    // opts: require_session_id, require_email_match
    // UPDATE pages SET owner_user_id = ? WHERE slug = ? AND owner_user_id IS NULL
}
```

登录后：

```php
// api/auth/verify.php 成功后 或 api/auth/me.php
// 若 session 有 page_slug 且 owner 空 → claim
```

**验收**

| 用例 | 期望 |
|---|---|
| 游客生成 → 同会话登录 | 该 slug 出现在「我的页面」 |
| 游客 A 的页，用户 B 登录 | 不能仅凭浏览认领 |
| 已有 owner 的页 | 不被改写 |
| 邮箱与 token 匹配且登录同邮箱 | 可认领 |

### 5.3 G4：状态注入与额度文案

**问题根因**

```php
// api/chat.php
t('prompt', 'status', $locale, ['identity' => $quota['identity'], 'remaining' => $quota['remaining']]);
// i18n: 「今日剩余生成额度：{remaining}」
```

积分模式下 `remaining = intdiv(credits, cost)`，**不是**「今日」概念。

**改动**

1. `quota_status` 增加字段：`mode: guest_daily | user_credits | user_fallback`，`credits`，`credit_cost`。  
2. i18n `prompt.status` 拆分：

| mode | 文案示例 |
|---|---|
| guest_daily | 当前用户：游客，今日剩余免费生成：{remaining}/{limit} |
| user_credits | 当前用户：已登录，积分余额：{credits}（每次生成约 {cost} 分） |
| user_fallback | 当前用户：已登录，积分不足；今日保底免费剩余：{remaining} |

3. 前端 pill 已有 `quotaCredits` / `quotaRemaining`，与注入文案对齐即可。  
4. 模型 prompt（`chat-system.txt`【身份与额度】）改为引用上述真实语义，禁止模型编造「登录后每天 50 页」等过时数字。

**验收**

| 用例 | 期望 |
|---|---|
| 游客对话 | 注入含「今日」「免费」 |
| 登录有积分 | 注入含「积分」不含误导「今日额度」 |
| 积分 0 + fallback | 注入含保底免费 |

---

## 6. Sprint B — P1 语义与运营（G3 / G6 / G9 / G11）

### 6.1 G3 再生成 vs 新会话

- 保持 `chat-system.txt` 规则：只有明确「从零开始 / 丢弃上下文」才 `NEW_SESSION`。  
- 前端：发布后交付卡增加次要按钮 **「再改一版（扣积分）」** vs **「做新页面」**，减少纯靠模型判断。  
- 回归：`scripts/test-logic-review.php` 或手工脚本覆盖「重新生成一版」不触发 NEW_SESSION。

### 6.2 G6 daily_quota 与 credit_mode

- 文档与后台标注：`credit_mode=true` 时生成 **忽略** `users.daily_quota`（除非开启 G1 fallback）。  
- Admin 用户列表展示积分优先；`daily_quota` 标记为 legacy / fallback 相关。

### 6.3 G9 编辑再生成扣费说明

- 生成确认卡：`publishConfirmBody` 登录态追加「将消耗 {cost} 积分」。  
- 编辑模式同样展示，避免「改字也扣费」投诉。

### 6.4 G11 系统事件文案

- `publish.php` 追加的系统事件按是否登录分支：  
  - 登录：引导「我的 → 我的页面」  
  - 游客：引导邮箱修改链接  

---

## 7. Sprint C — P2 资产连贯（G8 / G10）

### 7.1 G8 登录绑定 session

- `verify` 成功后：`UPDATE sessions SET user_id = ? WHERE id = ? AND (user_id IS NULL OR user_id = ?)`。  
- 保证后续 publish 与 claim 有稳定会话锚点。

### 7.2 G10 邮箱 token 与账号合并

- 账号邮箱 == `pages.email` 且 owner 空 → 登录时批量 claim（限量、需用户确认更佳）。  
- token 链接打开时若已登录且邮箱一致 → 静默 claim。

---

## 8. 测试与验收清单（汇总）

### 8.1 手工 / 真机

1. 游客生成 1 页 → 登录 → 页面是否在「我的页面」（G2）  
2. 登录扣光积分 → 是否仍可按 fallback 生成 / 或明确充值（G1）  
3. 登录对话中模型是否还说「今日额度」（G4）  
4. topbar 仅「我的」，无用户名、无独立「页面」（Sprint 0）  
5. 「我的」内：我的页面 / 充值 / 退出均可用  
6. 游客额度尽 → 登录 CTA → 新用户 10 分可生成  

### 8.2 自动化（建议补）

```bash
php scripts/test-pay-quota.php   # 积分扣充
# 新增：scripts/test-guest-user-flow.php
#  - guest publish owner null
#  - login claim same session
#  - credit exhausted + fallback
#  - quota_status mode fields
```

---

## 9. 配置速查（客服 / 运营口径）

| 项 | 配置键 | 默认 |
|---|---|---|
| 游客日生成 | `billing.guest_generate_quota` | 5 |
| 注册赠送积分 | `billing.signup_credits` | 10 |
| 每次生成扣分 | `billing.generate_credit_cost` | 1 |
| 积分模式开关 | `billing.credit_mode` | true |
| 登录保底日次（G1 后） | `billing.user_fallback_daily_generate` | 建议 2 |

**对外口径（credit_mode 开启时）**

- 游客：每天免费试 N 次，页面不自动进账号；要长期改页请留邮箱或登录后生成。  
- 登录：用积分生成；新用户送 10 分；可充值；页面在「我的 → 我的页面」。  
- 不要说「登录后每天 50 页」等旧数字。

---

## 10. 决策记录（已按默认落地）

- [x] G1 采用 **A1 保底日试用**，`billing.user_fallback_daily_generate` 默认 **2**  
- [x] G2 认领触发：**同会话自动** + **邮箱匹配**（登录 verify/me；edit token 打开时静默认领）  
- [x] 编辑再生成与新建 **同价扣分**（确认卡文案已标明）  
- [ ] 老用户 0 积分是否一次性补偿（运营，非代码默认）  

**实现状态（2026-07-26）：** Sprint 0–C 代码与 `scripts/test-guest-user-flow.php` 已落地。

---

## 11. 文件索引

| 路径 | 职责 |
|---|---|
| `includes/quota.php` | 游客日计 / 积分扣退 / status |
| `api/chat.php` | 状态注入 prompt |
| `api/publish.php` | 扣费、写 page、owner |
| `api/my-pages.php` | 登录用户页面列表 |
| `api/auth/verify.php` | 注册送分、登录 |
| `includes/i18n.php` | UI + prompt 文案 |
| `js/ai-app.js` | topbar、我的、充值、交付卡 |
| `index.php` | 壳与面板 DOM |
| `prompts/chat-system.txt` | 模型动作与身份规则 |

---

## 12. 完成定义

| Sprint | Done when |
|---|---|
| **0** | 登录 topbar 仅「我的」；页面/充值/退出在面板内；三语与窄屏通过 |
| **A** | G1 不倒挂、G2 同会话可认领、G4 注入文案与积分语义一致；有测试或清单勾选 |
| **B** | 确认卡标明扣分；系统事件分游客/登录；运营口径文档更新 |
| **C** | session 绑定与邮箱 claim 策略上线或明确不做 |

---

*文档版本：2026-07-26 · 与顶部「我的」UI 同期产出*
