<?php
/**
 * Plugin Name: Clerk WP Sync
 * Plugin URI: https://github.com/ahzs645/clerk-wp-sync
 * Update URI: https://github.com/ahzs645/clerk-wp-sync
 * Description: Synchronizes users between Clerk.com and WordPress using Clerk's official PHP SDK.
 * Version: 1.1.2-unbc-dev.20261015.1
 * Requires at least: 5.8
 * Requires PHP: 8.2
 * Author: Al Guertin
 * Author URI: https://systemsaholic.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clerk-wp-sync
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLERK_WP_SYNC_VERSION', '1.1.2-unbc-dev.20261015.1');
define('CLERK_WP_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLERK_WP_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CLERK_WP_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CLERK_WP_SYNC_DEFAULT_LOGIN_URL', home_url('/portal/'));

// Check for Composer autoloader
if (!file_exists(CLERK_WP_SYNC_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php _e('Clerk WP Sync: Missing dependencies. Please run <code>composer install</code> in the plugin directory or download the full release version.', 'clerk-wp-sync'); ?>
            </p>
        </div>
        <?php
    });
    return;
}

require_once CLERK_WP_SYNC_PLUGIN_DIR . 'vendor/autoload.php';

// Existing production MU proxies load first; keep their transport unchanged.
if (!function_exists('UNBC\\ClerkProxy\\use_proxy')) {
    require_once CLERK_WP_SYNC_PLUGIN_DIR . 'includes/Auth/frontend-proxy.php';
}

final class Clerk_WP_Sync_Plugin {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'migrate_legacy_options'));
        add_action('init', array($this, 'init_components'));
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'clerk-wp-sync',
            false,
            dirname(CLERK_WP_SYNC_PLUGIN_BASENAME) . '/languages'
        );
    }

    public function init_components() {
        new \ClerkWPSync\Webhook\Handler();
        new \ClerkWPSync\Auth\SessionBridge();
        new \ClerkWPSync\Auth\PlayerProvisioner();
        new \ClerkWPSync\Auth\BackendGuard();
        if (is_admin()) {
            new \ClerkWPSync\Admin\Admin();
        } else {
            new \ClerkWPSync\Auth\Frontend();
        }
    }

    public static function activate() {
        self::migrate_legacy_option_values();

        add_option('clerk_wp_sync_webhook_secret', '');
        add_option('clerk_wp_sync_api_key', '');
        add_option('clerk_wp_sync_publishable_key', '');
        add_option('clerk_wp_sync_restrict_unbc_emails', '1');
        add_option('clerk_wp_sync_login_url', CLERK_WP_SYNC_DEFAULT_LOGIN_URL);
        add_option('clerk_wp_sync_default_role', 'subscriber');
        add_option('clerk_wp_sync_deletion_behavior', 'unlink');
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function migrate_legacy_options() {
        self::migrate_legacy_option_values();
    }

    private static function migrate_legacy_option_values() {
        $option_map = [
            'unbc_clerk_webhook_secret' => 'clerk_wp_sync_webhook_secret',
            'unbc_clerk_secret_key' => 'clerk_wp_sync_api_key',
            'unbc_clerk_default_role' => 'clerk_wp_sync_default_role',
            'unbc_clerk_deletion_behavior' => 'clerk_wp_sync_deletion_behavior',
            'unbc_clerk_login_url' => 'clerk_wp_sync_login_url',
        ];

        foreach ($option_map as $old_option => $new_option) {
            $old_value = get_option($old_option, null);
            $new_value = get_option($new_option, null);

            if (null !== $old_value && (null === $new_value || '' === $new_value)) {
                update_option($new_option, $old_value);
            }
        }

        if (!get_option('clerk_wp_sync_unbc_registration_policy_migrated')) {
            add_option('clerk_wp_sync_restrict_unbc_emails', '1');
            add_option('clerk_wp_sync_unbc_registration_policy_migrated', '1');
        }
    }
}

register_activation_hook(__FILE__, array('Clerk_WP_Sync_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Clerk_WP_Sync_Plugin', 'deactivate'));

function clerk_wp_sync() {
    return Clerk_WP_Sync_Plugin::instance();
}

clerk_wp_sync();
