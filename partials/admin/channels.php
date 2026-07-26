<?php
/** @var array $payChannels */
/** @var array|null $editChannel */
$payChannels = $payChannels ?? [];
$editChannel = $editChannel ?? null;
?>
<p class="note">在此新增/修改支付宝、微信等易支付渠道。密钥仅保存在服务器 SQLite，不会回显完整密文。启用前须密钥齐全。</p>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>名称</th>
      <th>方式</th>
      <th>驱动</th>
      <th>API / PID</th>
      <th>状态</th>
      <th>排序</th>
      <th>操作</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($payChannels as $ch): ?>
    <tr>
      <td><code><?= h($ch['id']) ?></code></td>
      <td><?= h($ch['name']) ?></td>
      <td><?= h(pay_channel_pay_types()[$ch['pay_type']] ?? $ch['pay_type']) ?></td>
      <td><?= h(pay_channel_drivers()[$ch['driver']] ?? $ch['driver']) ?></td>
      <td>
        <div><?= h($ch['api_base']) ?></div>
        <span class="muted">pid=<?= h($ch['pid']) ?></span>
        <?php if (pay_channel_is_configured($ch)): ?><span class="badge">密钥齐全</span><?php else: ?><span class="badge">缺密钥</span><?php endif; ?>
      </td>
      <td><?= !empty($ch['enabled']) ? '启用' : '停用' ?></td>
      <td><?= (int)$ch['sort_order'] ?></td>
      <td class="actions">
        <a href="<?= h(admin_tab_url('channels', ['edit_channel' => $ch['id']])) ?>">编辑</a>
        <form method="post" action="/admin.php?tab=channels" style="display:inline" onsubmit="return confirm('确认切换启用状态？');">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="pay_channel_action" value="toggle">
          <input type="hidden" name="id" value="<?= h($ch['id']) ?>">
          <input type="hidden" name="enabled_to" value="<?= !empty($ch['enabled']) ? '0' : '1' ?>">
          <button type="submit"><?= !empty($ch['enabled']) ? '停用' : '启用' ?></button>
        </form>
        <form method="post" action="/admin.php?tab=channels" style="display:inline" onsubmit="return confirm('确认停用该渠道？历史订单仍保留密钥以便对账，不会物理删除。');">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <input type="hidden" name="pay_channel_action" value="delete">
          <input type="hidden" name="id" value="<?= h($ch['id']) ?>">
          <button type="submit">停用</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$payChannels): ?>
    <tr><td colspan="8" class="muted">还没有支付渠道，请在下方添加。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php
$f = $editChannel ?: [
    'id' => '', 'name' => '', 'pay_type' => 'wxpay', 'driver' => 'epay_v1_md5',
    'api_base' => 'https://e.xhmcn.com', 'pid' => '', 'method' => 'jump',
    'enabled' => 1, 'sort_order' => 20,
];
$isEdit = (bool)$editChannel;
?>
<h3><?= $isEdit ? '编辑渠道' : '新增渠道' ?></h3>
<form class="channel-form" method="post" action="/admin.php?tab=channels" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
  <input type="hidden" name="pay_channel_action" value="save">
  <input type="hidden" name="is_new" value="<?= $isEdit ? '0' : '1' ?>">
  <label>渠道 ID（英文，创建后不可随意改）
    <input name="id" required value="<?= h($f['id']) ?>" <?= $isEdit ? 'readonly' : '' ?> placeholder="wxpay_xhmcn">
  </label>
  <label>显示名称
    <input name="name" required value="<?= h($f['name']) ?>" placeholder="微信支付">
  </label>
  <label>支付方式
    <select name="pay_type">
      <?php foreach (pay_channel_pay_types() as $k => $label): ?>
        <option value="<?= h($k) ?>" <?= ($f['pay_type'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>驱动
    <select name="driver">
      <?php foreach (pay_channel_drivers() as $k => $label): ?>
        <option value="<?= h($k) ?>" <?= ($f['driver'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>API 根地址
    <input name="api_base" required value="<?= h($f['api_base']) ?>" placeholder="https://e.xhmcn.com">
  </label>
  <label>商户 PID
    <input name="pid" required value="<?= h($f['pid']) ?>" placeholder="1003">
  </label>
  <label>V2 method（仅 RSA）
    <input name="method" value="<?= h($f['method'] ?? 'jump') ?>" placeholder="jump">
  </label>
  <label>排序（越小越靠前）
    <input name="sort_order" type="number" value="<?= (int)($f['sort_order'] ?? 100) ?>">
  </label>
  <label class="full">MD5 密钥（V1 必填；编辑时留空表示不改）
    <input name="md5_key" type="password" value="" placeholder="<?= $isEdit && !empty($f['md5_key']) ? '•••• 已配置，留空不修改' : '商户 MD5 key' ?>">
  </label>
  <label class="full">商户 RSA 私钥（V2 必填；编辑留空不改）
    <textarea name="merchant_private_key" placeholder="<?= $isEdit && !empty($f['merchant_private_key']) ? '已配置，留空不修改' : 'PKCS8 base64 或 PEM' ?>"></textarea>
  </label>
  <label class="full">平台 RSA 公钥（V2 验签；编辑留空不改）
    <textarea name="platform_public_key" placeholder="<?= $isEdit && !empty($f['platform_public_key']) ? '已配置，留空不修改' : '平台公钥 base64 或 PEM' ?>"></textarea>
  </label>
  <label><input type="checkbox" name="enabled" value="1" <?= !empty($f['enabled']) ? 'checked' : '' ?>> 启用该渠道</label>
  <div class="row-actions">
    <button type="submit"><?= $isEdit ? '保存修改' : '新增渠道' ?></button>
    <?php if ($isEdit): ?><a href="<?= h(admin_tab_url('channels')) ?>" style="align-self:center">取消编辑</a><?php endif; ?>
  </div>
</form>
