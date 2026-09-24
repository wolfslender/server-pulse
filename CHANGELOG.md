# Changelog

All notable changes to this project are documented here.

## [3.7.0] - 2026-09-24

### Added
- **Traffic & logs (Pro)**: analyze WP Engine / Apache access and error logs into actionable insight, without sending anything off the server.
  - Access-log analyzer: request/minute spikes, 5xx and 504 counts by day, top offending IPs (with empty-User-Agent and 5xx attribution), busiest minutes, top paths, missing-asset 404 hot spots and heavy dynamic endpoints.
  - Error-log analyzer: recurring PHP warnings/notices/fatals grouped by file:line with counts and first/last seen.
  - Automatic recommendations: copy-paste edge rules (block empty User-Agent, rate limit offender IPs, short-circuit missing assets, cache heavy endpoints) and a downloadable standalone HTML report.
  - Sources: auto-detected `_wpeprivate` logs on the server, or an uploaded `.log` / `.log.gz` file read in memory and never stored.
  - New Pro findings surfaced in the Diagnostics advisor (5xx spikes, empty-UA abuse, missing assets, PHP noise/fatals).
- The analyzer streaming engine is hard-capped (300 MB / 3M lines / 25 s, bounded unique keys) so a hostile or huge log cannot exhaust memory or time.

### Changed
- New admin UI lives under the single **Tools → Server Pulse** screen, now with a **Traffic & logs** tab (Pro).

### Security
- Traffic analysis endpoints enforce capability, nonce and the Pro license; uploads are validated (`is_uploaded_file`, extension allowlist, size cap) and are never moved, stored or served. Log discovery is confined to real paths inside the WP Engine private roots.

## [3.6.0] - 2026-09-23

### Changed
- The admin UI now lives under a single **Tools → Server Pulse** screen with **Dashboard, Settings, Alerts and Diagnostics** tabs, instead of a top-level menu, per the WordPress.org guidelines.
- Buttons and chips were restyled with rounded corners, a floating elevation and a modern cyan→indigo→fuchsia gradient with a light sweep on hover for primary actions.

### Fixed
- Fresh activations now schedule the 5-minute uptime check: the custom interval is registered before scheduling, so site-down alerts work on new installs.
- Notification bookkeeping no longer counts a failed channel (`WP_Error`) as delivered, the daily cap counts the actual notifications sent in the last 24 hours instead of the lifetime total, and critical escalations bypass the reminder cooldown.
- Capacity projection alerts resolve correctly when the projection becomes unavailable.
- The dashboard escapes theme, version and process values before injecting them, closing a DOM-XSS vector.
- cPanel resource values (disk, bandwidth, database) are converted from the UAPI megabytes to bytes so they merge correctly with other providers; the port follows the SSL setting when left at the default.
- The `enable_native` and `enable_wordpress` settings are now honoured by their providers.
- Outbound requests disable redirects so a validated public host cannot bounce to a private address, and WordPress's own unsafe-URL check is enabled as a second layer.
- Credentials fall back to an HMAC-authenticated keystream (instead of reversible base64) when OpenSSL is missing; the encryption and sentinel HMAC keys mix in a per-install random secret, and the CBC fallback is authenticated.
- "Delete revisions" now keeps the newest revisions per post and respects `WP_POST_REVISIONS`; transient cleanup also removes site/network transients.
- The sentinel no longer raises a false crash for slow activations (fixed window widened) and the uptime check requires two consecutive failures before alerting.
- The Diagnostics advisor category and severity counts now respect the active filters, so a category count can no longer show findings that the filtered list would hide; added a "Clear filters" control.
- Resolved alerts are pruned with the retention window and the alerts table indexes `last_notified`.

### Security
- Added an outbound URL guard that blocks SSRF to private, loopback and reserved addresses (including cloud metadata 169.254.169.254), applied to webhooks and the cPanel provider, with an explicit opt-in for private networks.
- cPanel TLS certificate verification is now configurable and on by default (previously always disabled).
- The crash marker is HMAC-signed; the early loader and the sentinel refuse to act on an unsigned or tampered marker.
- Activation guarding now only arms on a nonce-verified activation request.
- Credentials now use AES-256-GCM (authenticated) with `random_bytes()`, keeping backward compatibility with the legacy CBC format; a warning is shown when the platform cannot encrypt securely.
- Added per-user throttling to costly AJAX actions.
- Hardened the WP Engine password sanitizer against double encryption; completed uninstall cleanup; the early loader now updates itself when the bundled file changes.

## [3.5.0] - 2026-09-23

### Added
- **WordPress dashboard widget** with activation-crash alerts, diagnostics counts and a list of plugins whose declared requirements the environment cannot meet.
- **Pro development switch** (Settings → Plan) that unlocks Pro features for testing; can also be forced with the `server_pulse_is_pro` filter.
- Crash notices are now derived from persisted history, so they no longer expire and can be dismissed explicitly.

### Changed
- Detection also runs on the WordPress dashboard and Server Pulse screens, not only on the plugins screen.
- The diagnostics report is computed during sampling and cached, so the dashboard, widget and cards read cached data instead of running checks on page load.
- The crash-marker option is autoloaded to keep the early loader query-free.

## [3.4.0] - 2026-09-23

### Added
- **Early crash loader** (optional mu-plugin): deactivates the offending plugin before regular plugins load, so a plugin that fatal-errors on every load can no longer lock the admin out.
- Settings toggle to install/remove the loader; the file is copied to `wp-content/mu-plugins/`.
- The marker option is now autoloaded so the loader can read it with no extra query per request.

## [3.3.0] - 2026-09-23

