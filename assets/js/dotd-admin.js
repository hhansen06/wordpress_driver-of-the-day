(function ($) {
	'use strict';

	$(function () {
		var $list = $('#dotd-class-order-sortable');
		if (!$list.length) {
			return;
		}

		$list.sortable({
			axis: 'y',
			tolerance: 'pointer',
			items: '> li.dotd-class-order-item',
			cursor: 'move'
		});
	});
})(jQuery);
