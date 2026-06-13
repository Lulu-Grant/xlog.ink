# xlog.ink 简易后台设计

## 目标

第一版后台只解决运营观察问题：最近生成了哪些页面、每个页面有没有访问、今天大概有多少访客。它不做复杂权限系统、不做内容审核后台、不做删除和编辑操作。

## 入口

- 页面：`/admin.php`
- 生产鉴权：`/etc/xlog/config.php` 配置 `admin.token`
- 本地开发：未配置 token 时，仅允许 `127.0.0.1` / `::1` 访问
- 防爆破：同一 IP 默认 8 次失败后锁定 15 分钟；成功登录后清理该 IP 的失败记录

示例：

```php
'admin' => [
    'token' => '<ADMIN_DASHBOARD_TOKEN>',
    'max_attempts' => 8,
    'lock_seconds' => 900,
],
'analytics' => [
    'salt' => '<RANDOM_ANALYTICS_HASH_SALT>',
],
```

## 访问统计方式

发布页面时，系统会在生成 HTML 的 `</body>` 前注入一个 1x1 透明 GIF：

```html
<img src="https://xlog.ink/api/visit.php?slug={slug}" ...>
```

这样不需要在用户页面里执行额外 JavaScript，也不破坏生成页当前的 CSP 安全边界。`api/visit.php` 只记录已存在且状态为 `live` 的页面。

## 数据表

`page_visits`：

- `slug`：页面前缀
- `visitor_hash`：按日期、IP、UA、salt 哈希后的访客标识
- `ip_hash`：IP 哈希，仅用于风控预留
- `user_agent` / `referer` / `path`：基础访问上下文
- `date` / `created_at`：统计日期和事件时间

同一页面、同一访客 60 秒内重复访问会被去重，避免刷新或脚本短时间刷爆统计表。

## 后台展示

当前展示：

- 页面总数
- 今日生成
- 总访问事件
- 今日访问事件
- 今日去重访客
- 近期页面列表：标题、slug、类型、语言、成人标记、可编辑状态、总访问、今日访问、最近访问时间

## 已知边界

- 旧页面不会自动带访问像素，除非重新发布或单独写脚本批量注入。
- 统计是轻量运营统计，不等同于严肃 BI；广告拦截器、图片禁用、爬虫和缓存都可能影响准确性。
- 后台第一版只读，不提供删除、封禁、改 slug 等危险操作。
