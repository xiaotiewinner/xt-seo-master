<?php
/**
 * XtSeoMaster 推送管理后台页面。
 *
 * 说明：
 * - 本文件是 Typecho 后台面板脚本（非类文件）
 * - 负责渲染推送统计卡片、推送记录、文章推送列表
 * - 负责前端异步推送与批量删除日志交互
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/QueueRepository.php';
require_once __DIR__ . '/PushService.php';
require_once __DIR__ . '/Action.php';
require_once __DIR__ . '/Plugin.php';

$adminDir = rtrim(__TYPECHO_ROOT_DIR__ . __TYPECHO_ADMIN_DIR__, '/\\');
include_once $adminDir . '/common.php';

$repo = new XtSeoMaster_QueueRepository();
$logs = $repo->recentLogs(5000);

$pluginOptions = null;
try {
    $pluginOptions = Helper::options()->plugin('XtSeoMaster');
} catch (Exception $e) {
    $pluginOptions = null;
}

$siteUrl = rtrim(Helper::options()->siteUrl, '/');
$runnerToken = (is_object($pluginOptions) && !empty($pluginOptions->pushRunnerToken))
    ? $pluginOptions->pushRunnerToken
    : '';
$runnerEndpoint = $siteUrl . '/xt-seo/push-runner';

$enabledEngines = array();
if (is_object($pluginOptions)) {
    $enabledEngines = XtSeoMaster_PushService::enabledEngines($pluginOptions);
}
$engineLabels = array(
    'baidu' => '百度普通推送',
    'baidu_daily' => '百度快速推送',
    'indexnow' => 'IndexNow推送'
);

$formatGmt8 = function ($timestamp) {
    return gmdate('Y-m-d H:i:s', intval($timestamp) + 8 * 3600);
};

$logTotal = count($logs);
$logSuccess = 0;
$logFailed = 0;
foreach ($logs as $logRow) {
    if (intval($logRow['is_success']) === 1) {
        $logSuccess++;
    } else {
        $logFailed++;
    }
}
$recentPushLogs = array_slice($logs, 0, 8);

$db = Typecho_Db::get();
$perPage = 20;
$currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
$currentPage = max(1, $currentPage);
$countRow = $db->fetchRow(
    $db->select(array('COUNT(cid)' => 'total'))
        ->from('table.contents')
        ->where('status = ?', 'publish')
        ->where('type IN ?', array('post', 'page'))
        ->where('password IS NULL OR password = ?', '')
);
$totalPosts = isset($countRow['total']) ? intval($countRow['total']) : 0;
$totalPages = max(1, intval(ceil($totalPosts / $perPage)));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$posts = $db->fetchAll(
    $db->select('cid', 'title', 'slug', 'created', 'type')
        ->from('table.contents')
        ->where('status = ?', 'publish')
        ->where('type IN ?', array('post', 'page'))
        ->where('password IS NULL OR password = ?', '')
        ->order('created', Typecho_Db::SORT_DESC)
        ->page($currentPage, $perPage)
);

$statusMap = array();
foreach ($posts as $post) {
    $cid = intval($post['cid']);
    $siteUrlFull = rtrim(Helper::options()->siteUrl, '/') . '/';
    $canonical = XtSeoMaster_Plugin::buildContentPermalink($post, $siteUrlFull);
    $ampUrl = $canonical !== '' ? rtrim($siteUrlFull, '/') . '/amp/' . rawurlencode(!empty($post['slug']) ? $post['slug'] : $cid) : '';
    $mipUrl = $canonical !== '' ? rtrim($siteUrlFull, '/') . '/mip/' . rawurlencode(!empty($post['slug']) ? $post['slug'] : $cid) : '';
    $targets = array_values(array_filter(array_unique(array($canonical, $ampUrl, $mipUrl))));
    if (empty($targets)) {
        continue;
    }

    $statusMap[$cid] = array();
    foreach (array_keys($engineLabels) as $engine) {
        $latest = null;
        foreach ($logs as $log) {
            if ($log['engine'] !== $engine) {
                continue;
            }
            if (!in_array($log['url'], $targets, true)) {
                continue;
            }
            $latest = $log;
            break;
        }
        $statusMap[$cid][$engine] = $latest;
    }
}

include_once $adminDir . '/header.php';
include_once $adminDir . '/menu.php';
?>

<div class="main">
    <div class="body container">
        <h2>XtSeoMaster · 推送管理</h2>

        <style>
            .xt-card-wrap { display:flex; gap:12px; flex-wrap:wrap; margin:14px 0 18px; }
            .xt-card {
                min-width:120px; padding:10px 12px; border:1px solid #e5e7eb;
                border-radius:8px; background:#fff;
            }
            .xt-card .k { font-size:12px; color:#666; margin-bottom:6px; }
            .xt-card .v { font-size:22px; font-weight:700; color:#222; line-height:1.2; }
            .xt-actions { margin:10px 0 18px; display:flex; gap:10px; flex-wrap:wrap; }
            .xt-actions a, .xt-actions button {
                display:inline-block; padding:8px 12px; border:1px solid #d1d5db;
                border-radius:6px; background:#f8fafc; color:#111; text-decoration:none;
                cursor:pointer; font-size:13px;
            }
            .xt-actions a:hover, .xt-actions button:hover { background:#eef2ff; }
            .xt-actions button:disabled { opacity:0.45; cursor:not-allowed; }
            .xt-muted { color:#666; font-size:12px; margin:6px 0 0; }
            .xt-status-ok { color:#16a34a; font-weight:600; }
            .xt-status-fail { color:#dc2626; font-weight:600; }
            .xt-status-empty { color:#9ca3af; }
            .xt-time-icon { display:inline-block; width:18px; text-align:center; cursor:help; color:#2563eb; }
            .xt-push-btn {
                display:inline-block; padding:4px 8px; margin-right:6px; margin-bottom:4px;
                border:1px solid #d1d5db; border-radius:4px; background:#f8fafc; color:#111; text-decoration:none; font-size:12px;
            }
            .xt-push-btn:hover { background:#eef2ff; }
            .xt-push-btn.disabled { opacity:0.45; pointer-events:none; }
            .typecho-list-table { table-layout:fixed; width:100%; }
            .typecho-list-table th:nth-child(1), .typecho-list-table td:nth-child(1) { width:70px; text-align:center; }
            .typecho-list-table th:nth-child(2), .typecho-list-table td:nth-child(2) { width:48px; text-align:center; }
            .typecho-list-table th:nth-child(3), .typecho-list-table td:nth-child(3) { width:64px; }
            .typecho-list-table th:nth-child(4), .typecho-list-table td:nth-child(4) { width:30%; }
            .typecho-list-table th:nth-child(5), .typecho-list-table td:nth-child(5) { width:26%; }
            .typecho-list-table th:nth-child(6), .typecho-list-table td:nth-child(6) { width:24%; }
            .typecho-list-table th:nth-child(7), .typecho-list-table td:nth-child(7) { width:74px; text-align:center; }
            .xt-title-cell { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .xt-url-cell { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .xt-engine-line { display:block; white-space:nowrap; }
            .xt-pager { margin-top:14px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
            .xt-pager a, .xt-pager span {
                display:inline-block; min-width:28px; text-align:center; padding:4px 8px;
                border:1px solid #d1d5db; border-radius:4px; text-decoration:none;
                background:#fff; color:#111; font-size:12px;
            }
            .xt-pager .current { background:#2563eb; border-color:#2563eb; color:#fff; }
            .xt-pager .muted { color:#9ca3af; border-color:#e5e7eb; }
            .xt-result-box {
                margin: 10px 0 14px;
                padding: 10px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                background: #fbfdff;
                font-size: 12px;
                color: #334155;
                white-space: pre-wrap;
                word-break: break-all;
            }
            .xt-summary-grid { display:flex; gap:14px; align-items:stretch; margin:14px 0 18px; }
            .xt-summary-grid .xt-card-wrap {
                flex: 0 0 300px;
                margin:0;
                display:grid;
                grid-template-columns: 1.15fr 0.85fr;
                grid-template-rows: 1fr 1fr;
                gap:10px;
            }
            .xt-summary-grid .xt-card-total { grid-column:1; grid-row:1 / 3; }
            .xt-summary-grid .xt-card-success { grid-column:2; grid-row:1; }
            .xt-summary-grid .xt-card-failed { grid-column:2; grid-row:2; }
            .xt-recent-card {
                flex: 1 1 auto;
                border:1px solid #e5e7eb;
                border-radius:8px;
                background:#fff;
                padding:10px 12px;
                max-height:160px;
                overflow:auto;
            }
            .xt-recent-card h4 { margin:2px 0 8px; font-size:13px; color:#374151; }
            .xt-recent-item { font-size:12px; line-height:1.55; color:#475569; margin:0 0 6px; white-space:normal; word-break:break-all; }
        </style>

        <div class="xt-summary-grid">
            <div class="xt-card-wrap">
                <div class="xt-card xt-card-total"><div class="k">推送记录总数</div><div class="v"><?php echo intval($logTotal); ?></div></div>
                <div class="xt-card xt-card-success"><div class="k">成功</div><div class="v"><?php echo intval($logSuccess); ?></div></div>
                <div class="xt-card xt-card-failed"><div class="k">失败</div><div class="v"><?php echo intval($logFailed); ?></div></div>
            </div>
            <div class="xt-recent-card">
                <h4>最近推送记录</h4>
                <?php if (empty($recentPushLogs)): ?>
                    <p class="xt-recent-item">暂无推送记录。</p>
                <?php else: ?>
                    <?php foreach ($recentPushLogs as $recentRow): ?>
                        <?php
                            $engineName = isset($engineLabels[$recentRow['engine']]) ? $engineLabels[$recentRow['engine']] : $recentRow['engine'];
                            $statusText = intval($recentRow['is_success']) === 1 ? '成功' : '失败';
                        ?>
                        <p class="xt-recent-item">
                            <?php echo $formatGmt8($recentRow['created_at']); ?>
                            向<?php echo htmlspecialchars($engineName, ENT_QUOTES, 'UTF-8'); ?>推送：
                            <?php echo htmlspecialchars($recentRow['url'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo $statusText; ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="xt-actions">
            <button type="button" id="xt-batch-push" disabled>推送</button>
            <button type="button" id="xt-batch-push-urlonly" disabled>推送（不包含AMP/MIP）</button>
            <button type="button" id="xt-batch-delete" disabled>删除推送记录</button>
            <button type="button" id="xt-push-all">全部推送</button>
        </div>
        <p class="xt-muted">提示：上方“推送”会推送 URL+AMP+MIP；“推送（不包含AMP/MIP）”只推送 URL；“删除推送记录”会删除勾选文章对应 URL/AMP/MIP 的历史推送记录；“全部推送”会对已发布内容执行一次全量手动推送。</p>
        <div id="xt-result" class="xt-result-box">准备就绪。</div>

        <h3 style="margin-top:20px;">文章推送状态（按发布时间倒序）</h3>
        <table class="typecho-list-table">
            <thead>
                <tr>
                    <th>操作</th>
                    <th><input type="checkbox" id="xt-select-all" title="全选/反选" /></th>
                    <th>CID</th>
                    <th>标题</th>
                    <th>URL</th>
                    <th>推送状态</th>
                    <th>推送时间</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($posts)): ?>
                <tr>
                    <td colspan="7">暂无已发布文章</td>
                </tr>
            <?php else: ?>
                <?php foreach ($posts as $row): ?>
                <?php
                    $cid = intval($row['cid']);
                    $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
                    $tips = array();
                    $siteUrlFull = rtrim(Helper::options()->siteUrl, '/') . '/';
                    $canonical = XtSeoMaster_Plugin::buildContentPermalink($row, $siteUrlFull);
                    $ampUrl = $canonical !== '' ? rtrim($siteUrlFull, '/') . '/amp/' . rawurlencode(!empty($row['slug']) ? $row['slug'] : $cid) : '';
                    $mipUrl = $canonical !== '' ? rtrim($siteUrlFull, '/') . '/mip/' . rawurlencode(!empty($row['slug']) ? $row['slug'] : $cid) : '';
                    $targets = array_values(array_filter(array_unique(array($canonical, $ampUrl, $mipUrl))));
                    foreach ($logs as $log) {
                        if (!in_array($log['url'], $targets, true)) {
                            continue;
                        }
                        if (!isset($engineLabels[$log['engine']])) {
                            continue;
                        }
                        $tips[] = $formatGmt8($log['created_at']) . ' | ' . $engineLabels[$log['engine']] . ' | ' . (intval($log['is_success']) === 1 ? '成功' : '失败');
                        if (count($tips) >= 5) {
                            break;
                        }
                    }
                    $tipText = empty($tips) ? '暂无推送记录' : implode("\n", $tips);
                ?>
                <tr>
                    <td>
                        <?php $enabled = !empty($enabledEngines); ?>
                        <button type="button" class="xt-push-btn xt-row-push<?php echo $enabled ? '' : ' disabled'; ?>" data-cid="<?php echo $cid; ?>"<?php echo $enabled ? '' : ' disabled'; ?>>推送</button>
                    </td>
                    <td><input type="checkbox" class="xt-row-check" value="<?php echo $cid; ?>" /></td>
                    <td>
                        <?php echo $cid; ?>
                    </td>
                    <td class="xt-title-cell" title="<?php echo $title; ?>"><?php echo $title; ?></td>
                    <td class="xt-url-cell" title="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td>
                        <?php foreach ($engineLabels as $engineCode => $engineLabel): ?>
                            <?php $latest = isset($statusMap[$cid][$engineCode]) ? $statusMap[$cid][$engineCode] : null; ?>
                            <span class="xt-engine-line">
                                <?php echo htmlspecialchars($engineLabel, ENT_QUOTES, 'UTF-8'); ?>：
                                <?php if (empty($latest)): ?>
                                    <span class="xt-status-empty">—</span>
                                <?php elseif (intval($latest['is_success']) === 1): ?>
                                    <span class="xt-status-ok">成功</span>
                                <?php else: ?>
                                    <span class="xt-status-fail">失败</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td><span class="xt-time-icon" title="<?php echo htmlspecialchars($tipText, ENT_QUOTES, 'UTF-8'); ?>">🕒</span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
            $basePath = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : 'extending.php';
            $query = $_GET;
            $query['panel'] = 'XtSeoMaster/PushStatus.php';
            unset($query['page']);
            $buildPageUrl = function ($pageNum) use ($basePath, $query) {
                $params = $query;
                $params['page'] = intval($pageNum);
                return $basePath . '?' . http_build_query($params);
            };
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
        ?>
        <div class="xt-pager">
            <?php if ($currentPage > 1): ?>
                <a href="<?php echo htmlspecialchars($buildPageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo; 上一页</a>
            <?php else: ?>
                <span class="muted">&laquo; 上一页</span>
            <?php endif; ?>

            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <?php if ($p === $currentPage): ?>
                    <span class="current"><?php echo $p; ?></span>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($buildPageUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="<?php echo htmlspecialchars($buildPageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8'); ?>">下一页 &raquo;</a>
            <?php else: ?>
                <span class="muted">下一页 &raquo;</span>
            <?php endif; ?>

            <span class="muted">共 <?php echo intval($totalPosts); ?> 篇</span>
        </div>
    </div>
</div>

<script>
(function () {
    var selectAll = document.getElementById('xt-select-all');
    var batchPushBtn = document.getElementById('xt-batch-push');
    var batchPushUrlOnlyBtn = document.getElementById('xt-batch-push-urlonly');
    var batchDeleteBtn = document.getElementById('xt-batch-delete');
    var pushAllBtn = document.getElementById('xt-push-all');
    var resultBox = document.getElementById('xt-result');
    var runnerEndpoint = <?php echo json_encode($runnerEndpoint); ?>;
    var runnerToken = <?php echo json_encode($runnerToken); ?>;
    var hasEngine = <?php echo !empty($enabledEngines) ? 'true' : 'false'; ?>;
    var engineNameMap = {
        baidu: '百度',
        baidu_daily: '百度快速收录',
        indexnow: 'IndexNow'
    };

    function getRowChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.xt-row-check'));
    }
    function getRowPushButtons() {
        return Array.prototype.slice.call(document.querySelectorAll('.xt-row-push'));
    }
    function setResult(text) {
        if (!resultBox) return;
        resultBox.textContent = text;
    }
    function setBusy(busy) {
        var disabled = !!busy;
        if (pushAllBtn) pushAllBtn.disabled = disabled || !hasEngine;
        var rowBtns = getRowPushButtons();
        for (var i = 0; i < rowBtns.length; i++) {
            if (rowBtns[i].classList.contains('disabled')) continue;
            rowBtns[i].disabled = disabled;
        }
    }
    function parseUrlsFromPayload(payload) {
        var text = (payload || '').toString().trim();
        if (!text) return [];
        if (text.charAt(0) === '{') {
            try {
                var obj = JSON.parse(text);
                if (obj && Array.isArray(obj.urlList)) {
                    return obj.urlList;
                }
            } catch (e) {}
        }
        var lines = text.split(/\r?\n/);
        var urls = [];
        for (var i = 0; i < lines.length; i++) {
            var u = lines[i].trim();
            if (u) urls.push(u);
        }
        return urls;
    }
    function formatPushResult(data) {
        if (!data || typeof data !== 'object') {
            return '推送失败：返回结果无效。';
        }
        if (!data.ok) {
            var text = '推送失败：' + (data.message || data.error || '未知错误');
            if (data.raw) {
                text += '\n原始返回：\n' + data.raw;
            }
            return text;
        }
        if (data.task === 'delete_logs') {
            return '删除推送记录完成。\n'
                + '文章数量：' + (data.count || 0) + '\n'
                + 'URL 数量：' + (data.url_count || 0) + '\n'
                + '删除日志条数：' + (data.deleted_logs || 0);
        }
        var details = Array.isArray(data.details) ? data.details : [];
        if (details.length === 0) {
            return '推送完成。\n总数量：' + (data.total || 0) + '\n成功数量：' + (data.success || 0) + '\n失败数量：' + (data.failed || 0);
        }
        var blocks = [];
        for (var i = 0; i < details.length; i++) {
            var d = details[i] || {};
            var engine = d.engine || 'unknown';
            var engineName = engineNameMap[engine] || engine;
            var urls = parseUrlsFromPayload(d.request_payload);
            var total = parseInt(d.url_count || urls.length || 0, 10);
            if (isNaN(total)) total = 0;
            var ok = !!d.success;
            var succ = ok ? total : 0;
            var fail = ok ? 0 : total;
            var reason = ok ? '无' : (d.error || d.response || '未知错误');
            blocks.push(
                '向' + engineName + '推送：\n'
                + (urls.length ? urls.join('\n') : '（无 URL）') + '\n'
                + '总数量：' + total + '\n'
                + '成功数量：' + succ + '\n'
                + '失败数量：' + fail + '\n'
                + '失败原因：' + reason
            );
        }
        blocks.push('汇总：总数量=' + (data.total || 0) + '，成功=' + (data.success || 0) + '，失败=' + (data.failed || 0));
        return blocks.join('\n\n');
    }
    function requestPush(params, done) {
        if (!hasEngine) {
            setResult('未启用任何搜索引擎，无法推送。');
            return;
        }
        params.token = runnerToken;
        var body = new URLSearchParams();
        for (var k in params) {
            if (Object.prototype.hasOwnProperty.call(params, k)) {
                body.append(k, params[k]);
            }
        }
        setBusy(true);
        setResult('推送中，请稍候...');
        fetch(runnerEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) { return { ok: false, raw: t, message: 'invalid json' }; }
            });
        }).then(function (data) {
            setResult(formatPushResult(data));
            if (typeof done === 'function') done(data);
        }).catch(function (err) {
            setResult('请求失败：' + (err && err.message ? err.message : 'unknown error'));
        }).finally(function () {
            setBusy(false);
            updateState();
        });
    }

    function updateState() {
        var checks = getRowChecks();
        var checkedCount = 0;
        for (var i = 0; i < checks.length; i++) {
            if (checks[i].checked) {
                checkedCount++;
            }
        }
        if (selectAll) {
            if (checks.length === 0 || checkedCount === 0) {
                selectAll.checked = false;
                selectAll.disabled = true;
            } else {
                selectAll.disabled = false;
                selectAll.checked = checkedCount === checks.length;
            }
        }
        if (batchPushBtn) {
            batchPushBtn.disabled = !hasEngine || checkedCount === 0;
        }
        if (batchPushUrlOnlyBtn) {
            batchPushUrlOnlyBtn.disabled = !hasEngine || checkedCount === 0;
        }
        if (batchDeleteBtn) {
            batchDeleteBtn.disabled = checkedCount === 0;
        }
        if (pushAllBtn) {
            pushAllBtn.disabled = !hasEngine;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checks = getRowChecks();
            for (var i = 0; i < checks.length; i++) {
                checks[i].checked = selectAll.checked;
            }
            updateState();
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('xt-row-check')) {
            updateState();
        }
    });

    if (batchPushBtn) {
        batchPushBtn.addEventListener('click', function () {
            var checks = getRowChecks();
            var cids = [];
            for (var i = 0; i < checks.length; i++) {
                if (checks[i].checked) {
                    cids.push(checks[i].value);
                }
            }
            if (!hasEngine || cids.length === 0) {
                updateState();
                return;
            }
            requestPush({
                task: 'push_articles_all',
                cids: cids.join(','),
                include_amp_mip: '1'
            });
        });
    }
    if (batchPushUrlOnlyBtn) {
        batchPushUrlOnlyBtn.addEventListener('click', function () {
            var checks = getRowChecks();
            var cids = [];
            for (var i = 0; i < checks.length; i++) {
                if (checks[i].checked) {
                    cids.push(checks[i].value);
                }
            }
            if (!hasEngine || cids.length === 0) {
                updateState();
                return;
            }
            requestPush({
                task: 'push_articles_all',
                cids: cids.join(','),
                include_amp_mip: '0'
            });
        });
    }
    if (batchDeleteBtn) {
        batchDeleteBtn.addEventListener('click', function () {
            var checks = getRowChecks();
            var cids = [];
            for (var i = 0; i < checks.length; i++) {
                if (checks[i].checked) {
                    cids.push(checks[i].value);
                }
            }
            if (cids.length === 0) {
                updateState();
                return;
            }
            if (!window.confirm('确认删除已选文章的推送记录吗？')) {
                return;
            }
            requestPush({
                task: 'delete_logs',
                cids: cids.join(',')
            });
        });
    }
    if (pushAllBtn) {
        pushAllBtn.addEventListener('click', function () {
            requestPush({
                task: 'push_all',
                limit: '2000'
            });
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.classList.contains('xt-row-push')) {
            return;
        }
        e.preventDefault();
        if (e.target.classList.contains('disabled')) {
            return;
        }
        var cid = e.target.getAttribute('data-cid') || '';
        if (!cid) {
            return;
        }
        requestPush({
            task: 'push_article_all',
            cid: cid,
            engine: 'all',
            include_amp_mip: '1'
        });
    });

    updateState();
})();
</script>

<?php
include_once $adminDir . '/copyright.php';
include_once $adminDir . '/common-js.php';
include_once $adminDir . '/footer.php';
