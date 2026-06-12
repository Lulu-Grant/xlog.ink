# 第二轮审计报告（AUDIT-2）

> 审计日期：2026-06-12 · 审计基线：commit `22b4150`
> 范围：全部后端 PHP、前端 JS/CSS、prompts、Nginx 片段
> 关联：[V2-DESIGN.md](V2-DESIGN.md)（总体设计）、[ACTION-ROUTING-PLAN.md](ACTION-ROUTING-PLAN.md)（动作标记协议）
> 上一轮审计：2026-06-11 口头报告（基线 `aa35b58`），其结论闭环情况见第一部分。

---

## 总评

结构没有跑偏，且较上轮明显变好。上轮 3 个高危项全部闭环，编辑流图片误删、验证码生命周期等逻辑 bug 也已修复，工程质量扎实。

本轮新发现问题集中在三类：**发布事件与对话历史的断点**（影响协议中 PUBLISH/EMAIL 动作的实际效果）、**流式与中断处理的体验/成本公平性**、**PUBLISH 自动发布对既定裁决原则的偏离**。无新增高危安全项。

---

## 第一部分 · 上轮审计闭环情况

| 上轮编号 | 项 | 状态 | 落地位置 |
|---|---|---|---|
| 高危 1 | `/data` 等目录可被直接下载 | ✅ 已修 | `docs/nginx-v2-snippet.conf`：`location ~ ^/(?:data\|includes\|prompts\|scripts)` 返回 404 + dotfile 屏蔽 |
| 高危 2 | 先生成后扣额度（成本放大攻击） | ✅ 已修 | `api/publish.php`：开头 `consume_quota`，失败/拒绝走 `refund_generate_charge`；`includes/quota.php` 增加 `refund_quota`、`BEGIN IMMEDIATE` |
| 高危 3 | 会话/上传无创建限制（磁盘耗尽） | ✅ 已修 | `session_create`（游客 50/天、用户 200/天）、`upload_image`（80/400/天）独立配额；upload 增加会话归属校验 |
| bug 5 | 编辑重生成删除旧图片资产 | ✅ 已修 | `includes/imageproc.php`：不再清空 finalDir，改为碰撞重命名 + 逐文件改写 HTML 与 DB |
| bug 4/6 | edit 流鉴权不一致 | ✅ 已修 | `sessions.edit_mode` 列 + `api/publish.php` 按 `edit_owner`/`edit_token` 分别校验 |
| 小项 | login_codes 不清理、验证成功不作废 | ✅ 已修 | `send-code.php` 清理过期；`verify.php` 成功后删除该邮箱全部验证码；发信失败回滚 |
| 小项 | WAL 无 busy_timeout | ✅ 已修 | `includes/db.php`：`PRAGMA busy_timeout=5000` |
| 小项 | 伪二维码误导 | ✅ 已修 | `js/ai-app.js` `drawQr`：失败显示提示文案，隐藏下载按钮 |
| UX 7/8/9/11 | 假窗口栏、死按钮、正则猜上传等 | ✅ 已修 | 语言/主题死按钮已删；上传卡改由 `[[ACTION:UPLOAD]]` 标记驱动 |

仍开放的遗留项见第五部分。

---

## 第二部分 · 新发现逻辑问题（建议修，按优先级）

### A1. 发布成功不写回对话历史 —— 当前最大逻辑断点

`api/publish.php` 成功后只更新 `sessions.state='done'`，不向 `messages` 追加任何记录。连锁后果：

1. `prompts/chat-system.txt` 第 7 条"重复生成"逻辑依赖 gemma 知道"页面已发布过"，但它只能从用户措辞里猜；
2. `[[ACTION:EMAIL]]` 标记已定义，但模型永远感知不到"发布后"这个时机，实际不可触发；
3. 发布后用户说"标题改一下"，gemma 不知道上一版内容。

**修法**：发布成功后 append 一条对话记录，例如：
`[系统] 页面已发布：https://{slug}.xlog.ink，标题《{title}》。用户接下来可能要求修改这一页或再做一个新页面。`
一行代码激活第 7 条 prompt 与 EMAIL 动作。

### A2. 流尾缓冲 160 字符过大，典型轮次等于无流式

`api/chat.php:32` 缓冲 160 字符才开始下发 delta。prompt 要求 gemma"简洁、一次只问 1-2 个问题"，典型回复 <100 字 → 用户盯着 typing 动画直到整段一次性出现。
最长标记 `[[ACTION:PUBLISH reason=20汉字]]` ≈ 46 字符（mb_strlen 计数）。**缓冲降到 64 即安全**。

### A3. 中断生成不退款：刷新/关页 = 白扣额度 + 白烧 sonnet

publish 由浏览器 SSE 连接驱动，无 `ignore_user_abort(true)`。生成中刷新 → PHP 在下一次 flush 被杀 → 异常路径走不到 → `refund_generate_charge` 不执行。额度已扣、token 已烧、页面没了。
**修法**：`ignore_user_abort(true)` 让生成在服务端跑完落盘（登录用户可在"我的页面"找回；配合 A5 游客刷新回来也能拿到 URL）。

### A4. 游客上传按 IP 锁会话，移动网络用户会中招

`api/upload.php` 的 `upload_session_allowed` 用 `hash_equals($session['ip'], client_ip())`。蜂窝网络 IP 漂移常见——聊到一半上传 403。
**修法**：会话创建时已发 `xlog_cid` cookie，改绑 cookie（需在 sessions 表记录创建时的 cid）。

### A5. 会话不持久：刷新 = 全部丢失 + 多扣一次 session_create

