# 支付 / 积分运维手册（PAY-RUNBOOK）

## 快速自检

```bash
# 代码烟雾（含金额 fen 比较、积分原子扣减、幂等入账）
php scripts/test-pay-quota.php

# 待支付订单补查（不改库）
php scripts/pay-reconcile.php --dry-run --limit=50

# 真实补单
php scripts/pay-reconcile.php --limit=50 --max-age-hours=48

# 敏感路径不可公网
curl -sI https://xlog.ink/data/xlog.db | head -1
curl -sI https://xlog.ink/includes/pay.php | head -1
```

期望：`/data/*`、`/includes/*`、`/scripts/*`、`/prompts/*` 返回 **403/404**。

---

## 用户反馈「付了没到账」

1. 收集：登录邮箱、大约支付时间、支付宝/微信、金额  
2. 查库：

```sql
SELECT id, user_id, status, amount_cents, credits, pay_channel, channel_id, trade_no, created_at, paid_at
FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10;
```

3. 看异步日志：`data/pay-notify.log` 搜 `order_id` / `out_trade_no`  
4. 补单：

```bash
php scripts/pay-reconcile.php --limit=20
# 或让用户点充值面板「我已支付」（调 status 接口）
```

5. 仍失败：在支付商户后台（xuanfan / e.xhmcn.com）用商户订单号对账  

---

## 渠道配置

| 渠道 | 驱动 | 要点 |
|---|---|---|
| 支付宝 `alipay_main` | `epay_v2_rsa` | api_base + pid + 商户私钥 + 平台公钥，`method=jump` |
| 微信 `wxpay_xhmcn` | `epay_v1_md5` | `https://e.xhmcn.com`，pid + MD5，`method=jump`；下单走 `/api/pay/create` |

后台：`/admin.php` → 支付渠道（需 CSRF）。  
**不要删除**有 pending 订单的渠道行，可先停用。

### 密钥外置（AUDIT-8 P2-2，可选）

当前生产仍可能在 SQLite `pay_channels` 存明文密钥（`md5_key` / RSA）。代码已支持 `secret_ref`：

1. 渠道行设置 `secret_ref = 'wxpay_xhmcn'`（示例）
2. 在 `/etc/xlog/config.php` 增加：

```php
'pay' => [
    'secrets' => [
        'wxpay_xhmcn' => [
            'md5_key' => '...',
            // 或 RSA：
            // 'merchant_private_key' => '...',
            // 'platform_public_key' => '...',
        ],
    ],
],
```

或 `/etc/xlog/secrets.php` 返回 `['pay_channels' => [ 'wxpay_xhmcn' => [...] ]]`。

**残留风险（未全量 cutover 前）：** 备份/导出 `data/xlog.db` 仍可能含明文密钥；权限须保持 `660`、web 不可访问 `/data`。全量迁移需在维护窗把密钥拷入 secrets、清空 DB 列并回归 notify 验签后再宣布闭环。

---

## 入账路径（勿搞反）

1. **主路径**：网关 `notify_url` → 验签 → 金额 fen 比较 → 幂等 `paid` + 加积分  
2. **补路径**：`pay-reconcile.php` / `status` / `return` 均只做 **服务端 query**，return **不再**根据回跳签名直接入账  

---

## 常见错误码

| 现象 | 含义 |
|---|---|
| notify `bad sign` | 密钥/签名算法与渠道不一致 |
| notify `money_mismatch` | 回调金额与订单分不一致（已支持 `10`/`10.0`/`10.00`） |
| create 失败 | 渠道停用、密钥不全、网关拒绝 |
| `credits_exhausted` | 登录用户积分不足（原子扣减） |

---

## Cron 建议

```cron
*/5 * * * * www /www/server/php/80/bin/php /www/wwwroot/xlog.ink/scripts/pay-reconcile.php --limit=50 >> /www/wwwroot/xlog.ink/data/pay-reconcile.log 2>&1
```

日志目录须不可 web 访问。
