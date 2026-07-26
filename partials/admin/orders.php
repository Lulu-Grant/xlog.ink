<?php
/** @var array $orders */
/** @var string $orderStatus */
/** @var string $q */
/** @var int $limit */
$orders = $orders ?? [];
$orderStatus = $orderStatus ?? 'all';
$q = $q ?? '';
$limit = $limit ?? 50;
?>
<p class="note">充值订单只读列表。对账请用服务器脚本 <code>scripts/pay-reconcile.php</code>。本页无写操作控件。</p>
<form class="search" method="get" action="/admin.php">
  <input type="hidden" name="tab" value="orders">
  <select name="status">
    <?php foreach (['all' => '全部状态', 'pending' => 'pending', 'paid' => 'paid'] as $val => $label): ?>
      <option value="<?= h($val) ?>" <?= $orderStatus === $val ? 'selected' : '' ?>><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <input name="q" value="<?= h($q) ?>" placeholder="订单号 / 邮箱">
  <select name="limit">
    <?php foreach ([20, 50, 100, 200] as $n): ?>
      <option value="<?= $n ?>" <?= (int)$limit === $n ? 'selected' : '' ?>><?= $n ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit">筛选</button>
</form>

<table>
  <thead>
    <tr>
      <th>订单号</th>
      <th>用户</th>
      <th>金额</th>
      <th>积分</th>
      <th>状态</th>
      <th>渠道</th>
      <th>套餐</th>
      <th>创建</th>
      <th>支付</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><code><?= h($o['id']) ?></code></td>
      <td><?= h($o['user_email'] ?? ('#' . (int)$o['user_id'])) ?></td>
      <td>¥<?= h(admin_format_yuan_from_cents($o['amount_cents'] ?? 0)) ?></td>
      <td class="num"><?= (int)($o['credits'] ?? 0) ?></td>
      <td><span class="badge"><?= h($o['status'] ?? '') ?></span></td>
      <td><?= h(($o['channel_id'] ?? '') !== '' ? $o['channel_id'] : ($o['pay_channel'] ?? '-')) ?></td>
      <td><?= h($o['package_id'] ?? '-') ?></td>
      <td><?= fmt_dt($o['created_at'] ?? '') ?></td>
      <td><?= fmt_dt($o['paid_at'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?>
    <tr><td colspan="9" class="muted">没有匹配订单。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
