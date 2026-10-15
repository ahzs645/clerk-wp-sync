# Portable local WordPress development

Every local site can have its own hostname. The proxy uses the WordPress
configured home URL for `/__clerk`, its ClerkJS script URL and the session
bridge's trusted issuer. The new-install login default uses that site's
`/portal/`, including a configured subdirectory. No DDEV project name needs
to be changed in plugin source.

Existing saved login URLs and other settings are preserved on activation.
Do not replace production settings with local options during deployment.

## Clerk environments

The plugin's portability and Clerk's key/domain restrictions are separate.

For independent local login on disposable hostnames, configure one matching
development-instance key pair in the local environment. Reuse that development
configuration for future local copies as appropriate; production keeps its own
configuration. Use test accounts, not production participant identities, for
automated authentication qualification.

A production-mode pair remains bound to its configured domains even when the
website is only used for learning. The fork cannot remove that provider rule.
Registering every temporary hostname as a production satellite was declined for
this workflow and is not automated. Do not alter the primary domain's proxy,
spoof an origin, relax token validation or auto-register domains to work around it.

No key switching, credential entry, domain changes, billing changes or positive
sign-in tests are performed by this repository's CI.

## DDEV routing

For a root-installed WordPress site on DDEV Nginx:

1. Copy `deploy/ddev/nginx/clerk-frontend-proxy.conf` into that project's
   `.ddev/nginx/` directory.
2. Run `ddev exec sudo nginx -t`. Only if valid, activate with a graceful Nginx
   reload or the normal DDEV restart flow.
3. Confirm the SDK request under `/__clerk/npm/` reaches WordPress. A static-file
   404 means the web-server location did not route into the proxy.

The sample Nginx snippet targets a root installation. For subdirectory WordPress,
adapt its location and index.php paths to the actual project before applying it.
The PHP proxy itself derives the subdirectory from WordPress settings.

The web server must resolve the client address through its explicitly trusted
ingress when proxied. The PHP proxy does not trust arbitrary user-supplied
forwarding headers. Domain registration and real login still need verification
after asset routing succeeds.

## Local identity isolation

On a Zoer copy with its existing `zoer-local-copy.php` MU guard, install the
optional `deploy/ddev/mu-plugins/clerk-local-development.php` into that site's
MU plugins directory. It disables the fork's Clerk metadata write-back so a
local WordPress ID cannot overwrite a shared identity's production `wp_id`.

It preserves Zoer's mail and WordPress HTTP guard, allowing only a no-redirect
HTTPS GET for one Clerk user profile needed during first-time login. It does not
permit writes or other destinations and is not an OS-level network sandbox.

These DDEV/MU files remain outside the distributable plugin ZIP. Keep them local.

## Qualification and publishing

Use the actual configured local hostname to test sign-in, WordPress session
creation, refresh, logout and player/staff permissions. Offline fixture results
or a downloaded ClerkJS file do not establish a working login.

Publish reviewed source/packages separately from WordPress data. Keep the live
database, credentials, Clerk domain settings and existing proxy configuration.
The current local investigation remains blocked before real sign-in; see the
[dated investigation](local-clerk-proxy.md).

References: [Clerk proxy configuration](https://clerk.com/docs/guides/dashboard/dns-domains/proxy-fapi),
[testing environments](https://clerk.com/docs/guides/development/testing/overview),
[DDEV custom Nginx configuration](https://docs.ddev.com/en/stable/users/extend/customization-extendibility/#custom-nginx-configuration).
