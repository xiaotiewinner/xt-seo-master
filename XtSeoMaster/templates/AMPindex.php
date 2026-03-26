<!doctype html>
<html amp lang="zh">
<head>
    <meta charset="utf-8">
    <script async src="https://cdn.ampproject.org/v0.js"></script>
    <script async custom-element="amp-list" src="https://cdn.ampproject.org/v0/amp-list-0.1.js"></script>
    <script async custom-template="amp-mustache" src="https://cdn.ampproject.org/v0/amp-mustache-0.2.js"></script>
    <script async custom-element="amp-bind" src="https://cdn.ampproject.org/v0/amp-bind-0.1.js"></script>
    <title><?php echo htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8'); ?> -- AMP Version</title>
    <link rel="canonical" href="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>"/>
    <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
    <style amp-custom>*{margin:0;padding:0}html,body{height:100%}body{background:#fff;color:#666;font-size:14px;font-family:"-apple-system","Open Sans","Helvetica Neue",Helvetica,Arial,sans-serif}a{color:#2479cc;text-decoration:none}header{background:#fff;box-shadow:0 0 20px rgba(0,0,0,.08);height:60px;padding:0 15px;position:absolute;width:100%;box-sizing:border-box}header h1{font-size:28px;line-height:60px;font-weight:400}.content{padding-top:60px}article{padding:24px 18px;border-bottom:1px solid #eee}article a{font-size:1.4em}article p{line-height:1.9em;font-size:15px;padding-top:10px}.pageinfo{font-size:14px;padding:8px;text-align:center}.info{background:#f8f8f8;border-left:4px solid #2479cc;color:#444;font-size:14px;padding:10px 12px;margin:10px 0}.nav{text-align:center;margin:6px 0 12px}.nav button{width:130px;height:32px;margin:0 4px;border:0;border-radius:4px;background:#1e90ff;cursor:pointer;color:#fff;font-size:14px}.nav button:hover{background:#59f}.footer{font-size:.9em;text-align:center;padding:10px 10px 20px}
    </style>
    <style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style>
    <noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>
</head>
<body>
<header>
    <h1><a href="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8'); ?></a></h1>
</header>
<div class="content">
    <amp-list width="auto"
              height="680"
              layout="fixed-height"
              src="<?php echo htmlspecialchars($amp_list_first_url, ENT_QUOTES, 'UTF-8'); ?>"
              [src]="'<?php echo htmlspecialchars($amp_list_base_url, ENT_QUOTES, 'UTF-8'); ?>' + pageNumber"
              single-item>
        <template type="amp-mustache">
            {{#article}}
            <article>
                <a href="{{url}}">{{title}}</a>
                <p>{{content}}</p>
            </article>
            {{/article}}
            <p class="pageinfo">Page {{currentPage}} of {{pageCount}}</p>
        </template>
    </amp-list>
</div>
<footer>
    <div class="nav">
        <button class="prev"
                hidden
                [hidden]="pageNumber < 2"
                on="tap:AMP.setState({ pageNumber: pageNumber - 1 })">Previous</button>
        <button class="next"
                [hidden]="page ? pageNumber >= page.items.pageCount : false"
                on="tap:AMP.setState({ pageNumber: pageNumber ? pageNumber + 1 : 2 })">Next</button>
    </div>

    <amp-state id="page"
               src="<?php echo htmlspecialchars($amp_list_first_url, ENT_QUOTES, 'UTF-8'); ?>"
               [src]="'<?php echo htmlspecialchars($amp_list_base_url, ENT_QUOTES, 'UTF-8'); ?>' + pageNumber"></amp-state>

    <p class="info">当前页面是本站的「<a href="//www.ampproject.org/zh_cn/">Google AMP</a>」版。查看和发表评论请点击：<a href="<?php echo htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8'); ?>">完整版 »</a></p>
    <div class="footer"><p>&copy; 2026 <a href="https://github.com/xiaotiewinner/xt-seo-master">xt-seo-master</a> v1.0.0 , Designed by <a href="https://www.xiaotiewinner.com">小铁</a>.</p></div>
</footer>
</body>
</html>
