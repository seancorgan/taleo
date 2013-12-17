<?php
/**
 * Plugin Name: AGT Taleo Plugin
 * Description: A Wordpress Plugin to manage Job listing in taleo
 * Version: 0.1
 * Author: Sean Corgan
 * Author URI:  
 */
require_once('taleo.class.php'); 

// @todo - enable/disable contact form 7 integration 
// @todo - should refactor add_job - DRY!

class Agt_taleo extends TaleoClient {
	
	function __construct() {
		register_activation_hook( __FILE__, array( $this, 'agt_rewrite_flush') );
		add_action( 'init', array( $this, 'agt_register_jobs_post_type') );

		add_action( 'init', array( $this, 'agt_register_job_taxonomies') );

		add_action( 'admin_menu', array( $this, 'agt_plugin_menu') );

		if(is_admin()): 
			$this->check_for_settings(); 
		endif; 

		add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action( 'wp_ajax_manually_sync_jobs', array($this, 'manually_sync_jobs'));

		add_filter('wpcf7_hidden_field_value_reqId', array($this, 'agt_job_req_id'));
		add_filter('query_vars', array($this, 'agt_add_to_query_var'));


		// un-commet this to send job applications to Taleo
		// add_action("wpcf7_before_send_mail", array($this, "agt_before_applications_sent"));  

	}
	/**
	* Send Job canidate to Taleo on job application form submission
	*/

	function agt_before_applications_sent(&$wpcf7_data) {  
 
		   	$canidate_data = array('email' => $wpcf7_data->posted_data['email'], 
		   						   'lastName' => $wpcf7_data->posted_data['last_name'], 
		   						   'firstName' => $wpcf7_data->posted_data['email'], 
		   						   'address' => $wpcf7_data->posted_data['first_name'],
		   						   'city' => $wpcf7_data->posted_data['city'],
		   						   'country' => $wpcf7_data->posted_data['country'],
		   						   'state' => $wpcf7_data->posted_data['region'],
		   						   'zipCode' => $wpcf7_data->posted_data['zip'],
		   						   'phone' => $wpcf7_data->posted_data['phone'],
		   						   'textResume' => $wpcf7_data->posted_data['CoverLetter'],
		   						   'languages' => $wpcf7_data->posted_data['Languages'],
		   						   'Current_Salary' => $wpcf7_data->posted_data['salary'],
		   						   'educationLevel' => $wpcf7_data->posted_data['Education'],
		   						   'Current_Salary' => $wpcf7_data->posted_data['salary'],
		   						   'howdidyouhearSpecific' => $wpcf7_data->posted_data['HearAbout'],
		   						   'degree' => $wpcf7_data->posted_data['Education'],
		   						   'salaryDenomination' => $wpcf7_data->posted_data['salary'],
		   						   'willingToTravel' => implode(",", $wpcf7_data->posted_data['willingtotravel']),
		   						   'availability' => $wpcf7_data->posted_data['starting'],
		   						   'availabilityOther' => $wpcf7_data->posted_data['referral_source']);

		   	$canidate_obj = (object) $canidate_data; 
		   	$canidate_id = $this->createCandidate($canidate_obj);
	}  
	/**
	* Enques javascript and styles 
	*/ 
	function enqueue_scripts($hook) {
    if( 'settings_page_agt-taleo' != $hook ) {
		// Only applies to dashboard panel
		return;
	    }
	        
		wp_enqueue_script( 'ajax-script', plugins_url( '/js/scripts.js', __FILE__ ), array('jquery') );

		wp_localize_script( 'ajax-script', 'ajax_object',
	            array( 'ajax_url' => admin_url( 'admin-ajax.php' )) );
	}

	/**
	* updates hidden field for contact form 7
	* @return Return GET param reqId
	*/ 
	function agt_job_req_id() { 
	    return $_GET['reqId']; 
	}

