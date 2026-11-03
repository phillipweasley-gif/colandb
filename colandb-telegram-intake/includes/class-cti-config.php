<?php
/**
 * Settings and secrets.
 *
 * Secrets never live in the database or the repository. They are read
 * from wp-config.php constants or, failing that, environment variables
 * with the same names (the host's secret manager):
 *
 *   COLANDB_TG_BOT_TOKEN          Telegram bot token from @BotFather
 *   COLANDB_TG_WEBHOOK_SECRET     random 32+ characters, A-Z a-z 0-9 _ -
 *   COLANDB_INTAKE_ANTHROPIC_KEY  Claude API key (flyer and text reading)
 *
 * Optional: COLANDB_TG_API_BASE and COLANDB_INTAKE_ANTHROPIC_BASE point the
 * plugin at a test server (the local test kit uses them).
 *
 * Everything else is in the cti_settings option (Events → Telegram Intake
 * → Settings).
 */
defined( 'ABSPATH' ) || exit;

final class CTI_Config {

	const OPTION = 'cti_settings';

	public static function secret( $name ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$value = getenv( $name );
		return false === $value ? '' : trim( (string) $value );
	}

	public static function bot_token() {
		return self::secret( 'COLANDB_TG_BOT_TOKEN' );
	}

	public static function webhook_secret() {
		return self::secret( 'COLANDB_TG_WEBHOOK_SECRET' );
	}

	public static function anthropic_key() {
		return self::secret( 'COLANDB_INTAKE_ANTHROPIC_KEY' );
	}

	public static function telegram_base() {
		$base = self::secret( 'COLANDB_TG_API_BASE' );
		return untrailingslashit( $base ? $base : 'https://api.telegram.org' );
	}

	public static function anthropic_base() {
		$base = self::secret( 'COLANDB_INTAKE_ANTHROPIC_BASE' );
		return untrailingslashit( $base ? $base : 'https://api.anthropic.com' );
	}

	public static function defaults() {
		return array(
			'channels'                 => array(),
			'dry_run'                  => true,
			'extractor'                => 'anthropic',
			'model'                    => 'claude-opus-5-5',
			'effort'                   => 'medium',
			'notify_emails'            => '',
			'max_image_bytes'          => 5 * MB_IN_BYTES,
			'image_types'              => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'http_timeout'             => 90,
			'max_attempts'             => 5,
			'max_submissions_per_hour' => 20,
			'max_posts_per_run'        => 5,
			'min_confidence'           => 0.6,
			'duplicate_threshold'      => 50,
			'author_id'                => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function dry_run() {
		return (bool) self::get( 'dry_run' );
	}

	/**
	 * The allowlist. Each line of the settings box is
	 *   <channel id or @username> [| reuse-images] [| label]
	 * e.g. "-1001234567890 | no | Kink 101" or "@capcitypets".
	 */
	public static function parse_channels( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$ref   = $parts[0];
			$row   = array( 'id' => '', 'username' => '', 'reuse_images' => false, 'label' => '' );
			if ( preg_match( '/^-?\d{5,20}$/', $ref ) ) {
				$row['id'] = $ref;
			} elseif ( preg_match( '/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/', $ref, $m ) ) {
				$row['username'] = strtolower( $m[1] );
			} else {
				continue;
			}
			$row['reuse_images'] = isset( $parts[1] ) && in_array( strtolower( $parts[1] ), array( 'yes', 'reuse-images', 'reuse', 'y' ), true );
			$row['label']        = isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '';
			$out[]               = $row;
		}
		return $out;
	}

	public static function channels_text( $channels ) {
		$lines = array();
		foreach ( (array) $channels as $c ) {
			$lines[] = trim( ( $c['id'] ? $c['id'] : '@' . $c['username'] ) . ' | ' . ( $c['reuse_images'] ? 'yes' : 'no' ) . ( $c['label'] ? ' | ' . $c['label'] : '' ) );
		}
		return implode( "\n", $lines );
	}

	/** The allowlist entry for a Telegram chat object, or null. */
	public static function channel_for( $chat ) {
		$id       = isset( $chat['id'] ) ? (string) $chat['id'] : '';
		$username = isset( $chat['username'] ) ? strtolower( (string) $chat['username'] ) : '';
		foreach ( (array) self::get( 'channels' ) as $c ) {
			if ( ( $c['id'] && $c['id'] === $id ) || ( $c['username'] && $username && $c['username'] === $username ) ) {
				return $c;
			}
		}
		return null;
	}

	public static function notify_emails() {
		$emails = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) self::get( 'notify_emails' ) ) ), 'is_email' );
		return $emails ? array_values( $emails ) : array( get_option( 'admin_email' ) );
	}
}
