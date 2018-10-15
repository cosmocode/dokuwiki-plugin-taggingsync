<?php

/**
 * DokuWiki Plugin sentry (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */
class action_plugin_taggingsync extends DokuWiki_Action_Plugin
{

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    /**
     * Get the diff between a page at the primary and the client wiki
     *
     * @param Doku_Event $event
     */
    public function handleAjax(Doku_Event $event)
    {
        if (strpos($event->data, 'taggingsync') !== 0) {
            return;
        }

        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        $pid = $INPUT->str('pid');

        $primaryText = rawWiki($pid);
        $clientText = $INPUT->str('clientPageFN') ? file_get_contents($INPUT->str('clientPageFN')) : '';

        $diff = new Diff(explode("\n", $clientText), explode("\n", $primaryText));
        $diffformatter = new TableDiffFormatter();
        echo $diffformatter->format($diff);
    }
}
