<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
wp_clear_scheduled_hook('picts_connector_inventory');
wp_clear_scheduled_hook('picts_connector_inventory_resume');
delete_option('picts_connector_settings');
delete_option('picts_connector_last_result');
delete_option('picts_connector_pending_inventory');

wp_clear_scheduled_hook('picts_connector_poll');
delete_option('picts_connector_pending_job');
delete_option('picts_connector_last_poll');
delete_option('picts_connector_lock_job');
delete_option('picts_connector_lock_inventory');
wp_clear_scheduled_hook('picts_connector_job_resume');
