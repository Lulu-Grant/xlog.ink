# xlog.ink V2 逻辑复审后续开发文档

> 制定日期：2026-07-12
> 代码基线：`d93ee4c`
> 依据：大型逻辑合理性复审、73 项动态测试、`5.189.149.76` 生产环境核对
> 目标：修复编辑可靠性、内容安全、真实 IP、邮件 token、历史资源和并发一致性问题，不改变既定产品方向。

---

## 1. 当前结论

主创建链路已经具备上线运行基础：会话归属、额度预扣与失败退款、发布锁、上传限制、HTML 校验、SSE 流式回退和三语界面均已形成闭环。后续开发不需要重做架构，重点是修复以下六类逻辑缺口：

1. 编辑会话生成时没有稳定携带原页面 HTML。
2. AI Moderation 没有区分“成人内容可加门”与“必须拒绝的内容”。
3. Cloudflare 后真实客户端 IP 未恢复，影响全部按 IP 的安全和额度逻辑。
4. 邮件发送失败会提前作废旧 edit token。
5. 搬家后存在历史图片资产缺失，部分 live 页面图片 404。
6. 会话消息整段 JSON 回写存在并发覆盖风险。

本轮继续遵守以下既定产品决策：

- 普通创建会话再次生成时创建新页面、新 URL，不覆盖上一页。
- 只有邮箱 token 或登录用户 owner edit 才能覆盖原 slug。
- 聊天动作只由 AI 的结构化 ACTION 标记触发，不增加用户文本关键词兜底。
- 成人内容只由 AI Moderation 判断，不使用预设关键词、正则或本地词库。
- 页面截图是发布后的展示增强，不是发布成功条件。

---

## 2. 开发优先级

| 阶段 | 目标 | 上线要求 |
|---|---|---|
| P0 | 修复线上历史资源、真实 IP 和部署目标 | 必须先完成 |
| P1 | 修复编辑上下文、Moderation 分类和邮件 token | 下一版本必须完成 |
| P2 | 修复消息并发、图片 URL 下载安全 | 放量前完成 |
| P3 | 清理 QR/死代码、SEO 基础文件和运维噪音 | 随版本收口 |

---

## 3. P0：生产数据与基础设施修复

### 3.1 历史 `site-assets` 完整迁移

当前生产环境至少有以下页面引用不存在的 `/site-assets/97o2lmm9ie/1.webp`：

- `site/97o2lmm9ie.html`
- `site/pagejnc.html`
- `site/y4o6a2cbui.html`

处理方式：

1. 从旧服务器或迁移备份增量同步整个 `site-assets/{slug}/`，不得清空新服务器已有目录。
2. 同步后按数据库 `images.path` 检查文件是否存在。
3. 扫描全部 `site/*.html` 中的 `/site-assets/` URL，生成缺失资源报告。
4. 对无法找回的历史资源只做告警，不自动删除页面，也不替换为假图。
5. 新增只读脚本 `scripts/audit-page-assets.php`，输出：页面、引用 URL、文件状态、数据库记录状态。

建议迁移命令采用 `rsync -a --ignore-existing`，避免覆盖新服务器已经生成的图片。

验收标准：

- 数据库中所有现存 `images.path` 都有对应文件，或进入明确的缺失清单。
- 线上错误日志不再持续出现可恢复资产的 404。
- 旧 `site/*.html` 和新页面均保持可访问。

### 3.2 恢复 Cloudflare 真实客户端 IP

优先在 Nginx 完成真实 IP 解析，不在 PHP 中直接信任任意请求头：

```nginx
real_ip_header CF-Connecting-IP;
real_ip_recursive on;
set_real_ip_from <Cloudflare IPv4/IPv6 CIDR>;
```

实现要求：

- `set_real_ip_from` 使用 Cloudflare 官方完整 CIDR，并建立定期更新流程。
- PHP 看到的 `REMOTE_ADDR` 必须是最终访问者 IP。
- 未经过 Cloudflare 可信网段的请求不得信任 `CF-Connecting-IP`、`X-Real-IP` 或 `X-Forwarded-For`。
- Nginx access log 同时保留解析后的 IP 和原始代理地址，便于排障。
- `includes/helpers.php` 保留可信代理防护，但不再依赖空的环境变量完成 Cloudflare 解析。

