<?php
/**
 * Plugin Name: WP Instruction Indexer
 * Description: Indexa o conteúdo de instruções do WordPress para facilitar a busca.
 * Version: 1.0.4
 * Author: Seu Nome
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Caminho para o diretório do plugin
define( 'WP_INSTRUCTION_INDEXER_PATH', plugin_dir_path( __FILE__ ) );

// Inclui o arquivo da classe Instruction_Indexer (lógica de indexação)
require_once WP_INSTRUCTION_INDEXER_PATH . 'includes/class-indexer.php';

// Inclui a classe da página de administração
require_once WP_INSTRUCTION_INDEXER_PATH . 'admin/class-instruction-indexer-admin.php';

// Função de ativação do plugin
function wp_instruction_indexer_activate() {
    Instruction_Indexer::create_table();
}
register_activation_hook( __FILE__, 'wp_instruction_indexer_activate' );

// --- MODIFICADO: Função para log de depuração (adiciona ao transient) ---
if ( ! function_exists( 'wp_instruction_indexer_debug_log' ) ) {
    function wp_instruction_indexer_debug_log( $message ) {
        // As mensagens são armazenadas em uma variável global para a requisição atual
        // e depois salvas em um transient na função handle_save_intentions.
        global $wp_instruction_indexer_debug_messages;
        if ( ! is_array( $wp_instruction_indexer_debug_messages ) ) {
            $wp_instruction_indexer_debug_messages = [];
        }
        $wp_instruction_indexer_debug_messages[] = $message;
    }
}

// Inicializa o indexador quando o WordPress estiver pronto (opcional, para testes)
add_action( 'init', function() {
    // Para fins de teste, você pode querer chamar isso manualmente ou via um cron job
    Instruction_Indexer::index_single_instruction(); // DESCOMENTADA: Esta linha vai executar o indexador.
});

// A classe de administração agora é inicializada de forma mais segura para a tabela.
// O método estático 'setup_admin_page' da classe de administração registra a página.
add_action( 'admin_menu', [ 'Instruction_Indexer_Admin', 'setup_admin_page' ] );

// Registra o manipulador de salvamento de intenções
add_action( 'admin_post_save_instruction_intentions', [ 'Instruction_Indexer_Admin', 'handle_save_intentions' ] );


// Adiciona as mensagens de administração (sucesso/erro e agora os logs de depuração)
add_action( 'admin_notices', [ 'Instruction_Indexer_Admin', 'display_admin_notices' ] );