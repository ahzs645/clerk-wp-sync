# Fork provenance

Repository: https://github.com/ahzs645/clerk-wp-sync

Upstream: https://github.com/Systemsaholic/clerk-wp-sync

Initial upstream commit: `76317546a1c57b60829126b742004a85a596b24c`
(`feat: Add build script for releases`). The GitHub fork retains that history.
Original plugin attribution is Al Guertin / Systemsaholic, with GPL v2 or later
declared in the plugin header and README. The fork preserves that attribution.

The starting UNBC variant was recovered from an authorized existing local
WordPress plugin copy on October 15, 2026: 23 source files, excluding installed
vendor dependencies, releases, site configuration and database exports. This
includes the earlier UNBC/LeagueFlow portal integration and account protections.
Exact before-state digests remain outside Git in private operator receipts.

The first maintained version also includes the portable same-origin proxy,
session-bridge configuration fix, local metadata isolation, no-redirect profile
reads, modern frontend redirect options and regression tests. The unused
`clerk-auth(1).js` duplicate was omitted; it was never the enqueued asset.

Maintenance preparation adds a site-relative new-install login default,
preservation of existing options during activation, fork metadata, a committed
dependency lock, ZIP validation and GitHub Actions checks. The upstream binary
ZIP is no longer tracked; build artifacts are generated from reviewed source.

The installed local build remains `1.1.2-unbc-dev.20261015`. The source-only fork
candidate is `1.1.2-unbc-dev.20261015.1`. Neither a Git push nor CI installs that
candidate onto a WordPress site. No stable release has been published.

No API keys, signing secrets, account tokens, real participant records, site
exports or environment configuration belong in this repository. Existing option
names, WordPress plugin slug, Clerk-ID metadata and webhook compatibility routes
are retained to avoid creating a second disconnected installation.
