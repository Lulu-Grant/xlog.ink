# xlog.ink

xlog.ink V2 是一个 PHP 8 + SQLite + 原生 JS 的 AI 驱动个人页面快速创建与二级域名分发系统。

用户打开首页后进入聊天式创建界面，由较弱模型负责需求引导，最终由强模型生成完整 HTML 页面，并发布到 `{slug}.xlog.ink`。系统支持图片上传转 WebP、二维码交付、邮箱修改 token、邮箱验证码登录、游客/注册用户额度、防滥用和成人内容确认门。

## 当前形态

- 首页：`index.php`，Claude 风格 app shell，固定头部、固定底部输入框，中间聊天流滚动。
- 对话模型：`google/gemma-4-26B-A4B-it`，通过 OpenAI 兼容 `/v1/chat/completions`。
- 生成模型：`claude-sonnet-4-6`，通过 Anthropic 兼容 `/v1/messages`。
- 数据库：SQLite，默认 `data/xlog.db`，首次访问自动建表。
- 分发：生成页写入 `site/{slug}.html`，配合 Nginx 通配符二级域名访问。
- 资源：上传图片转 WebP，发布后放入 `site-assets/{slug}/`。

真实 API key、SMTP 密码、Turnstile key 不进入仓库，只放服务器 `/etc/xlog/config.php`。

## 关键目录

```text
api/                 V2 JSON/SSE API
includes/            DB、AI、额度、邮件、图片处理、编辑上下文等服务模块
prompts/             对话阶段和生成阶段 system prompt
scripts/             迁移、诊断、recent 构建、成本报表、清理脚本
docs/                V2 定稿设计、示例配置、Nginx 配置片段
css/ js/             V2 前端资源
assets/              生成页兼容样式、OG 图和品牌资源
data/                运行时数据目录，仅保留可迁移 JSONL，不提交 DB/日志
site/                生成页运行目录，仓库只保留 .gitkeep
site-assets/         上传资源运行目录，仓库只保留 .gitkeep
```

## 本地运行

```bash
php -S 127.0.0.1:8097 -t .
```

未配置 `/etc/xlog/config.php` 时系统会使用 mock AI，便于本地调试。

## 生产配置

复制并按服务器实际情况填写：

```bash
sudo mkdir -p /etc/xlog
sudo cp docs/config.example.php /etc/xlog/config.php
```

上线前必须确认：

```bash
php scripts/diagnose-config.php --live-ai --smtp-to=you@example.com
php scripts/migrate-jsonl.php
php scripts/build-recent.php
```

Nginx 参考配置见 `docs/nginx-v2-snippet.conf`。

生产 Nginx 必须屏蔽主域内部目录，至少包含：

```nginx
location ~ ^/(?:data|includes|prompts|scripts)(?:/|$) { return 404; }
location ~ /\.(?!well-known) { return 404; }
```

上线后用 `curl -I` 确认 `/data/xlog.db`、`/includes/bootstrap.php`、`/prompts/chat-system.txt`、`/scripts/cost-report.php` 都返回 404 或 403。

## 主要接口

- `POST /api/session.php`
- `POST /api/chat.php`
- `POST /api/upload.php`
- `POST /api/publish.php`
- `POST /api/page-email.php`
- `POST /api/resend-edit.php`
- `POST /api/my-pages.php`
- `POST /api/edit-session.php`
- `POST /api/auth/send-code.php`
- `POST /api/auth/verify.php`
- `POST /api/auth/logout.php`
- `POST /api/auth/me.php`

## 测试与诊断

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php scripts/diagnose-config.php
php scripts/cost-report.php
```

## 不提交内容

仓库排除以下运行时或本机内容：

- `/etc/xlog/config.php` 和任何真实凭据
- SQLite 数据库、WAL/SHM、邮件日志
- 生成页 `site/*.html`
- 上传资源 `site-assets/*`
- 本机预览、限流临时文件、`.DS_Store`
- 嵌套旧副本目录 `xlog.ink/`
