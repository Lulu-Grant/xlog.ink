<?php
/** @var string $tab */
/** @var string $adminFlash */
/** @var string $adminFlashError */
/** @var string $today */
$nav = [
    'overview' => '概览',
    'pages' => '页面',
    'channels' => '支付渠道',
    'orders' => '订单',
    'users' => '用户积分',
];
$tabTitles = $nav;
$pageTitle = $tabTitles[$tab] ?? '概览';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>xlog.ink admin · <?= h($pageTitle) ?></title>
<style>
:root{--bg:#f4f1ea;--ink:#24211f;--muted:#8b8379;--line:#d8d0c4;--strong:#24211f;--accent:#df7658}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.admin-shell{display:grid;grid-template-columns:200px minmax(0,1fr);min-height:100vh}
.admin-nav{border-right:2px solid var(--strong);padding:20px 12px;background:transparent}
.admin-nav .brand{display:block;font-weight:800;font-size:15px;margin:0 8px 16px;letter-spacing:.02em;color:var(--ink);text-decoration:none}
.admin-nav a{display:block;padding:9px 10px;margin:2px 0;border:1.5px solid transparent;color:var(--ink);text-decoration:none;font-weight:600}
.admin-nav a:hover{border-color:var(--line);background:rgba(223,118,88,.08)}
.admin-nav a[aria-current="page"]{border-color:var(--strong);background:var(--accent);color:#fff}
.admin-main{padding:24px;min-width:0}
header.mod-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;padding-bottom:16px;border-bottom:2px solid var(--strong);margin-bottom:16px}
h1{margin:0;font-size:22px;letter-spacing:.02em}h2{margin:0 0 8px;font-size:18px}h3{margin:20px 0 8px;font-size:15px}
.muted{color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:18px 0}
.stat{border:1.5px solid var(--strong);padding:14px;background:transparent}.stat b{display:block;font-size:24px;line-height:1.1}.stat span{color:var(--muted);font-size:12px}
.stat a{color:inherit;text-decoration:none}
form.search{display:flex;gap:8px;margin:14px 0 18px;flex-wrap:wrap}
input,button,select,textarea{font:inherit;border:1.5px solid var(--line);background:transparent;color:var(--ink);padding:8px 10px}
input,button,select{height:36px}button{border-color:var(--strong);font-weight:700;cursor:pointer}
table{width:100%;border-collapse:collapse;border:1.5px solid var(--strong);background:transparent}
th,td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top;text-align:left}
th{font-size:12px;color:var(--muted);font-weight:700}tr:hover{background:rgba(223,118,88,.06)}
.title{max-width:340px;font-weight:700}.slug a{color:var(--accent);font-weight:700;text-decoration:none}
.badge{display:inline-flex;border:1px solid var(--line);padding:1px 6px;margin-right:4px}
.num{font-weight:800}
.actions a,.actions button{color:var(--ink);text-decoration:none;border:1px solid var(--strong);padding:4px 7px;display:inline-flex;margin:0 4px 4px 0;background:transparent;height:auto;font-weight:600}
.section{margin:28px 0;padding-top:18px;border-top:2px solid var(--strong)}
.flash{padding:10px 12px;border:1.5px solid var(--strong);margin:14px 0}.flash.err{border-color:#a33;color:#a33}
.channel-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0;padding:14px;border:1.5px solid var(--line)}
.channel-form label{display:grid;gap:4px;font-size:12px;color:var(--muted)}
.channel-form label.full{grid-column:1/-1}
.channel-form textarea{min-height:72px;font-size:12px}
.channel-form .row-actions{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}
.quick-links{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 20px}
.quick-links a{border:1.5px solid var(--strong);padding:8px 12px;text-decoration:none;color:var(--ink);font-weight:700}
.quick-links a:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.note{margin:0 0 16px;color:var(--muted);font-size:13px}
@media(max-width:820px){
  .admin-shell{grid-template-columns:1fr}
  .admin-nav{border-right:0;border-bottom:2px solid var(--strong);padding:12px;display:flex;flex-wrap:wrap;gap:4px;align-items:center}
  .admin-nav .brand{width:100%;margin:0 0 8px}
  .admin-nav a{margin:0}
  .admin-main{padding:14px}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  table{display:block;overflow:auto;white-space:nowrap}
  header.mod-head{align-items:flex-start;flex-direction:column}
  .channel-form{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="admin-shell">
  <nav class="admin-nav" aria-label="后台模块">
    <a class="brand" href="<?= h(admin_tab_url('overview')) ?>">■ xlog admin</a>
    <?php foreach ($nav as $id => $label): ?>
      <a href="<?= h(admin_tab_url($id)) ?>"<?= $tab === $id ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <main class="admin-main">
    <header class="mod-head">
      <div>
        <h1><?= h($pageTitle) ?></h1>
        <div class="muted">运营后台 · 模块隔离 · UTC <?= h($today ?? utc_date()) ?></div>
      </div>
    </header>
    <?php if (!empty($adminFlash)): ?><div class="flash"><?= h($adminFlash) ?></div><?php endif; ?>
    <?php if (!empty($adminFlashError)): ?><div class="flash err"><?= h($adminFlashError) ?></div><?php endif; ?>
    <?php
    $partial = __DIR__ . '/' . $tab . '.php';
    if (is_file($partial)) {
        require $partial;
    } else {
        echo '<p class="muted">未知模块。</p>';
    }
    ?>
  </main>
</div>
</body>
</html>
