<?php
// includes/class-indexer.php

if ( ! defined( 'ABSPATH' ) ) exit;

class Instruction_Indexer {

    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'instrucao_index';
        $charset_collate = $wpdb->get_charset_collate();

        // Modificado: Adicionada a coluna 'intencao_vinculada'
        $sql = "CREATE TABLE $table_name (
            id_index BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instrucao TEXT NOT NULL,
            item TEXT,
            post_id BIGINT(20),
            nome_item TEXT,
            palavras_indexadas TEXT,
            intencao_vinculada TEXT, -- NOVA COLUNA ADICIONADA AQUI
            data_indexacao DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function index_single_instruction() {
        wp_instruction_indexer_debug_log('Instruction_Indexer: index_single_instruction() iniciada.');

        $target_post_id = 574; // Manter por enquanto para testes.

        $post = get_post( $target_post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            wp_instruction_indexer_debug_log('Instruction_Indexer: Post ID ' . $target_post_id . ' não encontrado ou não publicado.');
            return;
        }

        wp_instruction_indexer_debug_log('Instruction_Indexer: Processando Post ID: ' . $target_post_id . ' - Título: ' . $post->post_title);

        $slug = get_permalink( $post );
        $post_id_in_slug = $post->ID; 

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Garante que o conteúdo seja tratado como UTF-8 ao carregar HTML
        $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Armazenar os IDs e os textos dos links do sumário
        $anchors_data_from_html = []; // Renomeado para maior clareza
        $anchors = $xpath->query('//a[contains(@href, "#")]');
        foreach ($anchors as $a) {
            $href = $a->getAttribute('href');
            if (preg_match('/#([^"]+)/', $href, $match)) {
                $anchors_data_from_html[] = [
                    'id' => $match[1],
                    'text' => trim($a->textContent) // O texto visível do link no sumário
                ];
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'instrucao_index';

        // --- NOVA LÓGICA DE PERSISTÊNCIA ---

        // 1. Obter todos os itens atualmente indexados para este post_id no banco de dados
        $existing_db_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id_index, item, intencao_vinculada FROM $table WHERE post_id = %d",
            $post_id_in_slug
        ), OBJECT_K ); // OBJECT_K retorna um array associativo onde a chave é o valor da coluna 'item'

        $processed_anchor_ids = []; // Para rastrear os IDs processados do HTML

        if ( ! empty( $anchors_data_from_html ) ) {
            wp_instruction_indexer_debug_log('Instruction_Indexer: Encontradas ' . count($anchors_data_from_html) . ' âncoras no HTML.');
            
            // Logar os primeiros 500 caracteres do conteúdo do post para comparação
            wp_instruction_indexer_debug_log('Instruction_Indexer: Trecho do HTML do Post (primeiros 500 caracteres): ' . esc_html(substr($post->post_content, 0, 500)) . '...');

            foreach ( $anchors_data_from_html as $anchor_info ) {
                $anchor_id = $anchor_info['id'];
                $anchor_text_from_toc = $anchor_info['text'];
                $anchor_decoded = urldecode( $anchor_id ); 
                $item_db_identifier = '#' . esc_attr( $anchor_id ); // Como o item é armazenado no BD

                $nome_item = 'sem informação'; 
                $palavras_indexadas_item_display = 'sem informação'; 
                $palavras_indexadas_item_raw = '';

                $target_el = null;

                // 1. Tenta encontrar por ID
                $xpath_query_id = "//*[@id='" . esc_attr($anchor_decoded) . "']";
                wp_instruction_indexer_debug_log('Instruction_Indexer: Tentando XPath por ID: "' . $anchor_decoded . '" com query: "' . $xpath_query_id . '"');
                $target_el = $xpath->query($xpath_query_id)->item(0);

                // 2. Se não encontrou por ID, tenta por NAME
                if (!$target_el) {
                    $xpath_query_name = "//a[@name='" . esc_attr($anchor_decoded) . "']";
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Tentando XPath por NAME: "' . $anchor_decoded . '" com query: "' . $xpath_query_name . '"');
                    $target_el = $xpath->query($xpath_query_name)->item(0);
                }
                
                if ($target_el) {
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Elemento alvo ENCONTRADO para âncora: ' . $anchor_decoded);
                    
                    $target_el_html_snippet = $target_el->ownerDocument->saveHTML($target_el);
                    wp_instruction_indexer_debug_log('Instruction_Indexer: HTML do elemento encontrado (primeiros 200 caracteres): ' . esc_html(substr($target_el_html_snippet, 0, 200)) . '...');

                    if ($target_el->nodeName === 'a' && $target_el->hasAttribute('name')) {
                        $parent_node = $target_el->parentNode;
                        if ($parent_node) {
                            $nome_item = trim($parent_node->textContent);
                        }
                        
                        $current_node_for_content = $parent_node ? $parent_node : $target_el->nextSibling;
                        while ($current_node_for_content) {
                            $palavras_indexadas_item_raw .= $current_node_for_content->ownerDocument->saveHTML($current_node_for_content);
                            if (in_array(strtolower($current_node_for_content->nodeName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'ul', 'ol', 'table']) && $current_node_for_content !== $parent_node && $current_node_for_content !== $target_el->nextSibling) {
                                break;
                            }
                            $current_node_for_content = $current_node_for_content->nextSibling;
                        }

                    } else {
                        $nome_item = trim($target_el->textContent);
                        $palavras_indexadas_item_raw = $target_el->ownerDocument->saveHTML($target_el);
                    }

                    if (empty($nome_item) || mb_strlen($nome_item, 'UTF-8') < 5) { 
                        $cleaned_content = wp_strip_all_tags($palavras_indexadas_item_raw);
                        $first_sentence = strtok($cleaned_content, '.!?') . (strpos($cleaned_content, '.') !== false ? '.' : '');
                        if (!empty($first_sentence)) {
                             $nome_item = sanitize_text_field(trim($first_sentence));
                        }
                    }

                    if (empty($nome_item) || $nome_item === 'sem informação') {
                        $nome_item = $anchor_text_from_toc;
                    }
                    
                    $word_count = !empty($palavras_indexadas_item_raw) ? str_word_count(wp_strip_all_tags($palavras_indexadas_item_raw)) : 0;
                    $palavras_indexadas_item_display = $word_count > 0 ? $word_count . ' palavras' : '0 palavras';

                    wp_instruction_indexer_debug_log('Instruction_Indexer: Nome do Item extraído: "' . $nome_item . '" | Palavras: ' . $palavras_indexadas_item_display);

                } else {
                    $nome_item = $anchor_text_from_toc; 
                    $palavras_indexadas_item_display = '0 palavras (item não encontrado no conteúdo)';
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Elemento alvo NÃO encontrado para âncora: "' . $anchor_decoded . '". Usando o texto do sumário "' . $nome_item . '" como fallback.');
                }

                // === VERIFICAÇÃO DE NUMERAÇÃO E SE É >= 5 ===
                $is_numbered_item = false;
                $main_section_number = 0;

                if (preg_match('/^(\d+)(\.\d+)*\s*.*$/', $nome_item, $matches)) {
                    $main_section_number = (int)$matches[1];
                    if (preg_match('/^(\d+)(\.\d+)*\s*.*$/', $anchor_text_from_toc)) {
                        $is_numbered_item = true;
                    }
                }
                
                if (!$is_numbered_item || $main_section_number < 5) {
                    wp_instruction_indexer_debug_log('Instruction_Indexer: IGNORANDO âncora: "' . $anchor_decoded . '" ("' . $nome_item . '") - Não é um item numerado ou a seção principal é menor que 5.');
                    // Não adiciona ao processed_anchor_ids se for ignorado
                    continue; 
                }
                // ===============================================

                // Adiciona o item processado à lista de IDs processados para comparação futura
                $processed_anchor_ids[$item_db_identifier] = true;

                // Prepara os dados a serem inseridos ou atualizados
                $data_to_save = [
                    'instrucao'          => esc_url_raw( $slug ),
                    'item'               => $item_db_identifier, // Usa o ID original da âncora
                    'post_id'            => $post_id_in_slug,
                    'nome_item'          => sanitize_text_field( $nome_item ),
                    'palavras_indexadas' => sanitize_text_field( $palavras_indexadas_item_display ), 
                    'data_indexacao'     => current_time( 'mysql' ),
                ];

                // Verifica se o item já existe no banco de dados
                if ( isset( $existing_db_items[$item_db_identifier] ) ) {
                    // Item existe, atualiza os dados, mas PRESERVA a intencao_vinculada
                    $wpdb->update( $table, $data_to_save, 
                        [ 'id_index' => $existing_db_items[$item_db_identifier]->id_index ] 
                    );
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Dados ATUALIZADOS para âncora: ' . $anchor_decoded . ' (Intenção Vinculada preservada).');
                    // Remove o item da lista de itens existentes, para que o que sobrar seja deletado
                    unset( $existing_db_items[$item_db_identifier] );
                } else {
                    // Item não existe, insere um novo registro
                    $wpdb->insert( $table, $data_to_save );
                    if (false === $wpdb->insert_id) { 
                        wp_instruction_indexer_debug_log('Instruction_Indexer: ERRO ao inserir dados para âncora ' . $anchor_decoded . ': ' . $wpdb->last_error);
                    } else {
                        wp_instruction_indexer_debug_log('Instruction_Indexer: Dados INSERIDOS para âncora: ' . $anchor_decoded);
                    }
                }
            } // Fim do foreach ($anchors_data_from_html)

            // Agora, delete os itens que existiam no banco de dados, mas não foram encontrados no HTML atual
            if ( ! empty( $existing_db_items ) ) {
                foreach ( $existing_db_items as $item_to_delete ) {
                    $wpdb->delete( $table, [ 'id_index' => $item_to_delete->id_index ] );
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Dados DELETADOS para âncora OBSOLETA: ' . $item_to_delete->item . ' (não encontrada no HTML atual).');
                }
            }

        } else {
            // Se nenhuma âncora foi encontrada no HTML, trata o post completo ou limpa os antigos
            wp_instruction_indexer_debug_log('Instruction_Indexer: Nenhuma âncora encontrada para o Post ID ' . $target_post_id . '. Verificando itens existentes ou indexando post completo.');

            // Limpa todos os itens antigos para este post se não há mais âncoras no HTML
            if ( ! empty( $existing_db_items ) ) {
                $wpdb->delete( $table, [ 'post_id' => $post_id_in_slug ] );
                wp_instruction_indexer_debug_log('Instruction_Indexer: Todas as entradas antigas para Post ID: ' . $post_id_in_slug . ' foram deletadas pois nenhuma âncora foi encontrada no HTML.');
            }

            // Opcional: Re-indexar o post completo se não houver âncoras, como fallback
            $conteudo = wp_strip_all_tags( $post->post_content );
            $palavras_array = preg_split( '/[\s,;:.!?]+/', $conteudo, -1, PREG_SPLIT_NO_EMPTY );
            $total_palavras = count($palavras_array);
            $palavras_display = $total_palavras > 0 ? $total_palavras . ' palavras' : '0 palavras';

            $data_to_save_full_post = [
                'instrucao'          => esc_url_raw( $slug ),
                'item'               => 'Conteúdo Completo', 
                'post_id'            => $post_id_in_slug,
                'nome_item'          => sanitize_text_field( $post->post_title ), 
                'palavras_indexadas' => sanitize_text_field( $palavras_display ),
                'data_indexacao'     => current_time( 'mysql' ),
            ];

            // Verifica se o item "Conteúdo Completo" já existe para este post
            $existing_full_post_item = $wpdb->get_row( $wpdb->prepare(
                "SELECT id_index, intencao_vinculada FROM $table WHERE post_id = %d AND item = 'Conteúdo Completo'",
                $post_id_in_slug
            ) );

            if ( $existing_full_post_item ) {
                $wpdb->update( $table, $data_to_save_full_post, 
                    [ 'id_index' => $existing_full_post_item->id_index ] 
                );
                wp_instruction_indexer_debug_log('Instruction_Indexer: Dados do post completo ATUALIZADOS (Intenção Vinculada preservada).');
            } else {
                $wpdb->insert( $table, $data_to_save_full_post );
                if (false === $wpdb->insert_id) { 
                    wp_instruction_indexer_debug_log('Instruction_Indexer: ERRO ao inserir dados do post completo: ' . $wpdb->last_error);
                } else {
                    wp_instruction_indexer_debug_log('Instruction_Indexer: Dados do post completo INSERIDOS.');
                }
            }
        }
        wp_instruction_indexer_debug_log('Instruction_Indexer: index_single_instruction() concluída.');
    }
}