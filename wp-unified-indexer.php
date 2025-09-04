<?php
/**
 * Plugin Name: WP Unified Indexer
 * Description: Indexador unificado (Fulltext e Estrutura) com batch 10/10, reindex seletiva, termos PT-BR, Auditoria e fallback AJAX.
 * Version: 2.7.2
 * Author: Eduardo Vieira
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Text Domain: wp-unified-indexer
 */

defined('ABSPATH') || exit;

if (!defined('WPUI_VERSION')) define('WPUI_VERSION', '2.7.2');
if (!defined('WPUI_FILE')) define('WPUI_FILE', __FILE__);
if (!defined('WPUI_DIR'))  define('WPUI_DIR', plugin_dir_path(__FILE__));
if (!defined('WPUI_URL'))  define('WPUI_URL', plugin_dir_url(__FILE__));

// Tabelas na ativação
register_activation_hook(__FILE__, function(){
    require_once WPUI_DIR . 'includes/class-fulltext-indexer.php';
    require_once WPUI_DIR . 'includes/class-instruction-indexer.php';
    (new \WPUI\Fulltext_Indexer())->maybe_install();
    (new \WPUI\Instruction_Indexer())->maybe_install();
});

// Includes
require_once WPUI_DIR . 'includes/class-unified-admin.php';
require_once WPUI_DIR . 'includes/class-unified-rest-api.php';
require_once WPUI_DIR . 'includes/class-fulltext-indexer.php';
require_once WPUI_DIR . 'includes/class-instruction-indexer.php';
require_once WPUI_DIR . 'includes/class-fulltext-index-table.php';
require_once WPUI_DIR . 'includes/class-instruction-index-table.php';
require_once WPUI_DIR . 'includes/class-instruction-audit-table.php';

// Bootstrap
add_action('plugins_loaded', function(){
    if (is_admin()) { \WPUI\Admin::init(); }
    \WPUI\REST::init();
});

// Marca posts como pendentes quando forem atualizados
add_action('save_post', function($post_id, $post){
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    (new \WPUI\Fulltext_Indexer())->mark_post_pending($post_id);
    (new \WPUI\Instruction_Indexer())->mark_post_pending($post_id);
}, 20, 2);
