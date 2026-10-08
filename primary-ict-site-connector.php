<?php
/**
 * Plugin Name: Primary ICT Support Site Connector
 * Description: Pairs this site with the Primary ICT Support dashboard and collects WordPress/PHP inventory and runs authorised plugin updates.
 * Version: 0.4.1
 * Author: Primary ICT Support
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/PrimaryICTSupportUK/primary-ict-site-connector
 */

if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-primary-ict-support-admin-menu.php';
require_once __DIR__ . '/includes/class-primary-ict-support-github-updater.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/jobs.php';

final class PICTS_Site_Connector {
    private const OPTION = 'picts_connector_settings';
    private const HOOK = 'picts_connector_inventory';
    private const RESUME_HOOK = 'picts_connector_inventory_resume';
    private const VERSION = '0.4.1';

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedule']);
        add_action('admin_menu', function (): void {
            Primary_ICT_Support_Admin_Menu::register_plugin([
                'page_title' => 'Primary ICT Support Site Connector', 'menu_title' => 'Site Connector',
                'description' => 'Connect this website to the dashboard, collect inventory and run authorised maintenance jobs.',
                'slug' => 'picts-connector', 'callback' => [self::class, 'settings_page'], 'capability' => 'manage_options',
                'icon_url' => plugins_url('assets/images/primary-ict-support-icon.png', __FILE__),
                'logo_url' => plugins_url('assets/images/primary-ict-support-logo.svg', __FILE__),
            ]);
        });
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=picts-connector')) . '">Settings</a>'); return $links;
        });
        // Preserve old Settings-menu bookmarks without adding a second menu entry.
        add_action('admin_init', static function (): void {
            global $pagenow;
            if ($pagenow === 'options-general.php' && ($_GET['page'] ?? '') === 'picts-connector' && current_user_can('manage_options')) {
                wp_safe_redirect(add_query_arg('picts_notice', sanitize_key($_GET['picts_notice'] ?? ''), admin_url('admin.php?page=picts-connector'))); exit;
            }
        });
        new Primary_ICT_Support_GitHub_Updater(__FILE__, [
            'repository' => 'PrimaryICTSupportUK/primary-ict-site-connector', 'name' => 'Primary ICT Support Site Connector',
            'description' => 'WordPress inventory, dashboard pairing and authorised maintenance jobs.', 'requires_wp' => '6.5', 'requires_php' => '8.0',
        ]);
        add_action('admin_post_picts_pair', [self::class, 'pair']);
        add_action('admin_post_picts_inventory', [self::class, 'manual_inventory']);
        add_action(self::HOOK, [self::class, 'send_inventory']);
        add_action(self::RESUME_HOOK, [self::class, 'send_inventory']);
    }

    private static function settings(): array {
        return (array) get_option(self::OPTION, []);
    }

    public static function cron_schedule(array $schedules): array {
        $settings = self::settings();
        $minutes = max(5, min(1440, (int) ($settings['interval'] ?? 15)));
        $schedules['picts_inventory_interval'] = ['interval' => $minutes * MINUTE_IN_SECONDS, 'display' => 'Primary ICT Support inventory interval'];
        return $schedules;
    }

    public static function activate(): void {
        if (!empty(self::settings()['token']) && !wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + MINUTE_IN_SECONDS, 'picts_inventory_interval', self::HOOK); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook(self::HOOK); wp_clear_scheduled_hook(self::RESUME_HOOK); wp_clear_scheduled_hook('picts_connector_poll'); wp_clear_scheduled_hook('picts_connector_job_resume'); }

    private static function finish(string $notice): void {
        wp_safe_redirect(add_query_arg('picts_notice', $notice, admin_url('admin.php?page=picts-connector')));
        exit;
    }

    public static function pair(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access is required.'); }
        check_admin_referer('picts_pair');
        $url = untrailingslashit(esc_url_raw(wp_unslash($_POST['service_url'] ?? '')));
        $site_id = sanitize_text_field(wp_unslash($_POST['site_id'] ?? ''));
        $code = sanitize_text_field(wp_unslash($_POST['pairing_code'] ?? ''));
        $interval = max(5, min(1440, (int) ($_POST['interval'] ?? 15)));
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment']) || !empty($parts['path']) || (!empty($parts['port']) && $parts['port'] !== 443) || !wp_http_validate_url($url) || !preg_match('/^[0-9a-f-]{36}$/i', $site_id) || !preg_match('/^picts_pair_[A-Za-z0-9_-]{43}$/', $code)) {
            self::finish('invalid');
        }
        $response = wp_safe_remote_post($url . '/api/plugin/pair', [
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['site_id' => $site_id, 'code' => $code, 'base_url' => untrailingslashit(home_url())]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { self::finish('failed'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || ($data['site_id'] ?? '') !== $site_id || !preg_match('/^picts_site_[A-Za-z0-9_-]{43}$/', $data['token'] ?? '')) { self::finish('failed'); }
        // The bearer credential is stored in a non-autoloaded option and never echoed in the admin UI.
        update_option(self::OPTION, ['service_url' => $url, 'site_id' => $site_id, 'token' => $data['token'], 'interval' => $interval], false);
        delete_option('picts_connector_pending_inventory');
        delete_option('picts_connector_pending_job');
        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'picts_inventory_interval', self::HOOK);
        self::send_inventory();
        PICTS_Check_Jobs::ensure_schedule();
        self::finish('paired');
    }

    private static function megabytes(string $raw): ?float {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-1' || (float) $raw <= 0) { return null; }
        $unit = strtolower(substr($raw, -1));
        $number = (float) $raw;
        $bytes = match ($unit) { 'g' => $number * 1024 * 1024 * 1024, 'm' => $number * 1024 * 1024, 'k' => $number * 1024, default => $number };
        return round($bytes / 1048576, 2);
    }

    public static function send_inventory(?string $job_id = null): bool {
        $success = false;
        PICTS_Check_Jobs::with_lock('inventory', static function () use (&$success, $job_id): void { $success = self::send_inventory_unlocked($job_id); });
        return $success;
    }

    private static function send_inventory_unlocked(?string $job_id): bool {
        $invocation_started = microtime(true);
        $settings = self::settings();
        if (empty($settings['token']) || empty($settings['service_url']) || empty($settings['site_id'])) { return false; }
        $metric = static fn (string $key, string $label, ?float $value, string $unit, string $source): array => ['key' => $key, 'label' => $label, 'value' => $value, 'capacity' => null, 'unit' => $unit, 'source' => $source];
        $execution = (int) ini_get('max_execution_time');
        $summary = [
            'plugin_version' => self::VERSION,
            'wordpress_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION,
            'metrics' => [
                $metric('php_memory_limit', 'PHP memory limit', self::megabytes((string) ini_get('memory_limit')), 'MiB', 'PHP configuration; not memory usage'),
                $metric('php_upload_limit', 'PHP upload limit', self::megabytes((string) ini_get('upload_max_filesize')), 'MiB', 'PHP configuration'),
                $metric('php_post_limit', 'PHP POST limit', self::megabytes((string) ini_get('post_max_size')), 'MiB', 'PHP configuration'),
                $metric('php_execution_limit', 'PHP execution limit', $execution > 0 ? (float) $execution : null, 'seconds', 'PHP configuration; null means unlimited or unavailable'),
                $metric('hosting_disk_usage', 'Hosting disk usage', null, 'MiB', 'Host account quota is not available from WordPress'),
            ],
        ];
        $success = false;
        $component_count = null;
        $snapshot_id = null;
        $reason = 'collection_failed';
        try {
            require_once __DIR__ . '/inventory.php';
            $pending = (array) get_option('picts_connector_pending_inventory', []);
            if ($job_id && ($pending['job_id'] ?? null) !== $job_id) {
                $last = (array) get_option('picts_connector_last_result', []);
                if (!empty($last['success']) && ($last['job_id'] ?? null) === $job_id) { return true; }
                delete_option('picts_connector_pending_inventory'); $pending = [];
            }
            if (!$pending || ($pending['started_at'] ?? 0) < time() - 7200) {
                $inventory = PICTS_Inventory::collect();
                if ($inventory['summary']['component_count'] > 5000 || $inventory['summary']['update_count'] > 5000) { $reason = 'catalogue_too_large'; throw new RuntimeException('Catalogue exceeds supported bound'); }
                $pending = ['job_id' => $job_id, 'inventory' => $inventory, 'summary' => array_merge($summary, $inventory['summary']), 'snapshot_id' => wp_generate_uuid4(), 'collected_at' => gmdate('Y-m-d\TH:i:s\Z'), 'started_at' => time(), 'index' => 0];
                update_option('picts_connector_pending_inventory', $pending, false);
            }
            $inventory = $pending['inventory'];
            $summary = $pending['summary'];
            $component_count = $summary['component_count'];
            if ($component_count > 5000 || $summary['update_count'] > 5000) { throw new RuntimeException('Catalogue exceeds supported bound'); }
            $count = max(1, (int) ceil(max($component_count, $summary['update_count']) / 25));
            $snapshot_id = $pending['snapshot_id'];
            $collected_at = $pending['collected_at'];
            $reason = 'upload_failed';
            $first_index = (int) $pending['index'];
            $budget = $execution > 0 ? min(20, max(5, $execution - 5)) : 20;
            // Bound each PHP invocation; resume larger catalogues through WP-Cron.
            for ($index = $first_index; $index < min($count, $first_index + 3); $index++) {
                if (microtime(true) - $invocation_started >= $budget) { $reason = 'upload_pending'; break; }
                $body = wp_json_encode([
                    'schema_version' => 2, 'site_id' => $settings['site_id'], 'snapshot_id' => $snapshot_id,
                    'collected_at' => $collected_at, 'chunk_index' => $index, 'chunk_count' => $count, 'summary' => $summary,
                    'components' => array_slice($inventory['components'], $index * 25, 25),
                    'updates' => array_slice($inventory['updates'], $index * 25, 25),
                ]);
                if ($body === false || strlen($body) > 200000) { $reason = 'payload_too_large'; break; }
                $reason = 'upload_failed';
                $response = wp_safe_remote_post($settings['service_url'] . '/api/plugin/inventory', [
                    'timeout' => 10, 'redirection' => 0, 'sslverify' => true,
                    'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $settings['token']], 'body' => $body,
                ]);
                if (is_wp_error($response)) { $reason = 'network_error'; break; }
                if (wp_remote_retrieve_response_code($response) !== 200) { $reason = 'http_' . (string) wp_remote_retrieve_response_code($response); break; }
                $result = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($result) || empty($result['accepted'])) { break; }
                if (!empty($result['ignored'])) { delete_option('picts_connector_pending_inventory'); $reason = 'older_snapshot_ignored'; break; }
                $pending['index'] = $index + 1;
                update_option('picts_connector_pending_inventory', $pending, false);
                $reason = 'upload_pending';
                if ($index === $count - 1) { $success = !empty($result['committed']); }
            }
            if ($success) { delete_option('picts_connector_pending_inventory'); wp_clear_scheduled_hook(self::RESUME_HOOK); }
            elseif (get_option('picts_connector_pending_inventory') && !wp_next_scheduled(self::RESUME_HOOK)) { wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RESUME_HOOK); }
        } catch (Throwable $error) {
            // Only the safe category is stored; raw exceptions can contain paths/secrets.
        }
        $previous = (array) get_option('picts_connector_last_result', []);
        update_option('picts_connector_last_result', ['at' => time(), 'success' => $success, 'reason' => $success ? 'accepted' : $reason, 'component_count' => $component_count, 'job_id' => $pending['job_id'] ?? null, 'snapshot_id' => $success ? $snapshot_id : null, 'last_success_at' => $success ? time() : ($previous['last_success_at'] ?? null)], false);
        return $success;
    }

    public static function manual_inventory(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access is required.'); }
        check_admin_referer('picts_inventory');
        self::finish(self::send_inventory() ? 'sent' : 'send_failed');
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $settings = self::settings();
        $notices = ['paired' => 'Site paired. Check the inventory result below.', 'sent' => 'Inventory sent successfully.', 'invalid' => 'Enter the HTTPS dashboard origin, site ID and current pairing code.', 'failed' => 'Pairing failed. Check the service URL, site domain, site ID and code expiry.', 'send_failed' => 'Inventory is incomplete or could not be sent. Check the result below; larger catalogues resume through WP-Cron.'];
        $notice = sanitize_key($_GET['picts_notice'] ?? '');
        echo '<div class="wrap picts-plugin-page picts-dashboard"><div class="picts-plugin-page__hero"><div><p class="picts-plugin-page__eyebrow">Primary ICT Support</p><h1>Site Connector</h1><p class="picts-plugin-page__intro">Version ' . esc_html(self::VERSION) . ' · Inventory, dashboard checks and authorised plugin updates.</p></div><img class="picts-plugin-page__logo" src="' . esc_url(plugins_url('assets/images/primary-ict-support-logo.svg', __FILE__)) . '" alt="Primary ICT Support"></div><div class="picts-plugin-page__panel">';
        if (isset($notices[$notice])) { echo '<div class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>'; }
        if (!empty($settings['token'])) {
            $last = (array) get_option('picts_connector_last_result', []);
            echo '<h2>Connected site</h2><p>Service: ' . esc_html($settings['service_url']) . '</p><p>Site ID: ' . esc_html($settings['site_id']) . '</p>';
            if (!empty($last['at'])) { echo '<p>Last inventory: ' . esc_html(wp_date('j M Y, H:i', $last['at'])) . ' — ' . (!empty($last['success']) ? 'accepted' : (($last['reason'] ?? '') === 'upload_pending' ? 'upload in progress' : 'failed')) . '</p>'; }
            if (isset($last['component_count'])) { echo '<p>Components collected: ' . esc_html((string) $last['component_count']) . '. Update checks use WordPress cached results; this button does not force provider checks.</p>'; }
            if (empty($last['success']) && !empty($last['reason'])) { echo '<p>Result category: ' . esc_html($last['reason']) . '. The dashboard keeps the last complete catalogue.</p>'; }
            if (!empty($last['last_success_at'])) { echo '<p>Last complete inventory accepted: ' . esc_html(wp_date('j M Y, H:i', $last['last_success_at'])) . '</p>'; }
            echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="picts_inventory">';
            wp_nonce_field('picts_inventory'); submit_button('Send inventory now', 'secondary'); echo '</form>';
        }
        if (!empty($settings['token'])) { PICTS_Check_Jobs::admin_status(); }
        echo '<h2>Pair or reconnect</h2><p>Get a one-time pairing code from Add site in the dashboard. Use the canonical WordPress home URL when registering the site.</p><form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="picts_pair">';
        wp_nonce_field('picts_pair');
        echo '<table class="form-table"><tr><th><label for="picts-service">Dashboard service URL</label></th><td><input id="picts-service" class="regular-text" name="service_url" type="url" required placeholder="https://your-dashboard.vercel.app" value="' . esc_attr($settings['service_url'] ?? '') . '"></td></tr>';
        echo '<tr><th><label for="picts-site">Site ID</label></th><td><input id="picts-site" class="regular-text" name="site_id" required value="' . esc_attr($settings['site_id'] ?? '') . '"></td></tr>';
        echo '<tr><th><label for="picts-code">Pairing code</label></th><td><input id="picts-code" class="regular-text" name="pairing_code" type="password" autocomplete="off" required></td></tr>';
        echo '<tr><th><label for="picts-interval">Inventory interval (minutes)</label></th><td><input id="picts-interval" name="interval" type="number" min="5" max="1440" value="' . esc_attr($settings['interval'] ?? 15) . '"><p class="description">WP-Cron depends on site traffic. This is not a guaranteed monitoring or update schedule.</p></td></tr></table>';
        submit_button('Pair site');
        echo '</form><h2>Connector releases</h2><p>Published GitHub releases will appear in Plugins and Dashboard → Updates once the connector release repository is available. WordPress automatic updates are optional: enable them for this connector in Plugins if wanted. Updating the connector preserves its pairing settings.</p><p>Dashboard jobs currently support authorised individual WordPress.org plugin updates. PHP configuration limits are not live server usage; host quotas and complete site-health checks need further integration.</p></div></div>';
    }
}

PICTS_Site_Connector::init();
register_deactivation_hook(__FILE__, [PICTS_Site_Connector::class, 'deactivate']);
register_activation_hook(__FILE__, [PICTS_Site_Connector::class, 'activate']);
