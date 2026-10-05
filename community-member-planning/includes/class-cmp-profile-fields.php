<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The profile field dictionary from the project brief (§2 "Initial profile
 * field dictionary") and the admin-managed option lists behind its select
 * fields. Field keys and option keys are stable machine keys: labels can be
 * renamed and options retired, but a key is never reused or deleted, so
 * stored selections keep their meaning.
 */
class CMP_Profile_Fields {

	const OPTION     = 'cmp_profile_options';
	const CAP        = 'cmp_manage_member_fields';
	const VISIBILITY = array( 'private', 'connections', 'members' );
	const AGE_BANDS  = array(
		'18-24' => '18–24',
		'25-34' => '25–34',
		'35-44' => '35–44',
		'45-54' => '45–54',
		'55-64' => '55–64',
		'65+'   => '65+',
	);

	/**
	 * Admin-managed option lists. 'availability' always includes the
	 * built-in "Not listed" default.
	 */
	public static function lists() {
		return array(
			'interests'       => __( 'Interests', 'cmp' ),
			'roles'           => __( 'Roles / capacities', 'cmp' ),
			'identity'        => __( 'Orientation / identity descriptors', 'cmp' ),
			'availability'    => __( 'Availability', 'cmp' ),
			'looking_for'     => __( 'Looking for', 'cmp' ),
			// 0.5.0 (profiles v2, modeled on FetLife / Sniffies / Chaster).
			'gender'          => __( 'Gender', 'cmp' ),
			'position'        => __( 'Position', 'cmp' ),
			'body_type'       => __( 'Body type', 'cmp' ),
			'active_level'    => __( 'How active', 'cmp' ),
			'not_looking_for' => __( 'Not looking for', 'cmp' ),
			'hosting'         => __( 'Hosting', 'cmp' ),
			'kinks'           => __( 'Kinks', 'cmp' ),
			'practices'       => __( 'Safer-sex practices', 'cmp' ),
			'substances'      => __( 'Substances', 'cmp' ),
		);
	}

	/**
	 * Starter options for every list, trimmed from the owner's research of
	 * FetLife, Sniffies and Chaster (docs/mockups/profiles-v2.html). Only
	 * used to fill a list that has no options at all; the site team edits
	 * them freely afterwards.
	 */
	public static function default_options() {
		return array(
			'interests'       => array( 'Leather', 'Rubber / latex', 'Gear', 'Bar nights', 'Contests & titles', 'Workshops & education', 'Social events', 'Volunteering', 'Leathercraft', 'Bootblacking', 'Motorcycles', 'Camping', 'Fitness' ),
			'roles'           => array( 'Dominant', 'Domme', 'Switch', 'submissive', 'Top', 'Bottom', 'Daddy', 'Mommy', 'boy', 'girl', 'pup', 'Handler', 'Owner', 'Master', 'Mistress', 'slave', 'Sir', 'Keyholder', 'Chastity wearer', 'Rigger', 'Rope bunny', 'Sadist', 'Masochist', 'Brat', 'Brat tamer', 'Primal', 'Little', 'Caregiver', 'Mentor', 'Mentee', 'Leatherman', 'Leatherwoman', 'Leatherperson', 'Bootblack', 'Kinkster', 'Exploring' ),
			'identity'        => array( 'Gay', 'Lesbian', 'Bisexual', 'Pansexual', 'Queer', 'Straight', 'Heteroflexible', 'Homoflexible', 'Asexual', 'Demisexual', 'Sapiosexual', 'Questioning' ),
			'availability'    => array( 'Open to meeting', 'Busy right now', 'Events only', 'Online only for now' ),
			'looking_for'     => array( 'A dynamic', 'Play partner', 'Keyholder', 'Keeping someone locked', 'Homework / tasks', 'Mentor / teacher', 'Mentee / student', 'Friendship', 'Community', 'Events', 'Conversation', 'Meeting in person', 'Relationship', 'Long-term relationship', 'Non-monogamy', 'Friends with benefits' ),
			'gender'          => array( 'Man', 'Woman', 'Nonbinary', 'Genderqueer', 'Genderfluid', 'Agender', 'Trans man', 'Trans woman', 'Transmasculine', 'Transfeminine', 'Two-Spirit', 'Intersex', 'Questioning' ),
			'position'        => array( 'Top', 'Vers top', 'Vers', 'Vers bottom', 'Bottom', 'Side', 'Not applicable' ),
			'body_type'       => array( 'Slim', 'Average', 'Athletic', 'Muscular', 'Stocky', 'Large', 'Bear', 'Cub', 'Otter', 'Twink', 'Jock', 'Dad bod' ),
			'active_level'    => array( 'I live it 24/7', 'I live the lifestyle when I can', 'Just in the bedroom', 'Once in a while to spice things up', 'Curious and want to try', 'Just curious right now' ),
			'not_looking_for' => array( 'Casual hookups', 'One night stands', 'Online only', 'Cybersex', 'Small talk', 'A relationship', 'A dynamic', 'Play partners', 'Meeting in person' ),
			'hosting'         => array( 'Can host', 'Can travel', 'Host or travel', 'Ask me' ),
			'kinks'           => array_merge( ...array_values( self::default_kinks_by_group() ) ),
			'practices'       => array( 'On PrEP', 'DoxyPEP', 'Condoms always', 'Condoms sometimes', 'Undetectable (U=U)', 'Mpox vaccinated', 'COVID vaccinated', 'Tested regularly' ),
			'substances'      => array( 'No drugs', 'No PnP', 'Sober', 'Drinks socially', '420 friendly', 'No tobacco' ),
		);
	}

	/**
	 * Fills each list that has no options at all with its starter options.
	 * Lists the site team has touched (even if every option is retired)
	 * are left alone. Runs on upgrade (CMP_Install).
	 */
	public static function seed_defaults() {
		$all     = get_option( self::OPTION, array() );
		$changed = false;
		foreach ( self::default_options() as $list => $labels ) {
			if ( ! empty( $all[ $list ] ) ) {
				continue;
			}
			$rows = array();
			foreach ( $labels as $i => $label ) {
				$key = substr( sanitize_title( $label ), 0, 60 );
				$rows[] = array( 'key' => $key ? $key : 'option-' . $i, 'label' => $label, 'active' => true, 'order' => $i );
				if ( 'kinks' === $list ) {
					$rows[ count( $rows ) - 1 ]['group'] = self::default_group_of( $label );
				}
			}
			$all[ $list ] = $rows;
			$changed      = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Profile sections, in order: the Profile tab groups fields under these
	 * headings (Sniffies-style), and the setup steps follow them.
	 */
	public static function sections() {
		return array(
			'basics'   => __( 'Basics', 'cmp' ),
			'identity' => __( 'Identity', 'cmp' ),
			'stats'    => __( 'Stats', 'cmp' ),
			'scene'    => __( 'Scene', 'cmp' ),
			'kinks'    => __( 'Kinks & limits', 'cmp' ),
			'health'   => __( 'Health & safer sex', 'cmp' ),
		);
	}

	/**
	 * Field definitions, in display order.
	 * type: account | image | text | textarea | multi | single | location | age | system
	 * - account: stored on the WordPress account (display name; edited on the
	 *   Account tab); only its visibility is stored here.
	 * - image: profile/cover photo (CMP_Profile_Images); its visibility
	 *   decides who can load the image.
	 */
	public static function fields() {
		return array(
			// Basics.
			'display_name'    => array( 'section' => 'basics', 'label' => __( 'Display name', 'cmp' ), 'type' => 'account', 'required' => true, 'max' => 80 ),
			'avatar'          => array( 'section' => 'basics', 'label' => __( 'Profile photo', 'cmp' ), 'type' => 'image', 'searchable' => false ),
			'cover'           => array( 'section' => 'basics', 'label' => __( 'Cover image', 'cmp' ), 'type' => 'image', 'searchable' => false ),
			'bio'             => array( 'section' => 'basics', 'label' => __( 'About me', 'cmp' ), 'type' => 'textarea', 'max' => 1000 ),
			'pronouns'        => array( 'section' => 'basics', 'label' => __( 'Pronouns', 'cmp' ), 'type' => 'text', 'max' => 40 ),
			'location'        => array( 'section' => 'basics', 'label' => __( 'Location', 'cmp' ), 'type' => 'location', 'max' => 80, 'help' => __( 'City, region and country only. Never a street address.', 'cmp' ) ),
			'hosting'         => array( 'section' => 'basics', 'label' => __( 'Hosting', 'cmp' ), 'type' => 'single', 'list' => 'hosting' ),
			'active_level'    => array( 'section' => 'basics', 'label' => __( 'How active', 'cmp' ), 'type' => 'single', 'list' => 'active_level' ),
			'availability'    => array( 'section' => 'basics', 'label' => __( 'Availability', 'cmp' ), 'type' => 'single', 'list' => 'availability' ),
			'member_since'    => array( 'section' => 'basics', 'label' => __( 'Member since', 'cmp' ), 'type' => 'system', 'searchable' => false ),
			// Identity.
			'gender'          => array( 'section' => 'identity', 'label' => __( 'Gender', 'cmp' ), 'type' => 'multi', 'list' => 'gender', 'limit' => 3, 'other' => 40 ),
			// A fixed list, no free text (owner, 0.9.1), so members can be found by it.
			'identity'        => array( 'section' => 'identity', 'label' => __( 'Orientation', 'cmp' ), 'type' => 'multi', 'list' => 'identity', 'limit' => 10 ),
			'roles'           => array( 'section' => 'identity', 'label' => __( 'Roles', 'cmp' ), 'type' => 'multi', 'list' => 'roles', 'limit' => 10 ),
			'position'        => array( 'section' => 'identity', 'label' => __( 'Position', 'cmp' ), 'type' => 'single', 'list' => 'position' ),
			'expression'      => array( 'section' => 'identity', 'label' => __( 'Expression', 'cmp' ), 'type' => 'text', 'max' => 60, 'help' => __( 'In your own words, e.g. "Leather daddy".', 'cmp' ) ),
			// Stats.
			'age'             => array( 'section' => 'stats', 'label' => __( 'Age', 'cmp' ), 'type' => 'age' ),
			'height'          => array( 'section' => 'stats', 'label' => __( 'Height', 'cmp' ), 'type' => 'height' ),
			'weight'          => array( 'section' => 'stats', 'label' => __( 'Weight', 'cmp' ), 'type' => 'weight' ),
			'body_type'       => array( 'section' => 'stats', 'label' => __( 'Body type', 'cmp' ), 'type' => 'single', 'list' => 'body_type' ),
			// Scene.
			'looking_for'     => array( 'section' => 'scene', 'label' => __( 'Looking for', 'cmp' ), 'type' => 'multi', 'list' => 'looking_for', 'limit' => 10 ),
			'not_looking_for' => array( 'section' => 'scene', 'label' => __( 'Not looking for', 'cmp' ), 'type' => 'multi', 'list' => 'not_looking_for', 'limit' => 10 ),
			'interests'       => array( 'section' => 'scene', 'label' => __( 'Interests', 'cmp' ), 'type' => 'multi', 'list' => 'interests', 'limit' => 20 ),
			// Kinks & limits.
			'kinks'           => array( 'section' => 'kinks', 'label' => __( 'Kinks', 'cmp' ), 'type' => 'rated', 'list' => 'kinks', 'limit' => 40 ),
			'hard_limits'     => array( 'section' => 'kinks', 'label' => __( 'Hard limits', 'cmp' ), 'type' => 'textarea', 'max' => 500, 'help' => __( 'What nobody should ask of you. Worth filling in before anyone proposes a dynamic.', 'cmp' ) ),
			// Health (sensitive: starts hidden even when filled in).
			'practices'       => array( 'section' => 'health', 'label' => __( 'Safer-sex practices', 'cmp' ), 'type' => 'multi', 'list' => 'practices', 'limit' => 10, 'sensitive' => true, 'searchable' => false ),
			'last_tested'     => array( 'section' => 'health', 'label' => __( 'Last tested', 'cmp' ), 'type' => 'month', 'sensitive' => true, 'searchable' => false ),
			'substances'      => array( 'section' => 'health', 'label' => __( 'Substances', 'cmp' ), 'type' => 'multi', 'list' => 'substances', 'limit' => 6, 'sensitive' => true ),
		);
	}

	/** Health details start hidden even once filled in (0.5.0). */
	public static function is_sensitive( $key ) {
		$f = self::field( $key );
		return $f && ! empty( $f['sensitive'] );
	}

	/** Kink categories for the picker (0.10.0). The site team assigns each kink one. */
	public static function kink_groups() {
		return array(
			'bondage'   => __( 'Bondage & restraint', 'cmp' ),
			'impact'    => __( 'Impact', 'cmp' ),
			'control'   => __( 'Chastity & control', 'cmp' ),
			'service'   => __( 'Service & protocol', 'cmp' ),
			'pet'       => __( 'Pup & pet', 'cmp' ),
			'roleplay'  => __( 'Role & age play', 'cmp' ),
			'gear'      => __( 'Fetish & gear', 'cmp' ),
			'sensation' => __( 'Sensation', 'cmp' ),
			'worship'   => __( 'Body worship', 'cmp' ),
			'exhibit'   => __( 'Exhibition & voyeur', 'cmp' ),
			'other'     => __( 'Other', 'cmp' ),
		);
	}

	/** Starter kinks by category (the 0.5.0 thirty plus more, 0.10.0). */
	public static function default_kinks_by_group() {
		return array(
			'bondage'   => array( 'Rope bondage', 'Restraints', 'Cuffs', 'Hoods', 'Mummification', 'Suspension', 'Cages' ),
			'impact'    => array( 'Impact play', 'Spanking', 'Flogging', 'Paddling', 'Caning', 'Belts' ),
			'control'   => array( 'Chastity / keyholding', 'Edging & denial', 'Orgasm control', 'Tease & denial', 'Ruined orgasms' ),
			'service'   => array( 'Service & protocol', 'Domestic service', 'Training & homework', 'Rules & rituals', 'Kneeling', 'Speech protocol' ),
			'pet'       => array( 'Pup play', 'Pony play', 'Kitten play', 'Handler / pet', 'Primal play' ),
			'roleplay'  => array( 'Daddy / boy dynamics', 'Role play', 'Teacher / student', 'Interrogation', 'Uniforms & authority' ),
			'gear'      => array( 'Leather', 'Leather worship', 'Rubber / latex', 'Uniforms & gear', 'Jockstraps', 'Socks', 'Sneakers', 'Gas masks' ),
			'sensation' => array( 'Sensory play', 'Wax play', 'Electro', 'Ice play', 'Tickling', 'Sensory deprivation' ),
			'worship'   => array( 'Boot worship', 'Muscle worship', 'Foot worship', 'Body hair', 'Armpits' ),
			'exhibit'   => array( 'Exhibitionism', 'Voyeurism', 'Public play', 'Photography' ),
			'other'     => array( 'Praise', 'Humiliation', 'Watersports', 'Toys', 'Group play' ),
		);
	}

	private static function default_group_of( $label ) {
		foreach ( self::default_kinks_by_group() as $group => $labels ) {
			if ( in_array( $label, $labels, true ) ) {
				return $group;
			}
		}
		return 'other';
	}

	/** option key => category, for lists that have them (kinks). */
	public static function option_groups( $list ) {
		$out = array();
		foreach ( self::raw_options( $list ) as $o ) {
			$out[ $o['key'] ] = isset( $o['group'] ) && isset( self::kink_groups()[ $o['group'] ] ) ? $o['group'] : 'other';
		}
		return $out;
	}

	/**
	 * Once (0.10.0): gives every existing kink a category, and adds the new
	 * starter kinks the list doesn't have yet. Kinks the site team renamed
	 * or retired are left as they are; nothing is removed.
	 */
	public static function upgrade_kinks() {
		if ( get_option( 'cmp_kinks_grouped' ) ) {
			return;
		}
		$all  = get_option( self::OPTION, array() );
		$rows = isset( $all['kinks'] ) ? (array) $all['kinks'] : array();
		$map  = array();
		foreach ( self::default_kinks_by_group() as $group => $labels ) {
			foreach ( $labels as $label ) {
				$map[ substr( sanitize_title( $label ), 0, 60 ) ] = array( $group, $label );
			}
		}
		$have  = array();
		$order = 0;
		foreach ( $rows as $i => $o ) {
			$have[ $o['key'] ] = true;
			$order             = max( $order, (int) $o['order'] );
			if ( empty( $o['group'] ) ) {
				$rows[ $i ]['group'] = isset( $map[ $o['key'] ] ) ? $map[ $o['key'] ][0] : 'other';
			}
		}
		foreach ( $map as $key => $info ) {
			if ( ! isset( $have[ $key ] ) ) {
				$rows[] = array( 'key' => $key, 'label' => $info[1], 'active' => true, 'order' => ++$order, 'group' => $info[0] );
			}
		}
		$all['kinks'] = array_values( $rows );
		update_option( self::OPTION, $all, false );
		update_option( 'cmp_kinks_grouped', CMP_VERSION, false );
	}

	const KINK_LEVELS = array( 'love', 'like', 'curious' );
	const KINK_DIRS   = array( 'giving', 'receiving', 'both' );

	public static function kink_level_labels() {
		return array( 'love' => __( 'Love it', 'cmp' ), 'like' => __( 'Like it', 'cmp' ), 'curious' => __( 'Curious', 'cmp' ) );
	}

	public static function kink_dir_labels() {
		return array( 'giving' => __( 'Giving', 'cmp' ), 'receiving' => __( 'Receiving', 'cmp' ), 'both' => __( 'Both', 'cmp' ) );
	}

	/** Height in inches → 5'11". */
	public static function format_height( $inches ) {
		$inches = (int) $inches;
		return $inches ? sprintf( '%d\'%d"', intdiv( $inches, 12 ), $inches % 12 ) : '';
	}

	public static function field( $key ) {
		$fields = self::fields();
		return isset( $fields[ $key ] ) ? $fields[ $key ] : null;
	}

	public static function can_be_searchable( $key ) {
		$f = self::field( $key );
		return $f && ( ! isset( $f['searchable'] ) || false !== $f['searchable'] );
	}

	/* ------------------------------------------------------------------
	 * Option lists
	 * ---------------------------------------------------------------- */

	/**
	 * @param bool $include_retired Retired options are kept so old
	 *                              selections can still be shown.
	 * @return array key => label, in admin order.
	 */
	public static function options( $list, $include_retired = false ) {
		$all = get_option( self::OPTION, array() );
		$out = array();
		if ( 'availability' === $list ) {
			$out['not_listed'] = __( 'Not listed', 'cmp' );
		}
		foreach ( isset( $all[ $list ] ) ? (array) $all[ $list ] : array() as $o ) {
			if ( $include_retired || ! empty( $o['active'] ) ) {
				$out[ $o['key'] ] = $o['label'];
			}
		}
		return $out;
	}

	public static function raw_options( $list ) {
		$all = get_option( self::OPTION, array() );
		return isset( $all[ $list ] ) ? (array) $all[ $list ] : array();
	}

	/**
	 * Saves one list from the admin screen. Existing keys keep their key;
	 * a new label gets a new key; nothing is ever deleted.
	 *
	 * @param array $rows array of array( key?, label, active, order ).
	 * @return true|WP_Error
	 */
	public static function save_list( $list, $rows ) {
		if ( ! isset( self::lists()[ $list ] ) ) {
			return new WP_Error( 'cmp_list', 'Unknown list.' );
		}
		$existing = array();
		foreach ( self::raw_options( $list ) as $o ) {
			$existing[ $o['key'] ] = $o;
		}

		$out    = array();
		$labels = array();
		foreach ( $rows as $row ) {
			$label = trim( sanitize_text_field( isset( $row['label'] ) ? $row['label'] : '' ) );
			$key   = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $label ) {
				if ( $key && isset( $existing[ $key ] ) ) {
					// A blanked label on an existing option keeps the option (retired).
					$out[ $key ] = array_merge( $existing[ $key ], array( 'active' => false, 'order' => (int) ( isset( $row['order'] ) ? $row['order'] : 0 ) ) );
				}
				continue;
			}
			if ( mb_strlen( $label ) > 80 ) {
				return new WP_Error( 'cmp_label', sprintf( /* translators: %s: label */ __( '"%s" is longer than 80 characters.', 'cmp' ), $label ) );
			}
			$active = ! empty( $row['active'] );
			if ( $active ) {
				$lower = mb_strtolower( $label );
				if ( isset( $labels[ $lower ] ) ) {
					return new WP_Error( 'cmp_dup', sprintf( /* translators: %s: label */ __( '"%s" appears more than once.', 'cmp' ), $label ) );
				}
				$labels[ $lower ] = true;
			}
			if ( ! $key || ! isset( $existing[ $key ] ) ) {
				$key  = substr( sanitize_title( $label ), 0, 60 );
				$base = $key ? $key : 'option';
				$n    = 2;
				while ( isset( $existing[ $key ] ) || isset( $out[ $key ] ) || 'not_listed' === $key || '' === $key ) {
					$key = $base . '-' . $n++;
				}
			}
			$out[ $key ] = array( 'key' => $key, 'label' => $label, 'active' => $active, 'order' => (int) ( isset( $row['order'] ) ? $row['order'] : 0 ) );
			if ( 'kinks' === $list ) {
				$group               = isset( $row['group'] ) ? sanitize_key( $row['group'] ) : '';
				$out[ $key ]['group'] = isset( self::kink_groups()[ $group ] ) ? $group : ( isset( $existing[ $key ]['group'] ) ? $existing[ $key ]['group'] : 'other' );
			}
		}
		// Options not submitted at all are kept unchanged (never deleted),
		// and still count when checking for duplicate names.
		foreach ( $existing as $key => $o ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $o;
				if ( ! empty( $o['active'] ) ) {
					$lower = mb_strtolower( $o['label'] );
					if ( isset( $labels[ $lower ] ) ) {
						return new WP_Error( 'cmp_dup', sprintf( /* translators: %s: label */ __( '"%s" appears more than once.', 'cmp' ), $o['label'] ) );
					}
					$labels[ $lower ] = true;
				}
			}
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return ( (int) $a['order'] <=> (int) $b['order'] ) ?: strcasecmp( $a['label'], $b['label'] );
			}
		);

		$all          = get_option( self::OPTION, array() );
		$before       = isset( $all[ $list ] ) ? $all[ $list ] : array();
		$all[ $list ] = array_values( $out );
		update_option( self::OPTION, $all, false );
		if ( $before !== $all[ $list ] ) {
			CMP_Audit::log( 'profile_options_changed', 'option_list', 0, array( 'list' => $list, 'options' => $before ), array( 'list' => $list, 'options' => $all[ $list ] ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Validation of a member's submitted value
	 * ---------------------------------------------------------------- */

	/**
	 * @return mixed|WP_Error The clean value to store ('' / array() = empty).
	 */
	public static function clean( $key, $raw ) {
		$f = self::field( $key );
		if ( ! $f || in_array( $f['type'], array( 'system', 'account', 'image' ), true ) ) {
			return new WP_Error( 'cmp_field', 'Unknown field.' );
		}
		switch ( $f['type'] ) {
			case 'text':
			case 'textarea':
				$raw = is_scalar( $raw ) ? (string) $raw : '';
				$v   = 'textarea' === $f['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
				$v = trim( $v );
				if ( ! empty( $f['required'] ) && '' === $v ) {
					/* translators: %s: field label */
					return new WP_Error( 'cmp_required', sprintf( __( '%s is required.', 'cmp' ), $f['label'] ) );
				}
				if ( mb_strlen( $v ) > $f['max'] ) {
					/* translators: 1: field label, 2: maximum characters */
					return new WP_Error( 'cmp_too_long', sprintf( __( '%1$s can be at most %2$d characters.', 'cmp' ), $f['label'], $f['max'] ) );
				}
				return $v;

			case 'location':
				$v = array();
				foreach ( array( 'city', 'region', 'country' ) as $part ) {
					$p = trim( sanitize_text_field( is_array( $raw ) && isset( $raw[ $part ] ) && is_scalar( $raw[ $part ] ) ? (string) $raw[ $part ] : '' ) );
					if ( mb_strlen( $p ) > $f['max'] ) {
						/* translators: %d: maximum characters */
						return new WP_Error( 'cmp_too_long', sprintf( __( 'Each part of your location can be at most %d characters.', 'cmp' ), $f['max'] ) );
					}
					$v[ $part ] = $p;
				}
				return array_filter( $v ) ? $v : '';

			// Retired options stay valid, so a member who already chose one
			// keeps it when saving other changes (the form only offers a
			// retired option to someone who already has it).
			case 'single':
				$v = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
				return isset( self::options( $f['list'], true )[ $v ] ) && 'not_listed' !== $v ? $v : '';

			case 'multi':
				$valid = self::options( $f['list'], true );
				// Submitted as f[key][keys][] (+ f[key][other]); a plain list is accepted too.
				$keys  = is_array( $raw ) && array_key_exists( 'keys', $raw ) ? (array) $raw['keys'] : ( is_array( $raw ) ? array_diff_key( $raw, array( 'other' => 1 ) ) : array() );
				$v     = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $keys ) ), function ( $k ) use ( $valid ) {
					return isset( $valid[ $k ] );
				} ) ) );
				if ( count( $v ) > $f['limit'] ) {
					/* translators: 1: field label, 2: maximum choices */
					return new WP_Error( 'cmp_too_many', sprintf( __( 'Choose at most %2$d for %1$s.', 'cmp' ), $f['label'], $f['limit'] ) );
				}
				$out = array( 'keys' => $v );
				if ( ! empty( $f['other'] ) ) {
					$other = trim( sanitize_text_field( is_array( $raw ) && isset( $raw['other'] ) ? (string) $raw['other'] : '' ) );
					if ( mb_strlen( $other ) > $f['other'] ) {
						/* translators: %d: maximum characters */
						return new WP_Error( 'cmp_too_long', sprintf( __( '"Other" can be at most %d characters.', 'cmp' ), $f['other'] ) );
					}
					$out['other'] = $other;
				}
				return ( $out['keys'] || ! empty( $out['other'] ) ) ? $out : '';

			case 'age':
				$raw  = is_array( $raw ) ? $raw : array();
				$mode = isset( $raw['mode'] ) && is_scalar( $raw['mode'] ) ? sanitize_key( $raw['mode'] ) : '';
				if ( 'exact' === $mode ) {
					$n = isset( $raw['exact'] ) && is_scalar( $raw['exact'] ) ? trim( (string) $raw['exact'] ) : '';
					if ( ! preg_match( '/^\d{2,3}$/', $n ) || (int) $n < 18 || (int) $n > 120 ) {
						return new WP_Error( 'cmp_age', __( 'Age must be a whole number from 18 to 120.', 'cmp' ) );
					}
					return array( 'mode' => 'exact', 'value' => (int) $n );
				}
				if ( 'band' === $mode ) {
					$b = isset( $raw['band'] ) && is_scalar( $raw['band'] ) ? (string) $raw['band'] : '';
					if ( ! isset( self::AGE_BANDS[ $b ] ) ) {
						return new WP_Error( 'cmp_age', __( 'Choose an age range.', 'cmp' ) );
					}
					return array( 'mode' => 'band', 'value' => $b );
				}
				return '';

			case 'height':
				$n = is_scalar( $raw ) ? (int) $raw : 0;
				if ( ! $n ) {
					return '';
				}
				if ( $n < 48 || $n > 96 ) {
					return new WP_Error( 'cmp_height', __( 'Choose a height from the list.', 'cmp' ) );
				}
				return $n;

			case 'weight':
				$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' === $raw ) {
					return '';
				}
				if ( ! preg_match( '/^\d{2,3}$/', $raw ) || (int) $raw < 70 || (int) $raw > 700 ) {
					return new WP_Error( 'cmp_weight', __( 'Weight must be a whole number of pounds from 70 to 700.', 'cmp' ) );
				}
				return (int) $raw;

			case 'month':
				$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' === $raw ) {
					return '';
				}
				if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 || $raw > wp_date( 'Y-m' ) || (int) $m[1] < 1980 ) {
					return new WP_Error( 'cmp_month', sprintf( /* translators: %s: field label */ __( '%s must be a month and year, not in the future.', 'cmp' ), $f['label'] ) );
				}
				return $raw;

			case 'rated':
				// Submitted as f[kinks][<option key>][lvl|dir]; an option with
				// no level is not chosen.
				$valid = self::options( $f['list'], true );
				$out   = array();
				foreach ( is_array( $raw ) ? $raw : array() as $k => $row ) {
					$k = sanitize_key( (string) $k );
					if ( ! isset( $valid[ $k ] ) || ! is_array( $row ) ) {
						continue;
					}
					$lvl = isset( $row['lvl'] ) && is_scalar( $row['lvl'] ) ? sanitize_key( $row['lvl'] ) : '';
					if ( ! in_array( $lvl, self::KINK_LEVELS, true ) ) {
						continue;
					}
					$dir   = isset( $row['dir'] ) && is_scalar( $row['dir'] ) ? sanitize_key( $row['dir'] ) : '';
					$out[] = array( 'k' => $k, 'lvl' => $lvl, 'dir' => in_array( $dir, self::KINK_DIRS, true ) ? $dir : '' );
				}
				if ( count( $out ) > $f['limit'] ) {
					/* translators: 1: field label, 2: maximum choices */
					return new WP_Error( 'cmp_too_many', sprintf( __( 'Choose at most %2$d for %1$s.', 'cmp' ), $f['label'], $f['limit'] ) );
				}
				return $out ? $out : '';
		}
		return '';
	}

	/**
	 * Human-readable value, for previews (labels of retired options are
	 * still shown, per the brief).
	 */
	public static function display( $key, $value ) {
		$f = self::field( $key );
		if ( ! $f || '' === $value || array() === $value || null === $value || false === $value ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'location':
				return implode( ', ', array_filter( array_map( 'strval', (array) $value ) ) );
			case 'single':
				$o = self::options( $f['list'], true );
				return isset( $o[ $value ] ) ? $o[ $value ] : '';
			case 'multi':
				$o      = self::options( $f['list'], true );
				$labels = array();
				foreach ( isset( $value['keys'] ) ? (array) $value['keys'] : array() as $k ) {
					if ( isset( $o[ $k ] ) ) {
						$labels[] = $o[ $k ];
					}
				}
				// Only fields that still take "Other" show it (orientation stopped in 0.9.1).
				if ( ! empty( $value['other'] ) && ! empty( $f['other'] ) ) {
					$labels[] = $value['other'];
				}
				return implode( ', ', $labels );
			case 'age':
				if ( ! is_array( $value ) || ! isset( $value['mode'], $value['value'] ) ) {
					return '';
				}
				return 'exact' === $value['mode'] ? (string) $value['value'] : ( isset( self::AGE_BANDS[ $value['value'] ] ) ? self::AGE_BANDS[ $value['value'] ] : '' );
			case 'height':
				return self::format_height( $value );
			case 'weight':
				/* translators: %d: pounds */
				return sprintf( __( '%d lb', 'cmp' ), (int) $value );
			case 'month':
				return preg_match( '/^\d{4}-\d{2}$/', (string) $value ) ? date_i18n( 'M Y', strtotime( $value . '-01' ) ) : '';
			case 'rated':
				$o      = self::options( $f['list'], true );
				$lvls   = self::kink_level_labels();
				$dirs   = self::kink_dir_labels();
				$labels = array();
				foreach ( (array) $value as $row ) {
					if ( is_array( $row ) && isset( $row['k'], $o[ $row['k'] ] ) ) {
						$bits     = array_filter( array( isset( $lvls[ $row['lvl'] ] ) ? $lvls[ $row['lvl'] ] : '', ! empty( $row['dir'] ) && isset( $dirs[ $row['dir'] ] ) ? mb_strtolower( $dirs[ $row['dir'] ] ) : '' ) );
						$labels[] = $o[ $row['k'] ] . ( $bits ? ' (' . implode( ', ', $bits ) . ')' : '' );
					}
				}
				return implode( ', ', $labels );
			default:
				return (string) $value;
		}
	}
}
