# 账号 / 积分侧栏抽屉 — 下一步开发文档

> 依据：登录用户「我的」「积分」面板 UI/UX 分析（2026-07-26）  
> 范围：PC 右侧侧栏、手机侧滑叠层、统一 Drawer 壳、登录/账号/积分/我的页面视图迁入  
> 状态：可执行规格；按 P0 → P1 → P2 排期  
> 相关：`GUEST-USER-NEXT-DEV.md`（我的收敛）、`PAY-CREDITS-NEXT-DEV.md`（充值链路）

---

## 1. 背景与目标

### 1.1 当前形态（问题）

登录后顶栏：

```text
[+] [简|繁|EN] [积分 N] [我的]
```

点击后打开的是 **topbar 下方、聊天区上方的横向条带**：

| 面板 | DOM | 布局问题 |
|---|---|---|
| 登录 / 账号 | `#accountBox.login-panel` | `flex: 0 0 auto`，压矮 messages |
| 购买积分 | `#buyCreditsPanel` | `max-height: 320px` 横条 |
| 我的页面 | `#myPagesPanel` | `max-height: 280px` 横条 |

三者 `position: relative` + `border-bottom`，与主任务（对话）抢 **同一纵轴**；窄屏更挤。互斥仅靠 JS 关另一个，视觉上仍是「换一块顶条」。

### 1.2 本阶段目标

1. **PC**：点「我的」「积分」→ **右侧侧栏** 呈现，不再用顶栏下横条挤聊天。  
2. **手机**：侧栏 **滑入并叠在对话框与 composer 之上**（overlay + 遮罩）。  
3. **统一壳**：一个 Drawer + 多 view（login / account / credits / pages），避免三套动画。  
4. **功能零回归**：登录、退出、充值、我已支付、我的页面修改链路保持可用。  
5. **视觉贴合 TUI**：直角、1.5px 描边、无 blur/阴影；遮罩用半透明实色。

### 1.3 非目标（本期不做）

- 把主布局改成 IDE 式持久双栏（chat | sidebar 常驻）  
- 底部 Tab Bar / 全局导航重构  
- 毛玻璃、大阴影、第三方 Drawer 库  
- 改计费/配额业务逻辑（仅搬 UI 容器）  
- 游客积分 pill 强行可充（仍引导登录）

### 1.4 决策冻结（实现默认）

| 项 | 选择 | 理由 |
|---|---|---|
| 布局模式 | **方案 A：覆盖式右侧抽屉**（PC/手机同一模式） | 750px 窗内推挤式收益低；实现统一 |
| 壳数量 | **单 Drawer + view 切换** | 互斥与动画一处维护 |
| 「我的页面」 | **account 内二级 view**（带返回） | 避免第三个顶层横条 |
| 从账号点充值 | **同壳切到 credits** | 比关再开更顺 |
| 手机方向 | **右侧滑入**（与文案一致）；不做底 Sheet 为主方案 | |
| 动画 | `transform: translateX` ~220ms；`prefers-reduced-motion` 关闭位移动画 | |

---

## 2. 目标信息架构

### 2.1 触发与视图

| 触发 | 条件 | Drawer view |
|---|---|---|
| `#loginToggle` | 未登录 | `login` |
| `#loginToggle` | 已登录 | `account` |
| `#quotaText` 点击 | 已登录且 `pay_enabled` | `credits` |
| `#quotaText` 点击 | 游客 | 不打开充值；可选 toast「登录后充值」（P1） |
| 账号内「我的页面」 | 已登录 | `pages`（二级，可返回 account） |
| 账号内「充值积分」 | 已登录 | `credits` |
| 对话卡 `data-open-buy-credits` | 已登录 | `credits` |
| 对话卡 `data-open-login` | 游客 | `login` |
| 关闭 × / 遮罩 / Esc / 再点同一入口 toggle | — | `closed` |

### 2.2 视图内容规格

**login**

- 现有邮箱 → 验证码两步（`#loginEmail` / `#loginCode` 等，id 可保留）  
- `#loginHint`  
- 标题：登录 / Log in  

**account**

- 完整邮箱  
- 操作：我的页面、充值积分（`pay_enabled` 时）、退出登录  
- 标题：我的 / Account  

**credits**