受影响逻辑：游客额度、session IP 回退、登录验证码限频、后台防爆破、访问统计、Turnstile remote IP。

验收标准：

- 两台不同网络设备访问时，服务端记录为两个真实 IP。
- 伪造 `CF-Connecting-IP` 直连源站不能改变 `client_ip()`。
- 同一用户刷新不会因为 Cloudflare 节点变化而切换额度身份。

### 3.3 更新部署脚本

修改 `scripts/deploy-code.sh`：

- 默认目标更新为 `root@5.189.149.76`。
- 不再内置旧密钥路径；密钥通过 `XLOG_DEPLOY_KEY` 提供，或使用 SSH config。
- `StrictHostKeyChecking=no` 改为 `accept-new`，生产主机指纹变更时必须中止。
- 继续排除 `data/`、`site/`、`site-assets/`，代码发布不得覆盖运行数据。
- 增加部署前目标主机和目录回显，要求显式确认环境变量或使用 `--check` 只读模式。
- 增加远端 PHP 8.0+ 检查，而不是调用系统默认 PHP 7.4。

代码部署与数据迁移必须保持两个独立流程；部署脚本不负责同步历史生成页和用户资产。

---

## 4. P1：核心业务逻辑修复

### 4.1 编辑生成上下文重构

#### 现状问题

`page_edit_seed_messages()` 将原页面 HTML 放进内部 user message；生成阶段又过滤“当前页面信息”。简中和繁中会完全丢弃原 HTML，英文只保留被截断的前 3000 字符，导致编辑行为依赖语言且容易退化为重新设计。

#### 实现方式

不要再依赖聊天消息携带完整旧页面。发布时根据已校验的 `$editPage` 重新读取原文件：

1. 新增 `build_edit_page_context(array $editPage)`。
2. 从 `html_path` 读取页面并调用 `clean_generated_html_for_edit()`。
3. 单独构造结构化生成上下文：`url/title/type/lang/current_html/requested_changes`。
4. 当前 HTML 设置独立预算，建议 24,000 字符；超出时保留 `<head>`、主要内容和尾部结构，而不是简单只截开头。
5. `generation_context_messages()` 继续过滤 UI/系统事件，但不负责保留旧 HTML。
6. 三种 locale 使用完全相同的数据结构，仅提示文本本地化。
7. 发布前再次校验 owner/token edit 权限，保持原 slug 覆盖语义。

生成 prompt 必须明确：基于当前 HTML 修改、保留用户未要求改变的内容、只输出一份完整 HTML。

验收标准：

- 简中、繁中、英文编辑同一页面时获得一致结果。
- 只要求修改标题时，正文、图片、链接和整体结构不会无故丢失。
- 编辑不上传新图片时旧图片继续可访问。
- 普通创建会话二次生成仍然产生新 URL。

### 4.2 AI Moderation 结果分层

#### 原则

本功能只使用 AI Moderation 的结构化分类结果。禁止增加关键词列表、正则成人词检测或前端文字猜测。

#### 新结果结构

```php
[
    'status' => 'ok|unavailable|error',
    'adult_score' => 0.0,
    'sexual_minors_score' => 0.0,
    'is_adult' => false,
    'must_block' => false,
    'categories' => [],
    'reason' => '',
]
```

处理规则：

- 普通成人性内容达到阈值：允许发布，但注入 18+ gate。
- `sexual/minors` 达到低风险阈值或被服务端分类为 true：直接拒绝发布并退款。
- Moderation 未配置、超时、响应异常或无结果：`status != ok`，暂停发布并退款，不按安全内容放行。
- 文字和图片分别审核，最终取更严格结果。
- 审核结果写入 `publish_events`，仅保存类别、分数和状态，不记录不必要的敏感原文。

建议在调用昂贵的 HTML 生成模型前完成文字和现有图片审核；生成后的 HTML 再做一次必要的最终校验。

