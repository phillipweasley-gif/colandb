<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Widget_Calendar extends \Elementor\Widget_Base {

	public function get_name() {
		return 'cec_calendar';
	}

	public function get_title() {
		return __( 'Community Calendar (Month View)', 'cec' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'community-calendar' );
	}

	protected function render() {
		echo do_shortcode( '[cec_calendar]' );
	}
}
