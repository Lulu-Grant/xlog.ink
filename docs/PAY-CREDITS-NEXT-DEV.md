# 支付 / 积分下一步开发文档

> 依据：全链路合理性 / 代码 / 逻辑审计（2026-07）  
> 范围：充值、多渠道、入账、积分扣退、后台渠道、对账与安全加固  
> 状态：可执行规格；按 P0 → P1 → P2 排期

---

## 1. 背景与目标

### 1.1 当前已上线能力

| 能力 | 说明 |
|---|---|
| 积分模式 | 登录用户 `credit_mode` 扣积分生成；游客日额度 5 |
| 注册赠送 | 新用户 10 积分 |
| 套餐 | c10 / c30 / c100 / c500(¥398)（配置驱动） |
| 支付宝 | `alipay_main` · 易支付 V2 RSA · xuanfan |
| 微信 | `wxpay_xhmcn` · 易支付 V1 MD5 · e.xhmcn.com |
| 渠道后台 | `admin.php` 可增删改启停渠道 |
| 入账 | notify 主路径 + status/return 补查；fulfill 幂等 |
| 前端 | 登录后充值面板，按渠道展示支付宝/微信按钮 |

### 1.2 本阶段目标

1. **消除审计 P0 资金/积分风险**（付了不到账、并发超扣）  
2. **收紧支付回跳与密钥暴露面**  
3. **补齐最小可运营对账能力**  
4. **不重写架构**，在现有 `includes/pay.php` + `quota.php` + `admin.php` 上迭代  

### 1.3 非目标（本期不做）

- 退款 API / 发票 / 订阅制  
- 换框架、换 Postgres  
- 复杂 AB、优惠券引擎  
- 自定义域名、企业子账号  

---

## 2. 问题回溯（审计摘要）

| ID | 级别 | 问题 | 业务影响 |
|---|---|---|---|
| M1 | 中高 | 入账金额用字符串全等 `"10.00"` | 网关返回 `10`/`10.0` 时拒单，付了可能不到账 |
| M2 | 中高 | 扣积分 `SELECT` 后 `UPDATE` 非原子 | 并发生成可超卖积分 |
| M3 | 中 | V1 查单 MD5 key 在 URL | 日志泄露商户密钥 |
| M4 | 中 | `return.php` 可直接 fulfill | 回跳面与 notify 等价，面偏大 |
| M5 | 中低 | Admin 渠道写操作无 CSRF | 依赖 SameSite，非零风险 |
| M6 | 中 | 密钥在 SQLite `data/` | 依赖 Nginx 屏蔽；误开放即全泄 |
| L* | 低 | pending 无过期、无对账 cron、套餐不可后台改、流水无 slug | 运营与排障成本高 |

---

## 3. 里程碑总览

```text
Sprint A（P0）  资金正确性补丁          0.5–1 天
Sprint B（P1）  回跳收敛 + 查单安全      1 天
Sprint C（P1）  Admin CSRF + 对账脚本    1–1.5 天
Sprint D（P2）  运营面板 + 套餐配置      2–3 天（可后置）
```

每个 Sprint 必须有：**实现清单 + 验收用例 + 回归命令**。

---

## 4. Sprint A — P0 资金与积分正确性（必做）

### 4.1 金额规范化比较（修 M1）

**文件：** `includes/pay.php`

**新增：**

```php
/**
 * Compare gateway money with local order amount.
 * Prefer integer fen to avoid float drift.
 */
function pay_money_equal($gatewayMoney, $amountCents): bool {
    if ($gatewayMoney === null || $gatewayMoney === '') return true; // caller may skip
    $gatewayCents = pay_parse_money_to_cents($gatewayMoney);
    if ($gatewayCents === null) return false;
    return $gatewayCents === (int)$amountCents;
}

function pay_parse_money_to_cents($money) {
    $s = trim((string)$money);
    if ($s === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    // "10", "10.0", "10.00" → 1000
    return (int)round((float)$s * 100);
}
```

**修改 `pay_fulfill_order`：**

- 删除 `(string)$opts['money'] !== $expectedMoney`  
- 改为：若传入 `money`，则 `pay_money_equal($opts['money'], $order['amount_cents'])`  
- 不匹配仍返回 `money_mismatch`

**验收：**

| 用例 | 期望 |
|---|---|
| 本地 1000 分，回调 `10.00` | 入账成功 |
| 回调 `10` / `10.0` | 入账成功 |
| 回调 `9.99` | `money_mismatch`，不入账 |
| 回调 `10.001` 或非数字 | 拒绝 |

扩展 `scripts/test-pay-quota.php` 增加上述断言。

---

