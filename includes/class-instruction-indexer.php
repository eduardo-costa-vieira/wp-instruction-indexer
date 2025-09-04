<?php
namespace WPUI;
defined('ABSPATH') || exit;

/**
 * Instruction_Indexer
 * Autor: Eduardo Vieira
 * v2.7.2
 *
 * - Extrai itens de estrutura (H2–H6) de posts publicados
 * - Gera 1–3 termos com stopwords PT-BR (peso maior no título)
 * - Não sobrescreve sinônimos existentes em reindex
 * - Lote 10/10, auditoria de pendentes e reindex seletiva por hash
 */
class Instruction_Indexer {
    protected $table;
    protected $stopwords;

    public function __construct(){
        global $wpdb;
        $this->table = $wpdb->prefix.'wpui_instruction_index';
        $this->stopwords = $this->ptbr_stopwords();
    }

    /** Cria/atualiza tabela */
    public function maybe_install(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          post_id BIGINT UNSIGNED NOT NULL,
          item_id VARCHAR(64) NOT NULL,
          item_anchor VARCHAR(191) NULL,
          item_title TEXT NULL,
          url TEXT NULL,
          terms TEXT NULL,
          synonyms TEXT NULL,
          item_hash CHAR(32) NULL,
          last_post_modified_gmt DATETIME NULL,
          indexed_at_gmt DATETIME NULL,
          status VARCHAR(20) DEFAULT 'indexed',
          mode VARCHAR(20) DEFAULT 'auto',
          PRIMARY KEY(id),
          KEY post_id (post_id),
          KEY status (status),
          KEY item_id (item_id)
        ) {$charset};";
        dbDelta($sql);

