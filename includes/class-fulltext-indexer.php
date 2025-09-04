<?php
namespace WPUI;
defined('ABSPATH') || exit;

/**
 * Fulltext_Indexer
 * Autor: Eduardo Vieira
 * v2.7.2
 *
 * - Indexa posts publicados em uma tabela própria (fulltext)
 * - Lote 10/10 via REST/AJAX (chamado pelo Admin/JS)
 * - Reindex seletiva com force
 * - Contadores: publicados / indexados / pendentes
 */
class Fulltext_Indexer {
    protected $table;

    public function __construct(){
        global $wpdb;
        $this->table = $wpdb->prefix.'wpui_fulltext_index';
        foreach($this->post_types() as $type){
            add_action("save_post_{$type}", [$this, 'mark_pending']);
        }
    }

    /** Cria/atualiza tabela (executado na ativação e ao abrir a tela do admin) */
    public function maybe_install(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          post_id BIGINT UNSIGNED NOT NULL,
          post_title TEXT NULL,
          post_url TEXT NULL,
          total_words INT UNSIGNED DEFAULT 0,
          content_hash CHAR(32) NULL,
          last_post_modified_gmt DATETIME NULL,
          indexed_at_gmt DATETIME NULL,
          mode VARCHAR(20) DEFAULT 'auto',
          status VARCHAR(20) DEFAULT 'indexed',
          PRIMARY KEY(id),
          KEY post_id (post_id),
          KEY status (status)
        ) {$charset};";
        dbDelta($sql);
    }

    /** Post types alvo (filtrável) */
    public function post_types(){
        return apply_filters('wpui_fulltext_post_types', ['post']);
    }

    /** Estatísticas para os cartões */
    public function stats(){
        global $wpdb;
        $types = $this->post_types();
        $in = implode("','", array_map('esc_sql', $types));
        $published = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('{$in}')"));
        $indexed = intval($wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE status='indexed'"));
        $pending_records = intval($wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE status='pending'"));
        $pending = max(0, $published - $indexed - $pending_records) + $pending_records;
        return compact('published','indexed','pending');
    }

    /** Marca o post para reindexação ou remove o registro */
    public function mark_pending($post_id){
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $post = get_post($post_id);
        if (!$post) return;
        global $wpdb;
        if ($post->post_status !== 'publish'){
            $wpdb->delete($this->table, ['post_id' => $post_id]);
            return;
        }
        $wpdb->update($this->table, ['status' => 'pending'], ['post_id' => $post_id]);
    }

    /** Resolve post por ID ou URL */
    public function find_post($id, $url){
        $id = intval($id);
        if ($id > 0) return get_post($id);
        if ($url) { $p = url_to_postid($url); if ($p) return get_post($p); }
        return null;
    }

    /** Remove o índice de um post específico */
    public function unindex($post_id){
        global $wpdb;
        $wpdb->delete($this->table, ['post_id'=>intval($post_id)]);
    }

    /** Hash simples do conteúdo para comparação */
    protected function content_hash($content){
        return md5(wp_strip_all_tags((string)$content));
    }

    /**
     * Indexa 1 post
     * - $force=true remove o registro anterior e reinsere
     * - $mode='manual' ou 'auto'
     */
    public function index_one($id=0,$url='',$force=false,$mode='manual'){
        $post = $this->find_post($id,$url);
        if (!$post || $post->post_status!=='publish') {
            return ['status'=>'not_found','message'=>__('Instrução não encontrada/publicada.','wp-unified-indexer')];
        }

        global $wpdb;
        $content = (string)apply_filters('the_content',$post->post_content);
        $hash = $this->content_hash($content);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE post_id=%d AND status='indexed' LIMIT 1",
            $post->ID
        ));

        if ($exists && !$force) {
            return ['status'=>'already_indexed','message'=>__('Instrução já indexada.','wp-unified-indexer')];
        }

        if ($exists && $force) {
            $wpdb->delete($this->table, ['post_id'=>$post->ID]);
        }

        $total_words = str_word_count(
            wp_strip_all_tags($content),
            0,
            'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿ'
        );

        $wpdb->insert($this->table, [
            'post_id'                 => $post->ID,
            'post_title'              => $post->post_title,
            'post_url'                => get_permalink($post),
            'total_words'             => $total_words,
            'content_hash'            => $hash,
            'last_post_modified_gmt'  => $post->post_modified_gmt,
            'indexed_at_gmt'          => current_time('mysql',1),
            'mode'                    => $mode==='manual' ? 'manual' : 'auto',
            'status'                  => 'indexed',
        ], ['%d','%s','%s','%d','%s','%s','%s','%s','%s']);

        return ['status'=>'ok','message'=>__('Instrução indexada com sucesso.','wp-unified-indexer')];
    }

    /** Próximos IDs pendentes (para processamento em lote 10/10) */
    protected function next_pending_post_ids($batch=10){
        global $wpdb;
        $types = $this->post_types();
        $in = implode("','", array_map('esc_sql', $types));
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$this->table} s ON (s.post_id=p.ID AND s.status='indexed')
                WHERE p.post_status='publish' AND p.post_type IN ('{$in}') AND s.post_id IS NULL
                ORDER BY p.ID DESC LIMIT %d";
        return $wpdb->get_col($wpdb->prepare($sql,$batch));
    }

    /** Indexação em lote (10/10) */
    public function index_all($batch=10,$mode='auto'){
        $ids = $this->next_pending_post_ids($batch);
        $count = 0;
        foreach($ids as $id){
            $res = $this->index_one($id,'',false,$mode);
            if (is_array($res) && ($res['status'] ?? '') === 'ok'){
                $count++;
            }
        }
        return $count;
    }
}
