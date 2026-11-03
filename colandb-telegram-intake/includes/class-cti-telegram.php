<?php
/**
 * The official Telegram Bot API, nothing else: no user accounts, no
 * MTProto. Error messages are redacted, since a request URL contains the
 * bot token.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Telegram {

	/** @return mixed|WP_Error The "result" field. */
	public static function call( $method, $params = array(), $timeout = 20 ) {
		$token = CTI_Config::bot_token();
		if ( '' === $token ) {
			return new WP_Error( 'cti_no_token', __( 'COLANDB_TG_BOT_TOKEN is not set.', 'cti' ) );
		}
		$response = wp_remote_post(
			CTI_Config::telegram_base() . '/bot' . $token . '/' . $method,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( (object) $params ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cti_retry', CTI_Log::redact( 'Telegram ' . $method . ': ' . $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['ok'] ) ) {
			$desc = is_array( $body ) && isset( $body['description'] ) ? (string) $body['description'] : 'HTTP ' . $code;
			return new WP_Error( ( 429 === $code || $code >= 500 ) ? 'cti_retry' : 'cti_telegram', CTI_Log::redact( 'Telegram ' . $method . ': ' . $desc ) );
		}
		return $body['result'];
	}

	/**
	 * Downloads a file the bot can see. The size is checked before and
	 * after download; the download URL (which holds the token) is never
	 * returned or logged.
	 *
	 * @return string|WP_Error The bytes.
	 */
	public static function download( $file_id, $max_bytes ) {
		$file = self::call( 'getFile', array( 'file_id' => (string) $file_id ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( empty( $file['file_path'] ) ) {
			return new WP_Error( 'cti_file', __( 'Telegram did not return a file path (files over 20 MB cannot be downloaded by bots).', 'cti' ) );
		}
		if ( isset( $file['file_size'] ) && (int) $file['file_size'] > $max_bytes ) {
			return new WP_Error( 'cti_too_big', __( 'The image is larger than the configured limit.', 'cti' ) );
		}
		$response = wp_remote_get(
			CTI_Config::telegram_base() . '/file/bot' . CTI_Config::bot_token() . '/' . ltrim( (string) $file['file_path'], '/' ),
			array(
				'timeout'             => 30,
				'limit_response_size' => $max_bytes + 1,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cti_retry', CTI_Log::redact( 'Telegram file download: ' . $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( $code >= 500 ? 'cti_retry' : 'cti_file', 'Telegram file download: HTTP ' . $code );
		}
		$bytes = wp_remote_retrieve_body( $response );
		if ( strlen( $bytes ) > $max_bytes ) {
			return new WP_Error( 'cti_too_big', __( 'The image is larger than the configured limit.', 'cti' ) );
		}
		return $bytes;
	}

	public static function set_webhook( $url, $drop_pending = false ) {
		$secret = CTI_Config::ensure_webhook_secret();
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,256}$/', $secret ) ) {
			return new WP_Error( 'cti_secret', __( 'COLANDB_TG_WEBHOOK_SECRET must be 32-256 characters of A-Z, a-z, 0-9, _ and -.', 'cti' ) );
		}
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'cti_https', __( 'Telegram only sends webhooks to https:// addresses.', 'cti' ) );
		}
		return self::call(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => $secret,
				'allowed_updates'      => CTI_Receiver::UPDATE_TYPES,
				'drop_pending_updates' => (bool) $drop_pending,
				'max_connections'      => 5,
			)
		);
	}
}
