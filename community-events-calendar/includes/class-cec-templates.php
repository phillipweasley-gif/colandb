<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Templates {

	public static function template_include( $template ) {
		if ( is_singular( 'cec_event' ) ) {
			$override = CEC_DIR . 'templates/single-cec_event.php';
			if ( file_exists( $override ) ) {
				return $override;
			}
		}

		if ( is_tax( 'cec_partner_org' ) || is_tax( 'cec_venue' ) || is_tax( 'cec_event_type' ) ) {
			$override = CEC_DIR . 'templates/taxonomy-cec_events.php';
			if ( file_exists( $override ) ) {
				return $override;
			}
		}

		return $template;
	}

	/**
	 * These two templates paint their own dark background, but that only
	 * covers the centered content column — without this, the surrounding
	 * <body> (controlled by the active theme) would still show through as
	 * whatever the theme's default page background is on wider screens.
	 */
	public static function body_class( $classes ) {
		if ( is_singular( 'cec_event' ) || is_tax( array( 'cec_partner_org', 'cec_venue', 'cec_event_type' ) ) ) {
			$classes[] = 'cec-plugin-page';
		}
		return $classes;
	}

	/**
	 * Emits schema.org/JSON-LD Event markup on wp_head rather than inline in
	 * the single-event template, so it still fires even if a theme/child
	 * plugin later swaps out templates/single-cec_event.php.
	 */
	public static function output_event_schema() {
		if ( ! is_singular( 'cec_event' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$data = CEC_Event_Helper::data( $post_id );
		echo '<script type="application/ld+json">' . wp_json_encode( CEC_Event_Helper::event_schema( $data ), JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore
	}
}
