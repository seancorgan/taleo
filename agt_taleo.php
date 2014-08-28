<?php
/**
 * Plugin Name: AGT Taleo Plugin
 * Description: A Wordpress Plugin to manage Job listing in taleo
 * Version: 0.3.0
 * Author: Sean Corgan
 * Author URI:  
 */
require_once('taleo.class.php'); 
require_once('htmlpurifier-4.3.0-standalone/HTMLPurifier.standalone.php');


// @todo - enable/disable contact form 7 integration in settings panel 
class Agt_taleo extends TaleoClient {
	
	function __construct() {
		register_activation_hook( __FILE__, array( $this, 'agt_rewrite_flush') );

		register_deactivation_hook( __FILE__, array($this, 'agt_deactivate') );

		add_action( 'init', array( $this, 'agt_register_jobs_post_type') );

		add_action( 'init', array( $this, 'agt_register_job_taxonomies') );

		add_action( 'init', array( $this, 'agt_setup_resume_paths') );

		add_action( 'admin_menu', array( $this, 'agt_plugin_menu') );

		if(is_admin() || (defined( 'WP_CLI' ) && WP_CLI) ): 
			$this->check_for_settings(); 
		endif; 

		add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts'));

		add_action( 'wp_ajax_manually_sync_jobs', array($this, 'manually_sync_jobs'));
		add_action( 'wp_ajax_agt_save_workflow', array($this, 'agt_save_workflow'));
		add_action( 'wp_ajax_agt_approve_job', array($this, 'agt_approve_job'));

		add_filter('wpcf7_hidden_field_value_reqId', array($this, 'agt_job_req_id'));
		add_filter('query_vars', array($this, 'agt_add_to_query_var'));
		add_action( 'add_meta_boxes', array( $this, 'agt_add_metabox' ) );

		// un-commet this to send job applications to Taleo
		add_action("wpcf7_before_send_mail", array($this, "agt_before_applications_sent"));

	}

	/**
	 * adds path for uploading temp resumes. 
	 */
	function agt_setup_resume_paths() { 
		// Adds path to upload temp resume files.
		$resume_path = plugin_dir_path(__FILE__).'resumes/';

		if (!file_exists($resume_path)) {
		    mkdir($resume_path, 0777, true);
		 }

		define( 'WPCF7_UPLOADS_TMP_DIR', $resume_path );
	}

	/**
	 * Updates user approval status
	 * @return json success or failure
	 */
	public function agt_approve_job() {	
		$user_ID = get_current_user_id(); 
		$approval_status = $this->agt_has_user_approved($user_ID, $_POST['id']);  

		if(add_post_meta( $_POST['id'], 'agt_approval_status_'.$user_ID, 'approved', true)) { 
			echo json_encode(array('status' => 'success', 'message' => 'Workflow updated')); 
		} else { 
			echo json_encode(array('status' => 'error')); 
		}

		// Send next notification
		$this->agt_notification_workflow($_POST['id'], $_POST['job_title']); 

	    die();
	}

	/**
	 * Adds metaboxes for job post type 
	 * @param  string $post_type The post type passed in the URL 
	 */
	function agt_add_metabox($post_type) { 
            if($post_type == 'job'){ 
            	add_meta_box('agt_workflow_approval', 'Job Publishing Approval', array($this, 'agt_workflow_approval_box'), 'job', 'side', 'default');
            }
	}

	/**
	 * Outputs Approval Box, to approve job
	 * @param  object $post the post object
	 */
	function agt_workflow_approval_box($post) { 
		$assigned_users = unserialize(get_option('assigned_users'));
		$current_user_id = get_current_user_id();

		if(!empty($assigned_users)) { 

			if(in_array($current_user_id, $assigned_users)) { 
				if(!$this->agt_has_user_approved($current_user_id, $post->ID)) { 
					echo '<button data-id="'.$post->ID.'" data-title="'.$post->post_title.'" id="approve-job" class="button-primary">Approve Job</button>'; 
				}
			}

			foreach ($assigned_users as $user_id) {
				$user = get_userdata( $user_id);
				echo '<ul class="user_approvals">'; 
				if($this->agt_has_user_approved($user_id, $post->ID)) { 
					echo '<li class="approved">'.$user->user_nicename.' Approved<li>';
				} else { 
					echo '<li class="not-approved">'.$user->user_nicename.' Awaiting Approval<li>'; 
				}
				
			}

			
		}

	}

