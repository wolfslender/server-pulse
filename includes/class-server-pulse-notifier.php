<?php
/**
 * Alert delivery channels.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends alert events through the configured channels.
 *
 * Email is available on every install. Webhook, Slack, Discord and Telegram
 * are Pro channels and are skipped unless Server_Pulse_License::is_pro().
 */
class Server_Pulse_Notifier {

	/**
	 * Channel definitions.
	 *
	 * @return array
	 */
	public function channels() {
		return array(
			'email'    => array(
				'label' => __( 'Email', 'server-pulse' ),
				'pro'   => false,
			),
			'webhook'  => array(
				'label' => __( 'Generic webhook', 'server-pulse' ),
				'pro'   => true,
			),
			'slack'    => array(
				'label' => __( 'Slack', 'server-pulse' ),
				'pro'   => true,
			),
			'discord'  => array(
				'label' => __( 'Discord', 'server-pulse' ),
				'pro'   => true,
			),
			'telegram' => array(
				'label' => __( 'Telegram', 'server-pulse' ),
				'pro'   => true,
			),
		);
	}

	/**
	 * Channels that are configured and allowed for the current plan.
	 *
	 * @return array
	 */
	public function enabled_channels() {
		$alerts   = Server_Pulse_Settings::get( 'alerts', array() );
		$channels = $this->channels();
		$active   = array();

		foreach ( $channels as $id => $channel ) {
			if ( $channel['pro'] && ! Server_Pulse_License::is_pro() ) {
				continue;
			}

			if ( $this->is_configured( $id, $alerts ) ) {
				$active[ $id ] = $channel;
			}
		}

		return $active;
	}

	/**
	 * Whether a channel has the data it needs to send.
	 *
	 * @param string $id     Channel id.
	 * @param array  $alerts Alerts settings.
	 * @return bool
	 */
	private function is_configured( $id, array $alerts ) {
		switch ( $id ) {
			case 'email':
				return ! empty( $alerts['email_enabled'] );
			case 'webhook':
				return ! empty( $alerts['webhook_url'] );
			case 'slack':
				return ! empty( $alerts['slack_webhook'] );
			case 'discord':
				return ! empty( $alerts['discord_webhook'] );
			case 'telegram':
				return ! empty( $alerts['telegram_token'] ) && ! empty( $alerts['telegram_chat'] );
		}

		return false;
	}

	/**
	 * Dispatch an event to every enabled channel.
	 *
	 * @param array $event Event payload from the alert engine.
	 * @return array Map of channel id to bool|WP_Error.
	 */
	public function dispatch( array $event ) {
		$results = array();

		foreach ( $this->enabled_channels() as $id => $channel ) {
			$results[ $id ] = $this->send( $id, $event );
		}

		return $results;
	}

	/**
	 * Send through a single channel.
	 *
	 * @param string $id    Channel id.
	 * @param array  $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send( $id, array $event ) {
		switch ( $id ) {
			case 'email':
				return $this->send_email( $event );
			case 'webhook':
				return $this->send_webhook( $event );
			case 'slack':
				return $this->send_slack( $event );
			case 'discord':
				return $this->send_discord( $event );
			case 'telegram':
				return $this->send_telegram( $event );
		}

		return new WP_Error( 'server_pulse_unknown_channel', __( 'Unknown alert channel.', 'server-pulse' ) );
	}

	/**
	 * Email channel.
	 *
	 * @param array $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send_email( array $event ) {
		$alerts     = Server_Pulse_Settings::get( 'alerts', array() );
		$recipients = $this->recipients( $alerts );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'server_pulse_no_recipients', __( 'No alert recipients are configured.', 'server-pulse' ) );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$body    = $this->render_email( $event );
		$sent    = wp_mail( $recipients, $event['subject'], $body, $headers );

		return (bool) $sent;
	}

	/**
	 * Resolve the recipient list.
	 *
	 * @param array $alerts Alerts settings.
	 * @return string[]
	 */
	private function recipients( array $alerts ) {
		$raw = isset( $alerts['email_recipients'] ) ? (string) $alerts['email_recipients'] : '';

		if ( '' === trim( $raw ) ) {
			$raw = get_option( 'admin_email' );
		}

		$list = array_filter( array_map( 'sanitize_email', preg_split( '/[,\s]+/', $raw ) ) );

		return array_values( array_unique( $list ) );
	}

