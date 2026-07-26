<?php
/** @var array $pages */
/** @var string $q */
/** @var int $limit */
$pages = $pages ?? [];
$q = $q ?? '';
$limit = $limit ?? 50;
?>
<form class="search" method="get" action="/admin.php">
  <input type="hidden" name="tab" value="pages">
  <input name="q" value="<?= h($q) ?>" placeholder="搜索 slug / 标题 / 类型">
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
      <th>页面</th>
      <th>类型</th>
      <th>访问</th>
      <th>今日</th>
      <th>最近访问</th>
      <th>创建/更新</th>
      <th>状态</th>
      <th>操作</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($pages as $p): $url = 'https://' . $p['slug'] . '.xlog.ink/'; ?>
    <tr>
      <td>
        <div class="slug"><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($p['slug']) ?></a></div>
        <div class="title"><?= h($p['title']) ?></div>
      </td>
      <td><?= h($p['type']) ?><br><span class="muted"><?= h($p['lang']) ?></span></td>
      <td><span class="num"><?= (int)$p['total_visits'] ?></span></td>
      <td><span class="num"><?= (int)$p['today_visits'] ?></span><br><span class="muted"><?= (int)$p['today_visitors'] ?> 人</span></td>
      <td><?= fmt_dt($p['last_visit'] ?? '') ?></td>
      <td><?= fmt_dt($p['created_at'] ?? '') ?><br><span class="muted"><?= fmt_dt($p['updated_at'] ?? '') ?></span></td>
      <td>
        <?php if (!empty($p['is_adult'])): ?><span class="badge">18+</span><?php endif; ?>
        <?php if (!empty($p['editable'])): ?><span class="badge">editable</span><?php endif; ?>
        <?php if (!empty($p['slug_source'])): ?><span class="badge"><?= h($p['slug_source']) ?></span><?php endif; ?>
      </td>
      <td class="actions">
        <a href="<?= h($url) ?>" target="_blank" rel="noopener">打开</a>
        <a href="/site/<?= h($p['slug']) ?>.html" target="_blank" rel="noopener">静态</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$pages): ?>
    <tr><td colspan="8" class="muted">没有匹配页面。</td></tr>
  <?php endif; ?>
  </tbody>
</table>