	 /**
	 * Saves Order and User id's for emailing workflow. 
	 * @return json - success with message or error
	 * @todo this should have a Nonce for security 
	 */
	public function agt_save_workflow()
	{	

		$assigned_users = serialize($_POST['sortedIDs']);

		if(update_option('assigned_users', $assigned_users)) { 
			echo json_encode(array('status' => 'success', 'message' => 'Workflow updated')); 
		} else { 
			echo json_encode(array('status' => 'error')); 
		}

	    die();
	}

	/**
	* Send Job canidate to Taleo on job application form submission
	* @todo Need to ask @elliot about how Resume's are being passed to Taleo.   
	*/
	function agt_before_applications_sent(&$wpcf7_data) {
			// only fire for Application for employment. 
			if($wpcf7_data->title != "Application for Employment") { 
				return; 
			}

			$company_id = get_option('company_id');
			$username = get_option('username');
			$password = get_option('password');

			if(empty($company_id) || empty($username) || empty($password)) { 
				throw new Exception("Taleo Credentials Must be Set", 1);
			}

			parent::__construct($company_id, $username, $password, realpath(dirname(__FILE__)).'/DispatcherAPI.wsdl', realpath(dirname(__FILE__)).'/WebAPI.wsdl'); 
 			
 			if(empty($_GET['reqId'])) { 
 				$reqId = 930; 
 			} else { 
 				$reqId = $_GET['reqId'];
 			}

 			// Check for existing canidate. 
		   	$canidate_id = $this->findCandidateIDByEmail($wpcf7_data->posted_data['email']); 

		   	// Canidate does not alrady exist in Taleo so crate a new one. 
		   	if(empty($canidate_id)) { 

										$canidate_data = array(
											'email'                   => $wpcf7_data->posted_data['email'], 
											'lastName'                => $wpcf7_data->posted_data['last_name'], 
											'firstName'               => $wpcf7_data->posted_data['first_name'], 
											'address'                 => $wpcf7_data->posted_data['address'],
											'city'                    => $wpcf7_data->posted_data['city'],
											'country'                 => $wpcf7_data->posted_data['country'],
											'state'                   => $wpcf7_data->posted_data['region'],
											'zipCode'                 => $wpcf7_data->posted_data['zip'],
											'phone'                   => $wpcf7_data->posted_data['phone'],
											'textResume'              => $wpcf7_data->posted_data['CoverLetter'],
											'languages'               => $wpcf7_data->posted_data['Languages'],
											'Current_Salary'          => $wpcf7_data->posted_data['salary'],
											'educationLevel'          => $wpcf7_data->posted_data['Education'],
											'Current_Salary'          => $wpcf7_data->posted_data['salary'],
											'howdidyouhearSpecific'   => $wpcf7_data->posted_data['HearAbout'],
											'degree'                  => $wpcf7_data->posted_data['Education'],
											'salaryDenomination'      => $wpcf7_data->posted_data['salary'],
											'willingToTravel'         => implode(",", $wpcf7_data->posted_data['willingtotravel']),
											'availability'            => $wpcf7_data->posted_data['starting'],
											'availabilityOther'       => $wpcf7_data->posted_data['referral_source'], 
											'functionalGroup'         => $wpcf7_data->posted_data['FunctionalGroup'],
											'willingToRelocate'       => $wpcf7_data->posted_data['relocate'],
											'otherSalaryDenomination' => $wpcf7_data->posted_data['Denomination'],
											'geography'               => $wpcf7_data->posted_data['Location']
			   						   );

			   	$canidate_obj = (object) $canidate_data;
			   	$canidate_id = $this->createCandidate($canidate_obj);
			}  

		   	if(empty($canidate_id)) { 
		   		throw new Exception("Problem creating canidate", 1);
		   	}

		   	$this->upsertCandidateToRequisition($canidate_id, $reqId); 
			if (isset($wpcf7_data->uploaded_files['resume'])) {
				$this->setBinaryResume($canidate_id, basename($wpcf7_data->uploaded_files['resume']), file_get_contents($wpcf7_data->uploaded_files['resume']));
			}

	}  
	/**
	* Enques javascript and styles 
	*/ 
	function enqueue_scripts($hook) {
	    
		if($hook == 'settings_page_agt-taleo' || $hook === 'post.php') { 
			wp_enqueue_style('agt_taleo-styles', plugins_url( '/css/style.css', __FILE__ ));
			wp_enqueue_script( 'ajax-script', plugins_url( '/js/scripts.js', __FILE__ ), array('jquery') );
			wp_enqueue_script( 'jquery-ui-core');
			wp_localize_script( 'ajax-script', 'ajax_object',
	            array( 'ajax_url' => admin_url( 'admin-ajax.php' )) );
		} 

	    if( 'settings_page_agt-taleo' != $hook ) {
			// Only applies to dashboard panel
			return;
		}
	        
		

		wp_enqueue_style('agt_taleo-admin-ui-css',
                'http://ajax.aspnetcdn.com/ajax/jquery.ui/1.10.4/themes/ui-lightness/jquery-ui.min.css',false);
	
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
		header('Content-type: application/json; charset=utf-8');
		$jobs = $this->findRequisitionsForPublishing();
		$job_count = 0; 
		$update_count = 0; 
		$allRequisitionIds = array();
		if(!empty($jobs)):
			foreach ($jobs as $job) {
 				$req = $this->getRequisitionById($job->id);
				if ( $this->agt_find_object($req->flexValues->item, 'Global Business Unit') === 'Vocativ' ) {
					continue;
				}
				$allRequisitionIds[] = $job->id;
			 	$existing_post_id = $this->check_if_job_in_system($job->id);

				if ($existing_post_id === FALSE) {
			 		$this->add_job($req);
			 		$job_count++;  
				} 
				else {
					if ($this->agt_did_job_change($req, $existing_post_id)) {
			 			$this->add_job($req, $existing_post_id);
			 			$update_count++;  
					}
				}
			 } 
		endif;


		if ($allRequisitionIds) {
			$args = array(
				'numberposts' => -1,
				'post_type' => 'job',
				'post_status' => 'any',
				'meta_query' => array(
					array(
						'key' => 'agt_req_id',
						'value' => $allRequisitionIds,
						'compare' => 'NOT IN',
					),
				),
			);

			$the_query = new WP_Query( $args );
			
			if ( $the_query->have_posts() ) {
				while ( $the_query->have_posts() ) {
					$the_query->next_post();
					wp_delete_post( $the_query->post->ID );
				}
			}
		}
		echo json_encode(array("status" => "success", "message" => "$update_count jobs updated and $job_count jobs added.")); 
		die();
	}

