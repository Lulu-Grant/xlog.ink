# xlog.ink V2 逻辑复审整改验收记录

> 验收日期：2026-07-12
> 开发依据：`docs/LOGIC-REVIEW-FOLLOWUP-2026-07-12.md`
> 生产主机：`5.189.149.76`
> 结论：P0-P3 代码与生产配置整改完成；历史迁移缺失图片已形成不可恢复清单，等待旧服务器或离线备份重新可用。

## 1. 已完成范围

- 编辑生成改为发布时读取原页面并构造统一的结构化编辑上下文，三种语言不再走不同 HTML 截断路径。
- 成人与未成年人风险完全由 AI Moderation 结构化结果判断；未成年人性内容硬拒绝，Moderation 不可用时停止发布并退款。
- 邮件修改链接统一服务函数；SMTP 失败按当前 token hash 条件恢复旧 email、editable 和 token。
- 会话消息追加和图片 URL 回写使用 SQLite `BEGIN IMMEDIATE` 与有限重试，避免整段 JSON 并发覆盖。
- AI 图片远程下载限制为 HTTPS，逐跳校验 DNS/重定向，拒绝私网、链路本地和保留地址，并限制 20 MB。
- Nginx 已恢复 Cloudflare 真实客户端 IP，并拒绝直接信任非 Cloudflare 来源的转发头。
- 部署目标更新到新服务器，部署代码与运行数据分离，远端强制 PHP 8.0+。
- 内部目录保持不可公开访问；补齐 404、robots、sitemap、旧 footer 兼容与 www 301。
- 移除 QR 契约、旧二维码库和无调用的限流文件。
- 修复公开 SEO description 串入会话 JSON 的问题，并修复线上 17 个历史页面的受污染元数据。

## 2. 自动化验收

### 静态与专项测试

- 全项目 PHP lint：通过。
- `node --check js/ai-app.js`：通过。
- `node --check scripts/capture-page.js`：通过。
- `git diff --check`：通过。
- `scripts/test-logic-review.php`：本地与生产均通过。
- 会话并发追加：20 个并发写入全部保留，消息数和唯一数均为 20。

### 动态矩阵

`scripts/codex-dynamic-audit.js`：73/73 通过。

| 分组 | 通过 |
|---|---:|
| UserFlow | 19/19 |
| State | 13/13 |
| Negative | 33/33 |
| Exploratory | 8/8 |

覆盖游客创建、chat、发布、截图、邮箱 token、token edit 原 slug 覆盖、普通会话二次发布新 slug、生成锁、额度退款、越权、上传限制、域名保留字、动作注入剥离和截图后置事件顺序。

## 3. 生产验收

- 真实聊天模型流式返回：通过。
- 真实 HTML 模型流式生成：通过。
- 真实 Moderation：通过。
- 真实图片主模型 `gpt-image-2`：返回可解码图片，约 1.32 MB。
- 生产验收页：`https://seoace.xlog.ink/`。
- 页面状态：`live`；会话状态：`done`。
- 页面截图：`https://xlog.ink/site-assets/seoace/page-shot.png`，HTTP 200。
- 发布结果先于后置截图事件，截图失败不再影响页面发布结果。
- 新页面 SEO description 采用页面公开摘要，不包含角色消息或会话 JSON。
- 17 个旧页面的会话 JSON 元数据已备份后修复，复扫结果为 0。
- `www.xlog.ink` 保留路径和 query 301 到主域。
- Nginx 配置检查通过；仅有其他站点已有的 MIME/server_name 警告。

### 路由与安全状态

| URL | 结果 |
|---|---:|
| `/data/xlog.db` | 404 |
| `/includes/bootstrap.php` | 404 |
| `/prompts/chat-system.txt` | 404 |
| `/scripts/cost-report.php` | 404 |
| `/robots.txt` | 200 |
| `/sitemap.xml` | 200 |
| `/partials/footer.zh-CN.html` | 200 |
| 不存在路径 | 自定义 404 |

## 4. 历史资源缺失清单

资源审计扫描 2243 个页面、28 个资源引用，当前有 30 条问题记录。去除临时目录数据库残留后，正式缺失源文件包括：

- `/site-assets/sulwhn1qc0/1.webp`
- `/site-assets/svg1er5r8u/1.webp`
- `/site-assets/n6hstgt41z/1.webp`
- `/site-assets/97o2lmm9ie/1.webp`
- `/site-assets/1spn318qm0/1.webp`
- `/site-assets/dqopv88mea/1.webp`
- `/site-assets/xev5ssy2wp/1.webp`
- `/site-assets/pagewme/gen-2.webp`

受影响页面除同名源页面外，还包括 `coffeeaabb`、`coffeetzm`、`pagecic`、`pagejnc`、`pagerfn`、`y4o6a2cbui` 等复用页面。

已检查：

- 新服务器 `/root`、`/www/backup`、`/www/wwwroot` 的文件和归档。
- 本地项目、旧下载目录与现有迁移卷。
- 旧服务器 `103.73.66.117` 的 SSH 端口可建立 TCP，但 SSH banner 持续超时，无法读取旧资产。

按照既定安全规则，没有伪造占位图、没有删除 live 页面、没有自动改写这些图片引用。恢复条件是获得旧服务器可用 SSH 或包含原 `site-assets` 的离线备份，然后使用 `rsync --ignore-existing` 增量补齐并重新运行 `scripts/audit-page-assets.php`。

## 5. 回滚与备份

- 整改前 SQLite 和 Nginx 配置备份位于新服务器 `/root/xlog-backups/20260712-logic-review/`。
- 17 个页面元数据修复前的完整 `site/` 备份：`site-before-metadata-repair.tgz`。
- 部署脚本继续排除 `data/`、`site/`、`site-assets/`，不会用 Git 内容覆盖生产数据。

## 6. 最终完成条件

代码、配置和新链路验收已经完成。历史资源项在获得旧站或离线备份后执行：

1. 增量恢复上述 8 个正式资源文件。
2. 重新运行 `php scripts/audit-page-assets.php`。
3. 确认正式页面资源问题为 0；临时目录残留通过 cleanup 清理。
4. 观察 Nginx 错误日志，不再出现这些资源的持续 404。
