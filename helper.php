<?php

/**
 * DokuWiki Plugin taggingsync (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <dokuwiki@cosmocode.de>
 */
class helper_plugin_taggingsync extends DokuWiki_Plugin
{

    /**
     * Check if all requirements for the plugin are met
     *
     * @param bool $msg should error messages be printed?
     * @return bool true if all requirements are met
     */
    public function checkRequirements($msg = true)
    {
        $clientDataPath = trim($this->getConf('client_wiki_directory'));
        if (empty($clientDataPath)) {
            $msg && msg('Please configure clientpath in configuration!', -1);
            return false;
        }

        $logSpace = trim($this->getConf('client_log_namespace'));
        if (empty($logSpace)) {
            msg('Please configure the namespace in the client wiki where the logs should be written!', -1);
            return false;
        }

        $clientDataPath = rtrim($clientDataPath, '/');
        $clientPagesPath = $clientDataPath . '/pages';

        if (!is_dir($clientPagesPath)) {
            $msg && msg('The configured client path seems not to be valid', -1);
            return false;
        }

        if (!is_writable($clientPagesPath)) {
            $msg && msg('Can\'t write to the configured client path', -1);
            return false;
        }

        $tagging = plugin_load('helper', 'tagging');
        if ($tagging === null) {
            $msg && msg('The tagging plugin is required but not installed', -1);
            return false;
        }
        return true;
    }
}
