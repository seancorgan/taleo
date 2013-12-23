jQuery(document).ready(function($) {
	
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
	})

});