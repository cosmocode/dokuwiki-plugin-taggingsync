<?php
/**
 * DokuWiki Plugin taggingsync (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <dokuwiki@cosmocode.de>
 */

class admin_plugin_taggingsync_diff extends DokuWiki_Admin_Plugin
{

    protected $primaryPages;
    protected $clientPages;
    protected $combinedPages = [];

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
        return $this->getLang('menu') . ' ' . $this->getLang('menu_diff');
    }

    /** @inheritdoc */
    public function getMenuIcon()
    {
        return __DIR__ . '/../diff.svg';
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
        global $conf;

        /** @var helper_plugin_taggingsync $hlp */
        $hlp = plugin_load('helper', 'taggingsync');
        if (!$hlp->checkRequirements(false)) return;

        $clientDataPath = $this->getConf('client_wiki_directory');
        $clientDataPath = rtrim($clientDataPath, '/');
        $clientPagesPath = $clientDataPath . '/pages';
        $now = microtime(true);
        $clientPages = $this->collectPages($clientPagesPath);
        msg('Time collecting pages at client wiki: ' . round(microtime(true) - $now, 3));
        $now = microtime(true);
        $primaryPages = $this->collectPages($conf['datadir']);
        msg('Time collecting pages at primary wiki: ' . round(microtime(true) - $now, 3));
        $now = microtime(true);

        $combinedPages = array_unique(array_merge(array_keys($clientPages), array_keys($primaryPages)));
        $combinedPages = array_filter($combinedPages, function ($pageid) use ($primaryPages, $clientPages) {
            return empty($primaryPages[$pageid])
                || empty($clientPages[$pageid])
                || $primaryPages[$pageid]['mtime'] !== $clientPages[$pageid]['mtime'];
        });
        sort($combinedPages);
        $this->combinedPages = $combinedPages;

        $primaryPages = array_filter($primaryPages, function ($pageid) use ($combinedPages) {
            return in_array($pageid, $combinedPages);
        }, ARRAY_FILTER_USE_KEY);

        /** @var helper_plugin_tagging $tagging */
        $tagging = plugin_load('helper', 'tagging');
        $allTags = $tagging->getAllTagsByPage();
        $primaryPages = $this->hydratePrimaryPages($primaryPages, $allTags);
        msg('Time preparing pages for display: ' . round(microtime(true) - $now, 3));
        $this->primaryPages = $primaryPages;
        $this->clientPages = $clientPages;
    }

    /**
     * Add information about tags, title and media files to the $pages
     *
     * @param array[] $pages pageid as key, pagedata as value
     * @param string[] $allTags pageid as key, tags of that page as value (comma separated string)
     *
     * @return array[]
     */
    protected function hydratePrimaryPages($pages, $allTags)
    {
        foreach ($pages as $pid => &$pageData) {
            $pageData['tags'] = !empty($allTags[$pid]) ? $allTags[$pid] : [];
            $pageData['title'] = p_get_metadata(cleanID($pid), 'title');

            $pageMedia = p_get_metadata(cleanID($pid), 'relation')['media'];
            $pageData['media'] = array_keys($pageMedia !== null ? $pageMedia : []);
        }
        return $pages;
    }

    /**
     * Recursively find all pages in the given directory and collect basic data
     *
     * @param string $dir the directory on the filesystem
     * @param string $namespace the namespace associated with this directory
     *
     * @return array[] pageid as key, value contains mtime and filename
     */
    protected function collectPages($dir, $namespace = ':')
    {
        if ($this->isSkippedNS($namespace)) {
            return [];
        }

        $files = scandir($dir, SCANDIR_SORT_ASCENDING);
        $results = [];
        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) {
                continue;
            }
            $filePath = $dir . '/' . $file;
            if (is_dir($filePath)) {
                $results = array_merge($results, $this->collectPages($filePath, $namespace . $file . ':'));
                continue;
            }
            $pageName = substr($file, 0, -1 * strlen('.txt'));
            $results[cleanID($namespace . $pageName)] = [
                'mtime' => filemtime($filePath),
                'fn' => $filePath,
                // FIXME check is writable etc. here?
            ];
        }

        return $results;
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        ptln('<h1>' . $this->getLang('menu_diff') . '</h1>');

        /** @var helper_plugin_taggingsync $hlp */
        $hlp = plugin_load('helper', 'taggingsync');
        if (!$hlp->checkRequirements(true)) return;

        echo '<table>';
        echo '<tr>';
        echo '<th>' . $this->getLang('header: page') . '</th>';
        echo '<th>' . $this->getLang('header: tags') . '</th>';
        echo '<th>' . $this->getLang('header: last mod primary') . '</th>';
        echo '<th>' . $this->getLang('header: last mod client') . '</th>';
        echo '<th>' . $this->getLang('header: diff') . '</th>';
        echo '</tr>';
        foreach ($this->combinedPages as $pid) {
            $this->printRow($pid);
        }
        echo '</table>';
        echo '<div id="plugin__taggingsync_diff" style="display: none;">
                <div class="table"><table class="diff diff_sidebyside"></table></div>
              </div>';
    }

    /**
     * Print a rew in the table comparing pages at the primary and client wiki
     *
     * @param string $pid pageid
     */
    protected function printRow($pid)
    {
        echo '<tr>';
        $this->printTitleCell($pid);
        $this->printTagCell($pid);
        $this->printLastModCell($pid, $this->primaryPages);
        $this->printLastModCell($pid, $this->clientPages);
        $this->printDiffButtonCell($pid);
        echo '</tr>';
    }

    /**
     * Print the title cell for a page, including page title, pageid and number of media files
     *
     * @param string $pid
     */
    protected function printTitleCell($pid)
    {
        echo '<td>';
        echo '<strong>' . hsc($this->primaryPages[$pid]['title']) . '</strong>';
        echo '<br>';
        echo html_wikilink(':' . $pid, $pid);
        if (!empty($this->primaryPages[$pid]['media'])) {
            $mediaCount = count($this->primaryPages[$pid]['media']);
            $mediaList = implode(', ', $this->primaryPages[$pid]['media']);
            echo " <span title='$mediaList'>";
            echo '(' . sprintf($this->getLang('number of media files'), $mediaCount) . ')';
            echo '</span>';
        }
        echo '</td>';
    }

    /**
     * Print a cell containing the tags associated with a page
     *
     * @todo: this cell should somehow implement editing the tags
     *
     * @param string $pid
     */
    protected function printTagCell($pid)
    {
        // FIXME: implement tag editing!
        echo '<td>';
        if (!empty($this->primaryPages[$pid])) {
            echo hsc(implode(', ', $this->primaryPages[$pid]['tags']));
        }
        echo '</td>';
    }

    /**
     * Print a cell containing the last modified date of a page
     *
     * If a page doesn't exist in the array the cell is empty.
     *
     * This method can currently be used both for the primary and the client wiki
     *
     *
     * @param string $pid
     * @param array[] $pages an array of all available pages for this wiki, pageid as key, array with mtime key as value
     */
    protected function printLastModCell($pid, $pages)
    {
        echo '<td>';
        if (!empty($pages[$pid])) {
            echo '<time>' . dformat($pages[$pid]['mtime']) . '</time>';
        }
        echo '</td>';
    }

    /**
     * Print a cell containin the button to show the diff
     *
     * @param string $pid
     */
    protected function printDiffButtonCell($pid)
    {
        echo '<td>';
        echo '<button 
        class="button taggingsync_diff" 
        type="button" 
        data-pid="' . $pid . '"
        data-cfn="' . hsc($this->clientPages[$pid]['fn']) . '"
        >'
            . $this->getLang('button: diff')
            . 'diff</button>';
        echo '</td>';
    }

    /**
     * Skip the namespace where the logs are written because this one will always be out of sync
     *
     * @param string $namespace
     *
     * @return bool
     */
    protected function isSkippedNS($namespace)
    {
        $logNS = ':' . cleanID($this->getConf('client_log_namespace')) . ':';
        return $namespace === $logNS;
    }
}

