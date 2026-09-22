# Changelog

All notable changes to this project are documented here.

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
