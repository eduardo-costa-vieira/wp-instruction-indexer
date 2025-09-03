<?php
namespace WPUI;
defined('ABSPATH') || exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Fulltext_Index_Table
 * Autor: Eduardo Vieira
 * Versão: 2.7.2
 *
 * Lista o índice Fulltext com paginação e busca.
 */
class Fulltext_Index_Table extends \WP_List_Table {
    public $counts = ['published'=>0,'indexed'=>0,'pending'=>0];

    public function __construct(){
        parent::__construct([
            'singular' => 'ft',
            'plural'   => 'fts',
            'ajax'     => false,
        ]);
    }

    public function get_columns(){
        return [
            'cb'                      => '<input type="checkbox" />',
            'post_id'                 => __('ID','wp-unified-indexer'),
            'post_title'              => __('Título','wp-unified-indexer'),
            'post_url'                => __('Link','wp-unified-indexer'),
            'total_words'             => __('Total Palavras','wp-unified-indexer'),
            'last_post_modified_gmt'  => __('Última Atualização','wp-unified-indexer'),
            'indexed_at_gmt'          => __('Última Indexação','wp-unified-indexer'),
            'mode'                    => __('Modo','wp-unified-indexer'),
            'status'                  => __('Status','wp-unified-indexer'),
        ];
    }

    protected function column_cb($item){ return '<input type="checkbox" />'; }
    protected function column_post_id($item){ return intval($item['post_id']); }
    protected function column_post_title($item){ return esc_html($item['post_title']); }
    protected function column_post_url($item){
        $u = esc_url($item['post_url']);
        return $u ? '<a class="button button-small" target="_blank" href="'.$u.'">'.__('Abrir','wp-unified-indexer').'</a>' : '';
    }
    protected function column_default($item, $column_name){
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }

    public function get_sortable_columns(){
        return [
            'post_id'                => ['post_id', true],
            'post_title'             => ['post_title', false],
            'last_post_modified_gmt' => ['last_post_modified_gmt', false],
            'indexed_at_gmt'         => ['indexed_at_gmt', false],
        ];
    }

    public function prepare_items(){
        global $wpdb;

        // counters
        $idx = new Fulltext_Indexer();
        $this->counts = $idx->stats();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page = 12;
        $paged    = $this->get_pagenum();
        $offset   = ($paged-1)*$per_page;

        $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby  = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'post_id';
        $order    = (isset($_GET['order']) && strtolower($_GET['order'])==='asc') ? 'ASC' : 'DESC';

        $t = $wpdb->prefix.'wpui_fulltext_index';
        $where = 'WHERE 1=1';
        $args  = [];

        if ($search){
            $where .= " AND post_title LIKE %s";
            $args[] = '%'.$wpdb->esc_like($search).'%';
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS
                   post_id, post_title, post_url, total_words,
                   last_post_modified_gmt, indexed_at_gmt, mode, status
                FROM {$t}
                {$where}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $args = array_merge($args, [ $per_page, $offset ]);
        $this->items = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        $total_items = intval($wpdb->get_var("SELECT FOUND_ROWS()"));
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);
    }

    public function no_items(){
        _e('Nenhuma instrução indexada ainda.','wp-unified-indexer');
    }
}