	/**
	 * Build the HTML email body.
	 *
	 * @param array $event Event payload.
	 * @return string
	 */
	private function render_email( array $event ) {
		$colors = array(
			'critical' => '#d63638',
			'warning'  => '#dba617',
			'info'     => '#2271b1',
		);

		$color = isset( $colors[ $event['severity'] ] ) ? $colors[ $event['severity'] ] : '#2271b1';

		ob_start();
		?>
		<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1d2327;">
			<div style="border-left:4px solid <?php echo esc_attr( $color ); ?>;padding:12px 16px;background:#f6f7f7;">
				<p style="margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:<?php echo esc_attr( $color ); ?>;font-weight:600;">
					<?php echo esc_html( strtoupper( $event['severity'] ) ); ?> · <?php echo esc_html( $event['label'] ); ?>
				</p>
				<p style="margin:8px 0 0;font-size:15px;line-height:1.5;"><?php echo esc_html( $event['message'] ); ?></p>
			</div>
			<table style="width:100%;border-collapse:collapse;margin-top:16px;font-size:14px;">
				<tr><td style="padding:6px 0;color:#646970;"><?php esc_html_e( 'Site', 'server-pulse' ); ?></td><td style="padding:6px 0;text-align:right;"><?php echo esc_html( $event['site_name'] ); ?></td></tr>
				<tr><td style="padding:6px 0;color:#646970;"><?php esc_html_e( 'Rule', 'server-pulse' ); ?></td><td style="padding:6px 0;text-align:right;"><?php echo esc_html( $event['label'] ); ?></td></tr>
				<tr><td style="padding:6px 0;color:#646970;"><?php esc_html_e( 'Time (UTC)', 'server-pulse' ); ?></td><td style="padding:6px 0;text-align:right;"><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $event['occurred_at'] ) ); ?></td></tr>
			</table>
			<p style="margin-top:20px;">
				<a href="<?php echo esc_url( $event['admin_url'] ); ?>" style="display:inline-block;background:<?php echo esc_attr( $color ); ?>;color:#fff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;">
					<?php esc_html_e( 'Open Server Pulse', 'server-pulse' ); ?>
				</a>
			</p>
			<p style="margin-top:24px;font-size:12px;color:#646970;">
				<?php
				printf(
					/* translators: %s: site URL. */
					esc_html__( 'Sent by Server Pulse for %s.', 'server-pulse' ),
					esc_html( $event['site_url'] )
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Generic webhook channel (JSON POST).
	 *
	 * @param array $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send_webhook( array $event ) {
		$url = Server_Pulse_Settings::alert_secret( 'webhook_url' );

		return $this->post_json( $url, $event );
	}

	/**
	 * Slack incoming webhook.
	 *
	 * @param array $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send_slack( array $event ) {
		$url = Server_Pulse_Settings::alert_secret( 'slack_webhook' );

		return $this->post_json(
			$url,
			array(
				'text' => $event['subject'] . "\n" . $event['message'],
			)
		);
	}

	/**
	 * Discord webhook.
	 *
	 * @param array $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send_discord( array $event ) {
		$url = Server_Pulse_Settings::alert_secret( 'discord_webhook' );

		return $this->post_json(
			$url,
			array(
				'content' => '**' . $event['subject'] . "**\n" . $event['message'],
			)
		);
	}

	/**
	 * Telegram bot channel.
	 *
	 * @param array $event Event payload.
	 * @return bool|WP_Error
	 */
	private function send_telegram( array $event ) {
		$alerts    = Server_Pulse_Settings::get( 'alerts', array() );
		$token     = Server_Pulse_Settings::alert_secret( 'telegram_token' );
		$chat      = isset( $alerts['telegram_chat'] ) ? $alerts['telegram_chat'] : '';
		$telegram  = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage';

		if ( '' === $token || '' === $chat ) {
			return new WP_Error( 'server_pulse_telegram_config', __( 'Telegram is not fully configured.', 'server-pulse' ) );
		}

		$response = wp_remote_post(
			$telegram,
			array(
				'timeout' => 15,
				'body'    => array(
					'chat_id' => $chat,
					'text'    => $event['subject'] . "\n" . $event['message'],
				),
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * POST a JSON payload and interpret the response.
	 *
	 * @param string $url  Destination URL.
	 * @param array  $body Payload.
	 * @return bool|WP_Error
	 */
	private function post_json( $url, array $body ) {
		if ( '' === $url ) {
			return new WP_Error( 'server_pulse_missing_url', __( 'The channel URL is empty.', 'server-pulse' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * Turn a WP HTTP response into a boolean or error.
	 *
	 * @param array|WP_Error $response Response.
	 * @return bool|WP_Error
	 */
	private function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error(
			'server_pulse_http_error',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The channel responded with HTTP %d.', 'server-pulse' ),
				$code
			)
		);
	}
}
