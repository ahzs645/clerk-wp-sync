# Clerk proxy on the Intramurals local copy

The local deployment examined here is `1.1.2-unbc-dev.20261015`. The maintained
fork contains subsequent source-only portability and activation fixes as
`1.1.2-unbc-dev.20261015.1`; those have not been installed on either website.
A successful complete local Clerk login remains blocked by the shared
instance's domain configuration. Repository publication is not a site rollout.

## What is installed locally

- The plugin loads `includes/Auth/frontend-proxy.php` when an existing
  `UNBC\ClerkProxy\use_proxy` implementation is absent. On production the
  existing MU proxy continues to handle its original requests.
- The default proxy URL derives from trusted WordPress `home_url('/__clerk')`.
  The SDK script and `Clerk.load()` receive the same URL. The session bridge's
  trusted issuer uses that function, rather than a hard-coded production origin.
- The proxy forwards to the fixed `https://frontend-api.clerk.dev` upstream,
  verifies TLS, never follows upstream redirects with the secret, bounds request
  bodies, disables caching, strips spoofed proxy headers and WordPress cookies,
  and keeps only Clerk cookies. Keys stay in WordPress settings on the server.
- `.ddev/nginx/clerk-frontend-proxy.conf` routes Clerk asset requests through
  WordPress. Without this, DDEV's static `.js` location returns 404 before PHP.
  The existing full Nginx configuration is unchanged. The snippet passed
  `nginx -t` and was activated with a graceful reload.
- The optional local-only MU file `clerk-local-development.php` preserves
  Zoer's mail/outbound guard. It permits only a no-redirect HTTPS GET of a
  single Clerk user profile, needed during first-time local sign-in. Other
  destinations and HTTP methods remain blocked through the WP HTTP API.
- The local MU file disables Clerk metadata write-back. A local WordPress
  user ID must never overwrite the shared Clerk identity's `wp_id`.
- Failed SDK loading now shows an error instead of an empty login panel.
  Deprecated redirect properties were removed. Duplicate Clerk events share
  one in-flight WordPress login request, and remounts retain one listener.

The MU guard limits this integration's metadata write-back; it is not a general
network sandbox for arbitrary PHP/cURL or other installed plugins.

## The remaining domain restriction

The user explicitly requested retaining the existing key pair for this learning
environment. No key was changed, copied into source, or printed. Clerk classifies
this pair as **production mode** (`pk_live` / `sk_live`) regardless of how the
website is used.

A read-only Clerk domain inventory on October 15 showed exactly one domain:
`intramurals.unbctimberwolves.com`, the primary, with its proxy URL at
`https://intramurals.unbctimberwolves.com/__clerk`.

The local hostname is not registered:
`intramurals-live-oct-9-ff6e185d.wp.k8s.overtheedgepaper.ca`.
The local proxy now serves the SDK with HTTP 200, but Clerk initialization reports:
“We were unable to attribute this request to an instance running on Clerk.”

Do not repair this by making the local proxy claim the production URL, spoofing
Origin, changing the existing primary domain's proxy URL, relaxing JWT checks,
or accepting unsigned/artificial sessions. Those do not qualify a local login.

The supported choices are:

1. Use a matching development Clerk instance/key pair locally. That permits an
   embedded local login without reconfiguring the shared primary site.
2. Keep the existing pair and configure Clerk multi-domain authentication. The
   local hostname must be a satellite with its own proxy, and primary sign-in
   must support the approved return origin. Sign-in occurs on the primary;
   this is not an independent local embedded sign-in. It requires reviewing
   shared Clerk configuration and the primary login redirect behavior. Clerk
   documents a paid-plan requirement in production mode. Do not change billing
   or primary sign-in without explicit user authorization.

No Clerk domain, shared auth configuration, primary redirect, or billing change
was made as part of the local repair. Login/logout, persistence after refresh,
and account permissions still need a real authenticated browser test after
the domain/setup decision. A rendered SDK alone is not a completed login.

The user subsequently declined registering the temporary local hostname because
future copies will have different names. Do not implement the satellite option
for this copy. The requested follow-up is the maintained fork and portable source,
not shared authentication changes. See [portable local development](local-development.md).

Official references: [Clerk Frontend API proxy](https://clerk.com/docs/guides/dashboard/dns-domains/proxy-fapi),
[multi-domain authentication](https://clerk.com/docs/guides/dashboard/dns-domains/satellite-domains),
[Clerk testing](https://clerk.com/docs/guides/development/testing/overview).

## Verification and future publishing

Offline tests cover proxy configuration, fixed upstream, request methods and
bodies, HTTPS, forbidden headers, cookie isolation, no redirects with credentials,
cache control, missing/mismatched keys, request size, local write-back blocking,
profile-read exceptions, duplicate bridge requests and visible load errors.

Actual local REST checks rejected missing nonces (403), missing tokens (400),
malformed tokens (401) and foreign origins (403), setting no session cookies.
They created no accounts and did not establish an authenticated session.

The package builder includes the installed pinned Composer runtime and application
source, excludes `deploy/`, tests, docs and build tools, and fails if dependency
installation fails. The DDEV routing snippet and local MU guard are installed
separately and must stay local. A ZIP candidate is not a published release.

The source checkout was cloned from `systemsaholic/clerk-wp-sync` and overlaid
with the 23 existing local customization files before this repair. Consequently,
the branch diff from upstream includes earlier UNBC changes as well as this fix.
The exact before-state and file digests remain in private operator receipts.
The maintained fork retains upstream history; source commits do not authorize
publishing these changes to production or changing Clerk configuration.

For a future production update, preserve the live database, Clerk credentials,
domain/proxy configuration and existing MU file. Publish reviewed code only;
do not replace production with the local database/options or DDEV files. Theme
and LeagueFlow source updates do not include this separate Clerk plugin. The
live login widget already rendered through its existing proxy before this repair;
no successful local test is claimed until the real sign-in can complete.