### Added
- **Crash sentinel**: detects plugin activations that kill the PHP worker (nginx 502, OOM kill or timeout) out of band, records the offending plugin, how far it got and whether PHP caught a fatal.
- One-click **rollback** to deactivate the crashing plugin and restore the previous active plugins, plus an optional auto-rollback for production.
- Admin notice on the plugins screen explaining the crash and offering the rollback.
- Diagnostics advisor now surfaces recent activation crashes.

## [3.2.0] - 2026-09-23

### Added
- **Diagnostics advisor** inside the plugin: read-only checks across server, PHP, WordPress, database and security, each with a plain-language explanation and a recommended fix.
- One-click safe fixes: purge expired transients, delete post revisions and clear `wp-content/debug.log`.
- **Hosting environment detection**: identifies managed WordPress hosts (WP Engine, Flywheel, Kinsta, Cloudways, SiteGround, Hostinger, Pressable, WP Cloud, Pantheon, Platform.sh, Rocket.net, Nexcess, Servebolt…), control panels (cPanel/WHM, Plesk, DirectAdmin, ISPConfig, Webmin, HestiaCP, VestaCP, CWP, CyberPanel, InterWorx, CloudPanel, aaPanel, RunCloud, GridPane, SpinupWP, ServerPilot, Ploi, Forge…) and cloud/container platforms (AWS, GCP, Azure, DigitalOcean, Linode, Vultr, Hetzner, UpCloud, Docker, Kubernetes).
- New **Diagnostics** admin screen and a diagnostics summary card on the dashboard.
- AJAX actions `server_pulse_get_advisor` and `server_pulse_run_fix`.

## [3.1.0] - 2026-09-22

### Added
- Alert engine with per-rule thresholds, de-duplication, reminder cooldowns and a daily notification cap.
- Site availability (uptime) check every 5 minutes with down/resolved notifications.
- Email alert channel (free) with a styled HTML message and per-site subject lines.
- Pro delivery channels: generic webhook, Slack, Discord and Telegram, gated behind `Server_Pulse_License::is_pro()`.
- New `{prefix}sp_alerts` table storing alert history, status and notification counts.
- Alerts screen with history, active count and a "send test alert" action.
- Dashboard alert history table and alert configuration inside Settings.
- **Trend analysis and projections**: per metric current vs 7-day average with deviation detection (`cpu_trend`, `memory_trend`, `disk_trend`, `php_memory_trend`).
- **Capacity projections**: days left until disk reaches capacity, database growth rate and projected month-end bandwidth usage (`disk_projection`, `bandwidth_projection`), with a dedicated dashboard card.
- `disk_used` time series for growth-rate math.
- Database schema version 2 with an automatic upgrade routine for existing installs.

### Changed
- Alert channel secrets (webhook, Slack, Discord, Telegram) are encrypted at rest like the provider credentials.
- Threshold resolution now only clears rules owned by the threshold engine, so trend/projection alerts keep their own lifecycle.

### Security
- Every new AJAX action keeps the existing capability and nonce guards; channel URLs are sanitized and stored encrypted.

## [3.0.1] - 2026-09-22

### Fixed
- WP Engine credentials were double-encrypted because WordPress runs the settings sanitize callback twice, causing HTTP 401. Encryption is now idempotent and decryption auto-heals previously double-encrypted values.
- WP Engine limits endpoint corrected to `/accounts/{id}/limits` and `storage`/`bandwidth` (GB) are now converted to bytes.
- Provider connection tests now show a real step-by-step diagnostic and the settings screen loads the JavaScript that runs them.

### Added
- Local storage scanner: walks `wp-content` (uploads, plugins, themes, mu-plugins, other), runs daily via cron and on demand from the dashboard, and feeds the disk card when no hosting API is available.

## [3.0.0] - 2026-09-22

### Added
- Multi-provider architecture: Native, cPanel, WP Engine and WordPress providers.
- Provider manager with automatic host detection and per-metric prioritized merge.
- WP Engine provider using the official Hosting Platform API (storage, visits, requests, bandwidth, limits).
- cPanel provider using the UAPI, with CloudLinux LVE support when available.
- Native provider reading `/proc`, `sys_getloadavg` and `disk_*` functions.
- WordPress & database provider: DB size, autoloaded options, revisions, transients, cron health, object cache, environment details.
- Health score, grade and alert thresholds.
- Historical charts (hand-rolled SVG, no dependencies) with 24h / 7d / 30d ranges.
- Custom `{prefix}sp_samples` table with scheduled sampling and retention cleanup.
- Encrypted credential storage (WordPress salts + OpenSSL).
- REST API endpoints under `server-pulse/v1`.
- Settings screen, provider connection tests, uninstall cleanup and i18n.

### Changed
- Complete rewrite of the previous single-file monitor.
- Split assets into `assets/css/admin.css`, `assets/js/charts.js` and `assets/js/dashboard.js`.
- Main plugin file renamed to `server-pulse.php`; old `de-info-plugin.php` removed.

### Security
- Capability checks (`manage_options`) and nonces on every AJAX/REST action.
- Input sanitization and output escaping throughout.
- Shell execution is opt-in and read-only.

## [2.1.0] - 2024-01-10

### Added
- API Request Monitor for REST API traffic.
- Suspicious request detection and IP tracking.
- Improved progress bars with visual indicators and tooltips.

### Changed
- Styles moved to a separate CSS file.
- Improved memory and CPU visualization.

## [2.0] - 2025-01-09

### Added
- Monitoring of the most resource-consuming services.
- Memory usage display in MB.

## [1.1] - 2025-01-09

### Added
- Initial release with real-time server memory and CPU usage.