- 说明文案（扣费 cost）  
- 套餐列表 + 渠道按钮  
- 支付状态行 +「我已支付」  
- 标题：购买积分 / Buy credits  
- 关闭按钮  

**pages**

- 列表（打开 / 修改）  
- 返回 → account（P0 可用关闭整抽屉；P1 必须有「返回」）  
- 标题：我的页面  

### 2.3 状态机

```text
drawer:  closed | open
view:    login | account | credits | pages

open(view):
  close sibling logic internalized
  set view, show backdrop, add .is-open / body.drawer-open
  aria-expanded on triggers
  focus → drawer title or first control

close():
  hide backdrop, remove .is-open
  body unlock scroll
  focus → last trigger button
```

互斥：任意时刻最多一个 view 可见；打开新 view 替换旧 view，不叠两个面板。

---

## 3. 布局与视觉规格

### 3.1 层级（建议）

| 层 | z-index | 说明 |
|---|---|---|
| chat / composer | 默认 | |
| topbar | 10（现有） | 可保持；抽屉可盖住或与 topbar 齐顶 |
| drawer backdrop | 35 | 盖住 chat + composer |
| app drawer | 40 | |
| toast | > 40 | 支付成功 toast 仍可见 |

### 3.2 PC（≥761px 或相对 `.app-window`）

```text
.app-window { position: relative; }  /* 若尚未设置 */

.app-drawer {
  position: absolute;
  top: 0; right: 0; bottom: 0;
  width: min(360px, 92%);
  border-left: var(--bw) solid var(--line-strong);
  background: var(--panel);
  transform: translateX(100%);
  transition: transform .22s ease;
  display: flex; flex-direction: column;
  overflow: hidden;
}
.app-drawer.is-open { transform: translateX(0); }

.drawer-backdrop {
  position: absolute; inset: 0;
  background: rgba(31, 30, 29, 0.35);
  /* 无 blur */
}
```

- 抽屉自 topbar **下方** 或 **顶满 app-window** 二选一；推荐 **顶满 app-window 右侧**（含对齐 topbar 高度内边距），视觉更干净。  
- 打开时 **不 reflow** messages 高度（覆盖式）。

### 3.3 手机（≤760px）

```text
.app-drawer, .drawer-backdrop {
  position: fixed; /* 相对 viewport，盖住 composer */
  /* safe-area：padding-bottom: env(safe-area-inset-bottom) */
}
.app-drawer {
  width: min(100vw - 48px, 360px); /* 左侧留出可点遮罩 */
  /* 或 width: 100% 仅顶栏下，但不推荐挡死返回 */
}
body.drawer-open {
  overflow: hidden;
  /* 可选：禁止背后 chat 触摸滚动 */
}
```

### 3.4 抽屉内部结构

```text
┌─ drawer-head ────────── × ─┐
│ 标题（随 view 变）          │
├─ drawer-body（overflow auto）┤
│  view content               │
└─────────────────────────────┘
```

- head：与现有 `panel-head` 风格一致（`┌ 标题 ┐` 可保留或简化）  
- body：积分列表、页面列表可滚；head 固定  

### 3.5 动效

| 项 | 值 |
|---|---|
| 时长 | 200–280ms |
| 属性 | `transform` only |
| 减弱动效 | `@media (prefers-reduced-motion: reduce) { transition: none }` |
| 打开 class | `.is-open` on drawer + backdrop 去掉 `[hidden]` 或用 `aria-hidden` |

---

## 4. 里程碑

```text
P0  统一 Drawer 壳 + login/account/credits 迁入 + 开闭/遮罩/Esc     0.5–1 天
P1  pages 二级 + 返回；账号→积分无闪断；焦点与 aria 打磨           0.5 天
P2  支付 return 自动打开 credits；手势关闭；游客 pill 文案           0.5 天
```

---

## 5. Sprint P0 — 壳与主路径（必做）

### 5.1 DOM 改造（`index.php`）

在 `.app-window` 内（建议紧挨 `</section>` 前或 topbar 后）增加：