### 4.2 积分扣减原子化（修 M2）

**文件：** `includes/quota.php` → `consume_quota()` 的 credit_mode 分支

**改为：**

```php
$cost = max(1, (int)xlog_config('billing.generate_credit_cost', 1));
$stmt = $pdo->prepare(
  'UPDATE users SET credits = credits - ?
   WHERE id = ? AND status = ? AND credits >= ?'
);
$stmt->execute([$cost, $userId, 'active', $cost]);
if ($stmt->rowCount() === 0) {
  // commit/rollback 与现有事务模型一致
  return ['ok' => false, 'reason' => 'credits_exhausted', ...];
}
// 再写 credit_transactions
```

**退款 `refund_quota`：** 保持加回即可；可选 `WHERE` 不强制。

**验收：**

| 用例 | 期望 |
|---|---|
| 余额 1，cost 1，单次生成 | 成功，余额 0 |
| 余额 1，模拟两次并发扣减 | 仅一次成功（可用脚本串行紧挨两次验证 rowCount） |
| 余额 0 再生成 | `credits_exhausted` + 前端充值卡 |

**回归：** 生成失败路径仍调用 `refund_quota`，积分回到扣前。

---

### 4.3 生产暴露面自检（修 M6 运维侧）

**新增脚本建议：** `scripts/check-web-exposure.sh`（或并入 `diagnose-config.php`）

检查 URL（生产）：

```text
/data/xlog.db
/data/pay-notify.log
/includes/pay.php
/includes/bootstrap.php
/prompts/chat-system.txt
/scripts/test-pay-quota.php
/etc 不可达
```

期望：全部 **404/403**。  
部署文档写明：每次发布后跑一遍。

**验收：** 脚本 exit 0；人工 `curl -I` 抽样一致。

---

### 4.4 Sprint A 完成定义

- [ ] M1/M2 代码合并  
- [ ] `php scripts/test-pay-quota.php` 全绿（含新断言）  
- [ ] 生产部署 + 暴露面检查  
- [ ] 真机：支付宝或微信 **1 元/最低套餐** 测到账 1 次  

---

## 5. Sprint B — 回跳收敛与 V1 查单安全

### 5.1 return.php 只补查、不直接 fulfill（修 M4）

**文件：** `api/pay/return.php`

**目标行为：**

1. 解析 `out_trade_no`  
2. 若订单存在且 `pending` → **仅** `pay_sync_order_from_gateway($order)`  
3. **删除**「验签通过则 `pay_fulfill_order`」分支  
4. 仍 302 回首页 `?pay=return&order_id=&status=`

**理由：** 入账只通过「服务端持密钥向网关 query」或「异步 notify」，浏览器回跳不可信。

**验收：**

| 用例 | 期望 |
|---|---|
| 支付成功后 return | 最终 paid（靠 query 或后续 notify） |
| 伪造 return 无签名 | 不入账 |
| 仅 return、notify 延迟 | status 轮询/我已支付 可补单 |

---

### 5.2 V1 查单避免 key 进 URL（修 M3）

**文件：** `includes/pay.php` → `pay_query_gateway_order` V1 分支

**优先尝试：**

```http
POST {api_base}/api.php
Content-Type: application/x-www-form-urlencoded

act=order&pid=...&key=...&out_trade_no=...
```

若网关只支持 GET，则：

- 保留 GET  
- 在开发文档标注「确认 access_log 不记录 query」  
- 可选：查单专用只读 key（网关若支持）

**验收：** 微信渠道 pending 订单，`status` 接口能正确变为 paid；日志抽样无明文 key（若已改 POST）。

---

### 5.3 notify 金额字段统一走分比较

与 4.1 同一函数；notify / sync / fulfill 全部复用。

---

### 5.4 Sprint B 完成定义

- [ ] return 无直接 fulfill  
- [ ] V1 query 路径评估并落地  
- [ ] 支付宝 + 微信各至少 1 次完整「支付→到账→生成扣分」  

---

## 6. Sprint C — Admin 安全与最小对账

### 6.1 Admin 写操作 CSRF（修 M5）

**方案（简单稳妥）：**

1. 登录成功后 session 或 cookie 旁路存 `admin_csrf = random`  
2. 或：`hash_hmac('sha256', 'pay-channel', admin_token + session_id)` 作 form hidden  
3. 所有 `pay_channel_action` POST 校验  

**文件：** `admin.php`

**验收：** 缺 token 的 POST 拒绝；正常表单可保存渠道。

---

### 6.2 对账脚本 `scripts/pay-reconcile.php`

**行为：**

```text
SELECT * FROM orders WHERE status='pending' AND created_at > now-48h
  → 对每单 pay_sync_order_from_gateway
  → 打印/日志：id, channel, result
```