	/**
	* Checks to see if this job is already in wordpress
	* @param $id string - the requisition ID of the job 
	* @return int|bool - the post id if it exists, otherwise false
	*/
	function check_if_job_in_system($id) { 
		$args = array(
			'numberposts' => -1,
			'post_type' => 'job',
			'post_status' => 'any',
			'meta_key' => 'agt_req_id',
			'meta_value' => $id
		);

		$the_query = new WP_Query( $args );
		
		if( $the_query->have_posts() ):
			$the_query->next_post();
			return $the_query->post->ID; 
		else: 
			return FALSE; 
		endif; 
	}
	/**
	* Goes through an array of objects and pulls out the matching property value
	* @param $array_of_objects array - An array of objects 
	* @param $field_type string - The field name from taleo
	* @return string - the value of the field
	*/
	function agt_find_object($array_of_objects, $field_type) { 

		$callback = function ($e, $field_type) use ($array_of_objects, $field_type) {
		    return $e->fieldName == $field_type; 
		};
		  
		$neededObject = array_filter($array_of_objects, $callback);

		$object = array_pop($neededObject); 

		return $object->valueStr; 
	}


	/**
	 * Send notification to user if they are the first person 
	 * assigned to approve, but have not yet approved the post 
	 * @param int $post_id the job post id. 
	 * @param string $job_title The job title
	 */
	function agt_notification_workflow($post_id, $job_title) { 
		$assigned_users = unserialize(get_option('assigned_users'));

		if(!empty($assigned_users)): 
			foreach ($assigned_users as $user_id) {
				if(!$this->agt_has_user_approved($user_id, $post_id)) { 
					
					$user = get_userdata($user_id);

					$message = $job_title."  is awaiting your approval \r\n";
					$message .= "To approve Please Visit: ".site_url('/wp-admin/post.php?post='.$post_id.'&action=edit');
					$to = $user->user_email; 

					$subject = $job_title." is awaiting your approval"; 
					$mailed = wp_mail( $to, $subject, $message, "Content-type: text/html");

					return; 
				} 
			}

			// No one left to approve change status to approve. 
			$job = array('post_status' => 'publish', 'ID' => $post_id); 
			
			wp_update_post( $job );
			
		endif; 
	}

