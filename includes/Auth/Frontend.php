<?php
/**
 * Front-end Clerk sign-in experience for the LeagueFlow portal.
 *
 * @package ClerkWPSync
 */

namespace ClerkWPSync\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Embeds the Clerk sign-in widget on the portal and loads the bridge script.
 */
class Frontend {
    public function __construct() {
        add_filter('leagueflow_portal_logged_out_html', [$this, 'render_signin'], 10, 2);
        add_action('template_redirect', [$this, 'redirect_clerk_callback_to_portal'], 1);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue']);
        add_filter('script_loader_tag', [$this, 'tag_clerk_script'], 10, 3);
        // Ensure WP logout also clears the Clerk session (see clerk-auth.js).
        add_filter('logout_redirect', [$this, 'flag_logout'], 10, 3);
    }

    /**
     * Whether the Clerk keys needed for sign-in are present.
     */
    private function is_configured(): bool {
        return '' !== trim((string) get_option('clerk_wp_sync_publishable_key', ''))
            && '' !== trim((string) get_option('clerk_wp_sync_api_key', ''));
    }

    /**
     * Whether the current request is the LeagueFlow portal page.
     */
    private function is_portal_page(): bool {
        $page_id = absint(get_option('leagueflow_portal_page_id'));

        return $page_id > 0 && is_page($page_id);
    }

    /**
     * Whether this page load is the post-WordPress logout callback.
     */
    private function is_logout_callback(): bool {
        return isset($_GET['clerk_signout'])
            && '1' === sanitize_text_field(wp_unslash((string) $_GET['clerk_signout']));
    }

