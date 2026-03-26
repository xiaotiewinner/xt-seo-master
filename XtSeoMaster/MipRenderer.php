<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * XtSeoMaster MIP 渲染器。
 *
 * 职责：
 * - 将文章数据渲染为 MIP HTML
 * - 复用正文规范化逻辑并输出 mip-img
 * - 输出 MIP 页面所需结构化数据
 */
class XtSeoMaster_MipRenderer
{
    public static function render($post, $siteName, $siteUrl, $canonicalUrl, $description, $imageUrl)
    {
        $title = htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8');
        $desc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $canonical = htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8');
        $published = date('c', intval($post['created']));
        $modified = date('c', intval($post['modified']));
        $body = XtSeoMaster_AmpRenderer::normalizeBody($post['text'], 'mip');
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post['title'],
            'description' => $description,
            'url' => $canonicalUrl,
            'datePublished' => $published,
            'dateModified' => $modified,
            'author' => array(
                '@type' => 'Person',
                'name' => $post['author_name']
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => $siteUrl
            )
        );
        if ($imageUrl) {
            $schema['image'] = array($imageUrl);
        }

        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<!doctype html>' . "\n"
            . '<html mip lang="zh-CN">' . "\n"
            . '<head>' . "\n"
            . '  <meta charset="utf-8">' . "\n"
            . '  <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">' . "\n"
            . '  <title>' . $title . '</title>' . "\n"
            . '  <link rel="canonical" href="' . $canonical . '">' . "\n"
            . '  <meta name="description" content="' . $desc . '">' . "\n"
            . '  <script src="https://c.mipcdn.com/static/v2/mip.js"></script>' . "\n"
            . '  <style mip-custom>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;line-height:1.8;max-width:840px;margin:0 auto;padding:20px;color:#222}img{max-width:100%}.meta{color:#777;font-size:13px;margin-bottom:16px}</style>' . "\n"
            . '  <script type="application/ld+json">' . $schemaJson . '</script>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '  <article>' . "\n"
            . '    <h1>' . $title . '</h1>' . "\n"
            . '    <div class="meta">' . htmlspecialchars($post['author_name'], ENT_QUOTES, 'UTF-8') . ' · ' . date('Y-m-d H:i', intval($post['created'])) . '</div>' . "\n"
            .      $body . "\n"
            . '  </article>' . "\n"
            . '</body>' . "\n"
            . '</html>';
    }
}
