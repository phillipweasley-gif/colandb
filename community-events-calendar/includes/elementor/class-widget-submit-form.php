<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Widget_Submit_Form extends \Elementor\Widget_Base {

	public function get_name() {
		return 'cec_submit_form';
	}

	public function get_title() {
		return __( 'Submit an Event Form', 'cec' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( 'community-calendar' );
	}

	protected function render() {
		echo do_shortcode( '[cec_submit_event]' );
	}
}
