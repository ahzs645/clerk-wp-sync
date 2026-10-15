<?php
/**
 * Keep student accounts out of the WordPress back-end.
 *
 * @package ClerkWPSync
 */

namespace ClerkWPSync\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirects non-staff users away from wp-admin and hides the admin toolbar.
 *
 * "Staff" = anyone who can edit content (edit_posts) or manage the site
 * (manage_options). LeagueFlow players / team managers and plain subscribers
 * have neither, so they are confined to the front-end portal.
 */
class BackendGuard {
    public function __construct() {
        add_action('admin_init', [$this, 'block_admin_access'], 1);
        add_filter('show_admin_bar', [$this, 'filter_admin_bar']);
    }

    /**
     * Whether the current user is allowed in the back-end.
     */
    private function is_staff(): bool {
        return current_user_can('edit_posts') || current_user_can('manage_options');
    }

    /**
     * Redirect students away from wp-admin to the front-end portal.
     */
    public function block_admin_access(): void {
        // Never interfere with AJAX, the REST API, or form post handlers that
        // legitimately live under wp-admin (the portal posts to admin-post.php).
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $script = isset($_SERVER['SCRIPT_NAME'])
            ? basename(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])))
            : '';
        if (in_array($script, ['admin-post.php', 'admin-ajax.php'], true)) {
            return;
        }

        if (!is_user_logged_in() || $this->is_staff()) {
            return;
        }

        wp_safe_redirect($this->front_destination());
        exit;
    }

    /**
     * Hide the admin toolbar for students on the front end.
     *
     * @param bool $show Whether to show the bar.
     * @return bool
     */
    public function filter_admin_bar($show) {
        if (is_user_logged_in() && !$this->is_staff()) {
            return false;
        }

        return $show;
    }

    /**
     * Front-end URL to send students to (the LeagueFlow portal if present).
     */
    private function front_destination(): string {
        $page_id = absint(get_option('leagueflow_portal_page_id'));
        $url = $page_id ? get_permalink($page_id) : '';

        return (string) ($url ?: home_url('/'));
    }
}
