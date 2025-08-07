<?php
/**
 * Classe para gerenciar assets (CSS/JS) do plugin
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Assets {
    
    /**
     * Validador de permissões centralizado
     */
    private $permission_validator;
    
    public function __construct() {
        // Inicializar validador de permissões
        $this->permission_validator = Sincronizador_WC_Permission_Validator::get_instance();
        
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Enfileira assets do admin - REFATORADO
     */
    public function enqueue_admin_assets($hook) {
        // Usar validador centralizado
        if (!$this->permission_validator->is_plugin_page($hook)) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'sincronizador-wc-admin-css',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            SINCRONIZADOR_WC_VERSION
        );
        
        // JavaScript - Ordem de carregamento otimizada
        
        // 1. Utilitários centralizados (NOVO - elimina duplicações)
        wp_enqueue_script(
            'sincronizador-wc-utils',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/sincronizador-utils.js',
            array('jquery'),
            SINCRONIZADOR_WC_VERSION,
            true
        );
        
        // 2. Scripts principais (dependem dos utilitários)
        wp_enqueue_script(
            'sincronizador-wc-admin-js',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery', 'sincronizador-wc-utils'),
            SINCRONIZADOR_WC_VERSION,
            true
        );
        
        // 3. Batch admin (se necessário)
        if (strpos($hook, 'batch') !== false || strpos($hook, 'importar') !== false) {
            wp_enqueue_script(
                'sincronizador-wc-batch-admin',
                SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/batch-admin.js',
                array('jquery', 'sincronizador-wc-utils'),
                SINCRONIZADOR_WC_VERSION,
                true
            );
        }
        
        // Localizar script com dados necessários - CENTRALIZADO
        wp_localize_script('sincronizador-wc-admin-js', 'SincronizadorWC', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sincronizador_wc_nonce'),
            'strings' => array(
                'confirmDelete' => __('Tem certeza que deseja remover?', 'sincronizador-wc'),
                'loading' => __('Carregando...', 'sincronizador-wc'),
                'error' => __('Erro na requisição', 'sincronizador-wc'),
                'success' => __('Operação realizada com sucesso', 'sincronizador-wc'),
                'validatingConnection' => __('Testando conexão...', 'sincronizador-wc'),
                'connectionSuccess' => __('Conexão OK!', 'sincronizador-wc'),
                'connectionError' => __('Erro na conexão', 'sincronizador-wc'),
                'selectProducts' => __('Selecione pelo menos um produto', 'sincronizador-wc'),
                'importingProducts' => __('Importando produtos...', 'sincronizador-wc'),
                'syncingData' => __('Sincronizando dados...', 'sincronizador-wc'),
                'processing' => __('Processando...', 'sincronizador-wc'),
                'confirm_delete' => __('Tem certeza que deseja excluir?', 'sincronizador-wc')
            )
        ));
    }
    
    /**
     * REMOVIDO - DUPLICAÇÃO ELIMINADA
     * 
     * Verificação de páginas do plugin agora centralizada em 
     * Sincronizador_WC_Permission_Validator::is_plugin_page()
     * 
     * @see admin/class-permission-validator.php
     */
}
