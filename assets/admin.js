jQuery(function ($) {
    // Open media library and populate the hidden ID field + image preview
    $(document).on('click', '.soames-media-upload', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        var frame = wp.media({
            title: 'Select Image',
            button: { text: 'Use this image' },
            multiple: false,
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#' + target + '_id').val(attachment.id);
            $('#' + target + '_preview').attr('src', attachment.url).show();
        });
        frame.open();
    });

    // Clear a media selection
    $(document).on('click', '.soames-media-clear', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        $('#' + target + '_id').val('');
        $('#' + target + '_preview').attr('src', '').hide();
    });
});