前端不存 session_id，每次刷新 `start()` 新建会话。对话、已上传图片、刚生成的 URL 全部丢失。
**修法**：session_id 存 sessionStorage + resume 接口回放 messages（数据本来就在库里）。当前 UX 链路性价比最高的改进。

### A6. 成人误判依旧，且现在"会传染"

`conversation_indicates_adult`（`api/publish.php`）仍是子串匹配——"**不是**成人内容"也命中；编辑流 `is_adult` 从原页面继承且只 OR 不清除（publish.php:101），一次误判永久打码，无 UI 可解。
**修法**：去掉子串猜测，只信两个来源——用户 18+ 勾选 + 编辑继承；确认卡允许取消勾选以清除标记。

---

## 第三部分 · 动作标记协议与提示词评审

实现质量高的部分：动作白名单、参数 key 白名单 + slot 枚举校验 + 120 字符截断、旧格式 `[READY]`/`[UPLOAD]` 兼容、下划线转空格、后端流尾剥离（前端不再接触标记原文）。

### B1. `PUBLISH` 动作偏离既定裁决原则（重要）

[ACTION-ROUTING-PLAN.md](ACTION-ROUTING-PLAN.md) 第 3.3 条："标记是建议，模型乱打最坏只是多浮一张卡"。现状：Turnstile 关闭时 `[[ACTION:PUBLISH]]` → `runAutoPublish` → 直接 `publish()`（`js/ai-app.js`）。模型误判或提示词注入即可直接消耗生成额度与 sonnet 成本。

**建议（双因子门）**：前端仅在**触发这条回复的用户消息本身命中 publishIntent 正则**时才执行自动发布，否则降级为生成确认卡。"模型说生成"+"用户确实说了生成"两个独立信号同时成立才放行。并把该偏离补进 ACTION-ROUTING-PLAN 决策记录。

### B2. 生产/开发行为分叉

Turnstile 开启时 PUBLISH 退化为确认卡（≈READY），生产大概率开 Turnstile → prompt 第 7 条 READY/PUBLISH 的精细区分在生产几乎不生效。
两个选择：接受现状（无害）；或将 Turnstile widget 常驻渲染、预取 token，让自动发布在生产也走通。

### B3. prompt 第 7 条对 26B 模型偏重

四个嵌套条件分支，gemma 量级容易顾此失彼。可压缩为两句：
"用户**明确说**现在生成/重新生成 → PUBLISH；只是**问**能不能生成 → READY。"

### B4. 标记剥离只处理末尾

`strip_chat_action_markers` 只剥末尾标记；模型把标记打在中间时原文会漏给用户。建议全文剥离作为兜底（显示层面；动作提取仍只认末尾）。

`gen-system.txt` 无问题；图片清单含 `slot` 字段，与上传链路闭环完整。

---

## 第四部分 · UX 链路评审

链路整体顺畅。预览卡"生成动画 → 流式预览 → 内嵌成品 + 交付面板"一卡到底是正确方向；邮箱环节的上下文式回复（聊天框直接输邮箱/说"不用了"均可识别）设计聪明。

| # | 项 | 建议 |
|---|---|---|
| C1 | 生成时三行系统消息堆叠（"开始生成"+"AI 正在生成"+预览卡状态行） | 前两条合并进预览卡头部状态 |
| C2 | 编辑模式入场只有一句"已进入修改模式"，看不到页面现状 | 入场时用 `finalizeLivePreview` 嵌入当前页面 |
| C3 | `renderDelivery` 已无调用方（死代码）；`ensureLivePreviewCard` 的 `delivery-panel` 少一个闭合 div（innerHTML 自动补全，未炸） | 清理 |
| C4 | `publish_failed` 把 `$e->getMessage()` 原样发给用户，可能含服务器路径 | 生产映射为友好文案，原文进 error_log |

---

## 第五部分 · 遗留开放项（上轮提出，仍未修）

| # | 项 | 严重度 |
|---|---|---|
| D1 | `send-code` 无 per-IP 上限，单 IP 可对海量不同邮箱发信（损害发信域名信誉） | 中 |
| D2 | 登录成功后无 `session_regenerate_id()`（会话固定） | 中 |
| D3 | GD 路径无 EXIF 方向校正（Imagick 路径有 autoOrient），无 Imagick 的服务器手机照片横躺 | 中 |
| D4 | `infer_page_type` 子串匹配粗糙；`pages.lang` 硬编码 zh-CN（可从生成 HTML 的 `<html lang>` 提取） | 低 |

---

## 第六部分 · 修复顺序建议（投入产出排序）

1. **A1 发布事件写回对话历史** —— 一行代码，激活"同会话多页面"与 EMAIL 动作
2. **A5 会话持久化 + resume** —— 十几行，消灭最伤的 UX 黑洞
3. **A3 `ignore_user_abort` + A2 流尾缓冲 64** —— 两处小改，流式体验与成本公平性
4. **B1 PUBLISH 双因子门** —— 保住架构裁决原则（含文档决策记录更新）
5. A4 / A6 / C1-C4 / D1-D4 按表逐项清

---

## 附 · 结构走向结论

- 与 V2-DESIGN.md 对照：三级用户/额度、双模型路由、子域分发、编辑回路、积分预留全部按图实施，无偏移。
- 与 ACTION-ROUTING-PLAN.md 对照：协议实现忠实，唯一偏离是 B1（PUBLISH 自动发布），需补决策记录或加双因子门。
- 新增于设计之外（合理演进，建议补录进文档）：`session_create`/`upload_image` 配额、file-backed PHP sessions、`publish_events`/`mail_events` 审计表、交付面板并入预览卡。
