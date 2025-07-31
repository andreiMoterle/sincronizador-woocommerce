<?php
/**
 * Classe para gerenciar assets (CSS/JS) do plugin
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Assets {
    
    public function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Enfileira assets do admin
     */
    public function enqueue_admin_assets($hook) {
        // Verificar se estamos nas páginas do plugin
        if (!$this->is_plugin_page($hook)) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'sincronizador-wc-admin-css',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            SINCRONIZADOR_WC_VERSION
        );
        
        // JavaScript
        wp_enqueue_script(
            'sincronizador-wc-admin-js',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery'),
            SINCRONIZADOR_WC_VERSION,
            true
        );
        
        // Localizar script com dados necessários
        wp_localize_script('sincronizador-wc-admin-js', 'SincronizadorWC', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sincronizador_wc_nonce'),
            'strings' => array(
                'confirmDelete' => 'Tem certeza que deseja remover?',
                'loading' => 'Carregando...',
                'error' => 'Erro na requisição',
                'success' => 'Operação realizada com sucesso',
                'validatingConnection' => 'Testando conexão...',
                'connectionSuccess' => 'Conexão OK!',
                'connectionError' => 'Erro na conexão',
                'selectProducts' => 'Selecione pelo menos um produto',
                'importingProducts' => 'Importando produtos...',
                'syncingData' => 'Sincronizando dados...'
            )
        ));
    }
    
    /**
     * Verificar se estamos numa página do plugin
     */
    private function is_plugin_page($hook) {
        $plugin_pages = array(
            'toplevel_page_sincronizador-wc',
            'sincronizador-wc_page_sincronizador-wc-lojistas',
            'sincronizador-wc_page_sincronizador-wc-add-lojista',
            'sincronizador-wc_page_sincronizador-wc-importar',
            'sincronizador-wc_page_sincronizador-wc-sincronizados',
            'sincronizador-wc_page_sincronizador-wc-config'
        );
        
        return in_array($hook, $plugin_pages);
    }
}
