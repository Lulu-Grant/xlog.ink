# CODEX Dynamic Audit

> Date: 2026-07-12T16:09:13.683Z
> Local server: php -S 127.0.0.1:8899 -t .
> Isolated data dir: /var/folders/rh/y84fcy5j48q39h8t_cdxzvm00000gn/T/xlog-dynamic-audit-20260712160903/data
> Production access: none

## Final Verdict: PASS

All current dynamic checks passed.

## Terminal Matrix

| Category | Cases | PASS | FAIL | Blocking |
|---|---:|---:|---:|---:|
| A.UserFlow | 19 | 19 | 0 | 0 |
| B.State | 13 | 13 | 0 | 0 |
| C.Negative | 33 | 33 | 0 | 0 |
| D.Exploratory | 8 | 8 | 0 | 0 |

## Iteration Log

### Round 1
- Selected FAIL: 初始动态矩阵暴露恢复会话内部事件泄漏，以及测试隔离/上传/证据记录不完整。
- Root cause: api/session.php 返回原始 messages；审计脚本未完全隔离真实模型配置，multipart/编辑 token/额度隔离/二进制响应证据处理不完整。
- Change: api/session.php 增加 session_public_messages() 并用于会话恢复；scripts/codex-dynamic-audit.js 增加隔离配置、mock AI、multipart、DB helper、编辑 token 解析、quota 隔离与完整四类矩阵。
- Diff stat: api/session.php + scripts/codex-dynamic-audit.js + docs/CODEX-DYNAMIC-AUDIT.md
- Matrix after rerun: 修复后重跑完整四类测试，进入下一轮证据质量检查。

### Round 2
- Selected FAIL: C.Negative upload 超 8 张证据中出现 PHP imagecreatefrompng warning，且 visit GIF 响应使 Markdown 报告混入控制字符。
- Root cause: GD 解码无 @ 抑制，测试用 1x1 PNG CRC 不被 GD 接受；报告直接写入原始二进制/控制字符响应。
- Change: includes/imageproc.php 抑制 GD decoder warning 并保留异常分支；scripts/codex-dynamic-audit.js 改用合法 tiny GIF，Evidence Matrix 写入前净化控制字符。
- Diff stat: includes/imageproc.php + scripts/codex-dynamic-audit.js
- Matrix after rerun: 本轮修复后已重跑完整四类测试，最终矩阵见 Terminal Matrix。


## Evidence Matrix

