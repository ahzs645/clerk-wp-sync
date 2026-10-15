<?php

namespace ClerkWPSync\Api;

use Clerk\Backend\ClerkBackend;
use Clerk\Backend\Models\Operations\UpdateUserMetadataRequestBody;
use Throwable;

class Client {
    private $api_key;

    public function __construct() {
        $this->api_key = trim((string) get_option('clerk_wp_sync_api_key', ''));
    }

    /**
     * Fetch a Clerk user as an associative array (snake_case, webhook shape).
     *
     * Uses the Backend REST API directly so the payload matches what the
     * webhook sync path expects, rather than SDK model objects.
     */
    public function get_user(string $clerk_id): ?array {
        if (empty($this->api_key) || '' === $clerk_id) {
            return null;
        }

        $response = wp_remote_get(
            'https://api.clerk.com/v1/users/' . rawurlencode($clerk_id),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
                'redirection' => 0,
            ]
        );

        if (is_wp_error($response)) {
            error_log('Clerk WP Sync user fetch failed: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('Clerk WP Sync user fetch returned HTTP ' . $code);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    public function update_user_metadata(string $clerk_id, array $metadata): bool {
        // A local copy sharing a Clerk instance must not publish its user IDs.
        if (!apply_filters('clerk_wp_sync_metadata_writeback_enabled', true)) {
            return false;
        }

        if (empty($this->api_key)) {
            return false;
        }

        try {
            $sdk = ClerkBackend::builder()
                ->setSecurity($this->api_key)
                ->build();

            $sdk->users->updateMetadata(
                $clerk_id,
                new UpdateUserMetadataRequestBody(
                    privateMetadata: $metadata
                )
            );

            return true;
        } catch (Throwable $throwable) {
            error_log('Clerk WP Sync metadata write-back failed: ' . $throwable->getMessage());
            return false;
        }
    }
}
