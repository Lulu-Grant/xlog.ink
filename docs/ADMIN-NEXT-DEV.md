# 运营后台信息架构 — 下一步开发文档

> 依据：`admin.php` 单页结构审计与产品分析（2026-07-26）  
> 范围：侧边栏导航、按功能拆页、订单/用户只读模块、查询懒加载  
> 状态：可执行规格；按 Sprint 1 → 2 → 3 排期  
> 相关文档：`ADMIN-DASHBOARD.md`（初版只读观察台）、`PAY-RUNBOOK.md`（支付排障）、`PAY-CREDITS-NEXT-DEV.md`、`GUEST-USER-NEXT-DEV.md`

---

## 1. 背景与目标

### 1.1 当前已上线形态

| 项 | 说明 |
|---|---|
| 入口 | 单文件 `/admin.php`（约 430 行） |
| 鉴权 | `admin.token` Cookie 票据 + CSRF；登录失败 IP 锁定（`includes/admin_security.php`） |
| 能力 | ① 页面/访问 KPI ② 支付 KPI + billing 口径 ③ **支付渠道 CRUD（写）** ④ 近期页面列表（只读） |
| 设计原点 | `ADMIN-DASHBOARD.md`：只解决「生成了什么、有没有访问」；**不做多角色、不做审核台** |

支付渠道上线后，后台已从 **纯只读观察** 变为 **观察 + 资金敏感配置**，信息架构未同步升级。

### 1.2 本阶段目标

1. **侧边栏（或等价顶 tab）**：观察 / 内容 / 资金配置分视区，减少同屏干扰  
2. **按 tab 懒查库**：打开概览不再跑大 pages JOIN；打开渠道不加载页面表  
3. **支付渠道独占页**：密钥表单与页面列表物理隔离，降低误操作面  
4. **补齐排障只读面**：订单列表、用户/积分流水（先只读；补分可选）  
5. **不重写框架**：继续 PHP 单入口 + partial，不引入 Admin 框架 / SPA / 多角色 RBAC  

### 1.3 非目标（本期不做）

- 多管理员账号、角色权限矩阵  
- 可视化 BI / 图表库  
- 在线修改 `config.php`、热改 SMTP/AI 密钥  
- 页面删除、改 slug、封禁访客（高危写操作）  
- 内容审核工作台、成人内容人工队列  
- 退款 API、发票、订阅管理  

### 1.4 问题摘要（为何要改）

| ID | 级别 | 问题 | 业务影响 |
|---|---|---|---|
| **A1** | P0 | 概览 KPI、渠道密钥表单、页面列表同页纵向堆叠 | 日常查访问也要滚过密钥区；心智混乱 |
| **A2** | P0 | 每次打开全量查询（KPI + channels + pages JOIN visits） | 无关操作也吃聚合查询 |
| **A3** | P1 | `orders` 仅有 paid/pending 计数，无列表 | 对账/客诉仍靠 SQL 或 CLI |
| **A4** | P1 | `users` / `credit_transactions` 无后台面 | 0 积分、充值到账核对困难 |
| **A5** | P2 | 单文件继续膨胀（鉴权 + POST + HTML 一体） | 可维护性下降 |
| **A6** | P2 | `publish_events` / 邮件事件无入口 | 生成失败排障弱（可后置） |

---

## 2. 目标信息架构

### 2.1 菜单结构

```text
┌────────────┬──────────────────────────────────────┐
│ 概览       │  KPI + 口径摘要 + 待办快捷入口         │
│ 页面       │  搜索 / 列表 / 访问 / 打开·下 HTML    │
│ 支付渠道   │  渠道表 + 启用/删 + 新建·编辑表单      │
│ 订单       │  充值订单列表（只读，可筛状态）         │  ← Sprint 2
│ 用户积分   │  用户搜索 + 积分 + 流水（只读）         │  ← Sprint 3
│ 系统       │  billing 只读摘要 + Runbook 链接       │  ← 可并入概览
└────────────┴──────────────────────────────────────┘
```

