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

    /**
     * Get the full path name for the file behind the given ID on the client wiki
     *
     * @param string $id the ID to access
     * @param string $type can be 'page', 'media', 'meta', 'changelog', 'mediachangelog'
     * @return string
     */
    public function clientFileForID($id, $type = 'page')
    {
        $file = utf8_encodeFN(str_replace(':', '/', $id));
        $client = trim($this->getConf('client_wiki_directory'));

        switch ($type) {
            case 'page':
                return "$client/pages/$file.txt";
            case 'media':
                return "$client/media/$file";
            case 'meta':
                return "$client/meta/$file.meta";
            case 'changelog':
                return "$client/meta/$file.changes";
            case 'mediachangelog':
                return "$client/media_meta/$file.changes";
            case 'header':
                return "$client/header/$file.txt";
            default:
                throw new \RuntimeException('Unknown type given');

        }
    }

    /**
     * Check if two files have the same content
     *
     * @param string $one path to first file
     * @param string $two path to second file
     * @return bool
     */
    public function filesEqual($one, $two)
    {
        $md5_1 = '';
        $md5_2 = '';

        if (file_exists($one)) $md5_1 = md5(file_get_contents($one));
        if (file_exists($two)) $md5_2 = md5(file_get_contents($two));

        return $md5_1 === $md5_2;
    }
}
