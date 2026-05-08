<?php
/*
 * Plugin Name:       wp-plugin-install-from-url
 * Description:       Install a plugin from a URL (e.g., GitHub repository, Zip file).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Itou Kousuke
 * Author URI:        mailto:chrono-meter@gmx.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-plugin-install-from-url
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/chrono-meter/wp-plugin-install-from-url
 */
defined( 'ABSPATH' ) || die();


add_filter(
	'install_plugins_tabs',
	function ( $tabs ) {
		$tabs['url'] = 'URL';

		return $tabs;
	}
);


add_action(
	'install_plugins_' . 'url',  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
	/**
	 * Render the form to input plugin URL.
	 *
	 * @see \install_plugins_upload()
	 */
	function () {
		?>
		<form action="<?php echo esc_url( self_admin_url( 'update.php' ) ); ?>">
			<input type="hidden" name="action" value="install-from-url" />

			<?php wp_nonce_field( 'plugin-upload' ); ?>

			<input type="url" name="plugin_repository_url" placeholder="<?php echo esc_attr__( 'https://github.com/username/repository' ); ?>" class="regular-text" required />

			<?php submit_button( __( 'Install' ) ); ?>
		</form>
		<?php
	},
);


add_action(
	'update-custom_' . 'install-from-url',  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
	/**
	 * Handle the form submission and install the plugin from URL.
	 *
	 * @link https://github.com/WordPress/WordPress/blob/6.9.4/wp-admin/update.php#L149-L207
	 */
	function () {
		if ( ! current_user_can( 'upload_plugins' ) ) {
			wp_die( __( 'Sorry, you are not allowed to install plugins on this site.' ) );
		}

		check_admin_referer( 'plugin-upload' );

		$url = wp_unslash( $_GET['plugin_repository_url'] ?? '' );  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( str_starts_with( $url, 'https://github.com/' ) ) {
			$download_url = ( function ( string $repository_url ) {
				$fetch = function ( $url ) {
					$response = wp_remote_get( $url );

					if ( is_wp_error( $response ) ) {
						return $response;
					} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
						return new WP_Error( 'fetch_error', wp_remote_retrieve_response_message( $response ) );
					}

					return json_decode( wp_remote_retrieve_body( $response ), true );
				};

				// Get github tagged versions. https://docs.github.com/rest/releases/releases
				$releases = $fetch( str_replace( 'github.com/', 'api.github.com/repos/', $repository_url ) . '/releases' );

				if ( is_wp_error( $releases ) ) {
					return $releases;
				}

				foreach ( (array) $releases as $release ) {
					if ( ! empty( $release['prerelease'] ) || ! empty( $release['draft'] ) ) {
						continue;
					}
					foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
						if ( str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
							return $asset['browser_download_url'];
						}
					}
				}

				return new WP_Error( 'no_zip_asset', _x( 'No zip asset found in the latest release.', 'error', 'wp-plugin-install-from-url' ) );
			} )( $url );
		} else {
			$download_url = $url;
		}

		if ( is_wp_error( $download_url ) ) {
			wp_die( $download_url->get_error_message() );
		}

		// Used in the HTML title tag.
		$title        = __( 'Upload Plugin' );
		$parent_file  = 'plugins.php';
		$submenu_file = 'plugin-install.php';

		require_once ABSPATH . 'wp-admin/admin-header.php';

		$overwrite = isset( $_GET['overwrite'] ) ? sanitize_text_field( $_GET['overwrite'] ) : '';  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$overwrite = in_array( $overwrite, array( 'update-plugin', 'downgrade-plugin' ), true ) ? $overwrite : '';

		$upgrader = new Plugin_Upgrader(
			new Plugin_Installer_Skin(
				array(
					'title'     => sprintf( __( 'Installing plugin from uploaded file: %s' ), $download_url ),  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
					'type'      => 'upload',
					'nonce'     => 'plugin-upload',
					'url'       => remove_query_arg( array( 'overwrite' ), $_SERVER['REQUEST_URI'] ),  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'overwrite' => $overwrite,
				)
			)
		);

		$upgrader->install( $download_url, array( 'overwrite_package' => $overwrite ) );

		require_once ABSPATH . 'wp-admin/admin-footer.php';
	}
);
