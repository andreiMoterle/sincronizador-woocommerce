<?php
/**
 * Classe utilitária para validação de permissões - NOVO
 * Centraliza validações de permissão que estavam duplicadas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Permission_Validator {
    
    /**
     * Instância singleton
     */
    private static $instance = null;
    
    /**
     * Retorna instância singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Verifica se usuário pode gerenciar o sincronizador
     * Centraliza verificação que estava duplicada em múltiplos arquivos
     * 
     * @param bool $die_on_failure Se deve terminar execução em falha
     * @return bool
     */
    public function can_manage_sincronizador($die_on_failure = true) {
        $can_manage = current_user_can('manage_sincronizador_wc') || current_user_can('manage_woocommerce');
        
        if (!$can_manage && $die_on_failure) {
            wp_die(__('Você não tem permissão para realizar esta ação.', 'sincronizador-wc'));
        }
        
        return $can_manage;
    }
    
    /**
     * Verifica se usuário pode visualizar relatórios
     * 
     * @param bool $die_on_failure Se deve terminar execução em falha
     * @return bool
     */
    public function can_view_reports($die_on_failure = true) {
        $can_view = current_user_can('view_sincronizador_wc_reports') || current_user_can('manage_woocommerce');
        
        if (!$can_view && $die_on_failure) {
            wp_die(__('Você não tem permissão para visualizar relatórios.', 'sincronizador-wc'));
        }
        
        return $can_view;
    }
    
    /**
     * Verifica nonce para AJAX - Centraliza verificação duplicada
     * 
     * @param string $nonce_name Nome do nonce
     * @param string $nonce_value Valor do nonce (padrão: $_POST['nonce'])
     * @return void
     */
    public function verify_ajax_nonce($nonce_name = 'sincronizador_wc_nonce', $nonce_value = null) {
        if ($nonce_value === null) {
            $nonce_value = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        }
        
        if (!wp_verify_nonce($nonce_value, $nonce_name)) {
            wp_send_json_error(array(
                'message' => __('Token de segurança inválido.', 'sincronizador-wc')
            ));
        }
    }
    
    /**
     * Verifica nonce e permissões para AJAX - Combina verificações comuns
     * 
     * @param string $nonce_name Nome do nonce
     * @param string $capability Capacidade requerida
     * @return void
     */
    public function verify_ajax_request($nonce_name = 'sincronizador_wc_nonce', $capability = 'manage_sincronizador_wc') {
        // Verificar nonce
        check_ajax_referer($nonce_name, 'nonce');
        
        // Verificar permissões
        if (!current_user_can($capability) && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Você não tem permissão para realizar esta ação.', 'sincronizador-wc')
            ));
        }
    }
    
    /**
     * Sanitiza ID de post/produto - Centraliza validação duplicada
     * 
     * @param mixed $id ID a ser validado
     * @param string $error_message Mensagem de erro personalizada
     * @return int ID validado
     */
    public function validate_id($id, $error_message = null) {
        $validated_id = intval($id);
        
        if ($validated_id <= 0) {
            if ($error_message === null) {
                $error_message = __('ID inválido fornecido.', 'sincronizador-wc');
            }
            
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => $error_message));
            } else {
                wp_die($error_message);
            }
        }
        
        return $validated_id;
    }
    
    /**
     * Valida array de IDs - Remove duplicação de validação de múltiplos IDs
     * 
     * @param array $ids Array de IDs para validar
     * @param string $error_message Mensagem de erro personalizada
     * @return array Array de IDs validados
     */
    public function validate_ids_array($ids, $error_message = null) {
        if (!is_array($ids) || empty($ids)) {
            if ($error_message === null) {
                $error_message = __('Nenhum item selecionado.', 'sincronizador-wc');
            }
            
            if (wp_doing_ajax()) {
                wp_send_json_error(array('message' => $error_message));
            } else {
                wp_die($error_message);
            }
        }
        
        return array_map('intval', $ids);
    }
    
    /**
     * Verifica se é página do plugin - Centraliza verificação duplicada
     * 
     * @param string $hook Hook da página atual
     * @return bool
     */
    public function is_plugin_page($hook) {
        $plugin_hooks = array(
            'toplevel_page_sincronizador-wc',
            'sincronizador-wc_page_sincronizador-wc-lojistas',
            'sincronizador-wc_page_sincronizador-wc-add-lojista',
            'sincronizador-wc_page_sincronizador-wc-importar',
            'sincronizador-wc_page_sincronizador-wc-relatorios',
            'sincronizador-wc_page_sincronizador-wc-config',
            'sincronizador-wc_page_sincronizador-wc-logs',
            'sincronizador-wc_page_sincronizador-wc-sincronizados'
        );
        
        return in_array($hook, $plugin_hooks);
    }
}
