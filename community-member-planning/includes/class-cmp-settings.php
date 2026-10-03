<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Member Planning. One option array, administrators only.
 */
class CMP_Settings {

	const OPTION = 'cmp_settings';
	const PAGE   = 'cmp-settings';

	// Placeholder until the owner approves the final wording (decision D8 in
	// docs/phase-2-integration-plan.md). Editable on the settings screen so
	// approved text can be pasted in without a code change.
	const DEFAULT_ATTESTATION = '[PLACEHOLDER – awaiting owner approval] I confirm that I am 18 years of age or older.';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'audit_change' ), 10, 2 );
	}

	public static function defaults() {
		return array(
			'member_page_id'        => 0,
			'privacy_notice_url'    => '',
			'attestation_text'      => self::DEFAULT_ATTESTATION,
			'attestation_version'   => 1,
			'delete_data_on_delete' => 0,
		);
	}

	public static function get( $key ) {
		$opts = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	public static function member_page_url() {
		$page_id = (int) self::get( 'member_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' );
	}

	public static function add_menu() {
		add_options_page(
			__( 'Member Planning', 'cmp' ),
			__( 'Member Planning', 'cmp' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$old = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$out = $old;

		$page_id               = isset( $input['member_page_id'] ) ? absint( $input['member_page_id'] ) : 0;
		$out['member_page_id'] = ( $page_id && 'page' === get_post_type( $page_id ) ) ? $page_id : 0;

		$out['privacy_notice_url'] = isset( $input['privacy_notice_url'] ) ? esc_url_raw( $input['privacy_notice_url'] ) : '';

		$text = isset( $input['attestation_text'] ) ? sanitize_textarea_field( $input['attestation_text'] ) : '';
		$text = '' === trim( $text ) ? self::DEFAULT_ATTESTATION : $text;
		if ( $text !== $old['attestation_text'] ) {
			// Each member's attestation records which version they agreed to.
			$out['attestation_version'] = (int) $old['attestation_version'] + 1;
		}
		$out['attestation_text'] = $text;

		$out['delete_data_on_delete'] = empty( $input['delete_data_on_delete'] ) ? 0 : 1;
		return $out;
	}

	public static function audit_change( $old, $new ) {
		$old = wp_parse_args( (array) $old, self::defaults() );
		$new = wp_parse_args( (array) $new, self::defaults() );
		$changed_old = array_diff_assoc( $old, $new );
		if ( $changed_old ) {
			CMP_Audit::log( 'settings_changed', 'settings', 0, $changed_old, array_intersect_key( $new, $changed_old ) );
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$name = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Member Planning', 'cmp' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cmp_member_page_id"><?php esc_html_e( 'Member area page', 'cmp' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => esc_attr( $name . '[member_page_id]' ),
									'id'                => 'cmp_member_page_id',
									'selected'          => (int) $opts['member_page_id'],
									'show_option_none'  => esc_html__( '— Select —', 'cmp' ),
									'option_none_value' => '0',
									'post_status'       => array( 'publish', 'private', 'draft' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The page containing the [cmp_member_area] shortcode (or an Elementor Shortcode widget with it). This page is sent with no-cache and noindex headers and is left out of the sitemap.', 'cmp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cmp_attestation_text"><?php esc_html_e( '18+ attestation wording', 'cmp' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[attestation_text]" id="cmp_attestation_text"><?php echo esc_textarea( $opts['attestation_text'] ); ?></textarea>
							<p class="description">
								<?php
								printf(
									/* translators: %d: current attestation wording version */
									esc_html__( 'Shown with the checkbox every member must tick before first entering the member area. Changing it creates a new version (currently version %d); each member\'s record keeps the version they agreed to.', 'cmp' ),
									(int) $opts['attestation_version']
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cmp_privacy_notice_url"><?php esc_html_e( 'Member privacy notice URL', 'cmp' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[privacy_notice_url]" id="cmp_privacy_notice_url" value="<?php echo esc_attr( $opts['privacy_notice_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Linked next to the attestation. Leave empty until the notice is approved.', 'cmp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On plugin deletion', 'cmp' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_delete]" value="1" <?php checked( 1, (int) $opts['delete_data_on_delete'] ); ?> /> <?php esc_html_e( 'Permanently delete all member data (tables, notifications, audit log, member settings) when this plugin is deleted.', 'cmp' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Deactivating the plugin never deletes anything.', 'cmp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
