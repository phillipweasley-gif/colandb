<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$capacity = $data['rsvp_capacity'];
$taken    = $data['rsvp_taken'];
$full     = $capacity > 0 && $taken >= $capacity;
$user     = wp_get_current_user();
?>
<div class="cec-rsvp-box" data-event-id="<?php echo esc_attr( $data['id'] ); ?>">
	<?php if ( $capacity > 0 ) : ?>
		<p class="cec-rsvp-capacity"><?php echo esc_html( max( 0, $capacity - $taken ) ); ?> <?php esc_html_e( 'spots left', 'cec' ); ?></p>
	<?php endif; ?>

	<?php if ( $full ) : ?>
		<p class="cec-rsvp-full"><?php esc_html_e( 'This event is at capacity.', 'cec' ); ?></p>
		<form class="cec-waitlist-form">
			<input type="text" name="name" placeholder="<?php esc_attr_e( 'Your name', 'cec' ); ?>" value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>" required />
			<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'cec' ); ?>" value="<?php echo esc_attr( $user->exists() ? $user->user_email : '' ); ?>" required />
			<input type="number" name="guests" min="0" max="10" placeholder="<?php esc_attr_e( 'Additional guests', 'cec' ); ?>" value="0" />
			<button type="submit" class="cec-btn cec-btn-outline cec-btn-block"><?php esc_html_e( 'Join the Waitlist', 'cec' ); ?></button>
			<p class="cec-rsvp-message" hidden></p>
		</form>
	<?php else : ?>
		<form class="cec-rsvp-form">
			<input type="text" name="name" placeholder="<?php esc_attr_e( 'Your name', 'cec' ); ?>" value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>" required />
			<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email', 'cec' ); ?>" value="<?php echo esc_attr( $user->exists() ? $user->user_email : '' ); ?>" required />
			<input type="number" name="guests" min="0" max="10" placeholder="<?php esc_attr_e( 'Additional guests', 'cec' ); ?>" value="0" />
			<button type="submit" class="cec-btn cec-btn-block"><?php esc_html_e( 'RSVP', 'cec' ); ?></button>
			<p class="cec-rsvp-message" hidden></p>
		</form>
	<?php endif; ?>
</div>
