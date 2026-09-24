<?php
/**
 * PRO: derive recommendations and exportable reports from the traffic data.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a traffic report into an action plan and a standalone HTML document.
 */
class Server_Pulse_Traffic_Report {

	/**
	 * Build the recommended edge rules from a report.
	 *
	 * @param array $report Traffic report.
	 * @return array {
	 *     @type array $rules  Ordered list of rule descriptors.
	 *     @type string $text  Copy-paste text block.
	 * }
	 */
	public static function recommendations( array $report ) {
		$rules = array();

		if ( ! empty( $report['empty_ua'] ) ) {
			$rules[] = array(
				'id'    => 'empty_ua',
				'title' => __( 'Block requests with an empty or blank User-Agent', 'server-pulse' ),
				'why'   => sprintf(
					/* translators: %d: count. */
					__( 'Saw %d requests with no User-Agent. Real browsers always send one.', 'server-pulse' ),
					(int) $report['empty_ua']
				),
				'rule'  => 'Block if request User-Agent is empty OR matches ^\\s*$',
			);
		}

		$offenders = self::top_offender_ips( $report );
		if ( $offenders ) {
			$rules[] = array(
				'id'    => 'ratelimit_ips',
				'title' => __( 'Rate limit or block the top offending IPs', 'server-pulse' ),
				'why'   => __( 'These clients produced the most requests and 5xx responses in the analyzed window. Verify they are not your host monitoring or cron before blocking.', 'server-pulse' ),
				'rule'  => "Rate limit (e.g. 60 req/min) OR block these IPs:\n" . implode( "\n", $offenders ),
			);
		}

		$edits = self::missing_asset_actions( $report );
		if ( $edits ) {
			$rules[] = array(
				'id'    => 'missing_assets',
				'title' => __( 'Short-circuit the top missing assets', 'server-pulse' ),
				'why'   => __( 'Missing files generate 404s that still boot WordPress and consume a PHP worker. Serve them statically or return an instant 404/410 at the edge.', 'server-pulse' ),
				'rule'  => implode( "\n", $edits ),
			);
		}

		$heavy = self::heavy_rule( $report );
		if ( '' !== $heavy ) {
			$rules[] = array(
				'id'    => 'heavy_endpoints',
				'title' => __( 'Cache or short-circuit high-volume endpoints', 'server-pulse' ),
				'why'   => __( 'These dynamic endpoints are hit on nearly every page view. Cache them (varying by what they need) or move the work out of the request path.', 'server-pulse' ),
				'rule'  => $heavy,
			);
		}

		$text = '';
		foreach ( $rules as $rule ) {
			$text .= '# ' . $rule['title'] . "\n" . $rule['rule'] . "\n\n";
		}

		return array(
			'rules' => $rules,
			'text'  => trim( $text ),
		);
	}

