<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users → Member Profile Options: the admin-managed choices behind the
 * profile's pick-lists (decision D3). Only accounts with the dedicated
 * "Manage Member Fields" capability (administrators) can open it.
 * Options are never deleted, only retired, so members' existing choices
 * keep their meaning (see CMP_Profile_Fields::save_list()).
 */
class CMP_Profile_Options_Admin {

	const PAGE  = 'cmp-profile-options';
	const BLANK = 3;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cmp_profile_options', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_users_page( __( 'Member Profile Options', 'cmp' ), __( 'Member Profile Options', 'cmp' ), CMP_Profile_Fields::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function back( $list, $notice, $message = '' ) {
		$args = array( 'page' => self::PAGE, 'list' => $list, 'cmp_notice' => $notice );
		if ( $message ) {
			set_transient( 'cmp_options_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ) );
		exit;
	}

	public static function handle() {
		if ( ! current_user_can( CMP_Profile_Fields::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to change member profile options.', 'cmp' ), 403 );
		}
		check_admin_referer( 'cmp_profile_options' );
		$list = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		if ( ! isset( CMP_Profile_Fields::lists()[ $list ] ) ) {
			wp_die( esc_html__( 'Unknown list.', 'cmp' ), 400 );
		}
		$rows = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each row is sanitized in save_list().
		foreach ( isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'key'    => isset( $row['key'] ) ? (string) $row['key'] : '',
				'label'  => isset( $row['label'] ) ? (string) $row['label'] : '',
				'active' => ! empty( $row['active'] ),
				'order'  => isset( $row['order'] ) ? (int) $row['order'] : 0,
			);
		}
		// "Add several at once": one per line, active, after the existing ones.
		$bulk  = isset( $_POST['bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk'] ) ) : '';
		$order = 1000;
		foreach ( preg_split( '/\r\n|\r|\n/', $bulk ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$rows[] = array( 'key' => '', 'label' => trim( $line ), 'active' => true, 'order' => $order++ );
			}
		}
		$result = CMP_Profile_Fields::save_list( $list, $rows );
		if ( is_wp_error( $result ) ) {
			self::back( $list, 'error', $result->get_error_message() );
		}
		self::back( $list, 'saved' );
	}

	public static function render() {
		if ( ! current_user_can( CMP_Profile_Fields::CAP ) ) {
			return;
		}
		$lists   = CMP_Profile_Fields::lists();
		$current = isset( $_GET['list'] ) ? sanitize_key( wp_unslash( $_GET['list'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $lists[ $current ] ) ? $current : key( $lists );
		$notice  = isset( $_GET['cmp_notice'] ) ? sanitize_key( wp_unslash( $_GET['cmp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows    = CMP_Profile_Fields::raw_options( $current );
		$error   = get_transient( 'cmp_options_error_' . get_current_user_id() );
		delete_transient( 'cmp_options_error_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Member Profile Options', 'cmp' ); ?></h1>
			<p style="max-width:760px"><?php esc_html_e( 'The choices members can pick from on their profile. Renaming a choice updates it everywhere. To stop offering one, untick "Offered" (or clear its name): members who already chose it keep it, and nothing is ever deleted.', 'cmp' ); ?></p>
			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Saved.', 'cmp' ); ?></p></div>
			<?php elseif ( 'error' === $notice && $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?> <?php esc_html_e( 'Nothing was saved.', 'cmp' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Lists', 'cmp' ); ?>">
				<?php foreach ( $lists as $key => $label ) : ?>
					<a class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'list' => $key ), admin_url( 'users.php' ) ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cmp_profile_options" />
				<input type="hidden" name="list" value="<?php echo esc_attr( $current ); ?>" />
				<?php wp_nonce_field( 'cmp_profile_options' ); ?>
				<?php if ( 'availability' === $current ) : ?>
					<p><?php esc_html_e( '"Not listed" is always offered first and is every member\'s starting value.', 'cmp' ); ?></p>
				<?php endif; ?>
				<table class="widefat striped" style="max-width:760px">
					<thead><tr><th scope="col"><?php esc_html_e( 'Name shown to members', 'cmp' ); ?></th><th scope="col" style="width:90px"><?php esc_html_e( 'Offered', 'cmp' ); ?></th><th scope="col" style="width:90px"><?php esc_html_e( 'Order', 'cmp' ); ?></th><th scope="col"><?php esc_html_e( 'Key (never changes)', 'cmp' ); ?></th></tr></thead>
					<tbody>
						<?php
						$i = 0;
						foreach ( $rows as $o ) :
							?>
							<tr>
								<td><input type="hidden" name="rows[<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $o['key'] ); ?>" /><input type="text" class="regular-text" maxlength="80" name="rows[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $o['label'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'cmp' ); ?>" /></td>
								<td><input type="checkbox" name="rows[<?php echo (int) $i; ?>][active]" value="1" <?php checked( ! empty( $o['active'] ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: option name */ __( 'Offer %s', 'cmp' ), $o['label'] ) ); ?>" /></td>
								<td><input type="number" class="small-text" name="rows[<?php echo (int) $i; ?>][order]" value="<?php echo (int) $o['order']; ?>" aria-label="<?php esc_attr_e( 'Order', 'cmp' ); ?>" /></td>
								<td><code><?php echo esc_html( $o['key'] ); ?></code></td>
							</tr>
							<?php
							++$i;
						endforeach;
						for ( $n = 0; $n < self::BLANK; $n++, $i++ ) :
							?>
							<tr>
								<td><input type="text" class="regular-text" maxlength="80" name="rows[<?php echo (int) $i; ?>][label]" value="" placeholder="<?php esc_attr_e( 'New choice', 'cmp' ); ?>" aria-label="<?php esc_attr_e( 'New choice', 'cmp' ); ?>" /></td>
								<td><input type="checkbox" name="rows[<?php echo (int) $i; ?>][active]" value="1" checked aria-label="<?php esc_attr_e( 'Offer this new choice', 'cmp' ); ?>" /></td>
								<td><input type="number" class="small-text" name="rows[<?php echo (int) $i; ?>][order]" value="<?php echo (int) ( 10 * ( count( $rows ) + $n + 1 ) ); ?>" aria-label="<?php esc_attr_e( 'Order', 'cmp' ); ?>" /></td>
								<td></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<h2><label for="cmp_bulk"><?php esc_html_e( 'Add several at once', 'cmp' ); ?></label></h2>
				<p><textarea id="cmp_bulk" name="bulk" rows="6" class="large-text" style="max-width:760px" placeholder="<?php esc_attr_e( 'One choice per line', 'cmp' ); ?>"></textarea></p>
				<?php submit_button( __( 'Save list', 'cmp' ) ); ?>
			</form>
		</div>
		<?php
	}
}
