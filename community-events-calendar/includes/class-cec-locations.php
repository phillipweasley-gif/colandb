<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where an event is, as country / state / city, for the calendar's
 * location filter (1.49.0).
 *
 * Uses the event's own city, region and country fields (or its venue's)
 * when they are filled in. Many events (imports especially) only have a
 * one-line address such as "Chicago, Illinois, USA" or "49 Parsons Ave,
 * Columbus, OH 43215", so the address is read too:
 *   - "(…)" notes and anything after ";" are dropped;
 *   - the last part is matched against known countries ("USA", "U.S.",
 *     "United States", "UK", …);
 *   - the part before it against that country's states/provinces (names
 *     or codes, with a ZIP or postcode allowed after them);
 *   - a US state with no country means the United States;
 *   - the part before that is the city ("744 Frebis Ave Columbus" → the
 *     words after the street type).
 * Names are normalised so "OH" and "Ohio", or "USA" and "United States",
 * are one choice. Nothing is saved: this only reads.
 */
class CEC_Locations {

	const CACHE = 'cec_location_options';

	/** The place list is rebuilt after any event or venue change. */
	public static function init() {
		add_action( 'save_post_cec_event', array( __CLASS__, 'flush' ), 999 );
		add_action( 'transition_post_status', array( __CLASS__, 'flush_on_status' ), 999, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush' ) );
		add_action( 'edited_cec_venue', array( __CLASS__, 'flush_all' ) );
		add_action( 'delete_cec_venue', array( __CLASS__, 'flush_all' ) );
	}

	public static function flush_on_status( $new, $old, $post ) {
		if ( $post && 'cec_event' === $post->post_type && $new !== $old ) {
			delete_transient( self::CACHE );
		}
	}

	public static function flush_all() {
		delete_transient( self::CACHE );
	}

	const US_STATES = array(
		'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
		'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
		'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
		'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
		'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
		'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
		'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
		'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
		'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
		'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico',
	);

	const CA_PROVINCES = array(
		'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
		'NS' => 'Nova Scotia', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan',
		'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'YT' => 'Yukon',
	);

	const UK_NATIONS = array( 'England', 'Scotland', 'Wales', 'Northern Ireland' );

	/** Lower-case spelling => the name shown. */
	const COUNTRIES = array(
		'usa' => 'United States', 'us' => 'United States', 'u.s.' => 'United States', 'u.s.a.' => 'United States',
		'united states' => 'United States', 'united states of america' => 'United States', 'america' => 'United States',
		'canada' => 'Canada', 'uk' => 'United Kingdom', 'u.k.' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
		'great britain' => 'United Kingdom', 'britain' => 'United Kingdom', 'england' => 'United Kingdom', 'scotland' => 'United Kingdom',
		'wales' => 'United Kingdom', 'northern ireland' => 'United Kingdom', 'ireland' => 'Ireland', 'germany' => 'Germany',
		'deutschland' => 'Germany', 'spain' => 'Spain', 'france' => 'France', 'italy' => 'Italy', 'netherlands' => 'Netherlands',
		'the netherlands' => 'Netherlands', 'holland' => 'Netherlands', 'belgium' => 'Belgium', 'czech republic' => 'Czech Republic',
		'czechia' => 'Czech Republic', 'austria' => 'Austria', 'switzerland' => 'Switzerland', 'denmark' => 'Denmark', 'sweden' => 'Sweden',
		'norway' => 'Norway', 'finland' => 'Finland', 'poland' => 'Poland', 'portugal' => 'Portugal', 'greece' => 'Greece',
		'australia' => 'Australia', 'new zealand' => 'New Zealand', 'mexico' => 'Mexico', 'brazil' => 'Brazil', 'japan' => 'Japan',
		'south africa' => 'South Africa', 'israel' => 'Israel', 'thailand' => 'Thailand',
	);

	const STREET_TYPES = 'st|street|ave|avenue|rd|road|blvd|boulevard|dr|drive|way|ln|lane|ct|court|pl|place|pkwy|parkway|hwy|highway|ter|terrace|cir|circle|sq|square|ne|nw|se|sw';

	/**
	 * @param array $data CEC_Event_Helper::data() for one event.
	 * @return array{country:string,region:string,city:string}
	 */
	public static function for_event( $data ) {
		if ( isset( $data['location_mode'] ) && 'online' === $data['location_mode'] ) {
			return array( 'country' => '', 'region' => '', 'city' => '' );
		}
		$country = self::country( isset( $data['country'] ) ? $data['country'] : '' );
		$region  = self::region( isset( $data['region'] ) ? $data['region'] : '', $country );
		$city    = self::clean_city( isset( $data['city'] ) ? $data['city'] : '' );
		if ( '' === $country || '' === $region || '' === $city ) {
			$parsed = self::parse( isset( $data['address'] ) ? $data['address'] : '' );
			if ( '' === $country ) {
				$country = $parsed['country'];
			}
			if ( '' === $region && ( '' === $parsed['country'] || $parsed['country'] === $country ) ) {
				$region = $parsed['region'];
			}
			if ( '' === $city && ( '' === $parsed['region'] || '' === $region || $parsed['region'] === $region ) ) {
				$city = $parsed['city'];
			}
		}
		if ( '' === $country && '' !== $region && in_array( $region, self::US_STATES, true ) ) {
			$country = 'United States';
		}
		return array( 'country' => $country, 'region' => $region, 'city' => $city );
	}

	/** Reads country / state / city out of a one-line address. */
	public static function parse( $text ) {
		$out  = array( 'country' => '', 'region' => '', 'city' => '' );
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\([^)]*\)/u', ' ', $text );
		$text = preg_split( '/;/', $text )[0];
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $text ) ), 'strlen' ) );
		if ( ! $parts ) {
			return $out;
		}
		// Country: the last part, when it is one.
		$last = self::country( end( $parts ) );
		if ( '' !== $last ) {
			$out['country'] = $last;
			$uk_nation      = in_array( ucwords( strtolower( end( $parts ) ) ), self::UK_NATIONS, true ) ? ucwords( strtolower( end( $parts ) ) ) : '';
			array_pop( $parts );
			if ( $uk_nation ) {
				$out['region'] = $uk_nation;
			}
		}
		// State/province: the next part back ("Maryland 21230", "OH 43215").
		if ( $parts && '' === $out['region'] ) {
			$candidate = trim( preg_replace( '/\s+[A-Z0-9]{3}\s?[A-Z0-9]{3}$|\s+\d{5}(-\d{4})?$/', '', end( $parts ) ) );
			$region    = self::region( $candidate, $out['country'] );
			if ( '' === $region && preg_match( '/^(.*\S)\s+([A-Za-z]{2})$/', $candidate, $m ) && self::region( $m[2], $out['country'] ) ) {
				// "Columbus OH" with no comma.
				$region = self::region( $m[2], $out['country'] );
				$parts[ count( $parts ) - 1 ] = $m[1];
				$candidate = '';
			}
			if ( '' === $region && preg_match( '/^(?:north|south|east|west|central|northern|southern|eastern|western|upstate|downstate)\s+(.+)$/i', $candidate, $m ) ) {
				// "South Florida" is the state, not a city.
				$region = self::region( $m[1], $out['country'] );
			}
			if ( '' !== $region ) {
				$out['region'] = $region;
				if ( '' !== $candidate ) {
					array_pop( $parts );
				}
			}
		}
		if ( '' === $out['country'] && '' !== $out['region'] ) {
			$out['country'] = in_array( $out['region'], self::US_STATES, true ) ? 'United States' : ( in_array( $out['region'], self::CA_PROVINCES, true ) ? 'Canada' : '' );
		}
		// City: the next part back, unless nothing above was recognised
		// (then the text may be a venue name, not a place).
		if ( $parts && ( '' !== $out['country'] || '' !== $out['region'] ) ) {
			$out['city'] = self::clean_city( end( $parts ) );
		}
		return $out;
	}

	/** "USA" → "United States"; unknown text is kept (trimmed) unless it's empty. */
	public static function country( $text ) {
		$key = strtolower( trim( (string) $text, " \t\n\r\0\x0B." ) );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );
		if ( '' === $key ) {
			return '';
		}
		foreach ( array( $key, $key . '.' ) as $k ) {
			if ( isset( self::COUNTRIES[ $k ] ) ) {
				return self::COUNTRIES[ $k ];
			}
		}
		return '';
	}

	/** "OH" or "ohio" → "Ohio" (US, Canada, UK nations); '' when not recognised. */
	public static function region( $text, $country = '' ) {
		$t = trim( (string) $text, " \t\n\r\0\x0B." );
		if ( '' === $t ) {
			return '';
		}
		$lists = array();
		if ( '' === $country || 'United States' === $country ) {
			$lists[] = self::US_STATES;
		}
		if ( '' === $country || 'Canada' === $country ) {
			$lists[] = self::CA_PROVINCES;
		}
		foreach ( $lists as $list ) {
			$code = strtoupper( $t );
			if ( 2 === strlen( $t ) && isset( $list[ $code ] ) ) {
				return $list[ $code ];
			}
			foreach ( $list as $name ) {
				if ( 0 === strcasecmp( $name, $t ) ) {
					return $name;
				}
			}
		}
		if ( ( '' === $country || 'United Kingdom' === $country ) ) {
			foreach ( self::UK_NATIONS as $nation ) {
				if ( 0 === strcasecmp( $nation, $t ) ) {
					return $nation;
				}
			}
		}
		return '';
	}

	/** "744 Frebis Ave Columbus" → "Columbus"; "Chicago O'Hare area" → "Chicago O'Hare". */
	public static function clean_city( $text ) {
		$t = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $t || preg_match( '/^(tbd|tba|n\/a|online|various)$/i', $t ) ) {
			return '';
		}
		if ( preg_match( '/^\d/', $t ) ) {
			if ( preg_match( '/\b(?:' . self::STREET_TYPES . ')\.?\s+([A-Za-z][A-Za-z .\'’-]+)$/i', $t, $m ) ) {
				$t = $m[1];
			} else {
				return ''; // A street address with no city in it.
			}
		}
		$t = preg_split( '#\s+/\s+|\s+&\s+| and #', $t )[0];
		$t = trim( preg_replace( '/\s+(metro area|area|metro)$/i', '', $t ) );
		$t = trim( preg_replace( '/^(greater|downtown)\s+/i', '', $t ) );
		return mb_strlen( $t ) > 40 ? '' : $t;
	}

	/**
	 * Every place that has a published event: country => region => city
	 * => number of events. Cached for 6 hours, and cleared when an event
	 * is saved.
	 */
	public static function options() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = array();
		$ids = get_posts(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$loc = self::for_event( CEC_Event_Helper::data( $id ) );
			if ( '' === $loc['country'] ) {
				continue;
			}
			$r = '' !== $loc['region'] ? $loc['region'] : '';
			$c = '' !== $loc['city'] ? $loc['city'] : '';
			if ( ! isset( $map[ $loc['country'] ][ $r ][ $c ] ) ) {
				$map[ $loc['country'] ][ $r ][ $c ] = 0;
			}
			$map[ $loc['country'] ][ $r ][ $c ]++;
		}
		ksort( $map );
		foreach ( $map as &$regions ) {
			ksort( $regions );
			foreach ( $regions as &$cities ) {
				ksort( $cities );
			}
		}
		unset( $regions, $cities );
		set_transient( self::CACHE, $map, 6 * HOUR_IN_SECONDS );
		return $map;
	}

	public static function flush( $post_id = 0 ) {
		if ( ! $post_id || 'cec_event' === get_post_type( $post_id ) ) {
			delete_transient( self::CACHE );
		}
	}

	/** The filter as given in a request: country, region, city (each may be ''). */
	public static function filter_from( $source ) {
		$get = static function ( $key ) use ( $source ) {
			return isset( $source[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $source[ $key ] ) ) ) : '';
		};
		return array(
			'country' => $get( 'cec_country' ),
			'region'  => $get( 'cec_region' ),
			'city'    => $get( 'cec_city' ),
		);
	}

	/** True when the event is in the chosen place (an empty filter matches everything). */
	public static function matches( $data, $filter ) {
		if ( '' === $filter['country'] && '' === $filter['region'] && '' === $filter['city'] ) {
			return true;
		}
		$loc = self::for_event( $data );
		foreach ( array( 'country', 'region', 'city' ) as $key ) {
			if ( '' !== $filter[ $key ] && 0 !== strcasecmp( $filter[ $key ], $loc[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}
}
