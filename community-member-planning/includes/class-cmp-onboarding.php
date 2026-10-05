<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step-by-step profile setup (0.5.0; owner request 2026-10-04: after
 * signing up, "filling out a member profile in stages … with a bio").
 *
 * Six short steps, each saving only its own fields through the normal
 * profile save (CMP_Profiles::handle_save() with only[] and cmp_return), so
 * there is one set of validation and visibility rules. "Skip for now" moves
 * on without saving; "Set up later" leaves the steps (the home page then
 * offers them again); the Profile tab keeps everything editable.
 *
 * Shown automatically on the member home to a member who hasn't finished or
 * left it and has nothing on their profile yet. Members who already filled
 * something in before 0.5.0 get a "Finish your profile" card instead of
 * being sent through the steps.
 */
class CMP_Onboarding {

	const TAB        = 'setup';
	const META_STEP  = 'cmp_setup_step';  // Next step to show (1-based).
	const META_DONE  = 'cmp_setup_done';  // 'finished' | 'later'.
	const NONCE      = 'cmp_setup';

	public static function init() {
		add_action( 'cmp_profile_saved', array( __CLASS__, 'after_save' ), 10, 2 );
		add_action( 'admin_post_cmp_setup_later', array( __CLASS__, 'handle_later' ) );
		add_action( 'admin_post_nopriv_cmp_setup_later', array( 'CMP_Member_Area', 'redirect_to_login' ) );
	}

	/**
	 * key => title, lead, fields (profile field keys; 'avatar' = the photo panel).
	 */
	public static function steps() {
		return array(
			1 => array(
				'title'  => __( 'Say hello', 'cmp' ),
				'label'  => __( 'Photo & bio', 'cmp' ),
				'lead'   => __( 'A photo and a few lines about you. Anything you fill in is shown to signed-in members; you can switch any item off.', 'cmp' ),
				'fields' => array( 'avatar', 'bio', 'pronouns' ),
			),
			2 => array(
				'title'  => __( 'The basics', 'cmp' ),
				'label'  => __( 'Basics', 'cmp' ),
				'lead'   => __( 'Where you are and what you\'re here for.', 'cmp' ),
				'fields' => array( 'location', 'hosting', 'active_level', 'looking_for', 'not_looking_for' ),
			),
			3 => array(
				'title'  => __( 'Who you are', 'cmp' ),
				'label'  => __( 'Identity & roles', 'cmp' ),
				'lead'   => __( 'Pick what fits. The site team can add choices that are missing.', 'cmp' ),
				'fields' => array( 'gender', 'identity', 'roles', 'position', 'expression' ),
			),
			4 => array(
				'title'  => __( 'Your stats', 'cmp' ),
				'label'  => __( 'Stats', 'cmp' ),
				'lead'   => __( 'Optional. Your age comes from your date of birth; the rest is up to you.', 'cmp' ),
				'fields' => array( 'age', 'height', 'weight', 'body_type' ),
			),
			5 => array(
				'title'  => __( 'What you\'re into', 'cmp' ),
				'label'  => __( 'Kinks & limits', 'cmp' ),
				'lead'   => __( 'Rate only what applies. Hard limits are what everyone should read before proposing anything.', 'cmp' ),
				'fields' => array( 'kinks', 'hard_limits', 'interests' ),
			),
			6 => array(
				'title'  => __( 'Health & safer sex', 'cmp' ),
				'label'  => __( 'Health (optional)', 'cmp' ),
				'lead'   => __( 'Optional, and hidden until you switch it on: unlike other fields, health details start hidden even when filled in.', 'cmp' ),
				'fields' => array( 'practices', 'last_tested', 'substances' ),
			),
		);
	}

	public static function url( $step = 1 ) {
		return add_query_arg( array( 'cmp_tab' => self::TAB, 'cmp_step' => (int) $step ), CMP_Settings::member_page_url() );
	}

