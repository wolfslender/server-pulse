=== Server Pulse ===
Contributors: alexisolivero
Tags: server, monitoring, health, wp engine, cpanel
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.0.1
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
* Credentials encrypted at rest using WordPress salts and OpenSSL when available.
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

= 3.0.0 =
Major rewrite. Please review the settings after upgrading.
