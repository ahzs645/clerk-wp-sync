# Clerk WP Sync — maintained fork

WordPress user synchronization and portal sign-in using Clerk. This is
[ahzs645/clerk-wp-sync](https://github.com/ahzs645/clerk-wp-sync), forked from
[Systemsaholic/clerk-wp-sync](https://github.com/Systemsaholic/clerk-wp-sync)
by Al Guertin. Existing attribution, plugin slug, option names and webhook routes
are retained. See [provenance](docs/provenance.md).

The initial fork contains the existing UNBC/LeagueFlow customizations and the
portable same-origin Clerk proxy. It is a **development candidate**. A successful
complete sign-in has not been qualified for the local Intramurals copy because
its existing production-mode Clerk instance does not register that hostname.

## Current behavior

- Signed Clerk webhooks create, update, unlink or delete linked WordPress users.
- An embedded sign-in widget exchanges a verified Clerk session for a WordPress
  session on the LeagueFlow portal.
- The proxy and new-install portal default derive from WordPress's configured
  URL, including subdirectories. No particular DDEV hostname or Clerk tenant
  is built into those defaults. Existing saved settings remain intact.
- The session bridge verifies JWT signature, issuer, time claims, session type
  and authorized origin. Automatic privileged-account linking is rejected, and
  self-service roles cannot gain administrative capabilities.
- Existing UNBC registration policy and LeagueFlow player provisioning remain
  part of this fork; it is not yet a fully generic registration UI.
- Optional DDEV files isolate metadata write-back and allow only the profile
  read needed by local login through Zoer's existing WordPress HTTP guard.

## Install and configure

Requirements: WordPress 5.8+, PHP 8.2+, PHP cURL and Composer dependencies.
Building a ZIP also needs PHP ZipArchive and Composer 2.10.3.

1. Use a reviewed ZIP built by this fork's CI, or build it from source below.
2. Install/replace the existing `clerk-wp-sync` plugin folder; keep the same slug.
3. Configure the publishable key and matching server-side secret under
   **Settings → Clerk WP Sync**. Configure the webhook signing secret separately.
4. Review the registration policy, safe default role and deletion behavior.
5. When using LeagueFlow, set up its portal page. A new install defaults to the
   current site's `/portal/` URL; a saved login URL is preserved on reactivation.
6. For DDEV, apply the separate [local setup](docs/local-development.md).

Keys belong in each site's server settings, never in this repository or a ZIP.
Creating a fork does not change Clerk's domain/key rules. Use development-mode
keys for disposable local hostnames when you want independent local sign-in.
The fork does not register domains, change Clerk settings or switch keys for you.

The `Update URI` identifies this fork and avoids an unrelated WordPress.org slug
update. It does **not** implement a GitHub updater. Updating through Zoer still
requires importing/applying a reviewed plugin ZIP through its normal workflow.
No published fork release exists yet.

## Build and verify

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer validate --strict
composer test
node tests/frontend.cjs
php bin/build-release.php --skip-composer
php tests/package.php
```

Or run `php bin/build-release.php` with Composer 2.10.3 on PATH. The output is a
versioned ZIP under ignored `release/`. It includes application/runtime files;
DDEV configuration, tests, documentation and build tools remain outside the ZIP.
CI checks PHP 8.2 and 8.4, frontend behavior and package contents without Clerk
credentials, real accounts, a database or live websites.

See [maintenance](docs/maintenance.md), [portable local setup](docs/local-development.md)
and the [dated local investigation](docs/local-clerk-proxy.md) for remaining
qualification work and production publishing boundaries.

License declared by the upstream plugin: GPL v2 or later.
