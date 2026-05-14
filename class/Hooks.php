<?php
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Filter;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Action;
use CmPluginInstallFromURL\Dependency\z4kn4fein\SemVer\Version;


class Hooks {
	public const OPTIONS_KEY        = 'cm_plugin_install_from_url.plugins_options';
	public const REGISTRY_PAGE_SLUG = 'cm-plugin-install-from-url-registry';

	#[Action( 'admin_menu' )]
	public static function add_registry_submenu_page(): void {
		add_options_page(
			__( 'Registry', 'wp-plugin-install-from-url' ),
			__( 'Registry', 'wp-plugin-install-from-url' ),
			'manage_options',
			self::REGISTRY_PAGE_SLUG,
			array( self::class, 'render_registry_page' )
		);
	}

	public static function render_registry_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Registry', 'wp-plugin-install-from-url' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( Handlers\GitHub::SETTINGS_GROUP ); ?>
				<?php do_settings_sections( self::REGISTRY_PAGE_SLUG ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function get_plugin_options( string $plugin_file ): array {
		$all_options = get_option( self::OPTIONS_KEY, array() );

		return $all_options[ $plugin_file ] ?? array();
	}

	public static function set_plugin_options( string $plugin_file, array $options ): void {
		$all_options                 = get_option( self::OPTIONS_KEY, array() );
		$all_options[ $plugin_file ] = array_merge( $all_options[ $plugin_file ] ?? array(), $options );
		update_option( self::OPTIONS_KEY, $all_options );
	}

	#[Filter( 'install_plugins_tabs' )]
	public static function add_url_tab( $tabs ) {
		$tabs['url'] = 'URL';

		return $tabs;
	}

	/**
	 * Render the form to input plugin URL.
	 * At "wp-admin/plugin-install.php".
	 *
	 * @see \install_plugins_upload()
	 */
	#[Action( 'install_plugins_' . 'url' )]  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
	public static function render_url_form() {
		?>
		<form action="<?php echo esc_url( self_admin_url( 'update.php' ) ); ?>">
			<input type="hidden" name="action" value="install-from-url" />

			<?php wp_nonce_field( 'plugin-upload' ); ?>

			<p>
				<input type="url" name="plugin_repository_url" placeholder="<?php echo esc_attr__( 'https://github.com/owner/repo' ); ?>" class="regular-text" required />

				<!-- Allow pre-release versions -->
				<label>
					<input type="checkbox" name="allow_prerelease" value="1" />
					<?php echo esc_html__( 'Allow pre-release versions', 'wp-plugin-install-from-url' ); ?>
				</label>

				<input type="submit" class="button" value="<?php echo esc_attr__( 'Install' ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Handle the form submission and install the plugin from URL.
	 *
	 * @link https://github.com/WordPress/WordPress/blob/6.9.4/wp-admin/update.php#L149-L207
	 */
	#[Action( 'update-custom_' . 'install-from-url' )]  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
	public static function handle_url_install() {
		if ( ! current_user_can( 'upload_plugins' ) ) {
			wp_die( __( 'Sorry, you are not allowed to install plugins on this site.' ) );
		}

		check_admin_referer( 'plugin-upload' );

		$url = wp_unslash( $_GET['plugin_repository_url'] ?? '' );  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$options = array(
			'allow_prerelease' => ! empty( $_GET['allow_prerelease'] ),
		);

		$release_data = apply_filters(
			'cm_plugin_install_from_url.get_release',
			new \WP_Error( 'no_release', __( 'No release found.' ) ),
			$url,
			$options,
		);
		if ( is_wp_error( $release_data ) ) {
			wp_die( $release_data->get_error_message() );
		}

		// Used in the HTML title tag.
		$title        = __( 'Upload Plugin' );
		$parent_file  = 'plugins.php';
		$submenu_file = 'plugin-install.php';

		require_once ABSPATH . 'wp-admin/admin-header.php';

		$overwrite = isset( $_GET['overwrite'] ) ? sanitize_text_field( $_GET['overwrite'] ) : '';  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$overwrite = in_array( $overwrite, array( 'update-plugin', 'downgrade-plugin' ), true ) ? $overwrite : '';

		$upgrader = new \Plugin_Upgrader(
			new \Plugin_Installer_Skin(
				array(
					'title'     => sprintf( __( 'Installing plugin from uploaded file: %s' ), $release_data['download_link'] ),  // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
					'type'      => 'upload',
					'nonce'     => 'plugin-upload',
					'url'       => remove_query_arg( array( 'overwrite' ), $_SERVER['REQUEST_URI'] ),  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'overwrite' => $overwrite,
				)
			)
		);

		$plugins = array_keys( get_plugins() );

		$installation_result = $upgrader->install( $release_data['download_link'], array( 'overwrite_package' => $overwrite ) );

		if ( ! is_wp_error( $installation_result ) && $installation_result ) {
			// NOTE: There is no way to know the `slug` of installed plugin.

			$installed_plugins = array_diff( array_keys( get_plugins() ), $plugins );

			// Save options.
			foreach ( $installed_plugins as $plugin_file ) {
				static::set_plugin_options(
					$plugin_file,
					$url,
					array(
						...$options,
						'repository_url' => $url,
					)
				);
			}
		}

		require_once ABSPATH . 'wp-admin/admin-footer.php';
	}

	/**
	 * Support auto updates.
	 *
	 * NOTE: Requires `Update URI` header in plugins.
	 * NOTE: There is no way to hook in [`get_plugin_data()`](https://developer.wordpress.org/reference/functions/get_plugin_data/) via [`get_file_data()`](https://developer.wordpress.org/reference/functions/get_file_data/).
	 * NOTE: Only `\wp_update_plugins()` updates transient data `update_plugins`.
	 *
	 * @see \wp_update_plugins()
	 * @link https://nickgreen.info/add-autoupdates-to-your-wordpress-plugin-thats-hosted-on-github-using-update_plugins_hostname/
	 * @link https://github.com/passatgt/simple-wp-plugin-update
	 */
	public static function fetch_plugin_update_info( $result, $plugin_data, $plugin_file ) {
		$release_data = apply_filters(
			'cm_plugin_install_from_url.get_release',
			new \WP_Error( 'no_release', __( 'No release found.' ) ),
			$plugin_data['UpdateURI'],
			static::get_plugin_options( $plugin_file ),
		);
		if ( is_wp_error( $release_data ) ) {
			if ( 'no_handler' === $release_data->get_error_code() ) {
				return $result;
			}

			return $result;
		}

		try {
			$is_update_available = Version::greaterThan( $plugin_data['Version'], $release_data['version'] );
		} catch ( \Throwable $e ) {
			// Non semver version. Cannot determine update availability.
			return $result;
		}

		if ( $is_update_available ) {
			return array(
				/**
				 * In reality, WordPress plugin slugs are not deterministic.
				 * WordPress.org can dictate the slug, but depending on the environment,
				 * site administrators or plugin authors can also decide it.
				 * It's ambiguous, in short.
				 *
				 * @see \get_plugin_data(), \WP_Plugin_Dependencies::convert_to_slug()
				 */
				'slug'    => dirname( plugin_basename( $plugin_file ) ),
				'version' => $release_data['version'],
				'url'     => $release_data['link'],
				'package' => $release_data['download_link'],
			);
		}

		return $result;
	}

	#[Filter( 'plugins_api' )]
	public static function add_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' === $action ) {
			$plugin_slug = $args->slug;

			// Get all installed plugins.
			foreach ( get_plugins() as $plugin_file => $plugin_data ) {
				if ( ! str_starts_with( $plugin_file, $plugin_slug . '/' ) ) {
					continue;
				}

				$release_data = apply_filters(
					'cm_plugin_install_from_url.get_release',
					new \WP_Error( 'no_release', __( 'No release found.' ) ),
					$plugin_data['UpdateURI'],
					static::get_plugin_options( $plugin_file )
				);

				if ( ! is_wp_error( $release_data ) ) {
					return (object) array(
						...$release_data,
						'external' => true,
						'slug'     => $plugin_slug,
						'name'     => $plugin_data['Name'],
						'homepage' => $release_data['link'],
						'sections' => $release_data['sections'] ?? array(),  // "changelog" key is important.
					);
				}
			}
		}

		return $result;
	}

	/**
	 * todo: Support dependencies.
	 * NOTE: "Requires Plugins" header in only supports "wordpress.org" slug. See `\WP_Plugin_Dependencies`.
	 * NOTE: filter:upgrader_pre_install calls before package extraction. No usable arguments!
	 * NOTE: filter:upgrader_source_selection( string $source, string $remote_source, \WP_Upgrader $upgrader, any $extra )
	 * NOTE: filter:upgrader_post_install calls after old package deletion.
	 * function_exists( 'set_time_limit' ) && set_time_limit( 600 );
	 */
}
