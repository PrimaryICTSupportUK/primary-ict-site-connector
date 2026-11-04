<?php
if (!defined('ABSPATH')) { exit; }

/** Explicit, administrator-started preparation of the connector's core tables. */
final class PICTS_Database_Preparation {
    private const STATE = 'picts_connector_database_preparation';

    public static function inspect(): array {
        global $wpdb;
        $result = [];
        foreach (['posts', 'postmeta', 'options', 'term_relationships', 'term_taxonomy'] as $name) {
            $table = $wpdb->$name;
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_$]+$/D', $table)) { throw new RuntimeException('invalid_table'); }
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
            $result[] = ['name' => $name, 'table' => $table, 'engine' => is_string($engine) ? strtolower($engine) : 'unavailable'];
        }
        return $result;
    }

    // Named locks are scoped to the database server, so host aliases for the
    // same database must resolve to the same lock name.
    private static function lock_name(): string { return 'picts-db-' . substr(hash('sha256', DB_NAME), 0, 48); }
    private static function lock(): void {
        global $wpdb;
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name())) !== '1') { throw new RuntimeException('database_busy'); }
    }
    private static function assert_lock($connection, bool $fixture = false): void {
        global $wpdb;
        if ($wpdb->dbh !== $connection) { throw new RuntimeException('connection_uncertain'); }
        if ($fixture) { return; }
        if (!($connection instanceof mysqli)) { throw new RuntimeException('database_driver_unsupported'); }
        try {
            $result = mysqli_query($connection, "SELECT IS_USED_LOCK('" . self::lock_name() . "') = CONNECTION_ID()");
            if (!$result || (string) ($result->fetch_row()[0] ?? '') !== '1') { throw new RuntimeException('connection_uncertain'); }
        } catch (Throwable $e) { throw new RuntimeException('connection_uncertain'); }
    }
    private static function unlock($connection): void {
        global $wpdb;
        try {
            if ($connection instanceof mysqli) { mysqli_query($connection, "SELECT RELEASE_LOCK('" . self::lock_name() . "')"); }
            else { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name())); }
        } catch (Throwable $ignored) {}
    }
    private static function save(array $state, $connection, bool $fixture = false): void {
        global $wpdb;
        self::assert_lock($connection, $fixture);
        if ($fixture) {
            update_option(self::STATE, $state, false);
            wp_cache_delete(self::STATE, 'options');
            if (get_option(self::STATE) !== $state) { throw new RuntimeException('state_not_saved'); }
            return;
        }
        // Use the locked mysqli connection directly: wpdb's retry-on-disconnect
        // must never let a stale worker overwrite a newer recovery's state.
        try {
            $table = $wpdb->options;
            if (!preg_match('/^[A-Za-z0-9_$]+$/D', $table)) { throw new RuntimeException('state_not_saved'); }
            $stmt = $connection->prepare("INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES (?,?,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)");
            $key = self::STATE; $value = serialize($state);
            $stmt->bind_param('ss', $key, $value);
            if (!$stmt->execute()) { throw new RuntimeException('state_not_saved'); }
            $stmt->close();
            self::assert_lock($connection);
            $stmt = $connection->prepare("SELECT option_value FROM `{$table}` WHERE option_name=?");
            $stmt->bind_param('s', $key); $stmt->execute(); $stmt->bind_result($stored); $found = $stmt->fetch(); $stmt->close();
            if (!$found || $stored !== $value) { throw new RuntimeException('state_not_saved'); }
            self::assert_lock($connection);
        } catch (Throwable $e) { throw new RuntimeException('connection_uncertain'); }
        wp_cache_delete(self::STATE, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }
    public static function state(): array {
        global $wpdb;
        $table = $wpdb->options;
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_$]+$/D', $table)) { throw new RuntimeException('invalid_table'); }
        // Do not rely on WordPress's persistent negative/alloptions caches for
        // cross-request coordination of this directly written option.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", self::STATE));
        if (!is_string($raw)) { return []; }
        $value = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($value) ? $value : [];
    }

    private static function maintenance_content(string $owner): string {
        // This file runs before plugins. Only the setup page and the two
        // authenticated AJAX actions can bootstrap WordPress during preparation.
        return '<?php' . "\n" . '// Primary ICT database preparation ' . $owner . "\n" . <<<'PHP'
unset($_REQUEST['wp_scrape_key'], $_REQUEST['wp_scrape_nonce']);
$picts_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$picts_action = $_POST['action'] ?? '';
$picts_ajax = $picts_script === 'admin-ajax.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($picts_action, ['picts_db_progress', 'picts_db_recover'], true)
    && ($_REQUEST['action'] ?? '') === $picts_action
    && (!isset($_GET['action']) || $_GET['action'] === $picts_action);
$picts_page = $picts_script === 'admin.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['page'] ?? '') === 'picts-connector';
$upgrading = ($picts_ajax || $picts_page) ? 0 : PHP_INT_MAX;
PHP;
    }
    private static function install_maintenance(string $owner): void {
        $target = ABSPATH . '.maintenance';
        $tmp = ABSPATH . '.picts-maintenance-' . $owner;
        if (file_exists($target) || is_link($target)) { throw new RuntimeException('maintenance_exists'); }
        $file = @fopen($tmp, 'x');
        if (!$file) { throw new RuntimeException('maintenance_unavailable'); }
        $content = self::maintenance_content($owner);
        $written = fwrite($file, $content); $flushed = fflush($file); fclose($file);
        if ($written !== strlen($content) || !$flushed || !@link($tmp, $target)) { @unlink($tmp); throw new RuntimeException('maintenance_unavailable'); }
        @unlink($tmp);
    }
    private static function remove_maintenance(string $owner, bool $allow_missing = false): bool {
        $path = ABSPATH . '.maintenance';
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) { return $allow_missing; }
        $before = @lstat($path);
        if (is_link($path) || @file_get_contents($path) !== self::maintenance_content($owner)) { return false; }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!$before || !$after || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] || $before['mtime'] !== $after['mtime'] || $before['size'] !== $after['size']) { return false; }
        return @unlink($path);
    }
    private static function maintenance_owned(string $owner): bool {
        $path = ABSPATH . '.maintenance'; clearstatcache(true, $path);
        return !is_link($path) && @file_get_contents($path) === self::maintenance_content($owner);
    }

    /** Never retries an ALTER. $execute is injected only by the isolated fixture. */
    public static function run(?callable $execute = null, string $request_id = ''): array {
        global $wpdb;
        if (is_multisite()) { throw new RuntimeException('multisite_unsupported'); }
        self::lock();
        $connection = $wpdb->dbh;
        $fixture = $execute !== null && !($connection instanceof mysqli);
        $owner = ''; $installed = false;
        try {
            self::assert_lock($connection, $fixture);
            if (file_exists(ABSPATH . '.maintenance') || is_link(ABSPATH . '.maintenance')) { throw new RuntimeException('maintenance_exists'); }
            if (in_array(self::state()['status'] ?? '', ['running','finishing','uncertain'], true)) { throw new RuntimeException('recovery_required'); }
            $tables = self::inspect();
            self::assert_lock($connection, $fixture);
            foreach ($tables as $table) { if (!in_array($table['engine'], ['innodb', 'myisam'], true)) { throw new RuntimeException('unsupported_engine'); } }
            if (!$execute) {
                $execute = static function (string $sql) use ($connection, $wpdb): bool {
                    if ($wpdb->dbh !== $connection) { throw new RuntimeException('connection_uncertain'); }
                    try {
                        if (mysqli_query($connection, $sql) === false) {
                            throw new RuntimeException(mysqli_errno($connection) === 1142 ? 'alter_denied' : 'connection_uncertain');
                        }
                        return true;
                    }
                    catch (Throwable $e) { throw new RuntimeException($e->getCode() === 1142 || $e->getMessage() === 'alter_denied' ? 'alter_denied' : 'connection_uncertain'); }
                };
            }
            $owner = bin2hex(random_bytes(16));
            $pending = array_values(array_filter($tables, static fn ($table) => $table['engine'] === 'myisam'));
            $state = ['status' => 'running', 'request_id' => $request_id, 'owner' => $owner, 'completed' => 0, 'total' => count($pending), 'current' => '', 'reason' => ''];
            self::save($state, $connection, $fixture);
            self::assert_lock($connection, $fixture);
            if (!$pending) { $state['status'] = 'complete'; $state['tables'] = $tables; self::save($state, $connection, $fixture); return $state; }
            self::install_maintenance($owner); $installed = true;
            foreach ($pending as $table) {
                $state['current'] = $table['name']; self::save($state, $connection, $fixture);
                self::assert_lock($connection, $fixture);
                if (!self::maintenance_owned($owner)) { throw new RuntimeException('maintenance_lost'); }
                if (!$execute('ALTER TABLE `' . $table['table'] . '` ENGINE=InnoDB')) { throw new RuntimeException('alter_failed'); }
                self::assert_lock($connection, $fixture);
                if (!self::maintenance_owned($owner)) { throw new RuntimeException('maintenance_lost'); }
                $fresh = self::inspect();
                self::assert_lock($connection, $fixture);
                $match = array_values(array_filter($fresh, static fn ($item) => $item['name'] === $table['name']));
                if (($match[0]['engine'] ?? '') !== 'innodb') { throw new RuntimeException('verification_failed'); }
                $state['completed']++; self::save($state, $connection, $fixture);
            }
            $state['tables'] = self::inspect();
            self::assert_lock($connection, $fixture);
            foreach ($state['tables'] as $table) { if ($table['engine'] !== 'innodb') { throw new RuntimeException('verification_failed'); } }
            $state['status'] = 'finishing'; $state['current'] = ''; self::save($state, $connection, $fixture);
        } catch (Throwable $e) {
            if (!$owner) { throw $e; }
            $state['status'] = in_array($e->getMessage(), ['connection_uncertain','maintenance_lost'], true) ? 'uncertain' : 'failed';
            $state['reason'] = in_array($e->getMessage(), ['connection_uncertain','maintenance_lost','alter_denied','alter_failed','verification_failed','state_not_saved','maintenance_exists','maintenance_unavailable'], true) ? $e->getMessage() : 'operation_failed';
            try { self::save($state, $connection, $fixture); } catch (Throwable $ignored) { $state['status'] = 'uncertain'; }
        } finally {
            if ($installed && ($state['status'] ?? 'uncertain') !== 'uncertain') {
                try { self::assert_lock($connection, $fixture); } catch (Throwable $ignored) { $state['status'] = 'uncertain'; $state['reason'] = 'connection_uncertain'; }
                if ($state['status'] === 'uncertain') { /* Keep maintenance until the operation is reviewed. */ }
                elseif (!self::remove_maintenance($owner)) { $state['status'] = 'uncertain'; $state['reason'] = 'maintenance_cleanup_failed'; }
                elseif ($state['status'] === 'finishing') { $state['status'] = 'complete'; }
                try { self::save($state, $connection, $fixture); } catch (Throwable $ignored) { $state['status'] = 'uncertain'; }
            }
            self::unlock($connection);
        }
        return $state;
    }

    public static function recover(?callable $fixture_guard = null): array {
        global $wpdb;
        self::lock();
        $connection = $wpdb->dbh;
        try {
            $guard = $fixture_guard ?? static function () use ($connection): void { self::assert_lock($connection); };
            $guard();
            $state = self::state();
            $owner = $state['owner'] ?? '';
            if (!preg_match('/^[a-f0-9]{32}$/D', $owner) || !in_array($state['status'] ?? '', ['running','finishing','uncertain'], true)) { throw new RuntimeException('no_recovery'); }
            $tables = self::inspect();
            $guard();
            if (!self::remove_maintenance($owner, true)) { throw new RuntimeException('maintenance_not_owned'); }
            $state['status'] = 'interrupted'; $state['reason'] = 'review_before_retry'; $state['tables'] = $tables; self::save($state, $connection, $fixture_guard !== null);
            return $state;
        } finally { self::unlock($connection); }
    }

    public static function init(): void {
        add_action('wp_ajax_picts_db_start', [self::class, 'ajax_start']);
        add_action('wp_ajax_picts_db_progress', [self::class, 'ajax_progress']);
        add_action('wp_ajax_picts_db_recover', [self::class, 'ajax_recover']);
    }
    private static function authorize(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Administrator access is required.'], 403); }
        check_ajax_referer('picts_database_prepare', 'nonce');
    }
    private static function safe_state(array $state): array {
        unset($state['owner']);
        if (($state['reason'] ?? '') === 'alter_denied') { $state['message'] = 'The WordPress database account cannot alter a required table. Ask your hosting provider for the ALTER permission or help preparing it.'; }
        return $state;
    }
    private static function release_upgrader_lock(string $name, string $expected): void {
        global $wpdb;
        $option = $name . '.lock';
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", $option, $expected));
        wp_cache_delete($option, 'options');
    }
    public static function ajax_start(): void {
        self::authorize();
        $id = sanitize_text_field(wp_unslash($_POST['request_id'] ?? ''));
        if (($_POST['backup'] ?? '') !== 'yes' || !preg_match('/^[a-f0-9-]{36}$/D', $id)) { wp_send_json_error(['message' => 'Confirm your database backup before starting.'], 400); }
        ignore_user_abort(true); @set_time_limit(0);
        try {
            $result = null;
            $locked = PICTS_Check_Jobs::with_lock('job', static function () use (&$result, $id): void {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                if (!WP_Upgrader::create_lock('auto_updater')) { throw new RuntimeException('updater_busy'); }
                $auto_lock = (string) get_option('auto_updater.lock');
                try {
                    if (!WP_Upgrader::create_lock('core_updater')) { throw new RuntimeException('updater_busy'); }
                    $core_lock = (string) get_option('core_updater.lock');
                    try { $result = self::run(null, $id); }
                    finally { self::release_upgrader_lock('core_updater', $core_lock); }
                } finally { self::release_upgrader_lock('auto_updater', $auto_lock); }
            });
            if (!$locked) { throw new RuntimeException('connector_busy'); }
            wp_send_json_success(self::safe_state($result));
        } catch (Throwable $e) { wp_send_json_error(['message' => self::message($e->getMessage())], 409); }
    }
    public static function ajax_progress(): void { self::authorize(); wp_send_json_success(self::safe_state(self::state())); }
    public static function ajax_recover(): void {
        self::authorize();
        try { wp_send_json_success(self::safe_state(self::recover())); }
        catch (Throwable $e) { wp_send_json_error(['message' => self::message($e->getMessage())], 409); }
    }
    private static function message(string $code): string {
        $messages = [
            'database_busy' => 'A database preparation is still running. Wait and check again.',
            'connector_busy' => 'Another connector job is running. Wait for it to finish.',
            'updater_busy' => 'Another WordPress update is running. Wait for it to finish.',
            'maintenance_exists' => 'A maintenance file already exists. Resolve the existing maintenance operation first.',
            'maintenance_unavailable' => 'The maintenance file could not be created. Ask your host to check the WordPress root directory permissions.',
            'unsupported_engine' => 'A required table has an unsupported or unreadable storage engine. Ask your hosting provider to inspect it.',
            'database_driver_unsupported' => 'This database connection is not supported for automatic preparation.',
            'multisite_unsupported' => 'This connector does not support WordPress multisite preparation.',
            'recovery_required' => 'A previous preparation needs to be checked before starting again.',
            'no_recovery' => 'There is no interrupted preparation to check.',
            'maintenance_not_owned' => 'The existing maintenance file is not the one created by this operation. Ask your hosting provider for help.',
        ];
        return $messages[$code] ?? 'Preparation could not be completed. Check the table status and ask your hosting provider if needed.';
    }

    public static function admin_section(): void {
        $state = self::state();
        try { $tables = self::inspect(); $error = ''; }
        catch (Throwable $e) { $tables = []; $error = self::message($e->getMessage()); }
        $needs = array_filter($tables, static fn ($t) => $t['engine'] !== 'innodb');
        $request_id = wp_generate_uuid4();
        echo '<section id="picts-db-preparation"><h2>Prepare the database for content editing</h2>';
        echo '<p>Content editing needs five WordPress tables to use InnoDB. Check their status below. This action only changes required MyISAM tables.</p>';
        if ($error) { echo '<p>' . esc_html($error) . '</p>'; }
        if ($tables) {
            echo '<ul>';
            foreach ($tables as $table) { echo '<li><code>' . esc_html($table['name']) . '</code>: <span data-picts-db-table="' . esc_attr($table['name']) . '">' . esc_html($table['engine']) . '</span></li>'; }
            echo '</ul>';
        }
        echo '<p><strong>Before you begin:</strong> make a database backup, confirm you can restore it, and check that no other updates are running. Visitors will see a maintenance message while the tables are prepared. Keep this page open until the process finishes.</p>';
        echo '<p>The time depends on table size, server load and other requests. Tables are changed one at a time, and progress can pause while WordPress waits for a table. Other page controls will be unavailable until preparation finishes.</p>';
        echo '<p><strong>Check for updates first:</strong> another update already running could interrupt maintenance. Cached pages, existing requests and other applications can still access the site or database.</p>';
        echo '<label><input id="picts-db-backup" type="checkbox"> I have a restorable database backup and no other updates are running.</label>';
        echo '<p><button type="button" class="button button-primary" id="picts-db-start"' . (!$needs || $error || is_multisite() ? ' disabled' : '') . '>Prepare database</button></p>';
        echo '<p id="picts-db-message" role="status" aria-live="polite">' . (!$needs && !$error ? 'The required tables are ready.' : 'No changes will be made until you start.') . '</p>';
        echo '<label for="picts-db-progress">Tables completed</label> <progress id="picts-db-progress" max="1" value="0"></progress>';
        echo '<p id="picts-db-detail"></p><p><button type="button" class="button" id="picts-db-recover">Check an interrupted preparation</button></p>';
        echo '<p class="description">If the connection is lost, use the check button before trying again. It will not interrupt an active conversion. If you cannot open this page, ask your host to check whether a conversion is still running and help remove the connector-owned .maintenance file.</p></section>';
        $config = ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('picts_database_prepare'), 'requestId' => $request_id, 'state' => self::safe_state($state)];
        echo '<script>window.pictsDatabasePreparation=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        echo '<script>' . file_get_contents(__DIR__ . '/assets/database-preparation.js') . '</script>';
    }
}
