<!doctype html>
<html amp lang="zh">
<head>
    <meta charset="utf-8">
    <script async src="https://cdn.ampproject.org/v0.js"></script>
    <title><?php echo htmlspecialchars($AMPpage['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="canonical" href="<?php echo htmlspecialchars($AMPpage['permalink'], ENT_QUOTES, 'UTF-8'); ?>"/>
    <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
    <script type="application/ld+json">
{
  "@context": "http://schema.org",
  "@type": "BlogPosting",
  "headline": <?php echo json_encode($AMPpage['title']); ?>,
  "mainEntityOfPage": <?php echo json_encode($AMPpage['permalink']); ?>,
  "author": {
    "@type": "Person",
    "name": <?php echo json_encode($AMPpage['author']); ?>
  },
  "datePublished": <?php echo json_encode($AMPpage['date']); ?>,
  "dateModified": <?php echo json_encode($AMPpage['modified']); ?>,
  "image": {
    "@type": "ImageObject",
    "url": <?php echo json_encode(is_array($AMPpage['imgData']) ? $AMPpage['imgData']['url'] : ''); ?>,
    "width": <?php echo json_encode(is_array($AMPpage['imgData']) ? (string)$AMPpage['imgData']['width'] : '1200'); ?>,
    "height": <?php echo json_encode(is_array($AMPpage['imgData']) ? (string)$AMPpage['imgData']['height'] : '800'); ?>
  },
  "publisher": {
    "@type": "Organization",
    "name": <?php echo json_encode($AMPpage['publisher']); ?>,
    "logo": {
      "@type": "ImageObject",
      "url": <?php echo json_encode($AMPpage['LOGO']); ?>,
      "width": 60,
      "height": 60
    }
  },
  "description": <?php echo json_encode($AMPpage['desc']); ?>
}
    </script>
    <style amp-custom>*{margin:0;padding:0}body{background:#fff;color:#666;font-size:14px;font-family:"-apple-system","Open Sans","Helvetica Neue",Helvetica,Arial,sans-serif}a{color:#2479cc;text-decoration:none}.header{background:#fff;box-shadow:0 0 20px rgba(0,0,0,.08);height:60px;padding:0 15px;position:absolute;width:100%;box-sizing:border-box}.header h1{font-size:28px;line-height:60px;font-weight:400}.post{padding:82px 15px 0}.entry-content{color:#444;font-size:16px;line-height:1.85;word-wrap:break-word}.entry-content p{margin-top:14px;text-indent:2em}.entry-content blockquote,.entry-content ul,.entry-content ol,.entry-content pre,.entry-content table,.entry-content h1,.entry-content h2,.entry-content h3,.entry-content h4{margin-top:15px}.title{color:#333;font-size:2em;font-weight:300;line-height:1.35;margin-bottom:20px}.notice{background:#f8f8f8;border-left:4px solid #2479cc;color:#333;font-size:14px;padding:8px 10px;margin:18px 0}.footer{font-size:.9em;padding:14px 0 24px;text-align:center}
    </style>
    <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style>
    <noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>
</head>
<body>
<header class="header">
    <h1><a href="<?php echo htmlspecialchars(rtrim($base_url, '/') . '/ampindex/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($AMPpage['publisher'], ENT_QUOTES, 'UTF-8'); ?></a></h1>
</header>

<article class="post">
    <h1 class="title"><?php echo htmlspecialchars($AMPpage['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="entry-content"><?php echo $AMPpage['AMPtext']; ?></div>
    <p class="notice">当前页面是本站的「<a href="//www.ampproject.org/zh_cn/">Google AMP</a>」版。查看和发表评论请点击：<a href="<?php echo htmlspecialchars($AMPpage['permalink'], ENT_QUOTES, 'UTF-8'); ?>">完整版 »</a></p>
    <?php if (!$AMPpage['isMarkdown']) { echo '<p class="notice">因本文不是用 Markdown 编辑器书写，转换页面可能存在兼容差异。</p>'; } ?>
</article>
<footer><div class="footer"><p>&copy; 2026 <a href="https://github.com/xiaotiewinner/xt-seo-master">xt-seo-master</a> v1.0.0 , Designed by <a href="https://www.xiaotiewinner.com">小铁</a>.</p></div></footer>
</body>
</html>
