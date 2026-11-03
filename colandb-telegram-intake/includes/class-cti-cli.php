<?php
/**
 * WP-CLI: wp colandb-intake <command>
 *
 *   status                 Settings, secrets (set/missing only) and queue counts.
 *   set-webhook            Point Telegram at this site's webhook address.
 *   delete-webhook         Stop webhook delivery (needed before polling).
 *   poll [--once] [--timeout=<s>]
 *                          Long polling for local development (no webhook).
 *   process [--limit=<n>]  Work through the queue now.
 *   retry <post-id>        Put a failed post back in the queue.
 *   extract --text=<text> [--image=<file>]
 *                          Dry-run the reader on sample input; prints the
 *                          proposed calendar fields. Sends nothing anywhere
 *                          except the configured reader.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_CLI {

	public function status() {
		global $wpdb;
		$s = CTI_Config::all();
		WP_CLI::line( 'Dry run: ' . ( $s['dry_run'] ? 'ON (nothing is sent to the calendar)' : 'off' ) );
		WP_CLI::line( 'Reader: ' . $s['extractor'] . ' (' . $s['model'] . ', effort ' . $s['effort'] . ')' );
		WP_CLI::line( 'Channels: ' . ( $s['channels'] ? str_replace( "\n", '; ', CTI_Config::channels_text( $s['channels'] ) ) : 'none' ) );
		WP_CLI::line( 'Bot token: ' . ( CTI_Config::bot_token() ? 'set' : 'missing' ) . ' · webhook secret: ' . ( strlen( CTI_Config::webhook_secret() ) >= 32 ? 'set' : 'missing' ) . ' · Claude key: ' . ( CTI_Config::anthropic_key() ? 'set' : 'missing' ) );
		WP_CLI::line( 'Calendar: ' . ( cti_calendar_ready() ? 'ready' : 'needs Community Events Calendar ' . CTI_MIN_CEC_VERSION . '+' ) );
		WP_CLI::line( 'Webhook address: ' . CTI_Receiver::webhook_url() );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . CTI_Install::table( 'posts' ) . ' GROUP BY status', ARRAY_A ) as $r ) {
			WP_CLI::line( 'posts ' . $r['status'] . ': ' . $r['n'] );
		}
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . CTI_Install::table( 'entries' ) . ' GROUP BY status', ARRAY_A ) as $r ) {
			WP_CLI::line( 'entries ' . $r['status'] . ': ' . $r['n'] );
		}
	}

	/** Everything cti_diagnostics() reports, as JSON. */
	public function diagnose() {
		WP_CLI::line( wp_json_encode( cti_diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** @subcommand set-webhook */
	public function set_webhook( $args, $assoc ) {
		$url = isset( $assoc['url'] ) ? $assoc['url'] : CTI_Receiver::webhook_url();
		$r   = CTI_Telegram::set_webhook( $url, ! empty( $assoc['drop-pending'] ) );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Webhook set to ' . $url );
	}

	/** @subcommand delete-webhook */
	public function delete_webhook() {
		$r = CTI_Telegram::call( 'deleteWebhook' );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Webhook removed.' );
	}

	public function poll( $args, $assoc ) {
		$info = CTI_Telegram::call( 'getWebhookInfo' );
		if ( ! is_wp_error( $info ) && ! empty( $info['url'] ) ) {
			WP_CLI::error( 'A webhook is set; Telegram will not answer polling until `wp colandb-intake delete-webhook`.' );
		}
		$timeout = isset( $assoc['timeout'] ) ? max( 0, (int) $assoc['timeout'] ) : 25;
		do {
			$offset  = (int) get_option( 'cti_poll_offset', 0 );
			$updates = CTI_Telegram::call(
				'getUpdates',
				array(
					'offset'          => $offset,
					'timeout'         => $timeout,
					'allowed_updates' => CTI_Receiver::UPDATE_TYPES,
				),
				$timeout + 15
			);
			if ( is_wp_error( $updates ) ) {
				WP_CLI::warning( $updates->get_error_message() );
				sleep( 5 );
				continue;
			}
			foreach ( (array) $updates as $u ) {
				WP_CLI::line( 'update ' . (int) $u['update_id'] . ': ' . CTI_Receiver::receive( $u ) );
				update_option( 'cti_poll_offset', (int) $u['update_id'] + 1, false );
			}
			if ( $updates ) {
				CTI_Processor::run();
			}
		} while ( empty( $assoc['once'] ) );
	}

	public function process( $args, $assoc ) {
		$n = CTI_Processor::run( isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0 );
		WP_CLI::success( $n . ' post(s) processed.' );
	}

	public function retry( $args ) {
		if ( empty( $args[0] ) || ! CTI_Processor::get_post( (int) $args[0] ) ) {
			WP_CLI::error( 'Give a post ID from the intake queue.' );
		}
		CTI_Processor::editor_retry( (int) $args[0] );
		WP_CLI::success( 'Queued.' );
	}

	public function extract( $args, $assoc ) {
		$images = array();
		if ( ! empty( $assoc['image'] ) ) {
			$bytes = file_get_contents( $assoc['image'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$size  = $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $size || ! in_array( $size['mime'], (array) CTI_Config::get( 'image_types' ), true ) || strlen( $bytes ) > (int) CTI_Config::get( 'max_image_bytes' ) ) {
				WP_CLI::error( 'Not an accepted image (type or size).' );
			}
			$images[] = array( 'bytes' => $bytes, 'mime' => $size['mime'] );
		}
		$text  = isset( $assoc['text'] ) ? (string) $assoc['text'] : '';
		$types = get_terms( array( 'taxonomy' => 'cec_event_type', 'hide_empty' => false, 'fields' => 'names' ) );
		$out   = CTI_Extractors::run( array( 'text' => $text, 'links' => CTI_Receiver::links( $text, array() ), 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'channel' => 'CLI test', 'images' => $images, 'event_types' => is_wp_error( $types ) ? array() : $types, 'site_timezone' => wp_timezone_string() ) );
		if ( is_wp_error( $out ) ) {
			WP_CLI::error( $out->get_error_message() );
		}
		if ( ! $out['is_event'] ) {
			WP_CLI::line( 'Not an event: ' . $out['not_event_reason'] );
			return;
		}
		foreach ( $out['events'] as $ev ) {
			$norm = CTI_Normalizer::entry( $ev, array( 'post_url' => '', 'channel' => 'CLI test', 'body' => $text, 'links' => CTI_Receiver::links( $text, array() ), 'post_date' => gmdate( 'Y-m-d H:i:s' ) ) );
			WP_CLI::line( wp_json_encode( array( 'submittable' => $norm['ok'], 'missing' => $norm['missing'], 'fields' => $norm['fields'], 'notes' => $norm['notes'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}
}

WP_CLI::add_command( 'colandb-intake', 'CTI_CLI' );