	/**
	 * IPs worth rate limiting (high volume and/or 5xx and/or empty UA).
	 *
	 * @param array $report Report.
	 * @return string[]
	 */
	private static function top_offender_ips( array $report ) {
		$out = array();

		foreach ( (array) ( isset( $report['top_ips'] ) ? $report['top_ips'] : array() ) as $row ) {
			$requests = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			$five     = isset( $row['five_xx'] ) ? (int) $row['five_xx'] : 0;
			$empty    = isset( $row['empty_ua'] ) ? (int) $row['empty_ua'] : 0;

			if ( $requests < 200 && $five < 20 && $empty < 20 ) {
				continue;
			}

			$out[] = sprintf(
				'%s  (%d req, %d 5xx, %d empty-UA)',
				isset( $row['ip'] ) ? $row['ip'] : '?',
				$requests,
				$five,
				$empty
			);

			if ( count( $out ) >= 10 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Suggested handling for the top missing assets.
	 *
	 * @param array $report Report.
	 * @return string[]
	 */
	private static function missing_asset_actions( array $report ) {
		$hints = array(
			'edge_404'     => 'Return an instant 404/410 at the edge (no PHP).',
			'add_favicon'  => 'Add the file (or set a Site Icon in WP) so the browser stops retrying.',
			'add_static'   => 'Publish the static file (ads.txt / assetlinks.json) or redirect it.',
			'missing_asset' => 'Restore or redirect the missing file.',
			'scanner'      => 'Likely a scanner; block at the edge if the volume is high.',
		);

		$out = array();
		foreach ( (array) ( isset( $report['missing_assets'] ) ? $report['missing_assets'] : array() ) as $row ) {
			$path = isset( $row['path'] ) ? $row['path'] : '';
			$count = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			$fix   = isset( $row['suggestion'] ) ? $row['suggestion'] : 'scanner';

			if ( $count < 50 ) {
				continue;
			}

			$out[] = sprintf(
				'%s  (%d×) — %s',
				$path,
				$count,
				isset( $hints[ $fix ] ) ? $hints[ $fix ] : $hints['scanner']
			);
		}

		return $out;
	}

	/**
	 * A rule snippet for the heaviest dynamic endpoints.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function heavy_rule( array $report ) {
		$lines = array();

		foreach ( (array) ( isset( $report['heavy_endpoints'] ) ? $report['heavy_endpoints'] : array() ) as $row ) {
			$path  = isset( $row['path'] ) ? $row['path'] : '';
			$count = isset( $row['requests'] ) ? (int) $row['requests'] : 0;

			if ( $count < 100 ) {
				continue;
			}

			$line = sprintf( '%s  (%d req) — %s', $path, $count, self::endpoint_advice( $row ) );

			if ( ! empty( $row['search'] ) ) {
				$line .= "\n    search: " . $row['search'];
			}

			$lines[] = $line;
		}

		return implode( "\n", array_unique( $lines ) );
	}

	/**
	 * Plain-language guidance for a heavy endpoint.
	 *
	 * @param array $row Heavy-endpoint row.
	 * @return string
	 */
	public static function endpoint_advice( array $row ) {
		switch ( isset( $row['type'] ) ? $row['type'] : '' ) {
			case 'rest':
				return __( 'Custom REST route registered by a theme or plugin. If it runs on every page view, cache it (transient/object cache) and add edge caching with the right Vary header.', 'server-pulse' );
			case 'ajax':
				return __( 'admin-ajax.php request. Find which action is being called and disable the feature you do not need, or cache the response.', 'server-pulse' );
			case 'cron':
				return __( 'WP-Cron is being triggered by web requests. Define DISABLE_WP_CRON and rely on the host cron instead.', 'server-pulse' );
			case 'login':
				return __( 'Login page hit by bots. Rate limit or block it at the edge and enable two-factor authentication.', 'server-pulse' );
			case 'xmlrpc':
				return __( 'XML-RPC endpoint. Disable it if unused (xmlrpc_enabled) or block it at the edge.', 'server-pulse' );
			case 'php':
				return __( 'A PHP endpoint served by a plugin or theme. Review what handles this path.', 'server-pulse' );
		}

		return __( 'Cache or short-circuit this dynamic endpoint.', 'server-pulse' );
	}

	/**
	 * Render a standalone HTML report.
	 *
	 * @param array $report     Traffic report.
	 * @param array $log_report Error report.
	 * @return string
	 */
	public static function html( array $report, array $log_report = array() ) {
		$recs   = self::recommendations( $report );
		$title  = sprintf(
			/* translators: %s: site name. */
			__( 'Server Pulse — Traffic & error report for %s', 'server-pulse' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$h = array();
		$h[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
		$h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$h[] = '<title>' . esc_html( $title ) . '</title>';
		$h[] = '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;margin:0;background:#f6f7f9;color:#1d2327}';
		$h[] = '.wrap{max-width:1000px;margin:0 auto;padding:32px}.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px 24px;margin:16px 0}';
		$h[] = 'h1{font-size:22px}h2{font-size:16px;margin:0 0 10px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #f0f0f1}';
		$h[] = 'code{background:#f0f0f1;padding:1px 5px;border-radius:5px}pre{white-space:pre-wrap;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:8px;font-size:12px;overflow:auto}';
		$h[] = '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.kpi{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px}.kpi b{display:block;font-size:22px}.muted{color:#646970;font-size:12px}</style></head><body><div class="wrap">';

		$h[] = '<h1>' . esc_html( $title ) . '</h1>';
		$h[] = '<p class="muted">' . esc_html( sprintf( __( 'Generated %s', 'server-pulse' ), gmdate( 'Y-m-d H:i:s', (int) ( isset( $report['generated_at'] ) ? $report['generated_at'] : time() ) ) . ' UTC' ) ) . '</p>';

		$h[] = self::kpi_html( $report );
		$h[] = self::action_plan_html( $recs );

		$h[] = '<div class="card"><h2>' . esc_html__( 'Top IPs', 'server-pulse' ) . '</h2>' . self::table_ips( $report ) . '</div>';
		$h[] = '<div class="card"><h2>' . esc_html__( 'Busiest minutes', 'server-pulse' ) . '</h2>' . self::table_minutes( $report ) . '</div>';
		$h[] = '<div class="card"><h2>' . esc_html__( 'Top missing assets (404)', 'server-pulse' ) . '</h2>' . self::table_missing( $report ) . '</div>';
		$h[] = '<div class="card"><h2>' . esc_html__( 'Heavy endpoints', 'server-pulse' ) . '</h2>' . self::table_heavy( $report ) . '</div>';

		if ( $log_report ) {
			$h[] = '<div class="card"><h2>' . esc_html__( 'Top PHP errors', 'server-pulse' ) . '</h2>' . self::table_errors( $log_report ) . '</div>';
		}

		$h[] = '</div></body></html>';

		return implode( '', $h );
	}

	/**
	 * KPI cards.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function kpi_html( array $report ) {
		$status = isset( $report['status'] ) ? (array) $report['status'] : array();
		$kpis   = array(
			__( 'Requests', 'server-pulse' ) => (int) ( isset( $report['requests'] ) ? $report['requests'] : 0 ),
			'5xx'                            => (int) ( isset( $status['5xx'] ) ? $status['5xx'] : 0 ),
			'504'                            => (int) ( isset( $status['504'] ) ? $status['504'] : 0 ),
			__( '404', 'server-pulse' )      => (int) ( isset( $status['404'] ) ? $status['404'] : 0 ),
			__( 'Empty UA', 'server-pulse' ) => (int) ( isset( $report['empty_ua'] ) ? $report['empty_ua'] : 0 ),
		);

		$out = '<div class="card"><div class="grid">';
		foreach ( $kpis as $label => $value ) {
			$out .= '<div class="kpi"><span class="muted">' . esc_html( $label ) . '</span><b>' . esc_html( number_format_i18n( $value ) ) . '</b></div>';
		}
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Action plan.
	 *
	 * @param array $recs Recommendations.
	 * @return string
	 */
	private static function action_plan_html( array $recs ) {
		if ( empty( $recs['rules'] ) ) {
			return '';
		}

		$out = '<div class="card"><h2>' . esc_html__( 'Recommended actions', 'server-pulse' ) . '</h2><ol>';
		foreach ( $recs['rules'] as $rule ) {
			$out .= '<li><strong>' . esc_html( $rule['title'] ) . '</strong><br><span class="muted">' . esc_html( $rule['why'] ) . '</span><pre>' . esc_html( $rule['rule'] ) . '</pre></li>';
		}
		$out .= '</ol></div>';

		return $out;
	}

	/**
	 * IP table.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function table_ips( array $report ) {
		$out = '<table><tr><th>IP</th><th>' . esc_html__( 'Requests', 'server-pulse' ) . '</th><th>5xx</th><th>' . esc_html__( 'Empty UA', 'server-pulse' ) . '</th></tr>';
		foreach ( (array) ( isset( $report['top_ips'] ) ? $report['top_ips'] : array() ) as $row ) {
			$out .= '<tr><td><code>' . esc_html( $row['ip'] ) . '</code></td><td>' . esc_html( number_format_i18n( $row['requests'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['five_xx'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['empty_ua'] ) ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * Minutes table.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function table_minutes( array $report ) {
		$out = '<table><tr><th>' . esc_html__( 'Minute', 'server-pulse' ) . '</th><th>' . esc_html__( 'Requests', 'server-pulse' ) . '</th><th>5xx</th></tr>';
		foreach ( (array) ( isset( $report['top_minutes'] ) ? $report['top_minutes'] : array() ) as $row ) {
			$out .= '<tr><td>' . esc_html( $row['minute'] ) . '</td><td>' . esc_html( number_format_i18n( $row['requests'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['five_xx'] ) ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * Missing assets table.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function table_missing( array $report ) {
		$out = '<table><tr><th>' . esc_html__( 'Path', 'server-pulse' ) . '</th><th>' . esc_html__( '404s', 'server-pulse' ) . '</th><th>' . esc_html__( 'Suggestion', 'server-pulse' ) . '</th></tr>';
		foreach ( (array) ( isset( $report['missing_assets'] ) ? $report['missing_assets'] : array() ) as $row ) {
			$out .= '<tr><td><code>' . esc_html( $row['path'] ) . '</code></td><td>' . esc_html( number_format_i18n( $row['requests'] ) ) . '</td><td>' . esc_html( $row['suggestion'] ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * Heavy endpoints table.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function table_heavy( array $report ) {
		$out = '<table><tr><th>' . esc_html__( 'Path', 'server-pulse' ) . '</th><th>' . esc_html__( 'Requests', 'server-pulse' ) . '</th><th>' . esc_html__( 'What to do', 'server-pulse' ) . '</th></tr>';
		foreach ( (array) ( isset( $report['heavy_endpoints'] ) ? $report['heavy_endpoints'] : array() ) as $row ) {
			$advice = self::endpoint_advice( $row );
			if ( ! empty( $row['search'] ) ) {
				$advice .= ' ' . sprintf(
					/* translators: %s: code snippet to search for. */
					__( 'Search for: %s', 'server-pulse' ),
					$row['search']
				);
			}
			$out .= '<tr><td><code>' . esc_html( $row['path'] ) . '</code></td><td>' . esc_html( number_format_i18n( $row['requests'] ) ) . '</td><td>' . esc_html( $advice ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * Error table.
	 *
	 * @param array $report Error report.
	 * @return string
	 */
	private static function table_errors( array $report ) {
		$out = '<table><tr><th>' . esc_html__( 'Count', 'server-pulse' ) . '</th><th>' . esc_html__( 'Severity', 'server-pulse' ) . '</th><th>' . esc_html__( 'Location', 'server-pulse' ) . '</th><th>' . esc_html__( 'Message', 'server-pulse' ) . '</th></tr>';
		foreach ( (array) ( isset( $report['groups'] ) ? $report['groups'] : array() ) as $row ) {
			$out .= '<tr><td>' . esc_html( number_format_i18n( $row['count'] ) ) . '</td><td>' . esc_html( $row['severity'] ) . '</td><td><code>' . esc_html( $row['file'] . ':' . $row['line'] ) . '</code></td><td>' . esc_html( $row['message'] ) . '</td></tr>';
		}
		return $out . '</table>';
	}
}
