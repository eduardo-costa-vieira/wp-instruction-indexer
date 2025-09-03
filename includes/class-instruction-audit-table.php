<?php
namespace WPUI;
defined('ABSPATH') || exit;

if ( ! class_exists('\WP_List_Table') ) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Instruction_Audit_Table
 * Autor: Eduardo Vieira
 * Versão: 2.7.2
 *
 * Exibe posts publicados que seguiram pendentes após a indexação da Estrutura.
 * A tabela nasce vazia e só preenche depois da indexação rodar.
 */
class Instruction_Audit_Table extends \WP_List_Table {
    public $counts = ['published'=>0,'indexed'=>0,'pending'=>0];

    public function __construct(){
        parent::__construct([
            'singular' => 'audit',
            'plural'   => 'audits',
            'ajax'     => false,
        ]);
    }

    public function get_columns(){
        return [
            'post_id'                => __('ID','wp-unified-indexer'),
            'post_title'             => __('Título da Instrução','wp-unified-indexer'),
            'post_status'            => __('Status do Post','wp-unified-indexer'),
            'last_post_modified_gmt' => __('Última Atualização','wp-unified-indexer'),
            'reason'                 => __('Motivo','wp-unified-indexer'),
        ];
    }

    public function prepare_items(){
        global $wpdb;

        // counters
        $idx = new Instruction_Indexer();
        $this->counts = $idx->stats();

        $per_page = 12;
        $paged    = $this->get_pagenum();
        $offset   = ($paged-1)*$per_page;

        $search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $t = $wpdb->prefix.'wpui_instruction_index';
        $where = "WHERE p.post_status='publish'";
        $args  = [];

        if ($search){
            $where .= " AND p.post_title LIKE %s";
            $args[] = '%'.$wpdb->esc_like($search).'%';
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS
                    p.ID AS post_id, p.post_title, p.post_status, p.post_modified_gmt AS last_post_modified_gmt
                FROM {$wpdb->posts} p
                LEFT JOIN (SELECT DISTINCT post_id FROM {$t} WHERE status='indexed') s ON s.post_id = p.ID
                {$where} AND s.post_id IS NULL
                ORDER BY p.ID DESC
                LIMIT %d OFFSET %d";

        $args = array_merge($args, [ $per_page, $offset ]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        foreach($rows as &$r){
            $r['reason'] = __('Sem itens indexados (pendente)','wp-unified-indexer');
        }

        $this->items = $rows;

        $total_items = intval($wpdb->get_var("SELECT FOUND_ROWS()"));
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);
    }

    public function no_items(){
        _e('Nenhuma pendência encontrada.','wp-unified-indexer');
    }
}
