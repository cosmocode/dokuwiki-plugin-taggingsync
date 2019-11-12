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
    // just for making things easier to read
    const LOCAL = true;
    const REMOTE = false;
    const PAGE = true;
    const MEDIA = false;


    protected $taggedPages;
    /**
     * @var array
     */
    protected $deletedPages = [];
    protected $transferedMedia;
    protected $now;

    /** @var helper_plugin_taggingsync */
    protected $hlp;

    public function __construct()
    {
        $this->hlp = plugin_load('helper', 'taggingsync');
    }

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

    /** @inheritdoc */
    public function getMenuIcon()
    {
        return __DIR__ . '/../sync.svg';
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
        return $this->getLang('menu') . ' ' . $this->getLang('menu_transfer');
    }

    /** @inheritdoc */
    public function handle()
    {
        if (!$this->hlp->checkRequirements(false)) return;

        global $INPUT;
        if ($INPUT->has('tag')) {
            $tag = substr($INPUT->str('tag'), 1); // remove leading underscore
            $this->collectPages($tag);
            $this->collectDeletions();
        }
        if ($INPUT->bool('execute_transfer') && checkSecurityToken()) {
            $this->transferCollectedPages();
        }
    }

    /**
     * Transfer the collected pages and optionally page deletions to the client wiki
     */
    protected function transferCollectedPages()
    {
        global $INPUT;
        $this->now = time();
        $clientDataDir = $this->getConf('client_wiki_directory');

        // sync deletions first because the process checks for the latest sync, which is not supposed to be now
        if ($INPUT->bool('sync_delete') && $this->deletedPages) {
            foreach ($this->deletedPages as $page) {
                $this->transferSinglePage($page['id'], $clientDataDir, 'Sync delete');
            }
        }

        foreach (array_keys($this->taggedPages) as $pid) {
            $this->transferSinglePage($pid, $clientDataDir, $INPUT->str('summary'));
        }

        msg($this->getLang('msg: transfer done'), 1);
    }

    /**
     * Creates a list of files that are tagged with he current tag and differ from the client wiki
     *
     * @return array
     */
    protected function previewTransferList()
    {
        $transfer = [];

        foreach (array_keys($this->taggedPages) as $pid) {
            if (!$this->hlp->filesEqual(wikiFN($pid), $this->hlp->clientFileForID($pid))) {
                $transfer['p' . $pid] = ['id' => $pid, 'type' => 'page'];
            }

            $pageMeta = p_get_metadata($pid, 'relation');
            if (!isset($pageMeta['media']) || !is_array($pageMeta['media'])) {
                continue;
            }

            foreach (array_keys($pageMeta['media']) as $mid) {
                if (!$this->hlp->filesEqual(mediaFN($pid), $this->hlp->clientFileForID($pid, 'media'))) {
                    $transfer['p' . $mid] = ['id' => $mid, 'type' => 'media'];
                }
            }
        }

        return $transfer;
    }


    /**
     * Transfer a single page and its associated media to the client wiki
     *
     * @param string $pid the page id
     * @param string $clientDataDir the path of the client wiki's data directory
     * @param string $summary summary of the transfer
     */
    protected function transferSinglePage($pid, $clientDataDir, $summary)
    {
        $pagePathClient = $this->hlp->clientFileForID($pid, 'page');
        $metaPathClient = $this->hlp->clientFileForID($pid, 'meta');
        $changelogPathClient = $this->hlp->clientFileForID($pid, 'changelog');

        $deleting = is_file(wikiFN($pid)) ? false : true;

        // copy page if it differs or delete if it does not exist in primary wiki
        if (!$this->hlp->filesEqual(wikiFN($pid), $pagePathClient)) {
            if ($deleting) {
                unlink($pagePathClient);
            } else {
                io_makeFileDir($pagePathClient);
                copy(wikiFN($pid), $pagePathClient);
                $modTime = filemtime(wikiFN($pid));
                touch($pagePathClient, $modTime);
            }

            io_makeFileDir($metaPathClient);
            copy(metaFN($pid, '.meta'), $metaPathClient);

            $changelogSummary = $this->getLang('changelog prefix') . $summary;
            $changelog = $this->now . "\t0.0.0.0\tE\t$pid\t \t$changelogSummary\t \n";
            file_put_contents($changelogPathClient, $changelog);

            $this->writeLogLine($pid, $clientDataDir, $summary, $this->getLang('log: page'));
        }

        if ($deleting) return;

        // check media file dependencies
        $pageMeta = p_get_metadata($pid, 'relation');
        if (is_array($pageMeta) && isset($pageMeta['media'])) {
            $this->transferMedia(array_keys($pageMeta['media']), $clientDataDir, $summary);
        }
    }

    /**
     * Transfer the provided media files to the client wiki
     *
     * @param string[] $pageMedia array of media ids
     * @param string $clientDataDir the path of the client wiki's data directory
     * @param string $summary summary of the transfer
     */
    protected function transferMedia($pageMedia, $clientDataDir, $summary)
    {
        foreach ($pageMedia as $mediaID) {
            if ($this->transferedMedia[$mediaID]) {
                continue;
            }
            $this->transferedMedia[$mediaID] = true;

            $mediaPathClient = $this->hlp->clientFileForID($mediaID, 'media');
            $changelogPathClient = $this->hlp->clientFileForID($mediaID, 'mediachangelog');

            if ($this->hlp->filesEqual(mediaFN($mediaID), $mediaPathClient)) {
                continue; // files are the same, skip copying
            }

            io_makeFileDir($mediaPathClient);
            copy(mediaFN($mediaID), $mediaPathClient);

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
     * @param string $logFN Filename of the log page at the client wiki
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
     * @param string $id the transfered file (pageid or mediaid)
     * @param string $clientDataDir the path of the client wiki's data directory
     * @param string $summary summary of the transfer
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

    /**
     * Find page deletions since the latest sync using the global changelog.
     */
    protected function collectDeletions()
    {
        $clientDataDir = $this->getConf('client_wiki_directory');
        $since = $this->getTimeOfLastTransfer($clientDataDir);
        $changes = getRecentsSince($since);

        if ($changes) {
            $this->deletedPages = array_filter($changes, function ($change) {
                return $change['type'] === DOKU_CHANGE_TYPE_DELETE;
            });
        }
    }

    /** @inheritdoc */
    public function html()
    {
        global $INPUT;
        echo '<h1>' . $this->getLang('menu_transfer') . '</h1>';
        echo $this->locale_xhtml('transfer');

        if (!$this->hlp->checkRequirements(true)) return;

        $this->showTagSelector();

        if ($INPUT->str('tag') === '') {
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
        // prepare lists
        $listDeleted = '';
        $preview = $this->previewTransferList();
        $count = count($preview);
        if ($count) {
            $list = '<ul>';
            foreach ($preview as $item) {
                if (!page_exists($item['id'])) {
                    continue;
                }
                if ($item['type'] === 'page') {
                    $list .= '<li>' . html_wikilink($item['id']) . '</li>';
                } else {
                    $list .= '<li>' . $item['id'] . '</li>';
                }
            }
            $list .= '</ul>';

            if (count($this->deletedPages)) {
                $listDeleted = '<p>' . $this->getLang('label: recently deleted') . '</p>';
                $listDeleted .= '<ul>';
                foreach ($this->deletedPages as $item) {
                    $listDeleted .= '<li>' . $item['id'] . '</li>';
                }
                $listDeleted .= '</ul>';
            }
        } else {
            $list = '<p>' . $this->getLang('label: no file') . '</p>';
        }


        global $ID;
        $form = new Form([
            'action' => wl($ID, ['do' => 'admin', 'page' => 'taggingsync_transfer'], false, '&'),
            'class' => 'taggingsync_transfer'
        ]);
        $form->setHiddenField('tag', $tag);
        $form->addFieldsetOpen(sprintf($this->getLang('label: tagged with'), hsc($tag)));
        $form->addHTML($list);

        if ($count) {
            $form->addHTML($listDeleted);
            $form->addCheckbox('sync_delete', $this->getLang('label: sync delete'));
            $form->addHTML('<br>');

            $form->addTextInput('summary', $this->getLang('label: summary'))->attr('required', '1');
            $form->addButton('execute_transfer', 'Transfer files now');
        }
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

    /**
     * Returns the timestamp of last logged sync or 0 if no logs are found
     *
     * @param $clientDataDir
     * @return int
     */
    protected function getTimeOfLastTransfer($clientDataDir)
    {
        $since = 0;
        $logDir = $clientDataDir . '/pages/' . str_replace(':', '/', $this->getConf('client_log_namespace'));
        if (is_dir($logDir)) {
            $files = scandir($logDir, SCANDIR_SORT_DESCENDING);
            $files = array_diff($files, ['.', '..']);
            if (isset($files[0])) {
                $since = strtok($files[0], '.');
            }
        }
        return $since;
    }
}