| `tab` | 标题 | 操作级 | 数据 |
|---|---|---|---|
| `overview`（默认） | 概览 | 只读 | 页面/访问/订单计数；billing 开关与保底日次；跳转链接 |
| `pages` | 页面 | 只读 | 现有搜索 + 列表 + visits 聚合 |
| `channels` | 支付渠道 | **写** | 现有渠道 CRUD + 表单（CSRF） |
| `orders` | 订单 | 只读 | `orders` + 用户邮箱；筛 `pending`/`paid` |
| `users` | 用户积分 | 只读（补分可选写） | `users` + `credit_transactions` |

URL 约定：

```text
/admin.php                  → tab=overview
/admin.php?tab=pages
/admin.php?tab=pages&q=cafe&limit=50
/admin.php?tab=channels
/admin.php?tab=channels&edit_channel=wxpay_xhmcn
/admin.php?tab=orders&status=pending
/admin.php?tab=users&q=user@example.com
```

非法 / 未知 `tab` → 回落 `overview`。

### 2.2 侧栏交互

- 桌面：左侧固定约 **180–200px**，当前项 `aria-current="page"` + 强调边色  
- 窄屏（≤820px）：侧栏改为 **顶部横向 tab**（与现有响应式一致）  
- 视觉：沿用 admin 现有 mono / 描边 / 米色（`--bg #f4f1ea`、`--accent #df7658`），不引入新组件库  
- Flash 成功/失败条保留在内容区顶部  

### 2.3 概览页内容规格

| 区块 | 内容 |
|---|---|
| 内容 KPI | 页面总数、今日生成、总访问、今日访问、今日访客 |
| 资金 KPI | 已支付订单、待支付订单、渠道数、充值开关、积分模式、登录保底日次 |
| 口径 | 现有 credit_mode / daily_quota / fallback 说明（缩短为 2–3 行） |
| 快捷入口 | 「待支付 → 订单 tab」「管理渠道 → 支付渠道」「查页面 → 页面」 |

**不做**大表；概览只计数 + 链接。

---

## 3. 里程碑总览

```text
Sprint 1（P0）  壳 + 侧栏 + 拆 overview / pages / channels     0.5–1 天
Sprint 2（P1）  订单列表只读                                   0.5 天
Sprint 3（P1）  用户搜索 + 积分流水只读（补分可选）             0.5–1 天
Sprint 4（P2）  publish_events 只读 / 系统页（可后置）         0.5 天
```

每个 Sprint：**实现清单 + 验收用例**；行为兼容现有登录/CSRF/渠道 CRUD。

---

## 4. Sprint 1 — 壳与三 tab（必做，行为零回归）

### 4.1 目标

- 侧边栏可切换 **概览 / 页面 / 支付渠道**  
- **行为与线上一致**：鉴权、登录 PRG、渠道 save/toggle/delete、页面搜索与打开/下载  
- **按 tab 懒查库**  

### 4.2 推荐文件结构

```text
admin.php                      # 鉴权、tab 路由、POST 分发、require layout
includes/admin_security.php    # 已有（CSRF / cookie / ticket）
partials/admin/
  layout.php                   # HTML 壳：样式、侧栏、flash、content slot
  overview.php                 # 概览 KPI
  pages.php                    # 页面搜索 + 表
  channels.php                 # 渠道表 + 表单
```

可选：将 `admin_login_form` / 锁定逻辑继续留在 `admin.php` 或迁入 `admin_security.php`（二选一，避免双份）。

### 4.3 路由伪代码

