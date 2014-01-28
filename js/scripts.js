jQuery(document).ready(function($) {

    $( "#sortable1, #sortable2" ).sortable({
      connectWith: ".sortable", 
      placeholder: "ui-state-highlight"
    }).disableSelection();

	/**
	 * Syncs JObs
	 * @return {Json} return job syncing status. 
	 */
	$('#sync-jobs').on('click', function() {
		var data = {
			action: 'manually_sync_jobs'
		};

		$(this).hide(); 
		$('#loading-icon').show(); 

		jQuery.post(ajax_object.ajax_url, data, function(response) {
				console.log(response.message);
				$('#sync-jobs').show(); 
				$('#loading-icon').hide(); 
				$('#message').html(response.message);  
				window.setTimeout(function() { 
					$('#message').hide(); 
				},1500); 
		}, "json");
	}); 

	/**
	 * Saves Job Approval Workflow
	 * @return {json} return success or error of approval workflow. 
	 */
	$('#save-workflow').on('click', function() {
		var sortedIDs = $( "#sortable2" ).sortable( "toArray" );
		
		var data = {
			action: 'agt_save_workflow', 
			sortedIDs: sortedIDs
		};

		$(this).hide(); 
		$('.agt-workflow #loading-icon').show(); 

		jQuery.post(ajax_object.ajax_url, data, function(response) {
				$('#save-workflow').show(); 
				$('.agt-workflow #loading-icon').hide();  
				window.setTimeout(function() { 
					$('.agt-workflow #message').hide(); 
				},1500); 
		}, "json");
	})


	$('#approve-job').on('click', function(e) {
		e.preventDefault(); 
		var data = {
			action: 'agt_approve_job', 
			post_title: $(this).data('title'), 
			id: $(this).data('id')
		};
		console.log(ajax_object); 
		$(this).hide(); 
		//$('.agt-workflow #loading-icon').show(); 

		jQuery.post(ajax_object.ajax_url, data, function(response) {
				if(response.status == "success"){
					location.reload();
				}
				
		}, "json");
	})

});