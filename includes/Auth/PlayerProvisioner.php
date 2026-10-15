<?php
/**
 * Auto-create a LeagueFlow player profile for self sign-up students.
 *
 * @package ClerkWPSync
 */

namespace ClerkWPSync\Auth;

use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hooks LeagueFlow's portal to provision a player on first login, gated to UNBC
 * email domains so only legitimate students get a profile.
 */
class PlayerProvisioner {
    public function __construct() {
        add_filter('leagueflow_autocreate_player_id', [$this, 'maybe_create_player'], 10, 3);
    }

    /**
     * Create an lf_player for the user when their email is an allowed domain.
     *
     * @param int     $player_id Incoming player id (0 if none).
     * @param WP_User $user      Logged-in user.
     * @param string  $email     User email.
     * @return int Player post id, or 0 to deny.
     */
    public function maybe_create_player($player_id, $user, $email) {
        if ((int) $player_id > 0) {
            return (int) $player_id;
        }

        if (!$user instanceof WP_User) {
            return 0;
        }

        if (!post_type_exists('lf_player')) {
            return 0;
        }

        if (function_exists('\\LeagueFlow\\is_player_registration_enabled') && !\LeagueFlow\is_player_registration_enabled()) {
            return 0;
        }

        $email = strtolower(sanitize_email($email ?: $user->user_email));
        if ('' === $email || !is_email($email)) {
            return 0;
        }

        // When the admin restricts sign-up to UNBC, deny other domains.
        if ($this->restrict_to_unbc() && !$this->is_allowed_email($email)) {
            return 0;
        }

        $title = trim($user->first_name . ' ' . $user->last_name);
        if ('' === $title) {
            $title = trim((string) $user->display_name);
        }
        if ('' === $title) {
            $title = $user->user_login;
        }

        $new_id = wp_insert_post(
            [
                'post_type'   => 'lf_player',
                'post_status' => 'publish',
                'post_title'  => sanitize_text_field($title),
            ],
            true
        );

        if (is_wp_error($new_id) || !$new_id) {
            error_log('Clerk WP Sync could not auto-create player: ' . (is_wp_error($new_id) ? $new_id->get_error_message() : 'unknown error'));
            return 0;
        }

        update_post_meta($new_id, 'lf_email', $email);
        update_post_meta($new_id, 'lf_user_id', (int) $user->ID);

        do_action('clerk_wp_sync_player_created', (int) $new_id, $user->ID, $email);

        return (int) $new_id;
    }

    /**
     * Whether sign-up is restricted to UNBC email domains (admin toggle, default on).
     */
    private function restrict_to_unbc(): bool {
        return '1' === (string) get_option('clerk_wp_sync_restrict_unbc_emails', '1');
    }

    /**
     * Whether an email belongs to an allowed (UNBC) domain.
     */
    private function is_allowed_email(string $email): bool {
        if (function_exists('\\LeagueFlow\\is_registration_email_allowed')) {
            return \LeagueFlow\is_registration_email_allowed($email);
        }

        if ('' === $email || !is_email($email)) {
            return false;
        }

        $at = strrchr($email, '@');
        if (false === $at) {
            return false;
        }
        $domain = strtolower(substr($at, 1));

        /**
         * Filter the email domains allowed to auto-create a player profile.
         * Subdomains of a listed domain are also allowed (e.g. mail.unbc.ca).
         *
         * @param array<string> $domains Allowed base domains.
         */
        $allowed = (array) apply_filters('clerk_wp_sync_allowed_player_domains', ['unbc.ca']);

        foreach ($allowed as $allowed_domain) {
            $allowed_domain = strtolower(ltrim((string) $allowed_domain, '@'));
            if ('' === $allowed_domain) {
                continue;
            }
            if ($domain === $allowed_domain || $this->ends_with($domain, '.' . $allowed_domain)) {
                return true;
            }
        }

        return false;
    }

    private function ends_with(string $haystack, string $needle): bool {
        $len = strlen($needle);
        return 0 !== $len && substr($haystack, -$len) === $needle;
    }
}
