<?php
/**
 * Same-origin Clerk Frontend API transport for Clerk WP Sync.
 * Loaded by the plugin only when an existing UNBC MU proxy is absent.
 */

namespace UNBC\ClerkProxy;

if (!defined('ABSPATH')) {
    exit;
}

// Resolve the public origin from trusted WordPress settings, never request headers.
const PREFIX = '/__clerk';

function use_proxy(): bool {
    $url = wp_parse_url(home_url());
    return is_array($url) && 'https' === ($url['scheme'] ?? '')
        && !empty($url['host']) && empty($url['user']) && empty($url['pass'])
        && '0' !== (string) get_option('clerk_wp_sync_frontend_proxy_enabled', '1');
}

function unbc_clerk_proxy_url(): string {
    return rtrim(home_url(PREFIX), '/');
}

add_filter('script_loader_src', function ($src, $handle) {
    if (!use_proxy() || 'clerk-js' !== $handle) {
        return $src;
    }
    $path = wp_parse_url($src, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/npm/')) {
        return $src;
    }
    $query = wp_parse_url($src, PHP_URL_QUERY);
    return unbc_clerk_proxy_url() . $path . ($query ? '?' . $query : '');
}, 99, 2);

add_filter('script_loader_tag', function ($tag, $handle) {
    if (!use_proxy() || 'clerk-js' !== $handle) {
        return $tag;
    }
    return str_replace('<script ', '<script data-clerk-proxy-url="' . esc_attr(unbc_clerk_proxy_url()) . '" ', $tag);
}, 99, 2);

add_action('init', function () {
    if (!use_proxy()) {
        return;
    }
    $uri = wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $path = wp_parse_url($uri, PHP_URL_PATH);
    $prefix = (string) wp_parse_url(unbc_clerk_proxy_url(), PHP_URL_PATH);
    if (!is_string($path) || ($prefix !== $path && !str_starts_with($path, $prefix . '/'))) {
        return;
    }

    // Never allow a CDN or page cache to store authentication responses.
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    header('Cache-Control: no-store, private');

    $fail = static function (int $status, string $message): never {
        status_header($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode(['errors' => [['message' => $message, 'code' => 'clerk_proxy_unavailable']]]);
        exit;
    };

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
        header('Allow: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS');
        $fail(405, 'Unsupported request method.');
    }
    $suffix = substr($uri, strlen($prefix));
    if ('' === $suffix || '?' === $suffix[0]) {
        $suffix = '/' . $suffix;
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $suffix)) {
        $fail(400, 'Invalid proxy request.');
    }

    // The upstream host is fixed, and redirects are not followed with the secret key.
    $secret = trim((string) get_option('clerk_wp_sync_api_key', ''));
    $publishable = trim((string) get_option('clerk_wp_sync_publishable_key', ''));
    $mode = str_starts_with($secret, 'sk_test_') ? 'test' : 'live';
    if (!preg_match('/^sk_(?:live|test)_[A-Za-z0-9]+$/D', $secret)
        || !str_starts_with($publishable, 'pk_' . $mode . '_')
        || !function_exists('curl_init')) {
        $fail(503, 'Sign-in is temporarily unavailable.');
    }
    $stream = fopen('php://input', 'rb');
    $body = $stream ? stream_get_contents($stream, 1048577) : false;
    if ($stream) {
        fclose($stream);
    }
    if (false === $body || strlen($body) > 1048576) {
        $fail(413, 'Proxy request is too large.');
    }

    $inbound = function_exists('getallheaders') ? getallheaders() : [];
    $headers = [];
    $excluded = [
        'host', 'content-length', 'accept-encoding', 'connection', 'keep-alive',
        'transfer-encoding', 'te', 'trailer', 'upgrade', 'proxy-authorization',
        'proxy-connection', 'clerk-secret-key', 'clerk-proxy-url', 'forwarded',
        'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-wp-nonce',
    ];
    foreach ($inbound as $name => $value) {
        $lower = strtolower((string) $name);
        if (in_array($lower, $excluded, true) || preg_match('/[\r\n]/', (string) $value)) {
            continue;
        }
        if ('cookie' === $lower) {
            // WordPress/admin cookies are unrelated to Clerk and stay on this server.
            $cookies = [];
            foreach (explode(';', (string) $value) as $cookie) {
                if (preg_match('/^__(?:clerk|client|session|refresh|uat|dev_browser)[^=]*=/', trim($cookie))) {
                    $cookies[] = trim($cookie);
                }
            }
            if (!$cookies) {
                continue;
            }
            $value = implode('; ', $cookies);
        }
        $headers[] = $name . ': ' . $value;
    }
    $headers[] = 'Clerk-Proxy-Url: ' . unbc_clerk_proxy_url();
    $headers[] = 'Clerk-Secret-Key: ' . $secret;
    // Do not trust user-supplied forwarding headers. The web server must resolve
    // REMOTE_ADDR through its configured trusted ingress when behind a proxy.
    $client_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($client_ip, FILTER_VALIDATE_IP)) {
        $fail(400, 'Invalid client address.');
    }
    $headers[] = 'X-Forwarded-For: ' . $client_ip;

    $response_headers = [];
    $curl = curl_init('https://frontend-api.clerk.dev' . $suffix);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$response_headers) {
            $length = strlen($line);
            if (str_starts_with($line, 'HTTP/')) {
                $response_headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $response_headers[] = [strtolower(trim($name)), trim($value)];
            }
            return $length;
        },
    ]);
    if ('HEAD' === $method) {
        curl_setopt($curl, CURLOPT_NOBODY, true);
    } elseif ('' !== $body || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (false === $response || $status < 100) {
        $fail(502, 'Sign-in is temporarily unavailable. Please try again.');
    }
    status_header($status);
    foreach ($response_headers as [$name, $value]) {
        if (in_array($name, ['content-type', 'set-cookie', 'location', 'retry-after', 'content-security-policy', 'x-content-type-options'], true)
            || str_starts_with($name, 'clerk-') || str_starts_with($name, 'x-clerk-')) {
            header($name . ': ' . $value, 'set-cookie' !== $name);
        }
    }
    if ('HEAD' !== $method) {
        echo $response;
    }
    exit;
}, 0);
