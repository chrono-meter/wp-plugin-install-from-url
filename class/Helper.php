<?php
namespace CmPluginInstallFromURL;

use CmPluginInstallFromURL\Dependency\z4kn4fein\SemVer\Version;


class Helper {
	public static function remote_json( string $url, array $args = array() ) {
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$code    = wp_remote_retrieve_response_code( $response );
			$message = wp_remote_retrieve_response_message( $response );
			$error   = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( JSON_ERROR_NONE === json_last_error() ) {
				$code    = $error['code'] ?? $code;
				$message = $error['message'] ?? $message;
			}

			return new \WP_Error(
				$code,
				sprintf( '%s on request to %s', $message, $url ),
			);
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'json_decode_error', json_last_error_msg() );
		}

		return $result;
	}

	public static function is_prerelease_version( string $version ): bool {
		$version = ltrim( $version, 'v' );

		try {
			return Version::parse( $version )->isPreRelease();
		} catch ( \Throwable $e ) {
			// Non semver version. Cannot determine pre-release status.
			return false;
		}
	}
}
