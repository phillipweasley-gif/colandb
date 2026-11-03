<?php
/**
 * Editor notifications: one short email per processing run listing what
 * needs a look, sent to the addresses in Settings (or the site admin).
 * Nothing is emailed in dry-run mode. Problems (a failed post, a bad API
 * key) go to the site admin address, at most once per 6 hours each.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Notify {

	private static $items  = array();
	private static $alerts = array();

	const KINDS = array(
		'submitted'     => 'Waiting for approval in the calendar',
		'needs_info'    => 'Needs details before it can go in the calendar',
		'duplicate'     => 'Possible duplicate',
		'update_review' => 'Source post edited after review; changes need a look',
		'updated'       => 'Updated from an edited source post (still waiting for approval)',
	);

	public static function add( $kind, $title, $detail, $link ) {
		self::$items[] = compact( 'kind', 'title', 'detail', 'link' );
	}

	public static function alert( $message ) {
		self::$alerts[] = CTI_Log::redact( (string) $message );
	}

	public static function reset() {
		self::$items  = array();
		self::$alerts = array();
	}

	public static function queue_url( $entry_id = 0 ) {
		$args = array( 'post_type' => 'cec_event', 'page' => CTI_Admin::PAGE );
		if ( $entry_id ) {
			$args['entry'] = (int) $entry_id;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	public static function flush() {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( self::$items && ! CTI_Config::dry_run() ) {
			$lines = array( 'The Telegram intake found events to review. Nothing is published until an editor approves it.', '' );
			foreach ( self::KINDS as $kind => $heading ) {
				$rows = wp_list_filter( self::$items, array( 'kind' => $kind ) );
				if ( ! $rows ) {
					continue;
				}
				$lines[] = $heading . ':';
				foreach ( $rows as $r ) {
					$lines[] = '- ' . $r['title'] . ( $r['detail'] ? ' (' . $r['detail'] . ')' : '' );
					$lines[] = '  ' . $r['link'];
				}
				$lines[] = '';
			}
			$lines[] = 'Review queue: ' . self::queue_url();
			$count   = count( self::$items );
			/* translators: 1: site name, 2: number of items */
			$subject = sprintf( _n( '[%1$s] Telegram intake: %2$d item to review', '[%1$s] Telegram intake: %2$d items to review', $count, 'cti' ), $site, $count );
			wp_mail( CTI_Config::notify_emails(), $subject, implode( "\n", $lines ) );
		}
		foreach ( array_unique( self::$alerts ) as $alert ) {
			$key = 'cti_alert_' . md5( $alert );
			if ( get_transient( $key ) ) {
				continue;
			}
			set_transient( $key, 1, 6 * HOUR_IN_SECONDS );
			wp_mail(
				get_option( 'admin_email' ),
				/* translators: %s: site name */
				sprintf( __( '[%s] Telegram intake needs attention', 'cti' ), $site ),
				$alert . "\n\nNothing was lost: the post stays in the intake queue and can be retried.\n" . self::queue_url()
			);
		}
		self::reset();
	}
}
