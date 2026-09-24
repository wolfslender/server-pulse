<?php
/**
 * Hosting environment detection.
 *
 * @package ServerPulse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the hosting panel, managed platform or cloud provider the site
 * runs on by inspecting constants, filesystem paths, HTTP headers and DMI.
 *
 * Detection is best-effort and read-only: it never calls a remote API. It
 * tells the Advisor and the provider layer which integrations are relevant.
 */
class Server_Pulse_Host_Detector {

	/**
	 * Cached detection result.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Detect the current environment.
	 *
	 * @param bool $force Bypass the in-request cache.
	 * @return array {
	 *     @type string $id      Machine id (e.g. "plesk", "kinsta", "docker").
	 *     @type string $label   Human label.
	 *     @type string $type    panel|managed|cloud|container|unknown.
	 *     @type array  $signals Matched signals.
	 *     @type array  $matches All environments detected (first is primary).
	 * }
	 */
	public static function detect( $force = false ) {
		if ( ! $force && is_array( self::$cache ) ) {
			return self::$cache;
		}

		$matches = array();

		foreach ( self::signatures() as $signature ) {
			$signals = self::match( $signature );

			if ( $signals ) {
				$matches[] = array(
					'id'      => $signature['id'],
					'label'   => $signature['label'],
					'type'    => $signature['type'],
					'signals' => $signals,
				);
			}
		}

		if ( ! $matches ) {
			$matches[] = array(
				'id'      => 'unknown',
				'label'   => __( 'Unknown / generic host', 'server-pulse' ),
				'type'    => 'unknown',
				'signals' => array(),
			);
		}

		$primary = $matches[0];

		self::$cache = array(
			'id'      => $primary['id'],
			'label'   => $primary['label'],
			'type'    => $primary['type'],
			'signals' => $primary['signals'],
			'matches' => $matches,
		);

		return self::$cache;
	}

