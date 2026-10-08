<?php
if (!defined('ABSPATH')) { exit; }

/** Fixed-purpose checks and single-plugin updates. No remote callbacks or package URLs. */
final class PICTS_Check_Jobs {
    private const HOOK = 'picts_connector_poll';
    private const RESUME = 'picts_connector_job_resume';
    private const PENDING = 'picts_connector_pending_job';
    private const VERSION = '0.5.1';
    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'schedule']);
        add_action('init', [self::class, 'ensure_schedule']);
        add_action(self::HOOK, [self::class, 'poll']);
        add_action(self::RESUME, [self::class, 'poll']);
        add_action('admin_post_picts_poll', [self::class, 'manual']);
    }
    public static function schedule(array $schedules): array {
        $settings = (array) get_option('picts_connector_settings', []);
        $minutes = max(1, min(60, (int) ($settings['poll_interval'] ?? 1)));
        $schedules['picts_poll_interval'] = ['interval' => $minutes * MINUTE_IN_SECONDS, 'display' => 'Primary ICT Support job poll'];
        return $schedules;
    }
    public static function ensure_schedule(): void {
        $settings = (array) get_option('picts_connector_settings', []);
        if (!empty($settings['token']) && !wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + MINUTE_IN_SECONDS, 'picts_poll_interval', self::HOOK); }
    }
    // add_option is atomic. Remove an expired lock only if its stored value still matches.
    public static function with_lock(string $name, callable $callback): bool {
        $pending = (array) get_option(self::PENDING, []);
        $lock_seconds = $name === 'job' && ($pending['job']['action'] ?? '') === 'update_plugin' ? 600 : 180;
        $name = 'picts_connector_lock_' . $name;
        $value = (string) time();
        if (!add_option($name, $value, '', false)) {
            $old = (string) get_option($name, '');
            if ((int) $old > time() - $lock_seconds) { return false; }
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $old));
            wp_cache_delete($name, 'options');
            if (!add_option($name, $value, '', false)) { return false; }
        }
        try { $callback(); return true; }
        finally {
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value));
            wp_cache_delete($name, 'options');
        }
    }
    private static function request(array $body): array {
        $settings = (array) get_option('picts_connector_settings', []);
        $response = wp_safe_remote_post($settings['service_url'] . '/api/plugin/jobs', [
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $settings['token']],
            'body' => wp_json_encode(array_merge(['site_id' => $settings['site_id'], 'plugin_version' => self::VERSION], $body)),
            'limit_response_size' => 30000,
        ]);
        if (is_wp_error($response)) { return ['code' => 0, 'data' => []]; }
        return ['code' => wp_remote_retrieve_response_code($response), 'data' => (array) json_decode(wp_remote_retrieve_body($response), true)];
    }
    private static function apply_settings(array $remote): void {
        $settings = (array) get_option('picts_connector_settings', []);
        $previous = $settings;
        foreach (['inventory_interval' => [5,1440,'interval'], 'poll_interval' => [1,60,'poll_interval']] as $key => $range) {
            if (isset($remote[$key]) && is_int($remote[$key]) && $remote[$key] >= $range[0] && $remote[$key] <= $range[1]) { $settings[$range[2]] = $remote[$key]; }
        }
        update_option('picts_connector_settings', $settings, false);
        if (($previous['interval'] ?? 15) !== ($settings['interval'] ?? 15)) {
            wp_clear_scheduled_hook('picts_connector_inventory');
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'picts_inventory_interval', 'picts_connector_inventory');
        }
        if (($previous['poll_interval'] ?? 1) !== ($settings['poll_interval'] ?? 1)) { wp_clear_scheduled_hook(self::HOOK); self::ensure_schedule(); }
    }
    private static function report(array $pending, string $status): array {
        return self::request(['operation' => 'report', 'job_id' => $pending['job']['id'], 'lease_token' => $pending['lease'], 'status' => $status, 'stage' => $pending['stage'], 'report' => $pending['report']]);
    }
    private static function restore_interrupted(array &$pending): void {
        if (!empty($pending['check_in_progress'])) {
            $transient = 'update_' . ($pending['check_in_progress'] === 'core' ? 'core' : $pending['check_in_progress'] . 's');
            if (array_key_exists('previous_check', $pending)) { if ($pending['previous_check'] === false) { delete_site_transient($transient); } else { set_site_transient($transient, $pending['previous_check']); } }
            unset($pending['check_in_progress'], $pending['previous_check']);
        }
    }
    public static function poll(): void {
        $settings = (array) get_option('picts_connector_settings', []);
        if (empty($settings['token'])) { return; }
        try { self::with_lock('job', static function (): void {
            $pending = (array) get_option(self::PENDING, []);
            if (!$pending) {
                $response = self::request(['operation' => 'poll', 'cron_context' => wp_doing_cron()]);
                update_option('picts_connector_last_poll', ['at' => time(), 'success' => $response['code'] === 200, 'cron' => wp_doing_cron()], false);
                if ($response['code'] !== 200) { return; }
                self::apply_settings((array) ($response['data']['settings'] ?? []));
                $job = $response['data']['job'] ?? null;
                if (!$job) { return; }
                $lease = $response['data']['lease_token'] ?? '';
                if (!in_array($job['action'] ?? '', ['collect_inventory','check_updates','update_plugin'], true) || !preg_match('/^[0-9a-f-]{36}$/i', $job['id'] ?? '') || !preg_match('/^picts_lease_[A-Za-z0-9_-]{43}$/', $lease) || !in_array($job['stage'] ?? '', ['core','diagnostics','preflight'], true)) { return; }
                $pending = ['job' => $job, 'lease' => $lease, 'stage' => $job['stage'], 'report' => ['schema_version' => 1, 'snapshot_id' => null, 'checks' => [], 'diagnostics' => null, 'failure' => null]];
                if ($job['action'] === 'update_plugin') {
                    if (!PICTS_Plugin_Update::valid_target((array) ($job['target'] ?? []))) { return; }
                    $pending['report']['update'] = PICTS_Plugin_Update::initial($job['target']);
                }
                update_option(self::PENDING, $pending, false);
            }
            // Confirm/renew the lease before every local phase. A stale worker cannot report over its replacement.
            $heartbeat = self::report($pending, 'running');
            if (in_array($heartbeat['code'], [401,409], true) || !empty($heartbeat['data']['terminal'])) { self::restore_interrupted($pending); delete_option(self::PENDING); return; }
            if ($heartbeat['code'] !== 200) { return; }
            self::restore_interrupted($pending);
            try {
                $stage = $pending['stage'];
                if ($stage === 'preflight') {
                    $check = isset($pending['job']['target']['source'])
                        ? ['category' => 'plugin', 'status' => PICTS_GitHub_Source::resolve($pending['job']['target']) ? 'success' : 'failed', 'reason' => 'response_received', 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'provider' => 'github']
                        : self::check('plugin', $pending);
                    if ($check['status'] !== 'success') { $check['reason'] = 'provider_error'; }
                    $pending['report']['checks'][] = $check;
                    $reason = $check['status'] === 'success' ? PICTS_Plugin_Update::guard($pending['job']['target']) : 'provider_check_failed';
                    if ($reason) { PICTS_Plugin_Update::stop($pending, $reason); } else { $pending['stage'] = 'execute'; }
                } elseif ($stage === 'execute') {
                    PICTS_Plugin_Update::execute($pending, static fn (array $body): array => self::request($body));
                } elseif ($stage === 'verify') {
                    PICTS_Plugin_Update::verify($pending);
                } elseif (in_array($stage, ['core','plugin','theme'], true)) {
                    $pending['report']['checks'][] = self::check($stage, $pending);
                    $pending['stage'] = ['core' => 'plugin', 'plugin' => 'theme', 'theme' => 'diagnostics'][$stage];
                } elseif ($stage === 'diagnostics') {
                    $pending['report']['diagnostics'] = self::diagnostics();
                    if (($pending['report']['update']['outcome'] ?? '') === 'updated' && array_filter($pending['report']['diagnostics']['health_checks'], static fn ($check) => $check['status'] === 'critical')) { PICTS_Plugin_Update::stop($pending, 'health_check_failed', 'failed'); $pending['report']['failure'] = 'post_update_failed'; }
                    $pending['stage'] = 'inventory';
                    $pending['fresh_inventory_started'] = false;
                } elseif ($stage === 'inventory') {
                    if (PICTS_Site_Connector::send_inventory($pending['job']['id'])) {
                        $last = (array) get_option('picts_connector_last_result', []);
                        $pending['report']['snapshot_id'] = $last['snapshot_id'] ?? null;
                        $pending['stage'] = 'finished';
                        if (array_filter($pending['report']['checks'], static fn ($check) => $check['status'] !== 'success')) { $pending['report']['failure'] = $pending['report']['failure'] ?? 'provider_check_failed'; }
                    } elseif (empty(get_option('picts_connector_pending_inventory'))) { $pending['report']['failure'] = 'inventory_failed'; $pending['stage'] = 'finished'; }
                }
            } catch (Throwable $error) { self::restore_interrupted($pending); if ($pending['job']['action'] === 'update_plugin') { PICTS_Plugin_Update::stop($pending, 'worker_error', !empty($pending['grant_attempted']) ? 'uncertain' : 'failed'); } else { $pending['report']['failure'] = 'worker_error'; $pending['stage'] = 'finished'; } }
            update_option(self::PENDING, $pending, false);
            if ($pending['stage'] === 'finished') {
                $done = self::report($pending, $pending['report']['failure'] ? 'failed' : 'completed');
                if ($done['code'] === 200 || in_array($done['code'], [401,409], true)) { delete_option(self::PENDING); }
            } else { self::report($pending, 'running'); }
        }); } finally {
            if (get_option(self::PENDING) && !wp_next_scheduled(self::RESUME)) { wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RESUME); }
            elseif (!get_option(self::PENDING)) { wp_clear_scheduled_hook(self::RESUME); }
        }
    }
    private static function check(string $category, array &$pending): array {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        $transient = 'update_' . ($category === 'core' ? 'core' : $category . 's');
        $old = get_site_transient($transient);
        $pending['previous_check'] = $old; $pending['check_in_progress'] = $category;
        update_option(self::PENDING, $pending, false);
        // Preserve candidates while bypassing WordPress's recent-check guard.
        if (is_object($old)) { $reset = clone $old; $reset->last_checked = 0; set_site_transient($transient, $reset); }
        $observed = null;
        $paths = ['core' => '/core/version-check/', 'plugin' => '/plugins/update-check/', 'theme' => '/themes/update-check/'];
        $debug = static function ($response, $context, $class, $args, $url) use (&$observed, $paths, $category): void {
            if ($context !== 'response' || wp_parse_url($url, PHP_URL_HOST) !== 'api.wordpress.org' || !str_starts_with((string) wp_parse_url($url, PHP_URL_PATH), $paths[$category])) { return; }
            $body = !is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
            $required = ['core' => 'offers', 'plugin' => 'plugins', 'theme' => 'themes'][$category];
            $observed = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && is_array($body) && isset($body[$required]) && is_array($body[$required]);
        };
        $timeout = static function ($args, $url) use ($paths, $category): array { if (wp_parse_url($url, PHP_URL_HOST) === 'api.wordpress.org' && str_starts_with((string) wp_parse_url($url, PHP_URL_PATH), $paths[$category])) { $args['timeout'] = 10; } return $args; };
        add_action('http_api_debug', $debug, 10, 5); add_filter('http_request_args', $timeout, 10, 2);
        try { if ($category === 'core') { wp_version_check([], true); } elseif ($category === 'plugin') { wp_update_plugins(); } else { wp_update_themes(); } }
        finally { remove_action('http_api_debug', $debug, 10); remove_filter('http_request_args', $timeout, 10); if ($observed !== true) { if ($old === false) { delete_site_transient($transient); } else { set_site_transient($transient, $old); } } }
        unset($pending['previous_check'], $pending['check_in_progress']);
        return ['category' => $category, 'status' => $observed === true ? 'success' : ($observed === false ? 'failed' : 'skipped'), 'reason' => $observed === true ? 'response_received' : ($observed === false ? 'provider_error' : 'not_observed'), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'provider' => 'wordpress_org'];
    }
    private static function diagnostics(): array {
        $checks = [];
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        $health = WP_Site_Health::get_instance();
        foreach (['php_extensions','ssl_support','file_uploads'] as $id) {
            $status = 'skipped'; $method = 'get_test_' . $id;
            try { if (is_callable([$health, $method])) { $result = $health->$method(); if (in_array($result['status'] ?? '', ['good','recommended','critical'], true)) { $status = $result['status']; } } } catch (Throwable $error) { /* Explicitly skipped; never transport raw messages. */ }
            $checks[] = ['id' => $id, 'status' => $status];
        }
        $next = wp_next_scheduled(self::HOOK);
        return ['cron_context' => wp_doing_cron(), 'cron_disabled' => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : null, 'next_poll_at' => $next ? gmdate('Y-m-d\TH:i:s\Z', $next) : null, 'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG, 'search_indexing' => (string) get_option('blog_public') === '1', 'https' => is_ssl(), 'health_checks' => $checks];
    }
    public static function manual(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access is required.'); }
        check_admin_referer('picts_poll'); self::poll();
        wp_safe_redirect(admin_url('admin.php?page=picts-connector')); exit;
    }
    public static function admin_status(): void {
        $last = (array) get_option('picts_connector_last_poll', []);
        echo '<h2>Dashboard jobs</h2><p>This site runs inventory/check jobs and individually authorised plugin-update jobs. Updates run in WordPress cron and preserve activation. Review the saved dashboard report.</p>';
        if (!empty($last['at'])) { echo '<p>Last service poll: ' . esc_html(wp_date('j M Y, H:i', $last['at'])) . ' — ' . (!empty($last['success']) ? 'accepted' : 'failed') . '. Source: ' . (!empty($last['cron']) ? 'WordPress cron' : 'manual') . '.</p>'; }
        $pending = (array) get_option(self::PENDING, []);
        if ($pending) { echo '<p>Current phase: ' . esc_html($pending['stage'] ?? 'unknown') . '. Each poll runs one phase.</p>'; }
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="picts_poll">'; wp_nonce_field('picts_poll'); submit_button('Poll dashboard jobs now', 'secondary'); echo '</form>';
        echo '<p>For idle sites, enable central wakeups in the dashboard. If your firewall blocks the standard WordPress cron endpoint, host cron is an optional fallback. Service receipt of a cron poll proves cron ran; it does not prove the schedule will keep running.</p>';
    }
}
PICTS_Check_Jobs::init();