验收标准：

- 成人内容由 AI 判定后正常出现 18+ gate。
- `sexual/minors` 不会仅添加成人门，而是拒绝并退还额度。
- 关闭 Moderation key 或模拟超时时，页面不会发布、额度不会损失。
- 测试中不存在任何关键词成人判断代码。

### 4.3 邮件 edit token 原子恢复

#### 目标

SMTP 失败不能作废用户已有修改链接，也不能把首次绑定页留在“editable=1 但用户没有 token”的状态。

#### 实现方式

1. 更新前读取旧的 `email/editable/token_hash/updated_at`。
2. 生成新 token 和 hash，条件更新页面。
3. 调用 SMTP。
4. 发送成功后记录 `mail_events` 和 session 系统事件。
5. 发送失败时执行条件回滚：仅当数据库当前 `token_hash` 仍等于本次新 hash 时恢复旧值，避免覆盖并发产生的更新。
6. 对外返回稳定错误码 `mail_send_failed`，不泄露 SMTP 细节；详细原因写入 error log。

`page-email.php` 和 `resend-edit.php` 必须复用同一服务函数，避免两套 token 生命周期再次分叉。

验收标准：

- 重发邮件失败后，旧修改链接仍有效。
- 首次绑定邮件失败后，页面恢复原 editable/email/token 状态。
- 两次并发重发不会由失败请求回滚成功请求的新 token。

---

## 5. P2：一致性与下载安全

### 5.1 会话消息并发一致性

短期修复：

- `append_session_message()` 使用 `BEGIN IMMEDIATE`，在同一事务内重新读取、追加、写回。
- 对 `SQLITE_BUSY` 做有限次数退避重试。
- `rewrite_session_message_asset_urls()` 与聊天 append 不得无条件覆盖更新后的消息数组。

长期结构：新增规范化 `session_messages` 表：

```sql
CREATE TABLE session_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    seq INTEGER NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(session_id, seq)
);
```

迁移步骤：

1. 将 `sessions.messages` JSON 幂等导入新表。
2. 新写入先双写一版，验证数据一致。
3. resume/chat/publish 改为从新表读取。
4. 稳定后停止更新 JSON 字段；旧字段暂不删除，保留一个版本回滚窗口。

验收标准：两个标签同时聊天、上传、绑定邮箱时所有事件都保留，消息顺序可解释且不重复。

### 5.2 AI 图片下载 SSRF 与体积保护

修改 `ai_download_image_url()`：

- 只允许 `https`。
- 优先配置供应商图片主机 allowlist。
- 每次 DNS 解析同时检查 IPv4/IPv6，拒绝 loopback、私网、链路本地、保留地址和云元数据地址。
- 不使用自动重定向；手动处理每次 3xx，并对新地址重新执行完整校验。
- 使用 curl write callback 流式累计，建议最大 20 MB，超限立即中止。
- 同时限制连接时间、总时间和最低可接受传输速度。
- 下载后继续通过实际图片解码验证 MIME，不只信任响应头。

验收标准：

- 公共供应商图片 URL 正常下载。
- `127.0.0.1`、`::1`、RFC1918、`169.254.169.254` 及重定向到私网均被拒绝。
- 超过限制的响应不会完整进入 PHP 内存。

---

## 6. P3：清理与运维收口

### 6.1 删除过期 QR 契约

- 移除 `index.php` 对 `qrcode.min.js` 的加载。
- 删除发布结果中的 `qr_payload`。
- 更新 `README.md`、`V2-DESIGN.md` 和 API 示例，不再描述二维码交付。
- 确认旧前端缓存不会因字段删除报错；必要时先保留一个版本但标记 deprecated。

### 6.2 清理死代码

- 确认无调用后删除 `includes/ratelimit.php`。
- 复查 `helpers.php` 中 V1 页面构建、JSONL 索引和旧 adult gate 辅助函数。
- 通过 `rg` 调用关系和全量回归确认后再删除，不做一次性大扫除。

### 6.3 生产路由与基础文件