	/**
	 * Checks if user approved this post already 
	 * @param  int  $id Id of user
	 * @return boolean     true if they have approved, flase if not. 
	 */
	function agt_has_user_approved($user_id, $post_id) { 
		$approval_status = get_post_meta($post_id, 'agt_approval_status_'.$user_id); 

		if($approval_status[0] == "approved") { 
			return true; 
		} else { 
			return false; 
		}
	}
	/**
	 * Check to see if the job changed
	 * @param  [int] $job              the req id
	 * @param  [int] $existing_post_id current post id
	 * @return bool                   Return true if it changed, false if it did not change. 
	 */
	function agt_did_job_change($job, $existing_post_id) {
		$post = get_post($existing_post_id);
		$body = $this->filter_body_text($job);
		if ($post->post_title != htmlspecialchars($job->title) || $body != $post->post_content) {
			return true;
		}
		return false;
	}

	function filter_body_text($job) {
		$body_value = $this->agt_find_object($job->flexValues->item, 'Job Description Thumbnail');
		if (!$body_value) {
			$body_value = $job->description;
		}

		$config = HTMLPurifier_Config::createDefault();
		//$config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
		$config->set('AutoFormat.RemoveEmpty', true);
		//$config->set('AutoFormat.RemoveSpansWithoutAttributes', true);
		$config->set('HTML.AllowedAttributes', array('a.href'));
		$config->set('HTML.AllowedElements', array('div', 'ul', 'ol', 'li', 'p', 'a', 'h2', 'br'));
		$config->set('CSS.AllowedFonts', array('Arial','sans-serif'));
		$config->set('CSS.AllowedProperties', array('cursor'));
		$purifier = new HTMLPurifier($config);
		
		$body_value = preg_replace('/(&nbsp;)+/', ' ', $body_value);
		$body_value = preg_replace('/ +/', ' ', $body_value);
		$body_value = str_replace('\xC2\xA0', ' ', $body_value);
		$body_value = $purifier->purify($body_value);

		return $body_value;
	}


