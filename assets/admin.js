(function ($) {
    $(document).on('click', '.gd-cron-delete', function (e) {
        const message = $(this).data('confirm');
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });
})(jQuery);
