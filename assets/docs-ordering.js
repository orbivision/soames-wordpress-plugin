jQuery(function ($) {
    var $list = $('#the-list');
    if (!$list.length || typeof SoamesDocsOrder === 'undefined') {
        return;
    }

    // Lock cell widths while dragging so the row doesn't collapse (standard WP
    // list-table sortable trick).
    function fixHelper(e, ui) {
        ui.children().each(function () {
            $(this).width($(this).width());
        });
        return ui;
    }

    function rowOrder() {
        return $list.children('tr').map(function () {
            var id = this.id || '';
            return id.indexOf('post-') === 0 ? parseInt(id.replace('post-', ''), 10) : null;
        }).get().filter(function (n) {
            return n;
        });
    }

    $list.sortable({
        items: 'tr',
        axis: 'y',
        cursor: 'move',
        opacity: 0.7,
        helper: fixHelper,
        placeholder: 'soames-docs-order-placeholder',
        start: function (e, ui) {
            ui.placeholder.height(ui.item.height());
        },
        update: function () {
            $list.css('opacity', 0.5);
            $.post(SoamesDocsOrder.ajaxUrl, {
                action: 'soames_reorder_docs',
                nonce: SoamesDocsOrder.nonce,
                order: rowOrder()
            }).always(function () {
                $list.css('opacity', 1);
            });
        }
    });
});
