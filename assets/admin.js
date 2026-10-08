/**
 * Post Expiry box on the edit screen.
 *
 * - Picking a date with no time fills in the default time (BPE_EXPIRY.defaultTime).
 * - "Clear expiry" empties both fields; saving the post then removes the expiry.
 * - Classic editor: if the post is in a required category (BPE_EXPIRY.required)
 *   and has no date, Publish / Schedule / Update is stopped with a message.
 *   Save Draft still works. The block editor's check is in admin-block.js.
 *
 * Delegated from document because the block editor moves meta boxes around
 * the DOM after load.
 */
(function ($) {
	'use strict';

	var config      = window.BPE_EXPIRY || {};
	var defaultTime = config.defaultTime || '23:59';
	var required    = $.map( config.required || [], function ( id ) {
		return String( id );
	} );

	$(document).on('change.bonsai_bpe', '#bpe_date', function () {
		var $time = $('#bpe_time');

		if ( $(this).val() && ! $time.val() ) {
			$time.val(defaultTime);
		}

		bonsai_bpe_hide_error();
	});

	$(document).on('click.bonsai_bpe', '#bpe_clear', function (event) {
		event.preventDefault();
		$('#bpe_date, #bpe_time').val('');
		$('#bpe_date').trigger('focus');
	});

	/**
	 * Whether any ticked category in the classic editor's checklist is required.
	 *
	 * @return {boolean}
	 */
	function bonsai_bpe_in_required_category() {
		var found = false;

		$('#categorychecklist input[type="checkbox"]:checked').each(function () {
			if ( -1 !== $.inArray( String( $(this).val() ), required ) ) {
				found = true;
				return false;
			}
		});

		return found;
	}

	function bonsai_bpe_hide_error() {
		$('.bpe-box__error').prop('hidden', true).text('');
	}

	// Classic editor only: #publish is Publish, Schedule or Update there.
	if ( required.length ) {
		$(document).on('click.bonsai_bpe', '#post #publish', function (event) {
			if ( $('#bpe_date').val() || ! bonsai_bpe_in_required_category() ) {
				return;
			}

			event.preventDefault();
			$('.bpe-box__error').text(config.requiredMessage).prop('hidden', false);
			$('#bpe_date').trigger('focus');
		});
	}
})(jQuery);
