<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * XtSeoMaster 搜索引擎推送服务。
 *
 * 职责：
 * - 识别已启用推送引擎
 * - 构造并发送百度普通/快速推送请求
 * - 构造并发送 IndexNow 请求
 * - 统一返回结构化推送结果（成功、状态码、响应、错误）
 */
class XtSeoMaster_PushService
{
    public static function enabledEngines($options)
    {
        $engines = array();
        if (!isset($options->pushEnableBaidu) || $options->pushEnableBaidu == '1') {
            $engines[] = 'baidu';
        }
        if (!empty($options->pushEnableBaiduDaily) && $options->pushEnableBaiduDaily == '1') {
            $engines[] = 'baidu_daily';
        }
        if (!empty($options->pushEnableIndexNow) && $options->pushEnableIndexNow == '1') {
            $engines[] = 'indexnow';
        }
        return $engines;
    }

    public static function pushUrl($engine, $url, $options, $siteUrl)
    {
        $result = self::pushUrls($engine, array($url), $options, $siteUrl);
        return array(
            'success' => !empty($result['success']),
            'http_code' => intval($result['http_code']),
            'response' => (string) $result['response'],
            'error' => (string) $result['error'],
            'request_payload' => (string) $result['request_payload']
        );
    }

    public static function pushUrls($engine, $urls, $options, $siteUrl)
    {
        $urls = array_values(array_unique(array_filter(array_map('trim', (array) $urls))));
        if (empty($urls)) {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'empty urls',
                'request_payload' => ''
            );
        }
        switch ($engine) {
            case 'baidu':
                return self::pushToBaidu($urls, $options, $siteUrl, false);
            case 'baidu_daily':
                return self::pushToBaidu($urls, $options, $siteUrl, true);
            case 'indexnow':
                return self::pushToIndexNow($urls, $options, $siteUrl);
            default:
                return array(
                    'success' => false,
                    'http_code' => 0,
                    'response' => '',
                    'error' => 'unknown engine',
                    'request_payload' => ''
                );
        }
    }

    private static function pushToBaidu($urls, $options, $siteUrl, $daily)
    {
        $token = trim((string) $options->pushBaiduToken);
        if ($token === '') {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'missing baidu token',
                'request_payload' => implode("\n", (array) $urls)
            );
        }

        $site = trim((string) $siteUrl);
        $site = self::stripHttpScheme($site);
        $site = rtrim($site, '/');
        if ($site === '') {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'missing baidu site',
                'request_payload' => implode("\n", (array) $urls)
            );
        }

        $api = 'http://data.zz.baidu.com/urls?site=' . rawurlencode($site) . '&token=' . rawurlencode($token);
        if ($daily) {
            $api .= '&type=daily';
        }

        $normalizedUrls = array();
        foreach ((array) $urls as $u) {
            $normalized = self::stripHttpScheme((string) $u);
            if ($normalized !== '') {
                $normalizedUrls[] = $normalized;
            }
        }
        $normalizedUrls = array_values(array_unique($normalizedUrls));
        if (empty($normalizedUrls)) {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'empty baidu urls',
                'request_payload' => ''
            );
        }

        $payload = implode("\n", $normalizedUrls);
        $result = self::httpPostCurlText($api, $payload, array('Content-Type: text/plain'));
        $parsed = self::decodeJson($result['response']);
        $ok = $result['http_code'] >= 200 && $result['http_code'] < 300;
        $errorText = $result['error'];

        if (is_array($parsed)) {
            if (isset($parsed['error'])) {
                $ok = false;
                $errorText = 'baidu error ' . $parsed['error']
                    . (isset($parsed['message']) ? (': ' . $parsed['message']) : '');
            } elseif (!isset($parsed['success']) && $ok) {
                // 2xx 但返回体异常时视为失败，避免“假成功”
                $ok = false;
                $errorText = 'baidu response missing success field';
            }
        } elseif ($ok && trim((string) $result['response']) !== '') {
            $ok = false;
            $errorText = 'baidu response is not valid json';
        }

        return array(
            'success' => $ok,
            'http_code' => $result['http_code'],
            'response' => $result['response'],
            'error' => $ok ? '' : $errorText,
            'request_payload' => $payload
        );
    }

    private static function pushToIndexNow($urls, $options, $siteUrl)
    {
        $key = trim((string) $options->pushIndexNowKey);
        if ($key === '') {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'missing indexnow key',
                'request_payload' => ''
            );
        }

        self::ensureIndexNowKeyFile($key);
        $host = parse_url($siteUrl, PHP_URL_HOST);
        if ($host === '') {
            return array(
                'success' => false,
                'http_code' => 0,
                'response' => '',
                'error' => 'missing indexnow host',
                'request_payload' => ''
            );
        }
        $keyLocation = rtrim($siteUrl, '/') . '/' . rawurlencode($key) . '.txt';
        $payloadData = array(
            'host' => $host,
            'key' => $key,
            'keyLocation' => $keyLocation,
            'urlList' => array_values((array) $urls)
        );
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $apiUrl = 'https://www.bing.com/indexnow';
        $result = self::httpPost($apiUrl, $payload, array('Content-Type: application/json; charset=utf-8'));

        $ok = $result['http_code'] >= 200 && $result['http_code'] < 300;
        return array(
            'success' => $ok,
            'http_code' => $result['http_code'],
            'response' => $result['response'],
            'error' => $ok ? '' : $result['error'],
            'request_payload' => $payload
        );
    }

    private static function httpPost($url, $body, $headers)
    {
        $timeout = 8;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);

            return array(
                'http_code' => $httpCode,
                'response' => is_string($response) ? $response : '',
                'error' => $error
            );
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true
            )
        ));
        $response = @file_get_contents($url, false, $context);
        $httpCode = 0;
        if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = intval($m[1]);
        }

        return array(
            'http_code' => $httpCode,
            'response' => is_string($response) ? $response : '',
            'error' => $response === false ? 'http request failed' : ''
        );
    }

    private static function httpGet($url)
    {
        $timeout = 8;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);

            return array(
                'http_code' => $httpCode,
                'response' => is_string($response) ? $response : '',
                'error' => $error
            );
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true
            )
        ));
        $response = @file_get_contents($url, false, $context);
        $httpCode = 0;
        if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = intval($m[1]);
        }

        return array(
            'http_code' => $httpCode,
            'response' => is_string($response) ? $response : '',
            'error' => $response === false ? 'http request failed' : ''
        );
    }

    private static function ensureIndexNowKeyFile($key)
    {
        $safeKey = trim((string) $key);
        if ($safeKey === '') {
            return;
        }
        $path = rtrim(__TYPECHO_ROOT_DIR__, '/\\') . DIRECTORY_SEPARATOR . $safeKey . '.txt';
        if (is_file($path)) {
            return;
        }
        @file_put_contents($path, $safeKey);
    }

    private static function decodeJson($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    // Baidu push is forced to cURL to match official examples.
    private static function httpPostCurlText($url, $body, $headers)
    {
        if (!function_exists('curl_init')) {
            return array(
                'http_code' => 0,
                'response' => '',
                'error' => 'curl extension not enabled'
            );
        }

        $timeout = 8;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, (array) $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'response' => is_string($response) ? $response : '',
            'error' => (string) $error
        );
    }

    private static function stripHttpScheme($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        return preg_replace('#^https?://#i', '', $url);
    }
}
