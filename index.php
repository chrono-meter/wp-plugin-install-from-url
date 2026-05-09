<?php
/*
 * Plugin Name:       wp-plugin-install-from-url
 * Description:       Install a plugin from a URL (e.g., GitHub repository, Zip file).
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Itou Kousuke
 * Author URI:        mailto:chrono-meter@gmx.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-plugin-install-from-url
 * Domain Path:       /languages
 * Update URI:        https://github.com/chrono-meter/wp-plugin-install-from-url
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Hook;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Filter;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Action;


defined( 'ABSPATH' ) || die();
require_once __DIR__ . '/third-party/vendor/autoload.php';


class Helper {
	public static function remote_json( string $url ) {
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'fetch_error', wp_remote_retrieve_response_message( $response ) );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_decode_error', json_last_error_msg() );
		}

		return $result;
	}
}


class App {

	public static array $domain_handlers = array();

	#[Action( 'cm_plugin_install_from_url.register_domain_handler' )]
	public static function register_domain_handler( string $domain, callable $handler ) {
		static::$domain_handlers[ $domain ] = $handler;

		add_filter( 'update_plugins_' . $domain, array( static::class, 'fetch_plugin_update_info' ), 10, 3 );
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
				<input type="url" name="plugin_repository_url" placeholder="<?php echo esc_attr__( 'https://github.com/username/repository' ); ?>" class="regular-text" required />

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

		$url    = wp_unslash( $_GET['plugin_repository_url'] ?? '' );  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$domain = wp_parse_url( $url, PHP_URL_HOST );

		if ( \is_callable( static::$domain_handlers[ $domain ] ?? null ) ) {
			$release_data = call_user_func( static::$domain_handlers[ $domain ], $url );
		} else {
			$release_data = array(
				'download_link' => $url,
			);
		}

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

		$upgrader->install( $release_data['download_link'], array( 'overwrite_package' => $overwrite ) );

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
		$domain = wp_parse_url( $plugin_data['UpdateURI'], PHP_URL_HOST );

		if ( ! \is_callable( static::$domain_handlers[ $domain ] ?? null ) ) {
			return $result;
		}

		$release_data = call_user_func( static::$domain_handlers[ $domain ], $plugin_data['UpdateURI'] );

		if (
			! is_wp_error( $release_data )
			&&
			version_compare( $plugin_data['Version'], $release_data['version'], '<' )
		) {
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

				$domain = wp_parse_url( $plugin_data['UpdateURI'], PHP_URL_HOST );

				if ( ! \is_callable( static::$domain_handlers[ $domain ] ?? null ) ) {
					continue;
				}

				$release_data = call_user_func( static::$domain_handlers[ $domain ], $plugin_data['UpdateURI'] );

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


Hook::install_static_methods( App::class );


/**
 * Register a domain handler for GitHub repositories.
 */
do_action(
	'cm_plugin_install_from_url.register_domain_handler',
	'github.com',
	function ( string $repository_url ) {
		if ( ! str_starts_with( $repository_url, 'https://github.com/' ) ) {
			return new WP_Error( 'invalid_url', 'Invalid GitHub repository URL.' );
		}

		// Get github tagged versions. https://docs.github.com/rest/releases/releases
		$releases = Helper::remote_json( str_replace( 'github.com/', 'api.github.com/repos/', $repository_url ) . '/releases' );

		if ( is_wp_error( $releases ) ) {
			return $releases;
		}

		foreach ( (array) $releases as $release ) {
			if ( ! empty( $release['prerelease'] ) || ! empty( $release['draft'] ) ) {
				continue;
			}

			$repo = Helper::remote_json( str_replace( 'github.com/', 'api.github.com/repos/', $repository_url ) );
			if ( is_wp_error( $repo ) ) {
				$repo = array();
			}

			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
					return array(
						'author'          => $release['author']['login'] ?? '',
						'version'         => ltrim( $release['tag_name'], 'v' ),
						// 'requires' => 'WordPress version',
						// 'tested' => 'WordPress version',
						'last_updated'    => $release['published_at'],
						'link'            => $repository_url,
						// 'donate_link' => '',
						'download_link'   => $asset['browser_download_url'],
						'downloaded'      => $asset['download_count'] ?? 0,
						'active_installs' => 0,  // No way to know this. Why `wordpress.org` can know this?
						'changelog_link'  => $release['html_url'],

						/**
						 * Sections are displayed in the tabbed content area of the plugin details modal.
						 *
						 * @see \install_plugin_information()
						 */
						'sections'        => array(
							'description' => $repo['description'] ?? '',
							// 'installation' => '',
							// 'faq' => '',
							// 'screenshots' => '',
							'changelog'   => $release['body'],
							// 'reviews' => '',
							// 'other_notes' => '',
							// 'addtional_sections_are_also_supported' => 'But section title will not be translated.',
						),

						// 'banners'         => array(
						//  'low'  => 'banner image url (low resolution)',
						//  'high' => 'banner image url (high resolution)',
						// ),
					);
				}
			}
		}

		return new WP_Error( 'no_zip_asset', _x( 'No zip asset found in the latest release.', 'error', 'wp-plugin-install-from-url' ) );
	}
);


/**
 * For development purpose, add "Check for updates" link in the plugins list table.
 */
if ( wp_is_development_mode( 'plugin' ) ) {
	add_filter(
		'views_plugins',
		fn ( $views ) => array(
			...$views,
			'check-updates' => sprintf(
				'<a href="%s">%s</a>',
				esc_attr( admin_url( 'plugins.php?force-check=1' ) ),
				__( 'Check for updates', 'wp-plugin-install-from-url' ),
			),
		)
	);

	add_action(
		'load-plugins.php',
		function () {
			if ( isset( $_GET['force-check'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$faker = function ( $result ) {
					$result->last_checked = 0;
					return $result;
				};
				add_filter( 'site_transient_' . 'update_plugins', $faker );  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
				wp_update_plugins();
				remove_filter( 'site_transient_' . 'update_plugins', $faker );  // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
			}
		},
		0
	);
}
