/**
 * Post Expiry box on the edit screen.
 *
 * - Picking a date with no time fills in the default time (BPE_EXPIRY.defaultTime).
 * - "Clear expiry" empties both fields; saving the post then removes the expiry.
 *
 * Delegated from document because the block editor moves meta boxes around
 * the DOM after load.
 */
(function ($) {
	'use strict';

	var defaultTime = ( window.BPE_EXPIRY && window.BPE_EXPIRY.defaultTime ) || '23:59';

	$(document).on('change.bonsai_bpe', '#bpe_date', function () {
		var $time = $('#bpe_time');

		if ( $(this).val() && ! $time.val() ) {
			$time.val(defaultTime);
		}
	});

	$(document).on('click.bonsai_bpe', '#bpe_clear', function (event) {
		event.preventDefault();
		$('#bpe_date, #bpe_time').val('');
		$('#bpe_date').trigger('focus');
	});
})(jQuery);