	/**
	 * Hosting environment signatures.
	 *
	 * Each signature declares its id, label, type and match rules. Rules are
	 * evaluated in order of priority as listed here.
	 *
	 * @return array
	 */
	private static function signatures() {
		return array(
			// --- Managed WordPress platforms (highest priority: containers). ---
			array(
				'id'     => 'wpengine',
				'label'  => 'WP Engine',
				'type'   => 'managed',
				'const'  => array( 'WPE_APIKEY', 'WPE_BILLING_ID', 'WPE_HELPER_PATH' ),
				'path'   => array( '/nas/content/live' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'flywheel',
				'label'  => 'Flywheel',
				'type'   => 'managed',
				'const'  => array( 'FLYWHEEL_APP_NAME' ),
				'path'   => array( '/fw-data', '/flywheel' ),
				'env'    => array( 'FLYWHEEL' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'kinsta',
				'label'  => 'Kinsta',
				'type'   => 'managed',
				'const'  => array( 'KINSTA_CACHE_ZONE', 'KINSTA_CDN' ),
				'path'   => array(),
				'env'    => array( 'KINSTA_ENVIRONMENT_TYPE', 'KINSTA_WP_ROOT' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'cloudways',
				'label'  => 'Cloudways',
				'type'   => 'managed',
				'const'  => array( 'CLOUDWAYS_SERVER_ID' ),
				'path'   => array(),
				'env'    => array( 'CLOUDWAYS' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'siteground',
				'label'  => 'SiteGround',
				'type'   => 'managed',
				'const'  => array( 'SG_PLUGIN_DIR', 'SG_CACHEPRESS_ENV' ),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'hostinger',
				'label'  => 'Hostinger',
				'type'   => 'managed',
				'const'  => array( 'HOSTINGER_MAIN_DOMAIN' ),
				'path'   => array(),
				'env'    => array( 'HOSTINGER' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'pressable',
				'label'  => 'Pressable / Automattic',
				'type'   => 'managed',
				'const'  => array( 'PRESSABLE_ACCOUNT', 'ATOMATIC_PRESSABLE' ),
				'path'   => array(),
				'env'    => array( 'PRESSABLE' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'wpcloud',
				'label'  => 'WordPress.com / WP Cloud',
				'type'   => 'managed',
				'const'  => array( 'IS_WPCOM', 'WPCOMSH_VERSION' ),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'pantheon',
				'label'  => 'Pantheon',
				'type'   => 'managed',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'PANTHEON_ENVIRONMENT', 'PANTHEON_SITE_NAME' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'platformsh',
				'label'  => 'Platform.sh / Upsun',
				'type'   => 'managed',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'PLATFORM_APPLICATION', 'PLATFORM_PROJECT', 'UPSUN_APPLICATION' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'rocketnet',
				'label'  => 'Rocket.net',
				'type'   => 'managed',
				'const'  => array( 'ROCKET_NET' ),
				'path'   => array(),
				'env'    => array( 'ROCKETNET' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'nexcess',
				'label'  => 'Nexcess / Liquid Web',
				'type'   => 'managed',
				'const'  => array( 'NEXCESS_ENVIRONMENT' ),
				'path'   => array( '/chroot', '/var/www/nexcess' ),
				'env'    => array( 'NEXCESS' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'raidboxes',
				'label'  => 'Raidboxes',
				'type'   => 'managed',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'RAIDBOXES', 'RAIDBOX' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'servebolt',
				'label'  => 'Servebolt',
				'type'   => 'managed',
				'const'  => array( 'SERVEBOLT_ENV' ),
				'path'   => array(),
				'env'    => array( 'SERVEBOLT' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'krystal',
				'label'  => 'Krystal',
				'type'   => 'managed',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'KRYSTAL_HOST' ),
				'vendor' => array(),
			),

			// --- Control panels. ---
			array(
				'id'     => 'cpanel',
				'label'  => 'cPanel / WHM',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/cpanel/cpanel', '/usr/local/cpanel/whostmgr' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'plesk',
				'label'  => 'Plesk',
				'type'   => 'panel',
				'const'  => array( 'PLESK_IS_WINDOWS_HOST' ),
				'path'   => array( '/usr/local/psa', '/opt/psa' ),
				'env'    => array( 'PLESK' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'directadmin',
				'label'  => 'DirectAdmin',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/directadmin' ),
				'env'    => array( 'DIRECTADMIN' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'ispconfig',
				'label'  => 'ISPConfig',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/ispconfig', '/var/www/clients' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'webmin',
				'label'  => 'Webmin / Virtualmin',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/etc/webmin', '/usr/share/webmin', '/etc/virtualmin' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'hestiacp',
				'label'  => 'HestiaCP',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/hestia' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'vestacp',
				'label'  => 'VestaCP',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/vesta' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'cwp',
				'label'  => 'CentOS Web Panel',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/cwp' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'cyberpanel',
				'label'  => 'CyberPanel',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/CyberCP', '/usr/local/cyberpanel' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'interworx',
				'label'  => 'InterWorx',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/local/interworx' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'cloudpanel',
				'label'  => 'CloudPanel',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/usr/share/cloudpanel', '/home/clp' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'aapanel',
				'label'  => 'aaPanel / BT Panel',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/www/server/panel' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'froxlor',
				'label'  => 'Froxlor',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/var/lib/froxlor' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'ajenti',
				'label'  => 'Ajenti',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/etc/ajenti' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'runcloud',
				'label'  => 'RunCloud',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/etc/runcloud', '/home/runcloud' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'gridpane',
				'label'  => 'GridPane',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/opt/gridpane' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'spinupwp',
				'label'  => 'SpinupWP',
				'type'   => 'panel',
				'const'  => array( 'SPINUPWP_SITE' ),
				'path'   => array( '/etc/spinupwp' ),
				'env'    => array( 'SPINUPWP' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'serverpilot',
				'label'  => 'ServerPilot',
				'type'   => 'panel',
				'const'  => array( 'SERVERPILOT_APP' ),
				'path'   => array( '/srv/users/serverpilot' ),
				'env'    => array( 'SERVERPILOT' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'ploi',
				'label'  => 'Ploi',
				'type'   => 'panel',
				'const'  => array( 'PLOI_SITE_ID' ),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'forge',
				'label'  => 'Laravel Forge',
				'type'   => 'panel',
				'const'  => array( 'FORGE_SITE_ID' ),
				'path'   => array( '/home/forge' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'enhance',
				'label'  => 'Enhance',
				'type'   => 'panel',
				'const'  => array(),
				'path'   => array( '/var/lib/enhance', '/chroot/enhance' ),
				'env'    => array(),
				'vendor' => array(),
			),

			// --- Cloud / VPS / containers. ---
			array(
				'id'     => 'kubernetes',
				'label'  => 'Kubernetes',
				'type'   => 'container',
				'const'  => array(),
				'path'   => array( '/var/run/secrets/kubernetes.io' ),
				'env'    => array( 'KUBERNETES_SERVICE_HOST' ),
				'vendor' => array(),
			),
			array(
				'id'     => 'docker',
				'label'  => 'Docker container',
				'type'   => 'container',
				'const'  => array(),
				'path'   => array( '/.dockerenv' ),
				'env'    => array(),
				'vendor' => array(),
			),
			array(
				'id'     => 'digitalocean',
				'label'  => 'DigitalOcean',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array( '/etc/digitalocean' ),
				'env'    => array( 'DIGITALOCEAN' ),
				'vendor' => array( 'digitalocean' ),
			),
			array(
				'id'     => 'aws',
				'label'  => 'Amazon Web Services',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'AWS_EXECUTION_ENV' ),
				'vendor' => array( 'amazon ec2', 'amazon' ),
			),
			array(
				'id'     => 'gcp',
				'label'  => 'Google Cloud',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array( 'GOOGLE_CLOUD_PROJECT' ),
				'vendor' => array( 'google' ),
			),
			array(
				'id'     => 'azure',
				'label'  => 'Microsoft Azure',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array( '/var/lib/waagent' ),
				'env'    => array( 'WEBSITE_SITE_NAME' ),
				'vendor' => array( 'microsoft corporation' ),
			),
			array(
				'id'     => 'linode',
				'label'  => 'Linode / Akamai',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array( 'linode' ),
			),
			array(
				'id'     => 'vultr',
				'label'  => 'Vultr',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array( 'vultr' ),
			),
			array(
				'id'     => 'hetzner',
				'label'  => 'Hetzner',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array( 'hetzner' ),
			),
			array(
				'id'     => 'upcloud',
				'label'  => 'UpCloud',
				'type'   => 'cloud',
				'const'  => array(),
				'path'   => array(),
				'env'    => array(),
				'vendor' => array( 'upcloud' ),
			),
		);
	}

	/**
	 * Evaluate a signature's match rules.
	 *
	 * @param array $signature Signature definition.
	 * @return string[] Matched signals (empty when no match).
	 */
	private static function match( array $signature ) {
		$signals = array();

		foreach ( $signature['const'] as $constant ) {
			if ( defined( $constant ) ) {
				$signals[] = 'constant:' . $constant;
			}
		}

		foreach ( $signature['env'] as $variable ) {
			$value = getenv( $variable );
			if ( false !== $value && '' !== $value ) {
				$signals[] = 'env:' . $variable;
			}
		}

		$docroot = self::docroot();

		foreach ( $signature['path'] as $path ) {
			if ( 0 === strpos( $path, '/' ) && @file_exists( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$signals[] = 'path:' . $path;
				continue;
			}

			if ( '' !== $docroot && false !== strpos( self::slashed( $docroot ), self::slashed( $path ) ) ) {
				$signals[] = 'docroot:' . $path;
			}
		}

		if ( $signature['vendor'] ) {
			$vendor = self::dmi_vendor();

			foreach ( $signature['vendor'] as $needle ) {
				if ( '' !== $vendor && false !== strpos( $vendor, $needle ) ) {
					$signals[] = 'vendor:' . $needle;
				}
			}
		}

		if ( $signature['vendor'] && ! $signals ) {
			// A DMI-only signature matches only through its vendor rule.
			return array();
		}

		return $signals;
	}

	/**
	 * Detect virtualization / hardware vendor from DMI.
	 *
	 * @return string
	 */
	private static function dmi_vendor() {
		$parts = array();

		foreach ( array( '/sys/class/dmi/id/sys_vendor', '/sys/class/dmi/id/product_name', '/sys/class/dmi/id/board_vendor' ) as $file ) {
			$value = Server_Pulse_Util::read_file( $file );
			if ( $value ) {
				$parts[] = strtolower( trim( $value ) );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Document root, normalized.
	 *
	 * @return string
	 */
	private static function docroot() {
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		return defined( 'ABSPATH' ) ? ABSPATH : '';
	}

	/**
	 * Normalize path separators for loose matching.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function slashed( $path ) {
		return trailingslashit( str_replace( '\\', '/', (string) $path ) );
	}

	/**
	 * All known signature ids and labels (for reference / UI).
	 *
	 * @return array<string,string>
	 */
	public static function catalogue() {
		$out = array();

		foreach ( self::signatures() as $signature ) {
			$out[ $signature['id'] ] = $signature['label'];
		}

		return $out;
	}
}