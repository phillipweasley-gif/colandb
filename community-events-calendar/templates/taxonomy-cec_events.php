<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
$term    = get_queried_object();
$is_org  = 'cec_partner_org' === $term->taxonomy;
$editing = $is_org && CEC_Org_Editor::is_editing( $term );
?>
<main class="cec-taxonomy-archive<?php echo $is_org ? ' is-partner-org' : ''; ?>">
	<?php if ( $editing ) : ?>
		<?php echo CEC_Org_Editor::form_html( $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	<?php else : ?>
		<?php if ( $is_org ) : ?>
			<?php echo CEC_Orgs::header_html( $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<header class="cec-archive-header cec-org-events-head">
				<h2><?php esc_html_e( 'Upcoming Events', 'cec' ); ?></h2>
				<?php echo CEC_Feeds::subscribe_links_html( $term->term_id ); // phpcs:ignore ?>
			</header>
		<?php else : ?>
		<header class="cec-archive-header">
			<h1><?php echo esc_html( $term->name ); ?></h1>
			<?php
			$org_url = get_term_meta( $term->term_id, 'cec_url', true );
			if ( $org_url ) :
				?>
				<p><a href="<?php echo esc_url( $org_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $org_url ); ?> &rarr;</a></p>
			<?php endif; ?>
			<?php if ( $term->description ) : ?><p><?php echo esc_html( $term->description ); ?></p><?php endif; ?>
		</header>
		<?php endif; ?>

		<div class="cec-events-wrap" data-view="grid">
			<div class="cec-events-results">
				<?php
				$filters = array( $term->taxonomy => $term->slug );
				echo CEC_Shortcodes::render_events_html( $filters, 'grid', 24 ); // phpcs:ignore
				?>
			</div>
		</div>
	<?php endif; ?>
</main>
<?php
get_footer();
