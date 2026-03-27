<?php
/**
 * <strong style="color: #00bba7;">XtSeoMaster - 全方位 SEO 优化插件</strong>
 *
 * <ul style="color: #00bba7;">
 *  <li>Meta Description / Keywords</li>
 *  <li>Open Graph</li>
 *  <li>Canonical 标签 & 分页 rel prev/next</li>
 *  <li>JSON-LD 结构化数据（Article / BreadcrumbList / WebSite）</li>
 *  <li>Sitemap XML 动态生成</li>
 *  <li>Robots.txt 生成</li>
 *  <li>文章编辑 SEO 评分面板</li>
 *  <li>AMP/MIP</li>
 *  <li>Index主动推送（百度普通推送、百度快速收录、IndexNow）</li>
 *  <li>推送管理</li>
 * </ul>
 *
 * @package   XtSeoMaster
 * @author    小铁
 * @version   1.0.0
 * @link      https://www.xiaotiewinner.com
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/QueueRepository.php';
require_once __DIR__ . '/PushService.php';
require_once __DIR__ . '/AmpRenderer.php';
require_once __DIR__ . '/MipRenderer.php';

/**
 * XtSeoMaster 主插件类。
 *
 * 职责：
 * - 注册/卸载插件 Hook、路由、后台面板
 * - 注入前台 Meta/OG/Canonical/结构化数据
 * - 处理文章 SEO 字段保存与自动推送触发
 * - 注入编辑页 SEO 面板与异步保存脚本
 * - 兼容原生 Typecho 与 Handsome 等主题的 Head 输出
 */
class XtSeoMaster_Plugin implements Typecho_Plugin_Interface
{
    private static $headBufferStarted = false;
    private static $headFallbackArchive = null;

    // ─── 插件激活 ────────────────────────────────────────────────────────────

    public static function activate()
    {
        $repo = new XtSeoMaster_QueueRepository();
        $repo->installTables();

        // 在 <head> 内注入所有 SEO 标签
        Typecho_Plugin::factory('Widget_Archive')->header
            = array('XtSeoMaster_Plugin', 'injectHead');
        // 兼容部分主题未调用 $this->header()：渲染阶段兜底注入到 </head> 前
        Typecho_Plugin::factory('Widget_Archive')->beforeRender
            = array('XtSeoMaster_Plugin', 'bootstrapHeadFallback');

        // 在 </body> 前注入 JSON-LD（避免阻塞渲染）
        Typecho_Plugin::factory('Widget_Archive')->footer
            = array('XtSeoMaster_Plugin', 'injectFooter');

        // 保存文章时写入自定义 SEO 字段
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write
            = array('XtSeoMaster_Plugin', 'saveFields');

        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->write
            = array('XtSeoMaster_Plugin', 'saveFields');

        // 发布/更新时触发主动推送
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish
            = array('XtSeoMaster_Plugin', 'onPostPublish');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishSave
            = array('XtSeoMaster_Plugin', 'onPostSave');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish
            = array('XtSeoMaster_Plugin', 'onPagePublish');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishSave
            = array('XtSeoMaster_Plugin', 'onPageSave');

        // 在写文章/页面后台底部注入 SEO 评分面板
        Typecho_Plugin::factory('admin/write-post.php')->bottom
            = array('XtSeoMaster_Plugin', 'adminPanel');

        Typecho_Plugin::factory('admin/write-page.php')->bottom
            = array('XtSeoMaster_Plugin', 'adminPanel');

        // 注册自定义路由（sitemap.xml / robots.txt）
        Helper::addRoute(
            'xtseomaster_sitemap',
            '/sitemap.xml',
            'XtSeoMaster_Action',
            'sitemap'
        );

        Helper::addRoute(
            'xtseomaster_robots',
            '/robots.txt',
            'XtSeoMaster_Action',
            'robots'
        );

        Helper::addRoute(
            'xtseomaster_amp',
            '/amp/[cid:digital]',
            'XtSeoMaster_Action',
            'amp'
        );

        Helper::addRoute(
            'xtseomaster_mip',
            '/mip/[cid:digital]',
            'XtSeoMaster_Action',
            'mip'
        );

        Helper::addRoute(
            'xtseomaster_push_runner',
            '/xt-seo/push-runner',
            'XtSeoMaster_Action',
            'pushRunner'
        );
        Helper::addRoute(
            'xtseomaster_save_seo',
            '/xt-seo/save-seo',
            'XtSeoMaster_Action',
            'saveSeo'
        );

        // 兼容 Typecho-AMP 的常见路由形式
        Helper::addRoute('amp_index', '/ampindex/', 'XtSeoMaster_Action', 'AMPindex');
        Helper::addRoute('amp_map', '/amp/[target]', 'XtSeoMaster_Action', 'AMPpage');
        Helper::addRoute('amp_list', '/amp/list/[list_id]', 'XtSeoMaster_Action', 'AMPlist');
        Helper::addRoute('mip_map', '/mip/[target]', 'XtSeoMaster_Action', 'MIPpage');
        Helper::addRoute('amp_sitemap', '/amp_sitemap.xml', 'XtSeoMaster_Action', 'AMPSiteMap');
        Helper::addRoute('mip_sitemap', '/mip_sitemap.xml', 'XtSeoMaster_Action', 'MIPSiteMap');
        Helper::addRoute('clean_cache', '/clean_cache', 'XtSeoMaster_Action', 'cleancache');

        // 控制台菜单：推送管理
        $menuIndex = Helper::addMenu('XtSeoMaster');
        self::syncPushStatusPanel($menuIndex);

        return _t('XtSeoMaster 插件已激活，请前往设置页面完成配置。');
    }

    // ─── 插件停用 ────────────────────────────────────────────────────────────

    public static function deactivate()
    {
        Helper::removeRoute('xtseomaster_sitemap');
        Helper::removeRoute('xtseomaster_robots');
        Helper::removeRoute('xtseomaster_amp');
        Helper::removeRoute('xtseomaster_mip');
        Helper::removeRoute('xtseomaster_push_runner');
        Helper::removeRoute('xtseomaster_save_seo');
        Helper::removeRoute('amp_index');
        Helper::removeRoute('amp_map');
        Helper::removeRoute('amp_list');
        Helper::removeRoute('mip_map');
        Helper::removeRoute('amp_sitemap');
        Helper::removeRoute('mip_sitemap');
        Helper::removeRoute('clean_cache');

        $menuIndex = Helper::removeMenu('XtSeoMaster');
        Helper::removePanel($menuIndex, 'XtSeoMaster/PushStatus.php');
        Helper::removePanel($menuIndex, 'XtSeoMaster/Links.php');
        return _t('XtSeoMaster 插件已停用。');
    }

    // ─── 后台配置表单 ─────────────────────────────────────────────────────────

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        self::ensurePanelMigration();
        self::ensureRuntimeRoutes();

        $current = null;
        try {
            $current = Helper::options()->plugin('XtSeoMaster');
        } catch (Exception $e) {
            $current = null;
        }
        $tokenDefault = (is_object($current) && !empty($current->pushRunnerToken))
            ? $current->pushRunnerToken
            : substr(md5(uniqid('xtseomaster', true)), 0, 24);