从生产日志可见 `www.xlog.ink` 根路径、`robots.txt`、`sitemap.xml`、`404.html` 和部分旧 footer 请求持续产生 404。建议：

- `www.xlog.ink` 301 到 `https://xlog.ink/`。
- 增加真实 `404.html`，避免错误页自身再次 404。
- 根据页面公开策略生成 `robots.txt` 和 `sitemap.xml`。
- 对旧 `/partials/footer*.html` 评估是恢复兼容文件还是从历史页面移除引用。

---

## 7. 文件改动范围

| 模块 | 预计文件 |
|---|---|
| 编辑上下文 | `includes/page_edit.php`、`api/publish.php`、`prompts/gen-system.txt` |
| Moderation | `includes/ai.php`、`includes/content_tools.php`、`api/publish.php`、`api/upload.php`、`api/image-generate.php` |
| 邮件 token | `includes/mailer.php` 或新增 `includes/edit_tokens.php`、`api/page-email.php`、`api/resend-edit.php` |
| 消息一致性 | `includes/db.php`、`api/chat.php`、`api/session.php`、迁移脚本 |
| 图片下载安全 | `includes/ai.php`、`docs/config.example.php`、诊断脚本 |
| 部署与数据 | `scripts/deploy-code.sh`、新增 `scripts/audit-page-assets.php`、Nginx 配置文档 |
| 遗留清理 | `index.php`、`api/publish.php`、`README.md`、`docs/V2-DESIGN.md` |

---

## 8. 测试计划

### 8.1 静态检查

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node --check js/ai-app.js
node --check scripts/capture-page.js
git diff --check
```

### 8.2 核心回归

- 游客：session → chat → upload/image-generate → publish → screenshot → email。
- 注册用户：验证码登录 → 我的页面 → owner edit → 原 slug 覆盖。
- token 用户：绑定邮箱 → edit link → 修改 → 原 slug 覆盖。
- 同一普通会话二次发布：新 slug，旧页面不变。
- 三语编辑：原页面上下文一致，不因 locale 丢失。
- Moderation：成人 gate、必须拒绝、审核不可用三条路径。
- 邮件：SMTP 成功、失败、并发重发及旧 token 保留。
- 并发：两个 PHP-FPM 请求同时追加消息、发布锁只放行一个。
- 资源：全部数据库图片、HTML 图片引用和磁盘文件一致。

### 8.3 生产验收

- `/data`、`/includes`、`/prompts`、`/scripts` 继续不可访问。
- Cloudflare 后服务端记录真实访客 IP，伪造头无效。
- 新旧页面、图片、截图、访问统计均正常。
- PHP、Nginx 日志无持续业务 404、SMTP fatal 或 SQLite busy。
- 部署脚本 dry-run 明确显示 `5.189.149.76`，不会连接旧服务器。

---

## 9. 发布与回滚

1. 先备份 SQLite、`site/`、`site-assets/` 和当前 Nginx 配置。
2. 先完成历史资产增量同步，再上线真实 IP 配置。
3. P1 按“编辑上下文 → Moderation → 邮件 token”分三个独立提交，每个提交单独回归。
4. 数据库结构变更必须使用幂等迁移，旧字段至少保留一个版本。
5. Moderation 新逻辑上线后重点观察拒绝率、不可用率和退款事件。
6. 任一阶段异常时只回滚该阶段代码；不得回滚或删除新增页面与用户资产。

---

## 10. 完成定义

满足以下条件后，本轮逻辑复审问题才算闭环：

- P0、P1 全部完成且生产验证通过。
- 编辑页面能可靠保留旧内容并覆盖原 slug。
- 成人判定完全由 AI 完成，未成年人相关内容硬拒绝，审核故障不放行。
- SMTP 故障不会破坏已有 edit token。
- 所有按 IP 的功能基于真实访客 IP。
- 历史页面资产缺失有完整报告，可恢复资源已全部补齐。
- 并发消息不会丢失，AI 图片下载不能访问私网或无限占用内存。
- 73 项动态矩阵继续全通过，并新增本文件所列专项测试。