	/**
	* Allows for a query var in wordpress. 
	* @param string - the get string? 
	* @return Return GET param reqId
	*/ 
	function agt_add_to_query_var($vars) {
	    $vars[] = 'reqId';
	    return $vars;
	}


	/**
	* Updates wordpress with Jobs from Taleo
	* @todo update jobs that are already in the system 
	*/
	function manually_sync_jobs() {
		global $wpdb;
		$jobs = $this->findRequisitionsForPublishing("", TRUE);
		
		if(!empty($jobs)):
			foreach ($jobs as $job) {
			 	if(!$this->check_if_job_in_system($job->id)): 
			 		$this->add_job($job->id); 
			 	endif;
			 } 
		endif; 
		die();
	}

	/**
	* Checks to see if this job is already in wordpress
	* @param $id string - the requisition ID of the job 
	* @return bool - true if it exists false if it dont. 
	*/
	function check_if_job_in_system($id) { 
		$args = array(
			'numberposts' => -1,
			'post_type' => 'job',
			'meta_key' => 'requisition_id',
			'meta_value' => $id
		);

		$the_query = new WP_Query( $args );
		
		if( $the_query->have_posts() ):
			return TRUE; 
		else: 
			return FALSE; 
		endif; 
	}

	/**
	* Adds Job 
	* @param $id string - the requisition ID of the job  
	*/
	function add_job($id) { 

 		$job = $this->getRequisitionById($id);

		 $my_post = array(
		  'post_title'    => $job->title,
		  'post_content'  => $job->description,
		  'post_status'   => 'publish',
		  'post_type' => 'job',
		  'post_author'   => 1
		);

		// add post 
		$post_id = wp_insert_post( $my_post);

		// This is a taxonomy
		$location = $job->location; 
		$job_type = $job->flexValues->item[7]->valueStr;
		$budgeted = $job->flexValues->item[9]->valueStr;
		$featured_job = $job->flexValues->item[10]->valueStr;
		$wage_frequency = $job->flexValues->item[11]->valueStr;
		// This is a taxonomy
		$job_category = $job->flexValues->item[12]->valueStr;
		$wage_currency = $job->flexValues->item[13]->valueStr;
		$relocation = $job->flexValues->item[7]->valueStr;
		$supervisory = $job->flexValues->item[29]->valueStr;
		$employment_status = $job->flexValues->item[30]->valueStr;
		$req_owner = $job->flexValues->item[31]->valueStr;
		$business_unit = $job->flexValues->item[33]->valueStr;
		$travel = $job->flexValues->item[36]->valueStr;
		$hiring_manager_phone = $job->flexValues->item[38]->valueStr;
		$hiring_manager_email = $job->flexValues->item[37]->valueStr;
		$hiring_manager_name = $job->flexValues->item[39]->valueStr;

		add_post_meta($post_id, 'agt_req_id', $id, TRUE);

		if(!empty($job_category)): 
			wp_set_object_terms( $post_id, $job_category, 'function', TRUE );
		endif;

		if(!empty($location)): 
			wp_set_object_terms( $post_id, $location, 'joblocation', TRUE );
		endif;


		if(!empty($job_type)): 
			 add_post_meta($post_id, 'agt_job_type', $job_type, TRUE);
		endif;

		if(!empty($budgeted)): 
			 add_post_meta($post_id, 'agt_budgeted', $budgeted, TRUE);
		endif;

		if(!empty($featured_job)): 
			 add_post_meta($post_id, 'agt_featured_job', $featured_job, TRUE);
		endif;

		if(!empty($wage_frequency)): 
			 add_post_meta($post_id, 'agt_wage_frequency', $wage_frequency, TRUE);
		endif;

		if(!empty($job_category)): 
			 add_post_meta($post_id, 'agt_job_category', $job_category, TRUE);
		endif;

		if(!empty($wage_currency)): 
			 add_post_meta($post_id, 'agt_wage_currency', $wage_currency, TRUE);
		endif;

		if(!empty($req_owner)): 
			 add_post_meta($post_id, 'agt_req_owner', $req_owner, TRUE);
		endif;

		if(!empty($supervisory)): 
			 add_post_meta($post_id, 'agt_supervisory', $supervisory, TRUE);
		endif;

		if(!empty($employment_status)): 
			 add_post_meta($post_id, 'agt_employment_status', $employment_status, TRUE);
		endif;

		if(!empty($business_unit)): 
			 add_post_meta($post_id, 'agt_business_unit', $business_unit, TRUE);
		endif;

		if(!empty($travel)): 
			 add_post_meta($post_id, 'agt_travel', $travel, TRUE);
		endif;


		if(!empty($hiring_manager_phone)): 
			 add_post_meta($post_id, 'agt_hiring_manager_phone', $hiring_manager_phone, TRUE);
		endif;

		if(!empty($hiring_manager_email)): 
			 add_post_meta($post_id, 'agt_hiring_manager_email', $hiring_manager_email, TRUE);
		endif;

		if(!empty($hiring_manager_name)): 
			 add_post_meta($post_id, 'agt_hiring_manager_name', $hiring_manager_name, TRUE);
		endif;
	}

