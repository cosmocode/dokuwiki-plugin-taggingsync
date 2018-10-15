<?php

use dokuwiki\Form\Form;

/**
 * DokuWiki Plugin taggingsync (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <dokuwiki@cosmocode.de>
 */
class admin_plugin_taggingsync_transfer extends DokuWiki_Admin_Plugin
{

    protected $taggedPages;
    protected $transferedMedia;
    protected $now;

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort()
    {
        return 123;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * Return the text that is displayed at the main admin menu
     *
     * @param string $language language code
     *
     * @return string menu string
     */
    public function getMenuText($language)
    {
        return 'taggingsync: ' . $this->getLang('menu_transfer');
    }

    public function handle()
    {
        // FIXME: add warning on tagging missing
        global $INPUT;
        if ($INPUT->has('tag')) {
            $tag = substr($INPUT->str('tag'), 1); // remove leading underscore
            $this->collectPages($tag);
        }
        if ($INPUT->bool('execute_transfer') && checkSecurityToken()) {
            $this->transferCollectedPages();
        }
    }

    /**
     * Transfer the collected pages to the client wiki
     */
    protected function transferCollectedPages()
    {
        global $INPUT;
        $this->now = time();
        $clientDataDir = $this->getConf('client_wiki_directory');
        foreach (array_keys($this->taggedPages) as $pid) {
            $this->transferSinglePage($pid, $clientDataDir, $INPUT->str('summary'));
        }

        // FIXME 2018-10-18: show some kind of success message!
    }

    /**
     * Transfer a single page and its associated media to the client wiki
     *
     * @param string $pid           the page id
     * @param string $clientDataDir the path of the client wiki's data directory
     * @param string $summary       summary of the transfer
     */
    protected function transferSinglePage($pid, $clientDataDir, $summary)
    {
        $pageAsPath = str_replace(':', '/', $pid);
        $pagePathClient = $clientDataDir . '/pages/' . $pageAsPath . '.txt';
        io_makeFileDir($pagePathClient);
        copy(wikiFN($pid), $pagePathClient);
        // FIXME 2018-10-19: This should be a proper revision stating that the page has been transfered to the client
        touch(wikiFN($pid), $this->now);

        $metaPathClient = $clientDataDir . '/meta/' . $pageAsPath . '.meta';
        io_makeFileDir($metaPathClient);
        copy(metaFN($pid, '.meta'), $metaPathClient);

        $changelogPathClient = $clientDataDir . '/meta/' . $pageAsPath . '.changes';
        $changelogSummary = $this->getLang('changelog prefix') . $summary;
        $changelog = $this->now . "\t0.0.0.0\tE\t$pid\t \t$changelogSummary\t \n";
        file_put_contents($changelogPathClient, $changelog);

        $this->writeLogLine($pid, $clientDataDir, $summary, $this->getLang('log: page'));

        $pageMedia = p_get_metadata($pid, 'relation')['media'];
        if (null !== $pageMedia) {
            $this->transferMedia(array_keys($pageMedia), $clientDataDir, $summary);
        }
    }

    /**
     * Transfer the provided media files to the client wiki
     *
     * @param string[] $pageMedia     array of media ids
     * @param string   $clientDataDir the path of the client wiki's data directory
     * @param string   $summary       summary of the transfer
     */
    protected function transferMedia($pageMedia, $clientDataDir, $summary)
    {
        foreach ($pageMedia as $mediaID) {
            if ($this->transferedMedia[$mediaID]) {
                $this->writeLogLine($mediaID, $clientDataDir, $summary, $this->getLang('log: media skipped'));
                continue;
            }
            $this->transferedMedia[$mediaID] = true;

            $mediaAsPath = str_replace(':', '/', $mediaID);
            $mediaPathClient = $clientDataDir . '/media/' . $mediaAsPath;
            io_makeFileDir($mediaPathClient);
            copy(mediaFN($mediaID), $mediaPathClient);

            $changelogPathClient = $clientDataDir . '/media_meta/' . $mediaAsPath . '.changes';
            io_makeFileDir($changelogPathClient);
            $changelogSummary = $this->getLang('changelog prefix') . $summary;
            $changelog = $this->now . "\t0.0.0.0\tE\t$mediaID\t \t$changelogSummary\t \n";
            file_put_contents($changelogPathClient, $changelog);

            $this->writeLogLine($mediaID, $clientDataDir, $summary, $this->getLang('log: media'));
        }
    }

    /**
     * Write the initial lines of this transfer's log at the client wiki
     *
     * @param string $logFN   Filename of the log page at the client wiki
     * @param string $summary summary of the transfer
     */
    protected function writeLogHeader($logFN, $summary)
    {
        io_makeFileDir($logFN);
        $fileIntro = '====== ' . sprintf($this->getLang('log: headline'), $summary) . " ======\n\n";
        $fileIntro .= sprintf($this->getLang('log: date'), dformat($this->now)) . "\n\n";
        file_put_contents($logFN, $fileIntro);
    }

    /**
     * Write a line in the transfer log
     *
     * @param string $id                  the transfered file (pageid or mediaid)
     * @param string $clientDataDir       the path of the client wiki's data directory
     * @param string $summary             summary of the transfer
     * @param string $localizedLogMessage the log message, should have a "%s" for the id
     */
    protected function writeLogLine($id, $clientDataDir, $summary, $localizedLogMessage)
    {
        $logFN = $this->getLogFN($clientDataDir);
        if (!file_exists($logFN)) {
            $this->writeLogHeader($logFN, $summary);
        }

        $logLine = '  * ' . sprintf($localizedLogMessage, ":$id") . "\n";
        file_put_contents($logFN, $logLine, FILE_APPEND);
    }

    /**
     * Return the filename for the logpage at the client wiki
     *
     * @param string $clientDataDir the path of the client wiki's data directory
     *
     * @return string the filepath of the logpage
     */
    protected function getLogFN($clientDataDir)
    {
        $logDir = str_replace(':', '/', $this->getConf('client_log_namespace'));
        $page = cleanID($this->now) . '.txt';
        return $clientDataDir . '/pages/' . $logDir . '/' . $page;
    }

    /**
     * Get the relevant pages from the tagging plugin
     *
     * @param string $tag the tag to be transfered
     */
    protected function collectPages($tag)
    {
        /** @var helper_plugin_tagging $tagging */
        $tagging = plugin_load('helper', 'tagging');
        $this->taggedPages = $tagging->findItems(['tag' => $tag], 'pid');
    }

    public function html()
    {
        global $INPUT;
        echo '<h1>' . $this->getLang('menu_transfer') . '</h1>';
        // FIXME 2018-10-12 Add intro text here!
        if (trim($this->getConf('client_wiki_directory')) === '') {
            msg('Please add server directory path to the client wiki\'s data directory in config!', -1);
            return;
        }

        if (trim($this->getConf('client_log_namespace')) === '') {
            msg('Please add the namespace in the client wiki where the logs should be written! (=> config)', -1);
            return;
        }

        $this->showTagSelector();
        if (!$INPUT->has('tag')) {
            return;
        }
        $tag = $INPUT->str('tag');
        $this->showTransferForm($tag);
    }

    /**
     * Show the form requesting the transfer summary and executing the transfer
     *
     * @param string $tag the tag to be transfered
     */
    protected function showTransferForm($tag)
    {
        echo '<ul>';
        foreach ($this->taggedPages as $pageid => $cnt) {
            echo '<li>' . html_wikilink($pageid) . '</li>';
        }
        echo '</ul>';
        // FIXME: also show associated media!

        global $ID;
        $form = new Form([
            'action' => wl($ID, ['do' => 'admin', 'page' => 'taggingsync_transfer'], false, '&'),
        ]);
        $form->setHiddenField('tag', $tag);
        $summaryInput = $form->addTextInput('summary', 'Transfer notice for changelog');
        $summaryInput->attr('required', '1');
        // FIXME 2018-10-12: make input longer

        $form->addButton('execute_transfer', 'Transfer files now');
        echo $form->toHTML();
    }

    /**
     * Show the form to select the tag which should be transferred
     */
    protected function showTagSelector()
    {
        /** @var helper_plugin_tagging $tagging */
        $tagging = plugin_load('helper', 'tagging');
        $tags = $tagging->getAllTags(':', 'tid');
        $options = array_column(array_map(function ($tagData) {
            $tagData['label'] = "$tagData[tid] ($tagData[count])";
            $tagData['key'] = '_' . $tagData['tid']; // Add underscore to enforce stringiness
            return $tagData;
        }, $tags),
            'label', 'key');
        array_unshift($options, '');

        $form = new Form([
            'id' => 'plugin__taggingsync_transferform',
            'method' => 'GET',
        ]);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'taggingsync_transfer');
        $form->addDropdown('tag', $options, $this->getLang('label: choose tag'));

        echo $form->toHTML();
    }
}
