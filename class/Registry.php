<?php
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Filter;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Action;


class Registry {
	public static array $domain_handlers = array();
	public static array $defaults        = array(
		'cache_expires_in' => 1 * HOUR_IN_SECONDS,  // Default cache expiration time for plugin update info.
	);

	#[Action( 'cm_plugin_install_from_url.register' )]
	public static function register( array $args ) {
		$args = array_merge( self::$defaults, $args );

		if ( empty( $args['domain'] ) ) {
			throw new \InvalidArgumentException( 'Property "domain" is required.' );
		}
		if ( empty( $args['handler'] ) || ! is_callable( $args['handler'] ) ) {
			throw new \InvalidArgumentException( 'Property "handler" is required and must be callable.' );
		}
		if ( ! is_int( $args['cache_expires_in'] ) ) {
			throw new \InvalidArgumentException( 'Property "cache_expires_in" must be an integer.' );
		}

		$domain = $args['domain'];

		static::$domain_handlers[ $domain ] = $args;

		add_filter( 'update_plugins_' . $domain, array( Hooks::class, 'fetch_plugin_update_info' ), 10, 3 );
	}

	protected static function is_registered( ?string $domain ): bool {
		return ! empty( $domain ) && \is_callable( static::$domain_handlers[ $domain ]['handler'] ?? null );
	}

	#[Filter( 'cm_plugin_install_from_url.get_release' )]
	public static function get_release( $result, string $repository_url, array $options = array() ) {
		$domain = wp_parse_url( $repository_url, PHP_URL_HOST );
		if ( ! static::is_registered( $domain ) ) {
			return new \WP_Error( 'no_handler', sprintf( 'No handler registered for domain: %s', $domain ) );
		}

		$cache_expires_in = static::$domain_handlers[ $domain ]['cache_expires_in'];
		$cache_key        = 'update_plugins_' . $repository_url;
		$cache            = $cache_expires_in > 0 ? get_transient( $cache_key ) : false;
		if ( false !== $cache ) {
			return $cache;
		}

		try {
			$result = call_user_func( static::$domain_handlers[ $domain ]['handler'], $repository_url, $options );
		} catch ( \Throwable $e ) {
			// Translate any unexpected error into WP_Error and not cache exceptions.
			return new \WP_Error( $e->getCode(), $e->getMessage() );
		}

		if ( $cache_expires_in ) {
			set_transient( $cache_key, $result, $cache_expires_in );
		}

		return $result;
	}
}
