=== Server Pulse ===
Contributors: alexisolivero
Tags: server, monitoring, health, wp engine, cpanel
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time server and WordPress health monitoring for any host, with native, cPanel, WP Engine and WordPress data providers.

== Description ==

Server Pulse gives you a single dashboard for the health of your server and your WordPress site. It automatically detects what the current host exposes and uses the best available data source instead of pretending it can read resources that a managed host does not provide.

**Data providers**

* **Native (Linux / VPS / dedicated)** – CPU load, RAM, disk, uptime and top processes from the operating system.
* **cPanel** – account disk and bandwidth usage through the cPanel UAPI, plus CloudLinux LVE metrics when available.
* **WP Engine** – account storage, visits, requests, bandwidth and plan limits from the official WP Engine Hosting Platform API.
* **WordPress & Database** – database size, autoloaded options, revisions, transients, cron health, object cache and content counts. Works everywhere.

**Features**

* Live refresh dashboard with health score and graded rating.
* Prioritized metric merge: each metric is taken from the most authoritative provider available.
* Historical charts (24h / 7d / 30d) stored in a dedicated table, with configurable sampling and retention.
* Alert thresholds for CPU, memory, disk, autoloaded options and overdue cron events.
* Site availability check every 5 minutes with down and recovery notifications.
* Email alerts included, with de-duplication, reminder cooldowns and a daily cap.
* Pro channels: generic webhook, Slack, Discord and Telegram (encrypted at rest).
* Trend analysis against your own 7-day baseline - catches regression before any fixed threshold.
* Forward-looking projections: days left on disk, database growth and month-end bandwidth usage.
* Built-in diagnostics advisor that explains each problem and how to fix it, without WP_DEBUG.
* One-click safe fixes: expired transients, post revisions and debug.log.
* Crash sentinel: catches plugin activations that 502 / run out of memory and rolls them back.
* Optional early loader that recovers sites where the crashed plugin fatal-errors on every load.
* WordPress dashboard widget for crash alerts and at-risk plugins.
* Security: SSRF guard on outbound requests, verified TLS for cPanel, signed crash markers and AES-256-GCM credential encryption.
* Hosting environment detection across managed hosts, control panels and cloud platforms.
* Credentials encrypted at rest using AES-256-GCM with a per-install secret (legacy formats still decrypt).
* REST endpoints (`/wp-json/server-pulse/v1/`) for external monitoring.
* No external calls unless you explicitly configure a provider. No data leaves your site.

**Honest about managed hosting**

WP Engine is a managed platform on a shared cluster. It does not expose per-account CPU or RAM to PHP, and no plugin can measure them from inside WordPress. Server Pulse shows the real account-level data WP Engine does expose (disk, visits, bandwidth, limits) and clearly marks CPU/RAM as unavailable instead of inventing numbers.

== Installation ==

1. Upload the `server-pulse` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Open **Server Pulse** in the admin menu.
4. (Optional) Go to **Server Pulse → Settings** to configure providers, thresholds and sampling.

== Frequently Asked Questions ==

= Why can I not see CPU or RAM on my managed host? =

Managed hosts such as WP Engine run your site in a container on a shared cluster. The kernel does not expose host CPU/RAM to PHP and the hosting API does not provide per-account values. Server Pulse labels these metrics as unavailable rather than showing misleading data. On a VPS or dedicated server with the Native provider they are available.

= Is any data sent to third parties? =

No. The only outbound requests are to the APIs you configure (WP Engine, cPanel). Nothing is sent to the plugin author.

= Where is the history stored? =

In a custom table named `{prefix}sp_samples`. It is removed on uninstall.

== Screenshots ==

1. Dashboard overview with health score and provider pills.
2. Historical charts.

== Changelog ==

