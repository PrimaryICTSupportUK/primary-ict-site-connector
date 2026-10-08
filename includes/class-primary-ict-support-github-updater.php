<?php
/** Public GitHub Releases updater, shared by independent Primary ICT plugins. */
if (!defined('ABSPATH')) { exit; }

if (!class_exists('Primary_ICT_Support_GitHub_Updater_V2')) {
    final class Primary_ICT_Support_GitHub_Updater_V2 {
        private array $config;
        private string $file;
        private string $cache;

        public function __construct(string $plugin_file, array $config) {
            $this->config = $config;
            $this->file = plugin_basename($plugin_file);
            $this->cache = 'picts_release_' . md5($this->file . ':' . ($config['repository'] ?? ''));
            // A blank repository disables the updater in an unconfigured starter.
            if (!$this->configured()) { return; }
            add_filter('update_plugins_github.com', [$this, 'check_update'], 10, 4);
            add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
            add_filter('upgrader_pre_download', [$this, 'verify_download'], 10, 4);
            add_filter('upgrader_source_selection', [$this, 'source_folder'], 10, 4);
            add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
        }

        private function configured(): bool {
            return (bool) preg_match('/^[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+$/D', $this->config['repository'] ?? '')
                && (bool) preg_match('/^[a-z0-9-]+$/D', dirname($this->file));
        }

        /** Only a published stable release with an exact, installable asset qualifies. */
        public function parse_release(array $release): ?array {
            if (!$this->configured() || !empty($release['draft']) || !empty($release['prerelease']) || empty($release['published_at'])) { return null; }
            $tag = $release['tag_name'] ?? '';
            if (!is_string($tag) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match)) { return null; }
            $version = $match[1];
            $name = dirname($this->file) . '-' . $version . '.zip';
            $expected = 'https://github.com/' . $this->config['repository'] . '/releases/download/' . $tag . '/' . $name;
            foreach (($release['assets'] ?? []) as $asset) {
                if (($asset['name'] ?? '') !== $name || ($asset['browser_download_url'] ?? '') !== $expected || ($asset['state'] ?? '') !== 'uploaded' || ($asset['size'] ?? 0) <= 0 || ($asset['size'] ?? 0) > 32 * 1024 * 1024) { continue; }
                $digest = $asset['digest'] ?? '';
                // GitHub now supplies SHA-256 for release assets. Require it for this channel.
                if (!is_string($digest) || !preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $hash)) { continue; }
                return ['version' => $version, 'package' => $expected, 'sha256' => $hash[1], 'notes' => substr((string) ($release['body'] ?? ''), 0, 30000)];
            }
            return null;
        }

        private function release(): ?array {
            $cached = get_site_transient($this->cache);
            if (is_array($cached)) { return $cached['release'] ?? null; }
            $response = wp_safe_remote_get('https://api.github.com/repos/' . $this->config['repository'] . '/releases/latest', [
                'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 200000,
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Primary-ICT-Support-WordPress-Updater'],
            ]);
            $body = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;
            $release = is_array($body) ? $this->parse_release($body) : null;
            // Cache errors briefly too, so an unpublished channel or rate limit cannot hammer GitHub.
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $status = $release ? 'verified' : ($code === 429 || $code === 403 ? 'rate_limited' : ($code === 200 ? 'no_valid_release' : 'provider_unavailable'));
            $ttl = $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
            set_site_transient($this->cache, ['release' => $release, 'status' => $status, 'checked_at' => time(), 'next_check_at' => time() + $ttl], $ttl);
            return $release;
        }

        public function release_status(): array {
            $cached = get_site_transient($this->cache);
            return ['status' => is_array($cached) ? ($cached['status'] ?? 'not_recorded') : 'not_checked',
                'checked_at' => is_array($cached) ? ($cached['checked_at'] ?? null) : null,
                'next_check_at' => is_array($cached) ? ($cached['next_check_at'] ?? null) : null,
                'version' => is_array($cached) ? ($cached['release']['version'] ?? null) : null];
        }

        /** Atomic cooldown: repeated admin clicks cannot hammer GitHub. */
        public function refresh_release(): array {
            $lock = $this->cache . '_manual';
            $previous = (string) get_option($lock, '');
            if ($previous !== '' && (int) $previous > time() - MINUTE_IN_SECONDS) { return ['status' => 'cooldown']; }
            if ($previous !== '') {
                global $wpdb;
                // Delete only the expired value we observed; never another request's new lock.
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $previous));
                wp_cache_delete($lock, 'options');
            }
            if (!add_option($lock, time(), '', false)) { return ['status' => 'cooldown']; }
            delete_site_transient($this->cache);
            $this->release();
            return $this->release_status();
        }

        public function check_update($update, array $plugin_data, string $plugin_file, array $locales) {
            if ($plugin_file !== $this->file || ($plugin_data['UpdateURI'] ?? '') !== 'https://github.com/' . $this->config['repository']) { return $update; }
            $release = $this->release();
            if (!$release) { return $update; }
            return ['id' => $plugin_data['UpdateURI'], 'slug' => dirname($this->file), 'version' => $release['version'],
                'url' => $plugin_data['UpdateURI'], 'package' => $release['package'],
                'requires' => $this->config['requires_wp'], 'requires_php' => $this->config['requires_php']];
        }

        public function plugin_info($result, string $action, $args) {
            if ($action !== 'plugin_information' || ($args->slug ?? '') !== dirname($this->file)) { return $result; }
            $release = $this->release();
            if (!$release) { return $result; }
            return (object) ['name' => $this->config['name'], 'slug' => dirname($this->file), 'version' => $release['version'],
                'author' => 'Primary ICT Support', 'homepage' => 'https://github.com/' . $this->config['repository'],
                'requires' => $this->config['requires_wp'], 'requires_php' => $this->config['requires_php'], 'download_link' => $release['package'],
                'sections' => ['description' => esc_html($this->config['description']), 'changelog' => wp_kses_post(wpautop($release['notes']))]];
        }

        /** Package integrity is checked before the WordPress upgrader extracts any files. */
        public function verify_download($reply, string $package, $upgrader, array $hook_extra) {
            if (($hook_extra['plugin'] ?? '') !== $this->file) { return $reply; }
            $release = $this->release();
            if (!$release || $package !== $release['package']) { return new WP_Error('picts_release_changed', 'The trusted plugin release changed or is unavailable. Check for updates again.'); }
            if ($reply !== false) { return new WP_Error('picts_download_override', 'Another updater intercepted this package. Installation was stopped.'); }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $download = download_url($package, 60);
            if (is_wp_error($download)) { return $download; }
            $digest = hash_file('sha256', $download);
            if (!$digest || !hash_equals($release['sha256'], $digest)) {
                wp_delete_file($download);
                return new WP_Error('picts_checksum_failed', 'The plugin package failed its SHA-256 check. Installation was stopped.');
            }
            return $download;
        }

        public function source_folder($source, $remote_source, $upgrader, array $hook_extra) {
            if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== $this->file) { return $source; }
            global $wp_filesystem;
            if (!$wp_filesystem || !$wp_filesystem->exists(trailingslashit($source) . basename($this->file))) { return new WP_Error('picts_package_invalid', 'The release does not contain the expected plugin entry file.'); }
            $folder = dirname($this->file);
            if (basename(untrailingslashit($source)) === $folder) { return $source; }
            $destination = trailingslashit($remote_source) . $folder;
            if ($wp_filesystem->exists($destination) || !$wp_filesystem->move($source, $destination, false)) { return new WP_Error('picts_package_folder', 'The plugin package folder could not be prepared.'); }
            return trailingslashit($destination);
        }

        public function clear_cache($upgrader, array $options): void {
            if (($options['type'] ?? '') === 'plugin' && in_array($this->file, (array) ($options['plugins'] ?? [$options['plugin'] ?? '']), true)) { delete_site_transient($this->cache); }
        }
    }
}

if (!class_exists('Primary_ICT_Support_GitHub_Updater')) { class_alias('Primary_ICT_Support_GitHub_Updater_V2', 'Primary_ICT_Support_GitHub_Updater'); }
