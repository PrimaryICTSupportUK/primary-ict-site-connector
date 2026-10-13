=== Primary ICT Support Site Connector ===
Contributors: primaryict
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.6.0
License: GPLv2 or later

WordPress inventory and queued diagnostic checks for the Primary ICT Support staff dashboard. Runs individually authorised dashboard component updates.

== Installation ==
1. Add the site's canonical HTTPS home URL in the staff dashboard.
2. Upload the plugin zip through Plugins > Add New and activate it.
3. Open Primary ICT Support > Site Connector.
4. Enter the dashboard origin, site ID and one-time pairing code.
5. Pair the site, then review the latest inventory in the dashboard.

== Data sent ==
Site ID, connector/WordPress/PHP versions, selected PHP limits, all installed normal/MU/drop-in plugins and themes, activation state, parent/child themes, public component metadata, declared requirements/dependencies, native auto-update selections and cached core/plugin/theme/translation candidates with check timestamps.
No content, user list or credentials are included in telemetry. The site credential authenticates outbound HTTPS requests and is stored in a non-autoloaded WordPress option.

== Upgrade from 0.1.0, 0.2.0 or 0.3.0 ==
Upload this ZIP over the installed connector and choose Replace current with uploaded. Activate if needed. Existing pairing settings are preserved; do not uninstall/delete the connector first. Open Primary ICT Support > Site Connector and click Poll dashboard jobs now. No new pairing code is normally required.

== Dashboard jobs ==
The connector claims fixed-purpose inventory collection, WordPress.org update-check and individually authorised component-update jobs for this site. Each invocation runs one bounded phase and reports safe statuses, selected Site Health results and a service-accepted inventory receipt. Failed/unobserved WordPress.org checks preserve the previous cached update list. Local locks prevent overlapping work. The service enforces leases, deadlines and at most three claims for read jobs. Update jobs have one claim and are never retried automatically.
Collection and idle polling intervals inherit dashboard defaults or per-site overrides. Running jobs resume each minute. For idle sites, the dashboard centrally requests the standard public wp-cron.php endpoint; this does not send management credentials. Existing WordPress scheduled tasks can also run. Firewalls or host outages can block wakeups. A service-recorded authenticated cron poll establishes contact at that time, not future reliability. Host cron is an optional fallback.

== Upload behaviour ==
Catalogues upload in batches of up to 25 components and 25 update candidates, with a 200 KB request bound. The service commits only complete snapshots. Each PHP invocation sends at most three batches within a time budget; larger uploads resume through WP-Cron. The plugin preserves pending sanitized inventory locally for up to two hours. The last accepted catalogue remains visible after failures. Catalogues above 5,000 components or candidates fail explicitly rather than being truncated.

== Limits ==
PHP configuration limits are not account resource usage. Hosting disk quota is unknown. Ordinary inventory includes cached candidates; explicit dashboard checks invoke WordPress.org core/plugin/theme checks and record success/failure/skipped evidence. Custom providers require explicit administrator approval of the native package host and a local WordPress update candidate; unsupported hosts and redirects are refused. Diagnostics include three selected Site Health checks, not a full audit. Native auto-update selections do not establish effective behaviour under filters. WordPress Multisite is identified, but update jobs require a single site. Exact targets support WordPress.org plugins, themes, core and translation packs, trusted public GitHub plugins, and explicitly approved native vendor plugins. Jobs require an allowed dashboard policy, single-use service permission, compatible versions and direct filesystem access. Installation runs in cron; a later WordPress request verifies the selected version, theme selection, core database version or translation content hashes. Interrupted writes require staff review with fresh inventory. Core major releases require explicit policy opt-in. Package URLs and credentials remain on WordPress. Connector self-update remains with the native GitHub updater. These additions require the compatible 0.6.0 service; older 0.5.1 connectors retain their plugin-only execution protocol.

== Uninstallation ==
Delete the plugin to remove its local settings and credential. Ask the dashboard administrator to revoke its server-side site credential.

== Connector GitHub releases ==
The native WordPress updater uses public releases from PrimaryICTSupportUK/primary-ict-site-connector. The repository/release channel must be published before automatic discovery works. Releases require a stable vMAJOR.MINOR.PATCH tag, the exact primary-ict-site-connector-VERSION.zip asset and its GitHub SHA-256 digest. Normal WordPress update controls and optional native automatic updates are used; the connector does not enable them for you. No GitHub credential is stored on the site. The dashboard still excludes connector self-updates. Trusted mapped public GitHub plugin jobs are supported.

From connector 0.5.1, use Check connector updates now to bypass cached release metadata. Repeated clicks are limited to one check per minute. Last/next check times and provider failures are visible.

== Changelog ==

= 0.6.0 =
* Adds exact theme, core and translation update jobs with one-use service grants and verification in a later WordPress request.
* Preserves active theme selection and verifies core database version and installed translation file hashes.
* Adds administrator-approved native vendor package hosts; signed package URLs and credentials stay on the WordPress site.
* Extends schema 2 inventory with protocol 3 component candidates and source host evidence.

= 0.5.1 =
* Adds a bounded Check connector updates now action with GitHub status and last/next check times.
* Uses a versioned shared updater so older helpers cannot suppress these controls.
* Allows twenty seconds for dashboard job responses that include independent public-page checks.
* Preserves pairing and shared menu settings. Requires the Phase 1 compatible service.

= 0.5.0 =
* Adds per-site trusted public GitHub plugin updates with pinned release metadata, SHA-256 and archive verification.
* Preserves pairing, shared menu and WordPress.org update support.