```html
<div id="drawerBackdrop" class="drawer-backdrop" hidden></div>
<aside id="appDrawer" class="app-drawer" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
  <div class="drawer-head">
    <strong id="drawerTitle">…</strong>
    <button type="button" id="closeDrawer" aria-label="关闭">×</button>
  </div>
  <div class="drawer-body">
    <!-- 迁入或包裹现有节点，保留关键 id 以降低 JS 改动 -->
    <div id="drawerViewLogin" data-drawer-view="login" hidden>…accountBox 登录步骤…</div>
    <div id="drawerViewAccount" data-drawer-view="account" hidden>…accountRow…</div>
    <div id="drawerViewCredits" data-drawer-view="credits" hidden>…buyCredits 内容…</div>
    <div id="drawerViewPages" data-drawer-view="pages" hidden>…myPages 列表…</div>
  </div>
</aside>
```

**约束：**

- 尽量 **保留** `#loginEmail`、`#buyCreditsList`、`#myPagesList` 等 id，减少事件重绑。  
- 删除或不再使用顶栏下「横条」布局 class 的视觉表现；节点可搬进 drawer。  
- 旧的三个独立 panel 的 `border-bottom` 横条样式废弃。

### 5.2 JS API（`js/ai-app.js`）

```js
// 建议新增
function openDrawer(view) { /* view: login|account|credits|pages */ }
function closeDrawer() {}
function setDrawerView(view) { /* 仅切换内容，保持 open */ }
function isDrawerOpen() {}

// 替换/收敛
// toggleBuyCredits(true)  → openDrawer('credits')
// toggleMyPages(true)     → openDrawer('pages')
// loginToggle             → openDrawer(user ? 'account' : 'login') or close if same
```

**行为：**

| 调用 | 行为 |
|---|---|
| `openDrawer(v)` | 设标题 i18n、显示对应 view、backdrop、`.is-open`、`body.drawer-open` |
| 再点同一触发且已开同 view | `closeDrawer()`（toggle） |
| `closeDrawer` | 清 class、hidden、解锁滚动、还焦点 |
| Esc | `closeDrawer`（document keydown，仅 open 时） |
| backdrop click | `closeDrawer` |
| `enterOwnerEdit` 成功 | `closeDrawer` 后进会话 |
| 退出登录成功 | `closeDrawer` |
| 登录成功 | 可保持 open 并 `setDrawerView('account')`，或 close（推荐 **切到 account**） |

### 5.3 CSS（`css/page-ai.css`）

- 新增 `.app-drawer` / `.drawer-backdrop` / `.drawer-head` / `.drawer-body` / `.drawer-view`  
- `.app-window { position: relative; }`（确认）  
- 移动端 `@media (max-width: 760px)` fixed + safe-area  
- 废弃或覆盖：`.login-panel` / `.buy-credits-panel` / `.my-pages-panel` 作为横条的 `flex:0 0 auto` 规则（避免残留占位）

### 5.4 i18n

| key | zh-CN | 用途 |
|---|---|---|
| （复用）`myAccount` / `login` / `buyCreditsTitle` / `myPages` | 已有 | drawer 标题 |
| `close` | 已有 | × |
| 可选 `drawerBack` | 返回 | P1 二级返回 |

### 5.5 验收 P0

| 用例 | 期望 |
|---|---|
| PC 登录后点「我的」 | 右侧出现账号面板；messages **高度不被顶成横条布局** |
| PC 点「积分」 | 右侧积分面板；套餐可点 |
| 手机点「我的」 | 面板从右侧滑入，盖住对话与输入框；点遮罩关闭 |
| 游客点「登录」 | 同壳 login view |
| 互斥 | 我的与积分不同时两个横条；后开覆盖前开 |
| Esc / × / 遮罩 | 关闭 |
| 充值下单 + 我已支付 | 仍在 credits view 可用 |
| 退出 | 关抽屉，顶栏回游客 |
| 无障碍 | `aria-modal`、标题关联、触发钮 `aria-expanded`（至少 P0 标题与 close） |

---

## 6. Sprint P1 — 二级页面与路径抛光

### 6.1 pages 二级

- account →「我的页面」：`setDrawerView('pages')`，head 增加 **返回**（回 account）  
- × 仍关闭整个抽屉  
- 空态 / 加载 / 列表 UI 与现网一致  

### 6.2 账号 → 充值

- 不先 `close` 再 `open` 造成闪断；直接 `setDrawerView('credits')`  

### 6.3 焦点

- 打开：焦点进 close 或标题  
- 关闭：焦点回 `#loginToggle` 或 `#quotaText`（记录 `state.drawerTrigger`）  
- 可选：简易 focus trap（Tab 在抽屉内循环）— 有则加，无则记 P2  

