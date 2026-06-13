# 第五轮综合审计报告（AUDIT-5）

> 审计日期：2026-06-13 · 审计基线：`6451099`（Add admin dashboard and visit tracking）
> 对比起点：AUDIT-4 基线 `576b572`
> 范围：自 AUDIT-4 之后的全部改动（管理后台、访问统计、AI 供应商回退、图片 URL 响应、成人关键词收紧、卡片样式统一）+ UI/UX + 健壮性 + 框架外行为
> 方式：全量重读新增/改动文件 + 浏览器实测（admin 鉴权、卡片宽度/调性）+ 全项目 `php -l` / `node --check` / `git diff --check` 通过

---

## 总评

这一轮把 AUDIT-4 的全部必修项**干净闭环**，并新增了管理后台、访问统计、供应商回退三块能力。质量继续走高。新发现没有 P0 阻断项，主要是**一个无鉴权写接口缺限流 + 一张分析表无清理**（运维隐患），以及若干低风险项与上一轮遗留的截图阻塞。

---

## 第一部分 · AUDIT-4 闭环确认

| AUDIT-4 编号 | 项 | 状态 | 证据 |
|---|---|---|---|
| H1 | 二级域名保留字黑名单 | ✅ 已修 | `content_tools.php` `slug_reserved_words()`（www/mail/api/admin/login/pay/vip/ai… 一大票）；`slug_is_reserved` 在 domain-check（返回 409）、`generate_semantic_slug`、`slug_base_from_text` 三处生效 |
| R1 | PUBLISH 双因子门 | ✅ 已修 | `ACTION-ROUTING-PLAN.md` 已改为"PUBLISH 只浮确认卡，用户点击才发布"，前端 `handleAction` 不再自动 publish，`isPublishIntent` 已删；文档与代码一致 |
| R2 | 成人关键词误判 + 手动开关 | ✅ 已升级 | 运行代码已删除 `adult_keyword_score` / `adult_keyword_hit` 和确认卡手动 18+ 开关；成人内容只由 AI moderation 对文本与图片返回的分数决定 |
| M3 | 配图失败回退假占位图 | ✅ 已修 | `image_create_generated_placeholder` 现在无 key 直接抛错、生成失败抛错，不再画 GD 色块 |
| M4 | 视觉审核 fail-open | ✅ 已改为 AI-only | 审核异常只记录 `ai_moderation_error`，不会回退到关键词或默认成人判定；是否加 adult gate 只看 AI moderation 成功返回的分数 |

五项全部落实，且文档同步——上一轮最担心的"代码与决策文档冲突"已消除。

---

## 第二部分 · 新增能力评审

### 管理后台 `admin.php` —— 总体扎实

优点：token 用 `hash_equals` 定时安全比对；cookie 用 `hash_hmac` 票据（不可伪造）；登录失败按 ip_hash 锁定（默认 8 次 / 900s）并落 `admin_login_attempts` 表 + 自清理；SQL 全参数化，`$limit` 经 `(int)` + clamp 才进 `LIMIT`，`$where` 走占位符；输出全 `h()` 转义。鉴权模型分两档（配了 token 走 token，没配则仅 localhost，否则 403）。

注意点：
- **A1（中）：生产必须设 `admin.token`。** 未配置时的"仅 localhost"兜底依赖 `client_ip()`。`client_ip()` 只在 `XLOG_TRUSTED_PROXIES` 配置正确时才信任 `X-Forwarded-For`；若反代/容器网络让 PHP 看到的 `REMOTE_ADDR` 恒为 `127.0.0.1`（某些 fpm over TCP 部署），则 localhost 兜底会对全网放行。**结论**：把"生产环境 admin.token 必填"写进部署清单，并在 `admin.php` 未配置且非 CLI 时打一条显眼 error_log 警告。
- A2（低）：admin cookie `secure` 取决于 `$_SERVER['HTTPS']` 检测；站在 CDN/反代后时 HTTPS 头可能为空，导致 cookie 不带 Secure。建议与 `xlog_start_session` 一样支持 `X-Forwarded-Proto` 判定。

### 访问统计 `api/visit.php` —— 有两个真实运维隐患

机制本身合理：1×1 gif 像素、slug 校验 + live 校验、访客哈希（salt|date|ip|ua）、60s 去重、隐私友好（只存哈希）。像素由 `inject_generated_footer` 注入，且与生成页 CSP `img-src https://xlog.ink` 兼容——链路通。

但：
- **V1（中）：无鉴权写接口 + 无限流 → 可刷可膨胀。** 去重键含 UA，攻击者每次换 UA 即绕过 60s 去重，对任一 slug 无限灌 `page_visits`，既能刷高某页访问数，也能撑爆表。建议加一层粗粒度每-IP 插入上限（如 `ip_hash` 维度每分钟 N 条），或对同 `ip_hash+slug` 也做去重窗口。
- **V2（中）：`page_visits` 无清理。** `scripts/cleanup-tmp-assets.php` 清 tmp 图/preview/session/mail_events，但**没清 page_visits**（也没清 admin_login_attempts，不过那张表 admin.php 自身会清）。长期运行此表只增不减，配合 V1 可被放大。建议 cron 增加 `DELETE FROM page_visits WHERE created_at < (N 天前)`，按需保留聚合或加保留期。
- V3（低）：SQLite 单写入。高流量页面每访客每 60s 一次 INSERT，会与 chat/publish 的写竞争（有 `busy_timeout=5000` 兜底）。当前规模无碍，放量前需评估是否把访问写入改为追加日志 + 批量入库。

