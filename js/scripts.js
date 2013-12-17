jQuery(document).ready(function($) {
	
	$('#sync-jobs').on('click', function() {
		var data = {
			action: 'manually_sync_jobs'
		};

		jQuery.post(ajax_object.ajax_url, data, function(response) {
			console.log(response);
		});
	})

});