<?php
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../includes/class-instruction-indexer.php';
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!function_exists('apply_filters')) { function apply_filters($tag,$value){ return $value; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($v){ return $v; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($text){ return strip_tags($text); } }
if (!function_exists('sanitize_title')) { function sanitize_title($title){ $title = strtolower($title); $title = preg_replace('/[^a-z0-9]+/','-',$title); return trim($title,'-'); } }
if (!function_exists('remove_accents')) { function remove_accents($str){ return $str; } }
if (!function_exists('wp_html_excerpt')) { function wp_html_excerpt($text,$len,$more=''){ return mb_substr($text,0,$len).$more; } }
if (!function_exists('strip_shortcodes')) { function strip_shortcodes($content){ return preg_replace('/\[[^\]]+\]/','',$content); } }
if (!function_exists('__')) { function __($text){ return $text; } }
if (!function_exists('get_permalink')) { function get_permalink($post){ return 'http://example.com/?p='.$post->ID; } }
if (!function_exists('current_time')) { function current_time($type,$gmt){ return '2023-01-01 00:00:00'; } }
if (!function_exists('get_post')) { function get_post($id){ global $posts; return $posts[$id] ?? null; } }
if (!function_exists('url_to_postid')) { function url_to_postid($url){ return 0; } }

class DummyWPDB {
    public $prefix = 'wp_';
    public function prepare($query, ...$args){ return $query; }
    public function get_row($query, $output){ return null; }
    public function update($table,$data,$where,$format=null,$where_format=null){ }
    public function insert($table,$data,$format=null){ }
}

global $wpdb;
$wpdb = new DummyWPDB();

class TestIndexer extends WPUI\Instruction_Indexer {
    public function extract_public($post){ return $this->extract_items($post); }
}

function make_post($id, $content){
    global $posts;
    $posts[$id] = (object)[
        'ID'=>$id,
        'post_content'=>$content,
        'post_status'=>'publish',
        'post_modified_gmt'=>'2023-01-01 00:00:00'
    ];
    return $posts[$id];
}

$indexer = new TestIndexer();

$tests = [];

$tests[] = function() use ($indexer){
    make_post(1, '<h2>One</h2><p>Text</p><h3>Two</h3>');
    $result = $indexer->index_one(1);
    assert($result['status'] === 'no_items');
};

$tests[] = function() use ($indexer){
    $content = '<h2 class="wp-block-heading">One</h2><div class="wp-block-shortcode"><h2>SC</h2></div><h2 class="wp-block-heading">Two</h2><h3>Three</h3>';
    make_post(2, $content);
    $result = $indexer->index_one(2);
    assert($result['status'] === 'ok');
    $items = $indexer->extract_public(get_post(2));
    assert(count($items) === 3);
};

foreach($tests as $i => $t){
    $t();
    echo 'Test '.($i+1)." passed\n";
}