```php
// admin.php（鉴权通过后）
$tab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'overview'));
$allowed = ['overview', 'pages', 'channels', 'orders', 'users'];
if (!in_array($tab, $allowed, true)) $tab = 'overview';

// POST：仅 channels 相关 action（现逻辑）；成功后 PRG 到 ?tab=channels
if (POST && pay_channel_action) {
    // ... 现有 CSRF + save/toggle/delete ...
    header('Location: ' . admin_self_url(['tab' => 'channels', /* flash via session or query */]));
    exit;
}

// 按 tab 加载数据
$data = [];
if ($tab === 'overview') { /* KPI only */ }
elseif ($tab === 'pages') { /* q, limit, pages query */ }
elseif ($tab === 'channels') { /* pay_channels_all, editChannel */ }
// orders/users: Sprint 2/3 再填；未实现时可 404 或占位「即将上线」

require partials/admin/layout.php; // 内部 include 对应 tab 文件
```

### 4.4 POST 与 PRG

| 现状问题 | 目标 |
|---|---|
| 渠道 POST 成功后仍可能渲染整页 | 成功/失败后 **303 到 `?tab=channels`**，flash 用 `$_SESSION['admin_flash']` 或一次性 query |
| 登录后若夹带 channel POST 的特殊分支 | 保持可用；登录成功后若非 channel POST 仍 PRG 到 overview |

### 4.5 懒加载规则

| tab | 必跑查询 | 禁止 |
|---|---|---|
| overview | 6–8 个 COUNT 级 KPI | pages 列表 JOIN、channels 全表（渠道数可用 `COUNT(*)`） |
| pages | pages + visits 聚合 + `q`/`limit` | 渠道表单字段 |
| channels | `pay_channels_all` + 可选 edit 行 | pages JOIN |

### 4.6 验收（Sprint 1）

| 用例 | 期望 |
|---|---|
| 未登录访问 | 登录表单；行为同现网 |
| 登录成功 | 默认 **概览**；侧栏高亮 |
| 点「页面」 | 仅页面表；搜索 `q`、limit 工作；打开/下载 HTML 可用 |
| 点「支付渠道」 | 列表 + 表单；save/toggle/delete + CSRF 同现网 |
| 在页面 tab 不加载渠道密钥 textarea | 源码/DOM 中无完整密钥表单（或 hidden 未渲染） |
| 窄屏 | 顶 tab 可切换，内容不横向溢出失控 |
| 旧书签 `/admin.php` | 仍可用，落到 overview |

**回归：** 改渠道密钥、启停、删除；登录锁定；CSRF 拒绝仍有效。

---

## 5. Sprint 2 — 订单列表（只读）

### 5.1 目标

运营可在后台查看充值订单，无需 SSH 查库。

### 5.2 列表字段

| 列 | 来源 |
|---|---|
| 订单号 | `orders.id` |
| 用户 | `users.email` JOIN `orders.user_id` |
| 金额 | `amount_cents` → 展示 `¥x.xx` |
| 积分 | `credits` |
| 状态 | `status`（pending / paid / …） |
| 渠道 | `pay_channel` / `channel_id` |
| 套餐 | `package_id` |
| 创建时间 | `created_at` |
| 支付时间 | 若有 `paid_at` 则显示，否则 `-` |

### 5.3 筛选

- `status`：`all`（默认）| `pending` | `paid`  
- `limit`：10–200，默认 50  
- 可选：`q` 匹配 order id 前缀或用户邮箱（LIKE，注意索引与性能）

### 5.4 操作

- **只读**；不提供手动 fulfill 按钮（避免与 notify/对账双路径；需要时用 `scripts/pay-reconcile.php`）  
- 文案链到 `docs/PAY-RUNBOOK.md` 要点（或后台「系统」区摘要）

### 5.5 验收

| 用例 | 期望 |
|---|---|
| 有 pending/paid 数据时列表正确 | 与 DB 一致 |
| 筛 pending | 仅 pending |
| 无写按钮 | 无「强制入账」类控件 |
| 未实现前 | tab 可隐藏或显示占位，不 500 |

---

## 6. Sprint 3 — 用户与积分（只读 + 可选补分）

### 6.1 目标

