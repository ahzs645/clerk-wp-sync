<?php
/**
 * User sync implementation for Clerk webhook payloads.
 *
 * @package ClerkWPSync
 */

namespace ClerkWPSync\User;

use ClerkWPSync\Api\Client as ClerkApi;
use ClerkWPSync\Security\AccountPolicy;
use WP_Error;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

class Sync {
    private ClerkApi $clerk_api;

    public function __construct() {
        $this->clerk_api = new ClerkApi();
    }

    public function handle_user_created(array $webhook_data): array|WP_Error {
        return $this->upsert_user($webhook_data, 'created');
    }

    public function handle_user_updated(array $webhook_data): array|WP_Error {
        return $this->upsert_user($webhook_data, 'updated');
    }

    /**
     * Find the WordPress user linked to a Clerk user id, if any.
     */
    public function find_linked_user(string $clerk_id): ?WP_User {
        return $this->find_user_by_clerk_id(sanitize_text_field($clerk_id));
    }

    /**
     * Create or update the WordPress user for a Clerk user object.
     *
     * Accepts a Clerk user payload (same shape Clerk delivers via webhooks and
     * the Backend API) and reuses the webhook upsert path. Used by the session
     * bridge to provision a user on first login without waiting for a webhook.
     */
    public function ensure_wp_user(array $clerk_user): array|WP_Error {
        return $this->upsert_user(['data' => $clerk_user], 'login');
    }

    public function handle_user_deleted(array $webhook_data): array|WP_Error {
        $data = $this->payload_data($webhook_data);
        if (is_wp_error($data)) {
            return $data;
        }

        $clerk_id = sanitize_text_field($data['id'] ?? '');
        if ('' === $clerk_id) {
            return new WP_Error('invalid_data', __('Missing Clerk ID', 'clerk-wp-sync'), ['status' => 400]);
        }

        $user = $this->find_user_by_clerk_id($clerk_id);
        if (!$user) {
            return new WP_Error('not_found', __('User not found', 'clerk-wp-sync'), ['status' => 404]);
        }

        $user_id = (int) $user->ID;
        $action = $this->deletion_behavior();

        if ('delete' === $action) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $reassign_id = absint(get_option('clerk_wp_sync_reassign_user', 0));
            $deleted = wp_delete_user($user_id, $reassign_id ?: null);
            if (!$deleted) {
                return new WP_Error('deletion_failed', __('Failed to delete user', 'clerk-wp-sync'), ['status' => 500]);
            }
        } else {
            delete_user_meta($user_id, 'clerk_id');
        }

        // Existing WP cookies must not outlive a processed identity deletion.
        \WP_Session_Tokens::get_instance($user_id)->destroy_all();

        do_action('clerk_wp_sync_user_deleted', $user_id, $data, $action);

