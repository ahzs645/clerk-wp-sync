<?php
/**
 * Plugin Name: Clerk local development isolation
 * Description: Local-only profile reads while preserving the Zoer outbound guard.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!is_file(WPMU_PLUGIN_DIR . '/zoer-local-copy.php')) {
    return;
}

// Never publish a local WordPress user ID back into the shared Clerk identity.
add_filter('clerk_wp_sync_metadata_writeback_enabled', '__return_false');

// The Zoer copy blocks all WP HTTP requests. First-time Clerk sign-in needs
// exactly one read-only profile lookup; keep the existing guard for every
// other destination, method, redirect and path.
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!is_wp_error($pre) || 'local_copy' !== $pre->get_error_code()) {
        return $pre;
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || 'https' !== ($parts['scheme'] ?? '')
        || 'api.clerk.com' !== ($parts['host'] ?? '')
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('~^/v1/users/user_[A-Za-z0-9_]+$~D', $parts['path'] ?? '')
        || 'GET' !== strtoupper($args['method'] ?? 'GET')
        || 0 !== (int) ($args['redirection'] ?? 5)) {
        return $pre;
    }
    return false;
}, 99, 3);
