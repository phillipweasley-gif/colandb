<?php
/**
 * Expects $data (from CEC_Event_Helper::data) and $view ('grid'|'list') in scope.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article class="cec-event-card<?php echo 'scheduled' !== $data['event_status'] ? ' cec-event-card-' . esc_attr( $data['event_status'] ) : ''; ?>">
	<?php $badge = CEC_Event_Helper::admission_badge( $data ); ?>
	<a class="cec-card-media" href="<?php echo esc_url( $data['permalink'] ); ?>">
		<?php if ( $data['thumb_card'] ) : ?>
			<img src="<?php echo esc_url( $data['thumb_card'] ); ?>" alt="<?php echo esc_attr( $data['photo_alt'] ? $data['photo_alt'] : $data['title'] ); ?>" loading="lazy" />
		<?php else : ?>
			<div class="cec-card-media-placeholder"></div>
		<?php endif; ?>
		<span class="cec-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
	</a>
	<div class="cec-card-body">
		<div class="cec-card-badges">
			<?php foreach ( $data['types'] as $type ) : ?>
				<span class="cec-badge cec-badge-type <?php echo esc_attr( CEC_Event_Helper::type_badge_class( $type ) ); ?>"><?php echo esc_html( $type ); ?></span>
			<?php endforeach; ?>
		</div>
		<h3 class="cec-card-title"><a href="<?php echo esc_url( $data['permalink'] ); ?>"><?php echo esc_html( $data['title'] ); ?></a></h3>
		<?php if ( 'scheduled' !== $data['event_status'] && $data['event_status_note'] ) : ?>
			<p class="cec-status-note"><?php echo esc_html( $data['event_status_note'] ); ?></p>
		<?php endif; ?>
		<?php $where = CEC_Event_Helper::location_display( $data ); ?>
		<div class="cec-card-meta">
			<span class="cec-card-when"><?php echo esc_html( $data['start_display'] ); ?><?php echo $data['timezone_abbr'] ? ' ' . esc_html( $data['timezone_abbr'] ) : ''; ?></span>
			<span class="cec-card-where">
				<?php if ( 'in_person' === $data['location_mode'] && $data['maps_url'] ) : ?>
					<a href="<?php echo esc_url( $data['maps_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $where['label'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $where['label'] ); ?>
				<?php endif; ?>
			</span>
		</div>
		<?php if ( ! empty( $data['partners'] ) ) : ?>
			<div class="cec-card-partners">
				<?php foreach ( $data['partners'] as $i => $partner ) : ?>
					<?php if ( $i > 0 ) : ?>, <?php endif; ?>
					<a href="<?php echo esc_url( $partner['link'] ); ?>"><?php echo esc_html( $partner['name'] ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="cec-card-excerpt-wrap">
			<p class="cec-card-excerpt"><?php echo esc_html( $data['excerpt'] ); ?></p>
			<?php if ( 'list' === $view ) : ?>
				<button type="button" class="cec-expand-toggle" data-target="cec-full-<?php echo esc_attr( $data['id'] ); ?>" aria-expanded="false" aria-controls="cec-full-<?php echo esc_attr( $data['id'] ); ?>"><?php esc_html_e( 'More info', 'cec' ); ?></button>
				<div class="cec-card-full" id="cec-full-<?php echo esc_attr( $data['id'] ); ?>" hidden>
					<?php echo wp_kses_post( $data['content'] ); ?>
					<?php if ( $data['organizers'] ) : ?><p><strong><?php esc_html_e( 'Hosted by:', 'cec' ); ?></strong> <?php echo esc_html( $data['organizers'] ); ?></p><?php endif; ?>
					<?php if ( $data['code_of_conduct'] ) : ?><p><strong><?php esc_html_e( 'Code of Conduct:', 'cec' ); ?></strong> <?php echo esc_html( $data['code_of_conduct'] ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="cec-card-actions">
			<a class="cec-btn cec-btn-small" href="<?php echo esc_url( $data['permalink'] ); ?>"><?php esc_html_e( 'View Details', 'cec' ); ?></a>
			<button type="button" class="cec-btn cec-btn-outline cec-btn-small cec-share-compact" data-share-title="<?php echo esc_attr( $data['title'] ); ?>" data-share-text="<?php echo esc_attr( $data['title'] . ( $data['start_display'] ? ' — ' . $data['start_display'] : '' ) ); ?>" data-share-url="<?php echo esc_url( $data['permalink'] ); ?>"><?php esc_html_e( 'Share', 'cec' ); ?></button>
		</div>
	</div>
</article>
