<?php
namespace ClerkWPSync\Security;

use WP_User;

defined('ABSPATH') || exit;

final class AccountPolicy {
    private const SELF_SERVICE_CAPS = ['read', 'level_0', 'leagueflow_manage_profile', 'leagueflow_manage_team'];

    public static function eligible_roles(): array {
        $safe = [];
        foreach (['subscriber', 'leagueflow_player', 'leagueflow_team_manager'] as $name) {
            $role = get_role($name);
            if ($role && !array_diff(array_keys(array_filter($role->capabilities)), self::SELF_SERVICE_CAPS)) {
                $safe[$name] = wp_roles()->roles[$name]['name'];
            }
        }
        return $safe;
    }

    public static function registration_role($requested): string {
        $roles = self::eligible_roles();
        $requested = is_string($requested) ? sanitize_key($requested) : '';
        if (isset($roles[$requested])) {
            return $requested;
        }
        return isset($roles['subscriber']) ? 'subscriber' : (string) (array_key_first($roles) ?? '');
    }

    public static function is_privileged(WP_User $user): bool {
        if (is_multisite() && is_super_admin($user->ID)) {
            return true;
        }

        // Include direct grants and registered capabilities supplied by filters.
        $roles = wp_roles()->roles;
        $registered_caps = [];
        foreach ($roles as $role) {
            $registered_caps += $role['capabilities'];
        }
        foreach (array_keys($user->allcaps + $registered_caps) as $cap) {
            if (in_array($cap, self::SELF_SERVICE_CAPS, true)) {
                continue;
            }
            // WordPress also stores role membership in allcaps. A role name
            // that is itself a registered capability still needs evaluation.
            if (isset($roles[$cap]) && !array_key_exists($cap, $registered_caps)) {
                continue;
            }
            if (user_can($user, $cap)) {
                return true;
            }
        }
        return false;
    }
}