	/**
	* Register the the custom post type to store our job data
	*/
	function agt_register_jobs_post_type() {
	    register_post_type( 'Job', array(
	        'public' => true,
	        'publicly_queryable' => true,
	        'show_ui' => true,
	        'show_in_menu' => true,
	        'query_var' => true,
	        'rewrite' => array( 'slug' => 'job' ),
	        'has_archive' => true,
	        'hierarchical' => false,
	        'menu_position' => null,
	        'supports' => array( 'title', 'editor',  'thumbnail', 'custom-fields'),
	        'capability_type' => 'post',
	        'capabilities' => array(),
	        'labels' => array(
	            'name' => __( 'Jobs', 'Job' ),
	            'singular_name' => __( 'Job', 'Job' ),
	            'add_new' => __( 'Add New', 'Job' ),
	            'add_new_item' => __( 'Add New Job', 'Job' ),
	            'edit_item' => __( 'Edit Job', 'Job' ),
	            'new_item' => __( 'New Job', 'Job' ),
	            'all_items' => __( 'All Jobs', 'Job' ),
	            'view_item' => __( 'View Job', 'Job' ),
	            'search_items' => __( 'Search Jobs', 'Job' ),
	            'not_found' =>  __( 'No Job found', 'news' ),
	            'not_found_in_trash' => __( 'No Job found in Trash', 'news' ),
	            'parent_item_colon' => '',
	            'menu_name' => 'Jobs'
	        )
	    ) );
	}

	/**
	* Register the custom taxonomies for jobs
	*/
	function agt_register_job_taxonomies() {
	    // Add new taxonomy, make it hierarchical (like categories)
	    $labels = array(
	        'name'              => _x( 'Location', 'location' ),
	        'singular_name'     => _x( 'Location', 'location' ),
	        'search_items'      => __( 'Search Locations' ),
	        'all_items'         => __( 'All Locations' ),
	        'parent_item'       => __( 'Parent Location' ),
	        'parent_item_colon' => __( 'Parent Location:' ),
	        'edit_item'         => __( 'Edit Location' ),
	        'update_item'       => __( 'Update Location' ),
	        'add_new_item'      => __( 'Add New Location' ),
	        'new_item_name'     => __( 'New Location' ),
	        'menu_name'         => __( 'Locations' ),
	    );

	    $args = array(
	        'hierarchical'      => true,
	        'labels'            => $labels,
	        'show_ui'           => true,
	        'show_admin_column' => true,
	        'query_var'         => true,
	        'rewrite'           => array( 'slug' => 'joblocation' ),
	    );

	    register_taxonomy( 'joblocation', 'job', $args );


	     $labels = array(
	        'name'              => _x( 'Function', 'Function' ),
	        'singular_name'     => _x( 'Function', 'Function' ),
	        'search_items'      => __( 'Search Functions' ),
	        'all_items'         => __( 'All Functions' ),
	        'parent_item'       => __( 'Parent Function' ),
	        'parent_item_colon' => __( 'Parent Function:' ),
	        'edit_item'         => __( 'Edit Function' ),
	        'update_item'       => __( 'Update Function' ),
	        'add_new_item'      => __( 'Add New Function' ),
	        'new_item_name'     => __( 'New Function' ),
	        'menu_name'         => __( 'Functions' ),
	    );

	    $args = array(
	        'hierarchical'      => true,
	        'labels'            => $labels,
	        'show_ui'           => true,
	        'show_admin_column' => true,
	        'query_var'         => true,
	        'rewrite'           => array( 'slug' => 'function' ),
	    );

	    register_taxonomy( 'function', 'job', $args );
	}

