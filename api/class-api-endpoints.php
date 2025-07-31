<?php
/**
 * Classe para endpoints da API REST
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_API_Endpoints {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        register_rest_route('sincronizador-wc/v1', '/lojistas', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_lojistas'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        register_rest_route('sincronizador-wc/v1', '/lojista/(?P<id>\d+)/vendas', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_lojista_vendas'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                )
            )
        ));
        
        register_rest_route('sincronizador-wc/v1', '/sync/vendas', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_vendas'),
            'permission_callback' => array($this, 'check_permissions')
        ));
        
        register_rest_route('sincronizador-wc/v1', '/produtos/search', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_produtos'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'search' => array(
                    'required' => true,
                    'validate_callback' => function($param, $request, $key) {
                        return !empty($param);
                    }
                )
            )
        ));
        
        register_rest_route('sincronizador-wc/v1', '/produto/(?P<id>\d+)/import', array(
            'methods' => 'POST',
            'callback' => array($this, 'import_produto'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
                'lojistas' => array(
                    'required' => true,
                    'validate_callback' => function($param, $request, $key) {
                        return is_array($param) && !empty($param);
                    }
                )
            )
        ));
    }
    
    public function check_permissions($request) {
        return current_user_can('manage_sincronizador_wc');
    }
    
    /**
     * Lista todos os lojistas
     */
    public function get_lojistas($request) {
        $lojista_manager = new Sincronizador_WC_Lojista_Manager();
        $lojistas = $lojista_manager->get_lojistas();
        
        $response_data = array();
        
        foreach ($lojistas as $lojista) {
            $stats = $lojista_manager->get_lojista_stats($lojista->id);
            
            $response_data[] = array(
                'id' => $lojista->id,
                'nome' => $lojista->nome,
                'url_loja' => $lojista->url_loja,
                'status' => $lojista->status,
                'ultima_sincronizacao' => $lojista->ultima_sincronizacao,
                'stats' => $stats
            );
        }
        
        return rest_ensure_response($response_data);
    }
    
    /**
     * Obtém dados de vendas de um lojista específico
     */
    public function get_lojista_vendas($request) {
        $lojista_id = $request['id'];
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        $vendas = $sync_manager->get_sales_report($date_from, $date_to, $lojista_id);
        
        return rest_ensure_response($vendas);
    }
    
    /**
     * Força sincronização de vendas
     */
    public function sync_vendas($request) {
        $lojista_id = $request->get_param('lojista_id');
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        
        if ($lojista_id) {
            $result = $sync_manager->sync_lojista_sales($lojista_id, $date_from, $date_to);
        } else {
            $result = $sync_manager->force_sync();
        }
        
        if ($result['success']) {
            return rest_ensure_response($result);
        } else {
            return new WP_Error('sync_failed', $result['message'], array('status' => 500));
        }
    }
    
    /**
     * Busca produtos
     */
    public function search_produtos($request) {
        $search_term = $request['search'];
        $limit = $request->get_param('limit') ?: 20;
        
        $product_importer = new Sincronizador_WC_Product_Importer();
        $produtos = $product_importer->search_products($search_term, $limit);
        
        return rest_ensure_response($produtos);
    }
    
    /**
     * Importa produto para lojistas
     */
    public function import_produto($request) {
        $produto_id = $request['id'];
        $lojistas = $request['lojistas'];
        
        $product_importer = new Sincronizador_WC_Product_Importer();
        $results = $product_importer->import_product_to_lojistas($produto_id, $lojistas);
        
        $success_count = 0;
        $error_count = 0;
        $messages = array();
        
        foreach ($results as $result) {
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
                $messages[] = $result['message'];
            }
        }
        
        $response_data = array(
            'success_count' => $success_count,
            'error_count' => $error_count,
            'messages' => $messages,
            'results' => $results
        );
        
        if ($success_count > 0) {
            return rest_ensure_response($response_data);
        } else {
            return new WP_Error('import_failed', 'Falha ao importar produto', array('status' => 500, 'data' => $response_data));
        }
    }
}
