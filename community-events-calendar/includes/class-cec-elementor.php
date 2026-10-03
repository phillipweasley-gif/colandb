<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEC_Elementor {

	public static function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'community-calendar',
			array(
				'title' => __( 'Community Calendar', 'cec' ),
				'icon'  => 'fa fa-calendar',
			)
		);
	}

	public static function register_widgets( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once CEC_DIR . 'includes/elementor/class-widget-calendar.php';
		require_once CEC_DIR . 'includes/elementor/class-widget-events-list.php';
		require_once CEC_DIR . 'includes/elementor/class-widget-upcoming.php';
		require_once CEC_DIR . 'includes/elementor/class-widget-submit-form.php';
		require_once CEC_DIR . 'includes/elementor/class-widget-volunteer-form.php';

		$widgets_manager->register( new \CEC_Widget_Calendar() );
		$widgets_manager->register( new \CEC_Widget_Events_List() );
		$widgets_manager->register( new \CEC_Widget_Upcoming() );
		$widgets_manager->register( new \CEC_Widget_Submit_Form() );
		$widgets_manager->register( new \CEC_Widget_Volunteer_Form() );
	}
}
