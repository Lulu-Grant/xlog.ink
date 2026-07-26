<?php
/** @var array $users */
/** @var array $ledger */
/** @var int|null $focusUserId */
/** @var string $q */
/** @var int $limit */
/** @var bool $grantAllowed */
$users = $users ?? [];
$ledger = $ledger ?? [];
$focusUserId = $focusUserId ?? null;
$q = $q ?? '';
$limit = $limit ?? 30;
$grantAllowed = !empty($grantAllowed);
?>
<p class="note">
  按邮箱搜索用户并查看积分流水。
  <?php if (!empty(xlog_config('billing.credit_mode', false))): ?>
    <code>daily_quota</code> 在 credit_mode 下对生成<strong>不生效</strong>（legacy 字段）。
  <?php endif; ?>
  <?php if (!$grantAllowed): ?>补发积分默认关闭（<code>admin.allow_credit_grant</code>）。<?php endif; ?>
</p>

<form class="search" method="get" action="/admin.php">
  <input type="hidden" name="tab" value="users">
  <input name="q" value="<?= h($q) ?>" placeholder="邮箱关键字" style="min-width:220px">
  <select name="limit">
    <?php foreach ([10, 30, 50, 100] as $n): ?>
      <option value="<?= $n ?>" <?= (int)$limit === $n ? 'selected' : '' ?>><?= $n ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit">搜索</button>
</form>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>邮箱</th>
      <th>积分</th>
      <th>daily_quota</th>
      <th>状态</th>
      <th>注册</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= (int)$u['id'] ?></td>
      <td><?= h($u['email']) ?></td>
      <td class="num"><?= (int)$u['credits'] ?></td>
      <td><?= (int)$u['daily_quota'] ?></td>
      <td><?= h($u['status']) ?></td>
      <td><?= fmt_dt($u['created_at'] ?? '') ?></td>
      <td class="actions">
        <a href="<?= h(admin_tab_url('users', ['q' => $q, 'user_id' => (int)$u['id'], 'limit' => $limit])) ?>">流水</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?>
    <tr><td colspan="7" class="muted">没有匹配用户。</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php if ($focusUserId): ?>
  <h3>积分流水 · user #<?= (int)$focusUserId ?></h3>
  <?php if ($grantAllowed): ?>
    <form class="search" method="post" action="/admin.php?tab=users&amp;user_id=<?= (int)$focusUserId ?>&amp;q=<?= h(urlencode($q)) ?>">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="admin_grant_action" value="1">
      <input type="hidden" name="user_id" value="<?= (int)$focusUserId ?>">
      <input name="credits" type="number" min="1" max="100000" required placeholder="补发积分">
      <input name="note" placeholder="备注（写入 ref）" style="min-width:180px">
      <button type="submit" onclick="return confirm('确认补发积分？此操作会写入流水。');">补发积分</button>
    </form>
  <?php endif; ?>
  <table>
    <thead>
      <tr>
        <th>时间</th>
        <th>delta</th>
        <th>reason</th>
        <th>ref</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ledger as $row): ?>
      <tr>
        <td><?= fmt_dt($row['created_at'] ?? '') ?></td>
        <td class="num"><?= (int)$row['delta'] ?></td>
        <td><?= h($row['reason'] ?? '') ?></td>
        <td><code><?= h($row['ref'] ?? '') ?></code></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger): ?>
      <tr><td colspan="4" class="muted">暂无流水。</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
<?php endif; ?>
