<?php

class Taleo_WPCLI extends WP_CLI_Command {

	/**
	* Syncs with taleo
	* 
	*/
	function sync() {
		global $taleo;
		$taleo->manually_sync_jobs();
	}

}

WP_CLI::add_command( 'taleo', 'Taleo_WPCLI' );