按邮箱定位用户，查看积分余额与近期流水，支撑客服与 G1 相关排障。

### 6.2 列表 / 详情

**搜索**：`q` = 邮箱子串（normalize 后 lower LIKE），limit 默认 30。

**用户行：**

| 列 | 来源 |
|---|---|
| ID | `users.id` |
| 邮箱 | `email` |
| 积分 | `credits` |
| daily_quota | `daily_quota`（标注 legacy：credit_mode 下生成不走它） |
| 状态 | `status` |
| 注册时间 | `created_at` |

**流水（点开用户或同页下方）：**

| 列 | 来源 |
|---|---|
| 时间 | `credit_transactions.created_at` |
| delta | `delta`（+/-） |
| reason | `signup_bonus` / `generate` / `recharge` / `*_refund` / 未来 `admin_grant` |
| ref | `ref`（订单号等） |

默认最近 50 条。

### 6.3 可选写操作：补发积分（默认关闭或二次确认）

| 项 | 规格 |
|---|---|
| 开关 | `admin.allow_credit_grant` 默认 **false**；true 才显示表单 |
| 入参 | user_id、正整数 credits、备注 |
| 实现 | 事务：`UPDATE users SET credits = credits + ?` + `INSERT credit_transactions (…, reason='admin_grant', ref=备注)` |
| CSRF | 必须 |
| 审计 | 流水可追；后台 flash 显示新余额 |

**非默认开启**；文档与 UI 标明高风险。

### 6.4 验收

| 用例 | 期望 |
|---|---|
| 搜邮箱 | 命中用户与积分 |
| 流水 | 与 `credit_transactions` 一致 |
| grant 关闭时 | 无补分表单 |
| grant 开启 | CSRF + 到账 + 流水 reason=admin_grant |

---

## 7. Sprint 4 — 可选增强（P2）

| 项 | 说明 |
|---|---|
| 发布事件 | `publish_events` 近 N 条：slug、result、reason、时间 |
| 系统页 | 只读：`credit_mode`、`guest_generate_quota`、`signup_credits`、`user_fallback_daily_generate`、pay_enabled；链 PAY-RUNBOOK / GUEST-USER 文档 |
| 概览待办 | pending 订单 > 0 时红点或数字徽章 |

---

## 8. 安全与不变式

| 规则 | 说明 |
|---|---|
| 鉴权不变 | 无 token → 非本机 403；cookie ticket + 刷新 |
| CSRF | 所有写操作（渠道、未来 grant）必须 `admin_csrf_ok` |
| 密钥不回显 | 渠道编辑仍不返回完整 md5/RSA；留空表示不改 |
| 无多租户 | 单运营 token 足够；不在本期做 RBAC |
| POST 后 PRG | 避免刷新重复提交 |
| 不暴露内部路径 | 后台不写 data_dir 绝对路径到 HTML |

---

## 9. 样式与无障碍

- 复用现有 CSS 变量；侧栏新增 `.admin-shell` / `.admin-nav` / `.admin-main`  
- 焦点可见：`button:focus-visible` 已有模式可延续  
- 侧栏用 `<nav aria-label="后台模块">` + 链接或 `button` 列表  
- 表格宽屏完整、窄屏 `overflow:auto`（现有）  

---

## 10. 测试与验收清单

### 10.1 手工

1. 登录 → 概览 KPI 与现网数量级一致  
2. 页面 tab：搜索 slug、打开外链、下载 HTML  
3. 渠道：新建/编辑/启停/删除；缺密钥不可启用  
4. 订单：筛 pending；与 DB 抽查一致  
5. 用户：搜邮箱；流水含 signup/recharge/generate  
6. CSRF 错误 token → 拒绝  
7. 窄屏 tab 切换  

### 10.2 自动化（建议）

