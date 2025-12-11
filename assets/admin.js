(function ($) {
    $(document).on('click', '.gd-cron-delete', function (e) {
        const message = $(this).data('confirm');
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });

    $(document).on('click', '.gd-cron-edit-toggle', function () {
        const target = $(this).data('target');
        $('#' + target).toggle();
    });

    $(document).on('click', '.gd-cron-edit-cancel', function () {
        const target = $(this).data('target');
        $('#' + target).hide();
    });
})(jQuery);