**参数建议：**

- `--dry-run`  
- `--limit=50`  
- `--max-age-hours=48`  

**Cron（生产）：** 每 5–10 分钟  

```bash
*/5 * * * * www /www/server/php/80/bin/php /www/wwwroot/xlog.ink/scripts/pay-reconcile.php >> /www/wwwroot/xlog.ink/data/pay-reconcile.log 2>&1
```

（日志目录须不可 web 访问）

**验收：** 人为制造 pending（下单不付）不误入账；支付成功但 notify 丢失时，cron 后变 paid。

---

### 6.3 notify 失败可检索性（已有增强）

已有 `data/pay-notify.log`。本期补充：

- reconcile 日志格式统一 JSON 行  
- `docs/PAY-RUNBOOK.md` 一页纸：丢单排查步骤（见 §9）

---

### 6.4 Sprint C 完成定义

- [ ] CSRF 生效  
- [ ] reconcile 可手工跑通  
- [ ] Runbook 合并进 docs  

---

## 7. Sprint D — 运营增强（P2，可排期）

### 7.1 Admin 订单面板

在 `admin.php` 增加「订单」区块（只读 + 手动补查）：

| 列 | 来源 |
|---|---|
| 订单号 | orders.id |
| 用户 | users.email |
| 金额 / 积分 | amount_cents / credits |
| 渠道 | channel_id + pay_channel |
| 状态 | status |
| 创建/支付时间 | created_at / paid_at |
| 操作 | 「补查」→ `pay_sync_order_from_gateway` |

筛选：`status`、`pay_channel`、日期。

### 7.2 套餐可配置（可选）

**方案 A（快）：** 套餐仍在 `/etc/xlog/config.php`，admin 只读展示。  
**方案 B：** 新表 `credit_packages`，admin CRUD；`pay_packages()` 优先读表。

建议先 A，付费稳定后再 B。

### 7.3 pending 超时

- 配置 `pay.pending_ttl_hours` 默认 24  
- reconcile 或独立 cleanup：超时 pending → `closed`  
- 不自动退款（未支付）

### 7.4 积分流水可关联页面

- `consume_quota` / 生成成功后更新 `credit_transactions.ref = session_id|slug`  
- Admin 或用户侧「积分明细」接口 `api/credits/history.php`（登录）

### 7.5 生成流水与成本

- `cost-report.php` 增加：日充值金额、日消耗积分、日 AI 成本对比  

---

## 8. 接口与数据契约（变更说明）

### 8.1 表结构（已有 + 建议）

**已有：**

- `orders`：`channel_id`, `package_id`, `trade_no`, `pay_url`, …  
- `pay_channels`：多渠道  
- `credit_transactions`：充值/消耗/退款  

**建议新增（Sprint D）：**

```sql
-- 可选
ALTER 无 新状态 closed 无需改表结构，status 文本即可
-- credit_packages 若做后台套餐
```

### 8.2 API（对外保持兼容）

| 接口 | 变更 |
|---|---|
| `POST /api/pay/packages` | 无破坏；继续返回 `channels[]` |
| `POST /api/pay/create` | 无破坏；`channel_id` 优先 |
| `POST /api/pay/status` | 无破坏 |
| `notify` | 金额比较逻辑内部升级，响应仍 plain `success` |
| `return` | 行为收紧：不再直接 fulfill |

### 8.3 前端

- 无需大改；确认 `money_mismatch` / 补单文案可读  
- 缓存参数跟版：`ai-app.js?v=…`

---

## 9. 运维手册要点（写入 `docs/PAY-RUNBOOK.md` 时展开）

### 9.1 用户反馈「付了没到账」

1. 要：邮箱、订单号（或大致时间）、支付宝/微信  
2. `orders` 查 status / trade_no / channel_id / amount  
3. `data/pay-notify.log` 搜 order_id  
4. 跑：`php scripts/pay-reconcile.php` 或 status「我已支付」  
5. 仍失败：用渠道 pid 在网关商户后台对单  

### 9.2 渠道配置

- 微信 V1：api_base + pid + md5_key  
- 支付宝 V2：api_base + pid + RSA 公私钥 + method  
- 改密钥后旧 pending 仍用下单时 channel 快照（DB 行），**不要删渠道行**，可先停用  

### 9.3 安全基线

```bash
curl -sI https://xlog.ink/data/xlog.db | head -1    # 403/404
curl -sI https://xlog.ink/includes/pay.php | head -1
```

---

## 10. 测试矩阵

### 10.1 自动化（CI/发布前）