```bash
# 结构/安全回归（扩展现有 pay 测试或独立脚本）
php scripts/test-pay-quota.php   # CSRF / admin_issue_session_cookie 仍过

# 新增（可选）scripts/test-admin-tabs.php：
#  - 源码：admin.php 含 tab 白名单
#  - 源码：channels 写路径仍 admin_csrf_ok
#  - CLI 模拟：orders 查询函数返回结构（若抽成 helper）
```

### 10.3 完成定义

| Sprint | Done when |
|---|---|
| **1** | 三 tab + 侧栏上线；渠道与页面能力零回归；按 tab 懒查询 |
| **2** | 订单只读列表可筛 status；无手动 fulfill |
| **3** | 用户+流水只读；grant 受配置开关约束 |
| **4** | 可选；有则文档勾选 |

---

## 11. 决策记录

| 决策 | 选择 | 理由 |
|---|---|---|
| 导航形态 | **轻量侧栏 + 窄屏顶 tab** | 模块将 ≥3，侧栏比纯锚点滚动清晰 |
| 路由 | **单入口 `admin.php?tab=`** | 鉴权一处；改造量小于多文件独立鉴权 |
| 文件组织 | **partials/admin/** | 避免 1000+ 行单文件，又不引入框架 |
| 订单 fulfill | **不做 UI 按钮** | 保留 notify + reconcile 主路径，防双写 |
| 积分补发 | **默认关** | 资金相关，需显式配置 |
| 多角色 | **不做** | 单运营 token 足够 |

勾选落地：

- [x] Sprint 1 壳与三 tab  
- [x] Sprint 2 订单  
- [x] Sprint 3 用户积分  
- [ ] Sprint 3b 开启 `admin.allow_credit_grant`（运营决策）  
- [ ] Sprint 4 发布事件 / 系统页（可选，本期未做）  

---

## 12. 文件索引

| 路径 | 职责 |
|---|---|
| `admin.php` | 入口：鉴权、tab 路由、POST 分发 |
| `includes/admin_security.php` | Cookie 票据、CSRF、登录锁定 |
| `includes/pay.php` | `pay_channel_*`、订单相关 helper |
| `includes/quota.php` | 积分扣充（grant 若做则复用事务风格） |
| `partials/admin/layout.php` | 侧栏壳（新建） |
| `partials/admin/*.php` | 各 tab 视图（新建） |
| `docs/ADMIN-DASHBOARD.md` | 初版观察台设计（历史） |
| `docs/ADMIN-NEXT-DEV.md` | **本文** |
| `docs/PAY-RUNBOOK.md` | 支付运营排障 |
| `scripts/pay-reconcile.php` | 订单对账 CLI |

### 12.1 建议配置键（Sprint 3+）

```php
// config.php / docs/config.example.php
'admin' => [
    'token' => '...',
    'max_attempts' => 8,
    'lock_seconds' => 900,
    'allow_credit_grant' => false, // Sprint 3 可选
],
```

---

## 13. 与初版文档关系

| 文档 | 关系 |
|---|---|
| `ADMIN-DASHBOARD.md` | 定义访问像素、只读观察、鉴权基线 → **仍然有效** |
| `ADMIN-NEXT-DEV.md` | 在基线上做 **IA 拆分与排障模块**，不推翻鉴权与 visit 模型 |

实施 Sprint 1 后，建议在 `ADMIN-DASHBOARD.md` 顶部增加一行：

> 模块导航与订单/用户扩展见 `ADMIN-NEXT-DEV.md`。

---

## 14. 实现顺序建议（给执行者）

1. 抽 `layout.php` 样式与侧栏，先把 **现有 HTML 原样** 塞进 `pages`+`channels` 仍同页渲染（中间态可接受，快速验证壳）  
2. 再切懒查询与三文件  
3. 渠道 POST 改为 PRG → `tab=channels`  
4. 加 orders / users  

**风险：** 一步大挪移易回归 CSRF/登录 PRG；优先「壳先动、查询后拆」。

---

*文档版本：2026-07-26 · 分析结论落地为可执行规格，尚未编码*
