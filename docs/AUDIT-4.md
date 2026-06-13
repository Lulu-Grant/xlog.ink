# 第四轮综合审计报告（AUDIT-4）

> 审计日期：2026-06-13 · 审计基线：commit `576b572`（Rebuild V2 AI page generation flow）
> 范围：本次 rebuild 新增/改动的全部代码 + UI/UX + 逻辑健壮性 + 框架外行为
> 关联：[V2-DESIGN.md](V2-DESIGN.md)、[ACTION-ROUTING-PLAN.md](ACTION-ROUTING-PLAN.md)、[AUDIT-2.md](AUDIT-2.md)、[AUDIT-3 口头]、[LOCAL-AUDIT-2026-06-13.md](LOCAL-AUDIT-2026-06-13.md)
> 方式：全量代码重读 + 浏览器实测（DOMAIN / IMAGE_GEN 新动作卡链路、横向溢出、控制台）+ 全项目 `php -l` / `node --check` 通过

---

## 总评

本次 rebuild 新增了五块能力：**自定义二级域名前缀、AI 配图生成、自动成人内容判断（关键词+视觉审核）、SEO/OG 注入、页面整页截图导出**，并为每块建了配套字段、额度、i18n、动作标记。工程完成度高，前几轮的高危项依然闭环（消息长度钳制 4000、caption 200、cookie 归属、退款、流尾缓冲、EXIF 全方向都在）。新动作 `DOMAIN`/`IMAGE_GEN` 链路实测可用。

但这次 rebuild 在**自己定下的两条架构纪律上发生了回退**，并引入了 **1 个新的真实安全缺口**和几个体验/健壮性问题。下面按严重度排。

---

## 一、架构纪律回退（重点）

### R1. PUBLISH 双因子门被移除 —— 违反已立项的决策记录

[ACTION-ROUTING-PLAN.md](ACTION-ROUTING-PLAN.md) 第 181 行的决策记录白纸黑字写明：
> PUBLISH 自动生成门槛 = 模型 `PUBLISH` + 用户当前轮明确生成语义

本次 rebuild 把 `js/ai-app.js` 的 `handleAction` 改成：
```js
else if (action.type === 'publish') state.pendingAutoPublish = params;  // 无条件
```
并**整段删除了 `isPublishIntent()` 函数**。现在只要 gemma 在回复里吐出 `[[ACTION:PUBLISH]]`，前端就直接进入真实生成（消耗额度 + 烧 sonnet），不再要求"用户当前消息确有生成意图"这个第二因子。

- 生产开 Turnstile 时退化为确认卡（`runAutoPublish` 分支），有人工兜底；
- **但 Turnstile 关闭时，弱模型一次误判 / 提示词注入即可单方面触发付费生成** —— 这正是当初设这道门要防的事。

**建议**：二选一并同步文档——① 恢复双因子门；② 若确实要"模型说生成就生成"，把 ACTION-ROUTING-PLAN §3.3 和决策记录改掉，并强制 PUBLISH 路径在任何环境都先过一次确认卡（哪怕 Turnstile 关闭），不要静默直发。

### R2. 成人内容判断回到关键词自动判定，且移除了用户手动开关 —— AUDIT-2 A6 的修复被反转

AUDIT-2 A6 当时的结论是：去掉子串猜测，成人标记只信"用户勾选 + 编辑继承"，并在确认卡保留可取消的勾选框作为逃生口。AUDIT-3 之后这条已落地。

本次 rebuild：
- 重新引入自动判定 `assess_session_adult()`，基于 `adult_keyword_score()` 对**整段对话文本**做子串匹配（`content_tools.php`）；
- 确认卡里的 `inline-adult-checkbox` **被删除**，换成一行只读提示 `adultAutoNotice`；
- publish 不再读 `data.is_adult`。

后果：关键词误判全面回归且**无逃生口**。用户说"这页**不是色情**内容""我们卖**内衣**""泳装新品发布"——`色情`/`内衣`/`泳装`命中即 score≥0.55（强词 0.72／软词 0.25 累加），页面被强制成人门，用户无任何手动撤销入口。编辑流虽有 `adultFlagCleared`，但只要关键词还在对话里，重新生成时 `assess` 仍会判回成人，清不掉。

视觉审核（OpenAI moderation）只在配置了 moderation key 时生效；未配置时**纯靠关键词**，误判面最大。

