<?php
/** @var array $kpis */
$k = $kpis ?? admin_overview_kpis();
?>
<section class="stats">
  <div class="stat"><b><?= (int)$k['total_pages'] ?></b><span>页面总数</span></div>
  <div class="stat"><b><?= (int)$k['today_pages'] ?></b><span>今日生成</span></div>
  <div class="stat"><b><?= (int)$k['total_visits'] ?></b><span>访问事件</span></div>
  <div class="stat"><b><?= (int)$k['today_visits'] ?></b><span>今日访问</span></div>
  <div class="stat"><b><?= (int)$k['today_visitors'] ?></b><span>今日访客</span></div>
</section>
<section class="stats">
  <div class="stat"><a href="<?= h(admin_tab_url('orders', ['status' => 'paid'])) ?>"><b><?= (int)$k['paid_orders'] ?></b><span>已支付订单</span></a></div>
  <div class="stat"><a href="<?= h(admin_tab_url('orders', ['status' => 'pending'])) ?>"><b><?= (int)$k['pending_orders'] ?></b><span>待支付订单</span></a></div>
  <div class="stat"><a href="<?= h(admin_tab_url('channels')) ?>"><b><?= (int)$k['channel_count'] ?></b><span>支付渠道</span></a></div>
  <div class="stat"><b><?= !empty($k['pay_enabled']) ? 'ON' : 'OFF' ?></b><span>充值开关</span></div>
  <div class="stat"><b><?= !empty($k['credit_mode']) ? 'ON' : 'OFF' ?></b><span>积分模式</span></div>
  <div class="stat"><b><?= (int)$k['user_fallback_daily_generate'] ?></b><span>登录保底日次</span></div>
</section>

<p class="note">
  运营口径：<code>credit_mode</code> 开启时登录用户优先扣钱包积分；
  <code>users.daily_quota</code> 对生成不生效（legacy）。
  积分不足可用登录保底日次；游客日免费 <?= (int)$k['guest_generate_quota'] ?> 次；注册赠送 <?= (int)$k['signup_credits'] ?> 积分。
</p>

<div class="quick-links">
  <a href="<?= h(admin_tab_url('pages')) ?>">查页面</a>
  <a href="<?= h(admin_tab_url('channels')) ?>">管理支付渠道</a>
  <a href="<?= h(admin_tab_url('orders', ['status' => 'pending'])) ?>">待支付订单<?= (int)$k['pending_orders'] > 0 ? ' (' . (int)$k['pending_orders'] . ')' : '' ?></a>
  <a href="<?= h(admin_tab_url('users')) ?>">用户积分</a>
</div>
