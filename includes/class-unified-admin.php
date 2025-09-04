<?php
namespace WPUI;
defined('ABSPATH') || exit;

class Admin {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        // CSV export
        add_action('wp_ajax_wpui_export', [__CLASS__, 'export_csv']);
        // Items of a post (Structure)
        add_action('wp_ajax_wpui_structure_items_for_post', [__CLASS__, 'ajax_items_for_post']);
        // REST fallbacks (AJAX)
        add_action('wp_ajax_wpui_fulltext_index_one', [__CLASS__, 'ajax_ft_index_one']);
        add_action('wp_ajax_wpui_fulltext_index_all', [__CLASS__, 'ajax_ft_index_all']);
        add_action('wp_ajax_wpui_structure_index_one', [__CLASS__, 'ajax_st_index_one']);
        add_action('wp_ajax_wpui_structure_index_all', [__CLASS__, 'ajax_st_index_all']);
        // Synonyms actions
        add_action('wp_ajax_wpui_structure_update_synonyms', [__CLASS__, 'ajax_st_update_synonyms']);
        add_action('wp_ajax_wpui_structure_delete_item', [__CLASS__, 'ajax_st_delete_item']);
    }

    public static function menu(){
        add_menu_page(
            __('WP Unified Indexer','wp-unified-indexer'),
            __('WP Unified Indexer','wp-unified-indexer'),
            'manage_options',
            'wpui-indexer',
            [__CLASS__, 'render'],
            'dashicons-filter',
            68
        );
    }

    public static function assets($hook){
        if ($hook !== 'toplevel_page_wpui-indexer') return;

        // Garante que as tabelas existem (caso de upgrade manual)
        (new Fulltext_Indexer())->maybe_install();
        (new Instruction_Indexer())->maybe_install();

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'fulltext';

        if ($tab === 'structure' || $tab === 'audit') {
            wp_enqueue_style('wpui-structure-style', WPUI_URL.'assets/structure-style.css', [], WPUI_VERSION);
            wp_enqueue_script('wpui-structure-js', WPUI_URL.'assets/structure-index.js', ['jquery'], WPUI_VERSION, true);
        } else {
            wp_enqueue_style('wpui-fulltext-style', WPUI_URL.'assets/fulltext-style.css', [], WPUI_VERSION);
            wp_enqueue_script('wpui-fulltext-js', WPUI_URL.'assets/fulltext-index.js', ['jquery'], WPUI_VERSION, true);
        }

        $rest = esc_url_raw( rest_url('wpui/v1/') );
        $nonce = wp_create_nonce('wp_rest');
        $ajax_nonce = wp_create_nonce('wpui_ajax');

        $ft_idx = new Fulltext_Indexer();
        $st_idx = new Instruction_Indexer();
        $counts_ft = $ft_idx->stats();
        $counts_st = $st_idx->stats();

        wp_localize_script(($tab==='structure'||$tab==='audit'?'wpui-structure-js':'wpui-fulltext-js'), 'WPUI', [
            'rest' => $rest,
            'nonce' => $nonce,
            'ajax' => admin_url('admin-ajax.php'),
            'ajax_nonce' => $ajax_nonce,
            'counts_ft' => $counts_ft,
            'counts_st' => $counts_st,
            'i18n' => [
                'already' => __('Instrução já está indexada. Deseja reindexar?','wp-unified-indexer'),
                'error' => __('Ocorreu um erro.','wp-unified-indexer'),
                'saved' => __('Sinônimos salvos.','wp-unified-indexer'),
                'deleted' => __('Item excluído.','wp-unified-indexer'),
            ]
        ]);
    }

    public static function render(){
        if (!current_user_can('manage_options')) return;
        $active = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'fulltext';
        echo '<div class="wrap wpui-wrap">';
        echo '<h1>WP Unified Indexer</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url(admin_url('admin.php?page=wpui-indexer&tab=fulltext')), $active==='fulltext'?'nav-tab-active':'', __('Indexar Fulltext','wp-unified-indexer'));
        printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url(admin_url('admin.php?page=wpui-indexer&tab=structure')), $active==='structure'?'nav-tab-active':'', __('Indexar Estrutura','wp-unified-indexer'));
        printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url(admin_url('admin.php?page=wpui-indexer&tab=audit')), $active==='audit'?'nav-tab-active':'', __('Auditoria','wp-unified-indexer'));
        echo '</h2>';

        if ($active==='structure') {
            self::render_structure_tab();
        } elseif ($active==='audit') {
            self::render_audit_tab();
        } else {
            self::render_fulltext_tab();
        }

        echo '</div>';
    }

    private static function counters_html($published, $indexed, $pending){
        return '<div class="wpui-counters">'
            . '<div class="wpui-counter"><div class="wpui-counter-k">'.esc_html__('Publicados','wp-unified-indexer').'</div><div class="wpui-counter-v published">'.esc_html($published).'</div></div>'
            . '<div class="wpui-counter"><div class="wpui-counter-k">'.esc_html__('Indexados','wp-unified-indexer').'</div><div class="wpui-counter-v indexed">'.esc_html($indexed).'</div></div>'
            . '<div class="wpui-counter"><div class="wpui-counter-k">'.esc_html__('Pendentes','wp-unified-indexer').'</div><div class="wpui-counter-v pending">'.esc_html($pending).'</div></div>'
            . '</div>';
    }

    private static function search_input(){
        $s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="wpui-search"><?php _e('Buscar por título','wp-unified-indexer'); ?></label>
            <input type="search" id="wpui-search" name="s" value="<?php echo esc_attr($s); ?>" />
            <?php submit_button(__('Buscar por título','wp-unified-indexer'), 'button', false, false, ['id' => 'search-submit']); ?>
        </p>
        <?php
    }

    private static function render_fulltext_tab(){
        $table = new Fulltext_Index_Table();
        $table->prepare_items();
        echo self::counters_html($table->counts['published'],$table->counts['indexed'],$table->counts['pending']);
        ?>
        <div class="wpui-actions">
            <a href="#" class="button wpui-index-all" data-kind="ft" data-label="<?php esc_attr_e('Indexar Tudo','wp-unified-indexer'); ?>"><?php _e('Indexar Tudo','wp-unified-indexer'); ?></a>
            <a href="#" class="button wpui-export" data-kind="ft"><?php _e('Exportar CSV','wp-unified-indexer'); ?></a>
        </div>
        <div class="wpui-manual">
            <label><span><?php _e('ID da Instrução','wp-unified-indexer'); ?></span>
                <input type="text" class="wpui-id-ft" placeholder="<?php esc_attr_e('ID da Instrução','wp-unified-indexer'); ?>"></label>
            <label><span><?php _e('URL da Instrução','wp-unified-indexer'); ?></span>
                <input type="text" class="wpui-url-ft" placeholder="<?php esc_attr_e('URL da Instrução','wp-unified-indexer'); ?>"></label>
            <a href="#" class="button button-primary wpui-index-manual" data-kind="ft"><?php _e('Indexar Manualmente','wp-unified-indexer'); ?></a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'wpui-indexer','tab'=>'fulltext'], admin_url('admin.php'))); ?>" class="button wpui-refresh"><?php _e('Atualizar Tabela','wp-unified-indexer'); ?></a>
        </div>
        <form method="get">
            <input type="hidden" name="page" value="wpui-indexer" />
            <input type="hidden" name="tab" value="fulltext" />
            <?php self::search_input(); ?>
            <?php $table->display(); ?>
        </form><?php
    }

    private static function render_structure_tab(){
        $table = new Instruction_Index_Table();
        $table->prepare_items();
        echo self::counters_html($table->counts['published'],$table->counts['indexed'],$table->counts['pending']); ?>
        <div class="wpui-actions">
            <a href="#" class="button wpui-index-all" data-kind="st" data-label="<?php esc_attr_e('Indexar Estrutura (Tudo)','wp-unified-indexer'); ?>"><?php _e('Indexar Estrutura (Tudo)','wp-unified-indexer'); ?></a>
            <a href="#" class="button wpui-export" data-kind="st"><?php _e('Exportar CSV','wp-unified-indexer'); ?></a>
        </div>
        <div class="wpui-manual">
            <label><span><?php _e('ID da Instrução','wp-unified-indexer'); ?></span>
                <input type="text" class="wpui-id-st" placeholder="<?php esc_attr_e('ID da Instrução','wp-unified-indexer'); ?>"></label>
            <label><span><?php _e('URL da Instrução','wp-unified-indexer'); ?></span>
                <input type="text" class="wpui-url-st" placeholder="<?php esc_attr_e('URL da Instrução','wp-unified-indexer'); ?>"></label>
            <a href="#" class="button button-primary wpui-index-manual" data-kind="st"><?php _e('Indexar Manualmente','wp-unified-indexer'); ?></a>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'wpui-indexer','tab'=>'structure'], admin_url('admin.php'))); ?>" class="button wpui-refresh"><?php _e('Atualizar Tabela','wp-unified-indexer'); ?></a>
        </div>
        <form method="get">
            <input type="hidden" name="page" value="wpui-indexer" />
            <input type="hidden" name="tab" value="structure" />
            <?php self::search_input(); ?>
            <?php $table->display(); ?>
        </form><?php
    }

    private static function render_audit_tab(){
        $table = new Instruction_Audit_Table();
        $table->prepare_items();
        echo self::counters_html($table->counts['published'],$table->counts['indexed'],$table->counts['pending']); ?>
        <p><?php _e('Esta aba exibe, após indexar na aba Estrutura, os posts publicados que continuaram pendentes (sem itens indexados).','wp-unified-indexer'); ?></p>
        <form method="get">
            <input type="hidden" name="page" value="wpui-indexer" />
            <input type="hidden" name="tab" value="audit" />
            <?php self::search_input(); ?>
            <?php $table->display(); ?>
        </form><?php
    }

    // ---------- CSV export ----------
    public static function export_csv(){
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('wpui_ajax','nonce');
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        global $wpdb;
        $filename = 'wpui-export-'. sanitize_file_name($type).'-'. date('Ymd-His').'.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);
        $out = fopen('php://output', 'w');
        if ($type==='ft') {
            $table = $wpdb->prefix.'wpui_fulltext_index';
            fputcsv($out, ['post_id','post_title','post_url','total_words','indexed_at_gmt','status']);
            $rows = $wpdb->get_results("SELECT post_id,post_title,post_url,total_words,indexed_at_gmt,status FROM {$table} ORDER BY indexed_at_gmt DESC LIMIT 5000", ARRAY_A);
            foreach($rows as $r) fputcsv($out, $r);
        } elseif ($type==='st') {
            $table = $wpdb->prefix.'wpui_instruction_index';
            fputcsv($out, ['post_id','item_id','item_title','terms','synonyms','url','indexed_at_gmt','status','mode']);
            $rows = $wpdb->get_results("SELECT post_id,item_id,item_title,terms,synonyms,url,indexed_at_gmt,status,COALESCE(mode,'auto') FROM {$table} ORDER BY indexed_at_gmt DESC LIMIT 10000", ARRAY_A);
            foreach($rows as $r) fputcsv($out, $r);
        } else {
            fputcsv($out, ['tipo inválido']);
        }
        fclose($out);
        exit;
    }

    // ---------- Items of a post ----------
    public static function ajax_items_for_post(){
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'forbidden']);
        check_admin_referer('wpui_ajax','nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error(['msg'=>'invalid_id']);
        global $wpdb;
        $t = $wpdb->prefix.'wpui_instruction_index';
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id,item_title,COALESCE(NULLIF(terms,''), '(sem termos)') AS terms, COALESCE(synonyms,'') AS synonyms, url FROM {$t} WHERE post_id=%d AND status='indexed' ORDER BY item_id ASC LIMIT 1000",
            $id
        ), ARRAY_A);
        wp_send_json_success(['items'=>$items]);
    }

    // ---------- AJAX fallback handlers ----------
    private static function check_ajax(){
        if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'forbidden']);
        check_admin_referer('wpui_ajax','nonce');
    }

    public static function ajax_ft_index_one(){
        self::check_ajax();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $force = !empty($_POST['force_reindex']);
        $idx = new Fulltext_Indexer();
        $res = $idx->index_one($id, $url, $force, 'manual');
        wp_send_json_success($res);
    }

    public static function ajax_ft_index_all(){
        self::check_ajax();
        $batch = isset($_POST['batch']) ? max(1,min(100,intval($_POST['batch']))) : 10;
        $idx = new Fulltext_Indexer();
        $n = $idx->index_all($batch);
        wp_send_json_success(['processed'=>$n]);
    }

    public static function ajax_st_index_one(){
        self::check_ajax();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $force = !empty($_POST['force_reindex']);
        $idx = new Instruction_Indexer();
        $res = $idx->index_one($id, $url, $force, 'manual');
        wp_send_json_success($res);
    }

    public static function ajax_st_index_all(){
        self::check_ajax();
        $batch = isset($_POST['batch']) ? max(1,min(100,intval($_POST['batch']))) : 10;
        $idx = new Instruction_Indexer();
        $n = $idx->index_all($batch, 'auto');
        wp_send_json_success(['processed'=>$n]);
    }

    // ---------- Synonyms actions ----------
    public static function ajax_st_update_synonyms(){
        self::check_ajax();
        global $wpdb;
        $t = $wpdb->prefix.'wpui_instruction_index';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $item_id = isset($_POST['item_id']) ? sanitize_text_field($_POST['item_id']) : '';
        $syn = isset($_POST['synonyms']) ? sanitize_text_field($_POST['synonyms']) : '';
        if (!$post_id || !$item_id) wp_send_json_error(['msg'=>'invalid']);
        $wpdb->update($t, ['synonyms'=>$syn], ['post_id'=>$post_id, 'item_id'=>$item_id], ['%s'], ['%d','%s']);
        wp_send_json_success(['msg'=>'ok']);
    }

    public static function ajax_st_delete_item(){
        self::check_ajax();
        global $wpdb;
        $t = $wpdb->prefix.'wpui_instruction_index';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $item_id = isset($_POST['item_id']) ? sanitize_text_field($_POST['item_id']) : '';
        if (!$post_id || !$item_id) wp_send_json_error(['msg'=>'invalid']);
        $wpdb->delete($t, ['post_id'=>$post_id, 'item_id'=>$item_id], ['%d','%s']);
        wp_send_json_success(['msg'=>'ok']);
    }
}
