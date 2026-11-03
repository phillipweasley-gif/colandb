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
			'/telegram',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
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

		$is_edit = isset( $update['edited_channel_post'] );
		$msg     = $is_edit ? $update['edited_channel_post'] : ( isset( $update['channel_post'] ) ? $update['channel_post'] : null );
		if ( ! is_array( $msg ) || ! isset( $msg['chat']['type'], $msg['message_id'] ) || 'channel' !== $msg['chat']['type'] ) {
			return 'ignored';
		}
		$chat    = $msg['chat'];
		$channel = CTI_Config::channel_for( $chat );
		if ( ! $channel ) {
			self::remember_unknown_chat( $chat );
			return 'not_allowed';
		}

		$row = self::row_from_message( $msg, $channel, $update_id );
		return self::store( $row, $is_edit );
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

		if ( $username ) {
			$url = 'https://t.me/' . rawurlencode( $username ) . '/' . (int) $msg['message_id'];
		} elseif ( 0 === strpos( $chat_id, '-100' ) ) {
			$url = 'https://t.me/c/' . substr( $chat_id, 4 ) . '/' . (int) $msg['message_id'];
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

		if ( $parent ) {
			if ( ! $existing ) {
				$wpdb->insert( $t, self::db_row( $row, array( 'group_parent' => (int) $parent['id'], 'status' => 'merged', 'reason' => 'Part of an album; processed with post #' . (int) $parent['id'], 'created_at' => $now ) ) );
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
			CTI_Log::add( (int) $wpdb->insert_id, 0, 'received', $is_edit ? 'First seen as an edit.' : '' );
			CTI_Processor::schedule_soon();
			return 'stored';
		}

		$photos = self::merge_photo( json_decode( (string) $existing['photos'], true ), $row['photo'], $row['message_id'] );
		return self::save_changes( $existing, $row['body'], $row['links'], $photos, $row, true );
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
	private static function remember_unknown_chat( $chat ) {
		$seen = get_option( 'cti_unknown_chats', array() );
		$seen = is_array( $seen ) ? $seen : array();
		$id   = (string) $chat['id'];
		$seen[ $id ] = array(
			'id'       => $id,
			'username' => isset( $chat['username'] ) ? sanitize_text_field( $chat['username'] ) : '',
			'title'    => isset( $chat['title'] ) ? sanitize_text_field( $chat['title'] ) : '',
			'seen'     => current_time( 'mysql', true ),
		);
		update_option( 'cti_unknown_chats', array_slice( $seen, -10, 10, true ), false );
	}
}