        echo '<style>
            label[for^="xtseo_group_entry"],
            label[for^="xtseo_group_structured"],
            label[for^="xtseo_group_push"] {
                display: block;
                margin: 18px 0 0 0;
                margin-bottom: 0 !important;
                padding: 10px 12px;
                border: 1px solid #d9dee5;
                border-bottom: none;
                border-top-left-radius: 8px;
                border-top-right-radius: 8px;
                background: #f8fafc;
                color: #111827;
                font-size: 14px;
                font-weight: 700;
            }
            input[name="xtseo_group_entry"] ~ .description,
            input[name="xtseo_group_structured"] ~ .description,
            input[name="xtseo_group_push"] ~ .description {
                margin: 0 0 8px;
                padding: 10px;
                border: 1px solid #d9dee5;
                border-top: none;
                border-bottom-left-radius: 8px;
                border-bottom-right-radius: 8px;
                background: #f8fafc;
                color: #6b7280;
                font-size: 12px;
                line-height: 1.6;
            }
            ul.typecho-option:not([id^="typecho-option-item-xtseo_group_"]) {
                margin: 20px;
            }
            ul.typecho-option-submit {
                margin: 0px !important;
            }
            ul[id^="typecho-option-item-__cloudServerAd-"] {
                margin: 0px !important;
            }
        </style>';

        $cloudAd = new Typecho_Widget_Helper_Form_Element_Text(
            '__cloudServerAd',
            null,
            '祝你开心愉快！',
            _t('<a style="font-weight:bold;color:red;text-decoration:underline;" href="https://www.rainyun.com/xiaotie_" target="_blank" rel="noopener noreferrer">云服务器推荐：2H2G100M 30元/月</a><br><a style="font-weight:bold;color:red;text-decoration:underline;" href="https://www.xiaotiewinner.com/2025/vps-tuijian.html" target="_blank" rel="noopener noreferrer">其他云服务器推荐</a>')
        );
        $cloudAd->input->setAttribute('display', 'none');
        $form->addInput($cloudAd);

        $groupEntry = new Typecho_Widget_Helper_Form_Element_Text(
            'xtseo_group_entry',
            null,
            '',
            _t('页面生成与抓取入口'),
            _t('控制 AMP/MIP、Sitemap、Robots 与关键词兜底策略。')
        );
        $groupEntry->input->setAttribute('style', 'display:none');
        $form->addInput($groupEntry);

