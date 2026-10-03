<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Widget_Upcoming extends \Elementor\Widget_Base {

	public function get_name() {
		return 'cec_upcoming';
	}

	public function get_title() {
		return __( 'Upcoming Events Scroller', 'cec' );
	}

	public function get_icon() {
		return 'eicon-slides';
	}

	public function get_categories() {
		return array( 'community-calendar' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Settings', 'cec' ) )
		);

		$this->add_control(
			'count',
			array(
				'label'   => __( 'Number of Events', 'cec' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 8,
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		echo do_shortcode( '[cec_upcoming count="' . esc_attr( $settings['count'] ) . '"]' );
	}
}
