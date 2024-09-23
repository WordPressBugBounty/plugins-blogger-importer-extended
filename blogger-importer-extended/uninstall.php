<?php

if (!defined('WP_UNINSTALL_PLUGIN') || !WP_UNINSTALL_PLUGIN || dirname(WP_UNINSTALL_PLUGIN) != dirname(plugin_basename(__FILE__))) {
    status_header(404);
    die;
}

delete_option('bie_license');
delete_option('bie_hide_setup_notice');

global $wpdb;
$results = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'bie_page_token_%'");
foreach ($results as $result) {
	delete_option($result->option_name);
}

/*
// Do we want to delete the redirects on uninstall?
// Todo - add option to settings page
global $wpdb;
$table_name = $wpdb->prefix.'bie_redirects';
$wpdb->query("DROP TABLE IF EXISTS $table_name");
*/