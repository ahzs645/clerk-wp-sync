<?php
// Isolated transport fixture. Never loaded by WordPress or included in packages.
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['fixture'] = json_decode(stream_get_contents(STDIN), true);
    $GLOBALS['hooks'] = [];
    $GLOBALS['status'] = 200;
    $GLOBALS['response_headers'] = [];
    function home_url($path = '') { return ($GLOBALS['fixture']['home'] ?? 'https://local.example') . $path; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function get_option($name, $fallback = '') {
        return ($GLOBALS['fixture']['options'] ?? [])[$name] ?? [
            'clerk_wp_sync_api_key' => 'sk_test_fixture',
            'clerk_wp_sync_publishable_key' => 'pk_test_fixture',
        ][$name] ?? $fallback;
    }
    function add_filter($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
    function add_action($name, $callback, ...$args) { add_filter($name, $callback); }
    function wp_unslash($value) { return $value; }
    function nocache_headers() {}
    function status_header($status) { $GLOBALS['status'] = $status; }
    function wp_json_encode($value) { return json_encode(['status' => $GLOBALS['status'], 'body' => $value]); }
    function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
    function getallheaders() { return $GLOBALS['fixture']['headers'] ?? []; }
    $_SERVER['REQUEST_URI'] = $GLOBALS['fixture']['uri'] ?? '/__clerk/v1/environment?example=1';
    $_SERVER['REQUEST_METHOD'] = $GLOBALS['fixture']['method'] ?? 'GET';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
}
namespace UNBC\ClerkProxy {
    // All upstream operations are captured; the fixture cannot contact Clerk.
    function header($line, $replace = true) { $GLOBALS['response_headers'][] = $line; }
    function fopen($path, $mode) {
        $stream = \fopen('php://memory', 'w+');
        \fwrite($stream, $GLOBALS['fixture']['body'] ?? '');
        \rewind($stream);
        return $stream;
    }
    function curl_init($url) { $GLOBALS['upstream'] = $url; return new \stdClass(); }
    function curl_setopt_array($curl, $options) { $GLOBALS['curl_options'] = $options; return true; }
    function curl_setopt($curl, $option, $value) { $GLOBALS['curl_options'][$option] = $value; return true; }
    function curl_getinfo($curl, $option) { return 200; }
    function curl_exec($curl) {
        return \json_encode([
            'status' => 200,
            'upstream' => $GLOBALS['upstream'],
            'headers' => $GLOBALS['curl_options'][CURLOPT_HTTPHEADER],
            'method' => $GLOBALS['curl_options'][CURLOPT_CUSTOMREQUEST],
            'body' => $GLOBALS['curl_options'][CURLOPT_POSTFIELDS] ?? '',
            'followRedirects' => $GLOBALS['curl_options'][CURLOPT_FOLLOWLOCATION],
            'verifyPeer' => $GLOBALS['curl_options'][CURLOPT_SSL_VERIFYPEER],
            'verifyHost' => $GLOBALS['curl_options'][CURLOPT_SSL_VERIFYHOST],
            'responseHeaders' => $GLOBALS['response_headers'],
        ]);
    }
}
namespace {
    require dirname(__DIR__) . '/includes/Auth/frontend-proxy.php';
    if (($GLOBALS['fixture']['action'] ?? '') === 'settings') {
        $src = 'https://fixture.clerk.accounts.dev/npm/@clerk/clerk-js@5/dist/clerk.browser.js';
        foreach ($GLOBALS['hooks']['script_loader_src'] as $fn) { $src = $fn($src, 'clerk-js'); }
        $tag = '<script src="' . $src . '"></script>';
        foreach ($GLOBALS['hooks']['script_loader_tag'] as $fn) { $tag = $fn($tag, 'clerk-js'); }
        echo json_encode(['url' => UNBC\ClerkProxy\unbc_clerk_proxy_url(), 'src' => $src, 'tag' => $tag]);
        exit;
    }
    foreach ($GLOBALS['hooks']['init'] as $fn) { $fn(); }
    echo json_encode(['unhandled' => true]);
}
