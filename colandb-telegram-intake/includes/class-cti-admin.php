<?php
/**
 * Events → Telegram Intake (Administrators): the review queue, each
 * item's evidence and decisions, settings, and the webhook status.
 * Pending events themselves are approved in the usual places (Events list,
 * Calendar Manager dashboard); this screen handles what could not become a
 * pending event on its own.
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Admin {

	const PAGE = 'cti-intake';
	const CAP  = 'manage_options';

	const STATUS_LABELS = array(
		'needs_info'         => 'Needs details',
		'possible_duplicate' => 'Possible duplicate',
		'update_review'      => 'Source edited — review',
		'waiting'            => 'Waiting (rate limit)',
		'dry_run'            => 'Dry run (would submit)',
		'submitted'          => 'Pending approval in calendar',
		'updated'            => 'Updated from edit (pending)',
		'linked'             => 'Linked to existing event',
		'dismissed'          => 'Dismissed',
		'reviewed'           => 'Reviewed',
		'new'                => 'New',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cti_action', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=cec_event', 'Telegram Intake', 'Telegram Intake', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function dependency_notice() {
		if ( current_user_can( self::CAP ) && ! cti_calendar_ready() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( 'COL&B Telegram Event Intake needs Community Events Calendar %s or later. Posts are kept in the queue until it is active.', CTI_MIN_CEC_VERSION ) ) . '</p></div>';
		}
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => 'cec_event', 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'queue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entry = isset( $_GET['entry'] ) ? (int) $_GET['entry'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post  = isset( $_GET['post_row'] ) ? (int) $_GET['post_row'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>Telegram Intake</h1>';
		if ( CTI_Config::dry_run() ) {
			echo '<div class="notice notice-info inline"><p><strong>Dry run is on.</strong> Posts are read and checked, but nothing is sent to the calendar and no emails go out. Turn it off under Settings when you are ready.</p></div>';
		}
		if ( isset( $_GET['cti_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['cti_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['cti_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['cti_err'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'queue' => 'Review queue', 'all' => 'All posts', 'settings' => 'Settings', 'status' => 'Connection' ) as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $key === $tab && ! $entry && ! $post ? 'nav-tab-active' : '', esc_url( self::url( array( 'tab' => $key ) ) ), esc_html( $label ) );
		}
		echo '</nav>';
		if ( $entry ) {
			self::render_entry( $entry );
		} elseif ( $post ) {
			self::render_post( $post );
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'status' === $tab ) {
			self::render_status();
		} else {
			self::render_list( 'all' === $tab );
		}
		echo '</div>';
	}

	private static function render_list( $all ) {
		global $wpdb;
		$e = CTI_Install::table( 'entries' );
		$p = CTI_Install::table( 'posts' );
		if ( $all ) {
			$rows  = $wpdb->get_results( "SELECT e.*, p.channel, p.post_url, p.post_date FROM $e e JOIN $p p ON p.id = e.post_id ORDER BY e.id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = $wpdb->get_results( "SELECT * FROM $p WHERE group_parent = 0 ORDER BY id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows  = $wpdb->get_results( "SELECT e.*, p.channel, p.post_url, p.post_date FROM $e e JOIN $p p ON p.id = e.post_id WHERE e.status IN ('needs_info','possible_duplicate','update_review','waiting','dry_run') ORDER BY e.id ASC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = $wpdb->get_results( "SELECT * FROM $p WHERE status IN ('failed','retry') ORDER BY id ASC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		echo '<h2>' . ( $all ? 'Recent events found' : 'Events that need a decision' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . ( $all ? 'Nothing yet.' : 'Nothing waiting. New pending events appear in the Events list and the Calendar Manager dashboard as usual.' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Date</th><th>Channel</th><th>Status</th><th>Why</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				printf(
					'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( self::url( array( 'entry' => (int) $r['id'] ) ) ),
					esc_html( $r['title'] ? $r['title'] : '(no title)' ),
					esc_html( $r['start_date'] ),
					esc_html( $r['channel'] ),
					esc_html( isset( self::STATUS_LABELS[ $r['status'] ] ) ? self::STATUS_LABELS[ $r['status'] ] : $r['status'] ),
					esc_html( wp_trim_words( $r['reason'], 30 ) )
				);
			}
			echo '</tbody></table>';
		}
		echo '<h2>' . ( $all ? 'Recent posts' : 'Posts that could not be processed' ) . '</h2>';
		if ( ! $posts ) {
			echo '<p>None.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Channel</th><th>Status</th><th>Tries</th><th>Why</th></tr></thead><tbody>';
		foreach ( $posts as $r ) {
			printf(
				'<tr><td><a href="%s">#%d</a> %s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_url( self::url( array( 'post_row' => (int) $r['id'] ) ) ),
				(int) $r['id'],
				esc_html( wp_trim_words( $r['body'], 10 ) ),
				esc_html( $r['channel'] ),
				esc_html( $r['status'] ),
				(int) $r['attempts'],
				esc_html( wp_trim_words( $r['reason'], 30 ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_post( $id ) {
		$post = CTI_Processor::get_post( $id );
		if ( ! $post ) {
			echo '<p>Not found.</p>';
			return;
		}
		self::post_box( $post );
		if ( in_array( $post['status'], array( 'failed', 'retry', 'not_event', 'done' ), true ) ) {
			self::form( 'retry', array( 'post_row' => $id ), 'Process this post again', 'button button-primary' );
		}
		foreach ( CTI_Processor::entries_for_post( $id ) as $entry ) {
			printf( '<p><a href="%s">%s</a> — %s</p>', esc_url( self::url( array( 'entry' => (int) $entry['id'] ) ) ), esc_html( $entry['title'] ? $entry['title'] : '(no title)' ), esc_html( $entry['status'] ) );
		}
		self::log_box( $id );
	}

	private static function post_box( $post ) {
		echo '<div class="postbox" style="padding:12px;margin-top:12px;"><h2 style="margin-top:0;">Source post</h2>';
		printf(
			'<p><strong>%s</strong> · posted %s UTC%s · %s</p>',
			esc_html( $post['channel'] ),
			esc_html( (string) $post['post_date'] ),
			$post['edit_date'] ? ' · edited ' . esc_html( $post['edit_date'] ) . ' UTC' : '',
			$post['post_url'] ? '<a href="' . esc_url( $post['post_url'] ) . '" target="_blank" rel="noopener noreferrer">Open in Telegram</a>' : 'private channel (no public link)'
		);
		$photos = json_decode( (string) $post['photos'], true );
		if ( $photos ) {
			echo '<p>' . esc_html( count( $photos ) . ' image(s) attached. The first becomes the event image unless the channel is set not to share flyers.' ) . '</p>';
		}
		echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;max-height:320px;overflow:auto;">' . esc_html( (string) $post['body'] ) . '</pre>';
		printf( '<p>Status: <code>%s</code>%s</p>', esc_html( $post['status'] ), $post['reason'] ? ' — ' . esc_html( $post['reason'] ) : '' );
		echo '</div>';
	}

	private static function render_entry( $id ) {
		$entry = CTI_Processor::get_entry( $id );
		if ( ! $entry ) {
			echo '<p>Not found.</p>';
			return;
		}
		$post = CTI_Processor::get_post( (int) $entry['post_id'] );
		$norm = json_decode( (string) $entry['proposed'], true );
		$cand = json_decode( (string) $entry['candidates'], true );

		printf( '<h2>%s</h2>', esc_html( $entry['title'] ? $entry['title'] : '(no title)' ) );
		printf( '<p><strong>%s</strong>%s</p>', esc_html( isset( self::STATUS_LABELS[ $entry['status'] ] ) ? self::STATUS_LABELS[ $entry['status'] ] : $entry['status'] ), $entry['reason'] ? ' — ' . esc_html( $entry['reason'] ) : '' );
		if ( (int) $entry['event_id'] ) {
			printf( '<p><a class="button" href="%s">Open calendar event #%d</a></p>', esc_url( admin_url( 'post.php?post=' . (int) $entry['event_id'] . '&action=edit' ) ), (int) $entry['event_id'] );
		}

		// Decisions.
		$open = in_array( $entry['status'], array( 'needs_info', 'possible_duplicate', 'dry_run', 'waiting', 'new' ), true );
		if ( 'possible_duplicate' === $entry['status'] && is_array( $cand ) ) {
			echo '<h3>Possible matches</h3><table class="widefat striped"><thead><tr><th>Existing</th><th>Date</th><th>Status</th><th>Score</th><th>Why it matched</th><th>Decision</th></tr></thead><tbody>';
			foreach ( $cand as $c ) {
				echo '<tr>';
				printf( '<td><a href="%s" target="_blank" rel="noopener">%s</a></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>', esc_url( $c['link'] ), esc_html( $c['title'] ), esc_html( $c['start'] ), esc_html( $c['status'] ), (int) $c['score'], esc_html( implode( '; ', $c['reasons'] ) ) );
				if ( 'event' === $c['kind'] ) {
					self::form( 'link', array( 'entry' => $id, 'event_id' => (int) $c['event_id'] ), 'Same event: link this post to it', 'button' );
				} else {
					self::form( 'dismiss', array( 'entry' => $id, 'reason' => 'Duplicate of intake item #' . (int) $c['id'] ), 'Same event: dismiss this copy', 'button' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table><p>Linking adds this post as another source on the existing event and lists any differences as notes. It never changes the event\'s details; edit them yourself if the new post has corrections.</p>';
		}
		if ( 'update_review' === $entry['status'] ) {
			$diff = is_array( $cand ) && isset( $cand['diff'] ) ? $cand['diff'] : array();
			if ( $diff ) {
				echo '<h3>What the edited post changed</h3><table class="widefat striped"><thead><tr><th>Field</th><th>Calendar now</th><th>Post said</th><th>Post now says</th></tr></thead><tbody>';
				foreach ( $diff as $d ) {
					printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td><strong>%s</strong></td></tr>', esc_html( $d['field'] ), esc_html( $d['event'] ), esc_html( $d['before'] ), esc_html( $d['after'] ) );
				}
				echo '</tbody></table>';
			}
			echo '<p>The calendar event was not changed. Edit it yourself if needed, then mark this reviewed.</p>';
			self::form( 'reviewed', array( 'entry' => $id ), 'Mark reviewed', 'button button-primary' );
		}
		if ( $open ) {
			echo '<h3>Send to the calendar</h3><p>Creates a <strong>pending</strong> event (not published) for approval in the usual way. Fill in anything missing.</p>';
			$f     = isset( $norm['fields'] ) ? $norm['fields'] : array();
			$start = isset( $f['cec_start'] ) ? $f['cec_start'] : '';
			$end   = isset( $f['cec_end'] ) ? $f['cec_end'] : '';
			$st    = $start && isset( $f['cec_time_mode'] ) && 'varies' !== $f['cec_time_mode'] ? substr( $start, 11, 5 ) : '';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'cti_action' );
			echo '<input type="hidden" name="action" value="cti_action"><input type="hidden" name="do" value="create"><input type="hidden" name="entry" value="' . (int) $id . '">';
			echo '<table class="form-table"><tr><th>Title</th><td><input class="regular-text" name="title" value="' . esc_attr( isset( $f['cec_title'] ) ? $f['cec_title'] : $entry['title'] ) . '"></td></tr>';
			echo '<tr><th>Start</th><td><input type="date" name="start_date" value="' . esc_attr( substr( $start, 0, 10 ) ) . '"> <input type="time" name="start_time" value="' . esc_attr( $st ) . '"></td></tr>';
			echo '<tr><th>End</th><td><input type="date" name="end_date" value="' . esc_attr( substr( $end, 0, 10 ) ) . '"> <input type="time" name="end_time" value="' . esc_attr( $end && 'exact' === ( isset( $f['cec_time_mode'] ) ? $f['cec_time_mode'] : '' ) ? substr( $end, 11, 5 ) : '' ) . '"></td></tr></table>';
			submit_button( 'possible_duplicate' === $entry['status'] ? 'This is a separate event: create pending event' : 'Create pending event', 'primary', 'submit', false );
			echo '</form><p></p>';
			self::form( 'dismiss', array( 'entry' => $id ), 'Dismiss (not an event / not for the calendar)', 'button' );
		}

		// Evidence.
		if ( ! empty( $norm['display'] ) ) {
			echo '<h3>What was read, and from where</h3><p>Highlighted rows are uncertain or were changed to follow the calendar\'s rules.</p><table class="widefat striped"><thead><tr><th>Field</th><th>Value</th><th>Confidence</th><th>Evidence</th><th>From</th></tr></thead><tbody>';
			foreach ( $norm['display'] as $d ) {
				if ( '' === $d['value'] && '' === $d['flag'] ) {
					continue;
				}
				printf(
					'<tr%s><td>%s</td><td>%s</td><td>%s</td><td><em>%s</em></td><td>%s</td></tr>',
					$d['flag'] ? ' style="background:#fcf0e3;"' : '',
					esc_html( $d['label'] ),
					esc_html( '' === $d['value'] ? '(not stated)' : $d['value'] ),
					esc_html( round( $d['confidence'] * 100 ) . '%' ),
					esc_html( $d['evidence'] ),
					esc_html( $d['source'] )
				);
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $norm['notes'] ) ) {
			echo '<h3>Notes for the editor</h3><ul style="list-style:disc;margin-left:20px;">';
			foreach ( $norm['notes'] as $n ) {
				echo '<li>' . esc_html( $n ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $post ) {
			self::post_box( $post );
			self::log_box( (int) $post['id'] );
		}
	}

	private static function log_box( $post_id ) {
		echo '<h3>History</h3><table class="widefat striped"><tbody>';
		foreach ( CTI_Log::for_post( $post_id ) as $l ) {
			printf( '<tr><td style="width:160px;">%s UTC</td><td style="width:140px;"><code>%s</code></td><td>%s</td></tr>', esc_html( $l['created_at'] ), esc_html( $l['action'] ), esc_html( mb_substr( $l['detail'], 0, 600 ) ) );
		}
		echo '</tbody></table>';
	}

	private static function form( $do, $fields, $label, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px 4px 2px 0;">';
		wp_nonce_field( 'cti_action' );
		echo '<input type="hidden" name="action" value="cti_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function render_settings() {
		$s = CTI_Config::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'cti_action' );
		echo '<input type="hidden" name="action" value="cti_action"><input type="hidden" name="do" value="settings">';
		echo '<h2>Secrets</h2><p>Paste a value and save. Saved values are never shown again; paste a new one to replace it.</p><table class="form-table">';
		$secret_rows = array(
			'COLANDB_TG_BOT_TOKEN'         => array( 'Telegram bot token', 'From @BotFather (looks like 123456789:AAE…).' ),
			'COLANDB_INTAKE_ANTHROPIC_KEY' => array( 'Claude API key', 'From console.anthropic.com (starts with sk-ant-).' ),
		);
		foreach ( $secret_rows as $name => $row ) {
			$src = CTI_Config::secret_source( $name );
			echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $row[0] ) . '</label></th><td>';
			if ( in_array( $src, array( 'wp-config', 'environment' ), true ) ) {
				echo '<p><strong>Set in ' . esc_html( 'wp-config' === $src ? 'wp-config.php' : 'the server environment' ) . '.</strong> Change it there.</p>';
			} else {
				echo '<p>' . ( $src ? '<strong style="color:#008a20;">Saved ✓</strong>' : '<strong style="color:#b32d2e;">Not set</strong>' ) . '</p>';
				echo '<input type="password" class="regular-text" id="' . esc_attr( $name ) . '" name="secret[' . esc_attr( $name ) . ']" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $src ? 'Paste a new value to replace it' : 'Paste it here' ) . '">';
				if ( $src ) {
					echo ' <label><input type="checkbox" name="clear_secret[' . esc_attr( $name ) . ']" value="1"> Remove</label>';
				}
				echo '<p class="description">' . esc_html( $row[1] ) . '</p>';
			}
			echo '</td></tr>';
		}
		$wsrc = CTI_Config::secret_source( 'COLANDB_TG_WEBHOOK_SECRET' );
		echo '<tr><th>Webhook secret</th><td><p>' . ( $wsrc ? '<strong style="color:#008a20;">Set ✓</strong> (' . esc_html( 'settings' === $wsrc ? 'made by this site' : ( 'wp-config' === $wsrc ? 'in wp-config.php' : 'in the server environment' ) ) . ')' : 'Made automatically when you connect the webhook.' ) . '</p><p class="description">Nobody needs to type this one. Only Telegram and this site use it.</p></td></tr>';
		echo '</table><h2>Channels and reading</h2><table class="form-table">';
		echo '<tr><th>Approved channels</th><td><textarea name="channels" rows="6" class="large-text code">' . esc_textarea( CTI_Config::channels_text( $s['channels'] ) ) . '</textarea>';
		echo '<p class="description">One channel or group per line: <code>ID or @username | use flyers (yes/no) | label</code>. Channel example: <code>-1001234567890 | yes | Kink 101</code>. Group example: <code>-1003923571403 | yes | COL&amp;B group | topics: 129, 275, 573</code> reads only those topics (members chatting elsewhere is ignored); add <code>| admins</code> to read only the group\'s admins. Prefer the numeric ID (it never changes). Anything not on this list is ignored. A post\'s flyer becomes the event image unless the line says "no".</p>';
		$topics = get_option( 'cti_seen_topics', array() );
		if ( $topics ) {
			echo '<p class="description"><strong>Topics skipped in listed groups:</strong> ';
			foreach ( $topics as $t ) {
				echo esc_html( $t['chat'] . ' topic ' . $t['topic'] . ( $t['name'] ? ' "' . $t['name'] . '"' : '' ) ) . '; ';
			}
			echo '</p>';
		}
		$unknown = get_option( 'cti_unknown_chats', array() );
		if ( $unknown ) {
			echo '<p class="description"><strong>Recently ignored (not on the list):</strong> ';
			foreach ( $unknown as $u ) {
				echo esc_html( $u['id'] . ( $u['username'] ? ' @' . $u['username'] : '' ) . ( $u['title'] ? ' "' . $u['title'] . '"' : '' ) . ( ! empty( $u['type'] ) ? ' (' . $u['type'] . ( ! empty( $u['topic'] ) ? ', topic ' . $u['topic'] : '' ) . ')' : '' ) ) . '; ';
			}
			echo '</p>';
		}
		echo '</td></tr>';
		printf( '<tr><th>Dry run</th><td><label><input type="checkbox" name="dry_run" value="1" %s> Read and check posts, but do not send anything to the calendar or email anyone</label></td></tr>', checked( $s['dry_run'], true, false ) );
		echo '<tr><th>Reader</th><td><select name="extractor">';
		foreach ( array_keys( CTI_Extractors::available() ) as $id ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $id ), selected( $s['extractor'], $id, false ), esc_html( 'anthropic' === $id ? 'Claude (text and flyer images)' : ( 'text_rules' === $id ? 'Text patterns only (no outside service; always needs an editor)' : $id ) ) );
		}
		echo '</select> Model <input name="model" value="' . esc_attr( $s['model'] ) . '"> Effort <select name="effort">';
		foreach ( array( 'low', 'medium', 'high' ) as $e ) {
			printf( '<option %s>%s</option>', selected( $s['effort'], $e, false ), esc_html( $e ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>Notify</th><td><input class="regular-text" name="notify_emails" value="' . esc_attr( $s['notify_emails'] ) . '"><p class="description">Editors to email (comma separated). Empty: the site admin address.</p></td></tr>';
		$num = array(
			'max_image_mb'             => array( 'Largest image (MB)', $s['max_image_bytes'] / MB_IN_BYTES ),
			'http_timeout'             => array( 'Reader time limit (seconds)', $s['http_timeout'] ),
			'max_attempts'             => array( 'Tries before giving up', $s['max_attempts'] ),
			'max_submissions_per_hour' => array( 'Most events submitted per hour', $s['max_submissions_per_hour'] ),
			'max_posts_per_run'        => array( 'Posts per run (every 5 minutes)', $s['max_posts_per_run'] ),
			'min_confidence'           => array( 'Confidence needed (0-1)', $s['min_confidence'] ),
			'duplicate_threshold'      => array( 'Duplicate score threshold', $s['duplicate_threshold'] ),
			'author_id'                => array( 'Author for submitted events (user ID, 0 = none)', $s['author_id'] ),
		);
		foreach ( $num as $key => $row ) {
			printf( '<tr><th>%s</th><td><input type="number" step="any" min="0" name="%s" value="%s"></td></tr>', esc_html( $row[0] ), esc_attr( $key ), esc_attr( $row[1] ) );
		}
		echo '<tr><th>Image types</th><td>';
		foreach ( array( 'image/jpeg', 'image/png', 'image/webp' ) as $t ) {
			printf( '<label style="margin-right:12px;"><input type="checkbox" name="image_types[]" value="%s" %s> %s</label>', esc_attr( $t ), checked( in_array( $t, (array) $s['image_types'], true ), true, false ), esc_html( $t ) );
		}
		echo '</td></tr></table>';
		submit_button( 'Save settings' );
		echo '</form>';
	}

	private static function render_status() {
		$rows = array(
			'Bot token'                                      => CTI_Config::bot_token() ? 'set (' . CTI_Config::secret_source( 'COLANDB_TG_BOT_TOKEN' ) . ')' : 'missing: add it under Settings',
			'Webhook secret'                                 => strlen( CTI_Config::webhook_secret() ) >= 32 ? 'set (' . CTI_Config::secret_source( 'COLANDB_TG_WEBHOOK_SECRET' ) . ')' : 'made when you connect the webhook',
			'Claude API key'                                 => CTI_Config::anthropic_key() ? 'set (' . CTI_Config::secret_source( 'COLANDB_INTAKE_ANTHROPIC_KEY' ) . ')' : 'missing: add it under Settings',
			'Calendar plugin'                                => cti_calendar_ready() ? 'ready (' . CEC_VERSION . ')' : 'needs Community Events Calendar ' . CTI_MIN_CEC_VERSION . '+',
			'Webhook address'                                => CTI_Receiver::webhook_url(),
			'Next scheduled run'                             => wp_next_scheduled( CTI_Processor::HOOK ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( CTI_Processor::HOOK ) ) . ' UTC' : 'not scheduled',
		);
		if ( CTI_Config::bot_token() ) {
			$me   = CTI_Telegram::call( 'getMe' );
			$info = CTI_Telegram::call( 'getWebhookInfo' );
			$rows['Bot'] = is_wp_error( $me ) ? $me->get_error_message() : '@' . $me['username'];
			if ( is_wp_error( $info ) ) {
				$rows['Telegram webhook'] = $info->get_error_message();
			} else {
				$rows['Telegram webhook']   = $info['url'] ? $info['url'] : 'not set (long polling or not connected)';
				$rows['Waiting at Telegram'] = (string) (int) $info['pending_update_count'];
				if ( ! empty( $info['last_error_message'] ) ) {
					$rows['Last delivery error'] = CTI_Log::redact( $info['last_error_message'] ) . ' (' . gmdate( 'Y-m-d H:i', (int) $info['last_error_date'] ) . ' UTC)';
				}
			}
		}
		echo '<table class="widefat striped" style="max-width:900px;margin-top:12px;"><tbody>';
		foreach ( $rows as $k => $v ) {
			printf( '<tr><th style="width:320px;">%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( $v ) );
		}
		echo '</tbody></table><p>';
		self::form( 'set_webhook', array(), 'Connect webhook', 'button button-primary' );
		self::form( 'delete_webhook', array(), 'Disconnect webhook', 'button' );
		self::form( 'process', array(), 'Process the queue now', 'button' );
		echo '</p>';
	}

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'cti_action' );
		$do    = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$entry = isset( $_POST['entry'] ) ? (int) $_POST['entry'] : 0;
		$user  = get_current_user_id();
		$back  = $entry ? self::url( array( 'entry' => $entry ) ) : self::url();
		$r     = true;
		$msg   = 'Done.';
		switch ( $do ) {
			case 'create':
				$o = array();
				foreach ( array( 'title', 'start_date', 'start_time', 'end_date', 'end_time' ) as $k ) {
					$o[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
				}
				foreach ( array( 'start_date', 'end_date' ) as $k ) {
					$o[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $o[ $k ] ) ? $o[ $k ] : '';
				}
				foreach ( array( 'start_time', 'end_time' ) as $k ) {
					$o[ $k ] = preg_match( '/^\d{2}:\d{2}$/', $o[ $k ] ) ? $o[ $k ] : '';
				}
				$r   = CTI_Processor::editor_create( $entry, $o, $user );
				$msg = is_wp_error( $r ) ? '' : 'Pending event #' . $r . ' created. It still needs approval in the calendar.';
				break;
			case 'link':
				$r   = CTI_Processor::editor_link( $entry, isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0, $user );
				$msg = 'Linked. The event now lists this post as a source.';
				break;
			case 'dismiss':
				$r = CTI_Processor::editor_set( $entry, 'dismissed', isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : 'Dismissed by an editor.', $user ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				break;
			case 'reviewed':
				$r = CTI_Processor::editor_set( $entry, 'reviewed', 'Reviewed by an editor.', $user );
				break;
			case 'retry':
				$post_row = isset( $_POST['post_row'] ) ? (int) $_POST['post_row'] : 0;
				CTI_Processor::editor_retry( $post_row );
				$back = self::url( array( 'post_row' => $post_row ) );
				$msg  = 'Queued. It will be processed within a few minutes.';
				break;
			case 'settings':
				$problems = self::save_settings();
				$back     = self::url( array( 'tab' => 'settings' ) );
				$msg      = 'Settings saved.';
				if ( $problems ) {
					$r = new WP_Error( 'cti_secret', 'Settings saved, except: ' . implode( ' ', $problems ) );
				}
				break;
			case 'set_webhook':
				$r    = CTI_Telegram::set_webhook( CTI_Receiver::webhook_url() );
				$back = self::url( array( 'tab' => 'status' ) );
				$msg  = 'Webhook connected.';
				break;
			case 'delete_webhook':
				$r    = CTI_Telegram::call( 'deleteWebhook' );
				$back = self::url( array( 'tab' => 'status' ) );
				$msg  = 'Webhook disconnected.';
				break;
			case 'process':
				$n    = CTI_Processor::run();
				$back = self::url();
				$msg  = $n . ' post(s) processed.';
				break;
		}
		$arg = is_wp_error( $r ) ? array( 'cti_err' => rawurlencode( $r->get_error_message() ) ) : array( 'cti_msg' => rawurlencode( $msg ) );
		wp_safe_redirect( add_query_arg( $arg, $back ) );
		exit;
	}

	/** Public for the test kit; handle() checks the capability and nonce first. */
	public static function save_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$problems = array();
		foreach ( array( 'COLANDB_TG_BOT_TOKEN', 'COLANDB_INTAKE_ANTHROPIC_KEY' ) as $name ) {
			if ( in_array( CTI_Config::secret_source( $name ), array( 'wp-config', 'environment' ), true ) ) {
				continue;
			}
			if ( ! empty( $_POST['clear_secret'][ $name ] ) ) {
				CTI_Config::save_secret( $name, '' );
				CTI_Log::add( 0, 0, 'secret_removed', $name . ' removed by user #' . get_current_user_id() );
				continue;
			}
			$value = isset( $_POST['secret'][ $name ] ) ? trim( (string) wp_unslash( $_POST['secret'][ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by valid_secret(), never output.
			if ( '' === $value ) {
				continue;
			}
			if ( CTI_Config::valid_secret( $name, $value ) ) {
				CTI_Config::save_secret( $name, $value );
				CTI_Log::add( 0, 0, 'secret_saved', $name . ' saved by user #' . get_current_user_id() );
			} else {
				$problems[] = 'COLANDB_TG_BOT_TOKEN' === $name ? 'That bot token does not look right (it should look like 123456789:AAE…). It was not saved.' : 'That Claude API key does not look right (it should start with sk-ant-). It was not saved.';
			}
		}
		$s                  = CTI_Config::all();
		$s['channels']      = CTI_Config::parse_channels( isset( $_POST['channels'] ) ? sanitize_textarea_field( wp_unslash( $_POST['channels'] ) ) : '' );
		$s['dry_run']       = ! empty( $_POST['dry_run'] );
		$ext                = isset( $_POST['extractor'] ) ? sanitize_key( $_POST['extractor'] ) : 'anthropic';
		$s['extractor']     = isset( CTI_Extractors::available()[ $ext ] ) ? $ext : 'anthropic';
		$model              = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$s['model']         = preg_match( '/^[a-z0-9.-]{3,60}$/', $model ) ? $model : 'claude-opus-5-5';
		$effort             = isset( $_POST['effort'] ) ? sanitize_key( $_POST['effort'] ) : 'medium';
		$s['effort']        = in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : 'medium';
		$s['notify_emails'] = isset( $_POST['notify_emails'] ) ? implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', wp_unslash( $_POST['notify_emails'] ) ) ), 'is_email' ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$num                = static function ( $key, $min, $max, $def ) {
			$v = isset( $_POST[ $key ] ) ? (float) $_POST[ $key ] : $def;
			return max( $min, min( $max, $v ) );
		};
		$s['max_image_bytes']          = (int) round( $num( 'max_image_mb', 0.1, 20, 5 ) * MB_IN_BYTES );
		$s['http_timeout']             = (int) $num( 'http_timeout', 10, 300, 90 );
		$s['max_attempts']             = (int) $num( 'max_attempts', 1, 10, 5 );
		$s['max_submissions_per_hour'] = (int) $num( 'max_submissions_per_hour', 1, 200, 20 );
		$s['max_posts_per_run']        = (int) $num( 'max_posts_per_run', 1, 50, 5 );
		$s['min_confidence']           = round( $num( 'min_confidence', 0, 1, 0.6 ), 2 );
		$s['duplicate_threshold']      = (int) $num( 'duplicate_threshold', 20, 200, 50 );
		$s['author_id']                = (int) $num( 'author_id', 0, PHP_INT_MAX, 0 );
		$types                         = isset( $_POST['image_types'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['image_types'] ) ) : array();
		$s['image_types']              = array_values( array_intersect( $types, array( 'image/jpeg', 'image/png', 'image/webp' ) ) );
		// phpcs:enable
		update_option( CTI_Config::OPTION, $s, false );
		return $problems;
	}
}
