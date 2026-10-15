<?php
namespace UNBC\ClerkProxy {
    // Existing MU implementation must retain ownership during plugin bootstrap.
    function use_proxy(): bool { return false; }
}
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['fixture_root'] = sys_get_temp_dir() . '/clerk-bootstrap-' . bin2hex(random_bytes(6)) . '/';
    mkdir($GLOBALS['fixture_root'] . 'vendor', 0700, true);
    file_put_contents($GLOBALS['fixture_root'] . 'vendor/autoload.php', '<?php // fixture, no packages executed');
    $GLOBALS['options'] = [
        'clerk_wp_sync_default_role' => 'leagueflow_team_manager',
        'clerk_wp_sync_deletion_behavior' => 'delete',
        'clerk_wp_sync_login_url' => 'https://saved.example/login/',
        'clerk_wp_sync_api_key' => 'fixture_current_secret',
        'clerk_wp_sync_restrict_unbc_emails' => '0',
        'unbc_clerk_secret_key' => 'fixture_old_secret',
    ];
    function home_url($path = '') { return 'https://new-local.example/subdirectory' . $path; }
    function plugin_dir_path($file) { return $GLOBALS['fixture_root']; }
    function plugin_dir_url($file) { return home_url('/wp-content/plugins/clerk-wp-sync/'); }
    function plugin_basename($file) { return 'clerk-wp-sync/clerk-wp-sync.php'; }
    function add_action(...$args) {}
    function register_activation_hook(...$args) {}
    function register_deactivation_hook(...$args) {}
    function flush_rewrite_rules() {}
    function get_option($name, $fallback = false) { return $GLOBALS['options'][$name] ?? $fallback; }
    function update_option($name, $value) { $GLOBALS['options'][$name] = $value; }
    function add_option($name, $value) { if (!array_key_exists($name, $GLOBALS['options'])) { update_option($name, $value); } }
    $checks = 0;
    function check($condition, $label) { global $checks; if (!$condition) { throw new \RuntimeException($label); } $checks++; }
    try {
        require dirname(__DIR__) . '/clerk-wp-sync.php';
        check(CLERK_WP_SYNC_DEFAULT_LOGIN_URL === home_url('/portal/'), 'default follows current site and subdirectory');
        $existing = $GLOBALS['options'];
        Clerk_WP_Sync_Plugin::activate();
        foreach ($existing as $name => $value) { check($GLOBALS['options'][$name] === $value, 'reactivation preserves ' . $name); }
        $GLOBALS['options'] = [];
        Clerk_WP_Sync_Plugin::activate();
        check(get_option('clerk_wp_sync_login_url') === home_url('/portal/'), 'fresh install has local portal default');
        check(get_option('clerk_wp_sync_default_role') === 'subscriber', 'fresh install uses safe role');
        check(get_option('clerk_wp_sync_deletion_behavior') === 'unlink', 'fresh install is non-destructive');
        check(get_option('clerk_wp_sync_restrict_unbc_emails') === '1', 'fresh install retains UNBC policy default');
        $GLOBALS['options'] = ['unbc_clerk_secret_key' => 'fixture_legacy_secret'];
        (new ReflectionMethod(Clerk_WP_Sync_Plugin::class, 'migrate_legacy_option_values'))->invoke(null);
        check(get_option('clerk_wp_sync_api_key') === 'fixture_legacy_secret', 'legacy configuration remains migratable');
        echo "$checks bootstrap and configuration checks passed.\n";
    } finally {
        unlink($GLOBALS['fixture_root'] . 'vendor/autoload.php');
        rmdir($GLOBALS['fixture_root'] . 'vendor'); rmdir($GLOBALS['fixture_root']);
    }
}
