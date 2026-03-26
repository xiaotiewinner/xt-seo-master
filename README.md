# XtSeoMaster

> XtSeoMaster 是一个面向 Typecho 的 SEO 增强插件，聚焦 Typecho 博客的常见需求：Meta 优化、AMP/MIP、搜索引擎主动推送与推送管理。

## 功能概览

- Meta Description / Keywords
- Open Graph
- Canonical 标签 & 分页 rel prev/next
- JSON-LD 结构化数据（Article / BreadcrumbList / WebSite）
- Sitemap XML 动态生成
- Robots.txt 生成
- 后台 SEO 评分面板
- AMP/MIP
- 索引自动/手动推送（百度普通推送、百度快速收录、IndexNow）
- 推送管理面板

## 安装步骤

1. 将 `XtSeoMaster` 文件夹上传到 `usr/plugins/XtSeoMaster/`
2. 在 Typecho 后台启用插件
3. 打开插件设置页并保存配置

## 主要路由

- `/sitemap.xml`
- `/robots.txt`
- `/ampindex/`
- `/amp/{target}`
- `/amp/list/{list_id}`
- `/mip/{target}`
- `/amp_sitemap.xml`
- `/mip_sitemap.xml`
- `/clean_cache`
- `/xt-seo/push-runner`
- `/xt-seo/save-seo`

## SEO 字段存储说明

文章/页面的自定义 SEO 字段存储在 Typecho `table.fields` 表中：

- `description`
- `keywords`

## 推送模式

提供两种推送方式：

- 自动推送（`realtime`）：在发布/保存 Hook 时触发
- 手动推送：通过推送管理页或 `push-runner` 接口触发

## 推送管理页能力

- 推送选中文章/页面到所有已启用搜索引擎
- 推送选中文章/页面（不包含 AMP/MIP）
- 全量推送已发布文章/页面
- 按搜索引擎展示聚合推送状态
- 删除选中文章对应的推送日志
- 展示最近推送记录与统计卡片

## 主题兼容说明

- 标准注入使用 `Widget_Archive->header`
- 在渲染阶段提供 `</head>` 前注入兜底
- 通过 footer 兼容脚本确保单页存在 `description/keywords` meta

以上机制用于提升非标准主题（如 Handsome 某些场景）下的兼容性。

## 许可证

本项目采用 MIT 协议开源，你可以自由使用、修改与分发。  
详情请查看仓库中的 `LICENSE` 文件。

### 署名要求

二次分发、修改发布或商用时，请保留原作者署名与链接：

- 作者：小铁
- 博客：https://www.xiaotiewinner.com
