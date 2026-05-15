<?php
/*
 * Plugin Name:       wp-plugin-install-from-url
 * Description:       Install a plugin from a URL (e.g., GitHub repository, Zip file).
 * Version:           0.3.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Itou Kousuke
 * Author URI:        mailto:chrono-meter@gmx.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-plugin-install-from-url
 * Domain Path:       /languages
 * Update URI:        https://github.com/chrono-meter/wp-plugin-install-from-url
 */
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Hook;


defined( 'ABSPATH' ) || die();

require_once __DIR__ . '/third-party/vendor/autoload.php';

/**
 * Install a class loader for a given namespace prefix.
 *
 * @param string $namespace_prefix Namespace prefix.
 * @param string $basedir          Base directory.
 */
function install_class_loader( string $namespace_prefix, string $basedir ): void {
	spl_autoload_register(
		function ( string $class ) use ( $namespace_prefix, $basedir ): void {  // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
			$prefix = $namespace_prefix . '\\';

			if ( \str_starts_with( $class, $prefix ) ) {
				$relative_path = \substr( $class, \strlen( $prefix ) );
				$relative_path = \str_replace( '\\', DIRECTORY_SEPARATOR, $relative_path );
				$absolute_path = $basedir . '/' . $relative_path . '.php';

				if ( file_exists( $absolute_path ) ) {
					require_once $absolute_path;
				}
			}
		}
	);
}

install_class_loader( __NAMESPACE__, __DIR__ . '/class' );
Hook::install_static_methods( Registry::class );
Hook::install_static_methods( Hooks::class );
if ( ! wp_is_development_mode( 'plugin' ) ) {
	Hook::install_static_methods( DevHooks::class );
}
Hook::install_static_methods( Handlers\GitHub::class );
