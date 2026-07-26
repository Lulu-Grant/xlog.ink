# 已生成页面风险批量审计

`scripts/audit-page-risk.php` 只读扫描 SQLite `pages` 表与 `site/*.html`，使用独立模型判断页面是否具有以下风险：

- 赌博、博彩或投注推广；
- 炒币、代币销售、投资信号或高收益币圈推广；
- 钓鱼、冒充、虚假投资、诱导付款等诈骗嫌疑；
- 应启用 18+ 门禁的成人内容。

脚本不会用内容关键词代替模型判断。18+ 门禁是否真实存在，则通过生成页注入的完整结构标记确定性检查。只有模型判断为成人内容且页面缺少门禁时，`adult_without_gate=1`。

## 配置

真实密钥不得写入仓库。任选一种方式：

```bash
export XLOG_AUDIT_API_KEY='...'
```

或在服务器 `/etc/xlog/config.php`、本地忽略的 `config.php` 中配置：

```php
'ai' => [
    'audit' => [
        'base_url' => 'https://api.3s3.org',
        'model' => 'grok-4.5',
        'format' => 'openai',
        'key' => '<PAGE_AUDIT_API_KEY>',
        'max_tokens' => 900,
        'timeout' => 120,
    ],
],
```

环境变量 `XLOG_AUDIT_BASE_URL` 和 `XLOG_AUDIT_MODEL` 可临时覆盖地址与模型。

## 使用

先检查页面发现范围和内部解析：

```bash
php scripts/audit-page-risk.php --self-test
php scripts/audit-page-risk.php --dry-run
```

全量执行：

```bash
php scripts/audit-page-risk.php
```

指定报告并支持中断后续跑：

```bash
php scripts/audit-page-risk.php \
  --output=data/reports/page-risk-audit.csv \
  --resume
```

断点续跑只跳过内容哈希未变化且 `review_error` 为空的成功记录。模型超时、响应异常或 HTML 缺失的页面会继续重试，不会被误判为已经审计完成。

其他选项：

```bash
php scripts/audit-page-risk.php --status=live --limit=20 --delay-ms=500
```

默认报告写入 `data/reports/`，该目录不进入 Git。CSV 会保留每个页面的模型结论、置信度、理由、门禁状态、内容哈希、模型名和调用错误。模型调用失败的页面会写入 `review_error`，不会被视为安全。

## CSV 重点字段

- `gambling`、`crypto_speculation`、`scam`：模型风险标记；
- `ai_adult`：模型认为页面需要成人门禁；
- `adult_gate_present`：页面实际包含完整门禁结构；
- `adult_without_gate`：成人内容但缺少门禁；
- `flagged`：前三类风险任一成立，或 `adult_without_gate=1`；
- `review_error`：模型或文件读取失败，必须人工复查。

该报告用于运营排查，不会自动下线、修改或删除任何页面。
