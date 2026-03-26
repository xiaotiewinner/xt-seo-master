<!DOCTYPE html>
<html lang="zh-cn" mip>
<head>
    <meta charset="utf-8">
    <meta name="X-UA-Compatible" content="IE=edge">
    <title><?php echo htmlspecialchars($MIPpage['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <link rel="stylesheet" type="text/css" href="https://mipcache.bdstatic.com/static/v1/mip.css">
    <link rel="canonical" href="<?php echo htmlspecialchars($MIPpage['permalink'], ENT_QUOTES, 'UTF-8'); ?>">
    <style mip-custom>p{text-indent:2em}*{box-sizing:border-box}body{overflow-x:hidden;font-family:"Helvetica Neue",Helvetica,Arial,sans-serif;font-size:15px;color:#444;background:#fff}.main{padding:60px 10px 0;max-width:1000px;margin:0 auto}.post{position:relative;padding:15px 10px;border-top:1px solid #fff;border-bottom:1px solid #ddd;word-wrap:break-word;background:#fff}.title{padding:10px 0;font-size:28px}.meta{color:#666}.article-content{line-height:1.8em;color:#444}.article-content .tip,.article-content .tip-error{position:relative;margin:1em 0;padding:1em 20px;border:1px solid #e3e3e3;border-left:5px solid #5fb878;border-top-right-radius:2px;border-bottom-right-radius:2px}.article-content .tip-error{border-left-color:#ff5722}.footer{line-height:1.8;text-align:center;padding:15px;border-top:1px solid #fff;font-size:.9em;color:#999}.footer a{color:#2479c2}
    </style>
    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "@id": <?php echo json_encode($MIPpage['mipurl']); ?>,
  "headline": <?php echo json_encode($MIPpage['title']); ?>,
  "description": <?php echo json_encode($MIPpage['desc']); ?>,
  "datePublished": <?php echo json_encode($MIPpage['date']); ?>,
  "dateModified": <?php echo json_encode($MIPpage['modified']); ?>,
  "image": <?php echo json_encode(is_array($MIPpage['imgData']) ? array($MIPpage['imgData']['url']) : array()); ?>,
  "publisher": {
    "@type": "Organization",
    "name": <?php echo json_encode($MIPpage['publisher']); ?>
  }
}
    </script>
</head>
<body>
<div class="main">
    <article class="post">
        <h1 class="title"><?php echo htmlspecialchars($MIPpage['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="meta"><?php echo htmlspecialchars($MIPpage['date'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="article-content">
            <?php echo $MIPpage['MIPtext']; ?>
            <div class="tip">当前页面是本站的「<a href="https://www.mipengine.org/">Baidu MIP</a>」版。发表评论请点击：<a href="<?php echo htmlspecialchars($MIPpage['permalink'], ENT_QUOTES, 'UTF-8'); ?>">完整版 »</a></div>
            <?php if (!$MIPpage['isMarkdown']) { echo '<div class="tip-error">因本文不是用 Markdown 编辑器书写，转换页面可能存在兼容差异。</div>'; } ?>
        </div>
    </article>
</div>
<div class="footer"><p>&copy; 2026 <a href="https://github.com/xiaotiewinner/xt-seo-master">xt-seo-master</a> v1.0.0 , Designed by <a href="https://www.xiaotiewinner.com">小铁</a>.</p></div>
<script src="https://mipcache.bdstatic.com/static/v1/mip.js"></script>
<?php if (!empty($MIPpage['mipStatsToken'])): ?>
<script src="https://c.mipcdn.com/static/v1/mip-stats-baidu/mip-stats-baidu.js"></script>
<mip-stats-baidu>
    <script type="application/json">
        {"token": <?php echo json_encode($MIPpage['mipStatsToken']); ?>}
    </script>
</mip-stats-baidu>
<?php endif; ?>
</body>
</html>
