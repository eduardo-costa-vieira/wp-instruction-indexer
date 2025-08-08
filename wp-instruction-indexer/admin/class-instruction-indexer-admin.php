<?php
// admin/class-instruction-indexer-admin.php

if ( ! defined( 'ABSPATH' ) ) exit;

class Instruction_Indexer_Admin extends WP_List_Table {

    private $table_name;
    private $post_id_to_show = 574;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'instrucao_index';

        parent::__construct( [
            'singular' => 'item_indexado',
            'plural'   => 'itens_indexados',
            'ajax'     => false
        ] );
    }

    public static function setup_admin_page() {
        add_menu_page(
            'Gerenciar Intenções',
            'Intenções Indexadas',
            'manage_options',
            'instruction-indexer-intentions',
            [ 'Instruction_Indexer_Admin', 'render_admin_page_content' ],
            'dashicons-media-text',
            6
        );
    }

    public static function render_admin_page_content() {
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
        }

        $list_table = new Instruction_Indexer_Admin();

        $filtered_post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 574;
        $list_table->post_id_to_show = $filtered_post_id;

        ?>
        <div class="wrap">
            <h1>Gerenciar Intenções Vinculadas</h1>
            <p style="background-color: yellow; padding: 10px; border: 1px solid orange;">
                **TESTE DE CARREGAMENTO DE FICHEIRO:** Se vir esta mensagem, o ficheiro está a ser carregado.
            </p>
            <p>Aqui você pode editar, excluir e visualizar as intenções vinculadas para cada item indexado.</p>

            <form method="get" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                <label for="post_id_filter">Filtrar por Post ID:</label>
                <input type="number" id="post_id_filter" name="post_id" value="<?php echo esc_attr($list_table->post_id_to_show); ?>" />
                <input type="submit" class="button" value="Aplicar Filtro" />
            </form>

            <form id="instruction-intentions-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="save_instruction_intentions">
                <input type="hidden" name="post_id_filter_hidden" value="<?php echo esc_attr($list_table->post_id_to_show); ?>">
                <?php wp_nonce_field( 'save_instruction_intentions_nonce', 'instruction_intentions_nonce_field' ); ?>

                <?php
                $list_table->prepare_items();
                $list_table->display();
                ?>
                <?php submit_button('Salvar Intenções'); ?>
            </form>
        </div>
        <script>
            // Script para controlar o estado dos campos de intenção
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.getElementById('instruction-intentions-form');
                if (form) {
                    form.addEventListener('click', function(e) {
                        if (e.target && e.target.classList.contains('edit-intention-button')) {
                            e.preventDefault();
                            const intentionId = e.target.getAttribute('data-id');
                            const intentionInput = document.getElementById('intention-field-' + intentionId);

                            if (intentionInput) {
                                if (intentionInput.disabled) {
                                    intentionInput.disabled = false;
                                    intentionInput.focus();
                                    intentionInput.style.backgroundColor = 'white';
                                    e.target.innerText = 'Cancelar';
                                } else {
                                    intentionInput.disabled = true;
                                    intentionInput.style.backgroundColor = '#eee';
                                    e.target.innerText = 'Editar';
                                }
                            }
                        }
                    });
                }
            });
        </script>
        <style>
            .intention-field {
                background-color: #eee;
                border: 1px solid #ccc;
                padding: 5px;
            }
        </style>
        <?php
    }

    public function get_columns() {
        $columns = [
            'cb'                     => '<input type="checkbox" />',
            'post_id'                => 'ID do Post',
            'nome_item'              => 'Nome do Item',
            'instrucao'              => 'Link da Instrução',
            'item'                   => 'Âncora',
            'intencao_clicavel'      => 'Intenção Clicável', // Nova coluna
            'intencao_vinculada'     => 'Intenção Vinculada',
            'palavras_indexadas'     => 'Palavras Indexadas',
            'acoes'                  => 'Ações', // Nova coluna
            'data_indexacao'         => 'Data de Indexação'
        ];
        return $columns;
    }

    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="item_ids[]" value="%s" />', $item->id_index
        );
    }

    protected function column_instrucao($item) {
        return sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url($item->instrucao . $item->item),
            esc_html($item->instrucao)
        );
    }

    protected function column_item($item) {
        return sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url(get_permalink($item->post_id) . $item->item),
            esc_html($item->item)
        );
    }

    protected function column_intencao_clicavel($item) {
        if (!empty($item->intencao_vinculada)) {
            return sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url(get_permalink($item->post_id) . $item->item),
                esc_html($item->intencao_vinculada)
            );
        }
        return '';
    }

    protected function column_intencao_vinculada($item) {
        return sprintf(
            '<input type="text" name="intention[%d]" id="intention-field-%d" value="%s" style="width:100%%; max-width: 400px;" class="intention-field" disabled />',
            $item->id_index,
            $item->id_index,
            esc_attr($item->intencao_vinculada)
        );
    }

    protected function column_acoes($item) {
        return sprintf(
            '<button class="button edit-intention-button" data-id="%d">Editar</button>',
            $item->id_index
        );
    }

    protected function column_default( $item, $column_name ) {
        switch( $column_name ) {
            case 'post_id':
            case 'nome_item':
            case 'palavras_indexadas':
            case 'data_indexacao':
                return esc_html($item->$column_name);
            default:
                return print_r( $item, true );
        }
    }

    public function get_sortable_columns() {
        $sortable_columns = [
            'post_id'        => ['post_id', false],
            'nome_item'      => ['nome_item', false],
            'data_indexacao' => ['data_indexacao', true]
        ];
        return $sortable_columns;
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = 20;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $query = "SELECT * FROM {$this->table_name} WHERE post_id = %d";
        $params = [$this->post_id_to_show];

        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'data_indexacao';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        if (array_key_exists($orderby, $sortable)) {
            $query .= " ORDER BY $orderby " . strtoupper($order);
        } else {
             $query .= " ORDER BY data_indexacao DESC";
        }

        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id_index) FROM {$this->table_name} WHERE post_id = %d", $this->post_id_to_show ) );

        $query .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = ($current_page - 1) * $per_page;

        $this->items = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ] );
    }

    /**
     * Lida com o salvamento das intenções vinculadas.
     * Este método é ESTÁTICO e agora salva os logs em um transient.
     */
    public static function handle_save_intentions() {
        global $wp_instruction_indexer_debug_messages;
        $wp_instruction_indexer_debug_messages = [];

        wp_instruction_indexer_debug_log('handle_save_intentions: Iniciado.');

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_instruction_indexer_debug_log('handle_save_intentions: Erro de permissão. Usuário não tem manage_options.');
            set_transient( 'wp_instruction_indexer_debug_logs', $wp_instruction_indexer_debug_messages, 60 );
            wp_die( 'Você não tem permissão para fazer isso.' );
        }

        if ( ! isset( $_POST['instruction_intentions_nonce_field'] ) || ! wp_verify_nonce( $_POST['instruction_intentions_nonce_field'], 'save_instruction_intentions_nonce' ) ) {
            wp_instruction_indexer_debug_log('handle_save_intentions: Erro de Nonce. Nonce inválido ou ausente.');
            set_transient( 'wp_instruction_indexer_debug_logs', $wp_instruction_indexer_debug_messages, 60 );
            wp_die( 'Ação inválida de segurança.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'instrucao_index';

        wp_instruction_indexer_debug_log('handle_save_intentions: Conteúdo de $_POST["intention"] (antes do loop): ' . print_r($_POST['intention'], true));

        $updates_successful = 0;
        $updates_failed = 0;

        if ( isset( $_POST['intention'] ) && is_array( $_POST['intention'] ) ) {
            foreach ( $_POST['intention'] as $id_index => $intention_text ) {
                $id_index = absint( $id_index );
                $intention_text = sanitize_text_field( $intention_text );

                wp_instruction_indexer_debug_log("handle_save_intentions: Processando ID: {$id_index}, Intenção: '{$intention_text}'");

                if ( $id_index > 0 ) {
                    $current_value = $wpdb->get_var($wpdb->prepare("SELECT intencao_vinculada FROM {$table} WHERE id_index = %d", $id_index));
                    wp_instruction_indexer_debug_log("handle_save_intentions: Valor atual na base de dados para o ID {$id_index} é: '{$current_value}'.");

                    if ($current_value === $intention_text) {
                        wp_instruction_indexer_debug_log("handle_save_intentions: O valor enviado ('{$intention_text}') é o mesmo que o valor atual na base de dados. Nenhuma atualização é necessária.");
                        continue;
                    }
                    
                    // Prepara a query SQL de atualização
                    $sql = $wpdb->prepare(
                        "UPDATE {$table} SET intencao_vinculada = %s WHERE id_index = %d",
                        $intention_text,
                        $id_index
                    );

                    wp_instruction_indexer_debug_log("handle_save_intentions: Executando a query SQL: {$sql}");

                    // Executa a query
                    $result = $wpdb->query( $sql );

                    if ( $result === false ) {
                        wp_instruction_indexer_debug_log('handle_save_intentions: ERRO ao atualizar item ID ' . $id_index . ': ' . $wpdb->last_error);
                        $updates_failed++;
                    } else {
                        if ($result > 0) {
                            wp_instruction_indexer_debug_log('handle_save_intentions: Sucesso ao atualizar item ID ' . $id_index . '. Linhas afetadas: ' . $result);
                            $updates_successful++;
                        } else {
                            wp_instruction_indexer_debug_log('handle_save_intentions: Item ID ' . $id_index . ' não foi alterado (mesmo valor).');
                        }
                    }
                } else {
                    wp_instruction_indexer_debug_log('handle_save_intentions: ID de índice inválido ignorado: ' . $id_index);
                }
            }
        } else {
            wp_instruction_indexer_debug_log('handle_save_intentions: $_POST["intention"] não está definido ou não é um array.');
        }

        wp_instruction_indexer_debug_log("handle_save_intentions: Concluído. Sucessos: {$updates_successful}, Falhas: {$updates_failed}.");

        set_transient( 'wp_instruction_indexer_debug_logs', $wp_instruction_indexer_debug_messages, 60 );

        $post_id_after_save = isset($_POST['post_id_filter_hidden']) ? absint($_POST['post_id_filter_hidden']) : 574;

        $redirect_url = add_query_arg(
            [
                'page'      => 'instruction-indexer-intentions',
                'message'   => '1',
                'post_id'   => $post_id_after_save
            ],
            admin_url( 'admin.php' )
        );
        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Adiciona mensagens de administração e exibe os logs de depuração do transient.
     */
    public static function display_admin_notices() {
        if ( isset( $_GET['message'] ) && $_GET['message'] == '1' ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Intenções vinculadas salvas com sucesso!</p>
            </div>
            <?php
        }

        $debug_logs = get_transient( 'wp_instruction_indexer_debug_logs' );

        if ( current_user_can( 'manage_options' ) && ! empty( $debug_logs ) ) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<h3>Logs de Depuração do WP Instruction Indexer:</h3>';
            echo '<pre style="max-height: 200px; overflow-y: auto; background: #f0f0f0; padding: 10px; border: 1px solid #ccc;">';
            foreach ( $debug_logs as $log_message ) {
                echo esc_html( $log_message ) . "\n";
            }
            echo '</pre>';
            echo '</div>';

            delete_transient( 'wp_instruction_indexer_debug_logs' );
        }
    }
}