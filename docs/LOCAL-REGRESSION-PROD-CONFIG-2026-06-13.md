# xlog.ink V2 线上同配置本地全量回归记录

日期：2026-06-13  
范围：使用线上 `/etc/xlog/config.php` 的 AI/SMTP 配置，在本地 PHP 服务中完成全链路回归。  
部署状态：未上线，未同步服务器，未推送 Git。

## 1. 配置方式

本轮通过 SSH 只读复制线上 `/etc/xlog/config.php` 到本地临时文件：

```text
/tmp/xlog-prod-config-local.php
```

随后创建本地回归专用临时配置：

```text
/tmp/xlog-regression-config.php
```

该配置使用线上 AI/SMTP 参数，但把运行路径改成本地：

- `data_dir` -> `/Users/apple/Documents/xlog/xlog.ink/data`
- `site_dir` -> `/Users/apple/Documents/xlog/xlog.ink/site`
- `asset_dir` -> `/Users/apple/Documents/xlog/xlog.ink/site-assets`

没有把任何线上密钥写入仓库。

## 2. 线上配置识别结果

本轮识别到的线上同配置：

- Chat：`https://api.3s3.org` / `google/gemma-4-26B-A4B-it` / `openai`
- Generate：`https://api.3s3.org` / `Qwen/Qwen3.6-35B-A3B` / `openai`
- Image：本地配置补齐 `gpt-image-2`
- Moderation：本地配置补齐 `omni-moderation-latest`
- SMTP：`smtpdm-ap-southeast-1.aliyun.com:465`
- Turnstile：disabled

说明：线上配置文件本身主要包含 chat/gen/SMTP；本地 `config.php` 仍补齐 image/moderation 配置，因此最终本地回归进程可同时测试图片生成和 OpenAI Moderation。

## 3. 诊断结果

执行：

```bash
XLOG_CONFIG_PATH=/tmp/xlog-regression-config.php php scripts/diagnose-config.php --live-ai
```

最终结果：

- SQLite：OK
- site_dir：OK
- asset_dir：OK
- AI chat config：OK
- AI gen config：OK
- AI image config：OK
- AI moderation config：OK
- AI chat stream：OK
- AI gen stream：OK
- AI visual moderation：OK
- Turnstile config：OK，当前 disabled
- SMTP config：OK

过程中发现 `scripts/diagnose-config.php` 使用 1x1 PNG 作为 Moderation 探针会导致 OpenAI 返回 HTTP 400。已在本地修复为 64x64 PNG 探针，复跑通过。

## 4. API 回归结果

本地服务：

```bash
XLOG_CONFIG_PATH=/tmp/xlog-regression-config.php php -S 127.0.0.1:8091 -t .
```

覆盖项：

- `POST /api/session.php` 创建会话：PASS
- `POST /api/session.php` 恢复会话：PASS
- `POST /api/chat.php` 真实 Gemma SSE 对话：PASS
- `POST /api/domain-check.php` 自定义前缀检查：PASS
- `POST /api/upload.php` 图片上传、WebP、caption 200 字符截断：PASS
- `POST /api/image-generate.php` 图片生成：PASS
- `POST /api/publish.php` 真实 Qwen 生成并发布：PASS
- 本地 HTML 落盘：PASS
- SEO title/meta/OG：PASS
- xlog footer 注入：PASS
- 页面截图导出：PASS
- `POST /api/page-email.php` 修改链接邮件：PASS
- `POST /api/auth/send-code.php` 登录验证码邮件：PASS
- 同会话 `generating` 锁：PASS

真实发布回归生成的本地页面：

```text
site/coffeevdc.html
```

对应结果：

```text
https://coffeevdc.xlog.ink/
https://xlog.ink/site-assets/coffeevdc/page-shot.png
```

说明：这是本地数据库和本地文件系统中的测试产物，没有同步到线上。

## 5. UI 回归结果

使用 Node Playwright 对本地服务做三组验证：

- Desktop：1280x900 / `zh-CN`
- Mobile：393x852 / `zh-TW`
- English：900x760 / `en`

结果：

- 控制台错误：PASS
- 最大宽度约束 750：PASS
- 输入框可见：PASS
- 无横向溢出：PASS
- 无 document 级纵向滚动：PASS
- 消息区高度可用：PASS
- 三语标题与 placeholder：PASS

截图：

```text
/tmp/xlog-regression-desktop.png
/tmp/xlog-regression-mobile.png
/tmp/xlog-regression-english.png
```

## 6. 额度处理说明

回归过程中本地访客 IP `127.0.0.1` 已触发：

- `session_create` 50/50
- `image_generate` 5/5

这证明额度限制生效，但也阻断了后续 UI 和图片生成补测。为完成本地全量回归，已仅在本地 SQLite 中把这两项测试计数临时归零：

```text
quota_counters: ip:127.0.0.1 / session_create / image_generate
```

该操作仅影响本地开发数据库，不影响线上。

## 7. 静态检查

已执行：

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
node -c js/ai-app.js
node -c scripts/capture-page.js
```

结果：

- PHP 全项目语法检查：PASS
- `js/ai-app.js`：PASS
- `scripts/capture-page.js`：PASS

## 8. 剩余注意项

1. 本轮没有上线、没有同步生产，因此子域访问结果只代表本地落盘和 URL 生成逻辑通过。
2. Turnstile 当前线上同配置为 disabled，无法验证真实 Turnstile 拦截。
3. SMTP 已真实发出测试邮件，注意不要频繁重复触发同一邮箱的验证码限频。
4. iOS Safari 真机键盘问题仍需要设备侧复测；本轮 Playwright 只覆盖 Chromium 移动视口。
5. 本地 `recent.html` 会因为发布回归被刷新，属于本地测试副作用。

