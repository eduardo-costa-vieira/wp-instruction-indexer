<?php
namespace WPUI;
defined('ABSPATH') || exit;

class REST {
    public static function init(){ add_action('rest_api_init', [__CLASS__, 'register_routes']); }

    public static function register_routes(){
        register_rest_route('wpui/v1', '/fulltext/index-one', [
            'methods'=>'POST','callback'=>[__CLASS__,'ft_index_one'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); },
        ]);
        register_rest_route('wpui/v1', '/fulltext/index-all', [
            'methods'=>'POST','callback'=>[__CLASS__,'ft_index_all'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); },
        ]);
        register_rest_route('wpui/v1', '/structure/index-one', [
            'methods'=>'POST','callback'=>[__CLASS__,'st_index_one'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); },
        ]);
        register_rest_route('wpui/v1', '/structure/index-all', [
            'methods'=>'POST','callback'=>[__CLASS__,'st_index_all'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); },
        ]);
    }

    public static function ft_index_one($req){
        $id = intval($req['id'] ?? 0);
        $url = esc_url_raw($req['url'] ?? '');
        $force = !empty($req['force_reindex']);
        $mode = !empty($req['mode']) ? sanitize_text_field($req['mode']) : 'manual';
        $idx = new Fulltext_Indexer();
        return rest_ensure_response($idx->index_one($id,$url,$force,$mode));
    }
    public static function ft_index_all($req){
        $batch = max(1,min(100,intval($req['batch'] ?? 10)));
        $idx = new Fulltext_Indexer();
        return rest_ensure_response(['processed'=>$idx->index_all($batch,'auto')]);
    }
    public static function st_index_one($req){
        $id = intval($req['id'] ?? 0);
        $url = esc_url_raw($req['url'] ?? '');
        $force = !empty($req['force_reindex']);
        $mode = !empty($req['mode']) ? sanitize_text_field($req['mode']) : 'manual';
        $idx = new Instruction_Indexer();
        return rest_ensure_response($idx->index_one($id,$url,$force,$mode));
    }
    public static function st_index_all($req){
        $batch = max(1,min(100,intval($req['batch'] ?? 10)));
        $idx = new Instruction_Indexer();
        $stats = $idx->index_all($batch,'auto');
        return rest_ensure_response($stats);
    }
}
