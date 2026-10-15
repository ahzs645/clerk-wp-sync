<?php
/**
 * Bridges a verified Clerk session into a WordPress login session.
 *
 * @package ClerkWPSync
 */

namespace ClerkWPSync\Auth;

use Clerk\Backend\Helpers\Jwks\VerifyToken;
use Clerk\Backend\Helpers\Jwks\VerifyTokenOptions;
use ClerkWPSync\Api\Client as ClerkApi;
use ClerkWPSync\User\Sync as UserSync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoint that exchanges a Clerk session token for a WP auth cookie.
 */
class SessionBridge {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('clerk-wp-sync/v1', '/session-login', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_login'],
            // Public route: CSRF is enforced via the wp_rest nonce inside the
            // callback, and authenticity via the signed Clerk session token.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_login(WP_REST_Request $request) {
        if (!wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('invalid_nonce', __('Invalid request.', 'clerk-wp-sync'), ['status' => 403]);
        }

        $secret = trim((string) get_option('clerk_wp_sync_api_key', ''));
        if ('' === $secret) {
            return new WP_Error('not_configured', __('Clerk secret key is not configured.', 'clerk-wp-sync'), ['status' => 500]);
        }

        $token_value = $request->get_param('token');
        $token = is_string($token_value) ? trim($token_value) : '';
        if ('' === $token) {
            return new WP_Error('missing_token', __('Missing session token.', 'clerk-wp-sync'), ['status' => 400]);
        }

        $origins = $this->allowed_origins();
        $origin = (string) $request->get_header('Origin');
        if ('' !== $origin && !in_array($origin, $origins, true)) {
            return new WP_Error('invalid_origin', __('Invalid sign-in origin.', 'clerk-wp-sync'), ['status' => 403]);
        }

        // This bridge accepts browser session JWTs only, never machine tokens.
        if (strlen($token) > 16384 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) {
            return new WP_Error('invalid_token', __('Invalid session token.', 'clerk-wp-sync'), ['status' => 401]);
        }
        $issuer = $this->configured_issuer();
        if ('' === $issuer || !$origins) {
            return new WP_Error('not_configured', __('Clerk sign-in configuration is incomplete.', 'clerk-wp-sync'), ['status' => 500]);
        }

        try {
            $claims = VerifyToken::verifyToken($token, new VerifyTokenOptions(secretKey: $secret, authorizedParties: $origins));
        } catch (\Throwable $e) {
            error_log('Clerk WP Sync session verification failed.');
            return new WP_Error('invalid_token', __('Could not verify your Clerk session.', 'clerk-wp-sync'), ['status' => 401]);
        }

        $now = time();
        if (
            !is_object($claims) || ($claims->iss ?? null) !== $issuer ||
            !is_string($claims->sub ?? null) || !preg_match('/^user_[A-Za-z0-9_]+$/D', $claims->sub) ||
            !is_string($claims->sid ?? null) || !preg_match('/^sess_[A-Za-z0-9_]+$/D', $claims->sid) ||
            !is_int($claims->exp ?? null) || !is_int($claims->nbf ?? null) || !is_int($claims->iat ?? null) ||
            $claims->exp <= $now - 5 || $claims->nbf > $now + 5 || $claims->iat > $now + 5 ||
            $claims->exp <= $claims->iat || $claims->exp <= $claims->nbf ||
            (property_exists($claims, 'azp') && (!is_string($claims->azp) || !in_array($claims->azp, $origins, true))) ||
            (property_exists($claims, 'sts') && 'active' !== $claims->sts)
        ) {
            return new WP_Error('invalid_session', __('A valid active Clerk session is required.', 'clerk-wp-sync'), ['status' => 401]);
        }

        $clerk_id = $claims->sub;
        if ('' === $clerk_id) {
            return new WP_Error('invalid_token', __('Session token is missing a subject.', 'clerk-wp-sync'), ['status' => 401]);
        }

        $sync = new UserSync();
        $user = $sync->find_linked_user($clerk_id);

        // First-time login (or webhook has not synced yet): create the WP user
        // on demand from the Clerk profile so login works without the webhook.
        if (!$user instanceof WP_User) {
            $clerk_user = (new ClerkApi())->get_user($clerk_id);
            if (!is_array($clerk_user)) {
                return new WP_Error('profile_unavailable', __('Could not load your Clerk profile.', 'clerk-wp-sync'), ['status' => 502]);
            }

            $result = $sync->ensure_wp_user($clerk_user);
            if (is_wp_error($result)) {
                return $result;
            }

            $user = get_user_by('id', (int) ($result['user_id'] ?? 0));
        }

        if (!$user instanceof WP_User) {
            return new WP_Error('no_user', __('Could not establish your account.', 'clerk-wp-sync'), ['status' => 500]);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        do_action('clerk_wp_sync_session_login', $user->ID, $clerk_id);

        return new WP_REST_Response([
            'ok' => true,
            'redirect' => $this->portal_url(),
        ], 200);
    }

    /** Configured site origins, including non-default local-development ports. */
    private function allowed_origins(): array {
        $origins = [];
        foreach ([home_url(), site_url()] as $url) {
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                continue;
            }
            $origin = strtolower($parts['scheme'] . '://' . $parts['host']);
            if (isset($parts['port']) && !(('https' === $parts['scheme'] && 443 === $parts['port']) || ('http' === $parts['scheme'] && 80 === $parts['port']))) {
                $origin .= ':' . $parts['port'];
            }
            $origins[] = $origin;
        }
        return array_values(array_unique($origins));
    }

    /** Resolve the trusted issuer from the publishable key and server-side proxy configuration. */
    private function configured_issuer(): string {
        $key = trim((string) get_option('clerk_wp_sync_publishable_key', ''));
        if (!preg_match('/^pk_(?:test|live)_(.+)$/D', $key, $match)) {
            return '';
        }
        $decoded = base64_decode($match[1], true);
        if (!is_string($decoded) || !str_ends_with($decoded, '$')) {
            return '';
        }
        $host = substr($decoded, 0, -1);
        if (!preg_match('/^[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/iD', $host)) {
            return '';
        }

        // With the configured Frontend API proxy, Clerk uses its URL as iss.
        // Resolve this from trusted server configuration, never token claims.
        if (function_exists('UNBC\\ClerkProxy\\use_proxy') && \UNBC\ClerkProxy\use_proxy()) {
            return \UNBC\ClerkProxy\unbc_clerk_proxy_url();
        }

        return 'https://' . strtolower($host);
    }

    /**
     * Resolve the LeagueFlow portal URL to land on after login.
     */
    private function portal_url(): string {
        $page_id = absint(get_option('leagueflow_portal_page_id'));
        $url = $page_id ? get_permalink($page_id) : '';

        return (string) ($url ?: home_url('/portal/'));
    }
}