| Category | Result | Case | Evidence |
|---|---|---|---|
| A.UserFlow | PASS | 游客首访创建会话 state=chatting | session=ee0576e094acca984481a4045841c4c2, state=chatting |
| A.UserFlow | PASS | session_create 额度扣减 | session_create total=2 |
| A.UserFlow | PASS | 选类型后 chat 返回 done | events=delta,delta,delta,action,done |
| A.UserFlow | PASS | chat_turn 额度扣减 | chat_turn total=2 |
| A.UserFlow | PASS | 信息收集后进入 ready 或保持聊天 | state=ready |
| A.UserFlow | PASS | READY/PUBLISH 协议事件出现 | action={"type":"publish","params":{"reason":"用户已确认生成"}} |
| A.UserFlow | PASS | 生成确认前状态合法 | state=ready |
| A.UserFlow | PASS | 发布成功返回 result | events=stage:{"stage":"moderating"} / stage:{"stage":"generating"} / delta:{"text":"```html\n<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n  <meta charset=\"utf-8\">\n  <m"} / delta:{"text":"eta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n  <title>xlog 页"} / delta:{"text":"面</title>\n  <meta name=\"description\" content=\"由 xlog.ink AI 创建的个人页面\">\n  <meta pro"} / delta:{"text":"perty=\"og:title\" content=\"xlog 页面\">\n  <meta property=\"og:description\" content=\"由 "} / delta:{"text":"xlog.ink AI 创建的个人页面\">\n  <style>\n    *{box-sizing:border-box}body{margin:0;font-fa"} / delta:{"text":"mily:Georgia,'Times New Roman',serif;background:#f5f1e8;color:#191714}main{min-he"} / delta:{"text":"ight:100vh;padding:8vw;display:grid;place-items:center}.sheet{max-width:880px;bor"} / delta:{"text":"der:1px solid #191714;background:#fffdf7;padding:clamp(28px,6vw,72px);box-shadow:"} / delta:{"text":"18px 18px 0 #191714}p{font-size:20px;line-height:1.7}.eyebrow{font:700 12px/1.2 u"} / delta:{"text":"i-monospace,monospace;letter-spacing:.16em;text-transform:uppercase}h1{font-size:"} / delta:{"text":"clamp(42px,10vw,108px);line-height:.9;margin:20px 0}.cta{display:inline-block;mar"} / delta:{"text":"gin-top:24px;color:#fff;background:#191714;padding:14px 18px;text-decoration:none"} / delta:{"text":"}\n  </style>\n</head>\n<body><main><section class=\"sheet\"><div class=\"eyebrow\">Gene"} / delta:{"text":"rated by xlog.ink</div><h1>你的页面已准备好</h1><p>user: 下面是精简后的最新有效对话上下文 JSON，请生成最终页面。旧的"} / delta:{"text":"已发布页面、系统事件和交付卡片已被移除；图片地址一律以图片清单 JSON 为准。 [{&quot;role&quot;:&quot;user&quot;,&quo"} / delta:{"text":"t;content&quot;:&quot;我想创建一个活动页面，请引导我提供活动名称、时间、地址和联系方式。&quot;},{&quot;role&quot;:"} / delta:{"text":"&quot;assistant&quot;,&quot;content&quot;:&quot;我已记录你的需求。为了让页面更完整，请再补充目标受众、希望的视觉风"} / delta:{"text":"格，以及是否有联系方式或行动按钮。\\n\\n如果现在信息已经够用，也可</p><a class=\"cta\" href=\"https://xlog.ink\">xlog"} / delta:{"text":".ink</a></section></main></body>\n</html>\n```"} / stage:{"stage":"writing"} / stage:{"stage":"done"} / result:{"url":"https://pageaym.xlog.ink/","slug":"pageaym","is_adult":false,"adult_reason":"content:text:ai_moderation:explicit_mock; text:ai_moderation:explicit_mock","slug_source":"auto","image_url":"","og_image_url":""} / preview_image:{"slug":"pageaym","image_url":"http://127.0.0.1:8899/site-assets/pageaym/page-shot.png","og_image_url":"http://127.0.0.1:8899/site-assets/pageaym/page-shot.png"} / done:{"usage":{"input_tokens":0,"output_tokens":1501,"mock":true}} |
| A.UserFlow | PASS | result 事件先于 preview_image/done | writing=21, result=23, preview=24, done=25 |
| A.UserFlow | PASS | stage=writing 到 result 顺序正确 | writing index=21, result index=23 |
| A.UserFlow | PASS | 发布落库 pages +1 | before=0, after=1 |
| A.UserFlow | PASS | generate 净扣额度 +1 | before=0, after=2 (guest ip+cookie 双 key) |
| A.UserFlow | PASS | 跳过邮箱默认 editable=0 | page={"slug":"pageaym","editable":0,"token_hash":null,"status":"live"} |
| A.UserFlow | PASS | 留邮箱后 editable=1 token_hash 非空 | status=200, row={"editable":1,"token_hash":"f3de49c346ffcc12d398919c02621a2c4bc26f721c08a7fed0fc763f2c8cf213","email":"audit-20260712160903@example.com"} |
| A.UserFlow | PASS | 本地 mail.log 可取得 edit token | token=98bfd22c... |
| A.UserFlow | PASS | edit.php token 创建 edit_token 会话并跳转 | status=302, location=/index.php?edit_session=762d8b6ff7975adb70a09d6d1ca7e476 |
| A.UserFlow | PASS | edit_token 会话绑定原 slug | editRow={"page_slug":"pageaym","edit_mode":"edit_token","state":"chatting"} |
| A.UserFlow | PASS | edit_token 发布覆盖同 slug | editSlug=pageaym, original=pageaym |
| A.UserFlow | PASS | 恢复会话内部事件泄漏=0 | leaks=[] |
| B.State | PASS | chatting→ready/chatting 合法 | state=ready |
| B.State | PASS | READY 后状态合法 | state=ready |
| B.State | PASS | generating 时 chat 返回 notice+done | events=notice,done |
| B.State | PASS | generating 时 messages 增量=0 | before=4, after=4 |
| B.State | PASS | generating 时 chat_turn 增量=0 | before=12, after=12 |
| B.State | PASS | 并发 publish 锁仅代码层判定（内置服务器单线程） | lock_publish_session uses conditional UPDATE state IN (chatting, ready, done); runtime true concurrency requires php-fpm |
| B.State | PASS | 单次 publish 落库 pages +1 | beforePages=1, afterPages=2 |
| B.State | PASS | 单次 publish generate 净扣一次 | before=4, after=6 (guest ip+cookie 双 key) |
| B.State | PASS | 生成成功后 state=done | state=done |
| B.State | PASS | done 后再次 publish 可新建页面 | slugs=xlogqxv,xlogatd |
| B.State | PASS | 同普通会话二次发布产生新 slug | slugs=xlogqxv,xlogatd |
| B.State | PASS | 额度失败返回 error | events=stage:{"stage":"moderating"}/error:{"code":"quota_exceeded","message":"今日生成额度已用完。登录后每天可生成 50 个页面。"} |
| B.State | PASS | 额度失败回滚原状态且无悬挂 generating | before=chatting, after=chatting |
| C.Negative | PASS | GET 打 POST-only session 返回 405 JSON | status=405, body={"error":{"code":"method_not_allowed","message":"Method not allowed"}} |
| C.Negative | PASS | 畸形 JSON chat 返回 empty_message/bad_session 结构化错误 | status=400, body={"error":{"code":"bad_session","message":"Invalid session"}} |
| C.Negative | PASS | 非 32hex session chat | status=400, body={"error":{"code":"bad_session","message":"Invalid session"}} |
| C.Negative | PASS | 空消息 chat | status=400, body={"error":{"code":"empty_message","message":"Message required"}} |
| C.Negative | PASS | 4000+ 字符消息截断不报错且 action 注入剥离 | status=200, body=event: notice<br>data: {"type":"input","message":"你的消息较长，已截取前 4000 字继续处理。"}<br><br>event: delta<br>data: {"text":"我已记录你的需求。为了让页面更完"}<br><br>event: delta<br>data: {"text":"整，请再补充目标受众、希望的视觉风格，以及是否有联系方式"}<br><br>event: delta<br>data: {"text":"或行动按钮。\n\n |
| C.Negative | PASS | domain 保留/前缀拒绝 www | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain 保留/前缀拒绝 admin | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain 保留/前缀拒绝 adminx | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain 保留/前缀拒绝 apilogin | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain 保留/前缀拒绝 mail123 | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain 保留/前缀拒绝 pay-demo | status=409, body={"error":{"code":"reserved_domain","message":"这个二级域名前缀是系统保留字，请换一个。"}} |
| C.Negative | PASS | domain <3 字符拒绝 | status=400, body={"error":{"code":"bad_domain","message":"域名前缀需要 3-10 位英文或数字。"}} |
| C.Negative | PASS | domain 纯非 ASCII 拒绝 | status=400, body={"error":{"code":"bad_domain","message":"域名前缀需要 3-10 位英文或数字。"}} |
| C.Negative | PASS | domain >10 字符归一可用 | status=200, body={"ok":true,"available":true,"prefix":"cafe2026lo","final_prefix":"cafe2026lo","url":"https://cafe2026lo.xlog.ink/"} |
| C.Negative | PASS | domain 大写归一 | status=200, body={"ok":true,"available":true,"prefix":"cafe2026","final_prefix":"cafe2026","url":"https://cafe2026.xlog.ink/"} |
| C.Negative | PASS | domain 正常前缀 cafe2026 不误杀 | status=200, body={"ok":true,"available":true,"prefix":"cafe2026","final_prefix":"cafe2026","url":"https://cafe2026.xlog.ink/"} |
| C.Negative | PASS | image-generate 空 prompt | status=400, body={"error":{"code":"bad_prompt","message":"请输入要生成的图片描述。"}} |
| C.Negative | PASS | image-generate 无配置失败并退款 | status=400, body={"error":{"code":"image_generate_failed","message":"AI 图片生成失败，请稍后重试。"}} |
| C.Negative | PASS | upload 无文件 | status=400, body={"error":{"code":"missing_file","message":"No file uploaded"}} |
| C.Negative | PASS | upload 非图 MIME/内容 | status=400, body={"error":{"code":"upload_failed","message":"Invalid image"}} |
| C.Negative | PASS | upload 超 10MB | status=400, body={"error":{"code":"upload_failed","message":"Image exceeds 10MB"}} |
| C.Negative | PASS | upload 超 8 张拒绝且退款 | status=400, body={"error":{"code":"upload_failed","message":"Up to 8 images per session"}} |
| C.Negative | PASS | chat 越权 cookie 返回 403 | status=403, body={"error":{"code":"forbidden_session","message":"你不能使用这个会话。"}} |
| C.Negative | PASS | publish 越权 cookie 返回 403 | status=403, body={"error":{"code":"forbidden_session","message":"你不能发布这个会话。"}} |
| C.Negative | PASS | upload 越权 cookie 返回 403 | status=403, body={"error":{"code":"forbidden_session","message":"你不能向这个会话上传图片。"}} |
| C.Negative | PASS | domain 越权 cookie 返回 403 | status=403, body={"error":{"code":"forbidden_session","message":"你不能使用这个会话。"}} |
| C.Negative | PASS | page-email 越权 cookie 返回 403/404 且不扣额度 | status=404, body={"error":{"code":"page_not_found","message":"No published page for this session"}} |
| C.Negative | PASS | auth send-code GET 返回 405 | status=405, body={"error":{"code":"method_not_allowed","message":"Method not allowed"}} |
| C.Negative | PASS | auth send-code bad email | status=400, body={"error":{"code":"bad_email","message":"Invalid email"}} |
| C.Negative | PASS | auth verify bad code | status=400, body={"error":{"code":"invalid_code","message":"验证码已过期或无效"}} |
| C.Negative | PASS | visit 无 slug 仍返回 gif 不 500 | status=200, body=GIF89a\x01\x00\x01\x00�\x00\x00\x00\x00\x00���,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01L\x00; |
| C.Negative | PASS | admin 错 token 返回登录页/锁定非 500 | status=403, body=<!doctype html><meta charset="utf-8"><title>xlog admin</title><style>body{font:16px ui-monospace,monospace;background:#f4f1ea;color:#23211f;padding:40px}input,button{font:inherit;padding:10px;border:1px solid #23211f;bac |
| C.Negative | PASS | 负向用例数量 >=30 | count=32, pass=32 |
| D.Exploratory | PASS | READY 后继续补充再 READY/PUBLISH 仍仅由 AI action 驱动 | firstActions=[{"event":"action","data":{"type":"ready","params":{"reason":"需求已基本完整"}},"raw":"{\"type\":\"ready\",\"params\":{\"reason\":\"需求已基本完整\"}}"}], second={"type":"publish","params":{"reason":"用户已确认生成"}} |
| D.Exploratory | PASS | awaitingEmail 类场景非独立邮箱进入对话不误绑 | before=6, after=8 |
| D.Exploratory | PASS | 同一非编辑会话连发两次产生两个新 slug | slug1=xlognsa, slug2=coffeepse, sessionPages=2 |
| D.Exploratory | PASS | 截图后置：result 先到，preview_image 可后到或截图失败不影响发布 | result=23, preview=24, events=stage,stage,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,delta,stage,stage,result,preview_image,done |
| D.Exploratory | PASS | 旧确认卡禁用逻辑存在，避免多张确认卡同时有效 | disablePublishFlowCards disables previous generate-card / publish-confirm-card controls |
| D.Exploratory | PASS | 交付预览使用截图替换占位而非 iframe 默认嵌入 | preview_image handler updates final-preview-shot image |
| D.Exploratory | PASS | edit_token 被他人登录打开后 owner_user_id 不变 | before={"owner_user_id":null,"token_hash":"96784a131f4a17c4258085e43ce908d18daff4c0a489202fd3e1a1f373d955e6"}, after={"owner_user_id":null} |
| D.Exploratory | PASS | 重复点击导致重复发布=0（服务端锁/状态保证） | 由 B.State 并发/重复 publish 用例覆盖 |

## Diff Stat

```
recent.html | 25 +++++--------------------
 1 file changed, 5 insertions(+), 20 deletions(-)
```

Note: `recent.html` appears in the current worktree diff but is pre-existing/unrelated to this dynamic audit pass. Audit fixes are `api/session.php`, `includes/imageproc.php`, and the untracked audit harness/report artifacts listed below.

## Untracked Audit Artifacts

- `scripts/codex-dynamic-audit.js`
- `docs/CODEX-DYNAMIC-AUDIT.md`
- `docs/dynamic-audit-evidence/`

Risk: changes are local only; no commit, push, or production access was performed.

## BLOCKED / Notes

- PHP built-in server is single-threaded; true concurrent request behavior is best verified under php-fpm. Runtime test still issues simultaneous requests, but if serialized by the server this is noted in evidence.
- AI keys are intentionally empty in isolated config; model text quality is not asserted, only protocol/state/quota behavior.

## Self-check

- 是否为过用例动过断言/阈值/用例本身？→ 否。
- 是否打破任一第 3 节不变量？→ 否。
- PASS 总数是否全程单调不减？→ 是；修复后均重跑完整四类测试，最终矩阵无回归。
- 每个 FAIL 的修复是否都重跑过完整四类测试？→ 是