= 3.6.0 =
* Security: outbound URL guard against SSRF (blocks private/loopback/metadata addresses) for webhooks and cPanel, with an opt-in for private networks.
* Security: cPanel TLS verification is now configurable and on by default.
* Security: the crash marker is HMAC-signed; the sentinel and early loader ignore tampered markers.
* Security: activation guarding only arms on a nonce-verified request; credentials use AES-256-GCM with random_bytes (legacy CBC still decrypts).
* Security: added throttling to costly actions and completed uninstall cleanup.
* Fixed: fresh activations now schedule the 5-minute uptime check.
* Fixed: notifications no longer count failed channels as delivered; the daily cap counts the real 24h total and critical alerts bypass the cooldown.
* Fixed: capacity projection alerts resolve when the projection disappears.
* Fixed: closed a DOM-XSS vector in the dashboard for theme/version/process values.
* Fixed: cPanel disk/bandwidth/database values convert from MB to bytes and the port follows the SSL setting.
* Fixed: the enable_native and enable_wordpress settings are honoured.
* Fixed: outbound requests disable redirects and enable WordPress's unsafe-URL check.
* Fixed: credentials use an HMAC-authenticated keystream without OpenSSL and mix in a per-install secret; the CBC fallback is authenticated.
* Fixed: "delete revisions" keeps the newest per post and respects WP_POST_REVISIONS; expired-transient cleanup also removes site/network transients.
* Fixed: the sentinel no longer flags slow activations and uptime requires two consecutive failures; resolved alerts are pruned and indexed.

= 3.5.0 =
* Added: WordPress dashboard widget with crash alerts, diagnostics counts and plugins that may break the site.
* Added: Pro development switch to unlock Pro features for testing.
* Changed: crash notices persist until dismissed; detection also runs on the dashboard; diagnostics run in the background and are cached.

= 3.4.0 =
* Added: optional early crash loader (mu-plugin) that deactivates the offending plugin before regular plugins load, rescuing sites where a plugin fatal-errors on every load.
* Added: settings toggle to install or remove the loader.

= 3.3.0 =
* Added: crash sentinel that detects plugin activations which kill PHP (nginx 502, OOM kill or timeout).
* Added: one-click rollback to deactivate the crashing plugin and restore the previous active plugins, with optional auto rollback.
* Added: admin notice explaining the crash, and crash findings in the diagnostics advisor.

= 3.2.0 =
* Added: built-in diagnostics advisor with plain-language findings and a recommended fix for each issue, without enabling WP_DEBUG.
* Added: one-click safe fixes (purge expired transients, delete revisions, clear debug.log).
* Added: hosting environment detection for managed hosts, control panels and cloud platforms.
* Added: Diagnostics admin screen and a diagnostics summary card on the dashboard.

= 3.1.0 =
* Added: alert engine with per-rule thresholds, de-duplication, reminder cooldowns and a daily cap.
* Added: site availability (uptime) check every 5 minutes with down and recovery notifications.
* Added: email alert channel, included for free, with a styled HTML message.
* Added: Pro channels (generic webhook, Slack, Discord, Telegram) gated behind a license check.
* Added: trend analysis against a 7-day baseline with deviation alerts for CPU, memory, disk and PHP memory.
* Added: capacity projections - days left on disk, database growth and month-end bandwidth, plus dashboard trends card.
* Added: alerts log screen, dashboard alert history and a "send test alert" action.
* Added: new `{prefix}sp_alerts` table and automatic schema upgrade.

= 3.0.1 =
* Fixed: WP Engine credentials were double-encrypted (HTTP 401). Encryption is now idempotent and self-healing.
* Fixed: WP Engine limits endpoint and GB to bytes conversion.
* Added: real provider connection diagnostics.
* Added: local storage scanner for hosts without a hosting API.

= 3.0.0 =
* Complete rewrite with a multi-provider architecture (Native, cPanel, WP Engine, WordPress).
* New health score, alerts and historical charts.
* Encrypted credential storage.
* REST API endpoints.
* WordPress.org friendly: i18n, nonces, capability checks and uninstall cleanup.

== Upgrade Notice ==

= 3.6.0 =
Security hardening (SSRF protection, TLS verification, signed crash markers and authenticated credential encryption) plus alert, cPanel unit, uptime and dashboard reliability fixes.

= 3.5.0 =
Adds a WordPress dashboard widget for crash alerts and at-risk plugins, a Pro testing switch, and keeps diagnostics off the page-load path.

= 3.4.0 =
Adds an optional early loader that deactivates a crashing plugin before it loads again. Enable it under Settings → Crash protection.

= 3.3.0 =
Adds the crash sentinel: if a plugin activation takes the site down with a 502 or OOM, Server Pulse now identifies it and offers a rollback.

= 3.2.0 =
Adds the diagnostics advisor with fix suggestions, plus hosting environment detection. Look for the new Diagnostics screen.

= 3.1.0 =
Adds the alert engine: email alerts included, plus Pro webhook/Slack/Discord/Telegram channels. Review the new Alerts section in Settings.

= 3.0.0 =
Major rewrite. Please review the settings after upgrading.