        return [
            'message' => __('User processed successfully', 'clerk-wp-sync'),
            'user_id' => $user_id,
            'action' => $action,
        ];
    }

    private function upsert_user(array $webhook_data, string $event_action): array|WP_Error {
        $data = $this->payload_data($webhook_data);
        if (is_wp_error($data)) {
            return $data;
        }

        $clerk_id = sanitize_text_field($data['id'] ?? '');
        $email = $this->primary_email($data);

        if ('' === $clerk_id || '' === $email) {
            return new WP_Error('invalid_data', __('Missing Clerk ID or email address', 'clerk-wp-sync'), ['status' => 400]);
        }

        $user = $this->find_user_by_clerk_id($clerk_id);
        if (!$user) {
            // An email match is not proof of ownership until Clerk verifies it.
            if (!$this->primary_email_is_verified($data)) {
                return new WP_Error('unverified_email', __('Verify your primary email address before signing in.', 'clerk-wp-sync'), ['status' => 403]);
            }

            $user = get_user_by('email', $email);
            if ($user instanceof WP_User && AccountPolicy::is_privileged($user)) {
                return new WP_Error('privileged_account_link', __('This staff account must be linked by an administrator before using this sign-in method.', 'clerk-wp-sync'), ['status' => 403]);
            }
            $linked_id = $user instanceof WP_User ? (string) get_user_meta($user->ID, 'clerk_id', true) : '';
            if ('' !== $linked_id && $linked_id !== $clerk_id) {
                return new WP_Error('identity_conflict', __('This account is already linked to another sign-in identity.', 'clerk-wp-sync'), ['status' => 409]);
            }
        } elseif (strtolower($email) !== strtolower($user->user_email) && !$this->primary_email_is_verified($data)) {
            return new WP_Error('unverified_email', __('Verify your new primary email address before updating your account.', 'clerk-wp-sync'), ['status' => 403]);
        }

        if (!$user instanceof WP_User && !$this->registration_email_allowed($email, $data)) {
            return new WP_Error(
                'registration_email_denied',
                __('New intramurals registrations require a verified UNBC email address.', 'clerk-wp-sync'),
                ['status' => 403]
            );
        }

        if ($user instanceof WP_User) {
            $user_id = (int) $user->ID;
            $result = $this->update_wp_user($user_id, $data, $email);
            $action = 'updated';
        } else {
            $result = $this->create_wp_user($data, $email);
            if (is_wp_error($result)) {
                return $result;
            }
            $user_id = (int) $result;
            $action = 'created';
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $this->update_clerk_metadata($user_id, $data);
        $this->clerk_api->update_user_metadata($clerk_id, ['wp_id' => $user_id]);

        do_action('clerk_wp_sync_user_upserted', $user_id, $data, $action, $event_action);

        return [
            'message' => sprintf('User %s successfully', $action),
            'user_id' => $user_id,
            'action' => $action,
        ];
    }

    private function create_wp_user(array $data, string $email): int|WP_Error {
        $role = $this->default_role();
        if ('' === $role) {
            return new WP_Error('unsafe_registration_role', __('No safe registration role is configured.', 'clerk-wp-sync'), ['status' => 403]);
        }

        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        $display_name = trim($first_name . ' ' . $last_name);

        return wp_insert_user([
            'user_login' => $this->unique_user_login($data, $email),
            'user_email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $display_name,
            'user_pass' => wp_generate_password(32, true, true),
            'role' => $role,
        ]);
    }

    private function update_wp_user(int $user_id, array $data, string $email): int|WP_Error {
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        $display_name = trim($first_name . ' ' . $last_name);

        $user_data = [
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];

        if ('' !== $display_name) {
            $user_data['display_name'] = $display_name;
        }

        $current_user = get_userdata($user_id);
        if ($current_user && $email !== $current_user->user_email) {
            $email_owner = email_exists($email);
            if ($email_owner && (int) $email_owner !== $user_id) {
                return new WP_Error('email_exists', __('Email already belongs to another WordPress user', 'clerk-wp-sync'), ['status' => 400]);
            }
            $user_data['user_email'] = $email;
        }

        return wp_update_user($user_data);
    }

    private function update_clerk_metadata(int $user_id, array $data): void {
        $scalar_meta = [
            'clerk_id' => 'id',
            'clerk_created_at' => 'created_at',
            'clerk_updated_at' => 'updated_at',
            'clerk_image_url' => 'image_url',
            'clerk_profile_image_url' => 'profile_image_url',
            'clerk_primary_email_address_id' => 'primary_email_address_id',
            'clerk_last_active_at' => 'last_active_at',
            'clerk_last_sign_in_at' => 'last_sign_in_at',
            'clerk_external_id' => 'external_id',
        ];

        foreach ($scalar_meta as $meta_key => $data_key) {
            update_user_meta($user_id, $meta_key, sanitize_text_field((string) ($data[$data_key] ?? '')));
        }

        foreach (['email_addresses', 'phone_numbers', 'external_accounts', 'public_metadata', 'private_metadata', 'unsafe_metadata'] as $data_key) {
            update_user_meta($user_id, 'clerk_' . $data_key, wp_json_encode($data[$data_key] ?? []));
        }
    }

    private function payload_data(array $webhook_data): array|WP_Error {
        $data = $webhook_data['data'] ?? null;
        if (!is_array($data)) {
            return new WP_Error('invalid_data', __('Missing user data in webhook payload', 'clerk-wp-sync'), ['status' => 400]);
        }

        return $data;
    }

    private function find_user_by_clerk_id(string $clerk_id): ?WP_User {
        if ('' === $clerk_id) {
            return null;
        }

        $users = get_users([
            'meta_key' => 'clerk_id',
            'meta_value' => $clerk_id,
            'number' => 1,
        ]);

        return $users ? $users[0] : null;
    }

    private function primary_email(array $data): string {
        $address = $this->primary_email_record($data);

        return $address ? sanitize_email($address['email_address'] ?? '') : '';
    }

    /**
     * Resolve the primary Clerk email record.
     */
    private function primary_email_record(array $data): ?array {
        $addresses = $data['email_addresses'] ?? [];
        $primary_id = $data['primary_email_address_id'] ?? '';

        if (!is_array($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            if (is_array($address) && $primary_id && ($address['id'] ?? '') === $primary_id) {
                return $address;
            }
        }

        // Do not silently use a different email when the declared primary is missing.
        if ($primary_id) {
            return null;
        }

        $first = reset($addresses);

        return is_array($first) ? $first : null;
    }

    /**
     * Confirm Clerk has verified the primary address before creating an account.
     */
    private function primary_email_is_verified(array $data): bool {
        $address = $this->primary_email_record($data);
        $verification = is_array($address) ? ($address['verification'] ?? null) : null;

        return is_array($verification) && 'verified' === sanitize_key((string) ($verification['status'] ?? ''));
    }

    private function registration_email_allowed(string $email, array $data): bool {
        if ('1' !== (string) get_option('clerk_wp_sync_restrict_unbc_emails', '1')) {
            return true;
        }

        if (function_exists('\\LeagueFlow\\is_registration_email_allowed')) {
            return \LeagueFlow\is_registration_email_allowed($email) && $this->primary_email_is_verified($data);
        }

        $email = strtolower(sanitize_email($email));
        $at = strrchr($email, '@');
        if (false === $at) {
            return false;
        }

        $domain = substr($at, 1);
        $domain_allowed = 'unbc.ca' === $domain || (strlen($domain) > 8 && '.unbc.ca' === substr($domain, -8));

        return $domain_allowed && $this->primary_email_is_verified($data);
    }

    private function unique_user_login(array $data, string $email): string {
        $email_parts = explode('@', $email);
        $base_login = sanitize_user($data['username'] ?? $email_parts[0], true);
        if ('' === $base_login) {
            $base_login = sanitize_user($email_parts[0], true);
        }
        if ('' === $base_login) {
            $base_login = 'clerk-user';
        }

        $login = $base_login;
        $suffix = 2;
        while (username_exists($login)) {
            $login = $base_login . '-' . $suffix;
            $suffix++;
        }

        return $login;
    }

    private function default_role(): string {
        return AccountPolicy::registration_role(get_option('clerk_wp_sync_default_role', 'subscriber'));
    }

    private function deletion_behavior(): string {
        $behavior = get_option('clerk_wp_sync_deletion_behavior', 'unlink');
        return in_array($behavior, ['unlink', 'delete'], true) ? $behavior : 'unlink';
    }
}
