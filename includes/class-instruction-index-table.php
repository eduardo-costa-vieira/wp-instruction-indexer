<?php
namespace WPUI;
defined('ABSPATH') || exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Instruction_Index_Table
 * Autor: Eduardo Vieira
 * Versão: 2.7.2
 *
 * Lista os itens indexados da Estrutura com as colunas solicitadas
 * e ações de edição/salvamento/exclusão de sinônimos.
 */
class Instruction_Index_Table extends \WP_List_Table {
    public $counts = ['published'=>0,'indexed'=>0,'pending'=>0];

    public function __construct(){
        parent::__construct([
            'singular' => 'st',
            'plural'   => 'sts',
            'ajax'     => false,
        ]);
    }

    public function get_columns(){
        return [
            'cb'                      => '<input type="checkbox" />',
            'post_id'                 => __('ID','wp-unified-indexer'),
            'post_title'              => __('Título da Instrução','wp-unified-indexer'),
            'item_count'              => __('Itens (≥3)','wp-unified-indexer'),
            'last_post_modified_gmt'  => __('Última Atualização','wp-unified-indexer'),
            'indexed_at_gmt'          => __('Última Indexação','wp-unified-indexer'),
            'mode'                    => __('Modo','wp-unified-indexer'),
            'status'                  => __('Status','wp-unified-indexer'),
            'item_title'              => __('Título do Item','wp-unified-indexer'),
            'url'                     => __('Link do Item','wp-unified-indexer'),
            'terms'                   => __('Termos','wp-unified-indexer'),
            'synonyms'                => __('Sinônimos','wp-unified-indexer'),
            'expand'                  => __('Expandir','wp-unified-indexer'),
            'actions'                 => __('Ações','wp-unified-indexer'),
        ];
    }

    protected function column_cb($item){ return '<input type="checkbox" />'; }
    protected function column_post_id($item){ return intval($item['post_id']); }
    protected function column_post_title($item){ return esc_html($item['post_title']); }
    protected function column_item_count($item){ return intval($item['item_count']); }
    protected function column_last_post_modified_gmt($item){ return esc_html($item['last_post_modified_gmt']); }
    protected function column_indexed_at_gmt($item){ return esc_html($item['indexed_at_gmt']); }
    protected function column_mode($item){ return esc_html($item['mode']); }
    protected function column_status($item){ return esc_html($item['status']); }
    protected function column_item_title($item){ return esc_html($item['item_title']); }

    protected function column_url($item){
        $u = esc_url($item['url']);
        return $u ? '<a class="button button-small" target="_blank" href="'.$u.'">'.__('Abrir','wp-unified-indexer').'</a>' : '';
    }
    protected function column_terms($item){ return esc_html($item['terms']); }

    protected function column_synonyms($item){
        $syn = isset($item['synonyms']) ? $item['synonyms'] : '';
        $pid = intval($item['post_id']);
        $iid = esc_attr($item['item_id']);
        return '<input type="text" class="wpui-syn" data-post="'.$pid.'" data-item="'.$iid.'" value="'.esc_attr($syn).'" style="width: 240px" />';
    }

    protected function column_actions($item){
        $pid = intval($item['post_id']);
        $iid = esc_attr($item['item_id']);
        return '<a href="#" class="button button-small wpui-syn-edit" data-post="'.$pid.'" data-item="'.$iid.'">'.__('Editar','wp-unified-indexer').'</a> '
             . '<a href="#" class="button button-primary button-small wpui-syn-save" data-post="'.$pid.'" data-item="'.$iid.'">'.__('Salvar','wp-unified-indexer').'</a> '
             . '<a href="#" class="button button-small wpui-syn-del" data-post="'.$pid.'" data-item="'.$iid.'">'.__('Excluir','wp-unified-indexer').'</a>';
    }

    protected function column_expand($item){
        $pid = intval($item['post_id']);
        return '<a href="#" class="button button-small wpui-expand" data-post="'.$pid.'">'.__('Expandir','wp-unified-indexer').'</a>';
    }

    public function prepare_items(){
        global $wpdb;

        // counters
        $idx = new Instruction_Indexer();
        $this->counts = $idx->stats();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = [];
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page = 12;
        $paged    = $this->get_pagenum();
        $offset   = ($paged-1)*$per_page;

        $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $t = $wpdb->prefix.'wpui_instruction_index';
        $where = "WHERE s.status='indexed'";
        $args  = [];

        if ($search){
            // busca pelo título do post
            $where .= " AND s.post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s)";
            $args[] = '%'.$wpdb->esc_like($search).'%';
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS
                    s.post_id, s.item_id, s.item_title, s.url, s.terms,
                    COALESCE(s.synonyms,'') AS synonyms, s.indexed_at_gmt, s.status,
                    COALESCE(s.mode,'auto') AS mode,
                    p.post_title, p.post_modified_gmt AS last_post_modified_gmt,
                    (SELECT COUNT(*) FROM {$t} WHERE post_id=s.post_id AND status='indexed') AS item_count
                FROM {$t} s
                JOIN {$wpdb->posts} p ON p.ID = s.post_id
                {$where}
                ORDER BY s.post_id DESC, s.item_id ASC
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
        _e('Nenhum item indexado ainda.','wp-unified-indexer');
    }
}
