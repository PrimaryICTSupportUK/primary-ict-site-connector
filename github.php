<?php
if (!defined('ABSPATH')) { exit; }

/** Public, pinned GitHub packages only. No tokens or arbitrary endpoints. */
final class PICTS_GitHub_Source {
    public static function valid(array $source, string $id, string $version): bool {
        if (array_diff(array_keys($source), ['provider','repository','tag','asset','sha256']) || count($source) !== 5) { return false; }
        return ($source['provider'] ?? '') === 'github'
            && is_string($source['repository'] ?? null) && strlen($source['repository']) <= 200
            && preg_match('/^[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+$/D', $source['repository']) && !str_contains($source['repository'], '..')
            && is_string($source['tag'] ?? null) && preg_match('/^v?\d+\.\d+\.\d+$/D', $source['tag'])
            && ltrim($source['tag'], 'v') === $version
            && ($source['asset'] ?? '') === dirname($id) . '-' . $version . '.zip'
            && is_string($source['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $source['sha256']);
    }
    public static function package(array $source): string {
        return 'https://github.com/' . $source['repository'] . '/releases/download/' . $source['tag'] . '/' . $source['asset'];
    }
    public static function resolve(array $target): bool {
        $source = $target['source'] ?? [];
        if (!self::valid($source, $target['plugin_id'], $target['to_version'])) { return false; }
        $response = wp_safe_remote_get('https://api.github.com/repos/' . $source['repository'] . '/releases/tags/' . $source['tag'], [
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 200000,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Primary-ICT-Support-WordPress-Manager'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return false; }
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || ($release['tag_name'] ?? '') !== $source['tag'] || ($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false || empty($release['published_at']) || !is_array($release['assets'] ?? null) || count($release['assets']) > 100) { return false; }
        foreach (($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === $source['asset'] && ($asset['browser_download_url'] ?? '') === self::package($source) && ($asset['state'] ?? '') === 'uploaded' && ($asset['size'] ?? 0) > 0 && ($asset['size'] ?? 0) <= 32 * 1024 * 1024 && ($asset['digest'] ?? '') === 'sha256:' . $source['sha256']) { return true; }
        }
        return false;
    }
    /** Inspect archive paths and the exact plugin header without loading its code. */
    public static function inspect(string $file, array $target): bool {
        if (!class_exists('ZipArchive') || filesize($file) > 32 * 1024 * 1024) { return false; }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) { return false; }
        try {
            if ($zip->numFiles > 5000) { return false; }
            $bytes = 0; $prefix = dirname($target['plugin_id']) . '/';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (!$entry || !str_starts_with($entry['name'], $prefix) || str_contains($entry['name'], '..') || str_contains($entry['name'], '\\') || str_contains($entry['name'], "\0")) { return false; }
                $bytes += $entry['size']; if ($bytes > 128 * 1024 * 1024) { return false; }
                if ($zip->getExternalAttributesIndex($index, $system, $attributes) && (($attributes >> 16) & 0170000) === 0120000) { return false; }
            }
            $entry = $zip->statName($target['plugin_id']);
            if (!$entry || $entry['size'] > 256000) { return false; }
            $main = $zip->getFromName($target['plugin_id']);
            return is_string($main) && preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $main, $version) && trim($version[1]) === $target['to_version'];
        } finally { $zip->close(); }
    }
    public static function download($reply, string $package, array $hook_extra, array $target) {
        if (($hook_extra['plugin'] ?? '') !== $target['plugin_id']) { return $reply; }
        if ($reply !== false || $package !== self::package($target['source'])) { return new WP_Error('picts_package_changed', 'The selected package was changed or intercepted. Installation stopped.'); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url($package, 60);
        if (is_wp_error($file)) { return $file; }
        $hash = hash_file('sha256', $file);
        if (!$hash || !hash_equals($target['source']['sha256'], $hash) || !self::inspect($file, $target)) {
            wp_delete_file($file);
            return new WP_Error('picts_package_invalid', 'Package checksum, folder or plugin version validation failed. Installation stopped.');
        }
        return $file;
    }
}
