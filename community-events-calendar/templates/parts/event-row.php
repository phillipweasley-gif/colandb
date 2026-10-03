<?php
/**
 * The Upcoming list's "date-range-first" row card — Phase 1b. Expects
 * $data (from CEC_Event_Helper::data()) in scope. Distinct from
 * event-card.php (the photo-forward Grid view card, unchanged); this is
 * the row layout used only by the List view, one per parent event,
 * grouped under a month heading by the caller.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$date  = CEC_Event_Helper::date_block( $data );
$badge = CEC_Event_Helper::admission_badge( $data );
$where = CEC_Event_Helper::location_display( $data );
?>
<article class="cec-event-row<?php echo 'scheduled' !== $data['event_status'] ? ' cec-event-row-' . esc_attr( $data['event_status'] ) : ''; ?>">
	<div class="cec-row-date">
		<?php if ( $date['month'] ) : ?><div class="cec-row-month"><?php echo esc_html( $date['month'] ); ?></div><?php endif; ?>
		<div class="cec-row-days"><?php echo esc_html( $date['days'] ); ?></div>
		<?php if ( $date['year_line'] ) : ?><div class="cec-row-year"><?php echo esc_html( $date['year_line'] ); ?></div><?php endif; ?>
	</div>
	<div class="cec-row-body">
		<h3 class="cec-row-title"><a href="<?php echo esc_url( $data['permalink'] ); ?>"><?php echo esc_html( $data['title'] ); ?></a></h3>
		<div class="cec-row-meta">
			<?php echo esc_html( $where['label'] ); ?>
			<?php if ( $data['host_org_name'] ) : ?> &middot; <?php esc_html_e( 'Hosted by', 'cec' ); ?> <?php echo esc_html( $data['host_org_name'] ); ?><?php endif; ?>
		</div>
		<?php if ( 'scheduled' !== $data['event_status'] && $data['event_status_note'] ) : ?>
			<p class="cec-status-note"><?php echo esc_html( $data['event_status_note'] ); ?></p>
		<?php endif; ?>
		<div class="cec-row-badges">
			<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
				<span class="cec-badge cec-badge-<?php echo esc_attr( $data['event_status'] ); ?>"><?php echo esc_html( ucfirst( $data['event_status'] ) ); ?></span>
			<?php endif; ?>
			<?php foreach ( $data['types'] as $type ) : ?>
				<span class="cec-badge cec-badge-type <?php echo esc_attr( CEC_Event_Helper::type_badge_class( $type ) ); ?>"><?php echo esc_html( $type ); ?></span>
			<?php endforeach; ?>
			<?php if ( 'scheduled' === $data['event_status'] ) : ?>
				<span class="cec-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<div class="cec-row-cta">
		<a class="cec-row-cta-btn" href="<?php echo esc_url( $data['permalink'] ); ?>"><?php esc_html_e( 'Event Details', 'cec' ); ?> &rarr;</a>
	</div>
</article>
