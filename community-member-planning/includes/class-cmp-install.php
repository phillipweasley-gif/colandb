<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades this plugin's own tables. Each release that adds a
 * table bumps CMP_DB_VERSION and adds its CREATE TABLE to schema(); dbDelta()
 * creates missing tables and adds missing columns without touching data.
 * Nothing here ever changes the events plugin's data.
 */
class CMP_Install {

	const DB_VERSION_OPTION = 'cmp_db_version';

	public static function activate() {
		self::create_tables();
	}

	/**
	 * Administrators get the brief's dedicated "Manage Member Fields"
	 * capability (the profile option lists); event editors never do.
	 */
	private static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( CMP_Profile_Fields::CAP ) ) {
			$admin->add_cap( CMP_Profile_Fields::CAP );
		}
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) < CMP_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cmp_' . $name;
	}

	public static function table_names() {
		return array( self::table( 'audit_log' ), self::table( 'notifications' ), self::table( 'profile_values' ), self::table( 'profile_images' ), self::table( 'dynamics' ), self::table( 'programs' ), self::table( 'tasks' ), self::table( 'task_entries' ), self::table( 'locks' ), self::table( 'lock_events' ), self::table( 'posts' ), self::table( 'post_photos' ), self::table( 'post_likes' ), self::table( 'conversations' ), self::table( 'messages' ), self::table( 'blocks' ), self::table( 'message_reports' ), self::table( 'follows' ), self::table( 'nods' ), self::table( 'dynamic_addons' ), self::table( 'calendar' ) );
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema() as $sql ) {
			dbDelta( $sql );
		}
		self::add_caps();
		// 0.5.0: starter options for any profile list that has none yet.
		CMP_Profile_Fields::seed_defaults();
		CMP_Profile_Fields::upgrade_kinks();
		CMP_Dynamics::migrate_addons(); // 0.16.0, once.
		CMP_Calendar::migrate(); // 0.17.0, once.
		update_option( self::DB_VERSION_OPTION, CMP_DB_VERSION, false );
	}

	/**
	 * All timestamps are stored in UTC (DATETIME, no offset).
	 */
	private static function schema() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// Append-only: CMP_Audit has no update or delete method. old_value
		// and new_value hold JSON; a create stores only new_value, a delete
		// only old_value plus a reason (brief §5).
		$audit = 'CREATE TABLE ' . self::table( 'audit_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL,
			object_type varchar(40) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_value longtext NULL,
			new_value longtext NULL,
			reason text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY object (object_type,object_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) $charset;";

		// deliver_at implements quiet hours: a notification is created
		// immediately but only shown once deliver_at has passed.
		$notifications = 'CREATE TABLE ' . self::table( 'notifications' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			category varchar(40) NOT NULL,
			message text NOT NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			deliver_at datetime NOT NULL,
			read_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_inbox (user_id,deliver_at),
			KEY user_unread (user_id,read_at)
		) $charset;";

		// One row per member per field: the value (JSON; NULL for fields
		// whose value lives elsewhere, e.g. display name, photos, member
		// since) plus the member's visibility and search choices. A missing
		// row means empty, Private and not searchable (brief §2 defaults).
		$values = 'CREATE TABLE ' . self::table( 'profile_values' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			field_key varchar(40) NOT NULL,
			value longtext NULL,
			visibility varchar(12) NOT NULL DEFAULT 'private',
			searchable tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id,field_key),
			KEY field_visibility (field_key,visibility)
		) $charset;";

		// Profile and cover photos, kept in the database (owner decision,
		// 2026-10-04) so no file ever sits at a public media URL. Always a
		// re-encoded JPEG (no camera metadata). Served only through
		// CMP_Profile_Images::serve(), which checks the photo's visibility.
		$images = 'CREATE TABLE ' . self::table( 'profile_images' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(10) NOT NULL,
			mime varchar(20) NOT NULL,
			width smallint(5) unsigned NOT NULL,
			height smallint(5) unsigned NOT NULL,
			bytes int(10) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			data mediumblob NOT NULL,
			alt varchar(150) NOT NULL DEFAULT '',
			decorative tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (user_id,kind)
		) $charset;";

		// Dynamics between two members (0.6.0). proposer_side: 'a' = the
		// proposer is the leading side, 'b' = the partner is, '' = equal type.
		$dynamics = 'CREATE TABLE ' . self::table( 'dynamics' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL,
			proposer_id bigint(20) unsigned NOT NULL,
			partner_id bigint(20) unsigned NOT NULL,
			proposer_side char(1) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'pending',
			message text NULL,
			proposer_show tinyint(1) NOT NULL DEFAULT 1,
			partner_show tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			responded_at datetime NULL,
			ended_at datetime NULL,
			ended_by bigint(20) unsigned NULL,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_proposer (proposer_id,status),
			KEY idx_partner (partner_id,status)
		) $charset;";

		// Chastity and homework add-ons between two members (0.16.0):
		// user_a < user_b; lead_id holds the key / sets homework. group_id
		// links one asked for in an invitation to its dynamics (0 = asked
		// for later on its own).
		$addons = 'CREATE TABLE ' . self::table( 'dynamic_addons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(12) NOT NULL,
			user_a bigint(20) unsigned NOT NULL,
			user_b bigint(20) unsigned NOT NULL,
			lead_id bigint(20) unsigned NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			responded_at datetime NULL,
			ended_at datetime NULL,
			ended_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY idx_pair (user_a,user_b,status),
			KEY idx_group (group_id)
		) $charset;";

		// My calendar (0.17.0): one row per member per event they added.
		// rem_*_for (0.19.0): the start time a reminder was last sent for.
		// response going|interested; audience members|partners|private.
		$calendar = 'CREATE TABLE ' . self::table( 'calendar' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			response varchar(12) NOT NULL DEFAULT 'going',
			audience varchar(12) NOT NULL DEFAULT 'private',
			rem_evening_for varchar(20) NOT NULL DEFAULT '',
			rem_2h_for varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id,event_id),
			KEY idx_event (event_id,response)
		) $charset;";

		// Homework programs (0.7.0): a program per lead + member, its tasks,
		// and one entry per task per day (proof photo kept here, private).
		$programs = 'CREATE TABLE ' . self::table( 'programs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			member_id bigint(20) unsigned NOT NULL,
			title varchar(120) NOT NULL,
			notes text NULL,
			consequences longtext NULL,
			status varchar(12) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_lead (lead_id,status),
			KEY idx_member (member_id,status)
		) $charset;";
		$tasks = 'CREATE TABLE ' . self::table( 'tasks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			program_id bigint(20) unsigned NOT NULL,
			title varchar(120) NOT NULL,
			category varchar(20) NOT NULL DEFAULT 'other',
			weekly_min tinyint(3) unsigned NOT NULL DEFAULT 7,
			what_counts text NULL,
			proof varchar(12) NOT NULL DEFAULT 'none',
			standard varchar(300) NOT NULL DEFAULT '',
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_program (program_id,active)
		) $charset;";
		$entries = 'CREATE TABLE ' . self::table( 'task_entries' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			task_id bigint(20) unsigned NOT NULL,
			program_id bigint(20) unsigned NOT NULL,
			member_id bigint(20) unsigned NOT NULL,
			day date NOT NULL,
			status varchar(10) NOT NULL,
			note text NULL,
			photo mediumblob NULL,
			photo_sha char(64) NOT NULL DEFAULT '',
			review varchar(12) NOT NULL DEFAULT '',
			review_note text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_task_day (task_id,day),
			KEY idx_program_day (program_id,day),
			KEY idx_member (member_id)
		) $charset;";

		// Chastity locks (0.8.0): one row per lock, and its history
		// (verification photos kept here, private to wearer and keyholder).
		$locks  = 'CREATE TABLE ' . self::table( 'locks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wearer_id bigint(20) unsigned NOT NULL,
			keyholder_id bigint(20) unsigned NOT NULL DEFAULT 0,
			keyholder_name varchar(60) NOT NULL DEFAULT '',
			option_key varchar(12) NOT NULL DEFAULT 'custom',
			option_name varchar(60) NOT NULL DEFAULT '',
			rule varchar(300) NOT NULL DEFAULT '',
			release_policy varchar(12) NOT NULL DEFAULT 'none',
			started_at datetime NOT NULL,
			planned_end datetime NULL,
			hide_timer tinyint(1) NOT NULL DEFAULT 0,
			verify_daily tinyint(1) NOT NULL DEFAULT 0,
			hygiene_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
			opened_at datetime NULL,
			release_allowed tinyint(1) NOT NULL DEFAULT 0,
			show_profile tinyint(1) NOT NULL DEFAULT 0,
			status varchar(10) NOT NULL DEFAULT 'locked',
			ended_at datetime NULL,
			ended_by bigint(20) unsigned NULL,
			end_reason varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_wearer (wearer_id,status),
			KEY idx_keyholder (keyholder_id,status)
		) $charset;";
		$events = 'CREATE TABLE ' . self::table( 'lock_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lock_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL,
			minutes int(11) NOT NULL DEFAULT 0,
			note varchar(500) NOT NULL DEFAULT '',
			photo mediumblob NULL,
			photo_sha char(64) NOT NULL DEFAULT '',
			code varchar(8) NOT NULL DEFAULT '',
			review varchar(12) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_lock (lock_id,id),
			KEY idx_lock_type (lock_id,type,created_at)
		) $charset;";

		// Member feed (0.9.0): posts, their photos (kept here, members only)
		// and likes.
		$posts  = 'CREATE TABLE ' . self::table( 'posts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			author_id bigint(20) unsigned NOT NULL,
			body text NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visibility varchar(12) NOT NULL DEFAULT 'members',
			status varchar(10) NOT NULL DEFAULT 'published',
			reports smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_author (author_id,id),
			KEY idx_event (event_id,id),
			KEY idx_status (status,id)
		) $charset;";
		$photos = 'CREATE TABLE ' . self::table( 'post_photos' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			position tinyint(3) unsigned NOT NULL DEFAULT 0,
			photo mediumblob NOT NULL,
			photo_sha char(64) NOT NULL DEFAULT '',
			width smallint(5) unsigned NOT NULL DEFAULT 0,
			height smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_post (post_id,position)
		) $charset;";
		$likes  = 'CREATE TABLE ' . self::table( 'post_likes' ) . " (
			post_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (post_id,user_id),
			KEY idx_user (user_id)
		) $charset;";

		// Messages (0.11.0): one conversation per pair of members (user_a is
		// the lower ID), its messages, blocks, and reports.
		$convs  = 'CREATE TABLE ' . self::table( 'conversations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_a bigint(20) unsigned NOT NULL,
			user_b bigint(20) unsigned NOT NULL,
			started_by bigint(20) unsigned NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'request',
			last_message_at datetime NOT NULL,
			last_sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			a_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
			b_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
			a_deleted_id bigint(20) unsigned NOT NULL DEFAULT 0,
			b_deleted_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_pair (user_a,user_b),
			KEY idx_b (user_b,last_message_at),
			KEY idx_started (started_by,created_at)
		) $charset;";
		$msgs   = 'CREATE TABLE ' . self::table( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_conv (conversation_id,id),
			KEY idx_sender (sender_id)
		) $charset;";
		$blocks = 'CREATE TABLE ' . self::table( 'blocks' ) . " (
			blocker_id bigint(20) unsigned NOT NULL,
			blocked_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (blocker_id,blocked_id),
			KEY idx_blocked (blocked_id)
		) $charset;";
		$reports = 'CREATE TABLE ' . self::table( 'message_reports' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reporter_id bigint(20) unsigned NOT NULL,
			reported_id bigint(20) unsigned NOT NULL,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason varchar(20) NOT NULL,
			note text NULL,
			status varchar(10) NOT NULL DEFAULT 'open',
			closed_by bigint(20) unsigned NULL,
			closed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status,id),
			KEY idx_reported (reported_id)
		) $charset;";

		// Follows (0.12.0).
		$follows = 'CREATE TABLE ' . self::table( 'follows' ) . " (
			follower_id bigint(20) unsigned NOT NULL,
			followed_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (follower_id,followed_id),
			KEY idx_followed (followed_id)
		) $charset;";

		// Nods (0.14.0).
		$nods = 'CREATE TABLE ' . self::table( 'nods' ) . " (
			from_id bigint(20) unsigned NOT NULL,
			to_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			seen_at datetime NULL,
			PRIMARY KEY  (from_id,to_id),
			KEY idx_to (to_id,created_at)
		) $charset;";

		return array( $audit, $notifications, $values, $images, $dynamics, $programs, $tasks, $entries, $locks, $events, $posts, $photos, $likes, $convs, $msgs, $blocks, $reports, $follows, $nods, $addons, $calendar );
	}
}
