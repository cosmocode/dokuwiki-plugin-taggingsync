jQuery(function() {
    const $transferForm = jQuery('#plugin__taggingsync_transferform');
    $transferForm.find('select[name="tag"]').on('change', function (event) {
        $transferForm.submit();
    });
});