	/**
	* Adds Job as a custom post type. 
	* @param $id object - the job object from taleo
	* @param $existing_post_id - The existing post id of the job 
	*/
	function add_job($job, $existing_post_id = null) { 

		$body_value = $this->filter_body_text($job);

		$my_post = array(
		  'post_title'    => $job->title,
		  'post_content'  => $body_value,
		  'post_status'   => 'pending',
		  'post_type' => 'job',
		  'post_author'   => 1
		);

		// add post 
		if ($existing_post_id !== null) {
			$my_post['ID'] = $existing_post_id;
			$post_id = wp_update_post( $my_post);
		}
		else {
	 		$post_id = wp_insert_post( $my_post);
	 		// Notify Workflow, only if we are a being inserted and not updated.
	 		$this->agt_notification_workflow($post_id, $job->title);   
		}

		$job_type = $this->agt_find_object($job->flexValues->item, "jobType"); 

		// This is a taxonomy
		$location = $job->location; 
		$job_type = $this->agt_find_object($job->flexValues->item, "jobType");
		$budgeted = $this->agt_find_object($job->flexValues->item, "Budgeted");
		$featured_job = $this->agt_find_object($job->flexValues->item, "Featured Job");
		$wage_frequency = $this->agt_find_object($job->flexValues->item, "Wage Frequency");

		// This is a taxonomy
		$job_category = $this->agt_find_object($job->flexValues->item, "Job Category");
		$wage_currency = $this->agt_find_object($job->flexValues->item, "Wage Currency");
		$relocation = $this->agt_find_object($job->flexValues->item, "Relocation");
		$supervisory = $this->agt_find_object($job->flexValues->item, "Supervisory");
		$employment_status = $this->agt_find_object($job->flexValues->item, "Employment Status");
		$req_owner = $this->agt_find_object($job->flexValues->item, "Main Req Owner");
		$business_unit = $this->agt_find_object($job->flexValues->item, "Global Business Unit");
		$travel = $this->agt_find_object($job->flexValues->item, "Travel");
		$hiring_manager_phone = $this->agt_find_object($job->flexValues->item, "Hiring Manager Phone");
		$hiring_manager_email = $this->agt_find_object($job->flexValues->item, "Hiring Manager email");
		$hiring_manager_name = $this->agt_find_object($job->flexValues->item, "Hiring Manager Name");

		update_post_meta($post_id, 'agt_req_id', $job->id, TRUE);

		if(!empty($job_category)): 
			wp_set_object_terms( $post_id, $job_category, 'function', TRUE );
		endif;

		if(!empty($location)):
			$location = preg_replace('/ *\(\w+\)/', '', $location); 
			wp_set_object_terms( $post_id, $location, 'joblocation', TRUE );
		endif;


		if(!empty($job_type)): 
			 update_post_meta($post_id, 'agt_job_type', $job_type, TRUE);
		endif;

		if(!empty($budgeted)): 
			 update_post_meta($post_id, 'agt_budgeted', $budgeted, TRUE);
		endif;

		if(!empty($featured_job)): 
			 update_post_meta($post_id, 'agt_featured_job', $featured_job, TRUE);
		endif;

		if(!empty($wage_frequency)): 
			 update_post_meta($post_id, 'agt_wage_frequency', $wage_frequency, TRUE);
		endif;

		if(!empty($job_category)): 
			 update_post_meta($post_id, 'agt_job_category', $job_category, TRUE);
		endif;

		if(!empty($wage_currency)): 
			 update_post_meta($post_id, 'agt_wage_currency', $wage_currency, TRUE);
		endif;

		if(!empty($req_owner)): 
			 update_post_meta($post_id, 'agt_req_owner', $req_owner, TRUE);
		endif;

		if(!empty($supervisory)): 
			 update_post_meta($post_id, 'agt_supervisory', $supervisory, TRUE);
		endif;

		if(!empty($employment_status)): 
			 update_post_meta($post_id, 'agt_employment_status', $employment_status, TRUE);
		endif;

		if(!empty($business_unit)): 
			 update_post_meta($post_id, 'agt_business_unit', $business_unit, TRUE);
		endif;

		if(!empty($travel)): 
			 update_post_meta($post_id, 'agt_travel', $travel, TRUE);
		endif;


		if(!empty($hiring_manager_phone)): 
			 update_post_meta($post_id, 'agt_hiring_manager_phone', $hiring_manager_phone, TRUE);
		endif;

		if(!empty($hiring_manager_email)): 
			 update_post_meta($post_id, 'agt_hiring_manager_email', $hiring_manager_email, TRUE);
		endif;

		if(!empty($hiring_manager_name)): 
			 update_post_meta($post_id, 'agt_hiring_manager_name', $hiring_manager_name, TRUE);
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
	            'not_found' =>  __( 'No Job found', 'Job' ),
	            'not_found_in_trash' => __( 'No Job found in Trash', 'Job' ),
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
	 * 
	 */
	function agt_deactivate() {
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
			<div id="message">
				
			</div>
			<button id="sync-jobs" class="button button-primary">Add New Jobs</button>

				
			<div style="display:none" id="loading-icon"><img src="<?php echo site_url(); ?>/wp-includes/js/tinymce/themes/advanced/skins/default/img/progress.gif"/></div>
			
			<hr>

		<div class="agt-workflow">
		<h3>Taleo Job Approval Flow</h3>
		<?php 

			$users = get_users();
			$assigned_users = unserialize(get_option('assigned_users'));


				echo "<div class='user-list left'>"; 
				echo "<h4>Users</h4>"; 
				echo "<ul id='sortable1' class='sortable'>"; 
				if(!empty($users)): 
					foreach ($users as $user) {
						if(!in_array($user->data->ID, $assigned_users)) {  
							echo "<li class='ui-state-default' id='".$user->data->ID."'><span class='ui-icon ui-icon-arrowthick-2-n-s'></span><span class='text'>".$user->data->user_nicename."</span></li>"; 
						}
					}
				endif; 
				echo "</ul>"; 
				echo "</div>"; 

		
				echo "<div class='user-list left'>"; 
				echo "<h4>Job Approval Workflow</h4>";

				echo "<ul id='sortable2' class='sortable'>"; 
				if(!empty($assigned_users)): 
					foreach ($assigned_users as $user_id) {
						$user = get_userdata( $user_id );
						echo "<li class='ui-state-default' id='".$user->ID."'><span class='ui-icon ui-icon-arrowthick-2-n-s'></span><span class='text'>".$user->user_nicename."</span></li>"; 
					}
				endif; 
				echo "</ul>"; 
				echo "</div>"; 

				?> 
				<button id="save-workflow" class="button button-primary">Save Workflow</button>
				<div style="display:none" id="loading-icon"><img src="<?php echo site_url(); ?>/wp-includes/js/tinymce/themes/advanced/skins/default/img/progress.gif"/></div>		
			</div>
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

if ( defined( 'WP_CLI' ) && WP_CLI )
	require_once dirname( __FILE__ ) . '/taleo-wpcli.php';

