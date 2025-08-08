<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'instrucao_index';
$wpdb->query( "DROP TABLE IF EXISTS $table" );