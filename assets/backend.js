/**
 * Initialize Sorting
 * @deprecated OctoberCMS v2.2+ only
 * @returns 
 */
function initializeSorting () {
    if (typeof Sortable === 'undefined') return;
    var $tbody = $('.drag-handle').parents('table.data tbody');
	$tbody.each(function () {
		var data = {};
		var field = this.closest('div.form-group[data-field-name]');

		if (field) {
			data.fieldName = field.dataset.fieldName;
		}
		Sortable.create(this, {
			handle: '.drag-handle',
			animation: 150,
			onEnd: function (evt) {
				var $inputs = $(evt.target).find('td>div.drag-handle>input');
				var $form = $('<form style="display: none;">');
				$form.append($inputs.clone())
					.request('onReorderRelation', {
						data: data,
						complete: function () {
							$form.remove();
						}
					});
			}
		});
	});
}

function initializePOSMallTaxListInteractiveCells () {
    var selector = [
        '.posmall-tax-list input',
        '.posmall-tax-list label',
        '.posmall-tax-list button',
        '.posmall-tax-list select',
        '.posmall-tax-list textarea',
        '.posmall-tax-list details',
        '.posmall-tax-list summary',
        '.posmall-tax-list [data-request]',
        '.posmall-tax-list [data-control]',
        '.posmall-tax-list [role="button"]'
    ].join(',');

	    $(document)
	        .off('click.posmallTaxList mousedown.posmallTaxList', selector)
	        .on('click.posmallTaxList mousedown.posmallTaxList', selector, function (event) {
	            event.stopPropagation();
	        });
}

function initializePOSMallTaxAutoUpdateSwitches () {
    $(document)
        .off('change.posmallTaxAutoUpdate', '.posmall-tax-auto-update-switch')
        .on('change.posmallTaxAutoUpdate', '.posmall-tax-auto-update-switch', function (event) {
            event.stopPropagation();

            $(this).request('onToggleUsaTaxAutoUpdate', {
                data: {
                    id: this.dataset.posmallTaxId,
                    enabled: this.checked ? 1 : 0
                }
            });
        });
}

$(function () {
    initializeSorting();
    initializePOSMallTaxListInteractiveCells();
    initializePOSMallTaxAutoUpdateSwitches();
    $(window).on('ajaxUpdateComplete', initializeSorting)
    $(window).on('ajaxUpdateComplete', initializePOSMallTaxListInteractiveCells)
    $(window).on('ajaxUpdateComplete', initializePOSMallTaxAutoUpdateSwitches)
});
