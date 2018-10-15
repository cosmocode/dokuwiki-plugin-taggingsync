jQuery(function() {

    const diffRepo = {};

    function requestDiffHandler() {
        const $this = jQuery(this);
        const pid = $this.data('pid');
        if (diffRepo[pid]) {
            showDiff(pid);
            return;
        }

        const clientPageFN = $this.data('cfn');
        const ajaxUrl = DOKU_BASE + 'lib/exe/ajax.php';
        jQuery.get(ajaxUrl, {
            call: 'taggingsync_getdiff',
            pid: pid,
            clientPageFN: clientPageFN,
        }).done(function(data){
            diffRepo[pid] = data;
            showDiff(pid);
        });
    }

    function showDiff(pid) {
        const diffWrapper = jQuery('#plugin__taggingsync_diff');
        diffWrapper.find('table').html(diffRepo[pid]);
        diffWrapper.dialog({
            modal: true,
            appendTo: '.dokuwiki',
            minWidth: 1050,
        });
    }

    jQuery('.taggingsync_diff').click(requestDiffHandler);
});
