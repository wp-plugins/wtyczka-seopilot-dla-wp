<?php

require_once( plugin_dir_path( __FILE__ ).'../SeoPilotClient.php' );

class SeoPilot_Widget extends WP_Widget {
	function SeoPilot_Widget() {
		parent::__construct(
			'seopilot',			// Base ID
			'SeoPilot.pl',		// Name
			array( 'description' => __( 'SeoPilot.pl Widget', 'seopilot' ), ) // Args
		);
	}
	public function widget( $args, $instance ) {
		echo SeoPilot::$client->build_links();
	}

	public function form( $instance ) {}
	public function update( $new_instance, $old_instance ) {}
}
