<?php
/**
 * XtSeoMaster Action - 动态路由处理器
 * 负责生成：
 *   - /sitemap.xml   动态 Sitemap
 *   - /robots.txt    可配置 Robots
 *   - /xt-seo/amp/[cid]
 *   - /xt-seo/mip/[cid]
 *   - /xt-seo/push-runner
 *
 * @package XtSeoMaster
 * @author 小铁
 * @version 1.0.0
 * @link https://github.com/xiaotiewinner/xt-seo-master
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/QueueRepository.php';
require_once __DIR__ . '/PushService.php';
require_once __DIR__ . '/AmpRenderer.php';
require_once __DIR__ . '/MipRenderer.php';

/**
 * XtSeoMaster 路由动作处理器。
 *
 * 职责：
 * - 处理 Sitemap/Robots/AMP/MIP 等公共路由
 * - 处理推送管理异步接口（单篇、批量、全量、删日志）
 * - 处理编辑页 SEO 字段异步保存接口
 * - 统一输出 JSON 响应与错误信息
 */
class XtSeoMaster_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        // 由路由分发决定调用对应 action
    }

    public function saveSeo()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->pass('editor')) {
            return $this->json(array('ok' => false, 'message' => 'forbidden'), 403);
        }

        $cid = intval($this->request->get('cid', 0));
        if ($cid <= 0) {
            return $this->json(array('ok' => false, 'message' => 'invalid cid'), 400);
        }

        $desc = trim((string) $this->request->get('description', $this->request->get('seo_desc', '')));
        $keywords = trim((string) $this->request->get('keywords', $this->request->get('seo_keywords', '')));
        $db = Typecho_Db::get();

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
                        ->where('name = ?', $name),
                    Typecho_Db::WRITE
                );
            } else {
                $db->query(
                    $db->insert('table.fields')
                        ->rows(array(
                            'cid' => $cid,
                            'name' => $name,
                            'type' => 'str',
                            'str_value' => $value,
                            'int_value' => 0,
                            'float_value' => 0
                        )),
                    Typecho_Db::WRITE
                );
            }
        };

        $upsertField('description', $desc);
        $upsertField('keywords', $keywords);

        return $this->json(array(
            'ok' => true,
            'cid' => $cid,
            'message' => 'saved'
        ), 200);
    }

    // ─── Sitemap XML ─────────────────────────────────────────────────────────

    public function sitemap()
    {
        $options  = Helper::options()->plugin('XtSeoMaster');
        $enabled  = !isset($options->enableSitemap) || $options->enableSitemap == '1';
        if (!$enabled) {
            if (method_exists($this->response, 'setStatus')) {
                $this->response->setStatus(404);
            }
            $this->response->setContentType('text/plain; charset=UTF-8');
            echo 'Not Found';
            exit;
        }

        $db       = Typecho_Db::get();
        $siteUrl  = rtrim(Helper::options()->siteUrl, '/') . '/';

        $changefreq = !empty($options->sitemapChangefreq)
            ? $options->sitemapChangefreq
            : 'daily';
        $priority   = !empty($options->sitemapPriority)
            ? $options->sitemapPriority
            : '0.9';
        $taxonomyMode = isset($options->sitemapTaxonomyMode)
            ? trim((string) $options->sitemapTaxonomyMode)
            : 'none';
        $includeCategories = in_array($taxonomyMode, array('no_tag', 'all'), true);
        $includeTags = in_array($taxonomyMode, array('no_category', 'all'), true);

        // 收集所有 URL
        $urls = array();

        // 1. 首页
        $urls[] = array(
            'loc'        => $siteUrl,
            'lastmod'    => date('Y-m-d'),
            'changefreq' => 'daily',
            'priority'   => '1.0',
        );

        // 2. 所有已发布的文章
        $posts = $db->fetchAll(
            $db->select('cid', 'slug', 'created', 'modified', 'type')
               ->from('table.contents')
               ->where('status = ?', 'publish')
               ->where('type IN ?', array('post', 'page'))
               ->where('password IS NULL OR password = ?', '')
               ->order('modified', Typecho_Db::SORT_DESC)
        );

        foreach ($posts as $post) {
            $permalink = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
            if ($permalink === '') {
                continue;
            }

            $urls[] = array(
                'loc'        => $permalink,
                'lastmod'    => date('Y-m-d', $post['modified']),
                'changefreq' => $changefreq,
                'priority'   => $post['type'] === 'page' ? '0.8' : $priority,
            );
        }

        // 3. 分类页（可选）
        if ($includeCategories) {
            $categories = $db->fetchAll(
                $db->select('mid', 'slug', 'type')
                   ->from('table.metas')
                   ->where('type = ?', 'category')
            );
            foreach ($categories as $cat) {
                try {
                    $permalink = Typecho_Router::url('category', $cat, $siteUrl);
                } catch (Exception $e) {
                    continue;
                }
                $urls[] = array(
                    'loc'        => $permalink,
                    'lastmod'    => date('Y-m-d'),
                    'changefreq' => 'weekly',
                    'priority'   => '0.6',
                );
            }
        }

        // 4. 标签页（可选）
        if ($includeTags) {
            $tags = $db->fetchAll(
                $db->select('mid', 'slug', 'type')
                   ->from('table.metas')
                   ->where('type = ?', 'tag')
            );
            foreach ($tags as $tag) {
                try {
                    $permalink = Typecho_Router::url('tag', $tag, $siteUrl);
                } catch (Exception $e) {
                    continue;
                }
                $urls[] = array(
                    'loc'        => $permalink,
                    'lastmod'    => date('Y-m-d'),
                    'changefreq' => 'weekly',
                    'priority'   => '0.5',
                );
            }
        }

        // 输出 XML
        $this->response->setContentType('application/xml; charset=UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        echo '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
        echo '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n";
        echo '          http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

        foreach ($urls as $url) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            echo "    <lastmod>" . htmlspecialchars($url['lastmod']) . "</lastmod>\n";
            echo "    <changefreq>" . htmlspecialchars($url['changefreq']) . "</changefreq>\n";
            echo "    <priority>" . htmlspecialchars($url['priority']) . "</priority>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }

    // ─── Robots.txt ──────────────────────────────────────────────────────────

    public function robots()
    {
        $options  = Helper::options()->plugin('XtSeoMaster');
        $enabled  = !isset($options->enableRobotsTxt) || $options->enableRobotsTxt == '1';
        if (!$enabled) {
            if (method_exists($this->response, 'setStatus')) {
                $this->response->setStatus(404);
            }
            $this->response->setContentType('text/plain; charset=UTF-8');
            echo 'Not Found';
            exit;
        }

        $siteUrl  = rtrim(Helper::options()->siteUrl, '/') . '/';

        $content = !empty($options->robotsTxt)
            ? $options->robotsTxt
            : "User-agent: *\nDisallow: /admin/\nDisallow: /?s=\n\nSitemap: {siteUrl}sitemap.xml";

        // 替换占位符
        $content = str_replace('{siteUrl}', $siteUrl, $content);
        if (isset($options->enableSitemap) && $options->enableSitemap == '0') {
            $content = preg_replace('/^Sitemap:.*$/mi', '', $content);
            $content = trim(preg_replace("/\n{3,}/", "\n\n", $content));
        }

        $this->response->setContentType('text/plain; charset=UTF-8');
        echo $content;
        exit;
    }

    // ─── AMP/MIP 扩展路由（兼容 Typecho-AMP 形态）────────────────────────────

    public function AMPSiteMap()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $enabled = !isset($options->enableAmpSitemap) || $options->enableAmpSitemap == '1';
        if (!$enabled) {
            return $this->render404();
        }
        $this->makeAmpMipSitemap('amp');
    }

    public function MIPSiteMap()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $enabled = !isset($options->enableMipSitemap) || $options->enableMipSitemap == '1';
        if (!$enabled) {
            return $this->render404();
        }
        $this->makeAmpMipSitemap('mip');
    }

    public function AMPpage()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        if (isset($options->enableAmp) && $options->enableAmp == '0') {
            return $this->render404();
        }

        $target = (string) $this->request->get('target', '');
        $post = $this->resolveTargetToContent($target);
        if (empty($post)) {
            return $this->render404();
        }
        return $this->renderAmpByPost($post);
    }

    public function MIPpage()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        if (isset($options->enableMip) && $options->enableMip == '0') {
            return $this->render404();
        }

        $target = (string) $this->request->get('target', '');
        $post = $this->resolveTargetToContent($target);
        if (empty($post)) {
            return $this->render404();
        }
        return $this->renderMipByPost($post);
    }

    public function AMPlist()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        if (isset($options->enableAmpIndex) && $options->enableAmpIndex == '0') {
            return $this->json(array('ok' => false, 'message' => 'amp index disabled'), 400);
        }

        $page = max(1, intval($this->request->get('list_id', 1)));
        $pageSize = 5;
        $rows = $this->fetchPublishedList($page, $pageSize);
        $total = $this->fetchPublishedCount();
        $pageCount = max(1, intval(ceil($total / $pageSize)));
        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';

        $articles = array();
        $items = array();
        foreach ($rows as $row) {
            $normalized = $this->normalizeContentText($row['text']);
            $canonical = XtSeoMaster_Plugin::buildContentPermalink($row, $siteUrl);
            if ($canonical === '') {
                continue;
            }
            $articles[] = array(
                'title' => $row['title'],
                'url' => $this->buildAmpMipUrl('amp', $row, $siteUrl),
                'canonical' => $canonical,
                'content' => $this->substrFormat(strip_tags($normalized['html']), 200)
            );
        }
        $items = array(
            'pageCount' => $pageCount,
            'currentPage' => $page,
            'article' => $articles
        );

        header('Access-Control-Allow-Origin: *');
        return $this->json(array(
            'items' => $items
        ), 200);
    }

    public function AMPindex()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        if (isset($options->enableAmpIndex) && $options->enableAmpIndex == '0') {
            return $this->render404();
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $view = array(
            'site_title' => Helper::options()->title,
            'base_url' => $siteUrl,
            'amp_list_first_url' => rtrim($siteUrl, '/') . '/amp/list/1',
            'amp_list_base_url' => rtrim($siteUrl, '/') . '/amp/list/'
        );
        $this->renderTemplate('AMPindex.php', $view);
    }

    public function cleancache()
    {
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->pass('administrator')) {
            return $this->json(array('ok' => false, 'message' => 'forbidden'), 403);
        }
        $count = $this->clearCacheFiles();
        return $this->json(array('ok' => true, 'deleted' => $count), 200);
    }

    // ─── AMP ────────────────────────────────────────────────────────────────

    public function amp()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $enabled = !isset($options->enableAmp) || $options->enableAmp == '1';
        if (!$enabled) {
            return $this->render404();
        }

        $cid = intval($this->request->get('cid', 0));
        if ($cid <= 0) {
            return $this->render404();
        }

        $post = $this->fetchPublishedContent($cid);
        if (empty($post)) {
            return $this->render404();
        }
        return $this->renderAmpByPost($post);
    }

    // ─── MIP ────────────────────────────────────────────────────────────────

    public function mip()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $enabled = !isset($options->enableMip) || $options->enableMip == '1';
        if (!$enabled) {
            return $this->render404();
        }

        $cid = intval($this->request->get('cid', 0));
        if ($cid <= 0) {
            return $this->render404();
        }

        $post = $this->fetchPublishedContent($cid);
        if (empty($post)) {
            return $this->render404();
        }
        return $this->renderMipByPost($post);
    }

    // ─── Push Runner ────────────────────────────────────────────────────────

    public function pushRunner()
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $token = trim((string) $this->request->get('token', ''));
        $savedToken = trim((string) $options->pushRunnerToken);
        if ($savedToken === '' || $token !== $savedToken) {
            return $this->json(array('ok' => false, 'message' => 'unauthorized'), 401);
        }

        if (!isset($options->enablePush) || $options->enablePush != '1') {
            return $this->json(array('ok' => false, 'message' => 'push disabled'), 400);
        }

        if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        $task = trim((string) $this->request->get('task', 'push_all'));
        $includeAmpMip = $this->request->get('include_amp_mip', '1');
        $includeAmpMip = ((string) $includeAmpMip === '0') ? false : true;
        if ($task === 'push_all') {
            $limit = max(1, min(5000, intval($this->request->get('limit', 2000))));
            return $this->json($this->pushAllPublishedTargets($options, $limit, $includeAmpMip), 200);
        }
        if ($task === 'push_ampmip') {
            $limit = max(1, min(500, intval($this->request->get('limit', 50))));
            $targetType = trim((string) $this->request->get('type', 'mip'));
            return $this->json($this->pushAmpMipToBaidu($options, $targetType, $limit), 200);
        }
        if ($task === 'push_article_all') {
            $cid = intval($this->request->get('cid', 0));
            $engine = trim((string) $this->request->get('engine', ''));
            return $this->json($this->pushArticleAllTargets($options, $cid, $engine, $includeAmpMip), 200);
        }
        if ($task === 'push_articles_all') {
            $rawCids = trim((string) $this->request->get('cids', ''));
            return $this->json($this->pushMultipleArticlesAllTargets($options, $rawCids, $includeAmpMip), 200);
        }
        if ($task === 'delete_logs') {
            $rawCids = trim((string) $this->request->get('cids', ''));
            return $this->json($this->deletePushLogsByCids($rawCids), 200);
        }

        return $this->json(array('ok' => false, 'message' => 'invalid task'), 400);
    }

    private function pushAllPublishedTargets($options, $limit, $includeAmpMip = true)
    {
        $rows = $this->fetchPublishedList(1, $limit);
        $processed = 0;
        $success = 0;
        $failed = 0;
        $details = array();

        foreach ($rows as $row) {
            $cid = intval(isset($row['cid']) ? $row['cid'] : 0);
            if ($cid <= 0) {
                continue;
            }
            $processed++;
            $result = $this->pushArticleAllTargets($options, $cid, 'all', $includeAmpMip);
            if (!empty($result['ok'])) {
                $success++;
            } else {
                $failed++;
            }
            $details[] = $result;
        }

        return array(
            'ok' => true,
            'task' => 'push_all',
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'details' => $details
        );
    }

    private function pushAmpMipToBaidu($options, $targetType, $limit)
    {
        $targetType = $targetType === 'amp' ? 'amp' : 'mip';
        $rows = $this->fetchPublishedList(1, $limit);
        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $repo = new XtSeoMaster_QueueRepository();

        $engines = array();
        if (!isset($options->pushEnableBaidu) || $options->pushEnableBaidu == '1') {
            $engines[] = 'baidu';
        }
        if (!empty($options->pushEnableBaiduDaily) && $options->pushEnableBaiduDaily == '1') {
            $engines[] = 'baidu_daily';
        }
        if (empty($engines)) {
            return array('ok' => false, 'task' => 'push_ampmip', 'message' => 'baidu push disabled');
        }

        $pushed = 0;
        $success = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $url = $this->buildAmpMipUrl($targetType, $row, $siteUrl);
            if ($url === '') {
                continue;
            }
            foreach ($engines as $engine) {
                $result = XtSeoMaster_PushService::pushUrls($engine, array($url), $options, $siteUrl);
                $ok = !empty($result['success']);
                $repo->logPushResult(0, $engine, $url, $result['request_payload'], $result['http_code'], $result['response'], $ok, $result['error']);
                $pushed += 1;
                if ($ok) {
                    $success += 1;
                } else {
                    $failed += 1;
                }
            }
        }

        return array(
            'ok' => true,
            'task' => 'push_ampmip',
            'type' => $targetType,
            'pushed' => $pushed,
            'success' => $success,
            'failed' => $failed
        );
    }

    private function pushArticleAllTargets($options, $cid, $engine, $includeAmpMip = true)
    {
        $cid = intval($cid);
        if ($cid <= 0) {
            return array('ok' => false, 'task' => 'push_article_all', 'message' => 'invalid cid');
        }

        $enabledEngines = XtSeoMaster_PushService::enabledEngines($options);
        if (empty($enabledEngines)) {
            return array('ok' => false, 'task' => 'push_article_all', 'message' => 'no enabled engines');
        }

        $validEngines = array('baidu', 'baidu_daily', 'indexnow');
        $engine = trim((string) $engine);
        $engines = array();
        if ($engine === '' || $engine === 'all') {
            $engines = $enabledEngines;
        } else {
            if (!in_array($engine, $validEngines, true)) {
                return array('ok' => false, 'task' => 'push_article_all', 'message' => 'invalid engine');
            }
            if (!in_array($engine, $enabledEngines, true)) {
                return array('ok' => false, 'task' => 'push_article_all', 'message' => 'engine disabled');
            }
            $engines = array($engine);
        }

        $post = $this->fetchPublishedContent($cid);
        if (empty($post)) {
            return array('ok' => false, 'task' => 'push_article_all', 'message' => 'post not found');
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
        if ($canonical === '') {
            return array('ok' => false, 'task' => 'push_article_all', 'message' => 'canonical not resolved');
        }

        $targets = array($canonical);
        if ($includeAmpMip) {
            $ampUrl = $this->buildAmpMipUrl('amp', $post, $siteUrl);
            $mipUrl = $this->buildAmpMipUrl('mip', $post, $siteUrl);
            if ($ampUrl !== '') {
                $targets[] = $ampUrl;
            }
            if ($mipUrl !== '') {
                $targets[] = $mipUrl;
            }
        }
        $targets = array_values(array_unique($targets));

        $batch = $this->pushTargetsByEngines($options, $siteUrl, $engines, $targets);

        return array(
            'ok' => true,
            'task' => 'push_article_all',
            'cid' => $cid,
            'engines' => $engines,
            'include_amp_mip' => $includeAmpMip ? 1 : 0,
            'total' => count($targets) * count($engines),
            'success' => $batch['success'],
            'failed' => $batch['failed'],
            'details' => $batch['details']
        );
    }

    private function pushMultipleArticlesAllTargets($options, $rawCids, $includeAmpMip = true)
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $rawCids)));
        $cids = array();
        foreach ($parts as $part) {
            $cid = intval($part);
            if ($cid > 0) {
                $cids[$cid] = $cid;
            }
        }
        $cids = array_values($cids);
        if (empty($cids)) {
            return array('ok' => false, 'task' => 'push_articles_all', 'message' => 'no valid cids');
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $enabledEngines = XtSeoMaster_PushService::enabledEngines($options);
        if (empty($enabledEngines)) {
            return array('ok' => false, 'task' => 'push_articles_all', 'message' => 'no enabled engines');
        }

        $allTargets = array();
        $targetMap = array();
        foreach ($cids as $cid) {
            $post = $this->fetchPublishedContent($cid);
            if (empty($post)) {
                continue;
            }
            $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
            if ($canonical === '') {
                continue;
            }
            $targets = array($canonical);
            if ($includeAmpMip) {
                $ampUrl = $this->buildAmpMipUrl('amp', $post, $siteUrl);
                $mipUrl = $this->buildAmpMipUrl('mip', $post, $siteUrl);
                if ($ampUrl !== '') {
                    $targets[] = $ampUrl;
                }
                if ($mipUrl !== '') {
                    $targets[] = $mipUrl;
                }
            }
            $targets = array_values(array_unique($targets));
            $targetMap[$cid] = $targets;
            foreach ($targets as $u) {
                $allTargets[$u] = $u;
            }
        }

        if (empty($allTargets)) {
            return array('ok' => false, 'task' => 'push_articles_all', 'message' => 'no valid targets');
        }

        $batch = $this->pushTargetsByEngines($options, $siteUrl, $enabledEngines, array_values($allTargets));
        $summary = array(
            'ok' => true,
            'task' => 'push_articles_all',
            'count' => count($cids),
            'success' => $batch['success'],
            'failed' => $batch['failed'],
            'total' => count($allTargets) * count($enabledEngines),
            'engines' => $enabledEngines,
            'include_amp_mip' => $includeAmpMip ? 1 : 0,
            'details' => $batch['details'],
            'targets_by_cid' => $targetMap
        );

        return $summary;
    }

    private function pushTargetsByEngines($options, $siteUrl, $engines, $targets)
    {
        $repo = new XtSeoMaster_QueueRepository();
        $success = 0;
        $failed = 0;
        $details = array();

        foreach ((array) $engines as $currentEngine) {
            $result = XtSeoMaster_PushService::pushUrls($currentEngine, $targets, $options, $siteUrl);
            $ok = !empty($result['success']);
            foreach ((array) $targets as $url) {
                $repo->logPushResult(
                    0,
                    $currentEngine,
                    $url,
                    $result['request_payload'],
                    $result['http_code'],
                    $result['response'],
                    $ok,
                    $result['error']
                );
                if ($ok) {
                    $success++;
                } else {
                    $failed++;
                }
            }
            $details[] = array(
                'engine' => $currentEngine,
                'success' => $ok,
                'http_code' => intval($result['http_code']),
                'url_count' => count((array) $targets),
                'error' => $result['error'],
                'response' => $result['response'],
                'request_payload' => $result['request_payload']
            );
        }

        return array(
            'success' => $success,
            'failed' => $failed,
            'details' => $details
        );
    }

    private function deletePushLogsByCids($rawCids)
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $rawCids)));
        $cids = array();
        foreach ($parts as $part) {
            $cid = intval($part);
            if ($cid > 0) {
                $cids[$cid] = $cid;
            }
        }
        $cids = array_values($cids);
        if (empty($cids)) {
            return array('ok' => false, 'task' => 'delete_logs', 'message' => 'no valid cids');
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $urls = array();
        foreach ($cids as $cid) {
            $post = $this->fetchPublishedContent($cid);
            if (empty($post)) {
                continue;
            }
            $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
            if ($canonical !== '') {
                $urls[$canonical] = $canonical;
            }
            $ampUrl = $this->buildAmpMipUrl('amp', $post, $siteUrl);
            if ($ampUrl !== '') {
                $urls[$ampUrl] = $ampUrl;
            }
            $mipUrl = $this->buildAmpMipUrl('mip', $post, $siteUrl);
            if ($mipUrl !== '') {
                $urls[$mipUrl] = $mipUrl;
            }
        }

        if (empty($urls)) {
            return array('ok' => false, 'task' => 'delete_logs', 'message' => 'no target urls');
        }

        try {
            $repo = new XtSeoMaster_QueueRepository();
            $deleted = $repo->deleteRecordsByUrls(array_values($urls));
        } catch (Exception $e) {
            return array(
                'ok' => false,
                'task' => 'delete_logs',
                'message' => 'delete failed: ' . $e->getMessage()
            );
        }

        return array(
            'ok' => true,
            'task' => 'delete_logs',
            'count' => count($cids),
            'url_count' => count($urls),
            'deleted_logs' => intval($deleted['deleted_logs'])
        );
    }

    private function renderAmpByPost($post)
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $templateVersion = 'amp_tpl_v2';
        $cacheKey = 'amp_' . intval($post['cid']) . '_' . $templateVersion;
        $ttl = intval(isset($options->ampMipCacheTtl) ? $options->ampMipCacheTtl : 600);
        $cached = $this->readCache($cacheKey, $ttl);
        if ($cached !== '') {
            $this->response->setContentType('text/html; charset=UTF-8');
            echo $cached;
            exit;
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $siteName = Helper::options()->title;
        $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
        if ($canonical === '') {
            return $this->render404();
        }
        $description = XtSeoMaster_Plugin::autoExcerpt($post['text'], 160);
        $firstImg = XtSeoMaster_Plugin::extractFirstImage($post['text']);
        $imageUrl = $firstImg ?: (!empty($options->defaultOgImage) ? $options->defaultOgImage : '');
        $normalized = $this->normalizeContentText($post['text']);
        $ampText = XtSeoMaster_AmpRenderer::normalizeBody($normalized['html'], 'amp');
        $imageData = $this->getImageMeta($post['text'], $imageUrl);
        if (!is_array($imageData) && $imageUrl !== '') {
            $imageData = array('url' => $imageUrl, 'width' => 1200, 'height' => 800);
        }
        $logoUrl = !empty($options->defaultOgImage) ? $options->defaultOgImage : $imageUrl;
        if ($logoUrl === '') {
            $logoUrl = rtrim($siteUrl, '/');
        }

        $html = $this->renderTemplateContent('AMPpage.php', array(
            'AMPpage' => array(
                'title' => $post['title'],
                'permalink' => $canonical,
                'ampurl' => $this->buildAmpMipUrl('amp', $post, $siteUrl),
                'modified' => date('F j, Y', intval($post['modified'])),
                'date' => date('F j, Y', intval($post['created'])),
                'author' => $post['author_name'],
                'LOGO' => $logoUrl,
                'isMarkdown' => $normalized['is_markdown'],
                'imgData' => $imageData,
                'desc' => $description,
                'publisher' => $siteName,
                'AMPtext' => $ampText
            ),
            'base_url' => $siteUrl
        ));

        if ($html === '') {
            return $this->render404();
        }
        $this->writeCache($cacheKey, $html);
        $this->response->setContentType('text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function renderMipByPost($post)
    {
        $options = Helper::options()->plugin('XtSeoMaster');
        $templateVersion = 'mip_tpl_v2';
        $cacheKey = 'mip_' . intval($post['cid']) . '_' . $templateVersion;
        $ttl = intval(isset($options->ampMipCacheTtl) ? $options->ampMipCacheTtl : 600);
        $cached = $this->readCache($cacheKey, $ttl);
        if ($cached !== '') {
            $this->response->setContentType('text/html; charset=UTF-8');
            echo $cached;
            exit;
        }

        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $siteName = Helper::options()->title;
        $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrl);
        if ($canonical === '') {
            return $this->render404();
        }
        $description = XtSeoMaster_Plugin::autoExcerpt($post['text'], 160);
        $firstImg = XtSeoMaster_Plugin::extractFirstImage($post['text']);
        $imageUrl = $firstImg ?: (!empty($options->defaultOgImage) ? $options->defaultOgImage : '');
        $normalized = $this->normalizeContentText($post['text']);
        $mipText = XtSeoMaster_AmpRenderer::normalizeBody($normalized['html'], 'mip');
        $imageData = $this->getImageMeta($post['text'], $imageUrl);
        $mipStatsToken = isset($options->mipStatsToken) ? trim((string) $options->mipStatsToken) : '';

        $html = $this->renderTemplateContent('MIPpage.php', array(
            'MIPpage' => array(
                'title' => $post['title'],
                'permalink' => $canonical,
                'mipurl' => $this->buildAmpMipUrl('mip', $post, $siteUrl),
                'modified' => date('Y-m-d\TH:i:s', intval($post['modified'])),
                'date' => date('Y-m-d\TH:i:s', intval($post['created'])),
                'isMarkdown' => $normalized['is_markdown'],
                'imgData' => $imageData,
                'mipStatsToken' => $mipStatsToken,
                'desc' => $description,
                'publisher' => $siteName,
                'MIPtext' => $mipText
            ),
            'base_url' => $siteUrl
        ));

        if ($html === '') {
            return $this->render404();
        }
        $this->writeCache($cacheKey, $html);
        $this->response->setContentType('text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function resolveTargetToContent($target)
    {
        $clean = trim((string) $target);
        if ($clean === '') {
            return array();
        }
        $clean = explode('.', $clean)[0];
        $decoded = urldecode($clean);
        $post = $this->fetchPublishedBySlug($decoded);
        if (!empty($post)) {
            return $post;
        }
        if (ctype_digit($decoded)) {
            return $this->fetchPublishedContent(intval($decoded));
        }
        return array();
    }

    private function fetchPublishedBySlug($slug)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'text', 'slug', 'type', 'created', 'modified', 'authorId')
                ->from('table.contents')
                ->where('slug = ?', $slug)
                ->where('status = ?', 'publish')
                ->where('type IN ?', array('post', 'page'))
                ->where('password IS NULL OR password = ?', '')
                ->limit(1)
        );
        if (empty($row)) {
            return array();
        }
        $author = $db->fetchRow(
            $db->select('screenName')->from('table.users')->where('uid = ?', intval($row['authorId']))->limit(1)
        );
        $row['author_name'] = !empty($author['screenName']) ? $author['screenName'] : 'Author';
        return $row;
    }

    private function fetchPublishedList($page, $pageSize)
    {
        $db = Typecho_Db::get();
        return $db->fetchAll(
            $db->select('cid', 'slug', 'type', 'title', 'text', 'created', 'modified')
                ->from('table.contents')
                ->where('status = ?', 'publish')
                ->where('type IN ?', array('post', 'page'))
                ->where('password IS NULL OR password = ?', '')
                ->order('created', Typecho_Db::SORT_DESC)
                ->page(max(1, intval($page)), max(1, intval($pageSize)))
        );
    }

    private function fetchPublishedCount()
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select(array('COUNT(cid)' => 'total'))
                ->from('table.contents')
                ->where('status = ?', 'publish')
                ->where('type IN ?', array('post', 'page'))
                ->where('password IS NULL OR password = ?', '')
        );
        return isset($row['total']) ? intval($row['total']) : 0;
    }

    private function renderTemplate($template, $vars)
    {
        $html = $this->renderTemplateContent($template, $vars);
        if ($html === '') {
            return $this->render404();
        }
        $this->response->setContentType('text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function renderTemplateContent($template, $vars)
    {
        $templatePath = __DIR__ . '/templates/' . $template;
        if (!is_file($templatePath)) {
            return '';
        }
        if (!is_array($vars)) {
            $vars = array();
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }

    private function normalizeContentText($text)
    {
        $text = (string) $text;
        $isMarkdown = (strpos($text, '<!--markdown-->') === 0);
        if ($isMarkdown) {
            $text = substr($text, 15);
        }
        if ($isMarkdown && class_exists('Markdown') && method_exists('Markdown', 'convert')) {
            $html = Markdown::convert($text);
        } elseif ($isMarkdown) {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        } else {
            $widget = Typecho_Widget::widget('Widget_Abstract_Contents');
            $html = $widget->autoP($text);
        }
        return array(
            'is_markdown' => $isMarkdown,
            'html' => (string) $html
        );
    }

    private function getImageMeta($text, $fallbackUrl)
    {
        $imgUrl = XtSeoMaster_Plugin::extractFirstImage((string) $text);
        if ($imgUrl === '') {
            $imgUrl = (string) $fallbackUrl;
        }
        if ($imgUrl === '') {
            return null;
        }
        $meta = @getimagesize($imgUrl);
        $width = isset($meta[0]) ? intval($meta[0]) : 1200;
        $height = isset($meta[1]) ? intval($meta[1]) : 800;
        return array(
            'url' => $imgUrl,
            'width' => $width > 0 ? $width : 1200,
            'height' => $height > 0 ? $height : 800
        );
    }

    private function substrFormat($text, $length, $replace = '...', $encoding = 'UTF-8')
    {
        $text = (string) $text;
        if ($text !== '' && mb_strlen($text, $encoding) > $length) {
            return mb_substr($text, 0, $length, $encoding) . $replace;
        }
        return $text;
    }

    private function buildAmpMipUrl($type, $row, $siteUrl)
    {
        $target = '';
        if (!empty($row['slug'])) {
            $target = $row['slug'];
        } elseif (!empty($row['cid'])) {
            $target = (string) $row['cid'];
        }
        if ($target === '') {
            return '';
        }
        $prefix = $type === 'amp' ? 'amp' : 'mip';
        return rtrim($siteUrl, '/') . '/' . $prefix . '/' . rawurlencode($target);
    }

    private function makeAmpMipSitemap($type)
    {
        $siteUrl = rtrim(Helper::options()->siteUrl, '/') . '/';
        $rows = $this->fetchPublishedList(1, 2000);
        $this->response->setContentType('application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($rows as $row) {
            $url = $this->buildAmpMipUrl($type, $row, $siteUrl);
            if ($url === '') {
                continue;
            }
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "</loc>\n";
            echo "    <lastmod>" . date('Y-m-d', intval($row['modified'])) . "</lastmod>\n";
            echo "  </url>\n";
        }
        echo '</urlset>';
        exit;
    }

    private function fetchPublishedContent($cid)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'text', 'slug', 'type', 'created', 'modified', 'authorId')
                ->from('table.contents')
                ->where('cid = ?', intval($cid))
                ->where('status = ?', 'publish')
                ->where('type IN ?', array('post', 'page'))
                ->limit(1)
        );
        if (empty($row)) {
            return array();
        }

        $author = $db->fetchRow(
            $db->select('screenName')
                ->from('table.users')
                ->where('uid = ?', intval($row['authorId']))
                ->limit(1)
        );
        $row['author_name'] = !empty($author['screenName']) ? $author['screenName'] : 'Author';
        return $row;
    }

    private function cacheFilePath($key)
    {
        $dir = __DIR__ . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . md5($key) . '.html';
    }

    private function readCache($key, $ttl)
    {
        if ($ttl <= 0) {
            return '';
        }
        $path = $this->cacheFilePath($key);
        if (!is_file($path)) {
            return '';
        }
        if (time() - filemtime($path) > $ttl) {
            return '';
        }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    private function writeCache($key, $content)
    {
        $path = $this->cacheFilePath($key);
        @file_put_contents($path, $content);
    }

    private function clearCacheFiles()
    {
        $dir = __DIR__ . '/cache';
        if (!is_dir($dir)) {
            return 0;
        }
        $files = glob($dir . '/*.html');
        $deleted = 0;
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file) && @unlink($file)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    private function render404()
    {
        if (method_exists($this->response, 'setStatus')) {
            $this->response->setStatus(404);
        }
        $this->response->setContentType('text/plain; charset=UTF-8');
        echo 'Not Found';
        exit;
    }

    private function json($data, $status)
    {
        if (method_exists($this->response, 'setStatus')) {
            $this->response->setStatus(intval($status));
        }
        $this->response->setContentType('application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
