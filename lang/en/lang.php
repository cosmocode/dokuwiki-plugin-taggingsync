<?php
/**
 * English language file for taggingsync plugin
 *
 * @author Michael Große <dokuwiki@cosmocode.de>
 */

// menu entry for admin plugins
$lang['menu'] = 'TaggingSync:';
$lang['menu_diff'] = 'Wiki Differences';
$lang['menu_transfer'] = 'Transfer Tagged Changes';

// custom language strings for the plugin
$lang['header: page'] = 'Page';
$lang['header: tags'] = 'Tags';
$lang['header: last mod primary'] = 'Last modified in primary wiki';
$lang['header: last mod client'] = 'Last modified in client wiki';
$lang['header: diff'] = 'Diff';

$lang['number of media files'] = '%s media';

$lang['changelog prefix'] = 'export from primary wiki: ';

$lang['label: choose tag'] = 'Select Tag:';
$lang['label: summary'] = 'Changlog Summary:';
$lang['label: tagged with'] = 'Files tagged with %s';
$lang['label: no file'] = 'No files differ for this tag';

$lang['log: headline'] = 'Log of page update "%s"';
$lang['log: date'] = 'Date of export: %s';
$lang['log: page'] = 'The page [[%s]] was replaced by a new Version. Its meta information and changelog was also replaced.';
$lang['log: media'] = 'The media file {{%s?linkonly}} was replaced by a new Version. Its changelog was also replaced.';
$lang['log: media skipped'] = 'The media file {{%s?linkonly}} was skipped, because it already had been transferred.';
