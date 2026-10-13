<?php
if (!defined('ABSPATH')) { exit; }

/** bulk_upgrade removes source-selection filters, so extend its supported package checker. */
final class PICTS_Translation_Upgrader extends Language_Pack_Upgrader {
    public array $target = [];
    public array $manifest = [];
    public function check_package($source, $remote_source) {
        $source = parent::check_package($source, $remote_source);
        if (is_wp_error($source)) { return $source; }
        $files = scandir($source);
        if ($files === false || count($files) > 502) { return new WP_Error('picts_translation_package', 'The translation package could not be verified.'); }
        $manifest = []; $bytes = 0;
        foreach ($files as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $file = rtrim($source, '/') . '/' . $name;
            if (!PICTS_Component_Update::translation_filename($name, $this->target) || is_link($file) || !is_file($file) || ($bytes += (int) filesize($file)) > 50000000) { return new WP_Error('picts_translation_package', 'The translation package identity did not match.'); }
            $sha = hash_file('sha256', $file);
            if (!$sha) { return new WP_Error('picts_translation_package', 'The translation package could not be read.'); }
            $manifest[$name] = $sha;
        }
        if (!$manifest) { return new WP_Error('picts_translation_package', 'The translation package is empty.'); }
        ksort($manifest); $this->manifest = $manifest;
        return $source;
    }
}