### AI 供应商回退 `ai_configs()` —— 设计正确

主配置 + `fallbacks[]` 深合并，逐个尝试。流式回退做了关键正确处理：**一旦已经吐出过 token 再失败就直接抛错**（`$emitted` 标志），不会把半截输出后的重试拼成乱码——这点很对。图片生成顺序回退、错误聚合到一条异常。

- I1（低）：`ai_download_image_url` 服务端下载图片 API 返回的任意 https URL（`FOLLOWLOCATION`，最多 3 跳）。URL 来自受信任的图片网关响应、非用户输入，SSRF 面有限；但若网关被攻破可指向内网 https 服务。建议把可下载主机限制到图片服务预期域名白名单，纵深防御。

---

## 第三部分 · UI / UX（本轮卡片统一专项）

本轮把 7 类卡片收敛为统一 TUI 调性，实测验收通过：

- **宽度统一**：新增 `--card-w` token，`.action-card` / `.upload-card` / `.live-preview-card` 三处实测均渲染为 580px，左右边缘对齐（此前 580/560/520 三套）。
- **标题统一**：`┌ 标题 ┐` 一种风格覆盖全部卡（含此前 18px 大字的 live-preview 和裸 `<strong>` 的 image-gen）。
- **字号/字重/按钮**：归一到 13/12 档、font-weight 600、`--btn-h: 36px`、`[ ]` 括号 + hover 填橘；保留"橘填主操作 / 墨黑描边默认 / 浅线 ghost"三级。
- **强调体系**：generate + publish-confirm 两张主操作卡统一橘色边框。
- 硬编码色 `#aaa49c`/`#f6f7fa` 收编为 `var(--muted)`/`var(--surface)`；缩略图统一 `.card-thumb`。

遗留（非阻断，cosmetic）：
- X1：按钮仍是 ~9 处独立 class 声明，视觉已对齐但未抽公共基类 `.tui-btn`；根治需结构性重构，风险中等，可后续单独处理。
- X2：admin.php 自带一套独立内联 CSS（非 TUI token 体系），与主应用风格相近但不共享变量。后台页面，影响可忽略。

---

## 第四部分 · 框架外行为系统盘点（本轮新增维度）

| 用户/外部行为 | 结果 | 判定 |
|---|---|---|
| 抢注 `www`/`admin`/`api`/`login` 前缀 | domain-check 返回 409 reserved | ✅ |
| 描述含"不是色情/内衣/泳装" | 否定剥离 + 软词 0.05 + 阈值 0.85 → 不误锁 | ✅ |
| 配图服务未配置/失败 | 明确报错 + 退额度，不发假图 | ✅ |
| 直接 GET `/api/visit.php?slug=x` 高频刷 | 无限流，可灌库刷量 | ✗ V1 |
| 长期运行 | page_visits 只增不减 | ✗ V2 |
| 未设 admin.token 且反代暴露 127.0.0.1 | 后台可能对全网开放 | △ A1（取决于部署）|
| 主 AI 供应商挂掉 | 自动回退备用供应商；已吐字后失败则报错不拼接 | ✅ |
| 图片网关返回 URL 而非 b64 | 服务端下载，限 https + 类型校验 | ✅（I1 可再收紧主机白名单）|
| 发布时整页截图 | 仍同步阻塞主流程最多 ~20s | △ 见 C1 |

---

## 第五部分 · 上轮遗留仍开放

| 来源 | 项 | 现状 |
|---|---|---|
| AUDIT-4 M1 | `capture_page_image` 在 publish 流程内同步 `exec` node+playwright，最多阻塞 20s；生产无 node 则每次白跑失败 exec | 仍同步（C1）。建议改发布后异步补截 + 一次性探测 node 可用性后缓存 |
| AUDIT-4 U3 | AI 配图等待无进度反馈，点"确认生成"后仅按钮 disabled（最长 180s） | 仍无进度提示 |
| 多轮 | 按钮 ~9 处独立 class 未抽公共基类 | 视觉已统一，结构未收敛（X1）|

---

## 第六部分 · 修复优先级建议

**尽快（运维/成本）**
1. **V1 visit.php 限流** —— 无鉴权写接口加每-IP 插入上限或 ip_hash 去重窗口
2. **V2 page_visits 清理** —— cleanup cron 增加保留期 DELETE
3. **A1 admin.token 部署约束** —— 写入部署清单 + 未配置时 error_log 警告

**迭代**
4. C1 截图异步化；U3 配图进度反馈
5. A2 admin cookie 用 X-Forwarded-Proto 判 Secure；I1 图片下载主机白名单
6. X1 按钮抽 `.tui-btn` 基类

**housekeeping**
- 工作区 `recent.html` 有未提交改动、`docs/AUDIT-4.md`/本文件未跟踪——按你的流程归并提交即可。

---

## 第七部分 · 与设计文档一致性

- AUDIT-4 的争议点（PUBLISH、成人判定）这轮已让代码、`ACTION-ROUTING-PLAN.md`、prompt 三者重新对齐，文档纪律恢复。
- 新增的 admin / visit / 供应商回退 / 图片 URL 下载属设计合理外延，建议补录进 `V2-DESIGN.md`（系统架构 + 数据库新增表 page_visits / admin_login_attempts + 新配置项 admin.* / analytics.salt / ai.*.fallbacks）。
- 整体结论：架构稳定、五轮审计未出现方向性偏移；当前距离"可放量上线"主要差**运维加固（限流 + 清理 + 截图异步）**和**真实生产服务回归**，而非架构问题。
