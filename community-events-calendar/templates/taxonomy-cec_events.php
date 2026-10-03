<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
$term = get_queried_object();
?>
<main class="cec-taxonomy-archive">
	<header class="cec-archive-header">
		<h1><?php echo esc_html( $term->name ); ?></h1>
		<?php
		$org_url = get_term_meta( $term->term_id, 'cec_url', true );
		if ( $org_url ) :
			?>
			<p><a href="<?php echo esc_url( $org_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $org_url ); ?> &rarr;</a></p>
		<?php endif; ?>
		<?php if ( $term->description ) : ?><p><?php echo esc_html( $term->description ); ?></p><?php endif; ?>
		<?php if ( 'cec_partner_org' === $term->taxonomy ) : ?>
			<?php echo CEC_Feeds::subscribe_links_html( $term->term_id ); // phpcs:ignore ?>
		<?php endif; ?>
	</header>

	<div class="cec-events-wrap" data-view="grid">
		<div class="cec-events-results">
			<?php
			$filters = array( $term->taxonomy => $term->slug );
			echo CEC_Shortcodes::render_events_html( $filters, 'grid', 24 ); // phpcs:ignore
			?>
		</div>
	</div>
</main>
<?php
get_footer();