	public static function is_done( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_DONE, true );
	}

	/** Has anything on the profile beyond what every account has. */
	private static function has_profile( $user_id ) {
		global $wpdb;
		$table = CMP_Install::table( 'profile_values' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE user_id = %d AND value IS NOT NULL LIMIT 1", $user_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			|| CMP_Profile_Images::get( $user_id, 'avatar' );
	}

	/**
	 * Go straight into the steps instead of the usual home: a new member
	 * with nothing on their profile yet, or anyone part-way through the
	 * steps (until they finish or choose "later").
	 */
	public static function should_start( $user_id ) {
		return ! self::is_done( $user_id ) && ( ! self::has_profile( $user_id ) || get_user_meta( $user_id, self::META_STEP, true ) );
	}

	/** Show a "Finish your profile" card on the home. */
	public static function should_nudge( $user_id ) {
		return 'finished' !== get_user_meta( $user_id, self::META_DONE, true );
	}

	public static function current_step( $user_id ) {
		$n = (int) get_user_meta( $user_id, self::META_STEP, true );
		return isset( self::steps()[ $n ] ) ? $n : 1;
	}

	/**
	 * A setup step was saved: move on, or finish after the last step.
	 */
	public static function after_save( $user_id, $only ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside CMP_Profiles::handle_save(), which checked its nonce.
		$step = isset( $_POST['cmp_setup_step'] ) ? absint( $_POST['cmp_setup_step'] ) : 0;
		if ( ! $step || null === $only ) {
			return;
		}
		$steps = self::steps();
		if ( $step >= count( $steps ) ) {
			update_user_meta( $user_id, self::META_DONE, 'finished' );
			delete_user_meta( $user_id, self::META_STEP );
			return;
		}
		update_user_meta( $user_id, self::META_STEP, $step + 1 );
	}

	public static function handle_later() {
		$user_id = get_current_user_id();
		if ( ! isset( $_POST['_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cmp_nonce'] ) ), self::NONCE ) || ! CMP_Access::is_member( $user_id ) ) {
			wp_safe_redirect( CMP_Settings::member_page_url() );
			exit;
		}
		// "Skip" on the last step finishes; "Set up later" leaves the steps.
		$finish = ! empty( $_POST['finish'] );
		if ( $finish ) {
			update_user_meta( $user_id, self::META_DONE, 'finished' );
			delete_user_meta( $user_id, self::META_STEP );
		} elseif ( ! self::is_done( $user_id ) ) {
			update_user_meta( $user_id, self::META_DONE, 'later' );
		}
		wp_safe_redirect( $finish ? add_query_arg( 'cmp_tab', CMP_Profiles::TAB, CMP_Settings::member_page_url() ) : CMP_Settings::member_page_url() );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/** The "Finish your profile" card on the home. */
	public static function nudge_html( $user_id ) {
		if ( ! self::should_nudge( $user_id ) ) {
			return '';
		}
		$step = self::current_step( $user_id );
		return '<section class="cmp-panel cmp-setup-nudge"><h3 class="cmp-panel-title">' . esc_html__( 'Finish your profile', 'cmp' ) . '</h3>'
			. '<p>' . esc_html__( 'A few short steps: photo and bio, basics, identity, stats, kinks and limits. Each one is optional.', 'cmp' ) . '</p>'
			. '<p><a class="cmp-btn" href="' . esc_url( self::url( $step ) ) . '">' . esc_html( 1 === $step ? __( 'Start', 'cmp' ) : __( 'Continue', 'cmp' ) ) . '</a></p></section>';
	}

	public static function render( $user_id, $step = 0 ) {
		$steps = self::steps();
		$step  = $step && isset( $steps[ $step ] ) ? $step : self::current_step( $user_id );
		$def   = $steps[ $step ];
		$total = count( $steps );
		$rows  = CMP_Profiles::rows( $user_id );
		$kept  = CMP_Profiles::saved_errors( $user_id );
		$next  = $step < $total ? self::url( $step + 1 ) : add_query_arg( 'cmp_tab', CMP_Profiles::TAB, CMP_Settings::member_page_url() );
		$here  = self::url( $step );
		$keys  = array_values( array_diff( $def['fields'], array( 'avatar' ) ) );

		wp_enqueue_script( 'cmp-member' );
		ob_start();
		?>
		<section class="cmp-step cmp-setup" aria-labelledby="cmp-setup-title">
			<div class="cmp-setup-progress" role="progressbar" aria-valuemin="1" aria-valuemax="<?php echo (int) $total; ?>" aria-valuenow="<?php echo (int) $step; ?>" aria-label="<?php esc_attr_e( 'Profile setup progress', 'cmp' ); ?>">
				<?php for ( $i = 1; $i <= $total; $i++ ) : ?>
					<span class="<?php echo $i <= $step ? 'is-done' : ''; ?>"></span>
				<?php endfor; ?>
			</div>
			<p class="cmp-muted cmp-setup-count">
				<?php
				/* translators: 1: step number, 2: total steps, 3: step name */
				echo esc_html( sprintf( __( 'Step %1$d of %2$d · %3$s', 'cmp' ), $step, $total, $def['label'] ) );
				?>
			</p>
			<h2 id="cmp-setup-title" class="cmp-title"><?php echo esc_html( $def['title'] ); ?></h2>
			<p><?php echo esc_html( $def['lead'] ); ?></p>
		</section>

		<?php if ( in_array( 'avatar', $def['fields'], true ) ) : ?>
			<?php echo CMP_Profile_Images::render_panels( $user_id, $rows, array( 'avatar' ), $here ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		<?php endif; ?>

		<section class="cmp-panel" id="cmp-about">
			<?php if ( $kept['errors'] ) : ?>
				<div class="cmp-notice cmp-notice-error" role="alert" id="cmp-profile-errors" tabindex="-1">
					<p><?php esc_html_e( 'Please fix these and save again:', 'cmp' ); ?></p>
					<ul>
						<?php foreach ( $kept['errors'] as $key => $message ) : ?>
							<li><a href="#cmp-row-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $message ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-form cmp-profile-form" aria-labelledby="cmp-setup-title" novalidate>
				<input type="hidden" name="action" value="cmp_profile_save" />
				<input type="hidden" name="_cmp_nonce" value="<?php echo esc_attr( wp_create_nonce( CMP_Profiles::NONCE ) ); ?>" />
				<input type="hidden" name="cmp_setup_step" value="<?php echo (int) $step; ?>" />
				<input type="hidden" name="cmp_return" value="<?php echo esc_url( $next ); ?>" />
				<?php foreach ( $keys as $key ) : ?>
					<input type="hidden" name="only[]" value="<?php echo esc_attr( $key ); ?>" />
				<?php endforeach; ?>
				<?php
				foreach ( $keys as $key ) {
					echo CMP_Profiles::row_html( $user_id, $key, $rows, $kept['input'], $kept['show_in'], $kept['errors'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
				<div class="cmp-actions cmp-setup-nav">
					<?php if ( $step > 1 ) : ?>
						<a class="cmp-btn cmp-btn-outline" href="<?php echo esc_url( self::url( $step - 1 ) ); ?>"><?php esc_html_e( 'Back', 'cmp' ); ?></a>
					<?php endif; ?>
					<?php if ( $step < $total ) : ?>
						<a class="cmp-setup-skip" href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'Skip for now', 'cmp' ); ?></a>
					<?php else : ?>
						<button type="submit" form="cmp-setup-finish" class="cmp-link-button cmp-setup-skip"><?php esc_html_e( 'Skip', 'cmp' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="cmp-btn"><?php echo esc_html( $step < $total ? __( 'Save & continue', 'cmp' ) : __( 'Finish', 'cmp' ) ); ?></button>
				</div>
			</form>
		</section>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmp-setup-later">
			<input type="hidden" name="action" value="cmp_setup_later" />
			<?php wp_nonce_field( self::NONCE, '_cmp_nonce' ); ?>
			<button type="submit" class="cmp-link-button"><?php esc_html_e( 'Set up my profile later', 'cmp' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cmp-setup-finish" hidden>
			<input type="hidden" name="action" value="cmp_setup_later" />
			<input type="hidden" name="finish" value="1" />
			<?php wp_nonce_field( self::NONCE, '_cmp_nonce' ); ?>
		</form>
		<?php
		return ob_get_clean();
	}
}