	/**
	* Should Help Handle permalinks on plugin activation
	*/
	function agt_rewrite_flush() {
    	$this->agt_register_jobs_post_type();
    	flush_rewrite_rules();
	}

	/**
	*  Adds Settings page, and fields
	*/
	function agt_plugin_menu() {
		 add_options_page( 'Taleo Settings', 'Taleo Settings', 'manage_options', 'agt-taleo', array($this, 'agt_plugin_options') );
		 $this->register_agt_settings(); 
	}

	/**
	*  Settings Page
	*/
	function agt_plugin_options() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		?> 
		<div class="wrap">
		<?php screen_icon(); ?> 
		
		<h2>AGT Taleo Settings</h2>
		<form method="post" action="options.php"> 
			<?php settings_fields( 'agt-taleo-settings' ); ?>
			<?php do_settings_sections( 'agt-taleo-settings' ); ?>

			 <table class="form-table">
		        <tr valign="top">
		        <th scope="row">Company ID</th>
		        <td><input type="text" name="company_id" value="<?php echo get_option('company_id'); ?>" /></td>
		        </tr>
		         
		        <tr valign="top">
		        <th scope="row">Username</th>
		        <td><input type="text" name="username" value="<?php echo get_option('username'); ?>" /></td>
		        </tr>
		        
		        <tr valign="top">
		        <th scope="row">Password</th>
		        <td><input type="text" name="password" value="<?php echo get_option('password'); ?>" /></td>
		        </tr>
		    </table>
			<?php submit_button(); ?>

		</form>
			<hr>
			<h3>Manual Sync</h3>
			<button id="sync-jobs" class="button button-primary">Add New Jobs</button>

		<?php 
	}

	/**
	*  Registers Plugin Settings
	*/
	function register_agt_settings() { // whitelist options
	  register_setting( 'agt-taleo-settings', 'company_id' );
	  register_setting( 'agt-taleo-settings', 'username' );
	  register_setting( 'agt-taleo-settings', 'password' );
	}

	/**
	*  Checks to make sure settings are set, displays error if not, run parent constructor if they are
	*/
	function check_for_settings() { 
		$company_id = get_option('company_id');
		$username = get_option('username');
		$password = get_option('password');

		if(empty($company_id) || empty($username) || empty($password)) { 
			add_action( 'admin_notices', array($this, 'settings_missing_message') );
		} else {  
			parent::__construct($company_id, $username, $password, realpath(dirname(__FILE__)).'/DispatcherAPI.wsdl', realpath(dirname(__FILE__)).'/WebAPI.wsdl'); 
		}
	}

	/**
	*  Displays Error Message
	*/	
	function settings_missing_message() {
	    ?>
	    <div class="error">
	        <p><?php _e( 'No Settings set for Taleo!', 'agt-taleo' ); ?></p>
	    </div>
	    <?php
	}
}

$taleo = new Agt_taleo(); 