<?php
/**
 * Settings and secrets.
 *
 * Secrets never live in the repository. They are read, in this order,
 * from wp-config.php constants, environment variables with the same names
 * (the host's secret manager), or the Secrets boxes in Settings (0.2.0,
 * option cti_secrets, not autoloaded, never shown again after saving):
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

	const SECRETS = 'cti_secrets';

	/** Secrets that may be typed into the Settings page. */
	const SETTABLE = array( 'COLANDB_TG_BOT_TOKEN', 'COLANDB_TG_WEBHOOK_SECRET', 'COLANDB_INTAKE_ANTHROPIC_KEY' );

	public static function secret( $name ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$value = getenv( $name );
		if ( false !== $value && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}
		if ( in_array( $name, self::SETTABLE, true ) ) {
			$saved = get_option( self::SECRETS, array() );
			return is_array( $saved ) && isset( $saved[ $name ] ) ? (string) $saved[ $name ] : '';
		}
		return '';
	}

	/** Where a secret comes from: wp-config, environment, settings, or '' (not set). */
	public static function secret_source( $name ) {
		if ( defined( $name ) ) {
			return 'wp-config';
		}
		$value = getenv( $name );
		if ( false !== $value && '' !== trim( (string) $value ) ) {
			return 'environment';
		}
		$saved = get_option( self::SECRETS, array() );
		return is_array( $saved ) && ! empty( $saved[ $name ] ) ? 'settings' : '';
	}

	/** Saves (or, with '', clears) a secret typed into Settings. */
	public static function save_secret( $name, $value ) {
		if ( ! in_array( $name, self::SETTABLE, true ) ) {
			return;
		}
		$saved = get_option( self::SECRETS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( '' === $value ) {
			unset( $saved[ $name ] );
		} else {
			$saved[ $name ] = $value;
		}
		if ( false === get_option( self::SECRETS, false ) ) {
			add_option( self::SECRETS, $saved, '', 'no' );
		} else {
			update_option( self::SECRETS, $saved, false );
		}
	}

	/**
	 * The webhook secret only has to match between this site and Telegram,
	 * so nobody needs to type it: one is made the first time it's needed.
	 */
	public static function ensure_webhook_secret() {
		if ( strlen( self::webhook_secret() ) < 32 && ! self::secret_source( 'COLANDB_TG_WEBHOOK_SECRET' ) ) {
			self::save_secret( 'COLANDB_TG_WEBHOOK_SECRET', wp_generate_password( 64, false, false ) );
		}
		return self::webhook_secret();
	}

	public static function valid_secret( $name, $value ) {
		switch ( $name ) {
			case 'COLANDB_TG_BOT_TOKEN':
				return (bool) preg_match( '/^\d{6,12}:[A-Za-z0-9_-]{30,64}$/', $value );
			case 'COLANDB_INTAKE_ANTHROPIC_KEY':
				return (bool) preg_match( '/^sk-ant-[A-Za-z0-9_-]{10,200}$/', $value );
			case 'COLANDB_TG_WEBHOOK_SECRET':
				return (bool) preg_match( '/^[A-Za-z0-9_-]{32,256}$/', $value );
		}
		return false;
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
	 *   <channel id or @username> [| use flyers: yes/no] [| label]
	 * e.g. "-1001234567890 | yes | Kink 101" or "@capcitypets".
	 * Flyers are used as the event image unless the line says "no"
	 * (owner's decision, 2026-11-03).
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
			$row['reuse_images'] = ! ( isset( $parts[1] ) && in_array( strtolower( $parts[1] ), array( 'no', 'n', 'off', 'false' ), true ) );
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
