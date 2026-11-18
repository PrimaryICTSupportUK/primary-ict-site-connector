<?php
if (!defined('ABSPATH')) { exit; }

/** The service supplies administrator-approved hosts; WordPress retains all package credentials. */
final class PICTS_Native_Source {
    private const MAX_PACKAGE_BYTES = 32 * 1024 * 1024;
    public static function valid(array $source): bool {
        if (array_diff(array_keys($source), ['provider','approved_hosts']) || ($source['provider'] ?? '') !== 'native_vendor' || !is_array($source['approved_hosts'] ?? null) || count($source['approved_hosts']) < 1 || count($source['approved_hosts']) > 50) { return false; }
        foreach ($source['approved_hosts'] as $host) {
            if (!is_string($host) || strlen($host) > 253 || $host !== strtolower($host) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) || $host === 'localhost' || str_ends_with($host, '.local')) { return false; }
        }
        return true;
    }
    public static function allows(string $url, array $source): bool {
        $p = wp_parse_url($url);
        return self::valid($source) && $p && ($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '', $source['approved_hosts'], true) && empty($p['user']) && empty($p['pass']) && empty($p['port']) && empty($p['fragment']);
    }
    public static function download($reply, string $package, array $extra, array $target) {
        $theme = ($target['kind'] ?? '') === 'theme';
        $id = $theme ? $target['theme_id'] : $target['plugin_id'];
        if (($extra[$theme ? 'theme' : 'plugin'] ?? '') !== $id) { return $reply; }
        $cache = get_site_transient($theme ? 'update_themes' : 'update_plugins');
        $candidate = $cache->response[$id] ?? null;
        $expected = $theme ? (string) (((array) $candidate)['package'] ?? '') : (string) ($candidate->package ?? '');
        if ($reply !== false) { return new WP_Error('picts_package_intercepted', 'Another updater intercepted this package.'); }
        if ($package !== $expected) { return new WP_Error('picts_package_changed', 'The approved native package changed.'); }
        if (!self::allows($package, $target['native_source'])) { return new WP_Error('picts_package_host_unapproved', 'The package host is not approved.'); }
        // Safe WordPress HTTP rejects private destinations; no redirect may escape the exact approved host.
        $http = static function ($args, $url) use ($package) { if ($url === $package) { $args['redirection']=0; $args['sslverify']=true; $args['reject_unsafe_urls']=true; $args['limit_response_size']=self::MAX_PACKAGE_BYTES + 1; } return $args; };
        add_filter('http_request_args', $http, PHP_INT_MAX, 2);
        try { $file = download_url($package, 60); }
        finally { remove_filter('http_request_args', $http, PHP_INT_MAX); }
        if (is_wp_error($file)) {
            // Only a validated status class crosses the boundary; WP_Error data
            // may include vendor response bodies, package URLs or credentials.
            $data = $file->get_error_code() === 'http_404' ? $file->get_error_data() : null;
            $status = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : 0;
            $code = $status >= 300 && $status <= 399 ? 'picts_package_redirect' : (in_array($status, [401,403], true) ? 'picts_package_denied' : ($status === 404 ? 'picts_package_not_found' : 'picts_package_download'));
            return new WP_Error($code, 'The approved native package could not be downloaded.');
        }
        // The extra byte distinguishes a response truncated by the HTTP cap from an allowed archive.
        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size !== false && $size > self::MAX_PACKAGE_BYTES) { wp_delete_file($file); return new WP_Error('picts_package_too_large', 'The native package exceeds the size limit.'); }
        if ($size === false || !PICTS_GitHub_Source::inspect($file, $target)) { wp_delete_file($file); return new WP_Error('picts_package_invalid', 'The native package identity did not match.'); }
        return $file;
    }
}
