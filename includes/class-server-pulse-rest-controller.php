<?php
/**
 * REST API endpoints.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes server metrics over the WordPress REST API.
 */
class Server_Pulse_Rest_Controller {

	/**
	 * Namespace.
	 */
	const NAMESPACE = 'server-pulse/v1';

	/**
	 * Collector.
	 *
	 * @var Server_Pulse_Collector
	 */
	private $collector;

	/**
	 * Repository.
	 *
	 * @var Server_Pulse_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Server_Pulse_Collector  $collector  Collector.
	 * @param Server_Pulse_Repository $repository Repository.
	 */
	public function __construct( Server_Pulse_Collector $collector, Server_Pulse_Repository $repository ) {
		$this->collector  = $collector;
		$this->repository = $repository;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/snapshot',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_snapshot' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 7,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Snapshot endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_snapshot( $request ) {
		$force = (bool) $request->get_param( 'force' );

		return rest_ensure_response( $this->collector->get_snapshot( $force ) );
	}

	/**
	 * History endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_history( $request ) {
		$days = (int) $request->get_param( 'days' );

		return rest_ensure_response(
			array(
				'days'   => $days,
				'series' => $this->repository->get_dashboard_series( $days ),
			)
		);
	}
}
