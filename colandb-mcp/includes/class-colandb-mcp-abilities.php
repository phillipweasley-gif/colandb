<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The connector's tools, registered as WordPress abilities (Abilities API,
 * core since 6.9). Each has a JSON input/output schema, which WordPress
 * validates, and a permission callback using the connector's capabilities.
 *
 * All of them are read-only. Events are read only through the Community
 * Events Calendar's public API (cec_get_public_event[s]) or, for events not
 * yet public, from the post itself with no submitter contact details. No
 * member data is read.
 *
 * None is marked `public`, so other MCP servers on the site (such as
 * Elementor's) don't offer them; they are served only by COLANDB_MCP_Server.
 */
class COLANDB_MCP_Abilities {

	const CATEGORY = 'colandb';

	/** Ability names, in the order the server lists them. */
	const NAMES = array(
		'colandb/list-events',
		'colandb/get-event',
		'colandb/list-pending-events',
		'colandb/site-health',
	);

	// Fallback for site-health when the updater isn't installed.
	const PLUGINS = array( 'community-events-calendar', 'community-member-planning', 'colandb-updater', 'colandb-mcp', 'cmp-hosting-check' );

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'colandb.com', 'colandb-mcp' ),
				'description' => __( 'Read-only tools for the colandb.com events calendar and site.', 'colandb-mcp' ),
			)
		);
	}

	private static function read_only() {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => false,
		);
	}

	public static function can_connect() {
		return current_user_can( COLANDB_MCP_Role::CAP_CONNECT );
	}

	public static function register() {
		$events_available = function_exists( 'cec_get_public_events' );

		$event_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'                  => array( 'type' => 'integer' ),
				'title'               => array( 'type' => 'string' ),
				'url'                 => array( 'type' => 'string' ),
				'when'                => array( 'type' => 'string', 'description' => 'As shown on the site, in the event\'s own time zone.' ),
				'start'               => array( 'type' => 'string', 'description' => 'Local start, YYYY-MM-DDTHH:MM.' ),
				'end'                 => array( 'type' => 'string', 'description' => 'Local end, YYYY-MM-DDTHH:MM, or empty.' ),
				'timezone'            => array( 'type' => 'string' ),
				'location'            => array( 'type' => 'string' ),
				'venue'               => array( 'type' => 'string' ),
				'city_region_country' => array( 'type' => 'string' ),
				'online_url'          => array( 'type' => 'string' ),
				'host'                => array( 'type' => 'string' ),
				'admission'           => array( 'type' => 'string' ),
				'status'              => array( 'type' => 'string', 'description' => 'scheduled, cancelled or postponed.' ),
				'is_past'             => array( 'type' => 'boolean' ),
				'event_types'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		);

		if ( $events_available ) {
			wp_register_ability(
				'colandb/list-events',
				array(
					'label'               => __( 'List events', 'colandb-mcp' ),
					'description'         => __( 'Published events on the colandb.com calendar that are on between two dates (default: the next 30 days), soonest first. Optional keyword search and filters by event type, venue or partner organization (slugs). Only public information.', 'colandb-mcp' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'default'              => array(),
						'additionalProperties' => false,
						'properties'           => array(
							'from'       => array( 'type' => 'string', 'format' => 'date', 'description' => 'First day, YYYY-MM-DD. Default: today.' ),
							'to'         => array( 'type' => 'string', 'format' => 'date', 'description' => 'Last day, YYYY-MM-DD. Default: 30 days after "from". At most 366 days after "from".' ),
							'search'     => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Words in the title or description.' ),
							'event_type' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Event type slug.' ),
							'venue'      => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Venue slug.' ),
							'partner'    => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Partner organization or titleholder slug.' ),
							'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'from'      => array( 'type' => 'string' ),
							'to'        => array( 'type' => 'string' ),
							'total'     => array( 'type' => 'integer', 'description' => 'All matching events, including any beyond the limit.' ),
							'events'    => array( 'type' => 'array', 'items' => $event_schema ),
						),
					),
					'execute_callback'    => array( __CLASS__, 'list_events' ),
					'permission_callback' => array( __CLASS__, 'can_connect' ),
					'meta'                => self::read_only(),
				)
			);

			$full_event                                  = $event_schema;
			$full_event['properties']['description']     = array( 'type' => 'string', 'description' => 'The event page text, as plain text (up to 3000 characters).' );
			$full_event['properties']['official_website'] = array( 'type' => 'string' );
			wp_register_ability(
				'colandb/get-event',
				array(
					'label'               => __( 'Get an event', 'colandb-mcp' ),
					'description'         => __( 'One published event by its ID, with its description. Events that are not published (drafts, pending, in review, trashed) are reported as not found.', 'colandb-mcp' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'id' ),
						'additionalProperties' => false,
						'properties'           => array(
							'id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'output_schema'       => $full_event,
					'execute_callback'    => array( __CLASS__, 'get_event' ),
					'permission_callback' => array( __CLASS__, 'can_connect' ),
					'meta'                => self::read_only(),
				)
			);

			wp_register_ability(
				'colandb/list-pending-events',
				array(
					'label'               => __( 'List events awaiting review', 'colandb-mcp' ),
					'description'         => __( 'Submitted events that are not public yet: Pending and In Review (and Draft if asked), newest submission first. Gives the title, dates, status and the wp-admin link to review it; never the submitter\'s contact details.', 'colandb-mcp' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'                 => 'object',
						'default'              => array(),
						'additionalProperties' => false,
						'properties'           => array(
							'include_drafts' => array( 'type' => 'boolean', 'default' => false ),
							'limit'          => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'total'  => array( 'type' => 'integer' ),
							'events' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'           => array( 'type' => 'integer' ),
										'title'        => array( 'type' => 'string' ),
										'status'       => array( 'type' => 'string' ),
										'start'        => array( 'type' => 'string' ),
										'end'          => array( 'type' => 'string' ),
										'submitted'    => array( 'type' => 'string', 'description' => 'UTC, ISO 8601.' ),
										'last_changed' => array( 'type' => 'string', 'description' => 'UTC, ISO 8601.' ),
										'submitted_by' => array( 'type' => 'string', 'description' => 'Display name of the account that submitted it, or "guest".' ),
										'review_url'   => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( __CLASS__, 'list_pending_events' ),
					'permission_callback' => static function () {
						return current_user_can( COLANDB_MCP_Role::CAP_CONNECT ) && current_user_can( COLANDB_MCP_Role::CAP_PENDING );
					},
					'meta'                => self::read_only(),
				)
			);
		}

		wp_register_ability(
			'colandb/site-health',
			array(
				'label'               => __( 'Site health', 'colandb-mcp' ),
				'description'         => __( 'Which colandb plugins are installed and active and at which version, the plugin updater\'s channel and last check, and the WordPress and PHP versions. Use it to see whether a release has reached this site.', 'colandb-mcp' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site_url'          => array( 'type' => 'string' ),
						'environment'       => array( 'type' => 'string' ),
						'wordpress_version' => array( 'type' => 'string' ),
						'php_version'       => array( 'type' => 'string' ),
						'plugins'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'slug'      => array( 'type' => 'string' ),
									'name'      => array( 'type' => 'string' ),
									'version'   => array( 'type' => 'string' ),
									'installed' => array( 'type' => 'boolean' ),
									'active'    => array( 'type' => 'boolean' ),
								),
							),
						),
						'updater'           => array(
							'type'       => 'object',
							'properties' => array(
								'channel'    => array( 'type' => 'string' ),
								'connected'  => array( 'type' => 'boolean', 'description' => 'A GitHub token is set.' ),
								'last_check' => array( 'type' => 'string', 'description' => 'UTC, ISO 8601, or empty.' ),
								'last_error' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'site_health' ),
				'permission_callback' => static function () {
					return current_user_can( COLANDB_MCP_Role::CAP_CONNECT ) && current_user_can( COLANDB_MCP_Role::CAP_HEALTH );
				},
				'meta'                => self::read_only(),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Events
	 * ---------------------------------------------------------------- */

	/** The fields Claude gets for an event, from cec_get_public_event(). */
	private static function summarize( $event ) {
		$types = get_the_terms( $event['id'], 'cec_event_type' );
		return array(
			'id'                  => (int) $event['id'],
			'title'               => (string) $event['title_plain'],
			'url'                 => (string) $event['permalink'],
			'when'                => trim( $event['when_display'] . ( $event['timezone_abbr'] ? ' ' . $event['timezone_abbr'] : '' ) ),
			'start'               => (string) $event['start_raw'],
			'end'                 => (string) $event['end_raw'],
			'timezone'            => (string) $event['timezone'],
			'location'            => (string) $event['location_label'],
			'venue'               => (string) $event['venue_name'],
			'city_region_country' => (string) $event['city_region_country'],
			'online_url'          => (string) $event['online_url'],
			'host'                => (string) $event['host_org_name'],
			'admission'           => (string) $event['admission_label'],
			'status'              => (string) $event['event_status'],
			'is_past'             => (bool) $event['is_past'],
			'event_types'         => is_array( $types ) ? array_values( wp_list_pluck( $types, 'name' ) ) : array(),
		);
	}

	/** A YYYY-MM-DD string as a date in the site's time zone, or null. */
	private static function parse_day( $value ) {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );
		return $day && $day->format( 'Y-m-d' ) === $value ? $day : null;
	}

	public static function list_events( $input ) {
		$input = (array) $input;
		$today = new DateTimeImmutable( 'today', wp_timezone() );

		$from = isset( $input['from'] ) ? self::parse_day( $input['from'] ) : $today;
		if ( ! $from ) {
			return new WP_Error( 'colandb_bad_date', __( '"from" must be a date in the form YYYY-MM-DD.', 'colandb-mcp' ) );
		}
		$to = isset( $input['to'] ) ? self::parse_day( $input['to'] ) : $from->modify( '+30 days' );
		if ( ! $to ) {
			return new WP_Error( 'colandb_bad_date', __( '"to" must be a date in the form YYYY-MM-DD.', 'colandb-mcp' ) );
		}
		if ( $to < $from || $to > $from->modify( '+366 days' ) ) {
			return new WP_Error( 'colandb_bad_range', __( '"to" must be on or after "from" and at most 366 days later.', 'colandb-mcp' ) );
		}

		// _cec_start/_cec_end are local "Y-m-d\TH:i" strings, so they compare
		// as text. An event is "on" in the range if it starts by the last day
		// and ends (or, with no end, starts) on or after the first day — the
		// same rule the calendar's own list uses.
		$range_start = $from->format( 'Y-m-d' ) . 'T00:00';
		$range_end   = $to->format( 'Y-m-d' ) . 'T23:59';
		$args        = array(
			'post_type'              => 'cec_event',
			'post_status'            => 'publish',
			'posts_per_page'         => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
			'fields'                 => 'ids',
			'meta_key'               => '_cec_start', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'                => 'meta_value',
			'order'                  => 'ASC',
			'update_post_term_cache' => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array(
					'key'     => '_cec_start',
					'value'   => $range_end,
					'compare' => '<=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_cec_end',
						'value'   => $range_start,
						'compare' => '>=',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_cec_end',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_cec_start',
							'value'   => $range_start,
							'compare' => '>=',
						),
					),
				),
			),
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		$tax = array();
		foreach ( array( 'event_type' => 'cec_event_type', 'venue' => 'cec_venue', 'partner' => 'cec_partner_org' ) as $key => $taxonomy ) {
			if ( ! empty( $input[ $key ] ) ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => sanitize_title( $input[ $key ] ),
				);
			}
		}
		if ( $tax ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$query  = new WP_Query( $args );
		$events = array();
		foreach ( cec_get_public_events( $query->posts ) as $event ) {
			$events[] = self::summarize( $event );
		}
		// get_many() is keyed by ID; keep the query's date order.
		$order = array_flip( array_map( 'intval', $query->posts ) );
		usort(
			$events,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['id'] ] - $order[ $b['id'] ];
			}
		);

		return array(
			'from'   => $from->format( 'Y-m-d' ),
			'to'     => $to->format( 'Y-m-d' ),
			'total'  => (int) $query->found_posts,
			'events' => $events,
		);
	}

	public static function get_event( $input ) {
		$id    = (int) $input['id'];
		$event = cec_get_public_event( $id );
		if ( ! $event ) {
			return new WP_Error( 'colandb_not_found', __( 'No published event has that ID.', 'colandb-mcp' ) );
		}

		// A password-protected page's text isn't public, so it isn't given.
		$text = post_password_required( $id ) ? '' : wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id, 'raw' ) ), true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 3000 ) {
			$text = mb_substr( $text, 0, 2997 ) . '...';
		}

		$result                     = self::summarize( $event );
		$result['description']      = $text;
		$result['official_website'] = (string) $event['official_website_url'];
		return $result;
	}

	public static function list_pending_events( $input ) {
		$input    = (array) $input;
		$statuses = array( 'pending', 'cec_in_review' );
		if ( ! empty( $input['include_drafts'] ) ) {
			$statuses[] = 'draft';
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'cec_event',
				'post_status'    => $statuses,
				'posts_per_page' => isset( $input['limit'] ) ? (int) $input['limit'] : 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$events = array();
		foreach ( $query->posts as $post ) {
			$status = get_post_status_object( $post->post_status );
			$author = $post->post_author ? get_userdata( $post->post_author ) : false;
			$events[] = array(
				'id'           => (int) $post->ID,
				'title'        => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
				'status'       => $status ? $status->label : $post->post_status,
				'start'        => (string) get_post_meta( $post->ID, '_cec_start', true ),
				'end'          => (string) get_post_meta( $post->ID, '_cec_end', true ),
				'submitted'    => mysql2date( 'c', $post->post_date_gmt, false ),
				'last_changed' => mysql2date( 'c', $post->post_modified_gmt, false ),
				'submitted_by' => $author ? $author->display_name : 'guest',
				'review_url'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			);
		}

		return array(
			'total'  => (int) $query->found_posts,
			'events' => $events,
		);
	}

	/* ------------------------------------------------------------------
	 * Site
	 * ---------------------------------------------------------------- */

	public static function site_health() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slugs = class_exists( 'COLANDB_Updater' ) ? COLANDB_Updater::managed_slugs() : self::PLUGINS;
		$found = array();
		foreach ( get_plugins() as $file => $data ) {
			$found[ dirname( $file ) ] = array( $file, $data );
		}

		$plugins = array();
		foreach ( $slugs as $slug ) {
			if ( isset( $found[ $slug ] ) ) {
				list( $file, $data ) = $found[ $slug ];
				$plugins[]           = array(
					'slug'      => $slug,
					'name'      => (string) $data['Name'],
					'version'   => (string) $data['Version'],
					'installed' => true,
					'active'    => is_plugin_active( $file ),
				);
			} else {
				$plugins[] = array(
					'slug'      => $slug,
					'name'      => '',
					'version'   => '',
					'installed' => false,
					'active'    => false,
				);
			}
		}

		// The updater's settings; the token itself is never returned.
		$settings = (array) get_option( 'colandb_updater', array() );
		$updater  = array(
			'channel'    => class_exists( 'COLANDB_Updater' ) ? COLANDB_Updater::channel() : '',
			'connected'  => ! empty( $settings['token'] ) || ( defined( 'COLANDB_GITHUB_TOKEN' ) && COLANDB_GITHUB_TOKEN ),
			'last_check' => ! empty( $settings['last_check'] ) ? gmdate( 'c', (int) $settings['last_check'] ) : '',
			'last_error' => isset( $settings['last_error'] ) ? (string) $settings['last_error'] : '',
		);

		return array(
			'site_url'          => home_url( '/' ),
			'environment'       => wp_get_environment_type(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'plugins'           => $plugins,
			'updater'           => $updater,
		);
	}
}