**建议**：① 关键词分降级为"软信号"，仅当 `score` 很高（如 ≥0.85）或视觉审核命中才自动加门；② 恢复确认卡上的手动开关（既能加也能去），让用户对自己页面的成人标记有最终控制权；③ 软词（性感/私密/内衣/泳装/bikini）默认不计分或权重砍到可忽略——这些是大量正常电商/活动页的常用词。

---

## 二、新增高危：二级域名前缀无保留字黑名单

### H1. 用户可抢注基础设施 / 仿冒子域名

`includes/content_tools.php` 的 `slug_clean()` 和 `api/domain-check.php` 只做 `[^a-z0-9]` 过滤 + 长度检查 + `slug_exists()` 占用检查，**没有任何保留字黑名单**。用户可把页面前缀指定为 `www`、`mail`、`api`、`admin`、`app`、`static`、`cdn`、`assets`、`ns1`、`ftp`、`login`、`pay`、`vip`、`blog` 等。

风险：
- `www.xlog.ink` 被某个游客页面占据 → 主站门面被一个 AI 生成页顶替；
- `mail.xlog.ink` / `api.xlog.ink` / `login.xlog.ink` 等被抢注 → 钓鱼面（"在 login.xlog.ink 上输入你的验证码"），因为它确实是官方裸域的子域，欺骗性极强；
- 与未来要上的基础设施子域（CDN、状态页、API 网关）撞名。

通配符 DNS `*.xlog.ink` 把所有子域都指到本服务，nginx 按 host 取 slug 映射 `/site/{slug}.html`，所以一旦 DB 里有这个 slug，对应子域立即生效。

**建议**：在 `slug_clean`/`generate_semantic_slug`/`domain-check` 三处共用一个保留字集合（至少：www mail smtp imap pop ftp ns1 ns2 api app admin static cdn assets img cls login signin signup pay vip help support status blog dev test staging edit），命中即拒绝或强制走随机后缀。这是上线前应堵的唯一新增高危。

---

## 三、逻辑 / 健壮性

### M1. 整页截图同步阻塞发布请求，且依赖生产未必有的 node+playwright
`capture_page_image()`（`content_tools.php`）在 publish 的 SSE 流程里**同步** `exec(node capture-page.js …)`，`waitUntil:networkidle` + 20s 超时。每次发布都串行等截图，给本就漫长的生成流程再叠加最多 20s 阻塞；`screenshot.enabled` 默认 `true`，生产若无 node/playwright，则每次发布都白跑一次失败 exec（虽有 try 容错，返回 null 不致命）。og:image 因此可能拿不到。
**建议**：截图移出主流程改为发布后异步任务（cron 扫 `screenshot_path=''` 的页面补截），或至少在 node 不可用时（一次探测后缓存结果）跳过，不要每次 exec。

### M2. 内部事件消息在会话恢复时会泄漏到可见对话
`renderStoredMessage`（`js/ai-app.js`）的过滤清单没跟上本次新增的两类事件：
- `api/domain-check.php` 以 **user 角色** 追加 `[系统事件] 用户指定二级域名前缀：xxx` —— 过滤只拦 `role==='system'` 的 `[系统事件]`，user 角色漏网；
- `api/image-generate.php` 追加 `[图片已生成: …]` —— 过滤只认 `[图片已上传:`。

刷新恢复会话后，这两条内部文本会作为普通气泡显示给用户。
**建议**：把 domain-check 的事件改成 system 角色（与 publish 事件一致），并给 `renderStoredMessage` 补 `[图片已生成:` 与 user 角色 `[系统事件]` 两条过滤。

### M3. AI 配图失败回退到"占位图"会被当成真图发布
`image_create_generated_placeholder()` 在无 image key 或生成失败时，用 GD 画一张带文字的色块占位图并入库（source=`generated`），它和真实生成图一样进图片清单、可成为 hero/og:image。用户以为生成了配图，最终页面上却是一张色块。
**建议**：占位图仅用于本地 mock；生产无 image key 时应直接报错"配图功能暂不可用"，不要把占位图混进发布素材。或在卡片上明确标注"示意占位图"。

