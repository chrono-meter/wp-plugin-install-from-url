<?php
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Filter;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Action;


class DevHooks {
	#[Filter( 'views_plugins' )]
	public static function add_test_view( $views ) {
		$views['check-updates'] = sprintf(
			'<a href="%s">%s</a>',
			esc_attr( admin_url( 'plugins.php?force-check=1' ) ),
			__( 'Check for updates', 'wp-plugin-install-from-url' ),
		);

		return $views;
	}

	#[Action( 'load-plugins.php' )]
	public static function force_check_updates() {
		if ( isset( $_GET['force-check'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$faker = function ( $result ) {
				$result->last_checked = 0;
				return $result;
			};
			add_filter( 'site_transient_' . 'update_plugins', $faker );  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
			wp_update_plugins();
			remove_filter( 'site_transient_' . 'update_plugins', $faker );  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
		}
	}
}