    /**
     * Whether Clerk has returned to WordPress with an auth callback parameter.
     */
    private function is_clerk_callback(): bool {
        foreach (array_keys($_GET) as $key) {
            if (0 === strpos((string) $key, '__clerk_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace the portal's logged-out panel with an embedded Clerk widget.
     *
     * @param string $default_html Default LeagueFlow markup.
     * @param string $portal_url   Portal URL.
     * @return string
     */
    public function render_signin($default_html, $portal_url = '') {
        if (!$this->is_configured()) {
            return $default_html;
        }

        $captain_open = function_exists('\\LeagueFlow\\is_captain_registration_enabled') && \LeagueFlow\is_captain_registration_enabled();
        $player_open = !function_exists('\\LeagueFlow\\is_player_registration_enabled') || \LeagueFlow\is_player_registration_enabled();
        $title = __('Player Login', 'clerk-wp-sync');
        $description = __('Sign in or create your account to manage your profile, team, and upcoming games.', 'clerk-wp-sync');

        if ($captain_open && !$player_open) {
            $title = __('Captain Login', 'clerk-wp-sync');
            $description = __('Sign in or create your account to register your team.', 'clerk-wp-sync');
        } elseif ($captain_open && $player_open) {
            $title = __('League Login', 'clerk-wp-sync');
            $description = __('Sign in or create your account to register a team or manage your player profile.', 'clerk-wp-sync');
        }

        ob_start();
        ?>
        <div class="leagueflow leagueflow-portal leagueflow-portal--auth alignfull">
            <section class="leagueflow-portal__panel leagueflow-portal__panel--signin">
                <div class="leagueflow-portal-signin__copy">
                    <p class="leagueflow-portal__eyebrow"><?php esc_html_e('UNBC Intramurals', 'clerk-wp-sync'); ?></p>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php echo esc_html($description); ?></p>
                </div>
                <div class="leagueflow-portal-signin__widget">
                    <div id="clerk-signin-error" class="leagueflow-portal__notice leagueflow-portal__notice--error" role="alert" hidden></div>
                    <div id="clerk-signin" class="clerk-signin" aria-live="polite"></div>
                    <noscript><?php esc_html_e('JavaScript is required to sign in.', 'clerk-wp-sync'); ?></noscript>
                </div>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Send Clerk auth callbacks to the portal before rendering a public page.
     */
    public function redirect_clerk_callback_to_portal(): void {
        if (!$this->is_configured() || !$this->is_clerk_callback() || $this->is_portal_page()) {
            return;
        }

        $callback_args = [];
        foreach ($_GET as $key => $value) {
            $key = (string) $key;
            if (0 !== strpos($key, '__clerk_')) {
                continue;
            }

            $callback_args[sanitize_key($key)] = sanitize_text_field(wp_unslash((string) $value));
        }

        wp_safe_redirect(add_query_arg($callback_args, $this->portal_url()));
        exit;
    }

    /**
     * Load Clerk JS + the bridge script anywhere Clerk returns after auth.
     */
    public function maybe_enqueue(): void {
        if (!$this->is_configured() || (!$this->is_portal_page() && !$this->is_logout_callback() && !$this->is_clerk_callback())) {
            return;
        }

        $publishable_key = trim((string) get_option('clerk_wp_sync_publishable_key', ''));
        $frontend_api = $this->frontend_api_domain($publishable_key);
        if ('' === $frontend_api) {
            return;
        }

        wp_enqueue_script(
            'clerk-js',
            'https://' . $frontend_api . '/npm/@clerk/clerk-js@5/dist/clerk.browser.js',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'clerk-wp-auth',
            CLERK_WP_SYNC_PLUGIN_URL . 'assets/js/clerk-auth.js',
            ['clerk-js'],
            (string) filemtime(CLERK_WP_SYNC_PLUGIN_DIR . 'assets/js/clerk-auth.js'),
            true
        );

        wp_localize_script('clerk-wp-auth', 'ClerkWPAuth', [
            'restUrl'        => esc_url_raw(rest_url('clerk-wp-sync/v1/session-login')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'portalUrl'      => $this->portal_url(),
            'proxyUrl'       => function_exists('UNBC\\ClerkProxy\\use_proxy') && \UNBC\ClerkProxy\use_proxy()
                ? \UNBC\ClerkProxy\unbc_clerk_proxy_url() : '',
            'isLoggedIn'     => is_user_logged_in(),
            'isAuthCallback' => $this->is_clerk_callback(),
            'isPortalPage'   => $this->is_portal_page(),
            'genericError'   => __('Sign-in could not be completed. Please try again.', 'clerk-wp-sync'),
        ]);
    }

    /**
     * Add the Clerk-required data attributes to the clerk-js script tag.
     *
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     * @param string $src    Script source URL.
     * @return string
     */
    public function tag_clerk_script($tag, $handle, $src) {
        if ('clerk-js' !== $handle) {
            return $tag;
        }

        $publishable_key = trim((string) get_option('clerk_wp_sync_publishable_key', ''));

        return sprintf(
            '<script async crossorigin="anonymous" data-clerk-publishable-key="%1$s" src="%2$s" id="clerk-js-js"></script>' . "\n",
            esc_attr($publishable_key),
            esc_url($src)
        );
    }

    /**
     * Append a flag so the front-end signs out of Clerk after WP logout.
     *
     * @param string $redirect_to Redirect destination.
     * @return string
     */
    public function flag_logout($redirect_to, $requested_redirect_to = '', $user = null) {
        if ('' === (string) $redirect_to) {
            return $redirect_to;
        }

        return add_query_arg('clerk_signout', '1', $redirect_to);
    }

    /**
     * Derive the Clerk Frontend API host from a publishable key.
     *
     * pk_test_<base64("frontend-api$")> => "frontend-api".
     */
    private function frontend_api_domain(string $publishable_key): string {
        $parts = explode('_', $publishable_key, 3);
        $encoded = $parts[2] ?? '';
        if ('' === $encoded) {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if (false === $decoded) {
            return '';
        }

        $domain = rtrim($decoded, '$');

        return preg_match('/^[a-z0-9.\-]+$/i', $domain) ? $domain : '';
    }

    /**
     * Resolve the portal URL.
     */
    private function portal_url(): string {
        $page_id = absint(get_option('leagueflow_portal_page_id'));
        $url = $page_id ? get_permalink($page_id) : '';

        return (string) ($url ?: home_url('/portal/'));
    }
}
