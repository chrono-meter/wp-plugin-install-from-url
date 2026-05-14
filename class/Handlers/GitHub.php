<?php
namespace CmPluginInstallFromURL\Handlers;

use CmPluginInstallFromURL\Helper;
use CmPluginInstallFromURL\Hooks;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Filter;
use CmPluginInstallFromURL\Dependency\ChronoMeter\WpDeclarativeHook\Action;


class GitHub {
	public const OPTION_PERSONAL_ACCESS_TOKEN = 'cm_plugin_install_from_url_github_personal_access_token';
	public const SETTINGS_GROUP               = 'cm_plugin_install_from_url.registry';
	public const SETTINGS_SECTION             = 'cm_plugin_install_from_url.github';

	#[Action( 'init' )]
	public static function init() {
		do_action(
			'cm_plugin_install_from_url.register',
			array(
				'domain'           => 'github.com',
				'handler'          => array( static::class, 'call' ),
				'cache_expires_in' => HOUR_IN_SECONDS,
			),
		);
	}

	#[Action( 'admin_init' )]
	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_PERSONAL_ACCESS_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			self::SETTINGS_SECTION,
			'GitHub',
			'__return_empty_string',
			Hooks::REGISTRY_PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_PERSONAL_ACCESS_TOKEN,
			__( 'Personal access token', 'wp-plugin-install-from-url' ),
			function (): void {
				$token = (string) get_option( self::OPTION_PERSONAL_ACCESS_TOKEN, '' );
				?>
				<input
					type="password"
					name="<?php echo esc_attr( self::OPTION_PERSONAL_ACCESS_TOKEN ); ?>"
					value="<?php echo esc_attr( $token ); ?>"
					class="regular-text"
					autocomplete="off"
				/>
				<p class="description">
					<?php echo esc_html__( 'Used for authenticated GitHub API requests.', 'wp-plugin-install-from-url' ); ?>
					<ol>
						<li><?php echo __( 'Open <a href="https://github.com/settings/personal-access-tokens" target="_blank" rel="noopener noreferrer">GitHub personal access tokens settings page</a>.', 'wp-plugin-install-from-url' ); ?></li>
						<li><?php echo esc_html__( 'Generate a fine-grained personal access token.', 'wp-plugin-install-from-url' ); ?></li>
						<li><?php echo esc_html__( 'Set "Repository access" to "All repositories".', 'wp-plugin-install-from-url' ); ?></li>
						<li><?php echo esc_html__( 'Set "Permissions" to "Read-only" for "Contents".', 'wp-plugin-install-from-url' ); ?></li>
						<li><?php echo esc_html__( 'Finally, you\'ll get the token. Copy and paste it here.', 'wp-plugin-install-from-url' ); ?></li>
					</ol>
				</p>
				<?php
			},
			Hooks::REGISTRY_PAGE_SLUG,
			self::SETTINGS_SECTION
		);
	}

	protected static function get_access_token(): string {
		return (string) get_option( self::OPTION_PERSONAL_ACCESS_TOKEN, '' );
	}

	public static function call( string $repository_url, array $options = array() ) {
		if ( ! str_starts_with( $repository_url, 'https://github.com/' ) ) {
			return new \WP_Error( 'invalid_url', 'Invalid GitHub repository URL.' );
		}

		$args = array();

		$pat = static::get_access_token();
		if ( ! empty( $pat ) ) {
			$args['headers'] = array(
				'Authorization' => 'Bearer ' . $pat,
			);
		}

		/**
		 * NOTE: GitHub API has a rate limit of 60 requests per hour for unauthenticated requests.
		 *
		 * @link https://docs.github.com/rest/using-the-rest-api/rate-limits-for-the-rest-api#primary-rate-limit-for-unauthenticated-users
		 */
		$rest_base = str_replace( 'github.com/', 'api.github.com/repos/', $repository_url );

		foreach ( range( 1, 10 ) as $page ) {
			// Get github tagged versions. https://docs.github.com/rest/releases/releases
			$releases = Helper::remote_json(
				add_query_arg(
					array(
						'page'     => $page,
						'per_page' => 100,
					),
					$rest_base . '/releases',
				),
				$args,
			);

			if ( is_wp_error( $releases ) ) {
				if ( 403 === $releases->get_error_code() && str_contains( $releases->get_error_message(), 'API rate limit exceeded' ) ) {
					// Rate limit exceeded. Return no update info, but do not cause an error.
					return new \WP_Error( 403, '<a href="https://docs.github.com/rest/using-the-rest-api/rate-limits-for-the-rest-api#primary-rate-limit-for-unauthenticated-users" target="_blank" rel="noopener noreferrer">Anonymous requests to GitHub API have a rate limit of 60 requests per hour.</a> Please try again later.' );
				}

				return $releases;
			}

			foreach ( (array) $releases as $release ) {
				if ( ! empty( $release['draft'] ) ) {
					continue;
				}

				if ( empty( $options['allow_prerelease'] ) && ! empty( $release['prerelease'] ) ) {
					continue;
				}

				foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
					if ( str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
						if ( empty( $options['allow_prerelease'] ) && Helper::is_prerelease_version( $release['tag_name'] ) ) {
							continue;
						}

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
								// 'description' => $repo['description'] ?? '',
								// 'installation' => '',
								// 'faq' => '',
								// 'screenshots' => '',
								'changelog' => $release['body'],
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
		}

		return new \WP_Error( 400, _x( 'No installable package found.', 'error', 'wp-plugin-install-from-url' ) );
	}
}
