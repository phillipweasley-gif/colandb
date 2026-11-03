<?php
/**
 * One call that says how the intake is doing, for troubleshooting:
 *   cti_diagnostics()  →  array
 * Versions, where each secret comes from (never the value), settings,
 * what Telegram says about the bot and the webhook, queue counts, recent
 * problems and ignored channels. Read-only, apart from asking Telegram
 * (getMe, getWebhookInfo) when $ask_telegram is true.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Diagnostics {

	public static function report( $ask_telegram = true ) {
		global $wpdb;
		$s   = CTI_Config::all();
		$out = array(
			'plugin'   => CTI_VERSION,
			'calendar' => defined( 'CEC_VERSION' ) ? CEC_VERSION : 'not active',
			'calendar_ready' => cti_calendar_ready(),
			'secrets'  => array(
				'bot_token'      => CTI_Config::secret_source( 'COLANDB_TG_BOT_TOKEN' ) ? CTI_Config::secret_source( 'COLANDB_TG_BOT_TOKEN' ) : 'missing',
				'webhook_secret' => CTI_Config::secret_source( 'COLANDB_TG_WEBHOOK_SECRET' ) ? CTI_Config::secret_source( 'COLANDB_TG_WEBHOOK_SECRET' ) : 'missing',
				'claude_key'     => CTI_Config::secret_source( 'COLANDB_INTAKE_ANTHROPIC_KEY' ) ? CTI_Config::secret_source( 'COLANDB_INTAKE_ANTHROPIC_KEY' ) : 'missing',
			),
			'dry_run'  => (bool) $s['dry_run'],
			'reader'   => $s['extractor'] . ' / ' . $s['model'] . ' / ' . $s['effort'],
			'channels' => CTI_Config::channels_text( $s['channels'] ),
			'notify'   => CTI_Config::notify_emails(),
			'webhook_address' => CTI_Receiver::webhook_url(),
			'next_run' => wp_next_scheduled( CTI_Processor::HOOK ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( CTI_Processor::HOOK ) ) . ' UTC' : 'not scheduled',
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
		if ( $ask_telegram && CTI_Config::bot_token() ) {
			$me = CTI_Telegram::call( 'getMe' );
			$out['bot'] = is_wp_error( $me ) ? 'error: ' . $me->get_error_message() : '@' . $me['username'] . ' (id ' . $me['id'] . ')';
			$info = CTI_Telegram::call( 'getWebhookInfo' );
			if ( is_wp_error( $info ) ) {
				$out['telegram_webhook'] = 'error: ' . $info->get_error_message();
			} else {
				$out['telegram_webhook'] = array(
					'url'             => isset( $info['url'] ) ? $info['url'] : '',
					'matches_site'    => isset( $info['url'] ) && $info['url'] === CTI_Receiver::webhook_url(),
					'pending_updates' => isset( $info['pending_update_count'] ) ? (int) $info['pending_update_count'] : 0,
					'allowed_updates' => isset( $info['allowed_updates'] ) ? $info['allowed_updates'] : array(),
					'last_error'      => ! empty( $info['last_error_message'] ) ? CTI_Log::redact( $info['last_error_message'] ) . ' at ' . gmdate( 'Y-m-d H:i', (int) $info['last_error_date'] ) . ' UTC' : '',
				);
			}
		}
		foreach ( array( 'posts', 'entries' ) as $t ) {
			$out[ $t ] = array();
			foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . CTI_Install::table( $t ) . ' GROUP BY status', ARRAY_A ) as $r ) {
				$out[ $t ][ $r['status'] ] = (int) $r['n'];
			}
		}
		$out['last_received'] = $wpdb->get_var( 'SELECT MAX(received_at) FROM ' . CTI_Install::table( 'updates' ) );
		$out['problems']      = $wpdb->get_results( 'SELECT id, channel, status, attempts, reason, updated_at FROM ' . CTI_Install::table( 'posts' ) . " WHERE status IN ('retry','failed') ORDER BY id DESC LIMIT 10", ARRAY_A );
		$out['recent_log']    = $wpdb->get_results( 'SELECT post_id, entry_id, action, LEFT(detail, 200) AS detail, created_at FROM ' . CTI_Install::table( 'log' ) . ' ORDER BY id DESC LIMIT 15', ARRAY_A );
		$out['ignored_chats'] = array_values( (array) get_option( 'cti_unknown_chats', array() ) );
		return $out;
	}
}

function cti_diagnostics( $ask_telegram = true ) {
	return CTI_Diagnostics::report( $ask_telegram );
}