        $enableTitleKeywordFallback = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableTitleKeywordFallback',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用标题分词兜底关键词'),
            _t('当文章未设置 SEO 关键词且没有分类/标签时，自动从标题提取关键词。')
        );
        $form->addInput($enableTitleKeywordFallback);

        $enableAmp = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableAmp',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 AMP 页面'),
            _t('启用后可访问 /amp/{target}（支持 slug/cid），并可在文章页输出 amphtml 链接。')
        );
        $form->addInput($enableAmp);

        $enableMip = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableMip',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 MIP 页面'),
            _t('启用后可访问 /mip/{target}（支持 slug/cid），并可在文章页输出 miphtml 链接。')
        );
        $form->addInput($enableMip);

        $enableAmpIndex = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableAmpIndex',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 AMP 首页'),
            _t('启用后可访问 /ampindex/，用于 AMP 文章索引列表。')
        );
        $form->addInput($enableAmpIndex);

        $enableAmpMipLink = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableAmpMipLink',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('输出 AMP/MIP 关联链接'),
            _t('在文章页 Head 输出 amphtml/miphtml 链接。')
        );
        $form->addInput($enableAmpMipLink);

        $ampMipCacheTtl = new Typecho_Widget_Helper_Form_Element_Text(
            'ampMipCacheTtl',
            null,
            '600',
            _t('AMP/MIP 缓存 TTL（秒）'),
            _t('0 表示不缓存，建议 300-1800 秒。')
        );
        $form->addInput($ampMipCacheTtl);

        $enableSitemap = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableSitemap',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 Sitemap'),
            _t('关闭后 /sitemap.xml 将返回 404，且 robots 中的 Sitemap 行会自动移除。')
        );
        $form->addInput($enableSitemap);

        $sitemapTaxonomyMode = new Typecho_Widget_Helper_Form_Element_Select(
            'sitemapTaxonomyMode',
            array(
                'none' => '不包含分类和标签（默认）',
                'no_category' => '不包含分类',
                'no_tag' => '不包含标签',
                'all' => '全部包含',
            ),
            'none',
            _t('sitemap.xml 内容范围'),
            _t('控制 sitemap.xml 是否包含分类页和标签页（文章和独立文章默认囊括）。')
        );
        $form->addInput($sitemapTaxonomyMode);

        $enableAmpSitemap = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableAmpSitemap',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 AMP Sitemap'),
            _t('启用后可访问 /amp_sitemap.xml。')
        );
        $form->addInput($enableAmpSitemap);

        $enableMipSitemap = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableMipSitemap',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 MIP Sitemap'),
            _t('启用后可访问 /mip_sitemap.xml。')
        );
        $form->addInput($enableMipSitemap);

        // ── Sitemap ──
        $sitemapChangefreq = new Typecho_Widget_Helper_Form_Element_Select(
            'sitemapChangefreq',
            array(
                'always'  => 'always',
                'hourly'  => 'hourly',
                'daily'   => 'daily（推荐）',
                'weekly'  => 'weekly',
                'monthly' => 'monthly',
                'yearly'  => 'yearly',
                'never'   => 'never',
            ),
            'daily',
            _t('Sitemap 更新频率'),
            _t('告知搜索引擎预计的内容更新周期。')
        );
        $form->addInput($sitemapChangefreq);

        $sitemapPriority = new Typecho_Widget_Helper_Form_Element_Select(
            'sitemapPriority',
            array(
                '1.0' => '1.0',
                '0.9' => '0.9（推荐）',
                '0.8' => '0.8',
                '0.7' => '0.7',
                '0.5' => '0.5',
            ),
            '0.9',
            _t('文章默认优先级'),
            _t('0.0–1.0，首页固定为 1.0。')
        );
        $form->addInput($sitemapPriority);

        $enableRobotsTxt = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableRobotsTxt',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 Robots.txt'),
            _t('关闭后 /robots.txt 将返回 404。')
        );
        $form->addInput($enableRobotsTxt);

        // ── Robots.txt ──
        $robotsTxt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'robotsTxt',
            null,
            "User-agent: *\nDisallow: /admin/\nUser-agent: *\nAllow: /\nDisallow: /feed/\nDisallow: /admin/\nDisallow: /category\nDisallow: /tag\nDisallow: /search\n\nSitemap: {siteUrl}sitemap.xml",
            _t('Robots.txt 内容'),
            _t('支持占位符 {siteUrl}，将自动替换为站点根 URL。')
        );
        $form->addInput($robotsTxt);

        $groupStructured = new Typecho_Widget_Helper_Form_Element_Text(
            'xtseo_group_structured',
            null,
            '',
            _t('内容结构化与分享展示'),
            _t('控制 Open Graph 与 JSON-LD 输出能力。')
        );
        $groupStructured->input->setAttribute('style', 'display:none');
        $form->addInput($groupStructured);

        $enableOg = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableOg',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 Open Graph 标签'),
            _t('控制 og:title / og:description / og:image 等社交分享元标签输出。')
        );
        $form->addInput($enableOg);

        $defaultOgImage = new Typecho_Widget_Helper_Form_Element_Text(
            'defaultOgImage',
            null,
            '',
            _t('默认 OG 封面图 URL'),
            _t('当文章无图片时使用，推荐尺寸 1200×630px。')
        );
        $form->addInput($defaultOgImage);

        // ── JSON-LD ──
        $enableJsonLd = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableJsonLd',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 JSON-LD 结构化数据'),
            _t('输出 WebSite、BlogPosting、BreadcrumbList 等结构化数据。')
        );
        $form->addInput($enableJsonLd);

        $groupPush = new Typecho_Widget_Helper_Form_Element_Text(
            'xtseo_group_push',
            null,
            '',
            _t('主动推送策略'),
            _t('控制百度/IndexNow 推送，以及自动/手动推送模式。')
        );
        $groupPush->input->setAttribute('style', 'display:none');
        $form->addInput($groupPush);

        $enablePush = new Typecho_Widget_Helper_Form_Element_Radio(
            'enablePush',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用主动推送'),
            _t('总开关。关闭后不会触发自动推送，也无法在推送管理中手动推送。')
        );
        $form->addInput($enablePush);

        $pushMode = new Typecho_Widget_Helper_Form_Element_Select(
            'pushMode',
            array(
                'realtime' => '自动推送',
                'manual' => '手动推送'
            ),
            'realtime',
            _t('推送模式'),
            _t('自动：发布文章即推送。手动：仅在“推送管理”页面触发推送。')
        );
        $form->addInput($pushMode);

        $pushEnableBaidu = new Typecho_Widget_Helper_Form_Element_Radio(
            'pushEnableBaidu',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用百度普通推送'),
            _t('使用百度站长普通推送接口，需填写百度 Token。')
        );
        $form->addInput($pushEnableBaidu);

        $pushEnableBaiduDaily = new Typecho_Widget_Helper_Form_Element_Radio(
            'pushEnableBaiduDaily',
            array('1' => '启用', '0' => '停用'),
            '0',
            _t('启用百度快速收录推送'),
            _t('对应百度 daily 接口。请确认站点具备快速收录权限后再开启。')
        );
        $form->addInput($pushEnableBaiduDaily);

        $pushBaiduToken = new Typecho_Widget_Helper_Form_Element_Text(
            'pushBaiduToken',
            null,
            '',
            _t('百度推送 Token'),
            _t('在 <a href="https://ziyuan.baidu.com/linksubmit/index" target="_blank">百度搜索资源平台</a> 获取，只填写接口调用地址中token=后面的内容。留空时百度相关推送将失败。')
        );
        $form->addInput($pushBaiduToken);

        $pushEnableIndexNow = new Typecho_Widget_Helper_Form_Element_Radio(
            'pushEnableIndexNow',
            array('1' => '启用', '0' => '停用'),
            '1',
            _t('启用 IndexNow 推送'),
            _t('推送到支持 IndexNow 的搜索引擎，需配置有效 Token。')
        );
        $form->addInput($pushEnableIndexNow);

        $pushIndexNowKey = new Typecho_Widget_Helper_Form_Element_Text(
            'pushIndexNowKey',
            null,
            '',
            _t('IndexNow Token'),
            _t('只需填写 Token，在 <a href="https://www.bing.com/indexnow/getstarted" target="_blank">IndexNow</a> 获取 API Key。插件会自动以该 Token 推送，并在站点根目录创建 {Token}.txt 验证文件。')
        );
        $form->addInput($pushIndexNowKey);

        $pushRunnerToken = new Typecho_Widget_Helper_Form_Element_Text(
            'pushRunnerToken',
            null,
            $tokenDefault,
            _t('手动推送 Token'),
            _t('用于 /xt-seo/push-runner 安全鉴权，仅手动推送接口使用。')
        );
        $form->addInput($pushRunnerToken);
    }

    // 个人配置（此插件无需个人配置）
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    private static function ensureRuntimeRoutes()
    {
        // In some environments users update plugin files without re-activating.
        // Re-register critical action routes at runtime to avoid 404.
        try {
            Helper::addRoute(
                'xtseomaster_save_seo',
                '/xt-seo/save-seo',
                'XtSeoMaster_Action',
                'saveSeo'
            );
        } catch (Exception $e) {
        }
    }

    public static function ensurePanelMigration()
    {
        $panelTable = Helper::options()->panelTable;
        $parents = isset($panelTable['parent']) ? (array) $panelTable['parent'] : array();
        $menuPos = array_search('XtSeoMaster', $parents, true);
        if ($menuPos === false) {
            return;
        }
        $menuIndex = intval($menuPos) + 10;
        self::syncPushStatusPanel($menuIndex);
    }

    private static function syncPushStatusPanel($menuIndex)
    {
        Helper::removePanel($menuIndex, 'PushStatus.php');
        Helper::removePanel($menuIndex, 'PushStatus.php/');
        Helper::removePanel($menuIndex, 'XtSeoMaster/PushStatus.php');
        Helper::removePanel($menuIndex, 'XtSeoMaster/Links.php');
        Helper::addPanel(
            $menuIndex,
            'XtSeoMaster/PushStatus.php',
            _t('推送管理'),
            _t('推送管理'),
            'administrator'
        );
    }

    // ─── HEAD 注入：Meta / OG / Canonical ─────────────────────────────────────

    public static function injectHead($archive)
    {
        if (!is_object($archive) || !method_exists($archive, 'is')) {
            try {
                $archive = Typecho_Widget::widget('Widget_Archive');
            } catch (Exception $e) {
                return;
            }
            if (!is_object($archive) || !method_exists($archive, 'is')) {
                return;
            }
        }

        $options  = Helper::options()->plugin('XtSeoMaster');
        $globalOptions = Helper::options();
        $siteUrl  = rtrim($globalOptions->siteUrl, '/') . '/';
        $siteName = $globalOptions->title;
        $defaultDesc = !empty($globalOptions->description)
            ? $globalOptions->description
            : '';

        // ── 收集页面信息 ──────────────────────────────────────────────
        $pageTitle = '';
        $pageDesc  = '';
        $pageKw    = '';
        $pageUrl   = $siteUrl;
        $ogType    = 'website';
        $ogImage   = !empty($options->defaultOgImage) ? $options->defaultOgImage : '';

        if ($archive->is('single')) {
            // 文章 / 独立页
            $cid     = $archive->cid;
            $customDesc = self::resolveContentFieldValue($archive, $cid, array('description', 'seo_desc'));
            $customKeywords = self::resolveContentFieldValue($archive, $cid, array('keywords', 'seo_keywords'));

            $pageTitle = $archive->title;
            $pageDesc  = !empty($customDesc)
                ? $customDesc
                : self::autoExcerpt($archive->text, 160);
            $enableTitleFallback = !isset($options->enableTitleKeywordFallback)
                || $options->enableTitleKeywordFallback == '1';
            $pageKw = !empty($customKeywords)
                ? $customKeywords
                : self::collectKeywordNames($archive, $enableTitleFallback);
            $pageUrl   = $archive->permalink;
            $ogType    = 'article';

            // 提取文章第一张图片
            $firstImg = self::extractFirstImage($archive->text);
            if ($firstImg) {
                $ogImage = $firstImg;
            }
        } elseif ($archive->is('index') || $archive->is('page')) {
            $pageTitle = $siteName;
            $pageDesc  = $defaultDesc;
            $pageUrl   = $siteUrl;

            // 分页
            $currentPage = $archive->getCurrentPage();
            if ($currentPage > 1) {
                $pageUrl = $siteUrl . 'page/' . $currentPage . '/';
            }
        } elseif ($archive->is('category')) {
            $pageTitle = $archive->category;
            $pageDesc  = $archive->description
                ? strip_tags($archive->description)
                : $defaultDesc;
            $pageKw    = $archive->category;
            $pageUrl   = $archive->archive->permalink;
        } elseif ($archive->is('tag')) {
            $pageTitle = $archive->keywords;
            $pageDesc  = $defaultDesc;
            $pageKw    = $archive->keywords;
        }

        $pageDesc = htmlspecialchars(trim($pageDesc), ENT_QUOTES, 'UTF-8');
        $pageKw   = htmlspecialchars(trim($pageKw), ENT_QUOTES, 'UTF-8');
        $pageUrl  = htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8');
        $ogImage  = htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8');

        echo "\n<!-- XtSeoMaster: Meta Tags -->\n";

        // ── Meta Description & Keywords ──
        if ($pageDesc) {
            echo '<meta name="description" content="' . $pageDesc . '">' . "\n";
        }
        if ($pageKw) {
            echo '<meta name="keywords" content="' . $pageKw . '">' . "\n";
        }

        // ── Canonical ──
        echo '<link rel="canonical" href="' . $pageUrl . '">' . "\n";

        $enableAmpMipLink = !isset($options->enableAmpMipLink) || $options->enableAmpMipLink == '1';
        if ($enableAmpMipLink && $archive->is('single')) {
            $target = !empty($archive->slug) ? $archive->slug : (string) intval($archive->cid);
            if ((!isset($options->enableAmp) || $options->enableAmp == '1') && $target !== '') {
                try {
                    $ampUrl = rtrim($siteUrl, '/') . '/amp/' . rawurlencode($target);
                    echo '<link rel="amphtml" href="' . htmlspecialchars($ampUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                } catch (Exception $e) {
                }
            }
            if ((!isset($options->enableMip) || $options->enableMip == '1') && $target !== '') {
                try {
                    $mipUrl = rtrim($siteUrl, '/') . '/mip/' . rawurlencode($target);
                    echo '<link rel="miphtml" href="' . htmlspecialchars($mipUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                } catch (Exception $e) {
                }
            }
        }

        // ── 分页 rel prev/next（仅列表归档页，且失败时静默跳过） ──
        $isListArchive = false;
        try {
            $isListArchive = $archive->is('index')
                || $archive->is('category')
                || $archive->is('tag')
                || $archive->is('author')
                || $archive->is('search');
        } catch (Exception $e) {
            $isListArchive = false;
        }

        if ($isListArchive && method_exists($archive, 'getCurrentPage')) {
            try {
                $currentPage = intval($archive->getCurrentPage());
                $totalPage = 0;
                if (method_exists($archive, 'getTotalPage')) {
                    $totalPage = intval($archive->getTotalPage());
                }

                if ($currentPage > 1) {
                    $prevUrl = ($currentPage == 2)
                        ? $siteUrl
                        : $siteUrl . 'page/' . ($currentPage - 1) . '/';
                    echo '<link rel="prev" href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                }
                if ($totalPage > 0 && $currentPage < $totalPage) {
                    $nextUrl = $siteUrl . 'page/' . ($currentPage + 1) . '/';
                    echo '<link rel="next" href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
                }
            } catch (Exception $e) {
                // 某些上下文下 Archive 未完成分页查询，跳过分页 rel 输出避免前台报错
            }
        }

        $enableOg = !isset($options->enableOg) || $options->enableOg == '1';
        if ($enableOg) {
            // ── Open Graph ──
            echo "\n<!-- XtSeoMaster: Open Graph -->\n";
            echo '<meta property="og:type" content="' . $ogType . '">' . "\n";
            echo '<meta property="og:url" content="' . $pageUrl . '">' . "\n";
            echo '<meta property="og:title" content="' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            echo '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            if ($pageDesc) {
                echo '<meta property="og:description" content="' . $pageDesc . '">' . "\n";
            }
            if ($ogImage) {
                echo '<meta property="og:image" content="' . $ogImage . '">' . "\n";
                echo '<meta property="og:image:width" content="1200">' . "\n";
                echo '<meta property="og:image:height" content="630">' . "\n";
            }
            if ($ogType === 'article' && $archive->is('single')) {
                echo '<meta property="article:published_time" content="'
                    . date('c', $archive->created) . '">' . "\n";
                echo '<meta property="article:modified_time" content="'
                    . date('c', $archive->modified) . '">' . "\n";
            }
        }

        echo "\n";
    }

    // ─── FOOTER 注入：JSON-LD ─────────────────────────────────────────────────

    public static function injectFooter($archive)
    {
        if (!is_object($archive) || !method_exists($archive, 'is')) {
            try {
                $archive = Typecho_Widget::widget('Widget_Archive');
            } catch (Exception $e) {
                return;
            }
            if (!is_object($archive) || !method_exists($archive, 'is')) {
                return;
            }
        }

        $options  = Helper::options()->plugin('XtSeoMaster');
        if (empty($options->enableJsonLd) || $options->enableJsonLd == '0') {
            return;
        }

        $globalOptions = Helper::options();
        $siteUrl  = rtrim($globalOptions->siteUrl, '/') . '/';
        $siteName = $globalOptions->title;

        $schemas = array();

        // WebSite + SearchAction（全局输出）
        $schemas[] = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            'name'            => $siteName,
            'url'             => $siteUrl,
            'potentialAction' => array(
                '@type'       => 'SearchAction',
                'target'      => array(
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $siteUrl . '?s={search_term_string}',
                ),
                'query-input' => 'required name=search_term_string',
            ),
        );

        // 文章页：Article + BreadcrumbList
        if ($archive->is('single')) {
            $customDesc = self::resolveContentFieldValue($archive, intval($archive->cid), array('description', 'seo_desc'));

            $desc = !empty($customDesc)
                ? $customDesc
                : self::autoExcerpt($archive->text, 200);

            $firstImg = self::extractFirstImage($archive->text);
            $imgUrl   = $firstImg ?: (!empty($options->defaultOgImage) ? $options->defaultOgImage : '');

            $articleSchema = array(
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $archive->title,
                'description'      => $desc,
                'url'              => $archive->permalink,
                'datePublished'    => date('c', $archive->created),
                'dateModified'     => date('c', $archive->modified),
                'author'           => array(
                    '@type' => 'Person',
                    'name'  => $archive->author->screenName,
                    'url'   => $siteUrl,
                ),
                'publisher'        => array(
                    '@type' => 'Organization',
                    'name'  => $siteName,
                    'url'   => $siteUrl,
                ),
            );

            if ($imgUrl) {
                $articleSchema['image'] = array(
                    '@type'  => 'ImageObject',
                    'url'    => $imgUrl,
                    'width'  => 1200,
                    'height' => 630,
                );
            }

            $schemas[] = $articleSchema;

            // BreadcrumbList
            $breadcrumbItems = array(
                array(
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => $siteName,
                    'item'     => $siteUrl,
                ),
            );

            // 尝试获取分类
            $cats = $archive->categories;
            if (!empty($cats) && isset($cats[0])) {
                $cat = $cats[0];
                $breadcrumbItems[] = array(
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => $cat['name'],
                    'item'     => Typecho_Router::url(
                        'category',
                        array('slug' => $cat['slug']),
                        $siteUrl
                    ),
                );
                $breadcrumbItems[] = array(
                    '@type'    => 'ListItem',
                    'position' => 3,
                    'name'     => $archive->title,
                    'item'     => $archive->permalink,
                );
            } else {
                $breadcrumbItems[] = array(
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => $archive->title,
                    'item'     => $archive->permalink,
                );
            }

            $schemas[] = array(
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $breadcrumbItems,
            );
        }

        echo "\n<!-- XtSeoMaster: JSON-LD -->\n";
        foreach ($schemas as $schema) {
            echo '<script type="application/ld+json">'
                . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                . '</script>' . "\n";
        }

        // 兼容部分主题未调用 $this->header()：在前端运行时兜底补齐 description/keywords
        self::emitHeadMetaCompatScript($archive);
        // 前台控制台签名
        self::emitConsoleSignatureScript();
    }

    public static function bootstrapHeadFallback($archive)
    {
        if (self::$headBufferStarted) {
            return;
        }
        self::$headFallbackArchive = $archive;
        self::$headBufferStarted = true;
        ob_start(array('XtSeoMaster_Plugin', 'injectHeadIntoBufferedHtml'));
    }

    public static function injectHeadIntoBufferedHtml($html)
    {
        self::$headBufferStarted = false;
        if (!is_string($html) || $html === '') {
            return $html;
        }
        if (strpos($html, '<!-- XtSeoMaster: Meta Tags -->') !== false) {
            return $html;
        }
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $archive = self::$headFallbackArchive;
        if (!is_object($archive)) {
            return $html;
        }

        ob_start();
        self::injectHead($archive);
        $headHtml = ob_get_clean();
        if (!is_string($headHtml) || trim($headHtml) === '') {
            return $html;
        }

        return preg_replace('/<\/head>/i', $headHtml . "\n</head>", $html, 1);
    }

    private static function emitHeadMetaCompatScript($archive)
    {
        if (!is_object($archive) || !method_exists($archive, 'is') || !$archive->is('single')) {
            return;
        }

        $options = Helper::options()->plugin('XtSeoMaster');
        $desc = self::resolveContentFieldValue($archive, intval($archive->cid), array('description', 'seo_desc'));
        $keywords = self::resolveContentFieldValue($archive, intval($archive->cid), array('keywords', 'seo_keywords'));
        if ($desc === '') {
            $desc = self::autoExcerpt($archive->text, 160);
        }
        if ($keywords === '') {
            $enableTitleFallback = !isset($options->enableTitleKeywordFallback)
                || $options->enableTitleKeywordFallback == '1';
            $keywords = self::collectKeywordNames($archive, $enableTitleFallback);
        }

        $descJs = json_encode((string) $desc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $kwJs = json_encode((string) $keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<script>(function(){'
            . 'var d=document,h=d.getElementsByTagName("head")[0];if(!h){return;}'
            . 'function ensureMeta(n,v){if(!v){return;}var m=h.querySelector(\'meta[name="\'+n+\'"]\');'
            . 'if(!m){m=d.createElement("meta");m.setAttribute("name",n);h.appendChild(m);}if(!m.getAttribute("content")){m.setAttribute("content",v);}}'
            . 'ensureMeta("description",' . $descJs . ');'
            . 'ensureMeta("keywords",' . $kwJs . ');'
            . '})();</script>' . "\n";
    }

    private static function emitConsoleSignatureScript()
    {
        echo '<script>(function(){'
            . 'if(typeof console==="undefined"||typeof console.log!=="function"){return;}'
            . 'console.log("\\n %c XtSeoMaster v1.0.0 %c for www.xiaotiewinner.com","color:#777;background:linear-gradient(90deg,#dbeafe,#e0e7ff,#f5d0fe);padding:6px 10px;border-radius:6px 0 0 6px;font-weight:600;","color:#64748b;background:#f8fafc;padding:6px 12px;border-radius:0 6px 6px 0;");'
            . '})();</script>' . "\n";
    }

    // ─── 保存自定义 SEO 字段 ──────────────────────────────────────────────────

    public static function saveFields($contents, $class)
    {
        $request = $class->request;

        $postedFields = (is_array($_POST) && isset($_POST['fields']) && is_array($_POST['fields']))
            ? $_POST['fields']
            : array();
        $hasDesc = array_key_exists('description', $postedFields)
            || (is_array($_POST) && array_key_exists('description', $_POST))
            || (is_array($_POST) && array_key_exists('seo_desc', $_POST));
        $hasKeywords = array_key_exists('keywords', $postedFields)
            || (is_array($_POST) && array_key_exists('keywords', $_POST))
            || (is_array($_POST) && array_key_exists('seo_keywords', $_POST));
        if (!$hasDesc && !$hasKeywords) {
            return;
        }

        $descValue = $hasDesc
            ? (isset($postedFields['description']) ? $postedFields['description'] : (is_array($_POST) && array_key_exists('description', $_POST) ? $_POST['description'] : $request->get('seo_desc', '')))
            : null;
        $keywordsValue = $hasKeywords
            ? (isset($postedFields['keywords']) ? $postedFields['keywords'] : (is_array($_POST) && array_key_exists('keywords', $_POST) ? $_POST['keywords'] : $request->get('seo_keywords', '')))
            : null;

        // cid 在 write 回调中由 $contents['cid'] 提供
        $cid = isset($contents['cid']) ? intval($contents['cid']) : 0;
        if (!$cid) {
            return;
        }

        $db = Typecho_Db::get();

        // 辅助：更新或插入自定义字段
        $upsertField = function ($name, $value) use ($db, $cid) {
            $exist = $db->fetchRow(
                $db->select('cid')->from('table.fields')
                   ->where('cid = ?', $cid)
                   ->where('name = ?', $name)
            );
            if ($exist) {
                $db->query(
                    $db->update('table.fields')
                       ->rows(array('str_value' => $value, 'type' => 'str'))
                       ->where('cid = ?', $cid)
                       ->where('name = ?', $name)
                );
            } else {
                $db->query(
                    $db->insert('table.fields')
                       ->rows(array(
                           'cid'       => $cid,
                           'name'      => $name,
                           'type'      => 'str',
                           'str_value' => $value,
                           'int_value' => 0,
                           'float_value' => 0,
                       ))
                );
            }
        };

        if ($hasDesc) {
            $upsertField('description', trim((string) $descValue));
        }
        if ($hasKeywords) {
            $upsertField('keywords', trim((string) $keywordsValue));
        }
    }

    // ─── 发布/更新触发：主动推送 ────────────────────────────────────────────────

    public static function onPostPublish()
    {
        self::handlePushTrigger(func_get_args(), 'post');
    }

    public static function onPostSave()
    {
        self::handlePushTrigger(func_get_args(), 'post');
    }

    public static function onPagePublish()
    {
        self::handlePushTrigger(func_get_args(), 'page');
    }

    public static function onPageSave()
    {
        self::handlePushTrigger(func_get_args(), 'page');
    }

    private static function handlePushTrigger($args, $fallbackType)
    {
        try {
            $options = Helper::options()->plugin('XtSeoMaster');
        } catch (Exception $e) {
            return;
        }

        if (!isset($options->enablePush) || $options->enablePush != '1') {
            return;
        }

        $row = self::normalizeHookContentRow($args, $fallbackType);
        if (empty($row) || empty($row['cid'])) {
            return;
        }
        if (!isset($row['status']) || $row['status'] !== 'publish') {
            return;
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $url = self::buildContentPermalink($row, $siteUrl);
        if ($url === '') {
            return;
        }

        $engines = XtSeoMaster_PushService::enabledEngines($options);
        if (empty($engines)) {
            return;
        }

        $repo = new XtSeoMaster_QueueRepository();
        $pushMode = isset($options->pushMode) ? trim((string) $options->pushMode) : 'realtime';
        // Only explicit "realtime" can trigger publish-time push.
        // Any unknown/legacy values should behave as manual mode.
        if ($pushMode !== 'realtime') {
            return;
        }

        foreach ($engines as $engine) {
            try {
                $result = XtSeoMaster_PushService::pushUrl($engine, $url, $options, $siteUrl);
                $repo->logPushResult(
                    0,
                    $engine,
                    $url,
                    $result['request_payload'],
                    $result['http_code'],
                    $result['response'],
                    !empty($result['success']),
                    $result['error']
                );
            } catch (Exception $e) {
                // Never break publish/save flow because of push failure.
                try {
                    $repo->logPushResult(
                        0,
                        $engine,
                        $url,
                        '',
                        0,
                        '',
                        false,
                        'push exception: ' . $e->getMessage()
                    );
                } catch (Exception $ignored) {
                }
            }
        }
    }

    private static function normalizeHookContentRow($args, $fallbackType)
    {
        $row = array();
        $candidates = is_array($args) ? $args : array($args);

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                if (isset($candidate['contents']) && is_array($candidate['contents'])) {
                    $row = array_merge($row, $candidate['contents']);
                }
                $row = array_merge($row, $candidate);
                continue;
            }
            if (is_object($candidate)) {
                // Try public/magic properties first.
                foreach (array('cid', 'status', 'visibility', 'type', 'slug') as $k) {
                    try {
                        if (isset($candidate->$k)) {
                            $row[$k] = $candidate->$k;
                        }
                    } catch (Exception $e) {
                    }
                }
                // Some hooks pass nested contents object/array.
                try {
                    if (isset($candidate->contents)) {
                        $contents = $candidate->contents;
                        if (is_array($contents)) {
                            $row = array_merge($row, $contents);
                        } elseif (is_object($contents)) {
                            foreach (array('cid', 'status', 'visibility', 'type', 'slug') as $k) {
                                try {
                                    if (isset($contents->$k)) {
                                        $row[$k] = $contents->$k;
                                    }
                                } catch (Exception $e) {
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                }
            }
        }

        if (!isset($row['status']) && isset($row['visibility'])) {
            $row['status'] = $row['visibility'];
        }
        if (!isset($row['type']) || $row['type'] === '') {
            $row['type'] = $fallbackType;
        }

        if (!isset($row['cid']) || intval($row['cid']) <= 0 || !isset($row['status']) || $row['status'] === '') {
            $cid = isset($row['cid']) ? intval($row['cid']) : 0;
            if ($cid > 0) {
                $db = Typecho_Db::get();
                $dbRow = $db->fetchRow(
                    $db->select('status', 'type', 'slug', 'cid')
                        ->from('table.contents')
                        ->where('cid = ?', $cid)
                        ->limit(1)
                );
                if (!empty($dbRow)) {
                    $row = array_merge($dbRow, $row);
                }
            }
        }

        return $row;
    }

    public static function buildContentPermalink($row, $siteUrl)
    {
        $cid = intval(isset($row['cid']) ? $row['cid'] : 0);
        if ($cid <= 0) {
            return '';
        }

        $db = Typecho_Db::get();
        $dbRow = $db->fetchRow(
            $db->select('cid', 'slug', 'type', 'created')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->limit(1)
        );
        if (empty($dbRow)) {
            return '';
        }

        $type = !empty($dbRow['type']) ? $dbRow['type'] : (isset($row['type']) ? $row['type'] : 'post');
        if ($type === 'post') {
            // 兼容 {category}/{directory} 路由参数，取第一分类作为主分类
            $cat = $db->fetchRow(
                $db->select('m.slug')
                    ->from('table.relationships AS r')
                    ->join('table.metas AS m', 'm.mid = r.mid')
                    ->where('r.cid = ?', $cid)
                    ->where('m.type = ?', 'category')
                    ->order('m.order', Typecho_Db::SORT_ASC)
                    ->limit(1)
            );
            if (!empty($cat['slug'])) {
                $dbRow['category'] = $cat['slug'];
                $dbRow['directory'] = $cat['slug'];
            }
        }

        try {
            $url = Typecho_Router::url($type, $dbRow, $siteUrl);
            return self::normalizeRouteUrl($url, $dbRow);
        } catch (Exception $e) {
            return '';
        }
    }

    private static function normalizeRouteUrl($url, $row)
    {
        $link = (string) $url;
        if ($link === '') {
            return '';
        }

        if (strpos($link, '{') !== false) {
            $created = isset($row['created']) ? intval($row['created']) : 0;
            $replacements = array(
                '{cid}' => isset($row['cid']) ? (string) $row['cid'] : '',
                '{slug}' => isset($row['slug']) ? (string) $row['slug'] : '',
                '{category}' => isset($row['category']) ? (string) $row['category'] : '',
                '{directory}' => isset($row['directory']) ? (string) $row['directory'] : '',
                '{year}' => $created > 0 ? date('Y', $created) : '',
                '{month}' => $created > 0 ? date('m', $created) : '',
                '{day}' => $created > 0 ? date('d', $created) : '',
                '{hour}' => $created > 0 ? date('H', $created) : '',
                '{minute}' => $created > 0 ? date('i', $created) : '',
                '{second}' => $created > 0 ? date('s', $created) : ''
            );
            $link = strtr($link, $replacements);
        }

        return (strpos($link, '{') === false && strpos($link, '}') === false) ? $link : '';
    }

    // ─── 后台 SEO 评分面板 ────────────────────────────────────────────────────

    public static function adminPanel()
    {
        self::ensureRuntimeRoutes();
        $pluginUrl = Helper::options()->pluginUrl . '/XtSeoMaster';
        $seoSaveUrl = Typecho_Common::url('xt-seo/save-seo', Helper::options()->index);
        $seoSaveUrlFallback = rtrim(Helper::options()->siteUrl, '/') . '/xt-seo/save-seo';

        // 读取当前文章已保存的字段（编辑模式）
        $cid         = 0;
        $seoDesc     = '';
        $seoKeywords = '';

        $request = Typecho_Request::getInstance();
        if ($request->get('cid')) {
            $cid = intval($request->get('cid'));
            $db  = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('str_value', 'name')
                   ->from('table.fields')
                   ->where('cid = ?', $cid)
                   ->where('name IN ?', array('description', 'keywords', 'seo_desc', 'seo_keywords'))
            );
            foreach ($rows as $row) {
                if ($row['name'] === 'description' || ($row['name'] === 'seo_desc' && $seoDesc === '')) {
                    $seoDesc = $row['str_value'];
                }
                if ($row['name'] === 'keywords' || ($row['name'] === 'seo_keywords' && $seoKeywords === '')) {
                    $seoKeywords = $row['str_value'];
                }
            }
        }

        $seoDesc     = htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8');
        $seoKeywords = htmlspecialchars($seoKeywords, ENT_QUOTES, 'UTF-8');
        ?>
<div id="xt-seomaster-panel" style="
    margin: 20px 0;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #fafafa;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    overflow: hidden;
">
    <div id="xt-seomaster-header" style="
        background: #2c3e50;
        color: #fff;
        padding: 10px 16px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        user-select: none;
    ">
        <strong>🔍 XtSeoMaster SEO 优化</strong>
        <span id="xt-seomaster-score-badge" style="
            background: #27ae60;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: bold;
        ">评分：--</span>
        <span id="xt-seomaster-toggle" style="font-size: 16px;">▾</span>
    </div>

    <div id="xt-seomaster-body" style="padding: 16px;">
        <!-- SEO 字段输入 -->
        <div style="margin-bottom: 14px;">
            <label style="display:block; font-weight:600; margin-bottom:4px;">
                Meta Description
                <span id="xt-seomaster-desc-count" style="font-weight:400; color:#888;">(0/160)</span>
            </label>
            <textarea id="xt-seomaster-desc" name="fields[description]"
                placeholder="建议 50–160 字符，留空则自动截取正文。"
                style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;
                       resize:vertical; min-height:64px; box-sizing:border-box;"
            ><?= $seoDesc ?></textarea>
            <div id="xt-seomaster-desc-hint" style="font-size:11px; color:#888; margin-top:2px;">
                <!-- 动态提示 -->
            </div>
        </div>

        <div style="margin-bottom: 14px;">
            <label style="display:block; font-weight:600; margin-bottom:4px;">
                Meta Keywords
            </label>
            <input id="xt-seomaster-keywords" name="fields[keywords]"
                type="text"
                placeholder="逗号分隔，留空则自动使用文章标签。"
                value="<?= $seoKeywords ?>"
                style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;
                       box-sizing:border-box;"
            />
        </div>

        <div style="margin-bottom:14px; display:flex; align-items:center; gap:10px;">
            <button type="button" id="xt-seomaster-save-btn" style="
                border:1px solid #2563eb; background:#2563eb; color:#fff;
                border-radius:4px; padding:6px 12px; cursor:pointer;
            ">保存 SEO</button>
            <span id="xt-seomaster-save-msg" style="font-size:12px; color:#64748b;"></span>
        </div>

        <!-- SEO 检查列表 -->
        <div style="margin-top: 16px;">
            <strong>SEO 检测项</strong>
            <ul id="xt-seomaster-checks" style="list-style:none; padding:0; margin:8px 0 0;">
                <!-- 由 JS 动态填充 -->
            </ul>
        </div>

        <!-- 评分进度条 -->
        <div style="margin-top: 12px; background:#eee; border-radius:4px; height:8px; overflow:hidden;">
            <div id="xt-seomaster-progress" style="
                height:8px; width:0%; background:#27ae60;
                transition: width 0.4s ease, background 0.4s ease;
                border-radius:4px;
            "></div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var seoCid = <?php echo intval($cid); ?>;
    var seoSaveUrls = [
        <?php echo json_encode($seoSaveUrl); ?>,
        <?php echo json_encode($seoSaveUrlFallback); ?>
    ];

    // ── 折叠面板 ──
    document.getElementById('xt-seomaster-header').addEventListener('click', function () {
        var body   = document.getElementById('xt-seomaster-body');
        var toggle = document.getElementById('xt-seomaster-toggle');
        if (body.style.display === 'none') {
            body.style.display = '';
            toggle.textContent = '▾';
        } else {
            body.style.display = 'none';
            toggle.textContent = '▸';
        }
    });

    // ── 检测逻辑 ──
    var CHECKS = [
        {
            id: 'title-len',
            label: '文章标题 30–65 字符',
            test: function () {
                var t = (document.getElementById('title') || {}).value || '';
                return t.length >= 10 && t.length <= 65;
            }
        },
        {
            id: 'desc-filled',
            label: 'Meta Description 已填写',
            test: function () {
                return document.getElementById('xt-seomaster-desc').value.trim().length > 0;
            }
        },
        {
            id: 'desc-len',
            label: 'Description 长度 50–160 字符',
            test: function () {
                var d = document.getElementById('xt-seomaster-desc').value.trim();
                return d.length >= 50 && d.length <= 160;
            }
        },
        {
            id: 'kw-filled',
            label: 'Keywords 已填写',
            test: function () {
                return document.getElementById('xt-seomaster-keywords').value.trim().length > 0;
            }
        },
        {
            id: 'content-len',
            label: '正文字数 ≥ 300',
            test: function () {
                var el = document.getElementById('text') ||
                         document.querySelector('.CodeMirror-code') ||
                         document.querySelector('textarea[name="text"]');
                if (!el) return false;
                var txt = el.value || el.textContent || '';
                return txt.replace(/<[^>]+>/g, '').replace(/\s/g, '').length >= 300;
            }
        },
        {
            id: 'img-alt',
            label: '正文图片含 alt 属性',
            test: function () {
                var el = document.querySelector('textarea[name="text"]');
                if (!el) return true;
                var imgs = el.value.match(/<img[^>]*>/gi) || [];
                if (imgs.length === 0) return true;
                return imgs.every(function (img) { return /alt=["'][^"']+["']/i.test(img); });
            }
        },
        {
            id: 'has-link',
            label: '正文包含内部/外部链接',
            test: function () {
                var el = document.querySelector('textarea[name="text"]');
                if (!el) return false;
                return /<a\s[^>]*href/i.test(el.value);
            }
        },
        {
            id: 'h-tag',
            label: '正文使用了 H2/H3 标题',
            test: function () {
                var el = document.querySelector('textarea[name="text"]');
                if (!el) return false;
                var text = el.value;
                // Markdown
                return /^#{2,3}\s.+/m.test(text) || /<h[23]/i.test(text);
            }
        }
    ];

    function renderChecks() {
        var ul     = document.getElementById('xt-seomaster-checks');
        var passed = 0;
        ul.innerHTML = '';
        CHECKS.forEach(function (check) {
            var ok = false;
            try { ok = check.test(); } catch (e) {}
            if (ok) passed++;
            var li = document.createElement('li');
            li.style.cssText = 'padding:4px 0; display:flex; align-items:center; gap:8px;';
            li.innerHTML =
                '<span style="font-size:14px;">' + (ok ? '✅' : '❌') + '</span>' +
                '<span style="color:' + (ok ? '#27ae60' : '#c0392b') + ';">' + check.label + '</span>';
            ul.appendChild(li);
        });

        var pct    = Math.round((passed / CHECKS.length) * 100);
        var color  = pct >= 75 ? '#27ae60' : pct >= 50 ? '#f39c12' : '#e74c3c';
        var label  = pct >= 75 ? '良好' : pct >= 50 ? '一般' : '待优化';

        document.getElementById('xt-seomaster-progress').style.width      = pct + '%';
        document.getElementById('xt-seomaster-progress').style.background = color;
        document.getElementById('xt-seomaster-score-badge').textContent   = '评分：' + pct + '分 · ' + label;
        document.getElementById('xt-seomaster-score-badge').style.background = color;
    }

    // ── Description 字符计数 ──
    document.getElementById('xt-seomaster-desc').addEventListener('input', function () {
        var len   = this.value.length;
        var count = document.getElementById('xt-seomaster-desc-count');
        var hint  = document.getElementById('xt-seomaster-desc-hint');
        count.textContent = '(' + len + '/160)';
        if (len === 0) {
            count.style.color = '#888';
            hint.textContent  = '';
        } else if (len < 50) {
            count.style.color = '#e74c3c';
            hint.textContent  = '⚠ 建议不少于 50 字符';
        } else if (len <= 160) {
            count.style.color = '#27ae60';
            hint.textContent  = '✔ 长度适中';
        } else {
            count.style.color = '#e74c3c';
            hint.textContent  = '⚠ 超出 160 字符，搜索结果中可能被截断';
        }
        renderChecks();
    });

    // ── 监听关键字变化 ──
    document.getElementById('xt-seomaster-keywords').addEventListener('input', renderChecks);

    var saveBtn = document.getElementById('xt-seomaster-save-btn');
    var saveMsg = document.getElementById('xt-seomaster-save-msg');
    function setSaveMsg(text, ok) {
        if (!saveMsg) return;
        saveMsg.textContent = text;
        saveMsg.style.color = ok ? '#16a34a' : '#dc2626';
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (!seoCid) {
                setSaveMsg('请先保存一次文章，再保存 SEO 字段。', false);
                return;
            }
            var desc = document.getElementById('xt-seomaster-desc').value || '';
            var keywords = document.getElementById('xt-seomaster-keywords').value || '';
            saveBtn.disabled = true;
            setSaveMsg('保存中...', true);

            var body = new URLSearchParams();
            body.append('cid', String(seoCid));
            body.append('description', desc);
            body.append('keywords', keywords);
            function parseServerData(text) {
                var t = (text || '').toString();
                try { return JSON.parse(t); } catch (e) {}
                var m = t.match(/\{[\s\S]*\}/);
                if (m && m[0]) {
                    try { return JSON.parse(m[0]); } catch (e2) {}
                }
                return { ok: false, message: 'invalid json', raw: t.slice(0, 1200), _invalid_json: true };
            }
            var trySave = function (idx) {
                var url = seoSaveUrls[idx] || seoSaveUrls[0];
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.text().then(function (t) { return parseServerData(t); });
                }).then(function (data) {
                    if (data && data.ok) {
                        setSaveMsg('保存成功。', true);
                        return;
                    }
                    if (data && data._invalid_json && idx + 1 < seoSaveUrls.length) {
                        return trySave(idx + 1);
                    }
                    var msg = (data && data.message) ? data.message : '未知错误';
                    if (data && data.raw) {
                        msg += ' | 原始返回: ' + data.raw;
                    }
                    setSaveMsg('保存失败：' + msg, false);
                }).catch(function (err) {
                    if (idx + 1 < seoSaveUrls.length) {
                        return trySave(idx + 1);
                    }
                    setSaveMsg('保存失败：' + (err && err.message ? err.message : '网络错误'), false);
                }).finally(function () {
                    if (idx === 0) {
                        saveBtn.disabled = false;
                    }
                });
            };
            trySave(0);
        });
    }

    // ── 监听正文变化（编辑器切换兼容）──
    var textEl = document.querySelector('textarea[name="text"]');
    if (textEl) {
        textEl.addEventListener('input', renderChecks);
    }
    // 兼容 Markdown/富文本编辑器延迟加载
    setTimeout(renderChecks, 800);
    setInterval(renderChecks, 3000);

    // 初始渲染
    renderChecks();
})();
</script>
        <?php
    }

    private static function resolveContentFieldValue($archive, $cid, array $fieldNames)
    {
        foreach ($fieldNames as $name) {
            $value = self::extractFieldFromArchive($archive, $name);
            if ($value !== '') {
                return $value;
            }
        }

        if ($cid <= 0) {
            return '';
        }

        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('name', 'str_value')
                   ->from('table.fields')
                   ->where('cid = ?', intval($cid))
            );
            if (!empty($rows)) {
                $map = array();
                foreach ($rows as $row) {
                    $map[$row['name']] = isset($row['str_value']) ? trim((string) $row['str_value']) : '';
                }
                foreach ($fieldNames as $name) {
                    if (isset($map[$name]) && $map[$name] !== '') {
                        return $map[$name];
                    }
                }
            }
        } catch (Exception $e) {
        }

        return '';
    }

    private static function extractFieldFromArchive($archive, $name)
    {
        if (!is_object($archive)) {
            return '';
        }

        $fields = null;
        if (isset($archive->fields)) {
            $fields = $archive->fields;
        }
        if ($fields === null) {
            return '';
        }

        try {
            if (is_array($fields) && isset($fields[$name])) {
                return trim((string) $fields[$name]);
            }
            if (is_object($fields)) {
                if (isset($fields->{$name})) {
                    return trim((string) $fields->{$name});
                }
                if (method_exists($fields, '__get')) {
                    $value = $fields->{$name};
                    if ($value !== null) {
                        return trim((string) $value);
                    }
                }
            }
        } catch (Exception $e) {
        }

        return '';
    }

    // ─── 辅助：自动摘要 ──────────────────────────────────────────────────────

    public static function autoExcerpt($text, $maxLen = 160)
    {
        // 去掉 Markdown / HTML 标签
        $text = preg_replace('/```[\s\S]*?```/', '', $text);
        $text = preg_replace('/`[^`]+`/', '', $text);
        $text = preg_replace('/#+\s/', '', $text);
        $text = preg_replace('/[*_~>\-]+/', '', $text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            $text = mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
        }
        return $text;
    }

    // ─── 辅助：提取分类与标签关键词 ────────────────────────────────────────────

    public static function collectKeywordNames($archive, $enableTitleFallback = true)
    {
        $names = array();

        foreach ((array) $archive->categories as $cat) {
            if (!empty($cat['name'])) {
                $names[] = trim($cat['name']);
            }
        }

        foreach ((array) $archive->tags as $tag) {
            if (!empty($tag['name'])) {
                $names[] = trim($tag['name']);
            }
        }

        if (empty($names) && $enableTitleFallback) {
            $names = self::extractTitleKeywords($archive->title);
        }

        $names = array_values(array_unique(array_filter($names)));
        return implode(',', $names);
    }

    // ─── 辅助：标题分词 ────────────────────────────────────────────────────────

    public static function extractTitleKeywords($title)
    {
        $title = trim(strip_tags((string) $title));
        if ($title === '') {
            return array();
        }

        // 按空白和常见中英文标点切分
        $parts = preg_split('/[\s\-\_\|,，.。!！?？:：;；\/\\\\]+/u', $title);
        $parts = array_filter(array_map('trim', (array) $parts));

        $keywords = array();
        foreach ($parts as $part) {
            $len = mb_strlen($part, 'UTF-8');
            if ($len >= 2 && $len <= 20) {
                $keywords[] = $part;
            }
        }

        // 标题无可切分片段时，退化为整句关键词
        if (empty($keywords)) {
            $len = mb_strlen($title, 'UTF-8');
            if ($len >= 2 && $len <= 40) {
                $keywords[] = $title;
            }
        }

        return array_values(array_unique($keywords));
    }

    // ─── 辅助：提取正文第一张图片 ────────────────────────────────────────────

    public static function extractFirstImage($text)
    {
        // Markdown 格式：![alt](url)
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)\)/', $text, $m)) {
            return $m[1];
        }
        // HTML 格式：<img src="...">
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $text, $m)) {
            return $m[1];
        }
        return '';
    }
}