### M4. 视觉审核异常时的"失败开放"（fail-open）
`assess_uploaded_image_adult` / `assess_generated_image_adult` 在 moderation 调用抛错时仅 `error_log` 并回退到关键词分（可能为 0）。即上游审核服务挂掉时，成人图按"干净"放行。对一个面向公开分发的平台，成人内容漏判的代价高于误判。
**建议**：审核服务异常时，对图片型内容采取保守策略（标记待审/降级为需人工确认），而非静默放行。

### M5. 自定义 slug 把语义信息泄漏 + 可枚举
`slug_base_from_text` 会用对话里的英文词/类目做 slug 前缀（如 `coffee`+随机3位）。语义化 slug 对分享友好，但也让 URL 暴露页面主题、且前缀空间缩小（7 位语义 base + 3 位随机），相比纯 10 位随机，被枚举/猜测的面变大。属可接受的权衡，仅记录。

---

## 四、UI / UX

| # | 项 | 说明 |
|---|---|---|
| U1 | 成人门移除手动开关 | 见 R2，UX 上用户失去对自己页面成人标记的控制权，是本轮最大的体验倒退 |
| U2 | DOMAIN/IMAGE_GEN 卡片实测正常 | 浮现时机、预填、focus、无横向溢出均 OK（实测通过）|
| U3 | 配图生成等待无进度反馈 | `image-generate.php` 同步调用 gpt-image（up to 180s），前端点"确认生成"后只是按钮 disabled，无 `[..]` 进度或预计时长，长等待易被当成卡死 |
| U4 | 占位图圆角被强制为 0 | `border-radius:0` 内联，符合 TUI 主题 ✓ |
| U5 | 域名保存成功仅一条 assistant 文本 | 没有把最终选定的 URL 以可复制的形式呈现；用户记不住自动加的随机后缀 |

---

## 五、框架外行为系统盘点（本轮新增维度）

| 用户行为 | 结果 | 判定 |
|---|---|---|
| 自定义前缀填 `www`/`admin`/`api` | 直接占用对应子域 | ✗ H1 |
| 自定义前缀填超长/带符号 | `slug_clean` 截 10 位 + 去符号 | ✓ |
| 自定义前缀已被占用 | 自动加 3 位随机后缀，最终保证唯一 | ✓ |
| 描述含"内衣/泳装/不是色情" | 关键词命中→强制成人门，无法撤销 | ✗ R2 |
| 要求生成配图但后端无 image key | 回退色块占位图当真图发布 | ✗ M3 |
| 审核服务超时 | 图片按"干净"放行 | △ M4 |
| 发布时触发整页截图 | 同步阻塞最多 20s | △ M1 |
| 刷新恢复带域名/配图事件的会话 | 内部事件文本泄漏到气泡 | ✗ M2 |
| gemma 单方面吐 PUBLISH（Turnstile 关） | 直接付费生成 | ✗ R1 |
| 超长消息 / 超长 caption | 4000 / 200 钳制 | ✓ |

---

## 六、修复优先级建议

**上线前必修**
1. **H1 二级域名保留字黑名单** —— 防基础设施抢注 / 钓鱼子域（一个共享数组，三处引用）
2. **R1 PUBLISH 门** —— 恢复双因子，或强制确认卡兜底；同步决策文档
3. **R2 成人判定** —— 提高自动判定阈值 + 恢复手动开关 + 砍软词权重

**尽快**
4. M2 会话恢复事件泄漏（两条过滤 + 一处角色改 system）
5. M3 占位图不混入生产发布
6. M4 审核 fail-open 改 fail-safe

**迭代**
7. M1 截图异步化；U3 配图进度反馈；U5 交付选定 URL 可复制

---

## 七、与设计文档的一致性结论

- 五个新功能本身实现质量高，与 SQLite schema / 额度 / i18n / 动作协议的接入方式都规范，属于设计的合理外延（建议补录进 V2-DESIGN.md 第二、三部分）。
- **但 R1、R2 是对项目自己已立项决策的静默回退** —— 这是本轮最需要正视的：代码改了，但 ACTION-ROUTING-PLAN.md 的决策记录和 AUDIT-2 的结论没同步，形成"文档说一套、代码做另一套"。无论最终选哪条路线，都应让代码与决策文档重新对齐，否则下一轮审计（和未来的你）会反复在同一处打转。
- H1 是纯新增功能（自定义域名）带来的纯新增攻击面，前几轮不存在，属本轮必须新堵的口子。
