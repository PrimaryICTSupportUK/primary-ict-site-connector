<?php
if (!defined('ABSPATH')) { exit; }
/** Typed outbound content jobs. No remote PHP, SQL, callbacks or URL fetching. */
final class PICTS_Content_Jobs {
    private const PENDING = 'picts_connector_content_pending';
    private const RECEIPTS = 'picts_connector_content_receipts';
    private const READS = ['list', 'get', 'metadata', 'media'];
    private const ACTIONS = ['list', 'get', 'metadata', 'media', 'create_draft', 'update_draft', 'upload_image', 'publish', 'schedule', 'update_published', 'trash', 'restore'];
    private static function short(string $value, int $length): string {
        if (function_exists('mb_substr')) { return mb_substr($value, 0, $length, 'UTF-8'); }
        return preg_match('/^.{0,' . $length . '}/us', $value, $matches) ? $matches[0] : '';
    }
    /** Match JavaScript/Zod string lengths (UTF-16 code units), without requiring mbstring. */
    private static function units(string $value): int {
        if (preg_match_all('/./us', $value, $characters) === false) { return PHP_INT_MAX; }
        $units = 0;
        foreach ($characters[0] as $character) { $units += strlen($character) === 4 ? 2 : 1; }
        return $units;
    }
    private static function request(array $body): array {
        $s = (array) get_option('picts_connector_settings', []);
        $r = wp_safe_remote_post($s['service_url'] . '/api/plugin/content', [
            'timeout' => 25, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $s['token']],
            'body' => wp_json_encode(array_merge(['site_id' => $s['site_id'], 'plugin_version' => '0.8.0'], $body)),
            'limit_response_size' => 3100000,
        ]);
        return is_wp_error($r) ? ['code' => 0, 'data' => []] : ['code' => wp_remote_retrieve_response_code($r), 'data' => (array) json_decode(wp_remote_retrieve_body($r), true)];
    }
    private static function message(array $p, string $operation, array $extra = []): array {
        return self::request(array_merge(['operation' => $operation, 'job_id' => $p['job']['id'], 'lease_token' => $p['lease']], $extra));
    }
    private static function persist(array $pending): void {
        update_option(self::PENDING, $pending, false);
        if (get_option(self::PENDING) !== $pending) { throw new RuntimeException('state_not_persisted'); }
    }
    private static function receipt(string $id, array $value): void {
        $receipts = (array) get_option(self::RECEIPTS, []);
        $receipts[$id] = $value + ['at' => time()];
        // Service leases expire within a day; old completed IDs cannot receive a new grant.
        if (count($receipts) > 100) {
            foreach ($receipts as $key => $item) {
                if (count($receipts) <= 100) { break; }
                if (($item['status'] ?? '') === 'completed') { unset($receipts[$key]); }
            }
        }
        update_option(self::RECEIPTS, $receipts, false);
        if (get_option(self::RECEIPTS) !== $receipts) { throw new RuntimeException('state_not_persisted'); }
    }
    /** Called only while the existing connector's shared job lock is held. */
    public static function tick(): bool {
        $p = (array) get_option(self::PENDING, []);
        if (!$p) {
            $r = self::request(['operation' => 'poll', 'cron_context' => wp_doing_cron()]);
            if ($r['code'] !== 200) { return false; }
            $job = $r['data']['job'] ?? null;
            if (!$job) { return false; }
            if (!preg_match('/^[a-f0-9-]{36}$/i', $job['id'] ?? '') || !in_array($job['action'] ?? '', self::ACTIONS, true) || !is_array($job['payload'] ?? null) || !preg_match('/^picts_content_[A-Za-z0-9_-]{43}$/', $r['data']['lease_token'] ?? '')) { return true; }
            $p = ['job' => $job, 'lease' => $r['data']['lease_token'], 'stage' => 'execute'];
            self::persist($p);
        }
        $heartbeat = self::message($p, 'heartbeat');
        if (in_array($heartbeat['code'], [401, 409], true)) {
            if (!empty($p['write_attempted'])) { self::receipt($p['job']['id'], ['status' => 'uncertain', 'reason' => 'lease_lost']); }
            delete_option(self::PENDING); return true;
        }
        if ($heartbeat['code'] !== 200) { return true; }
        $id = $p['job']['id'];
        $old = ((array) get_option(self::RECEIPTS, []))[$id] ?? null;
        $original_user = get_current_user_id();
        try {
            if (is_multisite()) { throw new RuntimeException('unsupported_site'); }
            if ($p['job']['action'] !== 'metadata') {
                $author = (int) ($p['job']['author_id'] ?? 0);
                if ($author < 1 || !get_userdata($author)) { throw new RuntimeException('author_required'); }
                wp_set_current_user($author);
                if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) { throw new RuntimeException('author_not_allowed'); }
            }
            if ($p['stage'] === 'finished') {
                // A lost report response must never re-enter the write API.
            } elseif ($old && ($old['status'] ?? '') === 'completed') {
                $p['result'] = $old['result']; $p['status'] = 'completed'; $p['stage'] = 'finished';
            } elseif ($p['stage'] === 'verify') {
                $result = $p['result'];
                if (isset($result['post'])) {
                    $fresh = self::post((int) $result['post']['id'], true);
                    if (!hash_equals($result['post']['fingerprint'], $fresh['fingerprint'])) { throw new RuntimeException('verification_changed'); }
                    $result['post'] = $fresh;
                } elseif (!empty($result['media'][0])) {
                    $media = get_post((int) $result['media'][0]['id']);
                    if (!$media || $media->post_type !== 'attachment' || !wp_attachment_is_image($media->ID) || get_post_meta($media->ID,'_wp_attachment_image_alt',true) !== $result['media'][0]['alt_text'] || (array_key_exists('caption',$result['media'][0]) && $media->post_excerpt !== $result['media'][0]['caption'])) { throw new RuntimeException('verification_changed'); }
                } else { throw new RuntimeException('verification_failed'); }
                $p['result'] = $result; $p['status'] = 'completed'; $p['stage'] = 'finished';
                self::receipt($id, ['status' => 'completed', 'result' => $result]);
            } elseif (!empty($p['write_attempted']) || ($old && ($old['status'] ?? '') !== 'completed')) {
                throw new RuntimeException('interrupted_write');
            } elseif (in_array($p['job']['action'], self::READS, true)) {
                $p['result'] = self::read($p['job']); $p['status'] = 'completed'; $p['stage'] = 'finished';
            } else {
                self::validate($p['job']);
                if ($p['job']['action'] !== 'upload_image') { self::require_transactions(); }
                // Persist intent before asking for a single-use service write grant.
                $p['write_attempted'] = true; self::persist($p);
                self::receipt($id, ['status' => 'writing', 'fingerprint' => $p['job']['fingerprint']]);
                $grant = self::message($p, 'begin');
                if ($grant['code'] !== 200 || empty($grant['data']['permitted'])) { throw new RuntimeException('grant_not_confirmed'); }
                $p['result'] = self::write($p['job']);
                $p['stage'] = 'verify';
                self::receipt($id, ['status' => 'verifying', 'result' => $p['result']]);
            }
        } catch (Throwable $error) {
            $known = ['state_not_persisted','unsupported_site','author_required','author_not_allowed','not_found','content_too_large','permission_denied','content_changed','wrong_status','invalid_payload','invalid_terms','invalid_image','invalid_featured_image','transaction_unavailable','write_failed','verification_changed','verification_failed','interrupted_write','grant_not_confirmed'];
            $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'content_worker_error';
            $p['status'] = !empty($p['write_attempted']) ? 'uncertain' : 'failed';
            $p['result'] = ['reason' => $reason]; $p['stage'] = 'finished';
            if (!empty($p['write_attempted'])) { self::receipt($id, ['status' => 'uncertain', 'reason' => $reason]); }
        } finally { wp_set_current_user($original_user); }
        self::persist($p);
        if ($p['stage'] === 'finished') {
            $r = self::message($p, 'report', ['status' => $p['status'], 'result' => $p['result']]);
            if ($r['code'] === 200 || in_array($r['code'], [401,409], true)) { delete_option(self::PENDING); }
        }
        return true;
    }
    private static function post(int $id, bool $full = false): array {
        clean_post_cache($id); wp_cache_delete($id, 'post_meta');
        $p = get_post($id);
        if (!$p || !in_array($p->post_type, ['post','page'], true) || !current_user_can('read_post', $id)) { throw new RuntimeException('not_found'); }
        $editable = strlen($p->post_content) <= 65536 && self::units($p->post_title) <= 300 && self::units($p->post_excerpt) <= 2000;
        if ($full && !$editable) { throw new RuntimeException('content_too_large'); }
        $categories = $p->post_type === 'post' ? array_map('intval', wp_get_post_categories($id)) : [];
        $tags = $p->post_type === 'post' ? array_map('intval', wp_get_post_tags($id, ['fields' => 'ids'])) : [];
        sort($categories); sort($tags);
        $featured = (int) get_post_thumbnail_id($id);
        $hash = hash('sha256', wp_json_encode([$p->ID,$p->post_type,$p->post_status,$p->post_title,$p->post_content,$p->post_excerpt,$p->post_name,$p->post_author,$p->post_modified_gmt,$p->post_date_gmt,$categories,$tags,$featured]));
        $out = ['id' => $id, 'post_type' => $p->post_type, 'status' => $p->post_status, 'title' => $full ? $p->post_title : self::short($p->post_title,300), 'slug' => $p->post_name, 'editable' => $editable, 'excerpt' => $full ? $p->post_excerpt : self::short($p->post_excerpt,240), 'modified_gmt' => $p->post_modified_gmt, 'fingerprint' => $hash, 'author_id' => (int) $p->post_author, 'category_ids' => $categories, 'tag_ids' => $tags, 'featured_media_id' => $featured ?: null, 'url' => set_url_scheme((string) get_permalink($id),'https'), 'edit_url' => set_url_scheme(admin_url('post.php?post=' . $id . '&action=edit'),'https'), 'preview_url' => set_url_scheme((string) get_preview_post_link($id),'https')];
        if ($full) { $out['content'] = $p->post_content; }
        return $out;
    }
    private static function read(array $job): array {
        $p = $job['payload']; $action = $job['action'];
        if ($action === 'get') { return ['post' => self::post((int) ($p['post_id'] ?? 0), true)]; }
        if ($action === 'metadata') {
            $authors = [];
            foreach (get_users(['number' => 100, 'orderby' => 'ID', 'fields' => ['ID','display_name']]) as $user) {
                if (user_can($user->ID,'edit_posts') || user_can($user->ID,'edit_pages')) { $authors[] = ['id' => (int) $user->ID, 'name' => self::short($user->display_name,150)]; }
            }
            $result = ['authors' => $authors, 'metadata_has_more' => count($authors) >= 100, 'database_tables' => self::database_tables()];
            foreach (['category' => 'categories','post_tag' => 'tags'] as $taxonomy => $key) {
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 100]);
                if (!is_wp_error($terms) && count($terms) >= 100) { $result['metadata_has_more'] = true; }
                $result[$key] = is_wp_error($terms) ? [] : array_map(static fn ($term) => ['id' => (int) $term->term_id, 'name' => self::short($term->name,150)], $terms);
            }
            return $result;
        }
        $page = max(1,min(1000,(int) ($p['page'] ?? 1))); $search = self::short((string) ($p['search'] ?? ''),150);
        if ($action === 'media') {
            if (!current_user_can('upload_files')) { throw new RuntimeException('permission_denied'); }
            $q = new WP_Query(['post_type' => 'attachment','post_status' => 'inherit','post_mime_type' => 'image','posts_per_page' => 50,'paged' => $page,'s' => $search]);
            return ['media' => array_map(static fn ($item) => ['id' => (int) $item->ID,'title' => self::short($item->post_title,300),'url' => set_url_scheme((string) wp_get_attachment_url($item->ID),'https'),'alt_text' => self::short((string) get_post_meta($item->ID,'_wp_attachment_image_alt',true),500)],$q->posts),'page' => $page,'has_more' => $page < $q->max_num_pages];
        }
        $type = $p['post_type'] ?? 'post'; $status = $p['status'] ?? 'any';
        if (!in_array($type,['post','page'],true) || !in_array($status,['any','draft','pending','publish','private','future','trash'],true)) { throw new RuntimeException('invalid_payload'); }
        $q = new WP_Query(['post_type' => $type,'post_status' => $status,'perm' => 'readable','posts_per_page' => 50,'paged' => $page,'s' => $search,'orderby' => 'modified','order' => 'DESC']);
        $items = [];
        foreach ($q->posts as $item) {
            if (!current_user_can('read_post',$item->ID)) { continue; }
            $items[] = self::post($item->ID);
        }
        return ['items' => $items, 'page' => $page, 'has_more' => $page < $q->max_num_pages];
    }
    private static function validate(array $job): void {
        $p = $job['payload']; $action = $job['action'];
        $fields = ['title','content','excerpt','slug','category_ids','tag_ids','featured_media_id'];
        $allowed = match ($action) {
            'create_draft' => array_merge($fields,['post_type']),
            'update_draft','update_published' => array_merge($fields,['post_id','expected_hash']),
            'publish','trash','restore' => ['post_id','expected_hash'],
            'schedule' => ['post_id','expected_hash','publish_at'],
            'upload_image' => ['filename','mime_type','data_base64','alt_text','caption'],
            default => [],
        };
        if (array_diff(array_keys($p),$allowed)) { throw new RuntimeException('invalid_payload'); }
        foreach (['title' => 1200,'content' => 65536,'excerpt' => 8000,'slug' => 200] as $key => $max) {
            if (isset($p[$key]) && (!is_string($p[$key]) || strlen($p[$key]) > $max || str_contains($p[$key],"\0"))) { throw new RuntimeException('invalid_payload'); }
        }
        if ((isset($p['title']) && (self::units($p['title']) > 300 || trim($p['title']) === '')) || (isset($p['excerpt']) && self::units($p['excerpt']) > 2000)) { throw new RuntimeException('invalid_payload'); }
        if (isset($p['post_id']) && (!is_int($p['post_id']) || $p['post_id'] < 1)) { throw new RuntimeException('invalid_payload'); }
        if ($action !== 'create_draft' && $action !== 'upload_image' && (!preg_match('/^[a-f0-9]{64}$/',$p['expected_hash'] ?? '') || empty($p['post_id']))) { throw new RuntimeException('invalid_payload'); }
        if ($action === 'create_draft' && (!in_array($p['post_type'] ?? '',['post','page'],true) || !is_string($p['title'] ?? null) || trim($p['title']) === '' || !is_string($p['content'] ?? null))) { throw new RuntimeException('invalid_payload'); }
        if ($action === 'schedule' && (!is_string($p['publish_at'] ?? null) || !strtotime($p['publish_at']) || strtotime($p['publish_at']) > time()+60)) { throw new RuntimeException('invalid_payload'); }
    }
    private static function database_tables(): array {
        global $wpdb;
        $tables = [];
        foreach (['posts','postmeta','options','term_relationships','term_taxonomy'] as $name) {
            $engine = strtolower((string) $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->$name)));
            $tables[] = ['table' => $name, 'engine' => in_array($engine,['innodb','myisam','aria','memory'],true) ? $engine : ($engine === '' ? 'unavailable' : 'other'), 'transactional' => $engine === 'innodb'];
        }
        return $tables;
    }
    private static function require_transactions(): void {
        foreach (self::database_tables() as $table) {
            if (!$table['transactional']) { throw new RuntimeException('transaction_unavailable'); }
        }
    }
    private static function transaction(): void {
        global $wpdb;
        self::require_transactions();
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('transaction_unavailable'); }
    }
    private static function write(array $job): array {
        global $wpdb;
        $p = $job['payload']; $action = $job['action'];
        if ($action === 'upload_image') { return self::upload($p,$job['id']); }
        self::transaction();
        $id = (int) ($p['post_id'] ?? 0);
        try {
            if ($id) {
                if (!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id))) { throw new RuntimeException('not_found'); }
                $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE",$id));
                $wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id=%d FOR UPDATE",$id));
                $before = self::post($id,true);
                if (!hash_equals($p['expected_hash'],$before['fingerprint'])) { throw new RuntimeException('content_changed'); }
                if (!current_user_can('edit_post',$id)) { throw new RuntimeException('permission_denied'); }
                if ($action === 'update_draft' && !in_array($before['status'],['draft','pending'],true)) { throw new RuntimeException('wrong_status'); }
                if ($action === 'update_published' && $before['status'] !== 'publish') { throw new RuntimeException('wrong_status'); }
                if (in_array($action,['publish','schedule'],true) && !in_array($before['status'],['draft','pending'],true)) { throw new RuntimeException('wrong_status'); }
                $type = $before['post_type'];
            } else { $type = $p['post_type']; }
            $object = get_post_type_object($type);
            if (!$object || !current_user_can($object->cap->create_posts)) { throw new RuntimeException('permission_denied'); }
            if (in_array($action,['publish','schedule','update_published','trash','restore'],true) && !current_user_can($object->cap->publish_posts)) { throw new RuntimeException('permission_denied'); }
            if (isset($p['featured_media_id']) && $p['featured_media_id'] !== null && (!is_int($p['featured_media_id']) || !wp_attachment_is_image($p['featured_media_id']) || !current_user_can('read_post',$p['featured_media_id']))) { throw new RuntimeException('invalid_featured_image'); }
            foreach (['category_ids' => 'category','tag_ids' => 'post_tag'] as $key => $taxonomy) {
                if (!array_key_exists($key,$p)) { continue; }
                if (!is_array($p[$key]) || count($p[$key]) > 30 || ($type !== 'post' && $p[$key])) { throw new RuntimeException('invalid_terms'); }
                if ($p[$key] && !current_user_can(get_taxonomy($taxonomy)->cap->assign_terms)) { throw new RuntimeException('permission_denied'); }
                foreach ($p[$key] as $term) { if (!is_int($term) || !term_exists($term,$taxonomy)) { throw new RuntimeException('invalid_terms'); } }
            }
            $data = $id ? ['ID' => $id] : ['post_type' => $type,'post_author' => get_current_user_id(),'post_status' => 'draft'];
            $sanitized = false;
            foreach (['title' => 'post_title','content' => 'post_content','excerpt' => 'post_excerpt','slug' => 'post_name'] as $key => $field) {
                if (!array_key_exists($key,$p)) { continue; }
                $value = in_array($key,['content','excerpt'],true) ? wp_kses_post($p[$key]) : ($key === 'slug' ? sanitize_title($p[$key]) : sanitize_text_field($p[$key]));
                $sanitized = $sanitized || $value !== $p[$key]; $data[$field] = $value;
            }
            if (in_array($action,['publish','schedule'],true)) { $data['post_status'] = 'publish'; $data['post_date'] = current_time('mysql'); $data['post_date_gmt'] = current_time('mysql',true); }
            if ($action === 'trash') {
                if (!defined('EMPTY_TRASH_DAYS') || EMPTY_TRASH_DAYS < 1 || !current_user_can('delete_post',$id) || $before['status'] === 'trash') { throw new RuntimeException('permission_denied'); }
                if (!wp_trash_post($id)) { throw new RuntimeException('write_failed'); }
            } elseif ($action === 'restore') {
                if ($before['status'] !== 'trash' || !current_user_can('delete_post',$id) || !wp_untrash_post($id)) { throw new RuntimeException('wrong_status'); }
            } else {
                $saved = $id ? wp_update_post(wp_slash($data),true) : wp_insert_post(wp_slash($data),true);
                if (is_wp_error($saved) || !$saved) { throw new RuntimeException('write_failed'); }
                $id = (int) $saved;
                foreach (['category_ids' => 'category','tag_ids' => 'post_tag'] as $key => $taxonomy) {
                    if (array_key_exists($key,$p) && $type === 'post') { $set = wp_set_post_terms($id,$p[$key],$taxonomy,false); if (is_wp_error($set)) { throw new RuntimeException('write_failed'); } }
                }
                if (array_key_exists('featured_media_id',$p)) {
                    if ($p['featured_media_id'] === null) { delete_post_thumbnail($id); } else { set_post_thumbnail($id,$p['featured_media_id']); }
                    if ((int) get_post_thumbnail_id($id) !== (int) ($p['featured_media_id'] ?? 0)) { throw new RuntimeException('write_failed'); }
                }
            }
            update_post_meta($id,'_picts_content_job_id',$job['id']);
            $out = ['post' => self::post($id,true), 'sanitized' => $sanitized];
            if ($out['post']['post_type'] !== $type || ($action === 'create_draft' && ($out['post']['status'] !== 'draft' || $out['post']['author_id'] !== get_current_user_id())) || ($action === 'update_draft' && !in_array($out['post']['status'],['draft','pending'],true)) || (in_array($action,['publish','schedule','update_published'],true) && $out['post']['status'] !== 'publish') || ($action === 'trash' && $out['post']['status'] !== 'trash') || ($action === 'restore' && $out['post']['status'] === 'trash')) { throw new RuntimeException('verification_changed'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('write_failed'); }
            return $out;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); if ($id) { clean_post_cache($id); } throw $error; }
    }
    private static function upload(array $p, string $job_id): array {
        if (!current_user_can('upload_files') || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(jpg|jpeg|png|webp)$/i',$p['filename'] ?? '') || !in_array($p['mime_type'] ?? '',['image/jpeg','image/png','image/webp'],true)) { throw new RuntimeException('invalid_image'); }
        $bytes = base64_decode($p['data_base64'] ?? '',true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 2097152 || base64_encode($bytes) !== $p['data_base64']) { throw new RuntimeException('invalid_image'); }
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam($p['filename']);
        if (!$tmp || file_put_contents($tmp,$bytes) !== strlen($bytes)) { if ($tmp) { wp_delete_file($tmp); } throw new RuntimeException('invalid_image'); }
        try {
            $size = wp_getimagesize($tmp); $check = wp_check_filetype_and_ext($tmp,$p['filename']);
            if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0]*$size[1] > 16000000 || wp_get_image_mime($tmp) !== $p['mime_type'] || ($check['type'] ?? '') !== $p['mime_type']) { throw new RuntimeException('invalid_image'); }
            $alt = sanitize_text_field($p['alt_text'] ?? ''); $caption = sanitize_textarea_field($p['caption'] ?? '');
            $id = media_handle_sideload(['name' => sanitize_file_name($p['filename']),'tmp_name' => $tmp],0,null,['post_author' => get_current_user_id(),'post_excerpt' => wp_slash($caption)]);
            if (is_wp_error($id)) { throw new RuntimeException('write_failed'); }
            update_post_meta($id,'_picts_content_job_id',$job_id);
            update_post_meta($id,'_wp_attachment_image_alt',wp_slash($alt));
            $saved_alt = (string) get_post_meta($id,'_wp_attachment_image_alt',true); $saved_caption = (string) get_post_field('post_excerpt',$id,'raw');
            if ($saved_alt !== $alt || $saved_caption !== $caption) { throw new RuntimeException('verification_changed'); }
            return ['media' => [['id' => (int) $id,'title' => get_the_title($id),'url' => set_url_scheme((string) wp_get_attachment_url($id),'https'),'alt_text' => $saved_alt,'caption' => $saved_caption]]];
        } finally { if (file_exists($tmp)) { wp_delete_file($tmp); } }
    }
}
