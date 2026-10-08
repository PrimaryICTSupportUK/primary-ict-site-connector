<?php
if (!defined('ABSPATH')) { exit; }

/** A single exact plugin target. Package locations stay inside WordPress. */
final class PICTS_Plugin_Update {
    private static ?string $request_id = null;
    private static function request_id(): string { return self::$request_id ??= bin2hex(random_bytes(16)); }
    public static function valid_target(array $target): bool {
        return is_string($target['plugin_id'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]+\/[A-Za-z0-9_.-]+\.php$/D', $target['plugin_id'])
            && !str_contains($target['plugin_id'], '..')
            && $target['plugin_id'] !== 'primary-ict-site-connector/primary-ict-site-connector.php'
            && (!isset($target['source']) || (is_array($target['source']) && PICTS_GitHub_Source::valid($target['source'], $target['plugin_id'], (string) ($target['to_version'] ?? ''))))
            && is_string($target['from_version'] ?? null) && strlen($target['from_version']) > 0 && strlen($target['from_version']) <= 80
            && is_string($target['to_version'] ?? null) && strlen($target['to_version']) > 0 && strlen($target['to_version']) <= 80
            && $target['from_version'] !== $target['to_version'] && is_bool($target['active'] ?? null);
    }
    public static function initial(array $target): array {
        return ['plugin_id' => $target['plugin_id'], 'from_version' => $target['from_version'], 'to_version' => $target['to_version'], 'observed_version' => null, 'active_before' => $target['active'], 'active_after' => null, 'outcome' => 'pending', 'reason' => 'pending', 'new_request_verified' => false];
    }
    public static function stop(array &$pending, string $reason, string $outcome = 'blocked'): void {
        $pending['report']['update']['reason'] = $reason;
        $pending['report']['update']['outcome'] = $outcome;
        $pending['report']['failure'] = $outcome === 'uncertain' ? 'update_uncertain' : ($outcome === 'blocked' ? 'update_blocked' : 'update_failed');
        $pending['stage'] = 'diagnostics';
    }
    public static function guard(array $target): ?string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        if (!self::valid_target($target) || is_multisite()) { return 'unsupported_site'; }
        if (!wp_is_file_mod_allowed('picts_plugin_update')) { return 'file_mods_disabled'; }
        wp_clean_plugins_cache(false);
        $plugins = get_plugins(); $id = $target['plugin_id']; $plugin = $plugins[$id] ?? null;
        $updates = get_site_transient('update_plugins');
        $candidate = $updates->response[$id] ?? null;
        if (!$plugin || !$candidate || (string) ($plugin['Version'] ?? '') !== $target['from_version'] || (string) ($candidate->new_version ?? '') !== $target['to_version'] || is_plugin_active($id) !== $target['active'] || (!empty($candidate->plugin) && $candidate->plugin !== $id)) { return 'candidate_changed'; }
        $uri = (string) ($plugin['UpdateURI'] ?? '');
        if (isset($target['source'])) {
            if ($uri !== 'https://github.com/' . $target['source']['repository']) { return 'unsupported_site'; }
            if (!PICTS_GitHub_Source::resolve($target)) { return 'provider_check_failed'; }
            if (($candidate->package ?? '') !== PICTS_GitHub_Source::package($target['source'])) { return 'candidate_changed'; }
        } else {
        if ($uri !== '' && wp_parse_url($uri, PHP_URL_HOST) !== 'wordpress.org') { return 'unsupported_site'; }
        $package = wp_parse_url((string) ($candidate->package ?? ''));
        $slug = (string) ($candidate->slug ?? '');
        if (!preg_match('/^[a-z0-9-]+$/D', $slug) || !$package || ($package['scheme'] ?? '') !== 'https' || ($package['host'] ?? '') !== 'downloads.wordpress.org' || !empty($package['user']) || !empty($package['pass']) || !empty($package['port']) || !empty($package['query']) || !empty($package['fragment']) || !preg_match('/^\/plugin\/' . preg_quote($slug, '/') . '\.[A-Za-z0-9_.-]+\.zip$/D', $package['path'] ?? '')) { return 'unsupported_site'; }
        }
        if (!is_wp_version_compatible((string) ($candidate->requires ?? '')) || !is_php_version_compatible((string) ($candidate->requires_php ?? ''))) { return 'incompatible'; }
        if (get_filesystem_method([], WP_PLUGIN_DIR) !== 'direct' || !WP_Filesystem([], WP_PLUGIN_DIR)) { return 'filesystem_unavailable'; }
        return null;
    }
    /** The permission request is single-use; a lost reply can never repeat installation. */
    public static function execute(array &$pending, callable $request): void {
        if (!wp_doing_cron()) { return; } // WordPress preserves active plugins during background upgrades.
        if (!empty($pending['grant_attempted'])) { self::stop($pending, 'interrupted', 'uncertain'); return; }
        $reason = self::guard($pending['job']['target']);
        if ($reason) { self::stop($pending, $reason); return; }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (!WP_Upgrader::create_lock('auto_updater')) { self::stop($pending, 'updater_busy'); return; }
        $target = $pending['job']['target'];
        $download = isset($target['source']) ? static fn ($reply, $package, $upgrader, $extra) => PICTS_GitHub_Source::download($reply, $package, $extra, $target) : null;
        if ($download) { add_filter('upgrader_pre_download', $download, PHP_INT_MAX, 4); }
        $translations = ['Language_Pack_Upgrader', 'async_upgrade'];
        $priority = has_action('upgrader_process_complete', $translations);
        if ($priority !== false) { remove_action('upgrader_process_complete', $translations, $priority); }
        try {
            $pending['grant_attempted'] = true;
            // Persist before any network or filesystem mutation. Never grant/repeat on resume.
            if (!update_option('picts_connector_pending_job', $pending, false)) { self::stop($pending, 'worker_error'); return; }
            $grant = $request(['operation' => 'begin_update', 'job_id' => $pending['job']['id'], 'lease_token' => $pending['lease']]);
            if ($grant['code'] !== 200 || empty($grant['data']['permitted']) || ($grant['data']['target'] ?? null) !== $pending['job']['target']) {
                self::stop($pending, $grant['code'] === 0 || $grant['code'] >= 500 || $grant['code'] === 200 ? 'grant_uncertain' : 'grant_rejected', $grant['code'] === 0 || $grant['code'] >= 500 || $grant['code'] === 200 ? 'uncertain' : 'blocked'); return;
            }
            // Recheck local state immediately after permission, before invoking the supported upgrader.
            $reason = self::guard($pending['job']['target']);
            if ($reason) { self::stop($pending, $reason); return; }
            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->upgrade($pending['job']['target']['plugin_id'], ['clear_update_cache' => true]);
            if ($result !== true || is_wp_error($result)) { self::stop($pending, 'upgrader_failed', 'failed'); return; }
            $pending['executed_request'] = self::request_id();
            $pending['stage'] = 'verify'; // The next invocation boots WordPress with the new files.
        } finally {
            if ($download) { remove_filter('upgrader_pre_download', $download, PHP_INT_MAX); }
            if ($priority !== false) { add_action('upgrader_process_complete', $translations, $priority, 2); }
            WP_Upgrader::release_lock('auto_updater');
        }
    }
    public static function verify(array &$pending): void {
        if (($pending['executed_request'] ?? null) === self::request_id()) { return; }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        wp_clean_plugins_cache(false);
        $target = $pending['job']['target']; $id = $target['plugin_id']; $plugins = get_plugins();
        $observed = $plugins[$id]['Version'] ?? null;
        $active = isset($plugins[$id]) ? is_plugin_active($id) : null;
        $pending['report']['update']['observed_version'] = is_string($observed) ? substr($observed, 0, 80) : null;
        $pending['report']['update']['active_after'] = $active;
        if ($observed !== $target['to_version']) { self::stop($pending, 'version_mismatch', 'failed'); return; }
        if ($active !== $target['active']) { self::stop($pending, 'activation_changed', 'failed'); return; }
        $path = realpath(WP_PLUGIN_DIR . '/' . $id);
        $loaded = !$target['active'] || ($path && in_array($path, array_map('realpath', get_included_files()), true));
        if (!$loaded || !did_action('plugins_loaded')) { self::stop($pending, 'activation_changed', 'failed'); return; }
        $pending['report']['update']['new_request_verified'] = true;
        $pending['report']['update']['outcome'] = 'updated';
        $pending['report']['update']['reason'] = 'verified';
        $pending['stage'] = 'diagnostics';
    }
}
