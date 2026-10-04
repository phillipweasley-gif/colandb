<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
while ( have_posts() ) :
	the_post();
	$data = CEC_Event_Helper::data( get_the_ID() );
	?>
	<main class="cec-single-event">
		<div class="cec-single-hero">
			<?php if ( $data['thumb_card'] ) : ?>
				<img src="<?php echo esc_url( get_the_post_thumbnail_url( get_the_ID(), 'large' ) ); ?>" alt="<?php echo esc_attr( $data['photo_alt'] ? $data['photo_alt'] : $data['title'] ); ?>" />
			<?php endif; ?>
		</div>

		<div class="cec-single-wrap">
			<div class="cec-single-main">
				<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
					<div class="cec-status-banner cec-status-banner-<?php echo esc_attr( $data['event_status'] ); ?>">
						<strong><?php echo 'cancelled' === $data['event_status'] ? esc_html__( 'This event has been cancelled.', 'cec' ) : esc_html__( 'This event has been postponed.', 'cec' ); ?></strong>
						<?php if ( $data['event_status_note'] ) : ?> <?php echo esc_html( $data['event_status_note'] ); ?><?php endif; ?>
					</div>
				<?php endif; ?>

				<?php $badge = CEC_Event_Helper::admission_badge( $data ); ?>
				<div class="cec-card-badges">
					<?php if ( $data['is_past'] ) : ?>
						<span class="cec-badge cec-badge-past"><?php esc_html_e( 'Past', 'cec' ); ?></span>
					<?php endif; ?>
					<?php foreach ( $data['types'] as $type ) : ?>
						<span class="cec-badge cec-badge-type <?php echo esc_attr( CEC_Event_Helper::type_badge_class( $type ) ); ?>"><?php echo esc_html( $type ); ?></span>
					<?php endforeach; ?>
					<?php if ( 'scheduled' === $data['event_status'] ) : ?>
						<span class="cec-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
					<?php endif; ?>
				</div>

				<h1><?php the_title(); ?></h1>

				<div class="cec-single-content"><?php the_content(); ?></div>

				<?php if ( $data['code_of_conduct'] ) : ?>
					<div class="cec-coc-box">
						<h3><?php esc_html_e( 'Code of Conduct', 'cec' ); ?></h3>
						<p><?php echo wp_kses_post( wpautop( make_clickable( $data['code_of_conduct'] ) ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<aside class="cec-single-sidebar">
				<div class="cec-info-box">
					<h3><?php esc_html_e( 'When', 'cec' ); ?></h3>
					<p><?php echo esc_html( $data['when_display'] ); ?><?php echo $data['timezone_abbr'] ? ' ' . esc_html( $data['timezone_abbr'] ) : ''; ?></p>
					<?php if ( $data['start_ts'] ) : ?>
						<p class="cec-add-to-calendar">
							<a class="cec-btn cec-btn-outline cec-btn-small" href="<?php echo esc_url( CEC_Ical::google_calendar_url( $data ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add to Google Calendar', 'cec' ); ?></a>
							<a class="cec-btn cec-btn-outline cec-btn-small" href="<?php echo esc_url( CEC_Ical::single_ics_url( $data ) ); ?>"><?php esc_html_e( 'Download .ics', 'cec' ); ?></a>
						</p>
					<?php endif; ?>

					<?php $share_links = CEC_Event_Helper::share_links( $data ); ?>
					<div class="cec-share" data-share-title="<?php echo esc_attr( $data['title'] ); ?>" data-share-text="<?php echo esc_attr( $data['title'] . ( $data['start_display'] ? ' — ' . $data['start_display'] : '' ) ); ?>" data-share-url="<?php echo esc_url( $data['permalink'] ); ?>">
						<button type="button" class="cec-btn cec-btn-outline cec-btn-small cec-share-toggle">
							<svg class="cec-share-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><line x1="8.3" y1="10.7" x2="15.7" y2="6.3"/><line x1="8.3" y1="13.3" x2="15.7" y2="17.7"/></svg>
							<?php esc_html_e( 'Share', 'cec' ); ?>
						</button>
						<div class="cec-share-menu" hidden>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['whatsapp'] ); ?>" target="_blank" rel="noopener">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12a8 8 0 1 1 3.5 6.6L4 20l1.2-3.6A7.96 7.96 0 0 1 4 12Z"/></svg></span>
								<span class="cec-share-label">WhatsApp</span>
							</a>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['telegram'] ); ?>" target="_blank" rel="noopener">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true"><path d="M5 12l14-7-4 7 4 7-14-7z"/></svg></span>
								<span class="cec-share-label">Telegram</span>
							</a>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['sms'] ); ?>">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="6" width="16" height="10" rx="2"/><circle cx="9" cy="11" r="0.6" fill="currentColor"/><circle cx="12" cy="11" r="0.6" fill="currentColor"/><circle cx="15" cy="11" r="0.6" fill="currentColor"/></svg></span>
								<span class="cec-share-label"><?php esc_html_e( 'Text', 'cec' ); ?></span>
							</a>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['facebook'] ); ?>" target="_blank" rel="noopener">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M14 8h-1.5A1.5 1.5 0 0 0 11 9.5V12h3l-.4 3H11v6"/></svg></span>
								<span class="cec-share-label">Facebook</span>
							</a>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['twitter'] ); ?>" target="_blank" rel="noopener">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg></span>
								<span class="cec-share-label">X</span>
							</a>
							<a class="cec-share-icon" href="<?php echo esc_url( $share_links['email'] ); ?>">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></span>
								<span class="cec-share-label"><?php esc_html_e( 'Email', 'cec' ); ?></span>
							</a>
							<button type="button" class="cec-share-icon cec-share-copy" data-url="<?php echo esc_url( $data['permalink'] ); ?>">
								<span class="cec-share-icon-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg></span>
								<span class="cec-share-label"><?php esc_html_e( 'Copy', 'cec' ); ?></span>
							</button>
						</div>
					</div>

					<?php $where = CEC_Event_Helper::location_display( $data ); ?>
					<h3><?php esc_html_e( 'Where', 'cec' ); ?></h3>
					<p>
						<?php echo esc_html( $where['label'] ); ?><br />
						<?php if ( 'hybrid' === $data['location_mode'] && $where['detail'] ) : ?><?php echo esc_html( $where['detail'] ); ?><br /><?php endif; ?>
						<?php if ( 'in_person' === $data['location_mode'] && $data['address'] && $data['address'] !== $data['venue_name'] ) : ?><?php echo esc_html( $data['address'] ); ?><br /><?php endif; ?>
						<?php if ( 'in_person' === $data['location_mode'] && $data['maps_url'] ) : ?>
							<a href="<?php echo esc_url( $data['maps_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on Google Maps', 'cec' ); ?> &rarr;</a>
						<?php endif; ?>
						<?php if ( in_array( $data['location_mode'], array( 'online', 'hybrid' ), true ) && $where['online_url'] ) : ?>
							<a href="<?php echo esc_url( $where['online_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join Online', 'cec' ); ?> &rarr;</a>
						<?php endif; ?>
					</p>

					<h3><?php esc_html_e( 'Hosted By', 'cec' ); ?></h3>
					<p>
						<?php if ( $data['host_org_name'] ) : ?>
							<?php if ( $data['host_org_url'] ) : ?>
								<a href="<?php echo esc_url( $data['host_org_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $data['host_org_name'] ); ?></a><br />
							<?php else : ?>
								<?php echo esc_html( $data['host_org_name'] ); ?><br />
							<?php endif; ?>
						<?php endif; ?>
						<?php echo esc_html( $data['organizers'] ); ?>
					</p>

					<?php if ( $data['official_website_url'] ) : ?>
						<p><a href="<?php echo esc_url( $data['official_website_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Official Event Website', 'cec' ); ?> &rarr;</a></p>
					<?php endif; ?>
					<?php if ( $data['more_info_url'] ) : ?>
						<p><a class="cec-btn cec-btn-outline cec-btn-block" href="<?php echo esc_url( $data['more_info_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More Info', 'cec' ); ?> &rarr;</a></p>
					<?php endif; ?>

					<?php if ( ! empty( $data['partners'] ) ) : ?>
						<h3><?php esc_html_e( 'Partner Organizations', 'cec' ); ?></h3>
						<p>
							<?php foreach ( $data['partners'] as $partner ) : ?>
								<a class="cec-partner-link" href="<?php echo esc_url( $partner['link'] ); ?>"><?php echo esc_html( $partner['name'] ); ?></a><br />
							<?php endforeach; ?>
						</p>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Cost', 'cec' ); ?></h3>
					<p><?php echo esc_html( $badge['label'] ); ?></p>
					<?php if ( $data['price_source_url'] ) : ?>
						<p><a href="<?php echo esc_url( $data['price_source_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Verify this price', 'cec' ); ?> &rarr;</a></p>
					<?php endif; ?>

					<?php if ( 'scheduled' !== $data['event_status'] ) : ?>
						<p class="cec-rsvp-disabled-note"><?php esc_html_e( 'RSVP/registration is paused while this event is postponed or cancelled.', 'cec' ); ?></p>
					<?php elseif ( 'external' === $data['rsvp_mode'] && $data['rsvp_url'] ) : ?>
						<a class="cec-btn cec-btn-block" href="<?php echo esc_url( $data['rsvp_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Register', 'cec' ); ?></a>
					<?php elseif ( 'internal' === $data['rsvp_mode'] ) : ?>
						<?php include CEC_DIR . 'templates/parts/rsvp-form.php'; ?>
					<?php endif; ?>
				</div>
			</aside>
		</div>
	</main>
	<?php
endwhile;
get_footer();
