<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Widget_Volunteer_Form extends \Elementor\Widget_Base {

	public function get_name() {
		return 'cec_volunteer_form';
	}

	public function get_title() {
		return __( 'Volunteer Inquiry Form', 'cec' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( 'community-calendar' );
	}

	protected function render() {
		echo do_shortcode( '[cec_volunteer_form]' );
	}
}
