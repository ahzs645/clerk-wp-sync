# Maintaining the fork

`origin` is the maintained `ahzs645/clerk-wp-sync` fork. `upstream` is the
original Systemsaholic repository. Feature branches use the `codex/` prefix.
Keep existing author credits and review upstream changes before integrating them.

Keep the `clerk-wp-sync` plugin folder, option names, webhook routes and Clerk-ID
metadata stable. The current registration UI and player provisioning contain
UNBC/LeagueFlow policy; do not describe them as generic integrations yet.

PHP 8.2 is the supported minimum. `composer.lock` is maintained for repeatable
dependency selection. Composer 2.10.3 is pinned in CI. Update dependencies
explicitly with their compatibility checks rather than resolving new versions
while packaging an existing installation.

Before publishing a source change:

```sh
composer validate --strict
composer install --no-dev --prefer-dist --optimize-autoloader
composer test
node --check assets/js/clerk-auth.js
node tests/frontend.cjs
php bin/build-release.php --skip-composer
php tests/package.php
```

Also lint all maintained PHP files. CI performs these checks on PHP 8.2 and 8.4,
plus frontend checks on Node 24. The workflow has read-only repository access,
uses pinned action commits and requires no Clerk secrets or site access.
Its ZIP artifacts are development candidates, not published GitHub releases.

The build script packages an explicit application/runtime allowlist and refuses
symlinks. Tests, build tools, DDEV deployment files, docs, credentials and site
exports do not belong in a distributable. Before a stable release, qualify the
complete authentication flow on an authorized disposable WordPress instance.
Do not label fixture success as a successful real Clerk login.

Changing code in this GitHub repository does not deploy it to WordPress.
`Update URI` prevents an unrelated WordPress.org replacement but does not provide
automatic GitHub updates. Build a reviewed ZIP and use the site's normal plugin
update workflow. Do not publish a stable release or update production as a side
effect of maintaining source.
