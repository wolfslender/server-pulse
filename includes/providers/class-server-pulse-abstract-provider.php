<?php
/**
 * Base provider with shared helpers.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convenience base class for providers.
 */
abstract class Server_Pulse_Abstract_Provider implements Server_Pulse_Provider_Interface {

	/**
	 * PHP memory metrics, available everywhere.
	 *
	 * @return array
	 */
	protected function php_memory_metrics() {
		$limit = Server_Pulse_Util::to_bytes( (string) ini_get( 'memory_limit' ) );
		$used  = memory_get_usage( true );
		$peak  = memory_get_peak_usage( true );

		return array(
			'php_memory_used'    => $used,
			'php_memory_peak'    => $peak,
			'php_memory_limit'   => $limit,
			'php_memory_percent' => $limit > 0 ? Server_Pulse_Util::clamp_percent( $used / $limit * 100 ) : 0,
		);
	}

	/**
	 * Build a normalized snapshot.
	 *
	 * @param array $metrics Metric map.
	 * @param array $notes   Notes.
	 * @return array
	 */
	protected function snapshot( array $metrics, array $notes = array() ) {
		return array(
			'source'    => $this->get_id(),
			'label'     => $this->get_label(),
			'available' => true,
			'metrics'   => array_merge( $this->php_memory_metrics(), $metrics ),
			'notes'     => array_values( array_filter( $notes ) ),
		);
	}

	/**
	 * Build an unavailable snapshot.
	 *
	 * @param string $reason Reason.
	 * @return array
	 */
	protected function unavailable( $reason = '' ) {
		return array(
			'source'    => $this->get_id(),
			'label'     => $this->get_label(),
			'available' => false,
			'metrics'   => array(),
			'notes'     => array( $reason ),
		);
	}
}
