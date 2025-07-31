<?php
/**
 * Classe para manipular requisições AJAX do admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Admin_Ajax {
    
    public function __construct() {
        // AJAX para usuários logados
        add_action('wp_ajax_sincronizador_wc_get_product_details', array($this, 'get_product_details'));
        add_action('wp_ajax_sincronizador_wc_import_product', array($this, 'import_product'));
        add_action('wp_ajax_sincronizador_wc_force_sync', array($this, 'force_sync'));
        add_action('wp_ajax_sincronizador_wc_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_sincronizador_wc_delete_lojista', array($this, 'delete_lojista'));
    }
    
    /**
     * Obtém detalhes completos de um produto
     */
    public function get_product_details() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => __('ID do produto inválido', 'sincronizador-wc')));
        }
        
        $product_importer = new Sincronizador_WC_Product_Importer();
        $product = $product_importer->get_product_details($product_id);
        
        if (!$product) {
            wp_send_json_error(array('message' => __('Produto não encontrado', 'sincronizador-wc')));
        }
        
        // Gerar HTML dos detalhes
        ob_start();
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/product-details.php';
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * Importa produto para lojistas selecionados
     */
    public function import_product() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        $product_id = intval($_POST['product_id']);
        $lojistas = array_map('intval', $_POST['lojistas']);
        
        if (!$product_id || empty($lojistas)) {
            wp_send_json_error(array('message' => __('Dados inválidos', 'sincronizador-wc')));
        }
        
        $product_importer = new Sincronizador_WC_Product_Importer();
        $results = $product_importer->import_product_to_lojistas($product_id, $lojistas);
        
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
        
        if ($success_count > 0 && $error_count === 0) {
            wp_send_json_success(array(
                'message' => sprintf(__('Produto importado com sucesso para %d lojista(s)', 'sincronizador-wc'), $success_count)
            ));
        } elseif ($success_count > 0 && $error_count > 0) {
            wp_send_json_success(array(
                'message' => sprintf(__('Produto importado para %d lojista(s), %d falharam. Erros: %s', 'sincronizador-wc'), 
                    $success_count, $error_count, implode(', ', $messages))
            ));
        } else {
            wp_send_json_error(array(
                'message' => sprintf(__('Falha ao importar produto. Erros: %s', 'sincronizador-wc'), implode(', ', $messages))
            ));
        }
    }
    
    /**
     * Força sincronização manual
     */
    public function force_sync() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        $lojista_id = isset($_POST['lojista_id']) ? intval($_POST['lojista_id']) : null;
        
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        $result = $sync_manager->force_sync($lojista_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Testa conexão com lojista
     */
    public function test_connection() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        
        if (!$lojista_id) {
            wp_send_json_error(array('message' => __('ID do lojista inválido', 'sincronizador-wc')));
        }
        
        $lojista_manager = new Sincronizador_WC_Lojista_Manager();
        $result = $lojista_manager->test_connection($lojista_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Exclui lojista
     */
    public function delete_lojista() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        
        if (!$lojista_id) {
            wp_send_json_error(array('message' => __('ID do lojista inválido', 'sincronizador-wc')));
        }
        
        $lojista_manager = new Sincronizador_WC_Lojista_Manager();
        $result = $lojista_manager->delete_lojista($lojista_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
