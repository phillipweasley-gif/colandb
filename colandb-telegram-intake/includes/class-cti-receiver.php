<?php
/**
 * Receives Telegram updates (webhook or long polling) and stores them.
 *
 * Nothing slow happens here: the webhook only checks the secret header,
 * records the update ID (so a re-delivered update is ignored), checks the
 * channel allowlist and saves the post for the processor (WP-Cron).
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Receiver {

	const NAMESPACE_ = 'colandb-intake/v1';

	/** Channel posts, and (0.3.0) group messages, new and edited. */
	const UPDATE_TYPES = array( 'channel_post', 'edited_channel_post', 'message', 'edited_message' );
	const MAX_BODY   = 1048576;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function webhook_url() {
		return rest_url( self::NAMESPACE_ . '/telegram' );
	}

	public static function routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run_now' ),
				'permission_callback' => array( __CLASS__, 'check_run_key' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/telegram',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Processing right away (0.3.2), without waiting for WP-Cron
	 * ---------------------------------------------------------------- */

	/** Key for the internal /run call: derived from the webhook secret, never stored. */
	private static function run_key() {
		return hash_hmac( 'sha256', 'cti-run', CTI_Config::webhook_secret() );
	}

	public static function check_run_key( $request ) {
		$given = (string) $request->get_header( 'X-CTI-Run-Key' );
		return strlen( CTI_Config::webhook_secret() ) >= 32 && '' !== $given && hash_equals( self::run_key(), $given )
			? true
			: new WP_Error( 'cti_forbidden', 'Forbidden.', array( 'status' => 401 ) );
	}

	/**
	 * After a new or edited post is stored, the site calls itself (without
	 * waiting for the answer) to process it once the album wait has passed.
	 * WP-Cron stays as the backup. Only one of these waits at a time.
	 */
	public static function kick() {
		if ( ! apply_filters( 'cti_process_on_arrival', true ) || get_transient( 'cti_kick_waiting' ) ) {
			return;
		}
		set_transient( 'cti_kick_waiting', 1, 5 * MINUTE_IN_SECONDS );
		wp_remote_post(
			rest_url( self::NAMESPACE_ . '/run' ),
			array(
				'blocking'  => false,
				'timeout'   => 1,
				'headers'   => array( 'X-CTI-Run-Key' => self::run_key() ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	public static function run_now() {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		nocache_headers();
		$done = 0;
		// Up to 4 rounds, so posts that arrive while this one waits are
		// picked up too.
		for ( $round = 0; $round < 4; $round++ ) {
			$due = CTI_Processor::next_due_in();
			if ( $due < 0 ) {
				break;
			}
			if ( $due > 0 ) {
				sleep( min( $due + 2, 120 ) );
			}
			$done += CTI_Processor::run( 0, 120 );
		}
		delete_transient( 'cti_kick_waiting' );
		// Something arrived during the last round: start another.
		if ( CTI_Processor::next_due_in() >= 0 ) {
			self::kick();
		}
		return rest_ensure_response( array( 'ok' => true, 'processed' => $done ) );
	}

	/** Telegram sends the secret in X-Telegram-Bot-Api-Secret-Token. */
	public static function check_secret( $request ) {
		$secret = CTI_Config::webhook_secret();
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error( 'cti_not_configured', 'Not configured.', array( 'status' => 503 ) );
		}
		$given = (string) $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );
		if ( '' === $given || ! hash_equals( $secret, $given ) ) {
			return new WP_Error( 'cti_forbidden', 'Forbidden.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function webhook( $request ) {
		nocache_headers();
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'cti_too_large', 'Too large.', array( 'status' => 413 ) );
		}
		$update = $request->get_json_params();
		$result = is_array( $update ) ? self::receive( $update ) : 'invalid';
		if ( in_array( $result, array( 'stored', 'edited' ), true ) ) {
			self::kick();
		}
		// Always 200 once the secret matched: anything else makes Telegram
		// re-send the same update, which helps nobody.
		return rest_ensure_response( array( 'ok' => true, 'result' => $result ) );
	}

	/**
	 * @return string What happened: stored, edited, duplicate, ignored,
	 *                not_allowed, unchanged or invalid.
	 */
	public static function receive( $update ) {
		global $wpdb;
		if ( ! isset( $update['update_id'] ) || ! is_numeric( $update['update_id'] ) ) {
			return 'invalid';
		}
		$update_id = (int) $update['update_id'];

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . CTI_Install::table( 'updates' ) . ' (update_id, received_at) VALUES (%d, %s)', $update_id, current_time( 'mysql', true ) ) );
		$wpdb->suppress_errors( $suppress );
		if ( ! $inserted ) {
			return 'duplicate';
		}

		$kind = '';
		foreach ( self::UPDATE_TYPES as $type ) {
			if ( isset( $update[ $type ] ) && is_array( $update[ $type ] ) ) {
				$kind = $type;
				break;
			}
		}
		$msg     = $kind ? $update[ $kind ] : null;
		$is_edit = 0 === strpos( $kind, 'edited_' );
		if ( ! is_array( $msg ) || ! isset( $msg['chat']['type'], $msg['message_id'] ) ) {
			return 'ignored';
		}
		$type = (string) $msg['chat']['type'];
		if ( 'channel' === $type ) {
			if ( false === strpos( $kind, 'channel_post' ) ) {
				return 'ignored';
			}
		} elseif ( in_array( $type, array( 'group', 'supergroup' ), true ) ) {
			if ( false !== strpos( $kind, 'channel_post' ) ) {
				return 'ignored';
			}
		} else {
			return 'ignored'; // Private chats with the bot are never read.
		}
		$chat    = $msg['chat'];
		$channel = CTI_Config::channel_for( $chat );
		if ( ! $channel ) {
			self::remember_unknown_chat( $chat, $msg );
			return 'not_allowed';
		}
		if ( 'channel' !== $type ) {
			$why = self::group_filter( $msg, $channel );
			if ( '' !== $why ) {
				return $why;
			}
		}

		$row = self::row_from_message( $msg, $channel, $update_id );
		return self::store( $row, $is_edit );
	}

	/**
	 * Group messages (0.3.0): only real posts in the allowed topics (and,
	 * with "admins", only from administrators) are kept. Nothing is stored
	 * for a message that doesn't pass, and the sender is never stored.
	 *
	 * @return string '' to keep it, or why it was skipped.
	 */
	private static function group_filter( $msg, $channel ) {
		if ( ! empty( $msg['from']['is_bot'] ) && empty( $msg['sender_chat'] ) ) {
			return 'filtered_bot';
		}
		$has_content = isset( $msg['text'] ) || isset( $msg['caption'] ) || isset( $msg['photo'] ) || isset( $msg['document'] );
		if ( ! $has_content ) {
			return 'filtered_service';
		}
		if ( ! empty( $channel['topics'] ) ) {
			$topic = ! empty( $msg['is_topic_message'] ) && isset( $msg['message_thread_id'] ) ? (int) $msg['message_thread_id'] : 0;
			if ( ! $topic || ! in_array( $topic, array_map( 'intval', $channel['topics'] ), true ) ) {
				self::remember_topic( $msg );
				return 'filtered_topic';
			}
		}
		if ( ! empty( $channel['admins_only'] ) ) {
			$sent_as_group = isset( $msg['sender_chat']['id'] ) && (string) $msg['sender_chat']['id'] === (string) $msg['chat']['id'];
			$from          = isset( $msg['from']['id'] ) ? (int) $msg['from']['id'] : 0;
			if ( ! $sent_as_group && ! in_array( $from, self::admin_ids( (string) $msg['chat']['id'] ), true ) ) {
				return 'filtered_not_admin';
			}
		}
		return '';
	}

	/** The group's administrators' user IDs, asked of Telegram at most once an hour. */
	private static function admin_ids( $chat_id ) {
		$key = 'cti_admins_' . md5( $chat_id );
		$ids = get_transient( $key );
		if ( is_array( $ids ) ) {
			return $ids;
		}
		$admins = CTI_Telegram::call( 'getChatAdministrators', array( 'chat_id' => $chat_id ) );
		$ids    = array();
		if ( ! is_wp_error( $admins ) ) {
			foreach ( (array) $admins as $a ) {
				if ( isset( $a['user']['id'] ) ) {
					$ids[] = (int) $a['user']['id'];
				}
			}
		}
		set_transient( $key, $ids, is_wp_error( $admins ) ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * Topic numbers seen in an allowed group (not their messages), so an
	 * administrator can check which topic is which. Last 20.
	 */
	private static function remember_topic( $msg ) {
		$topic = ! empty( $msg['is_topic_message'] ) && isset( $msg['message_thread_id'] ) ? (int) $msg['message_thread_id'] : 0;
		$seen  = get_option( 'cti_seen_topics', array() );
		$seen  = is_array( $seen ) ? $seen : array();
		$key   = (string) $msg['chat']['id'] . ':' . $topic;
		$seen[ $key ] = array(
			'chat'  => (string) $msg['chat']['id'],
			'topic' => $topic ? $topic : 'General (no topic)',
			'name'  => isset( $msg['reply_to_message']['forum_topic_created']['name'] ) ? sanitize_text_field( $msg['reply_to_message']['forum_topic_created']['name'] ) : '',
			'seen'  => current_time( 'mysql', true ),
		);
		update_option( 'cti_seen_topics', array_slice( $seen, -20, 20, true ), false );
	}

	/**
	 * Only channel-level data is kept: the chat ID and username, the post
	 * and its links and photo IDs. Author signatures and any user fields
	 * are not stored.
	 */
	private static function row_from_message( $msg, $channel, $update_id ) {
		$chat_id  = (string) $msg['chat']['id'];
		$username = isset( $msg['chat']['username'] ) ? (string) $msg['chat']['username'] : '';
		$text     = isset( $msg['text'] ) ? (string) $msg['text'] : ( isset( $msg['caption'] ) ? (string) $msg['caption'] : '' );
		$entities = isset( $msg['entities'] ) ? $msg['entities'] : ( isset( $msg['caption_entities'] ) ? $msg['caption_entities'] : array() );

		$photo = null;
		if ( ! empty( $msg['photo'] ) && is_array( $msg['photo'] ) ) {
			$big   = end( $msg['photo'] );
			$photo = array(
				'message_id' => (int) $msg['message_id'],
				'file_id'    => (string) $big['file_id'],
				'unique_id'  => isset( $big['file_unique_id'] ) ? (string) $big['file_unique_id'] : '',
				'size'       => isset( $big['file_size'] ) ? (int) $big['file_size'] : 0,
				'mime'       => 'image/jpeg',
			);
		} elseif ( ! empty( $msg['document']['mime_type'] ) && 0 === strpos( (string) $msg['document']['mime_type'], 'image/' ) ) {
			$photo = array(
				'message_id' => (int) $msg['message_id'],
				'file_id'    => (string) $msg['document']['file_id'],
				'unique_id'  => isset( $msg['document']['file_unique_id'] ) ? (string) $msg['document']['file_unique_id'] : '',
				'size'       => isset( $msg['document']['file_size'] ) ? (int) $msg['document']['file_size'] : 0,
				'mime'       => (string) $msg['document']['mime_type'],
			);
		}

		$topic = ! empty( $msg['is_topic_message'] ) && isset( $msg['message_thread_id'] ) ? (int) $msg['message_thread_id'] . '/' : '';
		if ( $username ) {
			$url = 'https://t.me/' . rawurlencode( $username ) . '/' . $topic . (int) $msg['message_id'];
		} elseif ( 0 === strpos( $chat_id, '-100' ) ) {
			$url = 'https://t.me/c/' . substr( $chat_id, 4 ) . '/' . $topic . (int) $msg['message_id'];
		} else {
			$url = '';
		}

		return array(
			'chat_id'        => $chat_id,
			'message_id'     => (int) $msg['message_id'],
			'channel'        => $channel['label'] ? $channel['label'] : ( $username ? '@' . $username : ( isset( $msg['chat']['title'] ) ? sanitize_text_field( $msg['chat']['title'] ) : $chat_id ) ),
			'post_url'       => $url,
			'post_date'      => isset( $msg['date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $msg['date'] ) : null,
			'edit_date'      => isset( $msg['edit_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $msg['edit_date'] ) : null,
			'last_update_id' => $update_id,
			'media_group'    => isset( $msg['media_group_id'] ) ? substr( (string) $msg['media_group_id'], 0, 64 ) : '',
			'body'           => wp_check_invalid_utf8( mb_substr( $text, 0, 10000 ) ),
			'links'          => self::links( $text, $entities ),
			'photo'          => $photo,
			// Kept for a few minutes only (a transient), to join a flyer and
			// its text sent as two messages by the same person. A salted hash:
			// the sender's Telegram ID is never stored.
			'sender'         => hash_hmac( 'sha256', (string) ( isset( $msg['from']['id'] ) ? $msg['from']['id'] : ( isset( $msg['sender_chat']['id'] ) ? $msg['sender_chat']['id'] : $chat_id ) ), wp_salt( 'auth' ) ),
			'thread'         => ! empty( $msg['is_topic_message'] ) && isset( $msg['message_thread_id'] ) ? (int) $msg['message_thread_id'] : 0,
		);
	}

	/** Links from text_link entities (hidden URLs) and url entities. */
	public static function links( $text, $entities ) {
		$out   = array();
		$utf16 = mb_convert_encoding( (string) $text, 'UTF-16LE', 'UTF-8' );
		foreach ( (array) $entities as $e ) {
			if ( ! isset( $e['type'] ) ) {
				continue;
			}
			if ( 'text_link' === $e['type'] && ! empty( $e['url'] ) ) {
				$out[] = (string) $e['url'];
			} elseif ( 'url' === $e['type'] && isset( $e['offset'], $e['length'] ) ) {
				$out[] = mb_convert_encoding( substr( $utf16, 2 * (int) $e['offset'], 2 * (int) $e['length'] ), 'UTF-8', 'UTF-16LE' );
			}
		}
		$clean = array();
		foreach ( $out as $url ) {
			$url = trim( $url );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( $url ) {
				$clean[] = $url;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	private static function store( $row, $is_edit ) {
		global $wpdb;
		$t   = CTI_Install::table( 'posts' );
		$now = current_time( 'mysql', true );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE chat_id = %s AND message_id = %d", $row['chat_id'], $row['message_id'] ), ARRAY_A );

		// An album arrives as one message per photo, sharing media_group_id;
		// usually only one carries the text. They are processed as one post.
		$parent = null;
		if ( ! $existing && $row['media_group'] ) {
			$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE chat_id = %s AND media_group = %s AND group_parent = 0 ORDER BY id ASC LIMIT 1", $row['chat_id'], $row['media_group'] ), ARRAY_A );
		}
		if ( $existing && $existing['group_parent'] ) {
			$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $existing['group_parent'] ), ARRAY_A );
		}
		if ( ! $existing && ! $parent && ! $is_edit && ! $row['media_group'] ) {
			$parent = self::companion( $row );
		}

		if ( $parent ) {
			if ( ! $existing ) {
				$wpdb->insert( $t, self::db_row( $row, array( 'group_parent' => (int) $parent['id'], 'status' => 'merged', 'reason' => ( $row['media_group'] ? 'Part of an album' : 'Sent with post #' . (int) $parent['id'] . ' (flyer and text as two messages)' ) . '; processed with post #' . (int) $parent['id'], 'created_at' => $now ) ) );
			} else {
				$wpdb->update( $t, array( 'body' => $row['body'], 'edit_date' => $row['edit_date'], 'updated_at' => $now, 'last_update_id' => $row['last_update_id'] ), array( 'id' => (int) $existing['id'] ) );
			}
			$photos = self::merge_photo( json_decode( (string) $parent['photos'], true ), $row['photo'], $row['message_id'] );
			$body   = '' !== trim( (string) $parent['body'] ) ? (string) $parent['body'] : $row['body'];
			if ( $existing && '' !== trim( $row['body'] ) && (int) $existing['message_id'] === (int) $row['message_id'] && '' === trim( (string) $parent['body'] ) ) {
				$body = $row['body'];
			}
			$links = array_values( array_unique( array_merge( (array) json_decode( (string) $parent['links'], true ), $row['links'] ) ) );
			return self::save_changes( $parent, $body, $links, $photos, $row, $is_edit || 'received' !== $parent['status'] );
		}

		if ( ! $existing ) {
			$photos = $row['photo'] ? array( $row['photo'] ) : array();
			$data   = self::db_row( $row, array( 'photos' => wp_json_encode( $photos ), 'content_hash' => self::hash( $row['body'], $photos ), 'created_at' => $now ) );
			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert( $t, $data );
			$wpdb->suppress_errors( $suppress );
			if ( ! $ok ) {
				return 'duplicate';
			}
			set_transient( 'cti_sender_' . (int) $wpdb->insert_id, $row['sender'] . '|' . $row['thread'], 5 * MINUTE_IN_SECONDS );
			CTI_Log::add( (int) $wpdb->insert_id, 0, 'received', $is_edit ? 'First seen as an edit.' : '' );
			CTI_Processor::schedule_soon();
			return 'stored';
		}

		$photos = self::merge_photo( json_decode( (string) $existing['photos'], true ), $row['photo'], $row['message_id'] );
		return self::save_changes( $existing, $row['body'], $row['links'], $photos, $row, true );
	}

	/**
	 * A flyer and its text often arrive as two messages: a photo with no
	 * caption, then the text, or the other way round. When the same sender
	 * posts the other half in the same chat and topic within 90 seconds,
	 * they are treated as one post (like an album).
	 */
	private static function companion( $row ) {
		global $wpdb;
		$has_text  = '' !== trim( (string) $row['body'] );
		$has_photo = (bool) $row['photo'];
		if ( $has_text === $has_photo ) {
			return null; // Already complete (text and photo), or neither.
		}
		$t    = CTI_Install::table( 'posts' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE chat_id = %s AND group_parent = 0 AND media_group = '' AND created_at >= %s ORDER BY id DESC LIMIT 5", $row['chat_id'], gmdate( 'Y-m-d H:i:s', time() - 90 ) ), ARRAY_A );
		foreach ( $rows as $cand ) {
			if ( get_transient( 'cti_sender_' . (int) $cand['id'] ) !== $row['sender'] . '|' . $row['thread'] ) {
				continue;
			}
			$cand_text  = '' !== trim( (string) $cand['body'] );
			$cand_photo = (bool) json_decode( (string) $cand['photos'], true );
			if ( ( $has_photo && $cand_text && ! $cand_photo ) || ( $has_text && $cand_photo && ! $cand_text ) ) {
				return $cand;
			}
		}
		return null;
	}

	private static function save_changes( $post, $body, $links, $photos, $row, $is_edit ) {
		global $wpdb;
		$hash = self::hash( $body, $photos );
		$data = array(
			'body'           => $body,
			'links'          => wp_json_encode( array_values( $links ) ),
			'photos'         => wp_json_encode( array_values( $photos ) ),
			'last_update_id' => max( (int) $post['last_update_id'], (int) $row['last_update_id'] ),
			'updated_at'     => current_time( 'mysql', true ),
		);
		if ( $row['edit_date'] ) {
			$data['edit_date'] = $row['edit_date'];
		}
		if ( $hash === $post['content_hash'] ) {
			$wpdb->update( CTI_Install::table( 'posts' ), $data, array( 'id' => (int) $post['id'] ) );
			return 'unchanged';
		}
		$data['content_hash'] = $hash;
		$data['attempts']     = 0;
		$data['next_attempt'] = null;
		if ( ! in_array( $post['status'], array( 'processing' ), true ) ) {
			$data['status'] = 'received';
			$data['reason'] = $is_edit ? 'Source post edited; checking again.' : '';
		}
		$wpdb->update( CTI_Install::table( 'posts' ), $data, array( 'id' => (int) $post['id'] ) );
		CTI_Log::add( (int) $post['id'], 0, $is_edit ? 'edited' : 'album_part', '' );
		CTI_Processor::schedule_soon();
		return $is_edit ? 'edited' : 'stored';
	}

	private static function merge_photo( $photos, $photo, $message_id ) {
		$photos = is_array( $photos ) ? $photos : array();
		$photos = array_values( array_filter( $photos, static function ( $p ) use ( $message_id ) {
			return (int) $p['message_id'] !== (int) $message_id;
		} ) );
		if ( $photo ) {
			$photos[] = $photo;
		}
		usort( $photos, static function ( $a, $b ) {
			return (int) $a['message_id'] <=> (int) $b['message_id'];
		} );
		return array_slice( $photos, 0, 10 );
	}

	private static function db_row( $row, $extra ) {
		return array_merge(
			array(
				'chat_id'        => $row['chat_id'],
				'message_id'     => $row['message_id'],
				'channel'        => mb_substr( $row['channel'], 0, 80 ),
				'post_url'       => $row['post_url'],
				'post_date'      => $row['post_date'],
				'edit_date'      => $row['edit_date'],
				'last_update_id' => $row['last_update_id'],
				'media_group'    => $row['media_group'],
				'body'           => $row['body'],
				'links'          => wp_json_encode( $row['links'] ),
				'photos'         => wp_json_encode( $row['photo'] ? array( $row['photo'] ) : array() ),
				'status'         => 'received',
				'reason'         => '',
				'extraction'     => '',
				'updated_at'     => current_time( 'mysql', true ),
			),
			$extra
		);
	}

	public static function hash( $body, $photos ) {
		$ids = array();
		foreach ( (array) $photos as $p ) {
			$ids[] = isset( $p['unique_id'] ) && $p['unique_id'] ? $p['unique_id'] : ( isset( $p['file_id'] ) ? $p['file_id'] : '' );
		}
		return hash( 'sha256', trim( preg_replace( '/\s+/u', ' ', (string) $body ) ) . '|' . implode( ',', $ids ) );
	}

	/**
	 * A chat that sent a post but isn't on the allowlist: only its ID and
	 * title are remembered (last 10), so an administrator can add it.
	 */
	private static function remember_unknown_chat( $chat, $msg = array() ) {
		$seen = get_option( 'cti_unknown_chats', array() );
		$seen = is_array( $seen ) ? $seen : array();
		$id   = (string) $chat['id'];
		$seen[ $id ] = array(
			'id'       => $id,
			'username' => isset( $chat['username'] ) ? sanitize_text_field( $chat['username'] ) : '',
			'title'    => isset( $chat['title'] ) ? sanitize_text_field( $chat['title'] ) : '',
			'type'     => isset( $chat['type'] ) ? sanitize_key( $chat['type'] ) : '',
			'topic'    => ! empty( $msg['is_topic_message'] ) && isset( $msg['message_thread_id'] ) ? (int) $msg['message_thread_id'] : 0,
			'seen'     => current_time( 'mysql', true ),
		);
		update_option( 'cti_unknown_chats', array_slice( $seen, -10, 10, true ), false );
	}
}
