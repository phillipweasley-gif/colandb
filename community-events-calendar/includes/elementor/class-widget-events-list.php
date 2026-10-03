<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Widget_Events_List extends \Elementor\Widget_Base {

	public function get_name() {
		return 'cec_events_list';
	}

	public function get_title() {
		return __( 'Community Events (Grid/List)', 'cec' );
	}

	public function get_icon() {
		return 'eicon-post-list';
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
			'view',
			array(
				'label'   => __( 'Layout', 'cec' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'grid' => __( 'Grid', 'cec' ),
					'list' => __( 'List', 'cec' ),
				),
			)
		);

		$this->add_control(
			'count',
			array(
				'label'   => __( 'Number of Events', 'cec' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 12,
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		echo do_shortcode( '[cec_events view="' . esc_attr( $settings['view'] ) . '" count="' . esc_attr( $settings['count'] ) . '"]' );
	}
}