        // Garante coluna 'mode' em installs antigos
        $col = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME='mode'",
            DB_NAME, $this->table
        ));
        if (!$col){
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN mode VARCHAR(20) DEFAULT 'auto'");
        }
    }

    /** Post types alvo (filtrável) */
    public function post_types(){
        return apply_filters('wpui_structure_post_types', ['post']);
    }

    /** Estatísticas (publicados/indexados/pendentes) */
    public function stats(){
        global $wpdb;
        $types = $this->post_types();
        $in = implode("','", array_map('esc_sql', $types));
        $published = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('{$in}')"));
        $indexed_posts = intval($wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE status='indexed'"));
        $pending = max(0, $published - $indexed_posts);
        return ['published'=>$published,'indexed'=>$indexed_posts,'pending'=>$pending];
    }

    /** Resolve post por ID/URL */
    public function find_post($id, $url){
        $id = intval($id);
        if ($id>0) return get_post($id);
        if ($url){ $p=url_to_postid($url); if($p) return get_post($p); }
        return null;
    }

    /** Remove o índice de um post para reprocessamento */
    public function unindex($post_id){
        global $wpdb;
        $wpdb->delete($this->table, ['post_id'=>intval($post_id)]);
    }

    /** Normaliza texto (minúsculas, sem acentos, sem pontuação) */
    protected function normalize($text){
        $text = wp_strip_all_tags((string)$text);
        $text = strtolower($text);
        $text = remove_accents($text);
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /** Extrai itens da estrutura a partir de H2–H6 */
    protected function extract_items($post){
        $content = apply_filters('the_content',$post->post_content);
        $html = wp_kses_post($content);

        // Captura H2..H6
        preg_match_all('/<(h[2-6])[^>]*>(.*?)<\/\\1>/is', $html, $matches, PREG_SET_ORDER);
        $items = [];

        foreach($matches as $m){
            $title = wp_strip_all_tags($m[2]);

            // Se começa com "1.2.3 Título", preservar o índice como item_id
            if (preg_match('/^(\d+(?:\.\d+)*)\s*[-–—:]?\s*(.*)$/u', $title, $mm)){
                $item_id = $mm[1];
                $item_title = trim($mm[2]) ?: $title;
            } else {
                $item_id = '';
                $item_title = $title;
            }

            $items[] = [
                'item_id'    => $item_id,
                'item_title' => $item_title,
                'item_anchor'=> $this->build_anchor($post->ID, $item_id, $item_title),
            ];
        }

        return $items;
    }

    /** Gera anchor (#...) previsível para o item */
    protected function build_anchor($post_id,$item_id,$title){
        $base = $item_id ? $item_id : sanitize_title($title);
        $base = strtolower(preg_replace('/[^a-z0-9\.\-]+/','-', remove_accents($base)));
        return trim($base,'-');
    }

    /** Pega um pequeno trecho logo após o título (fallback simples: resumo do conteúdo) */
    protected function first_snippet_after($html,$title){
        $plain = wp_strip_all_tags($html);
        $plain = preg_replace('/\s+/', ' ', $plain);
        return wp_html_excerpt($plain, 240, '...');
    }

    /** Pesa termos (título vale mais) e retorna 1–3 termos */
    protected function weigh_terms($title,$snippet){
        $title_norm = $this->normalize($title);
        $snip_norm  = $this->normalize($snippet);
        $tf = [];

        foreach(array_filter(explode(' ',$snip_norm)) as $w){
            if (isset($this->stopwords[$w])) continue;
            $tf[$w] = ($tf[$w] ?? 0) + 1;
        }
        foreach(array_filter(explode(' ',$title_norm)) as $w){
            if (isset($this->stopwords[$w])) continue;
            $tf[$w] = ($tf[$w] ?? 0) + 3; // título tem peso maior
        }

        arsort($tf);
        $top = array_slice(array_keys($tf), 0, 3);
        return implode(', ', $top);
    }

    /** Hash do item (para reindex seletiva) */
    protected function item_hash($title,$snippet){
        return md5($this->normalize($title).'|'.$this->normalize($snippet));
    }

    /**
     * Indexa 1 post (estrutura)
     * - Exige ao menos 3 itens
     * - Não sobrescreve synonyms
     */
    public function index_one($id=0,$url='',$force=false,$mode='manual'){
        $post = $this->find_post($id,$url);
        if (!$post || $post->post_status!=='publish'){
            return ['status'=>'not_found','message'=>__('Instrução não encontrada/publicada.','wp-unified-indexer')];
        }

        $items = $this->extract_items($post);
        if (!$items || count($items) < 3){
            return ['status'=>'no_items','message'=>__('Sem estrutura suficiente (mín. 3 itens).','wp-unified-indexer')];
        }

        global $wpdb;
        $html = apply_filters('the_content',$post->post_content);

        foreach($items as $it){
            $item_id = $it['item_id'] ?: sanitize_title($it['item_title']);
            $title   = $it['item_title'];
            $anchor  = $it['item_anchor'];
            $url_i   = get_permalink($post).'#'.$anchor;

            $snippet = $this->first_snippet_after($html,$title);
            $terms   = $this->weigh_terms($title,$snippet);
            $hash    = $this->item_hash($title,$snippet);

            // busca linha existente para preservar synonyms
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id,item_hash,synonyms FROM {$this->table} WHERE post_id=%d AND item_id=%s LIMIT 1",
                $post->ID, $item_id
            ), ARRAY_A);

            // Monta dados (synonyms preserva valor já existente)
            $data = [
                'post_id'                => $post->ID,
                'item_id'                => $item_id,
                'item_anchor'            => $anchor,
                'item_title'             => $title,
                'url'                    => $url_i,
                'terms'                  => $terms,
                'synonyms'               => $row ? $row['synonyms'] : null, // mantém
                'item_hash'              => $hash,
                'last_post_modified_gmt' => $post->post_modified_gmt,
                'indexed_at_gmt'         => current_time('mysql',1),
                'status'                 => 'indexed',
                'mode'                   => $mode==='manual' ? 'manual' : 'auto',
            ];

            // Formatos (synonyms pode ser NULL → usar %s mesmo)
            $fmt = ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];

            if ($row){
                // Reindex seletiva: só atualiza se hash mudou ou se force
                if ($row['item_hash'] !== $hash || $force){
                    $wpdb->update($this->table, $data, ['id'=>$row['id']], $fmt, ['%d']);
                }
            } else {
                $wpdb->insert($this->table, $data, $fmt);
            }
        }

        return ['status'=>'ok','message'=>__('Estrutura indexada com sucesso.','wp-unified-indexer')];
    }

    /** Próximos posts pendentes (sem itens indexados) */
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

    /** Indexação em lote 10/10 */
    public function index_all($batch=10,$mode='auto'){
        $ids = $this->next_pending_post_ids($batch);
        $count = 0;
        foreach($ids as $id){
            $this->index_one($id,'',false,$mode);
            $count++;
        }
        return $count;
    }

    /** Lista base de stopwords PT-BR (normalizadas sem acento) */
    protected function ptbr_stopwords(){
        $w = [
            'a','as','o','os','um','uma','umas','uns',
            'de','do','da','dos','das','no','na','nos','nas',
            'em','por','para','com','sem','sobre','entre','desde','ate',
            'e','ou','mas','como','se','que','porque','por que','pois',
            'tambem','muito','pouco','mais','menos','ja','nao','sim',
            'sao','ser','estar','ter','haver','fazer','poder','dever',
            'este','esta','estes','estas','esse','essa','esses','essas',
            'aquele','aquela','aqueles','aquelas','isso','isto','aquilo',
            'cada','todo','toda','todos','todas','outro','outra','outros','outras',
            'meu','minha','meus','minhas','seu','sua','seus','suas',
            'nosso','nossa','nossos','nossas','lhe','lhes',
            'ele','ela','eles','elas','voce','voces','nos',
            'depois','antes','durante','quando','onde','aqui','ali','la','agora','entao',
            'hoje','ontem','amanha',
            'exemplo','exemplos','introducao','resumo','observacao','nota'
        ];
        $m = [];
        foreach($w as $x){
            $m[ remove_accents(strtolower($x)) ] = true;
        }
        return $m;
    }
}