```bash
php scripts/test-pay-quota.php          # 扩展金额与幂等
php -l includes/pay.php includes/quota.php api/pay/*.php admin.php
# 可选：php scripts/pay-reconcile.php --dry-run
```

### 10.2 手工（每 Sprint）

| # | 场景 | 支付宝 | 微信 |
|---|---|---|---|
| 1 | 下单打开收银台 | ✓ | ✓ |
| 2 | 支付成功 notify 入账 | ✓ | ✓ |
| 3 | 重复 notify 不双加 | ✓ | ✓ |
| 4 | 仅 return / 仅 status 补单 | ✓ | ✓ |
| 5 | 余额 0 生成 → 充值卡 | ✓ | ✓ |
| 6 | 充值后生成扣 1 分 | ✓ | ✓ |
| 7 | 生成失败退分 | ✓ | — |
| 8 | Admin 改渠道启停 | ✓ | ✓ |

---

## 11. 任务拆分（开发票）

| 任务 ID | Sprint | 标题 | 主要文件 | 预估 |
|---|---|---|---|---|
| T-A1 | A | 金额分单位比较 | `pay.php`, `test-pay-quota.php` | 2h |
| T-A2 | A | 积分原子扣减 | `quota.php`, 单测/脚本 | 2h |
| T-A3 | A | 暴露面检查脚本 | `scripts/`, README | 1h |
| T-A4 | A | 部署 + 真机冒烟 | 生产 | 2h |
| T-B1 | B | return 只 query | `return.php` | 1h |
| T-B2 | B | V1 query POST | `pay.php` | 2h |
| T-B3 | B | 双渠道回归 | 手工 | 2h |
| T-C1 | C | Admin CSRF | `admin.php` | 2h |
| T-C2 | C | reconcile + cron | `scripts/pay-reconcile.php` | 3h |
| T-C3 | C | PAY-RUNBOOK | `docs/` | 1h |
| T-D1 | D | Admin 订单列表 | `admin.php` | 4h |
| T-D2 | D | pending 超时 | reconcile/cleanup | 2h |
| T-D3 | D | 积分明细 API | `api/credits/history.php`, 前端 | 4h |
| T-D4 | D | 套餐后台（可选） | db + admin | 6h |

---

## 12. 风险与回滚

| 风险 | 缓解 |
|---|---|
| 金额比较改动过宽导致少收 | 仅允许 1 分误差内；拒绝负值与超长小数 |
| 原子扣减导致误拒 | 用 `credits >= cost`；保留明确 `credits_exhausted` |
| return 不再 fulfill 导致到账变慢 | notify + reconcile + 前端轮询兜底 |
| Cron 误伤 | dry-run；只处理 pending；幂等 fulfill |

**回滚：**  

- 代码：`deploy` 回上一版本 git 树  
- 配置：`/etc/xlog/config.php.bak.*`  
- 渠道：admin 停用问题渠道，不删行  

---

## 13. 建议排期（一人）

| 日 | 内容 |
|---|---|
| D1 | T-A1 + T-A2 + 测试 + 部署 |
| D2 | T-A3 + T-A4 真机 + T-B1 |
| D3 | T-B2 + T-B3 |
| D4 | T-C1 + T-C2 |
| D5 | T-C3 + 缓冲 / 开 T-D1 |

---

## 14. 成功标准（本开发周期结束）

1. 审计 M1/M2 关闭，自动化覆盖  
2. 支付宝 + 微信各至少一笔真实到账记录可在 DB 复盘  
3. 人为「丢 notify」后，reconcile 或 status 能补单  
4. `/data` 与 `/includes` 公网不可读  
5. 无已知「付了积分重复到账」或「并发负积分」路径  

---

## 15. 关键代码索引

| 模块 | 路径 |
|---|---|
| 支付核心 | `includes/pay.php` |
| 额度/积分 | `includes/quota.php` |
| 下单/通知/回跳/状态 | `api/pay/*.php` |
| 后台 | `admin.php` |
| 前端充值 | `js/ai-app.js`, `index.php` |
| 烟雾测试 | `scripts/test-pay-quota.php` |
| 生产配置 | `/etc/xlog/config.php` |
| 运行时 DB | `data/xlog.db`（`pay_channels`, `orders`, `credit_transactions`） |

---

## 16. 下一步行动（立即）

1. **开工 Sprint A（T-A1 + T-A2）**，不穿插 P2 功能  
2. 合并后跑 `test-pay-quota.php` 并生产部署  
3. 真机最小金额验收支付宝、微信各一笔  
4. 再开 Sprint B  

文档版本：2026-07-26  
维护：随支付迭代更新 §2 问题表与 §11 任务状态  
