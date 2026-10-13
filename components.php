<?php
if (!defined('ABSPATH')) { exit; }

/** Exact, service-resolved component targets. Never accept a caller's package location. */
final class PICTS_Component_Update {
    private static ?string $request_id = null;
    private static function request_id(): string { return self::$request_id ??= bin2hex(random_bytes(16)); }
    private static function token($value, int $max = 80): bool { return is_string($value) && strlen($value) > 0 && strlen($value) <= $max && (bool) preg_match('/^[A-Za-z0-9_.-]+$/D', $value) && !str_contains($value, '..'); }
    public static function valid_target(array $t): bool {
        if (!in_array($t['kind'] ?? '', ['theme','core','translation'], true) || !self::token($t['from_version'] ?? null) || !self::token($t['to_version'] ?? null) || isset($t['package']) || isset($t['source'])) { return false; }
        if ($t['kind'] !== 'translation' && !version_compare($t['to_version'], $t['from_version'], '>')) { return false; }
        if ($t['kind'] === 'theme') { return self::token($t['theme_id'] ?? null) && ($t['component_id'] ?? null) === $t['theme_id'] && self::token($t['stylesheet'] ?? null) && self::token($t['template'] ?? null) && is_bool($t['active'] ?? null) && $t['active'] === ($t['theme_id'] === $t['stylesheet']); }
        if ($t['kind'] === 'core') { return ($t['component_id'] ?? '') === 'wordpress' && self::token($t['locale'] ?? null) && is_bool($t['allow_major'] ?? null) && (bool) preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $t['to_version']); }
        return in_array($t['translation_type'] ?? '', ['core','plugin','theme'], true) && self::token($t['slug'] ?? null) && self::token($t['language'] ?? null) && self::token($t['package_version'] ?? null) && $t['to_version'] === $t['package_version'] && is_string($t['updated'] ?? null) && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $t['updated']) && ($t['component_id'] ?? '') === $t['translation_type'] . ':' . $t['slug'] . ':' . $t['language'];
    }
    public static function initial(array $t): array {
        $report = ['kind'=>$t['kind'], 'component_id'=>$t['component_id'], 'from_version'=>$t['from_version'], 'to_version'=>$t['to_version'], 'observed_version'=>null, 'active_before'=>$t['active'] ?? false, 'active_after'=>null, 'outcome'=>'pending', 'reason'=>'pending', 'new_request_verified'=>false];
        if ($t['kind'] === 'theme') { $report += ['stylesheet_before'=>$t['stylesheet'],'template_before'=>$t['template'],'stylesheet_after'=>null,'template_after'=>null]; }
        if ($t['kind'] === 'core') { $report += ['observed_db_version'=>null,'expected_db_version'=>null]; }
        if ($t['kind'] === 'translation') { $report += ['translation_type'=>$t['translation_type'],'slug'=>$t['slug'],'language'=>$t['language'],'package_version'=>$t['package_version'],'updated'=>$t['updated'],'manifest_sha256'=>null,'verified_file_count'=>0]; }
        return $report;
    }
    public static function stop(array &$p, string $reason, string $outcome = 'blocked'): void { PICTS_Plugin_Update::stop($p, $reason, $outcome); }
    public static function candidate(array $t): ?object {
        if ($t['kind'] === 'theme') { $cache = get_site_transient('update_themes'); return isset($cache->response[$t['theme_id']]) ? (object) $cache->response[$t['theme_id']] : null; }
        if ($t['kind'] === 'core') {
            $cache = get_site_transient('update_core');
            foreach ((array) ($cache->updates ?? []) as $c) { if (($c->response ?? '') === 'upgrade' && ($c->current ?? '') === $t['to_version'] && ($c->locale ?? 'en_US') === $t['locale']) { return $c; } }
        } else {
            foreach (wp_get_translation_updates() as $c) { if (($c->type ?? '') === $t['translation_type'] && ($c->slug ?? 'default') === $t['slug'] && ($c->language ?? '') === $t['language'] && (string) ($c->version ?? '') === $t['package_version'] && ($c->updated ?? '') === $t['updated']) { return $c; } }
        }
        return null;
    }
    public static function official_package(string $url, string $path): bool {
        $p = wp_parse_url($url);
        return $p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'downloads.wordpress.org' && empty($p['user']) && empty($p['pass']) && empty($p['port']) && empty($p['query']) && empty($p['fragment']) && ($p['path'] ?? '') === $path;
    }
    private static function core_package(string $url, string $version): bool {
        $p = wp_parse_url($url);
        return $p && ($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '', ['downloads.wordpress.org','wordpress.org'], true) && empty($p['user']) && empty($p['pass']) && empty($p['port']) && empty($p['query']) && empty($p['fragment']) && (bool) preg_match('~^/(?:release/(?:[A-Za-z_]+/)?)?wordpress-' . preg_quote($version, '~') . '(?:-[A-Za-z0-9_.-]+)?\.zip$~D', $p['path'] ?? '');
    }
    public static function guard(array $t): ?string {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        if (!self::valid_target($t) || is_multisite()) { return 'unsupported_site'; }
        if (!wp_is_file_mod_allowed('picts_component_update')) { return 'file_mods_disabled'; }
        $c = self::candidate($t);
        if (!$c) { return 'candidate_changed'; }
        $root = ABSPATH;
        if ($t['kind'] === 'theme') {
            wp_clean_themes_cache(false); $theme = wp_get_theme($t['theme_id']); $root = get_theme_root($t['theme_id']);
            if (!$theme->exists() || $theme->errors() || $theme->get('Version') !== $t['from_version'] || (string) ($c->new_version ?? '') !== $t['to_version'] || get_stylesheet() !== $t['stylesheet'] || get_template() !== $t['template']) { return 'candidate_changed'; }
            $uri = (string) $theme->get('UpdateURI');
            if (($uri !== '' && wp_parse_url($uri, PHP_URL_HOST) !== 'wordpress.org') || !self::official_package((string) ($c->package ?? ''), '/theme/' . $t['theme_id'] . '.' . $t['to_version'] . '.zip')) { return 'unsupported_site'; }
            if (!is_wp_version_compatible((string) ($c->requires ?? '')) || !is_php_version_compatible((string) ($c->requires_php ?? ''))) { return 'incompatible'; }
        } elseif ($t['kind'] === 'core') {
            if (get_bloginfo('version') !== $t['from_version']) { return 'candidate_changed'; }
            if (implode('.', array_slice(explode('.', $t['from_version']), 0, 2)) !== implode('.', array_slice(explode('.', $t['to_version']), 0, 2)) && !$t['allow_major']) { return 'major_not_allowed'; }
            if (!is_php_version_compatible((string) ($c->php_version ?? ''))) { return 'incompatible'; }
            $packages = (array) ($c->packages ?? []);
            if (empty($packages['full'])) { return 'unsupported_site'; }
            foreach ($packages as $key => $url) { if ($url && $key !== 'rollback' && (!is_string($url) || !self::core_package($url, $t['to_version']))) { return 'unsupported_site'; } }
        } else {
            $root = is_dir(WP_LANG_DIR) ? WP_LANG_DIR : WP_CONTENT_DIR;
            if (!self::official_package((string) ($c->package ?? ''), '/translation/' . $t['translation_type'] . '/' . ($t['translation_type'] === 'core' ? '' : $t['slug'] . '/') . $t['package_version'] . '/' . $t['language'] . '.zip')) { return 'unsupported_site'; }
        }
        if (get_filesystem_method([], $root) !== 'direct' || !WP_Filesystem([], $root)) { return 'filesystem_unavailable'; }
        return null;
    }
    /** Persist grant intent before requesting it; never repeat after an ambiguous or interrupted response. */
    public static function execute(array &$p, callable $request): void {
        if (!wp_doing_cron()) { return; }
        if (!empty($p['grant_attempted'])) { self::stop($p, 'interrupted', 'uncertain'); return; }
        $t = $p['job']['target']; $reason = self::guard($t);
        if ($reason) { self::stop($p, $reason); return; }
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (!WP_Upgrader::create_lock('auto_updater')) { self::stop($p, 'updater_busy'); return; }
        $translations = ['Language_Pack_Upgrader','async_upgrade']; $priority = has_action('upgrader_process_complete', $translations);
        if ($priority !== false) { remove_action('upgrader_process_complete', $translations, $priority); }
        $http = null;
        try {
            $p['grant_attempted'] = true;
            if (!update_option('picts_connector_pending_job', $p, false)) { self::stop($p, 'worker_error'); return; }
            $grant = $request(['operation'=>'begin_update','job_id'=>$p['job']['id'],'lease_token'=>$p['lease']]);
            if ($grant['code'] !== 200 || empty($grant['data']['permitted']) || ($grant['data']['target'] ?? null) !== $t) {
                $uncertain = $grant['code'] === 0 || $grant['code'] >= 500 || $grant['code'] === 200;
                self::stop($p, $uncertain ? 'grant_uncertain' : 'grant_rejected', $uncertain ? 'uncertain' : 'blocked'); return;
            }
            $reason = self::guard($t); if ($reason) { self::stop($p, $reason); return; }
            $c = self::candidate($t);
            $packages = $t['kind'] === 'core' ? array_values((array) $c->packages) : [$c->package];
            $http = static function ($args, $url) use ($packages) { if (in_array($url, $packages, true)) { $args['redirection']=0; $args['sslverify']=true; $args['reject_unsafe_urls']=true; } return $args; };
            add_filter('http_request_args', $http, PHP_INT_MAX, 2);
            $skin = new Automatic_Upgrader_Skin();
            if ($t['kind'] === 'theme') { $result = (new Theme_Upgrader($skin))->upgrade($t['theme_id'], ['clear_update_cache'=>true]); $ok = $result === true; }
            elseif ($t['kind'] === 'core') { $result = (new Core_Upgrader($skin))->upgrade($c, ['attempt_rollback'=>false]); $ok = is_string($result) && $result === $t['to_version']; }
            else {
                require_once __DIR__ . '/translation-upgrader.php';
                $upgrader = new PICTS_Translation_Upgrader($skin); $upgrader->target = $t;
                $result = $upgrader->bulk_upgrade([$c], ['clear_update_cache'=>true]);
                $ok = is_array($result) && count($result) === 1 && is_array($result[0]) && !empty($upgrader->manifest);
                if ($ok) { $p['translation_manifest'] = $upgrader->manifest; }
            }
            if (!$ok || is_wp_error($result)) { self::stop($p, 'upgrader_failed', 'failed'); return; }
            $p['executed_request'] = self::request_id(); $p['stage'] = 'verify';
            // If this persistence is interrupted, the earlier grant_attempted marker refuses another install.
            if (!update_option('picts_connector_pending_job', $p, false)) { self::stop($p, 'interrupted', 'uncertain'); }
        } finally {
            if ($http) { remove_filter('http_request_args', $http, PHP_INT_MAX); }
            if ($priority !== false) { add_action('upgrader_process_complete', $translations, $priority, 2); }
            WP_Upgrader::release_lock('auto_updater');
        }
    }
    public static function verify(array &$p): void {
        if (empty($p['executed_request']) || empty($p['grant_attempted'])) { self::stop($p, 'interrupted', 'uncertain'); return; }
        if ($p['executed_request'] === self::request_id()) { return; }
        $t = $p['job']['target']; $r =& $p['report']['update'];
        if ($t['kind'] === 'theme') {
            wp_clean_themes_cache(false); $theme = wp_get_theme($t['theme_id']); $r['observed_version'] = $theme->exists() ? (string) $theme->get('Version') : null;
            $r['stylesheet_after'] = get_stylesheet(); $r['template_after'] = get_template(); $r['active_after'] = get_stylesheet() === $t['theme_id'];
            if ($theme->errors() || $r['observed_version'] !== $t['to_version']) { self::stop($p,'version_mismatch','failed'); return; }
            if ($r['stylesheet_after'] !== $t['stylesheet'] || $r['template_after'] !== $t['template']) { self::stop($p,'theme_selection_changed','failed'); return; }
        } elseif ($t['kind'] === 'core') {
            global $wp_db_version;
            $r['observed_version'] = get_bloginfo('version'); $r['observed_db_version'] = (int) get_option('db_version'); $r['expected_db_version'] = (int) $wp_db_version; $r['active_after'] = false;
            if ($r['observed_version'] !== $t['to_version']) { self::stop($p,'version_mismatch','failed'); return; }
            if ($r['expected_db_version'] <= 0 || $r['observed_db_version'] !== $r['expected_db_version']) { self::stop($p,'database_version_mismatch','failed'); return; }
        } else {
            $manifest = $p['translation_manifest'] ?? []; $base = WP_LANG_DIR . ($t['translation_type'] === 'core' ? '' : '/' . $t['translation_type'] . 's');
            if (!$manifest || count($manifest) > 500) { self::stop($p,'translation_content_mismatch','failed'); return; }
            foreach ($manifest as $name => $sha) {
                $file = $base . '/' . $name;
                if (!self::translation_filename($name, $t) || is_link($file) || !is_file($file) || !hash_equals($sha, (string) hash_file('sha256', $file))) { self::stop($p,'translation_content_mismatch','failed'); return; }
            }
            $r['manifest_sha256'] = hash('sha256', (string) json_encode($manifest)); $r['verified_file_count'] = count($manifest); $r['observed_version'] = $t['package_version']; $r['active_after'] = false;
        }
        $r['new_request_verified'] = true; $r['outcome'] = 'updated'; $r['reason'] = 'verified'; $p['stage'] = 'diagnostics';
    }
    public static function translation_filename(string $name, array $t): bool {
        $prefix = $t['translation_type'] === 'core' ? '(?:(?:admin|admin-network|continents-cities)-)?' : preg_quote($t['slug'], '~') . '-';
        return (bool) preg_match('~^' . $prefix . preg_quote($t['language'], '~') . '(?:\.(?:po|mo|l10n\.php)|-[a-f0-9]{32}\.json)$~D', $name);
    }
}
