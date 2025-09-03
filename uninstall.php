<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpui_fulltext_index");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpui_instruction_index");
