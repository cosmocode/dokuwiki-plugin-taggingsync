jQuery(function () {

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
        }).done(function (data) {
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

    function editTags() {
        const $elem = jQuery(this);
        let tags = $elem.text();
        const pid = $elem.data('page');
        tags = window.prompt('Edit Tags', tags);
        if (tags === null) return;

        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php?call=plugin_tagging_save',
            {
                tagging: {
                    id: pid,
                    tags: tags
                }
            },
            function () {
                $elem.text(tags);
            }
        );
    }

    jQuery('.taggingsync_diff').click(requestDiffHandler);
    jQuery('.taggingsync_tags').click(editTags);
});
