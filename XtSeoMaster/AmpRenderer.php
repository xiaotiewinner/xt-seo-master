<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * XtSeoMaster AMP 渲染器。
 *
 * 职责：
 * - 将文章数据渲染为 AMP HTML
 * - 规范化正文内容并转换图片标签为 amp-img
 * - 输出 AMP 页面所需结构化数据
 */
class XtSeoMaster_AmpRenderer
{
    public static function render($post, $siteName, $siteUrl, $canonicalUrl, $description, $imageUrl)
    {
        $title = htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8');
        $desc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $canonical = htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8');
        $published = date('c', intval($post['created']));
        $modified = date('c', intval($post['modified']));
        $body = self::normalizeBody($post['text'], 'amp');
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
            . '<html amp lang="zh-CN">' . "\n"
            . '<head>' . "\n"
            . '  <meta charset="utf-8">' . "\n"
            . '  <meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">' . "\n"
            . '  <title>' . $title . '</title>' . "\n"
            . '  <link rel="canonical" href="' . $canonical . '">' . "\n"
            . '  <meta name="description" content="' . $desc . '">' . "\n"
            . '  <script async src="https://cdn.ampproject.org/v0.js"></script>' . "\n"
            . '  <style amp-custom>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;line-height:1.8;max-width:840px;margin:0 auto;padding:20px;color:#222}img{max-width:100%}.meta{color:#777;font-size:13px;margin-bottom:16px}</style>' . "\n"
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

    public static function normalizeBody($raw, $mode)
    {
        $html = (string) $raw;
        // Convert Markdown image syntax if unparsed content leaked in.
        $html = preg_replace('/!\[[^\]]*\]\(([^)\s]+)\)/', '<img src="$1" />', $html);
        $html = preg_replace('/<script[\s\S]*?<\/script>/i', '', $html);
        $html = preg_replace('/<style[\s\S]*?<\/style>/i', '', $html);
        $html = preg_replace('/<iframe[\s\S]*?<\/iframe>/i', '', $html);
        $html = preg_replace('/\son\w+="[^"]*"/i', '', $html);
        $html = preg_replace('/\son\w+=\'[^\']*\'/i', '', $html);
        $html = strip_tags($html, '<p><br><h1><h2><h3><h4><ul><ol><li><blockquote><pre><code><strong><b><em><i><a><img>');

        $replaceTag = $mode === 'mip' ? 'mip-img' : 'amp-img';
        $html = preg_replace_callback('/<img\b([^>]*)>/i', function ($m) use ($replaceTag) {
            $attrsText = $m[1];
            $src = self::extractAttr($attrsText, 'src');
            if ($src === '') {
                $src = self::extractAttr($attrsText, 'data-src');
            }
            if ($src === '') {
                return '';
            }

            $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
            $width = intval(self::extractAttr($attrsText, 'width'));
            $height = intval(self::extractAttr($attrsText, 'height'));
            if ($width <= 0 || $height <= 0) {
                $width = 1200;
                $height = 800;
            }

            return '<' . $replaceTag . ' src="' . $src . '" layout="responsive" width="' . $width . '" height="' . $height . '"></' . $replaceTag . '>';
        }, $html);

        return $html;
    }

    private static function extractAttr($attrsText, $name)
    {
        $name = preg_quote($name, '/');
        if (preg_match('/\b' . $name . '\s*=\s*"([^"]*)"/i', $attrsText, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b' . $name . '\s*=\s*\'([^\']*)\'/i', $attrsText, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b' . $name . '\s*=\s*([^\s"\'>]+)/i', $attrsText, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
