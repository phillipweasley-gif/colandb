<?php
/**
 * The audit trail, and the one place text is cleaned before it is logged
 * or shown: bot tokens, API keys, the webhook secret and Telegram file
 * download paths never reach the log, an email or the screen.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Log {

	public static function add( $post_id, $entry_id, $action, $detail = '' ) {
		global $wpdb;
		$detail = is_string( $detail ) ? $detail : wp_json_encode( $detail );
		$wpdb->insert(
			CTI_Install::table( 'log' ),
			array(
				'post_id'    => (int) $post_id,
				'entry_id'   => (int) $entry_id,
				'action'     => substr( sanitize_key( $action ), 0, 40 ),
				'detail'     => self::redact( mb_substr( (string) $detail, 0, 20000 ) ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	public static function for_post( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CTI_Install::table( 'log' ) . ' WHERE post_id = %d ORDER BY id ASC', (int) $post_id ), ARRAY_A );
	}

	public static function redact( $text ) {
		$text = (string) $text;
		foreach ( array( CTI_Config::bot_token(), CTI_Config::webhook_secret(), CTI_Config::anthropic_key() ) as $secret ) {
			if ( strlen( $secret ) >= 8 ) {
				$text = str_replace( $secret, '[secret]', $text );
			}
		}
		// Anything shaped like a bot token or a Telegram file URL.
		$text = preg_replace( '#/bot\d+:[A-Za-z0-9_-]+#', '/bot[secret]', $text );
		$text = preg_replace( '#\b\d{6,12}:[A-Za-z0-9_-]{30,}\b#', '[secret]', $text );
		$text = preg_replace( '#/file/bot[^\s"\']*#', '/file/[hidden]', $text );
		$text = preg_replace( '#sk-ant-[A-Za-z0-9_-]+#', '[secret]', $text );
		return $text;
	}
}