### 6.4 验收 P1

| 用例 | 期望 |
|---|---|
| 我的 → 我的页面 → 返回 | 回到账号 view，抽屉未关 |
| 我的 → 充值 | 无整页闪白/闪关 |
| 修改某页成功 | 抽屉关闭，进入编辑会话 |

---

## 7. Sprint P2 — 回跳与细节

| 项 | 规格 |
|---|---|
| 支付 return | URL 带支付回跳参数时，登录态自动 `openDrawer('credits')` 并刷状态（对接现有 `handlePayReturnQuery`） |
| 手势 | 可选：抽屉内向右滑关闭（不强制） |
| 游客点 pill | toast 或 open login，文案「登录后可充值积分」 |
| reduced-motion | 已实现则勾选 |

---

## 8. 文件索引

| 路径 | 改动 |
|---|---|
| `index.php` | Drawer 壳 DOM；迁移/包裹现有 panel 内容 |
| `css/page-ai.css` | 抽屉与遮罩；废横条占位；移动 fixed |
| `js/ai-app.js` | `openDrawer` / `closeDrawer` / 替换 toggle*；Esc；body 锁滚 |
| `includes/i18n.php` | 标题/返回文案（按需） |
| `docs/UI-DRAWER-NEXT-DEV.md` | **本文** |

缓存：部署时 bump `page-ai.css?v=` 与 `ai-app.js?v=`。

---

## 9. 风险与测试

### 9.1 风险

| 风险 | 缓解 |
|---|---|
| iOS 键盘顶起飞出视口 | drawer-body 可滚；focus `scrollIntoView` |
| 支付外链回站丢面板 | P2 自动 open credits |
| 双滚动穿透 | `body.drawer-open { overflow: hidden }` |
| z-index 与预览卡 | 预览在 chat 内，抽屉 40 盖住即可 |
| id 搬迁漏绑事件 | 保留 id；P0 自测事件表 |

### 9.2 手工清单

1. PC：我的 / 积分开闭、遮罩、Esc  
2. 手机：叠层盖住 composer、遮罩关闭  
3. 登录全流程在 drawer 内完成  
4. 充值：选套餐 → 外跳 → 回站 → 我已支付  
5. 我的页面 → 修改 → 抽屉关、会话正确  
6. 退出后顶栏与 drawer 状态干净  
7. 窄屏 / 横屏抽测  

### 9.3 自动化（建议、可选）

```bash
# 结构断言 scripts/test-ui-drawer.php 或扩展现有 smoke：
# - index.php 含 id="appDrawer" drawerBackdrop
# - ai-app.js 含 openDrawer / closeDrawer
# - page-ai.css 含 .app-drawer.is-open
# - 无依赖「login-panel 横条 flex 占位」的关键路径
```

---

## 10. 完成定义

| Sprint | Done when |
|---|---|
| **P0** | 我的/积分/登录均在右侧覆盖式抽屉；手机 overlay；无顶栏下业务横条；充值与登录可用 |
| **P1** | pages 二级+返回；账号↔积分切换不闪；焦点基本正确 |
| **P2** | 支付回跳体验闭环；动效无障碍项勾选 |

### 10.1 决策勾选

- [x] 方案 A 覆盖式右侧抽屉（PC+手机）  
- [x] 单壳多 view  
- [x] 我的页面作二级（P1 完整返回）  
- [ ] 手势关闭（P2 可选）  
- [x] 支付 return 自动开 credits（P2，已实现）  

**实现状态（2026-07-26）：** P0+P1 已落地；`scripts/test-ui-drawer.php` 结构验收通过。

---

## 11. 实现顺序建议

1. CSS 壳 + 空 drawer 能开闭（绑临时按钮验证动画）  
2. 迁入 login/account，loginToggle 改 `openDrawer`  
3. 迁入 credits，quota + buy 按钮改路由  
4. 迁入 pages；互斥与 close 收敛  
5. 移动端 fixed 与锁滚  
6. P1 返回与焦点  
7. bump 静态资源版本并手工回归支付  

**原则：** 先搬容器、后抛光；业务 API 调用不改语义。

---

*文档版本：2026-07-26 · 分析结论落地为可执行规格，尚未编码*
