<?php
// Transport regression tests use only fake keys and never make network requests.
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
}
function fixture(array $input): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/proxy-fixture.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture'); }
    fwrite($pipes[0], json_encode($input)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'fixture process: ' . $err);
    $decoded = json_decode($out, true);
    check(is_array($decoded), 'fixture JSON: ' . $err);
    return $decoded;
}
$r = fixture(['action' => 'settings']);
check($r['url'] === 'https://local.example/__clerk', 'local canonical proxy URL');
check($r['src'] === 'https://local.example/__clerk/npm/@clerk/clerk-js@5/dist/clerk.browser.js', 'SDK uses local transport');
check(str_contains($r['tag'], 'data-clerk-proxy-url="https://local.example/__clerk"'), 'SDK proxy attribute');
$r = fixture(['action' => 'settings', 'home' => 'https://site.example/subdirectory']);
check($r['url'] === 'https://site.example/subdirectory/__clerk', 'subdirectory URL');
$r = fixture(['uri' => '/subdirectory/__clerk/v1/client', 'home' => 'https://site.example/subdirectory']);
check($r['upstream'] === 'https://frontend-api.clerk.dev/v1/client', 'subdirectory routing');
foreach (['/unrelated', '/__clerk-other/v1/client'] as $uri) {
    check(fixture(['uri' => $uri])['unhandled'] ?? false, 'unrelated routes remain untouched');
}
check(fixture(['home' => 'http://local.example'])['unhandled'] ?? false, 'HTTPS required');
check(fixture(['options' => ['clerk_wp_sync_frontend_proxy_enabled' => '0']])['unhandled'] ?? false, 'explicit proxy opt out');
$r = fixture(['method' => 'POST', 'body' => 'example=1', 'headers' => [
    'Cookie' => 'wordpress_logged_in_private=secret; __client=clerk; other=value; __session=jwt',
    'Clerk-Secret-Key' => 'spoofed', 'Clerk-Proxy-Url' => 'https://evil.example',
    'X-Forwarded-For' => '198.51.100.1', 'X-WP-Nonce' => 'private-wp-nonce', 'Content-Type' => 'application/x-www-form-urlencoded',
]]);
$headers = implode("\n", $r['headers']);
check($r['method'] === 'POST' && $r['body'] === 'example=1', 'method and body preserved');
check($r['upstream'] === 'https://frontend-api.clerk.dev/v1/environment?example=1', 'fixed upstream and query');
check(str_contains($headers, 'Cookie: __client=clerk; __session=jwt'), 'only Clerk cookies forwarded');
check(!str_contains($headers, 'wordpress') && !str_contains($headers, 'other=value'), 'private WP cookies excluded');
check(!str_contains($headers, 'spoofed') && !str_contains($headers, 'evil.example') && !str_contains($headers, 'private-wp-nonce'), 'spoofed proxy and WP auth headers stripped');
check(str_contains($headers, 'Clerk-Proxy-Url: https://local.example/__clerk'), 'trusted canonical origin');
check(str_contains($headers, 'X-Forwarded-For: 192.0.2.1') && !str_contains($headers, '198.51.100.1'), 'untrusted forwarding address ignored');
check(!$r['followRedirects'] && $r['verifyPeer'] && $r['verifyHost'] === 2, 'TLS checks and no credential-bearing redirect following');
check(in_array('Cache-Control: no-store, private', $r['responseHeaders'], true), 'auth responses cannot be cached');
foreach ([['method' => 'TRACE', 'status' => 405], ['body' => str_repeat('x', 1048577), 'status' => 413], ['uri' => "/__clerk/v1/client\r\ninvalid", 'status' => 400], ['options' => ['clerk_wp_sync_api_key' => ''], 'status' => 503], ['options' => ['clerk_wp_sync_publishable_key' => 'pk_live_fixture'], 'status' => 503]] as $case) {
    check(fixture($case)['status'] === $case['status'], 'reject invalid request or configuration');
}
$r = fixture(['options' => ['clerk_wp_sync_api_key' => 'sk_live_fixture', 'clerk_wp_sync_publishable_key' => 'pk_live_fixture']]);
check($r['status'] === 200, 'production key mode supported');
echo "$checks transport checks passed. No Clerk network requests made.\n";
