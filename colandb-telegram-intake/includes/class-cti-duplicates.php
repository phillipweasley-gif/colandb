<?php
/**
 * Possible-duplicate detection. Nothing is merged automatically: a match
 * sends the new entry to the review queue with the evidence.
 *
 * Looks at every calendar event except trashed ones (pending, in review,
 * rejected, published, past and cancelled) and at intake entries that have
 * not become events yet. Signals and points:
 *
 *   same official/ticket/online link (tracking parameters removed)  +60
 *   same flyer image (identical bytes)                              +40
 *   post text nearly identical (word overlap >= 70%)                 +25
 *   same start date                                                  +30
 *   date ranges overlap                                              +25
 *   start dates one day apart                                        +10
 *   title words overlap >= 80% (after normalizing)                   +20
 *   title words overlap >= 50%                                       +10
 *   venue/address words overlap >= 60%                               +15
 *   organizer words overlap >= 60%                                   +10
 *
 * A candidate is flagged when the total reaches the threshold (default 50)
 * AND at least one anchor holds: the dates are the same, overlap or are a
 * day apart; or a link matches; or the flyer or text is the same. So a
 * title alone (20 at most) never matches, and the same recurring event on
 * another date (different edition: title + venue + organizer = 45, no
 * date anchor) is not flagged unless the link or flyer is the same.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Duplicates {

	const TRACKING = '/^(utm_.*|fbclid|gclid|dclid|msclkid|mc_cid|mc_eid|igshid|si|ref|ref_src|ref_url|_ga|_gl|s|t)$/i';

	const ABBREVIATIONS = array(
		'&' => 'and', 'w/' => 'with', 'feat' => 'featuring', 'ft' => 'featuring', 'mtg' => 'meeting', 'mtng' => 'meeting',
		'intl' => 'international', 'assoc' => 'association', 'nite' => 'night', 'tonite' => 'tonight', 'bday' => 'birthday',
		'b-day' => 'birthday', 'xmas' => 'christmas', 'cbus' => 'columbus', 'cols' => 'columbus', 'oh' => 'ohio', 'mon' => 'monday',
		'tue' => 'tuesday', 'tues' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday', 'thur' => 'thursday', 'thurs' => 'thursday',
		'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday', 'st' => 'street', 'ave' => 'avenue', 'rd' => 'road',
	);

	const STOPWORDS = array( 'the', 'a', 'an', 'of', 'at', 'with', 'and', 'for', 'in', 'on', 'to', 'presents', 'featuring', 'by', 'our', 'your', 'join', 'us' );

	/**
	 * @param array $match       CTI_Normalizer::entry()['match'].
	 * @param int   $post_id     The cti_posts row being checked (its own entries are skipped).
	 * @param int[] $skip_events Events this entry already belongs to.
	 * @return array[] kind, id, event_id, title, start, status, score, reasons[], link; best first.
	 */
	public static function find( $match, $post_id, $skip_events = array() ) {
		$threshold = (int) CTI_Config::get( 'duplicate_threshold' );
		$out       = array();

		if ( function_exists( 'cec_intake_candidates' ) ) {
			$events = cec_intake_candidates(
				array(
					'start' => $match['start_date'],
					'end'   => $match['end_date'],
					'urls'  => self::url_variants( $match['urls'] ),
					'days'  => 3,
				)
			);
			foreach ( $events as $ev ) {
				if ( in_array( (int) $ev['id'], array_map( 'intval', $skip_events ), true ) ) {
					continue;
				}
				$other = array(
					'title'      => $ev['cec_title'],
					'start_date' => substr( $ev['cec_start'], 0, 10 ),
					'end_date'   => $ev['cec_end'] ? substr( $ev['cec_end'], 0, 10 ) : substr( $ev['cec_start'], 0, 10 ),
					'venue'      => trim( $ev['venue_name'] . ' ' . $ev['cec_venue_custom_address'] . ' ' . $ev['cec_venue_custom_city'] ),
					'organizer'  => trim( $ev['cec_organizers'] . ' ' . $ev['cec_host_org_name'] . ' ' . implode( ' ', $ev['org_names'] ) ),
					'urls'       => array_filter( array( $ev['cec_official_website_url'], $ev['cec_more_info_url'], $ev['cec_rsvp_url'], $ev['cec_online_url'] ) ),
					'source_urls' => $ev['source_urls'],
				);
				$posts = self::intake_posts_for_event( (int) $ev['id'] );
				$other['text']       = implode( "\n", wp_list_pluck( $posts, 'body' ) );
				$other['image_hash'] = implode( ' ', array_filter( wp_list_pluck( $posts, 'image_hash' ) ) );
				$scored              = self::score( $match, $other, $threshold );
				if ( $scored ) {
					$status = 'publish' === $ev['post_status'] ? 'published' : ( 'draft' === $ev['post_status'] ? 'rejected/draft' : $ev['post_status'] );
					if ( 'scheduled' !== $ev['event_status'] ) {
						$status .= ', ' . $ev['event_status'];
					}
					$out[] = array_merge(
						$scored,
						array(
							'kind'     => 'event',
							'id'       => (int) $ev['id'],
							'event_id' => (int) $ev['id'],
							'title'    => $ev['cec_title'],
							'start'    => $ev['cec_start'],
							'status'   => $status,
							'link'     => $ev['edit_link'],
						)
					);
				}
			}
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.id, e.post_id, e.status, e.title, e.start_date, e.proposed, p.body, p.image_hash, p.post_url FROM ' . CTI_Install::table( 'entries' ) . ' e JOIN ' . CTI_Install::table( 'posts' ) . " p ON p.id = e.post_id
				WHERE e.post_id <> %d AND e.event_id = 0 AND e.status IN ('needs_info','possible_duplicate','dry_run','waiting') AND e.created_at > %s
				ORDER BY e.id DESC LIMIT 500",
				(int) $post_id,
				gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$proposed = json_decode( (string) $row['proposed'], true );
			if ( empty( $proposed['match'] ) ) {
				continue;
			}
			$other               = $proposed['match'];
			$other['text']       = (string) $row['body'];
			$other['image_hash'] = (string) $row['image_hash'];
			$scored              = self::score( $match, $other, $threshold );
			if ( $scored ) {
				$out[] = array_merge(
					$scored,
					array(
						'kind'     => 'intake',
						'id'       => (int) $row['id'],
						'event_id' => 0,
						'title'    => $row['title'],
						'start'    => $row['start_date'],
						'status'   => 'intake: ' . $row['status'],
						'link'     => $row['post_url'],
					)
				);
			}
		}

		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return array_slice( $out, 0, 5 );
	}

	/** @return array|null score + reasons, or null when not a likely match. */
	public static function score( $a, $b, $threshold ) {
		$score   = 0;
		$reasons = array();
		$anchor  = false;

		$ua = array_filter( array_map( array( __CLASS__, 'normalize_url' ), (array) $a['urls'] ) );
		$ub = array_filter( array_map( array( __CLASS__, 'normalize_url' ), (array) $b['urls'] ) );
		$same_url = array_values( array_intersect( $ua, $ub ) );
		if ( $same_url ) {
			$score    += 60;
			$anchor    = true;
			$reasons[] = 'same event/ticket link (' . $same_url[0] . ')';
		}
		if ( ! empty( $a['image_hash'] ) && ! empty( $b['image_hash'] ) && false !== strpos( (string) $b['image_hash'], (string) $a['image_hash'] ) ) {
			$score    += 40;
			$anchor    = true;
			$reasons[] = 'same flyer image';
		}
		$ta = self::words( isset( $a['text'] ) ? $a['text'] : '', false );
		$tb = self::words( isset( $b['text'] ) ? $b['text'] : '', false );
		if ( count( $ta ) >= 8 && self::overlap( $ta, $tb ) >= 0.7 ) {
			$score    += 25;
			$anchor    = true;
			$reasons[] = 'post text nearly identical';
		}

		$dates = self::date_relation( $a['start_date'], $a['end_date'], $b['start_date'], $b['end_date'] );
		if ( 'same' === $dates ) {
			$score    += 30;
			$anchor    = true;
			$reasons[] = 'same start date';
		} elseif ( 'overlap' === $dates ) {
			$score    += 25;
			$anchor    = true;
			$reasons[] = 'date ranges overlap';
		} elseif ( 'near' === $dates ) {
			$score    += 10;
			$anchor    = true;
			$reasons[] = 'start dates one day apart';
		} elseif ( 'apart' === $dates ) {
			$reasons[] = 'different dates (may be another edition)';
		}

		$title = self::overlap( self::words( $a['title'], true ), self::words( $b['title'], true ) );
		if ( $title >= 0.8 ) {
			$score    += 20;
			$reasons[] = 'title matches (' . round( $title * 100 ) . '% of words)';
		} elseif ( $title >= 0.5 ) {
			$score    += 10;
			$reasons[] = 'title similar (' . round( $title * 100 ) . '% of words)';
		}
		$venue = self::overlap( self::words( $a['venue'], true ), self::words( $b['venue'], true ) );
		if ( $venue >= 0.6 ) {
			$score    += 15;
			$reasons[] = 'same venue';
		}
		$org = self::overlap( self::words( $a['organizer'], true ), self::words( $b['organizer'], true ) );
		if ( $org >= 0.6 ) {
			$score    += 10;
			$reasons[] = 'same organizer';
		}

		if ( $score < $threshold || ! $anchor ) {
			return null;
		}
		return array( 'score' => $score, 'reasons' => $reasons );
	}

	public static function normalize_url( $url ) {
		$p = wp_parse_url( trim( (string) $url ) );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$host = preg_replace( '/^(www\.|m\.)/', '', strtolower( $p['host'] ) );
		$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		$keep = array();
		if ( ! empty( $p['query'] ) ) {
			parse_str( $p['query'], $q );
			foreach ( $q as $k => $val ) {
				if ( ! preg_match( self::TRACKING, (string) $k ) ) {
					$keep[ $k ] = $val;
				}
			}
			ksort( $keep );
		}
		return $host . $path . ( $keep ? '?' . http_build_query( $keep ) : '' );
	}

	/** Spellings of a link as it may have been typed into the calendar. */
	private static function url_variants( $urls ) {
		$out = array();
		foreach ( (array) $urls as $url ) {
			$n = self::normalize_url( $url );
			if ( ! $n ) {
				continue;
			}
			foreach ( array( 'https://', 'http://', 'https://www.', 'http://www.' ) as $prefix ) {
				$out[] = $prefix . $n;
				$out[] = $prefix . $n . '/';
			}
			$out[] = $url;
		}
		return array_values( array_unique( $out ) );
	}

	/** same | overlap | near | apart | unknown */
	public static function date_relation( $a_start, $a_end, $b_start, $b_end ) {
		if ( ! $a_start || ! $b_start ) {
			return 'unknown';
		}
		$a_end = $a_end ? $a_end : $a_start;
		$b_end = $b_end ? $b_end : $b_start;
		if ( $a_start === $b_start ) {
			return 'same';
		}
		if ( $a_start <= $b_end && $b_start <= $a_end ) {
			return 'overlap';
		}
		if ( abs( strtotime( $a_start ) - strtotime( $b_start ) ) <= DAY_IN_SECONDS ) {
			return 'near';
		}
		return 'apart';
	}

	public static function words( $text, $expand ) {
		$text = strtolower( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$text = str_replace( array( '&', 'w/' ), array( ' and ', ' with ' ), $text );
		$text = preg_replace( '/[^\p{L}\p{N}\s-]+/u', ' ', $text );
		$out  = array();
		foreach ( preg_split( '/[\s-]+/u', $text ) as $w ) {
			if ( '' === $w ) {
				continue;
			}
			if ( $expand && isset( self::ABBREVIATIONS[ $w ] ) ) {
				$w = self::ABBREVIATIONS[ $w ];
			}
			if ( $expand && in_array( $w, self::STOPWORDS, true ) ) {
				continue;
			}
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	/** Shared words as a share of the smaller set (0-1). */
	public static function overlap( $a, $b ) {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		return count( array_intersect( $a, $b ) ) / min( count( $a ), count( $b ) );
	}

	private static function intake_posts_for_event( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT p.body, p.image_hash FROM ' . CTI_Install::table( 'entries' ) . ' e JOIN ' . CTI_Install::table( 'posts' ) . ' p ON p.id = e.post_id WHERE e.event_id = %d LIMIT 5', $event_id ), ARRAY_A );
	}
}
