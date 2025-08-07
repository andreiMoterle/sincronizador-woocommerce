<?php
/**
 * Classe principal do admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Admin {
    
    /**
     * Instância da classe de validação de permissões
     */
    private $permission_validator;
    
    public function __construct() {
        // REMOVIDO: add_action('admin_menu', array($this, 'add_admin_menu'));
        // Menus agora são gerenciados pela classe Sincronizador_WC_Admin_Menu
        
        add_action('admin_init', array($this, 'admin_init'));
        
        // REMOVIDO: add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        // Assets agora são gerenciados pela classe Sincronizador_WC_Assets
        
        // Inicializar validador de permissões
        $this->permission_validator = new Sincronizador_WC_Permission_Validator();
    }
    
    /**
     * REMOVIDO - DUPLICAÇÃO ELIMINADA
     * 
     * Os menus agora são gerenciados exclusivamente pela classe Sincronizador_WC_Admin_Menu
     * para evitar duplicação de código e conflitos.
     * 
     * @see admin/class-admin-menu.php
     */
    
    public function admin_init() {
        // Registrar configurações
        register_setting('sincronizador_wc_settings', 'sincronizador_wc_options');
    }
    
    /**
     * REMOVIDO - DUPLICAÇÃO ELIMINADA
     * 
     * O enqueue de scripts agora é gerenciado exclusivamente pela classe Sincronizador_WC_Assets
     * para evitar duplicação de código e conflitos.
     * 
     * @see admin/class-assets.php
     */
    
    public function dashboard_page() {
        $stats = Sincronizador_WC_Database::get_stats();
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    
    public function lojistas_page() {
        $lojista_manager = new Sincronizador_WC_Lojista_Manager();
        
        // Processar ações
        if (isset($_POST['action'])) {
            $this->process_lojista_action($_POST, $lojista_manager);
        }
        
        $lojistas = $lojista_manager->get_lojistas();
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/lojistas.php';
    }
    
    public function importar_page() {
        $product_importer = new Sincronizador_WC_Product_Importer();
        
        // Processar busca
        $products = array();
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $products = $product_importer->search_products(sanitize_text_field($_GET['search']));
        }
        
        $lojistas = $product_importer->get_active_lojistas();
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/importar.php';
    }
    
    public function relatorios_page() {
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        
        // Obter dados para relatórios
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-01');
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-t');
        
        $sales_report = $sync_manager->get_sales_report($date_from, $date_to);
        $lojistas_summary = $sync_manager->get_lojistas_summary();
        
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/relatorios.php';
    }
    
    public function logs_page() {
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        $logs = $sync_manager->get_sync_logs();
        
        include SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/views/logs.php';
    }
    
    private function process_lojista_action($post_data, $lojista_manager) {
        if (!wp_verify_nonce($post_data['_wpnonce'], 'sincronizador_wc_lojista_action')) {
            wp_die(__('Token de segurança inválido', 'sincronizador-wc'));
        }
        
        $action = sanitize_text_field($post_data['action']);
        $message = '';
        $type = 'error';
        
        switch ($action) {
            case 'add_lojista':
                $result = $lojista_manager->add_lojista($post_data);
                $message = $result['message'];
                $type = $result['success'] ? 'success' : 'error';
                break;
                
            case 'update_lojista':
                $id = intval($post_data['lojista_id']);
                $result = $lojista_manager->update_lojista($id, $post_data);
                $message = $result['message'];
                $type = $result['success'] ? 'success' : 'error';
                break;
                
            case 'delete_lojista':
                $id = intval($post_data['lojista_id']);
                $result = $lojista_manager->delete_lojista($id);
                $message = $result['message'];
                $type = $result['success'] ? 'success' : 'error';
                break;
        }
        
        if ($message) {
            add_action('admin_notices', function() use ($message, $type) {
                echo "<div class='notice notice-{$type} is-dismissible'><p>{$message}</p></div>";
            });
        }
    }
}
