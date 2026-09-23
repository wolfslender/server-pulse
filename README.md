# Server Pulse

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-3.0.1-brightgreen.svg)](CHANGELOG.md)

Real-time server and WordPress health monitoring for any host. Automatically detects the
environment and pulls metrics from the best available source: the native operating system,
cPanel, the WP Engine Hosting Platform API, or WordPress itself.

> Repository: <https://github.com/wolfslender/server-pulse>

## Why this plugin exists

Most "server monitor" plugins assume they can read CPU and RAM from PHP. On managed hosts
like WP Engine that is impossible: your site runs in a container on a shared cluster and the
kernel never exposes host resources. Server Pulse is honest about this. It shows the real
account-level data WP Engine *does* expose (storage, visits, bandwidth, plan limits) and marks
CPU/RAM as unavailable instead of inventing numbers. On a VPS or dedicated server the Native
provider reads the real system metrics.

## Providers

| Provider | Metrics | Requirements |
| --- | --- | --- |
| Native | CPU load, RAM, disk, uptime, top processes, network | Linux/VPS/dedicated. Shell access optional. |
| cPanel | Account disk, bandwidth, CloudLinux LVE CPU/RAM when present | cPanel host, user and API token. |
| WP Engine | Storage (files + DB), visits, requests, bandwidth, plan limits | WP Engine API user ID + password (account Owner required). |
| WordPress | DB size, autoloaded options, revisions, transients, cron, object cache, versions | Always available. |

The collector merges all providers into one summary using a per-metric priority, so disk comes
from WP Engine on WPE, while CPU/RAM come from the native system on a VPS.

## Features

- Live dashboard with auto refresh, health score and grade.
- Historical charts (24h / 7d / 30d) persisted in `{prefix}sp_samples`.
- Alert thresholds for CPU, memory, disk, autoloaded options and overdue cron.
- **Local storage scan** for hosts without a hosting API: walks `wp-content` and breaks down
  uploads, plugins, themes, mu-plugins and other, daily via cron and on demand.
- **Step-by-step provider diagnostics** for WP Engine and cPanel connections.
- Credentials encrypted at rest with WordPress salts + OpenSSL (idempotent encryption).
- REST API (`/wp-json/server-pulse/v1/snapshot`, `/history`) guarded by `manage_options`.
- No phone-home. No CDN assets. Charts are hand-rolled SVG.

## Installation

1. Copy the folder into `wp-content/plugins/` (the folder should be named `server-pulse`).
2. Activate **Server Pulse**.
3. Open the **Server Pulse** menu.
4. Configure providers under **Server Pulse → Settings**.

## WP Engine setup

WP Engine only exposes its Hosting Platform API when **API access is enabled for the account
by an account Owner**. Generating credentials for a user whose account has API access disabled
results in `HTTP 200` with an empty account list, and the plugin cannot read anything.

1. Log in to `my.wpengine.com` as the account **Owner**.
2. Go to **Users → API Access** (`my.wpengine.com/profile/api_access`).
3. Turn **API access ON** for the account (toggle next to the account name).
4. Click **Generate Credentials** and copy the API User ID and API Password.
5. Paste them in **Server Pulse → Settings → WP Engine** and save.
6. Open the dashboard and click **Test connection** to see the full diagnostic.

If `/accounts` returns `count: 0` with valid credentials, the account's API access is off or
the user lacks account access — contact WP Engine support for an ownership change.

## cPanel setup

1. In cPanel, open **Security → Manage API Tokens** and create a token.
2. Enter the host, username and token in **Server Pulse → Settings → cPanel**.
3. Save and test the connection.

## Development

The codebase is a small provider pattern:

```
server-pulse.php                        Bootstrap + autoloader
includes/
  class-server-pulse-plugin.php         Main controller
  class-server-pulse-*.php              Settings, crypto, repository, health, collector,
                                        admin, ajax, cron, rest, storage scanner, util
  providers/                            Native, cPanel, WP Engine, WordPress
admin/views/                            Dashboard and settings screens
assets/                                 admin.css, charts.js, dashboard.js, settings.js
```

Every provider implements `Server_Pulse_Provider_Interface` and returns a normalized snapshot.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Alexis Olivero · [https://oliverodev.com/](https://oliverodev.com/)

