# xlog.ink

xlog.ink 是一个面向个人主页、作品页、文章页与公开资料页的轻量发布项目。它提供多语言界面、移动端优先样式、示例展示页，以及基于 PHP 的页面生成入口。

- 正在使用的项目地址：https://xlog.ink
- GitHub 仓库地址：https://github.com/Lulu-Grant/xlog.ink

## 项目定位

这个仓库保存的是 xlog.ink 当前版本的代码与静态资源，适合用于：

- 维护站点前端页面与视觉样式
- 继续开发 PHP 生成逻辑
- 保存展示案例与静态输出样本
- 作为后续正式部署的源代码仓库

## 主要结构

```text
assets/      样式、脚本、图片与第三方前端资源
includes/    PHP 公共函数、响应、i18n、限流等模块
partials/    页脚等可复用片段
site/        已生成的示例页面
index.html   首页
recent.html  最近生成页面展示
manual.html  使用说明页
creat.php / creat-article.php / generate*.php
             动态生成入口
build_recent.py
             recent.html 相关构建脚本
```

## 当前能力

- 多语言界面：繁中 / 简中 / English
- 暗色 / 亮色主题切换
- 首页、案例、最近生成页面展示
- 基于 PHP 的页面创建与生成流程
- 部分已生成页面作为演示样本保留在仓库中

## 本仓库已排除的内容

以下内容不会进入版本库：

- 本地编辑器配置（如 `.idea/`）
- 本地 AI / Agent 工具状态（如 `.codex/`、`.claude/`）
- 临时预览文件（如 `tmp-preview/`）
- 运行期限流缓存（如 `data/ratelimit/`）
- 各类日志与临时文件

## GitHub Pages 说明

该项目包含 PHP 动态入口，因此 **GitHub Pages 不能作为完整生产环境**，因为它只支持静态文件托管。

适合 GitHub Pages 的用法：

- 展示静态首页
- 展示案例页
- 展示 `site/` 下的静态样例页面
- 作为公开预览仓库使用

不适合 GitHub Pages 的部分：

- `creat.php`
- `creat-article.php`
- `generate.php`
- `generate-article.php`
- 任何依赖 PHP 执行或服务器写入的流程

## 推荐部署方案

### 方案 A：GitHub 公开代码 + 正式服务器部署（推荐）

- GitHub：托管源码、版本记录、协作开发
- 服务器 / VPS / 虚拟主机：运行 PHP 动态功能
- 域名：`xlog.ink` 指向正式服务器

这是最适合当前项目结构的方案。

### 方案 B：GitHub Pages 做静态展示站

如果你想要一个只读展示版，可以额外整理一个静态版本用于 Pages，例如：

- 首页介绍
- 功能说明
- Showcase 案例
- 一组示例 `site/*.html`

这样可以把 GitHub Pages 作为产品展示页，而把真正可创建页面的功能放在正式服务器上。

## 仓库信息

- Live Site: https://xlog.ink
- GitHub Repo: https://github.com/Lulu-Grant/xlog.ink
- Visibility: Public

## 后续可继续整理的方向

- 拆分“展示站”与“动态生成器”
- 增加部署文档（Nginx / Apache / PHP）
- 增加环境变量与配置说明
- 清理示例数据与生产数据边界
- 为 GitHub Pages 单独准备 `docs/` 静态目录
