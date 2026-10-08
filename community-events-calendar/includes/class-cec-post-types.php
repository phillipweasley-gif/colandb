<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Post_Types {

	public static function register() {
		register_post_type(
			'cec_event',
			array(
				'labels'             => array(
					'name'               => __( 'Events', 'cec' ),
					'singular_name'      => __( 'Event', 'cec' ),
					'add_new_item'       => __( 'Add New Event', 'cec' ),
					'edit_item'          => __( 'Edit Event', 'cec' ),
					'all_items'          => __( 'All Events', 'cec' ),
					'search_items'       => __( 'Search Events', 'cec' ),
					'not_found'          => __( 'No events found', 'cec' ),
				),
				'public'              => true,
				'has_archive'         => 'events',
				'rewrite'             => array( 'slug' => 'event' ),
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-calendar-alt',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'comments' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			'cec_event_type',
			'cec_event',
			array(
				'labels'            => array(
					'name'          => __( 'Event Types', 'cec' ),
					'singular_name' => __( 'Event Type', 'cec' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'event-type' ),
			)
		);

		register_taxonomy(
			'cec_partner_org',
			'cec_event',
			array(
				'labels'            => array(
					'name'          => __( 'Partner Organizations & Titleholders', 'cec' ),
					'singular_name' => __( 'Partner Organization or Titleholder', 'cec' ),
					'menu_name'     => __( 'Partners & Titleholders', 'cec' ),
					'all_items'     => __( 'All Partner Organizations & Titleholders', 'cec' ),
					'add_new_item'  => __( 'Add New Partner Organization or Titleholder', 'cec' ),
					'edit_item'     => __( 'Edit Partner Organization or Titleholder', 'cec' ),
					'view_item'     => __( 'View Partner Organization or Titleholder', 'cec' ),
					'update_item'   => __( 'Update Partner Organization or Titleholder', 'cec' ),
					'search_items'  => __( 'Search Partner Organizations & Titleholders', 'cec' ),
					'not_found'     => __( 'No partner organizations or titleholders found.', 'cec' ),
					'back_to_items' => __( '&larr; Go to Partner Organizations & Titleholders', 'cec' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'partner' ),
			)
		);

		register_taxonomy(
			'cec_venue',
			'cec_event',
			array(
				'labels'            => array(
					'name'          => __( 'Venues', 'cec' ),
					'singular_name' => __( 'Venue', 'cec' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'venue' ),
			)
		);

		add_image_size( 'cec_calendar_thumb', 60, 60, true );
		// Not hard-cropped: many event flyers are tall/text-heavy, and a forced
		// landscape crop chops off dates/addresses. This proportionally fits
		// within a bounding box instead — the full image always stays intact.
		add_image_size( 'cec_card_thumb', 600, 600 );

		// A real status distinct from WordPress's own "Pending" and "Draft" —
		// those two had been doing double duty (Draft meant both "rejected" and
		// "not finished yet"; Pending meant both "just submitted, untouched" and
		// "an editor is partway through checking it"). Registered every request
		// (statuses aren't persisted — this must re-run on every 'init', same as
		// the post type/taxonomies above), not just on activation.
		register_post_status(
			'cec_in_review',
			array(
				'label'                     => _x( 'In Review', 'post status', 'cec' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of events in review */
				'label_count'               => _n_noop( 'In Review <span class="count">(%s)</span>', 'In Review <span class="count">(%s)</span>', 'cec' ),
			)
		);
	}

	/**
	 * WordPress's native Publish box only lists its own built-in statuses —
	 * a custom one needs a small admin_footer script to add itself as an
	 * option, same technique WooCommerce/EDD use for their own order
	 * statuses. Scoped to the cec_event edit screen only.
	 */
	public static function inject_in_review_status_js( $hook ) {
		global $post_type;
		if ( 'cec_event' !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$is_in_review = 'cec_in_review' === $post->post_status;
		$label        = esc_js( __( 'In Review', 'cec' ) );
		?>
		<script>
		jQuery(function($){
			var select = $('select#post_status');
			if ( ! select.length || select.find('option[value="cec_in_review"]').length ) {
				return;
			}
			select.append('<option value="cec_in_review"><?php echo $label; // phpcs:ignore ?></option>');
			<?php if ( $is_in_review ) : ?>
			select.val('cec_in_review');
			$('#post-status-display').text('<?php echo $label; // phpcs:ignore ?>');
			<?php endif; ?>
		});
		</script>
		<?php
	}

	public static function seed_default_terms() {
		$defaults = array( 'Social', 'Educational', 'Themed', 'Advocacy', 'Fundraiser', 'Meeting' );
		foreach ( $defaults as $term ) {
			if ( ! term_exists( $term, 'cec_event_type' ) ) {
				wp_insert_term( $term, 'cec_event_type' );
			}
		}
	}
}
