<?php
/**
 * Classe de ativação do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Activator {
    
    public static function activate() {
        // Verificar se WooCommerce está ativo
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Este plugin requer WooCommerce para funcionar.');
        }
        
        // Criar tabelas do banco de dados
        require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-database.php';
        Sincronizador_WC_Database::create_tables();
        
        // Criar páginas necessárias
        self::create_pages();
        
        // Definir capacidades
        self::add_capabilities();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Adicionar opção de versão
        add_option('sincronizador_wc_version', SINCRONIZADOR_WC_VERSION);
        add_option('sincronizador_wc_activation_date', current_time('mysql'));
    }
    
    private static function create_pages() {
        // Criar página de configurações se não existir
        $page = get_page_by_title('Sincronizador WooCommerce');
        
        if (!$page) {
            wp_insert_post(array(
                'post_title' => 'Sincronizador WooCommerce',
                'post_content' => '[sincronizador_wc_dashboard]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => 'sincronizador-woocommerce'
            ));
        }
    }
    
    private static function add_capabilities() {
        // Adicionar capacidades para administradores
        $role = get_role('administrator');
        
        if ($role) {
            $role->add_cap('manage_sincronizador_wc');
            $role->add_cap('view_sincronizador_wc_reports');
        }
        
        // Adicionar capacidades para shop managers
        $role = get_role('shop_manager');
        
        if ($role) {
            $role->add_cap('manage_sincronizador_wc');
            $role->add_cap('view_sincronizador_wc_reports');
        }
    }
}
