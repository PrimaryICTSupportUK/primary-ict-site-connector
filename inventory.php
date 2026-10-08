<?php
if (!defined('ABSPATH')) { exit; }

/** Curated metadata only: no options dump, credentials, package URLs or file contents. */
final class PICTS_Inventory {
    private static function text($value, int $length = 150): string {
        return wp_html_excerpt(wp_strip_all_tags((string) $value, true), $length, '');
    }
    private static function optional($value): ?string {
        $value = self::text($value, 40);
        return $value === '' ? null : $value;
    }
    private static function link($value): ?string {
        $parts = wp_parse_url((string) $value);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port'])) { return null; }
        // Public header reference only; discard query strings and fragments.
        $url = esc_url_raw($parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? ''));
        return strlen($url) <= 500 ? $url : null;
    }
    private static function provider(array $update, string $update_uri): string {
        foreach ([$update['id'] ?? '', $update['url'] ?? '', $update_uri] as $url) {
            $host = strtolower((string) (wp_parse_url((string) $url, PHP_URL_HOST) ?? ''));
            if ($host === 'wordpress.org' || str_ends_with($host, '.wordpress.org')) { return 'wordpress_org'; }
        }
        return $update_uri !== '' || $update ? 'custom' : 'unknown';
    }
    private static function component(string $kind, string $id, array $data, array $response, array $no_update, array $auto): array {
        $special = in_array($kind, ['mu_plugin', 'dropin'], true);
        $available = !$special && !empty($response['new_version']);
        $known = $available || isset($no_update[$id]);
        $reported = $available ? $response : (array) ($no_update[$id] ?? []);
        $dependencies = array_values(array_filter(array_map('trim', explode(',', (string) ($data['RequiresPlugins'] ?? '')))));
        return [
            'kind' => $kind, 'id' => $id, 'name' => self::text($data['Name'] ?? $id) ?: self::text($id),
            'version' => self::text($data['Version'] ?? '', 40), 'author' => self::text($data['Author'] ?? ''),
            'description' => self::text($data['Description'] ?? '', 500), 'url' => self::link($data['PluginURI'] ?? $data['ThemeURI'] ?? ''),
            'active' => $kind === 'plugin' ? is_plugin_active($id) : ($kind === 'mu_plugin' ? true : null),
            'network_active' => $kind === 'plugin' && is_multisite() ? is_plugin_active_for_network($id) : null,
            'parent' => null, 'requires_wp' => self::optional($data['RequiresWP'] ?? ''), 'requires_php' => self::optional($data['RequiresPHP'] ?? ''),
            'tested_wp' => self::optional($reported['tested'] ?? ''), 'dependencies' => array_map(static fn ($value) => self::text($value, 100), $dependencies),
            'errors' => [], 'native_auto_update' => $special ? 'not_supported' : (in_array($id, $auto, true) ? 'enabled' : 'disabled'),
            'update_status' => $available ? 'available' : ($known ? 'none_reported' : 'unknown'),
            'target_version' => $available ? self::optional($response['new_version']) : null,
            'provider' => $special ? 'not_supported' : self::provider($reported, (string) ($data['UpdateURI'] ?? '')),
        ];
    }
    public static function collect(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $components = [];
        $updates = [];
        $plugin_check = get_site_transient('update_plugins');
        $theme_check = get_site_transient('update_themes');
        $core_check = get_site_transient('update_core');
        $plugin_responses = (array) ($plugin_check->response ?? []);
        $plugin_no_updates = (array) ($plugin_check->no_update ?? []);
        $plugin_auto = (array) get_site_option('auto_update_plugins', []);
        foreach (['plugin' => get_plugins(), 'mu_plugin' => get_mu_plugins(), 'dropin' => get_dropins()] as $kind => $plugins) {
            foreach ($plugins as $file => $data) {
                $component = self::component($kind, $file, $data, (array) ($plugin_responses[$file] ?? []), $kind === 'plugin' ? $plugin_no_updates : [], $plugin_auto);
                $components[] = $component;
                if ($component['update_status'] === 'available') {
                    $updates[] = ['slug' => $file, 'name' => $component['name'], 'type' => 'plugin', 'current_version' => $component['version'], 'target_version' => $component['target_version']];
                }
            }
        }
        $theme_responses = (array) ($theme_check->response ?? []);
        $theme_no_updates = (array) ($theme_check->no_update ?? []);
        $theme_auto = (array) get_site_option('auto_update_themes', []);
        foreach (wp_get_themes(['errors' => null, 'allowed' => null]) as $slug => $theme) {
            $data = [];
            foreach (['Name', 'Version', 'Author', 'Description', 'ThemeURI', 'RequiresWP', 'RequiresPHP', 'UpdateURI'] as $key) { $data[$key] = $theme->get($key); }
            $component = self::component('theme', $slug, $data, (array) ($theme_responses[$slug] ?? []), $theme_no_updates, $theme_auto);
            $component['active'] = get_stylesheet() === $slug;
            $parent = (string) $theme->get('Template');
            $component['parent'] = $parent !== '' ? $parent : null;
            $errors = $theme->errors();
            // Codes identify errors without transmitting paths or raw diagnostic messages.
            $component['errors'] = is_wp_error($errors) ? array_map(static fn ($code) => self::text($code, 200), $errors->get_error_codes()) : [];
            $components[] = $component;
            if ($component['update_status'] === 'available') {
                $updates[] = ['slug' => $slug, 'name' => $component['name'], 'type' => 'theme', 'current_version' => $component['version'], 'target_version' => $component['target_version']];
            }
        }
        foreach ((array) ($core_check->updates ?? []) as $update) {
            if (($update->response ?? '') === 'upgrade' && !empty($update->current)) {
                $updates[] = ['slug' => 'wordpress', 'name' => 'WordPress core', 'type' => 'core', 'current_version' => get_bloginfo('version'), 'target_version' => self::text($update->current, 40)];
                break;
            }
        }
        foreach (wp_get_translation_updates() as $translation) {
            $type = (string) ($translation->type ?? '');
            $slug = (string) ($translation->slug ?? 'default');
            $language = (string) ($translation->language ?? '');
            // A translation package date/version is not a plugin or core version.
            $updates[] = ['slug' => self::text($type . ':' . $slug . ':' . $language, 150), 'name' => self::text(ucfirst($type) . ' translation: ' . $slug . ' (' . $language . ')'), 'type' => 'translation', 'current_version' => '', 'target_version' => self::text($translation->version ?? '', 40)];
        }
        $checks = [];
        foreach (['core' => $core_check, 'plugin' => $plugin_check, 'theme' => $theme_check] as $category => $check) {
            $checked = (int) ($check->last_checked ?? 0);
            $checks[] = ['category' => $category, 'last_checked_at' => $checked > 0 ? gmdate('Y-m-d\TH:i:s\Z', $checked) : null, 'source' => 'wordpress_update_transient'];
        }
        $core_auto = !defined('WP_AUTO_UPDATE_CORE') ? 'default' : (WP_AUTO_UPDATE_CORE === true ? 'enabled' : (WP_AUTO_UPDATE_CORE === false ? 'disabled' : (WP_AUTO_UPDATE_CORE === 'minor' ? 'minor_only' : 'unknown')));
        return [
            'components' => $components, 'updates' => $updates,
            'summary' => [
                'component_count' => count($components), 'update_count' => count($updates), 'locale' => self::text(get_locale(), 40),
                'multisite' => is_multisite(), 'scope' => 'current_site', 'active_theme' => get_stylesheet() ?: null,
                'core_auto_update_constant' => $core_auto,
                'file_mods_disabled' => defined('DISALLOW_FILE_MODS') ? (bool) DISALLOW_FILE_MODS : null,
                'automatic_updater_disabled' => defined('AUTOMATIC_UPDATER_DISABLED') ? (bool) AUTOMATIC_UPDATER_DISABLED : null,
                'update_checks' => $checks,
            ],
        ];
    }
}
