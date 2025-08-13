<?php
/**
 * Classe para gerenciar menus do admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Admin_Menu {
    
    public function __construct() {
        error_log('DEBUG: Sincronizador_WC_Admin_Menu construtor chamado');
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
    }
    
    /**
     * Adiciona menus do admin
     */
    public function add_admin_menu() {
        error_log('DEBUG: add_admin_menu() chamado');
        // Menu principal
        add_menu_page(
            __('Sincronizador WC', 'sincronizador-wc'),
            __('Sincronizador WC', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc',
            array($this, 'dashboard_page'),
            'dashicons-update',
            58
        );
        
        // Dashboard
        add_submenu_page(
            'sincronizador-wc',
            __('Dashboard', 'sincronizador-wc'),
            __('Dashboard', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc',
            array($this, 'dashboard_page')
        );
        
        // Lojistas
        add_submenu_page(
            'sincronizador-wc',
            __('Lojistas', 'sincronizador-wc'),
            __('Lojistas', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc-lojistas',
            array($this, 'lojistas_page')
        );
        
        // Importar Produtos
        add_submenu_page(
            'sincronizador-wc',
            __('Importar Produtos', 'sincronizador-wc'),
            __('Importar Produtos', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc-importar',
            array($this, 'importar_page')
        );
        
        // Relatórios
        add_submenu_page(
            'sincronizador-wc',
            __('Relatórios', 'sincronizador-wc'),
            __('Relatórios', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc-relatorios',
            array($this, 'relatorios_page')
        );
        
        // Configurações
        add_submenu_page(
            'sincronizador-wc',
            __('Configurações', 'sincronizador-wc'),
            __('Configurações', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc-config',
            array($this, 'config_page')
        );
        
        // Logs
        add_submenu_page(
            'sincronizador-wc',
            __('Logs', 'sincronizador-wc'),
            __('Logs', 'sincronizador-wc'),
            'manage_woocommerce',
            'sincronizador-wc-logs',
            array($this, 'logs_page')
        );
    }
    
    /**
     * Inicialização do admin
     */
    public function admin_init() {
        // Registrar configurações
        register_setting('sincronizador_wc_settings', 'sincronizador_wc_master_token');
        register_setting('sincronizador_wc_settings', 'sincronizador_wc_batch_size');
        register_setting('sincronizador_wc_settings', 'sincronizador_wc_cache_enabled');
        register_setting('sincronizador_wc_settings', 'sincronizador_wc_debug_enabled');
    }
    
    /**
     * Página do Dashboard
     */
    public function dashboard_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Dashboard - Sincronizador WC', 'sincronizador-wc') . '</h1>';
        
        // Verificar se as classes existem antes de usar
        if (class_exists('Sincronizador_WC_Cache')) {
            $cache = Sincronizador_WC_Cache::instance();
            $overview = $cache->cache_fabrica_overview();
        } else {
            $overview = array(
                'total_lojistas' => 0,
                'lojistas_ativos' => 0,
                'produtos_sincronizados' => 0,
                'vendas_totais' => 0
            );
        }
        
        ?>
        <div class="sincronizador-dashboard">
            <div class="performance-dashboard">
                <div class="performance-widget">
                    <h3><?php _e('Resumo Geral', 'sincronizador-wc'); ?></h3>
                    <div class="metric-grid">
                        <div class="metric-item">
                            <span class="metric-value"><?php echo isset($overview['total_lojistas']) ? $overview['total_lojistas'] : 0; ?></span>
                            <span class="metric-label"><?php _e('Total Lojistas', 'sincronizador-wc'); ?></span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-value"><?php echo isset($overview['lojistas_ativos']) ? $overview['lojistas_ativos'] : 0; ?></span>
                            <span class="metric-label"><?php _e('Lojistas Ativos', 'sincronizador-wc'); ?></span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-value"><?php echo isset($overview['produtos_sincronizados']) ? $overview['produtos_sincronizados'] : 0; ?></span>
                            <span class="metric-label"><?php _e('Produtos Sincronizados', 'sincronizador-wc'); ?></span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-value"><?php echo isset($overview['vendas_totais']) ? number_format($overview['vendas_totais']) : 0; ?></span>
                            <span class="metric-label"><?php _e('Vendas Totais', 'sincronizador-wc'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="performance-widget">
                    <h3><?php _e('Status do Sistema', 'sincronizador-wc'); ?></h3>
                    <div class="cache-status">
                        <div class="cache-indicator"></div>
                        <span class="cache-status-text"><?php _e('Sistema funcionando normalmente', 'sincronizador-wc'); ?></span>
                        <div class="cache-controls">
                            <button class="cache-btn" data-action="refresh"><?php _e('Atualizar', 'sincronizador-wc'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .sincronizador-dashboard { margin-top: 20px; }
        .performance-dashboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .performance-widget { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
        .performance-widget h3 { margin: 0 0 15px 0; color: #1d2327; border-bottom: 2px solid #00a32a; padding-bottom: 10px; }
        .metric-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .metric-item { text-align: center; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .metric-value { font-size: 20px; font-weight: bold; color: #00a32a; display: block; }
        .metric-label { font-size: 11px; color: #666; text-transform: uppercase; margin-top: 5px; }
        .cache-status { display: flex; align-items: center; justify-content: space-between; padding: 10px 15px; background: #e7f5e7; border-radius: 4px; }
        .cache-indicator { width: 12px; height: 12px; border-radius: 50%; background: #00a32a; margin-right: 10px; }
        .cache-btn { padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; cursor: pointer; background: #00a32a; color: white; }
        </style>
        <?php
        
        echo '</div>';
    }
    
    /**
     * Página de Lojistas
     */
    public function lojistas_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Gerenciar Lojistas', 'sincronizador-wc') . '</h1>';
        echo '<p>' . __('Aqui você pode gerenciar seus lojistas parceiros.', 'sincronizador-wc') . '</p>';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>' . __('Funcionalidade em desenvolvimento:', 'sincronizador-wc') . '</strong> ';
        echo __('A interface de lojistas será implementada na próxima versão.', 'sincronizador-wc') . '</p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Página de Importação
     */
    public function importar_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Importar Produtos', 'sincronizador-wc') . '</h1>';
        echo '<p>' . __('Interface para importação em lote de produtos.', 'sincronizador-wc') . '</p>';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>' . __('Funcionalidade em desenvolvimento:', 'sincronizador-wc') . '</strong> ';
        echo __('A interface de importação será implementada na próxima versão.', 'sincronizador-wc') . '</p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Página de Relatórios
     */
    public function relatorios_page() {
        // Debug: verificar se o método está sendo chamado
        error_log('DEBUG: relatorios_page() foi chamado');
        
        // Carregar estilos e scripts específicos da página de relatórios
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_style('sincronizador-wc-reports', plugins_url('admin/css/reports.css', dirname(__FILE__)), array(), '1.0.0');
        wp_enqueue_script('sincronizador-wc-reports', plugins_url('admin/js/reports.js', dirname(__FILE__)), array('jquery', 'chart-js'), '1.0.0', true);
        
        // Localizar script para AJAX
        wp_localize_script('sincronizador-wc-reports', 'sincronizador_wc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sincronizador_wc_nonce')
        ));
        
        // Debug: verificar se o template existe
        $template_path = dirname(__FILE__) . '/templates/reports-page.php';
        error_log('DEBUG: template path = ' . $template_path);
        error_log('DEBUG: template exists = ' . (file_exists($template_path) ? 'YES' : 'NO'));
        
        // Incluir template
        if (file_exists($template_path)) {
            include_once $template_path;
        } else {
            echo '<div class="wrap"><h1>Erro: Template não encontrado</h1><p>Path: ' . $template_path . '</p></div>';
        }
    }
    
    /**
     * Página de Configurações
     */
    public function config_page() {
        if (isset($_POST['submit'])) {
            // Salvar configurações
            if (isset($_POST['master_token'])) {
                update_option('sincronizador_wc_master_token', sanitize_text_field($_POST['master_token']));
            }
            if (isset($_POST['batch_size'])) {
                update_option('sincronizador_wc_batch_size', intval($_POST['batch_size']));
            }
            if (isset($_POST['cache_enabled'])) {
                update_option('sincronizador_wc_cache_enabled', $_POST['cache_enabled'] === '1');
            }
            if (isset($_POST['debug_enabled'])) {
                update_option('sincronizador_wc_debug_enabled', $_POST['debug_enabled'] === '1');
            }
            
            echo '<div class="notice notice-success"><p>' . __('Configurações salvas com sucesso!', 'sincronizador-wc') . '</p></div>';
        }
        
        $master_token = get_option('sincronizador_wc_master_token', '');
        $batch_size = get_option('sincronizador_wc_batch_size', 50);
        $cache_enabled = get_option('sincronizador_wc_cache_enabled', true);
        $debug_enabled = get_option('sincronizador_wc_debug_enabled', false);
        
        echo '<div class="wrap">';
        echo '<h1>' . __('Configurações - Sincronizador WC', 'sincronizador-wc') . '</h1>';
        
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('sincronizador_wc_config', 'sincronizador_wc_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Token Master', 'sincronizador-wc'); ?></th>
                    <td>
                        <input type="text" name="master_token" value="<?php echo esc_attr($master_token); ?>" class="regular-text" />
                        <p class="description"><?php _e('Token para integração com painel master das fábricas.', 'sincronizador-wc'); ?></p>
                        <button type="button" class="button" onclick="document.querySelector('[name=master_token]').value = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);"><?php _e('Gerar Novo Token', 'sincronizador-wc'); ?></button>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Tamanho do Lote', 'sincronizador-wc'); ?></th>
                    <td>
                        <input type="number" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="10" max="200" />
                        <p class="description"><?php _e('Número de produtos processados por lote (10-200).', 'sincronizador-wc'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Cache Ativado', 'sincronizador-wc'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cache_enabled" value="1" <?php checked($cache_enabled); ?> />
                            <?php _e('Ativar sistema de cache para melhor performance', 'sincronizador-wc'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Debug Ativado', 'sincronizador-wc'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_enabled" value="1" <?php checked($debug_enabled); ?> />
                            <?php _e('Ativar logs detalhados para debugging', 'sincronizador-wc'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2><?php _e('Informações do Sistema', 'sincronizador-wc'); ?></h2>
        <table class="widefat">
            <tr>
                <td><strong><?php _e('Versão do Plugin:', 'sincronizador-wc'); ?></strong></td>
                <td><?php echo SINCRONIZADOR_WC_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('WordPress:', 'sincronizador-wc'); ?></strong></td>
                <td><?php echo get_bloginfo('version'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('WooCommerce:', 'sincronizador-wc'); ?></strong></td>
                <td><?php echo class_exists('WooCommerce') ? WC()->version : __('Não instalado', 'sincronizador-wc'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('PHP:', 'sincronizador-wc'); ?></strong></td>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('MySQL:', 'sincronizador-wc'); ?></strong></td>
                <td><?php global $wpdb; echo $wpdb->get_var("SELECT VERSION()"); ?></td>
            </tr>
        </table>
        <?php
        
        echo '</div>';
    }
    
    /**
     * Página de Logs
     */
    public function logs_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Logs do Sistema', 'sincronizador-wc') . '</h1>';
        echo '<p>' . __('Visualização de logs e atividades do sistema.', 'sincronizador-wc') . '</p>';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>' . __('Funcionalidade em desenvolvimento:', 'sincronizador-wc') . '</strong> ';
        echo __('A visualização de logs será implementada na próxima versão.', 'sincronizador-wc') . '</p>';
        echo '</div>';
        
        echo '</div>';
    }
}
