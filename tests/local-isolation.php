<?php
// Offline tests for the optional DDEV-only guard; no account or HTTP operations.
define('ABSPATH', __DIR__);
$temporary = sys_get_temp_dir() . '/clerk-isolation-' . bin2hex(random_bytes(6));
mkdir($temporary);
define('WPMU_PLUGIN_DIR', $temporary);
file_put_contents($temporary . '/zoer-local-copy.php', '<?php // fixture marker');
$GLOBALS['hooks'] = [];
class WP_Error { public function __construct(private string $code) {} public function get_error_code() { return $this->code; } public function get_error_message() { return 'fixture'; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_parse_url($value) { return parse_url($value); }
function add_filter($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
function __return_false() { return false; }
function apply_filters($name, $value) { foreach ($GLOBALS['hooks'][$name] ?? [] as $fn) { $value = $fn($value); } return $value; }
function get_option($name, $fallback = '') { return $name === 'clerk_wp_sync_api_key' ? 'sk_test_fixture' : $fallback; }
function wp_remote_get($url, $options) { $GLOBALS['profile_request'] = [$url, $options]; return new WP_Error('fixture'); }
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
require dirname(__DIR__) . '/deploy/ddev/mu-plugins/clerk-local-development.php';
require dirname(__DIR__) . '/includes/Api/Client.php';
try {
    check(apply_filters('clerk_wp_sync_metadata_writeback_enabled', true) === false, 'local write-back disabled');
    $client = new ClerkWPSync\Api\Client();
    check($client->update_user_metadata('user_fixture', ['wp_id' => 999]) === false, 'no SDK write invoked');
    $guard = $GLOBALS['hooks']['pre_http_request'][0];
    $blocked = new WP_Error('local_copy');
    $valid = 'https://api.clerk.com/v1/users/user_fixture';
    check($guard($blocked, ['method' => 'GET', 'redirection' => 0], $valid) === false, 'one profile GET allowed');
    foreach ([
        'http://api.clerk.com/v1/users/user_fixture',
        'https://api.clerk.com.evil.example/v1/users/user_fixture',
        'https://user@api.clerk.com/v1/users/user_fixture',
        'https://api.clerk.com:443/v1/users/user_fixture',
        'https://api.clerk.com/v1/users/user_fixture?redirect=elsewhere',
        'https://api.clerk.com/v1/users/user_fixture#fragment',
        'https://api.clerk.com/v1/users',
        'https://api.clerk.com/v1/users/user_fixture/metadata',
        'https://api.clerk.com/v1/domains',
        'https://api.clerk.com/v1/users/user_fixture/../user_other',
        'https://api.clerk.com/v1/users/user_fixture%2fmetadata',
        'https://elsewhere.example/',
    ] as $url) { check($guard($blocked, ['method' => 'GET', 'redirection' => 0], $url) === $blocked, 'unapproved URL blocked'); }
    check($guard($blocked, ['method' => 'POST', 'redirection' => 0], $valid) === $blocked, 'writes remain blocked');
    check($guard($blocked, ['method' => 'GET', 'redirection' => 5], $valid) === $blocked, 'redirect following remains blocked');
    $other = new WP_Error('other_security_control');
    check($guard($other, ['method' => 'GET', 'redirection' => 0], $valid) === $other, 'other security controls preserved');
    $client->get_user('user_fixture');
    check($GLOBALS['profile_request'][1]['redirection'] === 0, 'profile fetch never forwards credentials on redirects');
    echo "$checks local isolation checks passed. No Clerk network requests made.\n";
} finally {
    unlink($temporary . '/zoer-local-copy.php'); rmdir($temporary);
}
