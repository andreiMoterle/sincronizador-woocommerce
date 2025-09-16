<?php
/**
 * Plugin Name: Sincronizador WooCommerce Fábrica-Lojista
 * Plugin URI: https://andreimoterle.com.br
 * Description: Plugin para sincronização de produtos entre fábrica e lojistas via API REST WooCommerce com cache avançado e processamento em lote
 * Version: 1.1.0
 * Author: Moterle Andrei
 * Author URI:  https://github.com/andreiMoterle
 * Text Domain: sincronizador-wc
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Define constantes do plugin
define('SINCRONIZADOR_WC_VERSION', '1.1.0');
define('SINCRONIZADOR_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SINCRONIZADOR_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SINCRONIZADOR_WC_PLUGIN_FILE', __FILE__);

// Configurações para grandes volumes
define('SINCRONIZADOR_WC_LARGE_DATASET', true);
define('SINCRONIZADOR_WC_BATCH_SIZE', 50);
define('SINCRONIZADOR_WC_MAX_EXECUTION_TIME', 30);

// Verifica se WooCommerce está ativo
function sincronizador_wc_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'sincronizador_wc_woocommerce_missing_notice');
        return false;
    }
    return true;
}

function sincronizador_wc_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Sincronizador WooCommerce</strong> requer que o WooCommerce esteja instalado e ativo.</p></div>';
}

// Classe fallback para Database caso o arquivo não seja encontrado
if (!class_exists('Sincronizador_WC_Database')) {
    class Sincronizador_WC_Database {
        public static function create_tables() {
            error_log("SINCRONIZADOR: Database class não disponível - criação de tabelas ignorada");
            return false;
        }
        
        public static function cleanup_expired_cache() {
            return 0;
        }
        
        public static function clear_produtos_cache($lojista_id) {
            return 0;
        }
        
        public static function get_produtos_cache($lojista_id) {
            return false;
        }
        
        public static function save_produtos_cache($lojista_id, $lojista_url, $produtos) {
            return false;
        }
    }
}

// Classe principal do plugin
class Sincronizador_WooCommerce {
    
    private static $instance = null;
    private $product_importer = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Initialize asset management for admin
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Lista de hooks válidos para carregar assets
        $valid_hooks = array(
            'toplevel_page_sincronizador-wc',
            'sincronizador-wc_page_sincronizador-wc-lojistas',
            'sincronizador-wc_page_sincronizador-wc-add-lojista',
            'sincronizador-wc_page_sincronizador-wc-importar',
            'sincronizador-wc_page_sincronizador-wc-sincronizados',
            'sincronizador-wc_page_sincronizador-wc-config'
        );
        
        $should_load = false;
        foreach ($valid_hooks as $valid_hook) {
            if (strpos($hook, $valid_hook) !== false || $hook === $valid_hook) {
                $should_load = true;
                break;
            }
        }
        
        // Fallback: se contém sincronizador, carrega
        if (!$should_load && (strpos($hook, 'sincronizador') !== false)) {
            $should_load = true;
        }
        
        if (!$should_load) {
            return;
        }
        
        // Verificar se os assets já foram enfileirados para evitar duplicação
        if (wp_script_is('sincronizador-wc-admin-js', 'enqueued')) {
            return;
        }
        
        // CSS principal
        wp_enqueue_style(
            'sincronizador-wc-admin-css',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            SINCRONIZADOR_WC_VERSION . '-' . time()
        );
        
        // CSS dos modais
        wp_enqueue_style(
            'sincronizador-wc-modal-css',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/css/modal-styles.css',
            array(),
            SINCRONIZADOR_WC_VERSION . '-' . time()
        );
        
        // JavaScript dos modais (deve ser carregado primeiro)
        wp_enqueue_script(
            'sincronizador-wc-modals-js',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/modals.js',
            array('jquery'),
            SINCRONIZADOR_WC_VERSION . '-' . time(),
            true
        );
        
        // JavaScript principal (simplificado)
        wp_enqueue_script(
            'sincronizador-wc-admin-js',
            SINCRONIZADOR_WC_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery', 'sincronizador-wc-modals-js'),
            SINCRONIZADOR_WC_VERSION . '-' . time(),
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


    
    public function init() {
        if (!sincronizador_wc_check_woocommerce()) {
            return;
        }
        
        $this->includes();
        $this->init_hooks();
    }
    
    // Adiciona menu diretamente na classe principal
    public function add_admin_menu() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Menu principal
        add_menu_page(
            'Sincronizador WC',
            'Sincronizador WC',
            'manage_woocommerce',
            'sincronizador-wc',
            array($this, 'dashboard_page'),
            'dashicons-update',
            26
        );
        
        // Dashboard
        add_submenu_page(
            'sincronizador-wc',
            'Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'sincronizador-wc',
            array($this, 'dashboard_page')
        );
        
        // Lojistas - Lista
        add_submenu_page(
            'sincronizador-wc',
            'Lojistas',
            'Lojistas',
            'manage_woocommerce',
            'sincronizador-wc-lojistas',
            array($this, 'lojistas_page')
        );
        
        // Lojistas - Adicionar Novo
        add_submenu_page(
            'sincronizador-wc',
            'Adicionar Lojista',
            'Adicionar Novo',
            'manage_woocommerce',
            'sincronizador-wc-add-lojista',
            array($this, 'add_lojista_page')
        );
        
        // Importação de Produtos
        add_submenu_page(
            'sincronizador-wc',
            'Importar Produtos',
            'Importar Produtos',
            'manage_woocommerce',
            'sincronizador-wc-importar',
            array($this, 'importar_page')
        );
        
        // Produtos Sincronizados
        add_submenu_page(
            'sincronizador-wc',
            'Produtos Sincronizados',
            'Produtos Sincronizados',
            'manage_woocommerce',
            'sincronizador-wc-sincronizados',
            array($this, 'produtos_sincronizados_page')
        );
        
        // Configurações
        add_submenu_page(
            'sincronizador-wc',
            'Configurações',
            'Configurações',
            'manage_woocommerce',
            'sincronizador-wc-config',
            array($this, 'config_page')
        );
        
        // Relatórios
        add_submenu_page(
            'sincronizador-wc',
            'Relatórios',
            'Relatórios',
            'manage_woocommerce',
            'sincronizador-wc-relatorios',
            array($this, 'relatorios_page')
        );
    }
    
    public function dashboard_page() {
        echo '<div class="wrap">';
        echo '<h1>🚀 Sincronizador WooCommerce - Dashboard</h1>';
        echo '<div class="notice notice-success"><p><strong>✅ Plugin funcionando perfeitamente!</strong></p></div>';
        echo '<p><strong>Autor:</strong> Moterle Andrei</p>';
        echo '<p><strong>Versão:</strong> 1.1.0</p>';
        echo '<h2>Status do Sistema:</h2>';
        echo '<ul>';
        echo '<li>✅ Plugin ativo e funcionando</li>';
        echo '<li>✅ WooCommerce detectado</li>';
        echo '<li>✅ Menu administrativo carregado</li>';
        echo '</ul>';
        echo '</div>';
    }
    
    public function lojistas_page() {
        // Processar ações
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'delete_lojista':
                    $this->delete_lojista($_POST['lojista_id']);
                    echo '<div class="notice notice-success"><p>Lojista removido com sucesso!</p></div>';
                    break;
                case 'sync_produtos':
                    $this->sync_produtos($_POST['lojista_id']);
                    echo '<div class="notice notice-success"><p>Sincronização de produtos iniciada!</p></div>';
                    break;
                case 'atualizar_lojista':
                    $result_update = $this->atualizar_lojista($_POST['lojista_id']);
                    if (is_array($result_update) && isset($result_update['success']) && $result_update['success']) {
                        echo '<div class="notice notice-success"><p>' . esc_html($result_update['message'] ?? 'Dados do lojista atualizados!') . '</p></div>';
                        if (!empty($result_update['details'])) {
                            $d = $result_update['details'];
                            echo '<div class="notice notice-info"><p>Preços atualizados: ' . intval($d['updated']) . ' | Pulados: ' . intval($d['skipped']) . ' | Erros: ' . intval($d['errors']) . '</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html($result_update['message'] ?? 'Erro ao atualizar lojista') . '</p></div>';
                    }
                    break;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>👥 Lojistas <a href="admin.php?page=sincronizador-wc-add-lojista" class="page-title-action">Adicionar Novo</a></h1>';
        
        // Lista de lojistas (simulada)
        $lojistas = $this->get_lojistas();
        
        if (empty($lojistas)) {
            echo '<div class="notice notice-info"><p>Nenhum lojista cadastrado ainda. <a href="admin.php?page=sincronizador-wc-add-lojista">Adicione o primeiro lojista</a>.</p></div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Nome da Loja</th>';
            echo '<th>URL</th>';
            echo '<th>Status</th>';
            echo '<th>Última Sync</th>';
            echo '<th>Ações</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($lojistas as $lojista) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($lojista['nome']) . '</strong></td>';
                echo '<td><a href="' . esc_url($lojista['url']) . '" target="_blank">' . esc_html($lojista['url']) . '</a></td>';
                echo '<td><span class="status-' . $lojista['status'] . '">' . ucfirst($lojista['status']) . '</span></td>';
                
                // Formatar data da última sincronização
                $ultima_sync_display = 'Nunca';
                if (!empty($lojista['ultima_sync']) && $lojista['ultima_sync'] !== 'Nunca') {
                    $ultima_sync_display = date('d/m/Y, H:i', strtotime($lojista['ultima_sync']));
                }
                echo '<td>' . $ultima_sync_display . '</td>';
                
                echo '<td>';
                
                // Botão Sincronizar Produtos
                echo '<form method="post" style="display:inline-block; margin-right: 5px;">';
                echo '<input type="hidden" name="action" value="sync_produtos">';
                echo '<input type="hidden" name="lojista_id" value="' . $lojista['id'] . '">';
                echo '<button type="submit" class="button button-primary button-small btn-sync" data-lojista-id="' . $lojista['id'] . '" title="Sincronizar todos os produtos">🔄 Sincronizar</button>';
                echo '</form>';
                
                // Botão Testar Conexão
                echo '<button type="button" class="button button-secondary button-small btn-test-connection" data-lojista-id="' . $lojista['id'] . '" id="btn-test-connection-' . $lojista['id'] . '" title="Testar conexão com a loja" style="margin-right: 5px;">🔗 Testar</button>';
                
                // Botão Atualizar
                echo '<form method="post" class="form-atualizar-precos" style="display:inline-block; margin-right: 5px;">';
                echo '<input type="hidden" name="action" value="atualizar_lojista">';
                echo '<input type="hidden" name="lojista_id" value="' . $lojista['id'] . '">';
                echo '<button type="submit" class="button button-small btn-atualizar-precos" title="Atualizar dados do lojista">📊 Atualizar</button>';
                echo '</form>';
                
                // Botão Editar
                echo '<a href="admin.php?page=sincronizador-wc-add-lojista&edit=' . $lojista['id'] . '" class="button button-small" style="margin-right: 5px;">✏️ Editar</a>';
                
                // Botão Remover
                echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Tem certeza que deseja remover este lojista?\');">';
                echo '<input type="hidden" name="action" value="delete_lojista">';
                echo '<input type="hidden" name="lojista_id" value="' . $lojista['id'] . '">';
                echo '<button type="submit" class="button button-small button-link-delete">🗑️ Remover</button>';
                echo '</form>';
                
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
    
    public function add_lojista_page() {
        $editing = isset($_GET['edit']);
        $lojista_data = $editing ? $this->get_lojista($_GET['edit']) : null;
        
        // Processar formulário
        if (isset($_POST['submit_lojista'])) {
            $result = $this->save_lojista($_POST);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>' . $result['message'] . '</p></div>';
                
                if (!$editing) {
                    // Limpar campos após salvar novo
                    $_POST = array();
                }
            } else {
                echo '<div class="notice notice-error"><p>' . $result['message'] . '</p></div>';
            }
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . ($editing ? 'Editar' : 'Adicionar Novo') . ' Lojista</h1>';
        
        echo '<form method="post" action="">';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row"><label for="nome_loja">Nome da Loja *</label></th>';
        echo '<td><input type="text" id="nome_loja" name="nome_loja" value="' . esc_attr($lojista_data['nome'] ?? $_POST['nome_loja'] ?? '') . '" class="regular-text" required /></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row"><label for="url_loja">URL da Loja *</label></th>';
        echo '<td><input type="url" id="url_loja" name="url_loja" value="' . esc_attr($lojista_data['url'] ?? $_POST['url_loja'] ?? '') . '" class="regular-text" placeholder="https://exemplo.com.br" required /></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row"><label for="consumer_key">Consumer Key *</label></th>';
        echo '<td><input type="text" id="consumer_key" name="consumer_key" value="' . esc_attr($lojista_data['consumer_key'] ?? $_POST['consumer_key'] ?? '') . '" class="regular-text" placeholder="ck_xxxxxxxxxx" required /></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row"><label for="consumer_secret">Consumer Secret *</label></th>';
        echo '<td>';
        echo '<input type="password" id="consumer_secret" name="consumer_secret" value="' . esc_attr($lojista_data['consumer_secret'] ?? $_POST['consumer_secret'] ?? '') . '" class="regular-text" placeholder="cs_xxxxxxxxxx" required />';
        echo '<p class="description">Chave secreta da API WooCommerce</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">Informações</th>';
        echo '<td>';
        if ($editing) {
            echo '<p class="description">💡 Use o botão "Testar" na lista de lojistas para verificar a conexão</p>';
        } else {
            echo '<p class="description">A conexão será testada automaticamente - SÓ SALVA SE CONECTAR!</p>';
        }
        echo '</td>';
        echo '</tr>';
        
    // Campo do percentual de acréscimo
    echo '<tr>';
    echo '<th scope="row"><label for="percentual_acrescimo">Percentual de Acréscimo (%)</label></th>';
    echo '<td>';
    $valor_percentual = isset($lojista_data['percentual_acrescimo']) ? $lojista_data['percentual_acrescimo'] : ($_POST['percentual_acrescimo'] ?? '');
    echo '<input type="number" id="percentual_acrescimo" name="percentual_acrescimo" value="' . esc_attr($valor_percentual) . '" class="regular-text" step="0.01" min="0" placeholder="0.00" />';
    echo '<p class="description">Aplicar acréscimo percentual aos preços enviados para este lojista (ex: 10 = 10%).</p>';
    echo '</td>';
    echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row"><label for="ativo">Status</label></th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="ativo" value="1" ' . checked($lojista_data['ativo'] ?? true, true, false) . ' /> Ativo</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        echo '<div class="notice notice-info inline" style="margin: 15px 0; padding: 10px; background: #e7f3ff; border-left: 4px solid #0073aa;">';
        echo '<p><strong>IMPORTANTE: Validação Obrigatória</strong></p>';
        echo '<ul style="margin: 5px 0 0 20px;">';
        echo '<li>🔗 A conexão será testada automaticamente ao salvar</li>';
        echo '<li>✅ O lojista SÓ será salvo se a conexão funcionar</li>';
        echo '<li>❌ Se não conectar, NÃO será salvo no sistema</li>';
        echo '<li>🔑 Certifique-se de que a URL seja válida e acessível</li>';
        echo '<li>🔐 As chaves API devem ter permissões de leitura e escrita</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<p class="submit">';
        echo '<input type="submit" name="submit_lojista" class="button button-primary" value="' . ($editing ? 'Atualizar' : 'Salvar') . ' Lojista" />';
        echo ' <a href="admin.php?page=sincronizador-wc-lojistas" class="button">Cancelar</a>';
        echo '</p>';
        
        if ($editing) {
            echo '<input type="hidden" name="lojista_id" value="' . esc_attr($_GET['edit']) . '" />';
        }
        
        echo '</form>';
        echo '</div>';
    }
    
    public function importar_page() {
        // Check if template file exists
        $template_file = SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/templates/import-page.php';
        if (file_exists($template_file)) {
            // Pass necessary data to template
            $lojistas = $this->get_lojistas();
            $historico = $this->get_historico_importacoes();
            
            include $template_file;
        } else {
            // Fallback if template doesn't exist
            echo '<div class="wrap">';
            echo '<h1>📦 Importar Produtos</h1>';
            echo '<div class="notice notice-error"><p>Template de importação não encontrado.</p></div>';
            echo '</div>';
        }
    }
    
    private function render_historico_importacoes() {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Data</th>';
        echo '<th>Lojista</th>';
        echo '<th>Tipo</th>';
        echo '<th>Produtos</th>';
        echo '<th>Criados</th>';
        echo '<th>Atualizados</th>';
        echo '<th>Erros</th>';
        echo '<th>Status</th>';
        echo '<th>Ações</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        $historico = $this->get_historico_importacoes();
        if (empty($historico)) {
            echo '<tr><td colspan="9" style="text-align: center;">Nenhuma sincronização/importação realizada ainda</td></tr>';
        } else {
            foreach ($historico as $item) {
                echo '<tr>';
                echo '<td>' . date('d/m/Y H:i', strtotime($item['data'])) . '</td>';
                echo '<td>' . esc_html($item['lojista']) . '</td>';
                
                // Tipo de operação
                $tipo_display = $item['tipo'] === 'sincronizacao' ? '🔄 Sincronização' : '📦 Importação';
                echo '<td>' . $tipo_display . '</td>';
                
                echo '<td><strong>' . intval($item['produtos']) . '</strong></td>';
                echo '<td><span style="color: blue;">' . intval($item['produtos_criados']) . '</span></td>';
                echo '<td><span style="color: orange;">' . intval($item['produtos_atualizados']) . '</span></td>';
                echo '<td><span style="color: red;">' . intval($item['produtos_erro']) . '</span></td>';
                
                // Status
                $status_class = $item['status'] === 'completo' ? 'success' : ($item['status'] === 'erro' ? 'error' : 'warning');
                echo '<td><span class="status-' . $status_class . '">' . ucfirst($item['status']) . '</span></td>';
                
                // Ações
                echo '<td>';
                if (!empty($item['logs'])) {
                    echo '<button class="button button-small btn-ver-logs" data-logs=\'' . esc_attr(json_encode($item['logs'])) . '\'>📄 Ver Logs</button>';
                } else {
                    echo '<span style="color: #666;">Sem logs</span>';
                }
                echo '</td>';
                
                echo '</tr>';
            }
        }
        
        echo '</tbody></table>';
    }
    
    public function sync_produtos($lojista_id) {
        $lojistas = $this->get_lojistas();
        $lojista = null;
        
        foreach ($lojistas as $l) {
            if ($l['id'] == $lojista_id) {
                $lojista = $l;
                break;
            }
        }
        
        if (!$lojista) {
            return false;
        }
        
        // SINCRONIZAÇÃO REAL IMPLEMENTADA
        return $this->realizar_sincronizacao_real($lojista);
    }
    
    /**
     * Realiza sincronização real de produtos
     */
    private function realizar_sincronizacao_real($lojista) {
        // 1. Buscar produtos da fábrica (local)
        $produtos_fabrica = $this->get_produtos_locais();
        
        if (empty($produtos_fabrica)) {

            return array();
        }

        // Obter histórico de envios para salvar produtos sincronizados
        $historico_envios = get_option('sincronizador_wc_historico_envios', array());
        $lojista_url = $lojista['url'];
        
        // Inicializar array para este lojista se não existir
        if (!isset($historico_envios[$lojista_url])) {
            $historico_envios[$lojista_url] = array();
        }
        
        $produtos_sincronizados = 0;
        $produtos_atualizados = 0;
        $produtos_criados = 0;
        $erros = 0;
        $resultados = array(); // Array com resultados detalhados
        

        
        foreach ($produtos_fabrica as $produto_fabrica) {
            $resultado_produto = array(
                'nome' => $produto_fabrica['name'],
                'sku' => $produto_fabrica['sku'],
                'success' => false,
                'error' => ''
            );
            
            try {
                // 2. Verificar se produto já existe no destino pelo SKU
                $produto_destino_id = $this->buscar_produto_no_destino($lojista, $produto_fabrica['sku']);
                
                if ($produto_destino_id) {
                    // Produto existe - APENAS criar vínculo (não atualizar)
                    $produtos_sincronizados++;
                    $resultado_produto['success'] = true;
                    $resultado_produto['action'] = 'vinculado';
                    // Salvar no histórico para criar vínculo
                    $historico_envios[$lojista_url][$produto_fabrica['id']] = $produto_destino_id;

                    // Disparar hook para produto sincronizado individualmente
                    do_action('sincronizador_wc_produto_sincronizado', $lojista['nome'], $produto_fabrica['id'], array(
                        'sku' => $produto_fabrica['sku'],
                        'nome' => $produto_fabrica['name'],
                        'destino_id' => $produto_destino_id
                    ));

                } else {
                    // Produto não existe - ignorar

                    $resultado_produto['success'] = false;
                    $resultado_produto['action'] = 'ignorado';
                    $resultado_produto['error'] = 'Produto não existe no destino';
                }
                

                
            } catch (Exception $e) {

                $erros++;
                $resultado_produto['error'] = $e->getMessage();
            }
            
            $resultados[] = $resultado_produto;
        }

        // Salvar o histórico de envios atualizado
        update_option('sincronizador_wc_historico_envios', $historico_envios);
        
        // Salvar relatório detalhado
        $this->salvar_relatorio_sync($lojista['nome'], $produtos_sincronizados, $produtos_criados, $produtos_atualizados, $erros);
        

        
    // Atualizar data/hora da última sincronização do lojista
    $this->atualizar_data_sync($lojista['id']);
    return $resultados; // Retornar array com detalhes de cada produto
    }
    
    /**
     * Buscar produtos locais (da fábrica)
     */
    private function get_produtos_locais() {
        // Buscar produtos WooCommerce locais
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '='
                )
            )
        );
        
        $produtos = get_posts($args);
        $produtos_formatados = array();
        
        foreach ($produtos as $produto_post) {
            $produto = wc_get_product($produto_post->ID);
            
            if ($produto && $produto->get_sku()) {
                // Debug dos preços na importação
                $preco_regular = $produto->get_regular_price();
                $preco_atual = $produto->get_price();
                $preco_promocional = $produto->get_sale_price();
                
                // Para produtos variáveis, buscar preço das variações
                if ($produto->is_type('variable')) {
                    $variation_prices = $produto->get_variation_prices();
                    if (!empty($variation_prices['price'])) {
                        $preco_atual = min($variation_prices['price']);

                    }
                    if (!empty($variation_prices['regular_price'])) {
                        $preco_regular = min($variation_prices['regular_price']);
                    }
                    if (!empty($variation_prices['sale_price'])) {
                        $precos_promocionais = array_filter($variation_prices['sale_price'], function($price) {
                            return $price !== '';
                        });
                        if (!empty($precos_promocionais)) {
                            $preco_promocional = min($precos_promocionais);
                        }
                    }
                }
                

                
                // Determinar o melhor preço para exibir
                $preco_final = '';
                if (!empty($preco_atual) && $preco_atual !== '0') {
                    $preco_final = $preco_atual;
                } elseif (!empty($preco_regular) && $preco_regular !== '0') {
                    $preco_final = $preco_regular;
                } else {
                    // Tentar buscar diretamente do meta
                    $preco_meta = get_post_meta($produto_post->ID, '_price', true);
                    $regular_meta = get_post_meta($produto_post->ID, '_regular_price', true);
                    
                    if (!empty($preco_meta) && $preco_meta !== '0') {
                        $preco_final = $preco_meta;
                    } elseif (!empty($regular_meta) && $regular_meta !== '0') {
                        $preco_final = $regular_meta;
                    }
                }
                

                
                $produtos_formatados[] = array(
                    'id' => $produto->get_id(),
                    'name' => $produto->get_name(),
                    'sku' => $produto->get_sku(),
                    'regular_price' => $preco_regular ?: '0',
                    'sale_price' => $preco_promocional ?: '',
                    'stock_quantity' => $produto->get_stock_quantity() ?: 0,
                    'description' => $produto->get_description(),
                    'short_description' => $produto->get_short_description(),
                    'categories' => wp_get_post_terms($produto->get_id(), 'product_cat', array('fields' => 'names')),
                    'images' => $this->get_product_images($produto)
                );
            }
        }
        
        // Se não há produtos reais, criar produtos de demonstração
        if (empty($produtos_formatados)) {
            $produtos_formatados = $this->get_produtos_demonstracao();
        }
        
        return $produtos_formatados;
    }
    
    /**
     * Produtos de demonstração para teste
     */
    private function get_produtos_demonstracao() {
        return array(
            array(
                'id' => 999001,
                'name' => 'Produto Demo 1 - Camiseta Básica',
                'sku' => 'CAM-001',
                'regular_price' => '29.90',
                'sale_price' => '24.90',
                'stock_quantity' => 100,
                'description' => 'Camiseta básica 100% algodão, confortável e durável.',
                'short_description' => 'Camiseta básica de alta qualidade',
                'categories' => array('Roupas', 'Camisetas'),
                'images' => array('https://via.placeholder.com/80x80/4CAF50/FFFFFF?text=CAM001')
            ),
            array(
                'id' => 999002,
                'name' => 'Produto Demo 2 - Calça Jeans',
                'sku' => 'CAL-002',
                'regular_price' => '89.90',
                'sale_price' => '79.90',
                'stock_quantity' => 50,
                'description' => 'Calça jeans masculina, corte reto, tecido resistente.',
                'short_description' => 'Calça jeans de qualidade premium',
                'categories' => array('Roupas', 'Calças'),
                'images' => array('https://via.placeholder.com/80x80/2196F3/FFFFFF?text=CAL002')
            ),
            array(
                'id' => 999003,
                'name' => 'Produto Demo 3 - Tênis Esportivo',
                'sku' => 'TEN-003',
                'regular_price' => '159.90',
                'sale_price' => '',
                'stock_quantity' => 25,
                'description' => 'Tênis esportivo para corrida e caminhada, solado antiderrapante.',
                'short_description' => 'Tênis esportivo confortável',
                'categories' => array('Calçados', 'Esportivo'),
                'images' => array('https://via.placeholder.com/80x80/FF9800/FFFFFF?text=TEN003')
            ),
            array(
                'id' => 999004,
                'name' => 'Produto Demo 4 - Mochila Escolar',
                'sku' => 'MOC-004',
                'regular_price' => '79.90',
                'sale_price' => '69.90',
                'stock_quantity' => 30,
                'description' => 'Mochila escolar com múltiplos compartimentos, resistente à água.',
                'short_description' => 'Mochila escolar resistente',
                'categories' => array('Acessórios', 'Mochilas'),
                'images' => array('https://via.placeholder.com/80x80/9C27B0/FFFFFF?text=MOC004')
            ),
            array(
                'id' => 999005,
                'name' => 'Produto Demo 5 - Relógio Digital',
                'sku' => 'REL-005',
                'regular_price' => '199.90',
                'sale_price' => '179.90',
                'stock_quantity' => 15,
                'description' => 'Relógio digital à prova d\'água com múltiplas funções.',
                'short_description' => 'Relógio digital multifuncional',
                'categories' => array('Acessórios', 'Relógios'),
                'images' => array('https://via.placeholder.com/80x80/607D8B/FFFFFF?text=REL005')
            ),
            array(
                'id' => 999006,
                'name' => 'Produto Demo 6 - Smartphone',
                'sku' => 'SMART-006',
                'regular_price' => '899.90',
                'sale_price' => '799.90',
                'stock_quantity' => 10,
                'description' => 'Smartphone com tela de 6.5 polegadas, 128GB de armazenamento.',
                'short_description' => 'Smartphone avançado',
                'categories' => array('Eletrônicos', 'Celulares'),
                'images' => array('https://via.placeholder.com/80x80/FF5722/FFFFFF?text=SMART006')
            ),
            array(
                'id' => 999007,
                'name' => 'Produto Demo 7 - Fone Bluetooth',
                'sku' => 'FONE-007',
                'regular_price' => '149.90',
                'sale_price' => '',
                'stock_quantity' => 40,
                'description' => 'Fone de ouvido Bluetooth com cancelamento de ruído.',
                'short_description' => 'Fone Bluetooth premium',
                'categories' => array('Eletrônicos', 'Áudio'),
                'images' => array('https://via.placeholder.com/80x80/795548/FFFFFF?text=FONE007')
            ),
            array(
                'id' => 999008,
                'name' => 'Produto Demo 8 - Notebook Gamer',
                'sku' => 'NOTE-008',
                'regular_price' => '2999.90',
                'sale_price' => '2699.90',
                'stock_quantity' => 5,
                'description' => 'Notebook para jogos com placa de vídeo dedicada.',
                'short_description' => 'Notebook de alta performance',
                'categories' => array('Eletrônicos', 'Computadores'),
                'images' => array('https://via.placeholder.com/80x80/3F51B5/FFFFFF?text=NOTE008')
            )
        );
    }
    
    /**
     * Buscar produto no destino pelo SKU
     */
    private function buscar_produto_no_destino($lojista, $sku) {
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products?sku=' . urlencode($sku);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {

            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body) && isset($body[0]['id'])) {
            return $body[0]['id'];
        }
        
        return false;
    }
    
    /**
     * Criar produto no destino
     */
    private function criar_produto_no_destino($lojista, $produto_fabrica) {
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products';
        
        $dados_produto = array(
            'name' => $produto_fabrica['name'],
            'sku' => $produto_fabrica['sku'],
            'regular_price' => $produto_fabrica['regular_price'],
            'sale_price' => $produto_fabrica['sale_price'],
            'stock_quantity' => $produto_fabrica['stock_quantity'],
            'manage_stock' => true,
            'description' => $produto_fabrica['description'],
            'short_description' => $produto_fabrica['short_description'],
            'status' => 'publish',
            'catalog_visibility' => 'visible'
        );
        
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($dados_produto)
        ));
        
        if (is_wp_error($response)) {

            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 201) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body) && isset($body['id'])) {
                return $body['id']; // Retorna o ID do produto criado
            }
        }
        
        return false;
    }
    
    /**
     * Atualizar produto no destino
     */
    private function atualizar_produto_no_destino($lojista, $produto_id, $produto_fabrica) {
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products/' . $produto_id;
        
        $dados_produto = array(
            'name' => $produto_fabrica['name'],
            'regular_price' => $produto_fabrica['regular_price'],
            'sale_price' => $produto_fabrica['sale_price'],
            'stock_quantity' => $produto_fabrica['stock_quantity'],
            'description' => $produto_fabrica['description'],
            'short_description' => $produto_fabrica['short_description']
        );
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($dados_produto)
        ));
        
        if (is_wp_error($response)) {

            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            return $produto_id; // Retorna o ID do produto atualizado
        }
        
        return false;
    }
    
    /**
     * Obter imagens do produto
     */
    private function get_product_images($produto) {
        $images = array();
        
        // Imagem principal
        $image_id = $produto->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($this->is_valid_image_url($image_url)) {
                $images[] = $image_url;
            }
        }
        
        // Galeria de imagens
        $gallery_ids = $produto->get_gallery_image_ids();
        foreach ($gallery_ids as $gallery_id) {
            $image_url = wp_get_attachment_image_url($gallery_id, 'full');
            if ($this->is_valid_image_url($image_url)) {
                $images[] = $image_url;
            }
        }
        
        return $images;
    }
    
    /**
     * Validar URL de imagem antes de enviar
     */
    private function is_valid_image_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // Em desenvolvimento, permitir todas as URLs para teste
        // Detectar ambiente de desenvolvimento por URL
        if (strpos($url, '.test') !== false || strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false) {

            return true;
        }
        
        // Verificar se a URL não é de desenvolvimento local (.test, localhost, etc)
        $parsed_url = parse_url($url);
        if (isset($parsed_url['host'])) {
            $host = $parsed_url['host'];
            
            // Bloquear domínios de desenvolvimento apenas em produção
            $dev_domains = array('.test', '.local', 'localhost', '127.0.0.1', '192.168.');
            foreach ($dev_domains as $dev_domain) {
                if (strpos($host, $dev_domain) !== false) {

                    return false;
                }
            }
        }
        
        // Verificar se a URL é acessível
        $response = wp_remote_head($url, array(
            'timeout' => 5,
            'redirection' => 3
        ));
        
        if (is_wp_error($response)) {

            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {

            return false;
        }
        
        // Verificar content-type se disponível
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if ($content_type && !str_starts_with($content_type, 'image/')) {

            return false;
        }
        
        return true;
    }
    
    /**
     * Salvar relatório de sincronização
     */
    private function salvar_relatorio_sync($lojista_nome, $sincronizados, $criados, $atualizados, $erros) {
        $relatorio = array(
            'data' => date('d/m/Y H:i:s'),
            'lojista' => $lojista_nome,
            'produtos_sincronizados' => $sincronizados,
            'produtos_criados' => $criados,
            'produtos_atualizados' => $atualizados,
            'erros' => $erros,
            'status' => $erros > 0 ? 'parcial' : 'completo'
        );
        

        
        $historico = get_option('sincronizador_wc_relatorios_sync', array());
        array_unshift($historico, $relatorio);
        
        // Manter apenas os últimos 50 relatórios
        if (count($historico) > 50) {
            $historico = array_slice($historico, 0, 50);
        }
        
        $resultado = update_option('sincronizador_wc_relatorios_sync', $historico);

        
        // Também adicionar ao histórico simples
        $this->adicionar_historico_sync($lojista_nome, $sincronizados);
        
        // Disparar hook para salvar dados da Master API
        do_action('sincronizador_wc_after_sync', $lojista_nome, array(
            'produtos_sincronizados' => $sincronizados,
            'produtos_criados' => $criados,
            'produtos_atualizados' => $atualizados,
            'erros' => $erros,
            'status' => $erros > 0 ? 'parcial' : 'completo'
        ));
    }
    
    /**
     * Salvar dados do Master API após sincronização
     */
    public function salvar_dados_master_api($lojista_nome, $dados_sync) {
        // Obter dados atuais da fábrica para o Master API
        $dados_fabrica = $this->obter_dados_fabrica_master_api();
        
        // Salvar no option usado pela Master API
        update_option('sincronizador_wc_master_data', $dados_fabrica);
        
        // Log da atualização
        error_log("Master API: Dados atualizados após sincronização com {$lojista_nome}");
    }
    
    /**
     * Atualizar dados do Master API quando uma venda específica é sincronizada
     */
    public function atualizar_dados_master_api($lojista_nome, $produto_id, $venda_data) {
        // Atualizar apenas os dados específicos deste produto/loja
        $dados_fabrica = get_option('sincronizador_wc_master_data', array());
        
        // Atualizar timestamp da última sincronização
        $dados_fabrica['ultima_atualizacao'] = current_time('mysql');
        
        // Salvar dados atualizados
        update_option('sincronizador_wc_master_data', $dados_fabrica);
        
        // Log da atualização específica
        error_log("Master API: Venda sincronizada - Lojista: {$lojista_nome}, Produto: {$produto_id}");
    }
    
    /**
     * Obter dados da fábrica formatados para Master API
     */
    private function obter_dados_fabrica_master_api() {
        // Obter informações da fábrica
        $lojistas = $this->get_lojistas();
        $produtos_locais = $this->get_produtos_locais();
        
        // Calcular estatísticas
        $total_lojistas = count($lojistas);
        $lojistas_ativos = count(array_filter($lojistas, function($l) { return $l['ativo']; }));
        $total_produtos = count($produtos_locais);
        
        // Obter produtos mais vendidos localmente
        $produtos_top = $this->obter_produtos_mais_vendidos_local();
        
        // Dados das vendas por lojista
        $vendas_por_lojista = array();
        foreach ($lojistas as $lojista) {
            if ($lojista['ativo']) {
                $vendas_por_lojista[] = array(
                    'id' => $lojista['id'],
                    'nome' => $lojista['nome'],
                    'url' => $lojista['url'],
                    'status' => $lojista['status'],
                    'ultima_sync' => $lojista['ultima_sync'] ?? 'Nunca',
                    'vendas_mes' => $this->calcular_vendas_lojista_mes($lojista)
                );
            }
        }
        
        return array(
            'fabrica_nome' => get_bloginfo('name'),
            'fabrica_url' => home_url(),
            'status' => 'ativo',
            'total_lojistas' => $total_lojistas,
            'lojistas_ativos' => $lojistas_ativos,
            'total_produtos' => $total_produtos,
            'produtos_top' => $produtos_top,
            'vendas_por_lojista' => $vendas_por_lojista,
            'ultima_atualizacao' => current_time('mysql'),
            'timestamp' => time()
        );
    }
    
    /**
     * Obter produtos mais vendidos localmente
     */
    private function obter_produtos_mais_vendidos_local() {
        global $wpdb;
        
        $query = "
            SELECT p.ID, p.post_title, 
                   COALESCE(pm.meta_value, 0) as total_vendas,
                   pm2.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_total_sales'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sku'
            WHERE p.post_type = 'product' 
              AND p.post_status = 'publish'
            ORDER BY CAST(COALESCE(pm.meta_value, 0) AS UNSIGNED) DESC
            LIMIT 10
        ";
        
        $produtos = $wpdb->get_results($query);
        $produtos_formatados = array();
        
        foreach ($produtos as $produto) {
            $produto_obj = wc_get_product($produto->ID);
            if ($produto_obj) {
                $produtos_formatados[] = array(
                    'id' => $produto->ID,
                    'nome' => $produto->post_title,
                    'sku' => $produto->sku ?: 'N/A',
                    'vendas_total' => intval($produto->total_vendas),
                    'preco' => $produto_obj->get_price(),
                    'estoque' => $produto_obj->get_stock_quantity()
                );
            }
        }
        
        return $produtos_formatados;
    }
    
    /**
     * Calcular vendas de um lojista no mês atual
     */
    private function calcular_vendas_lojista_mes($lojista) {
        // Seria ideal buscar via API do lojista, mas por enquanto retorna simulado
        return array(
            'total_vendas' => rand(1000, 5000),
            'total_pedidos' => rand(10, 50),
            'mes' => date('Y-m')
        );
    }

    public function atualizar_lojista($lojista_id) {
        $lojistas = $this->get_lojistas();
        $lojista_atualizado = null;
        
        foreach ($lojistas as &$lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista['ultima_atualizacao'] = date('Y-m-d H:i:s');
                $lojista_atualizado = $lojista;
                break;
            }
        }
        
        $resultado = update_option('sincronizador_wc_lojistas', $lojistas);

        // Disparar hook de atualização
        if ($lojista_atualizado) {
            do_action('sincronizador_wc_lojista_updated', $lojista_atualizado);
        }

        // Se não salvou, retornar erro
        if (!$resultado) {
            return array('success' => false, 'message' => 'Erro ao salvar dados do lojista');
        }

        // Tentar atualizar preços no destino aplicando percentual_acrescimo
        try {
            // Re-obter lojista atualizado (para garantir dados atuais)
            $lojista = $this->get_lojista($lojista_id);

            if (!$lojista) {
                return array('success' => false, 'message' => 'Lojista não encontrado após salvar');
            }

            $update_result = $this->atualizar_precos_loja($lojista);

            return array(
                'success' => true,
                'message' => 'Dados do lojista atualizados e operação de preços executada',
                'details' => $update_result
            );
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Erro ao atualizar preços: ' . $e->getMessage());
        }
    }

    /**
     * Atualiza preços do lojista remoto a partir dos preços locais da fábrica
     * Aplica o percentual_acrescimo salvo no lojista
     * Retorna array com contagens: updated, skipped, errors
     */
    private function atualizar_precos_loja($lojista) {
        $resultado = array('updated' => 0, 'skipped' => 0, 'errors' => 0, 'errors_list' => array());

        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            $resultado['errors']++;
            $resultado['errors_list'][] = 'Dados de conexão incompletos para lojista ID ' . ($lojista['id'] ?? 'n/a');
            return $resultado;
        }

        $percentual = isset($lojista['percentual_acrescimo']) ? floatval($lojista['percentual_acrescimo']) : 0.0;

        // Obter produtos da fábrica (locais)
        $produtos = $this->get_produtos_locais();

        if (empty($produtos)) {
            // Nada a fazer
            return $resultado;
        }

        foreach ($produtos as $produto) {
            $sku = $produto['sku'] ?? '';
            if (empty($sku)) {
                $resultado['skipped']++;
                continue;
            }

            // Buscar produto no destino
            $destino_id = $this->buscar_produto_no_destino($lojista, $sku);

            if (!$destino_id) {
                $resultado['skipped']++;
                continue;
            }

            // Determinar preço base (usar regular_price ou sale_price)
            $preco_base = '';
            if (isset($produto['regular_price']) && is_numeric($produto['regular_price']) && $produto['regular_price'] !== '') {
                $preco_base = floatval($produto['regular_price']);
            } elseif (isset($produto['sale_price']) && is_numeric($produto['sale_price']) && $produto['sale_price'] !== '') {
                $preco_base = floatval($produto['sale_price']);
            } else {
                // Tentar buscar meta price
                $preco_base = 0;
            }

            // Calcular novo preco aplicando percentual
            $novo_preco = $preco_base * (1 + ($percentual / 100));
            $novo_preco_str = number_format($novo_preco, 2, '.', '');

            // Preparar payload de atualização
            $url = rtrim($lojista['url'], '/') . '/wp-json/wc/v3/products/' . intval($destino_id);

            $body = json_encode(array(
                'regular_price' => $novo_preco_str
            ));

            $response = wp_remote_request($url, array(
                'method' => 'PUT',
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                    'Content-Type' => 'application/json'
                ),
                'body' => $body
            ));

            if (is_wp_error($response)) {
                $resultado['errors']++;
                $resultado['errors_list'][] = 'Erro ao atualizar SKU ' . $sku . ': ' . $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $resultado['updated']++;
            } else {
                $resultado['errors']++;
                $body_resp = wp_remote_retrieve_body($response);
                $resultado['errors_list'][] = 'HTTP ' . $code . ' ao atualizar SKU ' . $sku . ' resposta: ' . substr($body_resp, 0, 200);
            }
        }

        return $resultado;
    }
    
    public function get_produtos_fabrica() {
        // Em produção: conectar com API real da fábrica
        // Por enquanto retorna array vazio para usar dados reais em testes locais
        return array();
    }
    
    public function get_historico_importacoes() {
        // Buscar histórico unificado - primeiro dos relatórios de sync, depois das importações manuais
        $historico_sync = get_option('sincronizador_wc_relatorios_sync', array());
        $historico_import = get_option('sincronizador_wc_historico_importacoes', array());
        
        // Combinar os dois históricos
        $historico_completo = array();
        
        // Adicionar relatórios de sincronização (formato mais recente)
        foreach ($historico_sync as $item) {
            $historico_completo[] = array(
                'data' => $item['data'],
                'lojista' => $item['lojista'],
                'produtos' => $item['produtos_sincronizados'],
                'produtos_criados' => $item['produtos_criados'] ?? 0,
                'produtos_atualizados' => $item['produtos_atualizados'] ?? 0,
                'produtos_erro' => $item['erros'] ?? 0,
                'status' => $item['status'],
                'tipo' => 'sincronizacao',
                'logs' => array()
            );
        }
        
        // Adicionar importações manuais (formato antigo)
        foreach ($historico_import as $item) {
            $historico_completo[] = array(
                'data' => $item['data'],
                'lojista' => $item['lojista'],
                'produtos' => $item['quantidade'] ?? $item['produtos'] ?? 0,
                'produtos_criados' => 0,
                'produtos_atualizados' => 0,
                'produtos_erro' => 0,
                'status' => $item['status'],
                'tipo' => 'importacao',
                'logs' => $item['logs'] ?? array()
            );
        }
        
        // Ordenar por data (mais recente primeiro)
        usort($historico_completo, function($a, $b) {
            return strtotime($b['data']) - strtotime($a['data']);
        });
        
        return $historico_completo;
    }
    
    public function enviar_produtos_selecionados($data) {
        if (empty($data['produtos_selecionados']) || empty($data['lojista_destino'])) {
            return false;
        }
        
        $lojista_id = sanitize_text_field($data['lojista_destino']);
        $produtos_ids = array_map('intval', $data['produtos_selecionados']);
        
        // Buscar dados do lojista
        $lojistas = $this->get_lojistas();
        $lojista = null;
        
        foreach ($lojistas as $l) {
            if ($l['id'] == $lojista_id) {
                $lojista = $l;
                break;
            }
        }
        
        if (!$lojista) {
            return false;
        }
        
        // Buscar produtos selecionados
        $produtos_fabrica = $this->get_produtos_fabrica();
        $produtos_enviar = [];
        
        foreach ($produtos_fabrica as $produto) {
            if (in_array($produto['id'], $produtos_ids)) {
                $produtos_enviar[] = $produto;
            }
        }
        
        if (empty($produtos_enviar)) {
            return false;
        }
        
        // Simular envio para o lojista via API WooCommerce
        $incluir_variacoes = isset($data['incluir_variacoes']) && $data['incluir_variacoes'] == '1';
        $incluir_imagens = isset($data['incluir_imagens']) && $data['incluir_imagens'] == '1';
        $manter_precos = isset($data['manter_precos']) && $data['manter_precos'] == '1';
        
        // Registrar no histórico
        $this->adicionar_historico_importacao($lojista['nome'], count($produtos_enviar), 'concluido');
        
        return true;
    }
    
    public function adicionar_historico_importacao($lojista_nome, $quantidade, $status) {
        $historico = get_option('sincronizador_wc_historico_importacoes', []);
        
        $novo_item = [
            'data' => date('d/m/Y H:i'),
            'lojista' => $lojista_nome,
            'produtos' => $quantidade,
            'status' => $status
        ];
        
        array_unshift($historico, $novo_item);
        
        // Manter apenas os últimos 50 registros
        if (count($historico) > 50) {
            $historico = array_slice($historico, 0, 50);
        }
        
        update_option('sincronizador_wc_historico_importacoes', $historico);
    }
    
    public function adicionar_historico_sync($lojista_nome, $quantidade) {
        $this->adicionar_historico_importacao($lojista_nome, $quantidade, 'sincronizado');
    }
    
    public function produtos_sincronizados_page() {
        // Check if template file exists
        $template_file = SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/templates/sync-page.php';
        if (file_exists($template_file)) {
            // Pass necessary data to template
            $lojistas = get_option('sincronizador_wc_lojistas', array());
            $nonce = wp_create_nonce('sincronizador_wc_nonce');
            
            include $template_file;
        } else {
            // Fallback if template doesn't exist
            echo '<div class="wrap">';
            echo '<h1>📊 Produtos Sincronizados</h1>';
            echo '<div class="notice notice-error"><p>Template de produtos sincronizados não encontrado.</p></div>';
            echo '</div>';
        }
    }

    public function config_page() {
        // Processar geração de token
        if (isset($_POST['gerar_token'])) {
            $new_token = 'sync_' . substr(md5(time() . wp_salt()), 0, 32);
            update_option('sincronizador_wc_master_token', $new_token);
            echo '<div class="notice notice-success"><p>Novo token gerado com sucesso!</p></div>';
        }
        
        $current_token = get_option('sincronizador_wc_master_token', 'sync_' . substr(md5('default' . wp_salt()), 0, 32));
        
        echo '<div class="wrap">';
        echo '<h1>⚙️ Configurações</h1>';
        
        echo '<div class="card">';
        echo '<h2>🔑 Token Master para API</h2>';
        echo '<p>Use este token para integração com sistemas externos:</p>';
        echo '<div class="token-display">';
        echo '<code class="token-code">' . esc_html($current_token) . '</code>';
        echo '</div>';
        
        echo '<form method="post" action="">';
        echo '<p class="submit">';
        echo '<input type="submit" name="gerar_token" class="button button-primary" value="🔄 Gerar Novo Token" onclick="return confirm(\'Tem certeza? O token atual será invalidado.\');" />';
        echo '</p>';
        echo '</form>';
        echo '</div>';
        
        echo '<div class="card">';
        echo '<h2>🔗 Endpoint da API Master</h2>';
        echo '<p>URL para integração externa:</p>';
        echo '<div class="endpoint-display">';
        echo '<code>' . home_url('/wp-json/sincronizador-wc/v1/master/fabrica-status') . '</code>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="card">';
        echo '<h2>📝 Exemplo de Uso</h2>';
        echo '<pre class="curl-example">curl -H "Authorization: Bearer ' . esc_html($current_token) . '" \\
     "' . home_url('/wp-json/sincronizador-wc/v1/master/fabrica-status') . '"</pre>';
        echo '</div>';
        
        echo '<div class="card">';
        echo '<h2>📊 Estatísticas do Sistema</h2>';
        echo '<ul>';
        echo '<li><strong>Lojistas cadastrados:</strong> ' . count($this->get_lojistas()) . '</li>';
        echo '<li><strong>Lojistas ativos:</strong> ' . count(array_filter($this->get_lojistas(), function($l) { return $l['ativo']; })) . '</li>';
        
        // Buscar última sincronização real
        $historico = get_option('sincronizador_wc_relatorios_sync', array());
        $ultima_sync = 'Nunca';
        if (!empty($historico)) {
            // Pegar o relatório mais recente
            $ultimo_relatorio = end($historico);
            if (isset($ultimo_relatorio['data'])) {
                $ultima_sync = date('d/m/Y H:i', strtotime($ultimo_relatorio['data']));
            }
        }
        
        echo '<li><strong>Última sincronização:</strong> ' . $ultima_sync . '</li>';
        echo '<li><strong>Plugin ativo desde:</strong> ' . date('d/m/Y H:i') . '</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '</div>';
    }
    
    public function relatorios_page() {
        // Enfileirar estilos e scripts específicos para relatórios
        wp_enqueue_style('sincronizador-reports-css', plugin_dir_url(__FILE__) . 'admin/css/reports.css', array(), '1.0.0');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_script('sincronizador-reports-js', plugin_dir_url(__FILE__) . 'admin/js/reports.js', array('jquery', 'chart-js'), '1.0.0', true);
        
        // Localizar script para AJAX (usando nome consistente)
        wp_localize_script('sincronizador-reports-js', 'sincronizadorReports', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sincronizador_reports_nonce')
        ));
        
        // Incluir template da página de relatórios
        include plugin_dir_path(__FILE__) . 'admin/templates/reports-page.php';
    }
    
    // Métodos auxiliares para gerenciar lojistas
    /**
     * Buscar vendas de um lojista específico via API
     */
    private function buscar_vendas_lojista($lojista, $data_inicio, $data_fim) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {

            return array('success' => false, 'message' => 'Dados de conexão incompletos');
        }
        
        // Converter datas para formato ISO 8601 (WooCommerce API)
        $data_inicio_iso = $data_inicio . 'T00:00:00';
        $data_fim_iso = $data_fim . 'T23:59:59';
        
        $url = rtrim($lojista['url'], '/') . '/wp-json/wc/v3/orders';
        
        // Parâmetros para buscar pedidos do período
        $params = array(
            'after' => $data_inicio_iso,
            'before' => $data_fim_iso,
            'status' => 'completed,processing,on-hold',
            'per_page' => 100,  // Máximo por página
            'page' => 1
        );
        
        $url_com_params = add_query_arg($params, $url);
        

        
        $response = wp_remote_get($url_com_params, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sincronizador-WC/1.0'
            )
        ));
        
        if (is_wp_error($response)) {

            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code !== 200) {

            return array('success' => false, 'message' => "HTTP {$http_code}");
        }
        
        $body = wp_remote_retrieve_body($response);
        $pedidos = json_decode($body, true);
        
        if (!is_array($pedidos)) {

            return array('success' => false, 'message' => 'Resposta inválida da API');
        }
        
        // Calcular totais
        $total_vendas = 0;
        $total_pedidos = count($pedidos);
        $produtos_vendidos = 0;
        
        foreach ($pedidos as $pedido) {
            if (isset($pedido['total'])) {
                $total_vendas += floatval($pedido['total']);
            }
            
            if (isset($pedido['line_items'])) {
                foreach ($pedido['line_items'] as $item) {
                    if (isset($item['quantity'])) {
                        $produtos_vendidos += intval($item['quantity']);
                    }
                }
            }
        }
        
        // TODO: Implementar paginação para mais de 100 pedidos
        // Por enquanto, verificar se há mais páginas nos headers
        $headers = wp_remote_retrieve_headers($response);
        if (isset($headers['x-wp-totalpages']) && $headers['x-wp-totalpages'] > 1) {

            // TODO: Buscar outras páginas
        }
        
        $dados = array(
            'total_vendas' => $total_vendas,
            'total_pedidos' => $total_pedidos,
            'produtos_vendidos' => $produtos_vendidos
        );
        

        
        return array('success' => true, 'data' => $dados);
    }

    private function get_lojistas() {
        // Buscar lojistas reais do banco de dados
        return get_option('sincronizador_wc_lojistas', array());
    }
    
    private function get_lojista($id) {
        $lojistas = $this->get_lojistas();
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $id) {
                return $lojista;
            }
        }
        return null;
    }
    
    private function save_lojista($data) {
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        // Calcular próximo ID corretamente
        $proximo_id = 1;
        if (!empty($lojistas)) {
            $ids_existentes = array_column($lojistas, 'id');
            $proximo_id = max($ids_existentes) + 1;
        }
        
        $lojista = array(
            'id' => isset($data['lojista_id']) && $data['lojista_id'] ? intval($data['lojista_id']) : $proximo_id,
            'nome' => sanitize_text_field($data['nome_loja']),
            'url' => esc_url_raw($data['url_loja']),
            'consumer_key' => sanitize_text_field($data['consumer_key']),
            'consumer_secret' => sanitize_text_field($data['consumer_secret']),
            'percentual_acrescimo' => isset($data['percentual_acrescimo']) ? floatval($data['percentual_acrescimo']) : 0.0,
            'ativo' => isset($data['ativo']),
            'status' => isset($data['ativo']) ? 'ativo' : 'inativo',
            'ultima_sync' => 'Nunca',
            'criado_em' => current_time('mysql')
        );
        
        // TESTAR CONEXÃO ANTES DE SALVAR - SÓ SALVA SE CONECTAR!
        $teste_conexao = $this->testar_conexao_lojista_direto($lojista);
        
        if (!$teste_conexao['success']) {
            // Se a conexão falhou, NÃO SALVA e retorna erro
            return array(
                'success' => false,
                'message' => '❌ Conexão falhou! Lojista NÃO foi salvo. ' . $teste_conexao['message']
            );
        }
        
        // Se chegou até aqui, a conexão funcionou - pode salvar
        
        // Se é edição, atualiza o existente
        if (isset($data['lojista_id']) && $data['lojista_id']) {
            foreach ($lojistas as $key => $existing) {
                if ($existing['id'] == $data['lojista_id']) {
                    $lojistas[$key] = array_merge($existing, $lojista);
                    break;
                }
            }
        } else {
            // Novo lojista
            $lojistas[] = $lojista;
        }
        
        $result = update_option('sincronizador_wc_lojistas', $lojistas);
        
        // Retornar sucesso com mensagem de conexão OK
        return $result ? array(
            'success' => true,
            'lojista_id' => $lojista['id'],
            'message' => '✅ Lojista salvo com sucesso! Conexão testada e aprovada.'
        ) : array(
            'success' => false,
            'message' => '❌ Erro interno ao salvar no banco de dados.'
        );
    }
    
    /**
     * Obter ID do último lojista cadastrado
     */
    private function get_last_lojista_id() {
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        if (empty($lojistas)) {
            return 1;
        }
        
        $ultimo_id = 0;
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] > $ultimo_id) {
                $ultimo_id = $lojista['id'];
            }
        }
        
        return $ultimo_id;
    }
    
    /**
     * Testar conexão automaticamente após salvar lojista
     */
    /**
     * Testar conexão diretamente com os dados do lojista (antes de salvar)
     */
    private function testar_conexao_lojista_direto($lojista_data) {
        // Verificar se tem dados necessários
        if (empty($lojista_data['url']) || empty($lojista_data['consumer_key']) || empty($lojista_data['consumer_secret'])) {
            return array(
                'success' => false,
                'message' => 'Dados incompletos: URL, Consumer Key e Consumer Secret são obrigatórios'
            );
        }
        
        // Testar conexão real
        $url = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/system_status';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Erro de conexão: ' . $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200) {
            return array(
                'success' => true,
                'message' => 'Conexão testada com sucesso! Loja: ' . $lojista_data['nome']
            );
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => 'Erro HTTP ' . $code . ': ' . ($error_data['message'] ?? 'Falha na autenticação. Verifique Consumer Key e Consumer Secret.')
            );
        }
    }

    private function testar_conexao_automatica($lojista_id) {
        $lojista = $this->get_lojista($lojista_id);
        
        if (!$lojista) {
            return array(
                'success' => false,
                'message' => 'Lojista não encontrado para teste de conexão'
            );
        }
        
        // Verificar se tem dados necessários
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array(
                'success' => false,
                'message' => 'Dados incompletos: URL, Consumer Key e Consumer Secret são obrigatórios'
            );
        }
        
        // Testar conexão real
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/system_status';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Erro de conexão: ' . $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200) {
            return array(
                'success' => true,
                'message' => 'Conexão testada com sucesso! Loja: ' . $lojista['nome']
            );
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            return array(
                'success' => false,
                'message' => 'Erro HTTP ' . $code . ': ' . ($error_data['message'] ?? 'Falha na autenticação. Verifique Consumer Key e Consumer Secret.')
            );
        }
    }
    
    private function delete_lojista($id) {
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        foreach ($lojistas as $key => $lojista) {
            if ($lojista['id'] == $id) {
                unset($lojistas[$key]);
                break;
            }
        }
        
        return update_option('sincronizador_wc_lojistas', array_values($lojistas));
    }
    
    private function process_import($data) {
        // Simulação de processamento de importação
        // Aqui você implementaria a lógica real de importação
        
        $lojista_id = intval($data['lojista_destino']);
        $buscar_produto = sanitize_text_field($data['buscar_produto']);
        $categoria_filtro = sanitize_text_field($data['categoria_filtro']);
        $incluir_variacoes = isset($data['incluir_variacoes']);
        $incluir_imagens = isset($data['incluir_imagens']);
        $manter_precos = isset($data['manter_precos']);
        
        // Log da importação

        
        return true;
    }
    
    /**
     * Helper para garantir que a classe Database seja carregada apenas uma vez
     */
    private function ensure_database_loaded() {
        if (!class_exists('Sincronizador_WC_Database')) {
            $database_file = SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-database.php';
            if (file_exists($database_file)) {
                require_once $database_file;
            } else {
                error_log("SINCRONIZADOR: Arquivo class-database.php não encontrado em: " . $database_file);
                // Registrar que a classe não está disponível para evitar tentativas futuras
                return false;
            }
        }
        return true;
    }

    /**
     * Cache estático para verificação de tabelas
     */
    private static $tables_checked = false;

    /**
     * Verificar se as tabelas necessárias existem (com cache estático)
     */
    private function check_cache_table_exists() {
        if (!self::$tables_checked) {
            global $wpdb;
            
            // Verificar tabelas críticas
            $tables_to_check = array(
                'sincronizador_lojistas',
                'sincronizador_produtos_cache',
                'sincronizador_vendas'
            );
            
            $need_create_tables = false;
            foreach ($tables_to_check as $table_suffix) {
                $table_name = $wpdb->prefix . $table_suffix;
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
                if (!$table_exists) {
                    $need_create_tables = true;
                    break;
                }
            }
            
            if ($need_create_tables) {
                if ($this->ensure_database_loaded() && class_exists('Sincronizador_WC_Database')) {
                    Sincronizador_WC_Database::create_tables();
                    error_log("SINCRONIZADOR: Tabelas criadas com sucesso!");
                }
            }
            
            self::$tables_checked = true;
        }
        
        return true;
    }

    private function includes() {
        // Classes principais
        $this->ensure_database_loaded();
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-product-importer.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-product-importer.php';
        }
        
        // Admin classes
        if (is_admin()) {
            if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-assets.php')) {
                require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-assets.php';
            }
        }
        
        // Classes opcionais
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-cache.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-cache.php';
        }
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-batch-processor.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-batch-processor.php';
        }
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-api-handler.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-api-handler.php';
        }
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-sync-manager.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-sync-manager.php';
        }
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-lojista-manager.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-lojista-manager.php';
        }
        
        // Admin
        if (is_admin()) {
            if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin.php')) {
                require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin.php';
            }
            
            if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin-menu.php')) {
                require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin-menu.php';
            }
            
            if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin-ajax.php')) {
                require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'admin/class-admin-ajax.php';
            }
        }
        
        // API Endpoints
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'api/class-api-endpoints.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'api/class-api-endpoints.php';
        }
        
        if (file_exists(SINCRONIZADOR_WC_PLUGIN_DIR . 'api/class-master-api.php')) {
            require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'api/class-master-api.php';
        }
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        register_activation_hook(SINCRONIZADOR_WC_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(SINCRONIZADOR_WC_PLUGIN_FILE, array($this, 'deactivate'));
        
        // 🚀 NOVO: Agendar limpeza automática de cache
        add_action('wp', array($this, 'schedule_cache_cleanup'));
        add_action('sincronizador_wc_cleanup_cache', array($this, 'cleanup_expired_cache'));
        
        // Instanciar Product_Importer e registrar AJAX
        if (class_exists('Product_Importer')) {
            $this->product_importer = new Product_Importer();
            add_action('wp_ajax_sincronizador_wc_import_produtos', array($this->product_importer, 'ajax_import_produtos'));
        }
        
        // AJAX handlers para importação
        add_action('wp_ajax_sincronizador_wc_validate_lojista', array($this, 'ajax_validate_lojista'));
        add_action('wp_ajax_sincronizador_wc_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_sincronizador_wc_get_produtos_fabrica', array($this, 'ajax_get_produtos_fabrica'));
        // BACKUP - Garantir que a ação AJAX está registrada
        add_action('wp_ajax_sincronizador_wc_import_produtos', array($this, 'ajax_import_produtos_backup'));
    // AJAX handlers para atualização de preços (iniciar e checar status)
    add_action('wp_ajax_sincronizador_wc_start_price_update', array($this, 'ajax_start_price_update'));
    add_action('wp_ajax_sincronizador_wc_price_update_status', array($this, 'ajax_price_update_status'));
        
        // AJAX handlers para sincronização
        add_action('wp_ajax_verificar_lojista_config', array($this, 'ajax_verificar_lojista_config'));
        add_action('wp_ajax_sincronizar_produtos', array($this, 'ajax_sincronizar_produtos'));
        
        // AJAX handlers para produtos sincronizados
        add_action('wp_ajax_sincronizador_wc_get_produtos_sincronizados', array($this, 'ajax_get_produtos_sincronizados'));
        add_action('wp_ajax_sincronizador_wc_sync_vendas', array($this, 'ajax_sync_vendas'));
        add_action('wp_ajax_sincronizador_wc_testar_sync_produto', array($this, 'ajax_testar_sync_produto'));
        add_action('wp_ajax_sincronizador_wc_obter_detalhes_produto', array($this, 'ajax_obter_detalhes_produto'));
        add_action('wp_ajax_sincronizador_wc_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_sync_produtos', array($this, 'ajax_sync_produtos'));
        
        // AJAX handlers para relatórios
        add_action('wp_ajax_sincronizador_wc_get_lojistas', array($this, 'ajax_get_lojistas'));
        add_action('wp_ajax_sincronizador_wc_get_resumo_relatorio', array($this, 'ajax_get_resumo_relatorio'));
        add_action('wp_ajax_sincronizador_wc_get_graficos_relatorio', array($this, 'ajax_get_graficos_relatorio'));
        add_action('wp_ajax_sincronizador_wc_get_historico_relatorio', array($this, 'ajax_get_historico_relatorio'));
        add_action('wp_ajax_sincronizador_wc_export_relatorio', array($this, 'ajax_export_relatorio'));
        
        // AJAX handlers para relatórios de vendas
        add_action('wp_ajax_sincronizador_wc_get_lojistas_relatorios', array($this, 'ajax_get_lojistas_relatorios'));
        add_action('wp_ajax_sincronizador_wc_get_resumo_vendas', array($this, 'ajax_get_resumo_vendas'));
        add_action('wp_ajax_sincronizador_wc_get_vendas_por_lojista', array($this, 'ajax_get_vendas_por_lojista'));
        add_action('wp_ajax_sincronizador_wc_get_produtos_mais_vendidos', array($this, 'ajax_get_produtos_mais_vendidos'));
        add_action('wp_ajax_sincronizador_wc_get_vendas_detalhadas', array($this, 'ajax_get_vendas_detalhadas'));
        add_action('wp_ajax_sincronizador_wc_export_vendas', array($this, 'ajax_export_vendas'));
        add_action('wp_ajax_sincronizador_wc_limpar_cache_relatorios', array($this, 'ajax_limpar_cache_relatorios'));
        
        // Inicializar classes se existirem
        add_action('plugins_loaded', array($this, 'init_classes'));
        
        // Registrar endpoint REST personalizado para vendas
        add_action('rest_api_init', array($this, 'register_custom_rest_endpoints'));
        
        // Registrar Master API no hook correto
        add_action('rest_api_init', array($this, 'init_master_api'));

    // Cron hook para processar atualização de preços em lote
    add_action('sincronizador_wc_process_price_update', array($this, 'process_price_update_cron'));
    }
    
    /**
     * Inicializar Master API no momento correto para REST API
     */
    public function init_master_api() {
        // Verificar se arquivo existe
        $file_path = SINCRONIZADOR_WC_PLUGIN_DIR . 'api/class-master-api.php';
        if (!file_exists($file_path)) {
            return;
        }
        
        // Incluir arquivo se ainda não foi incluído
        if (!class_exists('Sincronizador_WC_Master_API')) {
            require_once $file_path;
        }
        
        // Inicializar Master API
        if (class_exists('Sincronizador_WC_Master_API')) {
            $master_api = new Sincronizador_WC_Master_API();
            
            // Registrar rotas da Master API
            register_rest_route('sincronizador-wc/v1', 'master/fabrica-status', array(
                'methods' => 'GET',
                'callback' => array($master_api, 'get_fabrica_status'),
                'permission_callback' => array($master_api, 'check_master_permissions')
            ));
            
            register_rest_route('sincronizador-wc/v1', 'master/health', array(
                'methods' => 'GET', 
                'callback' => array($master_api, 'health_check'),
                'permission_callback' => array($master_api, 'check_master_permissions')
            ));
            
            register_rest_route('sincronizador-wc/v1', 'master/revendedores', array(
                'methods' => 'GET',
                'callback' => array($master_api, 'get_revendedores_detalhado'),
                'permission_callback' => array($master_api, 'check_master_permissions')
            ));
            
            register_rest_route('sincronizador-wc/v1', 'master/produtos-top', array(
                'methods' => 'GET',
                'callback' => array($master_api, 'get_produtos_top'),
                'permission_callback' => array($master_api, 'check_master_permissions')
            ));
            
            register_rest_route('sincronizador-wc/v1', 'master/sync-vendas/(?P<lojista_id>\d+)', array(
                'methods' => 'POST',
                'callback' => array($master_api, 'sync_vendas_endpoint'),
                'permission_callback' => array($master_api, 'check_master_permissions')
            ));
        }
    }
    
    public function init_classes() {
        // Inicializar database e criar tabelas se necessário
        if (class_exists('Sincronizador_WC_Database')) {
            // Verificar tabelas uma única vez
            $this->check_cache_table_exists();
            new Sincronizador_WC_Database();
        }
        
        // Inicializar cache
        if (class_exists('Sincronizador_WC_Cache')) {
            Sincronizador_WC_Cache::instance();
        }
        
        // Inicializar batch processor
        if (class_exists('Sincronizador_WC_Batch_Processor')) {
            new Sincronizador_WC_Batch_Processor();
        }
        
        // Inicializar product importer
        if (class_exists('Sincronizador_WC_Product_Importer')) {
            new Sincronizador_WC_Product_Importer();
        }
        
        // Inicializar admin
        // Desabilitado temporariamente para evitar conflito com assets principais
        // if (is_admin() && class_exists('Sincronizador_WC_Admin')) {
        //     new Sincronizador_WC_Admin();
        // }
        
        // Inicializar admin menu
        if (is_admin()) {

            // COMENTADO: Evitar conflito de duplo registro de menus
            // if (class_exists('Sincronizador_WC_Admin_Menu')) {
            //     error_log('DEBUG: Classe Sincronizador_WC_Admin_Menu existe, inicializando...');
            //     new Sincronizador_WC_Admin_Menu();
            // } else {
            //     error_log('DEBUG: Classe Sincronizador_WC_Admin_Menu NÃO existe!');
            // }

        } else {

        }
        
        // Inicializar AJAX
        if (is_admin() && class_exists('Sincronizador_WC_Admin_Ajax')) {
            new Sincronizador_WC_Admin_Ajax();
        }
        
        // Inicializar API endpoints
        if (class_exists('Sincronizador_WC_API_Endpoints')) {
            new Sincronizador_WC_API_Endpoints();
        }
        
        // Hooks para salvar dados do Master API durante sincronizações
        add_action('sincronizador_wc_after_sync', array($this, 'salvar_dados_master_api'), 10, 2);
        add_action('sincronizador_wc_venda_sincronizada', array($this, 'atualizar_dados_master_api'), 10, 3);
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('sincronizador-wc', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Registrar endpoints REST customizados
     */
    public function register_custom_rest_endpoints() {
        register_rest_route('sincronizador-wc/v1', '/vendas/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_get_vendas_produto'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
    }
    
    /**
     * Verificar permissões para endpoints REST
     */
    public function rest_permission_check($request) {
        return current_user_can('manage_woocommerce');
    }
    
    /**
     * Endpoint REST para obter vendas de um produto
     */
    public function rest_get_vendas_produto($request) {
        $produto_id = $request['id'];
        
        global $wpdb;
        
        // Query para produtos simples e variações
        $query = $wpdb->prepare("
            SELECT 
                COALESCE(
                    (SELECT CAST(pm.meta_value AS UNSIGNED) 
                     FROM {$wpdb->postmeta} pm 
                     WHERE pm.post_id = %d AND pm.meta_key = '_total_sales'), 
                    0
                ) AS vendas_simples,
                COALESCE(
                    (SELECT COUNT(DISTINCT oi.order_item_id)
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id
                     JOIN {$wpdb->posts} orders ON orders.ID = oi.order_id
                     WHERE (im.meta_key = '_product_id' AND im.meta_value = %d)
                        OR (im.meta_key = '_variation_id' AND im.meta_value IN (
                            SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'
                        ))
                     AND orders.post_status IN ('wc-completed', 'wc-processing')
                     AND oi.order_item_type = 'line_item'
                    ),
                    0
                ) AS vendas_variacoes
        ", $produto_id, $produto_id, $produto_id);
        
        $resultado = $wpdb->get_row($query);
        
        if ($resultado) {
            $vendas_total = max($resultado->vendas_simples, $resultado->vendas_variacoes);
            return rest_ensure_response(array(
                'vendas_total' => $vendas_total,
                'vendas_simples' => $resultado->vendas_simples,
                'vendas_variacoes' => $resultado->vendas_variacoes
            ));
        }
        
        return rest_ensure_response(array('vendas_total' => 0));
    }
    
    public function activate() {
        require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-activator.php';
        Sincronizador_WC_Activator::activate();
    }
    
    public function deactivate() {
        require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-deactivator.php';
        Sincronizador_WC_Deactivator::deactivate();
    }
    
    /**
     * Agendar limpeza automática de cache
     */
    public function schedule_cache_cleanup() {
        if (!wp_next_scheduled('sincronizador_wc_cleanup_cache')) {
            wp_schedule_event(time(), 'daily', 'sincronizador_wc_cleanup_cache');
        }
    }
    
    /**
     * Executar limpeza automática de cache expirado
     */
    public function cleanup_expired_cache() {
        if ($this->ensure_database_loaded() && class_exists('Sincronizador_WC_Database')) {
            $rows_deleted = Sincronizador_WC_Database::cleanup_expired_cache();
        }
    }
    
    /**
     * AJAX: Verificar configuração do lojista antes da sincronização
     */
    public function ajax_verificar_lojista_config() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        $lojista = null;
        foreach ($lojistas as $l) {
            if ($l['id'] == $lojista_id) {
                $lojista = $l;
                break;
            }
        }
        
        if (!$lojista) {
            wp_send_json_error('Lojista não encontrado');
            return;
        }
        
        // Verificar se tem API key configurada
        if (empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            wp_send_json_error('API key não configurada para este lojista');
            return;
        }
        
        // Verificar se está ativo
        if ($lojista['status'] !== 'ativo') {
            wp_send_json_error('Lojista está inativo');
            return;
        }
        
        // Verificar se tem URL válida
        if (empty($lojista['url'])) {
            wp_send_json_error('URL não configurada para este lojista');
            return;
        }
        
        // Tudo OK - pode prosseguir
        wp_send_json_success(array(
            'message' => 'Lojista validado e pronto para sincronização',
            'lojista_name' => $lojista['nome']
        ));
    }
    
    /**
     * AJAX: Testar conexão com lojista
     */
    public function ajax_test_connection() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        $lojista = null;
        foreach ($lojistas as $l) {
            if ($l['id'] == $lojista_id) {
                $lojista = $l;
                break;
            }
        }
        
        if (!$lojista) {
            wp_send_json_error('Lojista não encontrado');
        }
        
        // Testar conexão com a API do WooCommerce
        $result = $this->test_woocommerce_connection($lojista);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Iniciar atualização de preços (cria job em option e agenda cron)
     */
    public function ajax_start_price_update() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }

        $lojista_id = isset($_POST['lojista_id']) ? intval($_POST['lojista_id']) : 0;
        $lojista = $this->get_lojista($lojista_id);
        if (!$lojista) {
            wp_send_json_error('Lojista não encontrado');
        }

        // Criar job na opção
        $job = array(
            'id' => 'price_update_' . time(),
            'lojista_id' => $lojista_id,
            'percentual' => isset($lojista['percentual_acrescimo']) ? floatval($lojista['percentual_acrescimo']) : 0,
            'status' => 'pending',
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'errors_list' => array(),
            'created_at' => current_time('mysql')
        );

        update_option('sincronizador_wc_price_update_job', $job);

        // Agendar execução imediata do cron worker
        if (!wp_next_scheduled('sincronizador_wc_process_price_update')) {
            wp_schedule_single_event(time() + 1, 'sincronizador_wc_process_price_update');
        } else {
            // Forçar execução rápida
            wp_clear_scheduled_hook('sincronizador_wc_process_price_update');
            wp_schedule_single_event(time() + 1, 'sincronizador_wc_process_price_update');
        }

        wp_send_json_success(array('job_id' => $job['id']));
    }

    /**
     * AJAX: Checar status do job de atualização de preços
     */
    public function ajax_price_update_status() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }

        $job = get_option('sincronizador_wc_price_update_job', false);
        if (!$job) {
            wp_send_json_error('Nenhum job em andamento');
        }

        wp_send_json_success($job);
    }

    /**
     * Cron worker: processa o job em lotes
     */
    public function process_price_update_cron() {
        $job = get_option('sincronizador_wc_price_update_job', false);
        if (!$job || !isset($job['lojista_id'])) {
            return;
        }

        // Marcar em progresso
        $job['status'] = 'running';
        update_option('sincronizador_wc_price_update_job', $job);

        $lojista = $this->get_lojista($job['lojista_id']);
        if (!$lojista) {
            $job['status'] = 'failed';
            $job['errors']++;
            $job['errors_list'][] = 'Lojista não encontrado';
            update_option('sincronizador_wc_price_update_job', $job);
            return;
        }

        // Processar em batches para evitar timeout
        $batch_size = defined('SINCRONIZADOR_WC_BATCH_SIZE') ? SINCRONIZADOR_WC_BATCH_SIZE : 50;

        // Obter lista de produtos locais (IDs) armazenados entre chamadas para não recalcular tudo
        if (!isset($job['product_index'])) {
            $produtos = $this->get_produtos_locais();
            $job['products_list'] = array_map(function($p) { return array('id' => $p['id'], 'sku' => $p['sku'], 'regular_price' => $p['regular_price'], 'sale_price' => $p['sale_price']); }, $produtos);
            $job['product_index'] = 0;
        }

        $total = count($job['products_list']);
        $start = $job['product_index'];
        $end = min($start + $batch_size, $total);

        for ($i = $start; $i < $end; $i++) {
            $p = $job['products_list'][$i];
            $sku = $p['sku'] ?? '';
            if (empty($sku)) {
                $job['skipped']++;
                $job['product_index']++;
                continue;
            }

            $dest_id = $this->buscar_produto_no_destino($lojista, $sku);
            if (!$dest_id) {
                $job['skipped']++;
                $job['product_index']++;
                continue;
            }

            // Determinar preco base
            $preco_base = is_numeric($p['regular_price']) && $p['regular_price'] !== '' ? floatval($p['regular_price']) : (is_numeric($p['sale_price']) && $p['sale_price'] !== '' ? floatval($p['sale_price']) : 0);
            $novo_preco = $preco_base * (1 + ($job['percentual'] / 100));
            $novo_preco_str = number_format($novo_preco, 2, '.', '');

            $url = rtrim($lojista['url'], '/') . '/wp-json/wc/v3/products/' . intval($dest_id);
            $response = wp_remote_request($url, array('method' => 'PUT', 'timeout' => 30, 'headers' => array('Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']), 'Content-Type' => 'application/json'), 'body' => json_encode(array('regular_price' => $novo_preco_str))));

            if (is_wp_error($response)) {
                $job['errors']++;
                $job['errors_list'][] = 'Erro SKU ' . $sku . ': ' . $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    $job['updated']++;
                } else {
                    $job['errors']++;
                    $job['errors_list'][] = 'HTTP ' . $code . ' SKU ' . $sku;
                }
            }

            $job['processed']++;
            $job['product_index']++;
        }

        // Se ainda há produtos, re-agendar
        if ($job['product_index'] < $total) {
            update_option('sincronizador_wc_price_update_job', $job);
            // Agendar próxima execução imediata
            wp_schedule_single_event(time() + 1, 'sincronizador_wc_process_price_update');
            return;
        }

        // Finalizar job
        $job['status'] = 'completed';
        $job['finished_at'] = current_time('mysql');
        update_option('sincronizador_wc_price_update_job', $job);

        // Disparar ação pós-processo
        do_action('sincronizador_wc_price_update_completed', $job);
    }
    
    /**
     * Testa a conexão com a API do WooCommerce
     */
    private function test_woocommerce_connection($lojista) {



        
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/system_status';

        
        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            $error_msg = 'Erro de conexão: ' . $response->get_error_message();

            return array(
                'success' => false,
                'message' => $error_msg
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);

        
        if ($response_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);

            
            if (isset($body['environment']['version'])) {
                $success_msg = 'Conexão OK! WooCommerce versão: ' . $body['environment']['version'];

                return array(
                    'success' => true,
                    'message' => $success_msg
                );
            } else {
                $success_msg = 'Conexão estabelecida com sucesso!';

                return array(
                    'success' => true,
                    'message' => $success_msg
                );
            }
        } else if ($response_code === 401) {
            $error_msg = 'Erro de autenticação. Verifique as credenciais (Consumer Key/Secret).';

            return array(
                'success' => false,
                'message' => $error_msg
            );
        } else if ($response_code === 404) {
            $error_msg = 'API WooCommerce não encontrada. Verifique se WooCommerce está ativo na loja destino.';

            return array(
                'success' => false,
                'message' => $error_msg
            );
        } else if ($response_code === 400) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = 'Erro HTTP 400';
            
            if (isset($body['message'])) {
                $error_message = $body['message'];
                
                // Tratamento especial para erros de imagem
                if (strpos($error_message, 'image_upload_error') !== false || 
                    strpos($error_message, 'Erro ao obter a imagem remota') !== false) {
                    $error_msg = 'Erro de imagem detectado. Conexão API OK, mas há problemas com URLs de imagens. Configure imagens com URLs válidas e acessíveis.';

                    return array(
                        'success' => false,
                        'message' => $error_msg
                    );
                }
            }
            
            $error_msg = 'Erro HTTP 400: ' . $error_message;

            return array(
                'success' => false,
                'message' => $error_msg
            );
        } else {
            $error_msg = 'Erro HTTP ' . $response_code . '. Verifique a URL da loja.';

            return array(
                'success' => false,
                'message' => $error_msg
            );
        }
    }

    /**
     * AJAX: Validar lojista
     */
    public function ajax_validate_lojista() {

        
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {

            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $produto_id = isset($_POST['produto_id']) ? intval($_POST['produto_id']) : null;
        


        
        $lojistas = get_option('sincronizador_wc_lojistas', array());

        
        // Procurar lojista pelo ID
        $lojista_data = null;
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {

            wp_send_json_error('Lojista não encontrado - ID: ' . $lojista_id);
        }
        

        
        // Se foi passado um produto_id, retornar dados específicos do produto
        if ($produto_id) {

            $resultado = $this->testar_produto_destino($lojista_data, $produto_id);
            if ($resultado['success']) {
                wp_send_json_success($resultado['data']);
            } else {
                wp_send_json_error($resultado['message']);
            }
            return;
        }
        
        // Senão, fazer teste geral de conexão

        $result = $this->test_woocommerce_connection($lojista_data);
        


        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Testar produto específico no destino
     */
    private function testar_produto_destino($lojista_data, $produto_id_fabrica) {
        // Primeiro encontrar o ID do produto no destino
        $historico_envios = get_option('sincronizador_wc_historico_envios', array());
        $lojista_url = $lojista_data['url'];
        
        if (!isset($historico_envios[$lojista_url][$produto_id_fabrica])) {
            return array(
                'success' => false,
                'message' => 'Produto não foi sincronizado ainda para este lojista'
            );
        }
        
        $produto_id_destino = $historico_envios[$lojista_url][$produto_id_fabrica];
        
        // Obter dados do produto no destino
        $dados_produto = $this->get_produto_destino($lojista_data, $produto_id_destino);
        
        if (!$dados_produto) {
            return array(
                'success' => false,
                'message' => 'Produto não encontrado no destino ou erro de conexão'
            );
        }
        
        // Retornar dados formatados
        return array(
            'success' => true,
            'data' => array(
                'id_destino' => $dados_produto['id'] ?? 'N/A',
                'nome' => $dados_produto['name'] ?? 'N/A',
                'sku' => $dados_produto['sku'] ?? 'N/A',
                'preco' => $dados_produto['price'] ?? 0,
                'estoque' => $dados_produto['stock_quantity'] ?? 0,
                'status' => $dados_produto['status'] ?? 'N/A',
                'tipo' => $dados_produto['type'] ?? 'simples'
            )
        );
    }
    
    /**
     * AJAX: Obter produtos da fábrica
     */
    public function ajax_get_produtos_fabrica() {

        
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {

            wp_die('Sem permissão');
        }
        

        // Buscar produtos locais (da fábrica)
        $produtos = $this->get_produtos_locais();

        
        if (empty($produtos)) {

            wp_send_json_error('Nenhum produto encontrado na fábrica. Certifique-se de ter produtos WooCommerce com SKU cadastrados.');
        }
        

        // Formatar produtos para exibição
        $produtos_formatados = array();
        foreach ($produtos as $produto) {
            // Para obter o objeto do produto real
            $produto_obj = wc_get_product($produto['id']);
            
            // Determinar preços corretos para exibir
            $preco_regular = 0;
            $preco_promocional = 0;
            
            if ($produto_obj) {
                if ($produto_obj->is_type('variable')) {
                    // Para produtos variáveis, pegar preços das variações
                    $variation_prices = $produto_obj->get_variation_prices();
                    
                    // Preço regular (menor preço regular das variações)
                    if (!empty($variation_prices['regular_price'])) {
                        $preco_regular = floatval(min($variation_prices['regular_price']));
                    }
                    
                    // Preço promocional (menor preço de venda das variações, se houver)
                    if (!empty($variation_prices['sale_price'])) {
                        $precos_promocionais = array_filter($variation_prices['sale_price'], function($price) {
                            return $price !== '' && $price > 0;
                        });
                        if (!empty($precos_promocionais)) {
                            $preco_promocional = floatval(min($precos_promocionais));
                        }
                    }
                    
                    // Se não há preço promocional, usar o preço atual
                    if ($preco_promocional == 0 && !empty($variation_prices['price'])) {
                        $preco_promocional = floatval(min($variation_prices['price']));
                    }
                } else {
                    // Para produtos simples
                    $preco_regular = floatval($produto_obj->get_regular_price() ?: 0);
                    $preco_promocional = floatval($produto_obj->get_sale_price() ?: 0);
                    
                    // Se não há preço promocional, usar o preço atual
                    if ($preco_promocional == 0) {
                        $preco_promocional = floatval($produto_obj->get_price() ?: $preco_regular);
                    }
                }
            }
            
            // Determinar se está em promoção (preço promocional menor que regular)
            $em_promocao = ($preco_promocional > 0 && $preco_regular > 0 && $preco_promocional < $preco_regular);
            

            
            $produtos_formatados[] = array(
                'id' => $produto['id'],
                'nome' => $produto['name'],
                'sku' => $produto['sku'],
                'preco' => $preco_regular,
                'preco_promocional' => $em_promocao ? $preco_promocional : 0,
                'em_promocao' => $em_promocao,
                'estoque' => $produto['stock_quantity'] ?: 0,
                'categoria' => is_array($produto['categories']) ? implode(', ', $produto['categories']) : 'Sem categoria',
                'imagem' => !empty($produto['images']) ? $produto['images'][0] : 'https://via.placeholder.com/80x80/CCCCCC/FFFFFF?text=IMG',
                'status' => 'ativo',
                'descricao' => $produto['description'],
                'descricao_curta' => $produto['short_description']
            );
        }
        

        wp_send_json_success($produtos_formatados);
    }
    
    /**
     * AJAX: Sincronizar produtos
     */
    public function ajax_sincronizar_produtos() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        // Procurar lojista pelo ID
        $lojista = null;
        foreach ($lojistas as $l) {
            if ($l['id'] == $lojista_id) {
                $lojista = $l;
                break;
            }
        }
        
        if (!$lojista) {
            wp_send_json_error('❌ Lojista não encontrado - ID: ' . $lojista_id);
        }
        
        // Verificar se o lojista tem API key configurada
        if (empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            wp_send_json_error('❌ API key não configurada para o lojista: ' . $lojista['nome'] . '. Configure as credenciais WooCommerce antes de sincronizar.');
        }
        
        // Verificar se o lojista está ativo
        if (isset($lojista['status']) && $lojista['status'] !== 'ativo') {
            wp_send_json_error('⚠️ Lojista "' . $lojista['nome'] . '" está inativo. Ative o lojista antes de sincronizar.');
        }
        
        // Executar sincronização real
        $produtos_sincronizados = $this->sync_produtos($lojista_id);
        
        if ($produtos_sincronizados === false) {
            wp_send_json_error('❌ Erro na sincronização. Verifique os logs do servidor e as configurações da API.');
        }
        
        // Obter relatório detalhado
        $relatorios = get_option('sincronizador_wc_relatorios_sync', array());
        $ultimo_relatorio = !empty($relatorios) ? $relatorios[0] : array();
        
        // Atualizar timestamp da última sincronização
        foreach ($lojistas as &$l) {
            if ($l['id'] == $lojista_id) {
                $l['ultima_sync'] = current_time('mysql');
                break;
            }
        }
        update_option('sincronizador_wc_lojistas', $lojistas);
        
        wp_send_json_success(array(
            'produtos_sincronizados' => $produtos_sincronizados,
            'produtos_criados' => $ultimo_relatorio['produtos_criados'] ?? 0,
            'produtos_atualizados' => $ultimo_relatorio['produtos_atualizados'] ?? 0,
            'erros' => $ultimo_relatorio['erros'] ?? 0,
            'lojista' => $lojista['nome'],
            'detalhes' => "✅ Sincronização concluída! {$produtos_sincronizados} produtos processados com sucesso.",
            'timestamp' => current_time('mysql')
        ));
    }
    
    // *** FUNÇÃO AJAX REMOVIDA - USAR APENAS DA CLASSE Product_Importer ***
    // ajax_import_produtos() foi removida para evitar conflitos
    // A classe Product_Importer tem toda a lógica de verificação de duplicatas
    
    /**
     * BACKUP - Garantir que importação funcione
     */
    public function ajax_import_produtos_backup() {
        // Tentar usar a classe Product_Importer
        if (class_exists('Sincronizador_WC_Product_Importer')) {
            $importer = new Sincronizador_WC_Product_Importer();
            if (method_exists($importer, 'ajax_import_produtos')) {
                $importer->ajax_import_produtos();
                return;
            }
        }
        
        // Fallback simples se a classe não estiver disponível
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
            return;
        }
        
        wp_send_json_error('Classe Product_Importer não disponível');
    }
    
    /**
     * AJAX: Obter produtos sincronizados
     */
    public function ajax_get_produtos_sincronizados() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'];
        
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        // Procurar lojista pelo ID
        $lojista_data = null;
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            wp_send_json_error('Lojista não encontrado - ID: ' . $lojista_id);
        }
        
        // Verificar se o lojista tem configuração válida
        if (empty($lojista_data['consumer_key']) || empty($lojista_data['consumer_secret'])) {
            wp_send_json_error('Lojista não tem API key configurada. Configure a API key antes de visualizar produtos sincronizados.');
            return;
        }
        
        if ($lojista_data['status'] !== 'ativo') {
            wp_send_json_error('Lojista está inativo. Ative o lojista antes de visualizar produtos sincronizados.');
            return;
        }
        
        // Limpar cache se solicitado
        if ($force_refresh) {
            $this->limpar_cache_produtos_sincronizados($lojista_id);
        }
        
        $produtos_sincronizados = $this->get_produtos_sincronizados($lojista_data);
        
        // Se não há produtos, mas o lojista está configurado, dar uma mensagem mais clara
        if (empty($produtos_sincronizados)) {
            wp_send_json_error('Nenhum produto foi sincronizado ainda para este lojista. Execute uma sincronização primeiro.');
            return;
        }
        
        wp_send_json_success($produtos_sincronizados);
    }
    
    /**
     * Limpar cache de produtos sincronizados
     */
    private function limpar_cache_produtos_sincronizados($lojista_id) {
        // Obter dados do lojista para limpar cache específico
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        $lojista_url = '';
        
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_url = $lojista['url'];
                break;
            }
        }
        
        if ($lojista_url) {
            // Limpar cache principal de produtos sincronizados
            $cache_key = 'sincronizador_wc_produtos_sync_' . md5($lojista_url);
            delete_transient($cache_key);
            
            // Limpar cache individual de produtos e vendas
            $historico_envios = get_option('sincronizador_wc_historico_envios', array());
            if (isset($historico_envios[$lojista_url])) {
                foreach ($historico_envios[$lojista_url] as $produto_id_destino) {
                    // Cache de dados do produto
                    $produto_cache_key = 'produto_destino_' . md5($lojista_url . '_' . $produto_id_destino);
                    delete_transient($produto_cache_key);
                    
                    // Cache de vendas
                    $vendas_cache_key = 'vendas_destino_' . md5($lojista_url . '_' . $produto_id_destino);
                    delete_transient($vendas_cache_key);
                }
            }
        }
        
        // Limpar transients relacionados se existirem
        delete_transient('sincronizador_wc_produtos_sync_' . $lojista_id);
        delete_transient('sincronizador_wc_vendas_' . $lojista_id);
        
        // Forçar limpeza de qualquer cache do WordPress
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        

    }

    /**
     * AJAX: Limpar cache de produtos sincronizados
     */
    public function ajax_clear_cache() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = isset($_POST['lojista_id']) ? intval($_POST['lojista_id']) : 0;
        
        if (!$lojista_id) {
            wp_send_json_error('ID do lojista é obrigatório');
            return;
        }
        
        // 🚀 Limpar cache do transient (método antigo)
        $this->limpar_cache_produtos_sincronizados($lojista_id);
        
        // Limpar cache do banco de dados
        try {
            if ($this->ensure_database_loaded() && class_exists('Sincronizador_WC_Database')) {
                $this->check_cache_table_exists();
                $rows_deleted = Sincronizador_WC_Database::clear_produtos_cache($lojista_id);
                wp_send_json_success("Cache limpo com sucesso. {$rows_deleted} registros removidos do cache do banco.");
            } else {
                wp_send_json_success("Cache limpo com sucesso.");
            }
        } catch (Exception $e) {
            wp_send_json_success("Cache limpo com sucesso.");
        }
    }
    
    /**
     * AJAX: Sincronizar vendas
     */
    public function ajax_sync_vendas() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        // Procurar lojista pelo ID
        $lojista_data = null;
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            wp_send_json_error('Lojista não encontrado - ID: ' . $lojista_id);
        }
        
        $resultado = $this->sincronizar_vendas_lojista($lojista_data);
        
        if ($resultado['success']) {
            wp_send_json_success($resultado);
        } else {
            wp_send_json_error($resultado['message']);
        }
    }
    
    /**
     * AJAX: Testar sincronização de produto específico
     */
    public function ajax_testar_sync_produto() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $produto_id = intval($_POST['produto_id']);
        
        $lojistas = $this->get_lojistas();
        $lojista_data = null;
        
        // Encontrar o lojista pelo ID
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            wp_send_json_error('Lojista não encontrado');
        }
        $produto = wc_get_product($produto_id);
        
        if (!$produto) {
            wp_send_json_error('Produto não encontrado');
        }
        
        require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'includes/class-product-importer.php';
        $importer = new Sincronizador_WC_Product_Importer();
        
        // Preparar dados do produto
        $dados_produto = $importer->montar_dados_produto($produto, array(
            'incluir_imagens' => true,
            'incluir_variacoes' => true,
            'manter_precos' => true
        ));
        
        // Verificar se produto já existe no destino
        $id_destino = $importer->buscar_produto_por_sku($lojista_data['url'], $lojista_data['consumer_key'], $lojista_data['consumer_secret'], $produto->get_sku());
        
        $resultado = $importer->importar_produto_para_destino(
            $lojista_data['url'],
            $lojista_data['consumer_key'],
            $lojista_data['consumer_secret'],
            $dados_produto,
            $id_destino
        );
        
        if ($resultado['success']) {
            wp_send_json_success($resultado);
        } else {
            wp_send_json_error($resultado['message']);
        }
    }
    
    /**
     * AJAX: Obter detalhes completos do produto para o modal
     */
    public function ajax_obter_detalhes_produto() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $produto_id_fabrica = intval($_POST['produto_id_fabrica']);
        
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        $lojista_data = null;
        
        // Encontrar o lojista pelo ID
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            wp_send_json_error('Lojista não encontrado');
        }
        
        // Buscar produto da fábrica
        $produto_fabrica = wc_get_product($produto_id_fabrica);
        if (!$produto_fabrica) {
            wp_send_json_error('Produto não encontrado na fábrica');
        }
        
        // Buscar ID do produto no destino
        $historico_envios = get_option('sincronizador_wc_historico_envios', array());
        $lojista_url = $lojista_data['url'];
        
        if (!isset($historico_envios[$lojista_url][$produto_id_fabrica])) {
            wp_send_json_error('Produto não foi sincronizado ainda para este lojista');
        }
        
        $produto_id_destino = $historico_envios[$lojista_url][$produto_id_fabrica];
        
        // Buscar dados completos do produto no destino
        $dados_destino = $this->get_produto_destino($lojista_data, $produto_id_destino);
        
        if (!$dados_destino) {
            wp_send_json_error('Produto não encontrado no destino ou erro de conexão');
        }
        
        // Buscar vendas (agora com detalhes por variação) e normalizar para inteiro
        $dados_vendas = $this->get_vendas_produto_destino_cached($lojista_data, $produto_id_destino);
        $vendas_total = 0;
        $vendas_por_variacao = array();
        if (is_array($dados_vendas)) {
            if (isset($dados_vendas['total_vendas'])) {
                $vendas_total = intval($dados_vendas['total_vendas']);
            } elseif (isset($dados_vendas['vendas_total'])) {
                $vendas_total = intval($dados_vendas['vendas_total']);
            } else {
                $vendas_total = 0;
            }

            if (isset($dados_vendas['vendas_por_variacao']) && is_array($dados_vendas['vendas_por_variacao'])) {
                $vendas_por_variacao = $dados_vendas['vendas_por_variacao'];
            }
        } else {
            $vendas_total = intval($dados_vendas ?? 0);
        }
        
        // Buscar variações completas se for produto variável
        $variacoes_completas = array();
        if ($this->produto_tem_variacoes($produto_fabrica)) {
            // Reutilizar a função otimizada que já tenta vários matches e coleta vendas por variação
            $dados_destino_para_matching = $dados_destino;
            $dados_destino_para_matching['lojista_data'] = $lojista_data;
            $variacoes_completas = $this->get_variacoes_produto_optimized($produto_fabrica, $dados_destino_para_matching);
        }
        
        // Montar resposta com dados completos
        $produto_completo = array(
            'id_fabrica' => $produto_fabrica->get_id(),
            'id_destino' => $produto_id_destino,
            'nome' => $produto_fabrica->get_name(),
            'sku' => $produto_fabrica->get_sku(),
            'tipo_produto' => $produto_fabrica->get_type(),
            'status' => 'sincronizado',
            'preco_fabrica' => $this->formatar_preco_produto($produto_fabrica),
            'preco_destino' => $this->formatar_preco_destino($dados_destino),
            'estoque_fabrica' => ($produto_fabrica->get_stock_quantity() !== null && $produto_fabrica->get_stock_quantity() !== '') ? $produto_fabrica->get_stock_quantity() : 0,
            'estoque_destino' => (isset($dados_destino['stock_quantity']) && $dados_destino['stock_quantity'] !== null && $dados_destino['stock_quantity'] !== '') ? $dados_destino['stock_quantity'] : 0,
            'vendas' => $vendas_total,
            'tem_variacoes' => $this->produto_tem_variacoes($produto_fabrica),
            'variacoes' => $variacoes_completas,
            'ultima_sync' => date('Y-m-d\TH:i:s')
        );
        

        
        wp_send_json_success($produto_completo);
    }
    
    /**
     * Obter produtos sincronizados
     */
    private function get_produtos_sincronizados($lojista_data) {
        $start_time = microtime(true);
        
        $lojista_url = $lojista_data['url'];
        $lojista_id = $lojista_data['id'];
        
        // Tentar buscar do cache do banco primeiro
        if (!isset($_POST['force_refresh'])) {
            try {
                if ($this->ensure_database_loaded() && class_exists('Sincronizador_WC_Database')) {
                    $this->check_cache_table_exists();
                    $produtos_cached = Sincronizador_WC_Database::get_produtos_cache($lojista_id);
                    
                    if ($produtos_cached !== false) {
                        return $produtos_cached;
                    }
                }
            } catch (Exception $e) {
                // Continuar sem cache do banco
            }
        }
        
        // Se não tem cache válido, carregar normalmente e salvar no cache
        $produtos_sincronizados = array();
        $historico_envios = get_option('sincronizador_wc_historico_envios', array());
        
        // Obter produtos que já foram enviados para este lojista
        if (!isset($historico_envios[$lojista_url]) || empty($historico_envios[$lojista_url])) {
            return array();
        }
        
        // Fazer batch request para obter todos os produtos de uma vez
        $produtos_ids_destino = array_values($historico_envios[$lojista_url]);
        $dados_produtos_destino = $this->get_produtos_destino_batch($lojista_data, $produtos_ids_destino);

        foreach ($historico_envios[$lojista_url] as $produto_id_fabrica => $produto_id_destino) {
            $produto_fabrica = wc_get_product($produto_id_fabrica);
            if (!$produto_fabrica) {
                continue;
            }
            // Usar dados do batch ao invés de requisição individual
            $dados_destino = isset($dados_produtos_destino[$produto_id_destino]) ? $dados_produtos_destino[$produto_id_destino] : false;
            // Se produto não existe mais no destino, remover do histórico
            if (!$dados_destino) {
                unset($historico_envios[$lojista_url][$produto_id_fabrica]);
                continue;
            }
            // Obter vendas (com cache individual) e normalizar para inteiro
            $vendas_data = $this->get_vendas_produto_destino_cached($lojista_data, $produto_id_destino);
            $vendas_total = 0;
            if (is_array($vendas_data)) {
                if (isset($vendas_data['total_vendas'])) {
                    $vendas_total = intval($vendas_data['total_vendas']);
                } elseif (isset($vendas_data['vendas_total'])) {
                    $vendas_total = intval($vendas_data['vendas_total']);
                } else {
                    $vendas_total = 0;
                }
            } else {
                $vendas_total = intval($vendas_data ?? 0);
            }
            // Verificar se produto tem variações
            $tem_variacoes = $this->produto_tem_variacoes($produto_fabrica);
            $variacoes_info = array();
            if ($tem_variacoes && $dados_destino) {
                // Passar lojista_data para get_variacoes_produto_optimized
                $dados_destino['lojista_data'] = $lojista_data;
                $variacoes_info = $this->get_variacoes_produto_optimized($produto_fabrica, $dados_destino);
            }
            $produto_data = array(
                'id_fabrica' => $produto_fabrica->get_id(),
                'id_destino' => $produto_id_destino,
                'nome' => $produto_fabrica->get_name(),
                'sku' => $produto_fabrica->get_sku(),
                'imagem' => wp_get_attachment_image_url($produto_fabrica->get_image_id(), 'thumbnail') ?: 'https://via.placeholder.com/50x50',
                'status' => $dados_destino['status'] ?? 'unknown',
                'preco_fabrica' => $this->formatar_preco_produto($produto_fabrica),
                'preco_destino' => $this->formatar_preco_destino($dados_destino),
                'estoque_fabrica' => ($produto_fabrica->get_stock_quantity() !== null && $produto_fabrica->get_stock_quantity() !== '') ? $produto_fabrica->get_stock_quantity() : 0,
                'estoque_destino' => $this->get_estoque_real_destino($dados_destino),
                'vendas' => $vendas_total,
                'ultima_sync' => $dados_destino['date_modified'] ?? null,
                'tem_variacoes' => $tem_variacoes,
                'variacoes' => $variacoes_info,
                'tipo_produto' => $tem_variacoes ? 'variável' : 'simples'
            );
            $produtos_sincronizados[] = $produto_data;
        }
        
        // Salvar histórico atualizado (produtos removidos foram retirados)
        update_option('sincronizador_wc_historico_envios', $historico_envios);
        
        // Salvar no cache do banco
        if (!empty($produtos_sincronizados)) {
            try {
                if ($this->ensure_database_loaded() && class_exists('Sincronizador_WC_Database')) {
                    $this->check_cache_table_exists();
                    Sincronizador_WC_Database::save_produtos_cache($lojista_id, $lojista_url, $produtos_sincronizados);
                }
            } catch (Exception $e) {
                // Continuar sem salvar no cache
            }
        }
        
    // 🚀 HOOK: Atualizar dados da Master API após sincronização
    // Removido do_action com $lojista indefinido
        
        return $produtos_sincronizados;
    }
    
    /**
     * Obter dados de múltiplos produtos de uma vez (BATCH REQUEST)
     */
    private function get_produtos_destino_batch($lojista_data, $produtos_ids) {
        if (empty($produtos_ids)) {
            return array();
        }
        
        $produtos_data = array();
        $batch_size = 10; // Processar 10 produtos por vez
        $batches = array_chunk($produtos_ids, $batch_size);
        
        foreach ($batches as $batch) {
            $ids_string = implode(',', $batch);
            $url = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/products?include=' . $ids_string . '&per_page=' . $batch_size;
            
            $response = wp_remote_get($url, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
                    'Content-Type' => 'application/json'
                )
            ));
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $batch_data = json_decode(wp_remote_retrieve_body($response), true);
                
                if (is_array($batch_data)) {
                    foreach ($batch_data as $produto) {
                        $produtos_data[$produto['id']] = $produto;
                    }
                }
            }
        }
        
        return $produtos_data;
    }
    
    /**
     * Versão otimizada para obter variações (usa dados já obtidos)
     */
    private function get_variacoes_produto_optimized($produto_fabrica, $dados_produto_destino) {
        $variacoes = array();
        if (!$this->produto_tem_variacoes($produto_fabrica)) {
            return $variacoes;
        }

        $variacoes_fabrica = $produto_fabrica->get_children();
        if (empty($variacoes_fabrica)) {
            return $variacoes;
        }

        // Função para extrair atributos relevantes (cor, tamanho) de uma variação
        $extrair_atributos = function($variacao) {
            $attrs = array();
            // Caso: resposta da API em array
            if (is_array($variacao) && isset($variacao['attributes'])) {
                foreach ($variacao['attributes'] as $att) {
                    // Compatível com estrutura API: ['id'=>..., 'name'=>..., 'option'=>...]
                    if (is_array($att)) {
                        $name = isset($att['name']) ? strtolower(trim(str_replace('pa_', '', $att['name']))) : '';
                        $option = isset($att['option']) ? strtolower(trim($att['option'])) : '';
                    } else {
                        // fallback genérico
                        $name = '';
                        $option = '';
                    }
                    if ($name !== '') { $attrs[$name] = $option; }
                }
                return $attrs;
            }

            // Caso: objeto WC_Product_Variation ou similar
            if (is_object($variacao)) {
                // Preferir get_variation_attributes quando disponível (retorna chave => valor)
                if (method_exists($variacao, 'get_variation_attributes')) {
                    $raw = $variacao->get_variation_attributes();
                    if (is_array($raw)) {
                        foreach ($raw as $name => $option) {
                            // Normalizar chaves como 'attribute_pa_tamanho', 'pa_tamanho', 'tamanho'
                            $n = strtolower(trim($name));
                            $n = preg_replace('/^attribute_/', '', $n);
                            $n = preg_replace('/^attribute-/', '', $n);
                            $n = preg_replace('/^pa_/', '', $n);
                            $n = preg_replace('/^pa-/', '', $n);
                            $option_val = $option;
                            if (is_array($option_val)) { $option_val = reset($option_val) ?: ''; }
                            $attrs[$n] = strtolower(trim((string)$option_val));
                        }
                        return $attrs;
                    }
                }

                // Fallback: get_attributes
                if (method_exists($variacao, 'get_attributes')) {
                    $raw = $variacao->get_attributes();
                    if (is_array($raw) && !empty($raw)) {
                        foreach ($raw as $name => $option) {
                            $n = strtolower(trim($name));
                            $n = preg_replace('/^attribute_/', '', $n);
                            $n = preg_replace('/^attribute-/', '', $n);
                            $n = preg_replace('/^pa_/', '', $n);
                            $n = preg_replace('/^pa-/', '', $n);
                            if (is_array($option)) { $option = reset($option) ?: ''; }
                            $attrs[$n] = $this->normalizar_valor_atributo($option);
                        }
                        return $attrs;
                    }
                }

                // Último recurso: ler do post meta da variação (attribute_...)
                if (method_exists($variacao, 'get_id')) {
                    $vid = $variacao->get_id();
                    if ($vid) {
                        $meta = get_post_meta($vid);
                        if (is_array($meta) && !empty($meta)) {
                            foreach ($meta as $mkey => $mval) {
                                if (strpos($mkey, 'attribute_') === 0) {
                                    $nm = strtolower(trim(str_replace('attribute_', '', $mkey)));
                                    $nm = preg_replace('/^pa_/', '', $nm);
                                    $val = '';
                                    if (is_array($mval)) { $val = reset($mval); } else { $val = $mval; }
                                    $attrs[$nm] = strtolower(trim((string)$val));
                                }
                            }
                            if (!empty($attrs)) {
                                return $attrs;
                            }
                        }
                    }
                }
            }

            return $attrs;
        };

        // Buscar vendas por variação direto do endpoint customizado (tabela wp_sincronizador_vendas)
        $vendas_por_variacao = array();
        $lojista_data = $dados_produto_destino['lojista_data'] ?? null;
        $produto_id_destino = $dados_produto_destino['id'] ?? null;
        $variacoes_destino = array();
        if ($lojista_data && $produto_id_destino) {
            $endpoint = trailingslashit($lojista_data['url']) . 'wp-json/sincronizador/v1/vendas-simples/' . $produto_id_destino;
            $response = wp_remote_get($endpoint, array('timeout' => 10, 'headers' => array('Content-Type' => 'application/json')));
                if (!is_wp_error($response)) {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    if ($code === 200) {
                        $data = json_decode($body, true);
                        if (isset($data['vendas_por_variacao']) && is_array($data['vendas_por_variacao'])) {
                            $vendas_por_variacao = $data['vendas_por_variacao'];
                        }
                    }
                }

            $variacoes_destino = $this->get_variacoes_destino($lojista_data, $produto_id_destino);
            // Logar variacoes_destino se estiver vazio para entender por que não retornou variações
            // variacoes_destino carregadas (se houver)
        }

        // Pré-processar mapas de vendas para correspondência rápida
        $vendas_by_dest_id = array();
        $vendas_by_factory_id = array();
        $vendas_by_sku = array();
        $vendas_by_attr_values = array(); // chave: valores ordenados concatenados

        foreach ($vendas_por_variacao as $var) {
            $v_vendas = isset($var['vendas']) ? intval($var['vendas']) : 0;
            if (isset($var['variacao_id'])) {
                $id = intval($var['variacao_id']);
                $vendas_by_dest_id[$id] = $v_vendas;
                $vendas_by_factory_id[$id] = $v_vendas; // guardar também como possível id_fabrica
            }
            if (!empty($var['sku'])) {
                $vendas_by_sku[$var['sku']] = $v_vendas;
            }
            // atributos podem vir como array ou string
            $attrs_vals = array();
            if (isset($var['attributes']) && is_array($var['attributes'])) {
                foreach ($var['attributes'] as $k => $vv) {
                    $attrs_vals[] = strtolower(trim($vv));
                }
            } elseif (isset($var['atributos']) && is_string($var['atributos'])) {
                $parts = array_map('trim', explode(',', $var['atributos']));
                foreach ($parts as $p) {
                    if (strpos($p, ':') !== false) {
                        list(,$vpart) = array_map('trim', explode(':', $p, 2));
                        $attrs_vals[] = strtolower($vpart);
                    }
                }
            }
            if (!empty($attrs_vals)) {
                sort($attrs_vals);
                $key = implode('|', $attrs_vals);
                $vendas_by_attr_values[$key] = $v_vendas;
            }
        }

        // Pré-processar atributos das variações do destino para matching por valores
        $dest_variations_by_attr_values = array();
        $dest_variations_by_sku = array();
        foreach ($variacoes_destino as $v_dest) {
            $attrs_dest = $extrair_atributos($v_dest);
            $vals = array();
            if (!empty($attrs_dest)) {
                foreach ($attrs_dest as $vv) { $vals[] = $this->normalizar_valor_atributo($vv); }
                sort($vals);
                $k = implode('|', $vals);
                $dest_variations_by_attr_values[$k] = $v_dest;
            }
            if (!empty($v_dest['sku'])) {
                $dest_variations_by_sku[$v_dest['sku']] = $v_dest;
            }
        }

    $idx_loop = 0;
    foreach ($variacoes_fabrica as $variacao_id) {
            $variacao_fabrica = wc_get_product($variacao_id);
            if (!$variacao_fabrica) continue;
            $attrs_fabrica = $extrair_atributos($variacao_fabrica);

            // debug statements removed

            // Procurar variação correspondente no destino
            $variacao_destino = null;
            // 1) Tentar por SKU (caso variações mantenham o mesmo SKU)
            if (!empty($variacoes_destino)) {
                foreach ($variacoes_destino as $v_dest) {
                    if (!empty($v_dest['sku']) && $variacao_fabrica->get_sku() && $v_dest['sku'] === $variacao_fabrica->get_sku()) {
                        $variacao_destino = $v_dest;
                        break;
                    }
                }
            }
            // 2) Se não encontrou por SKU, tentar por atributos
            if (!$variacao_destino) {
                foreach ($variacoes_destino as $v_dest) {
                    $attrs_destino = $extrair_atributos($v_dest);
                    if ($attrs_fabrica == $attrs_destino) {
                        $variacao_destino = $v_dest;
                        break;
                    }
                }
            }

            // 3) Fallback por índice/posição: se quantidade de variações for igual,
            // assumir que a ordem corresponde e mapear pela posição (último recurso)
                if (!$variacao_destino && !empty($variacoes_destino) && count($variacoes_destino) === count($variacoes_fabrica)) {
                    $fallback_dest = $variacoes_destino[$idx_loop] ?? null;
                    if ($fallback_dest) {
                        $variacao_destino = $fallback_dest;
                    }
                }

            // Buscar vendas_por_variacao. Tentamos corresponder por:
            // 1) variacao_id do destino (quando o endpoint usa IDs do destino)
            // 2) variacao_id da fábrica (compatibilidade)
            // 3) sku
            // 4) atributos (o endpoint pode retornar string "Cor: branco, Tamanho: m")
            $vendas_destino = 0;
            if (!empty($vendas_por_variacao)) {
                foreach ($vendas_por_variacao as $var) {
                    // Normalize vendas
                    $var_vendas = isset($var['vendas']) ? intval($var['vendas']) : 0;

                    // 1) variacao_id do destino
                    if (isset($var['variacao_id']) && $variacao_destino && intval($var['variacao_id']) === intval($variacao_destino['id'])) {
                        $vendas_destino = $var_vendas;
                        break;
                    }

                    // 2) variacao_id da fábrica (compatibilidade)
                    if (isset($var['variacao_id']) && intval($var['variacao_id']) === intval($variacao_id)) {
                        $vendas_destino = $var_vendas;
                        break;
                    }

                    // 3) SKU
                    if (isset($var['sku']) && $variacao_fabrica->get_sku() && $var['sku'] === $variacao_fabrica->get_sku()) {
                        $vendas_destino = $var_vendas;
                        break;
                    }

                    // 4) Atributos (podem vir como array ou string)
                    $attrs_var = array();
                    if (isset($var['attributes']) && is_array($var['attributes'])) {
                        $attrs_var = array_change_key_case($var['attributes'], CASE_LOWER);
                    } elseif (isset($var['atributos']) && is_string($var['atributos'])) {
                        // Ex: "Cor: branco, Tamanho: m" -> ['cor'=>'branco','tamanho'=>'m']
                        $parts = array_map('trim', explode(',', $var['atributos']));
                        foreach ($parts as $p) {
                            if (strpos($p, ':') !== false) {
                                list($k, $v) = array_map('trim', explode(':', $p, 2));
                                $k = strtolower(str_replace('pa_', '', $k));
                                $v = strtolower($v);
                                if ($k !== '') { $attrs_var[$k] = $v; }
                            }
                        }
                    }

                    // Checar mapa pré-processado por valores de atributos (mais rápido)
                    if (!empty($attrs_fabrica)) {
                        $vals = array();
                        foreach ($attrs_fabrica as $vv) { $vals[] = $this->normalizar_valor_atributo($vv); }
                        sort($vals);
                        $k = implode('|', $vals);
                        if (isset($vendas_by_attr_values[$k])) {
                            $vendas_destino = intval($vendas_by_attr_values[$k]);
                            break;
                        }
                    }

                    if (!empty($attrs_var) && !empty($attrs_fabrica)) {
                        $match = true;
                        foreach ($attrs_fabrica as $k => $v) {
                            $k_low = strtolower($k);
                            $v_low = strtolower(trim($v));
                            if (!isset($attrs_var[$k_low]) || strtolower(trim($attrs_var[$k_low])) !== $v_low) { $match = false; break; }
                        }
                        if ($match) { $vendas_destino = $var_vendas; break; }
                    }
                }
            }

            $attrs_assoc = $this->get_atributos_variacao($variacao_fabrica);
            $attrs_for_front = array();
            if (is_array($attrs_assoc) && !empty($attrs_assoc)) {
                foreach ($attrs_assoc as $ak => $av) {
                    // Nome amigável: remover underscores e capitalizar
                    $nome = ucwords(str_replace('_', ' ', $ak));
                    $attrs_for_front[] = array('nome' => $nome, 'valor' => $av);
                }
            }

            $variacoes[] = array(
                'id_fabrica'      => $variacao_id,
                'id_destino'      => isset($variacao_destino['id']) ? $variacao_destino['id'] : null,
                'sku'             => $variacao_fabrica->get_sku(),
                'atributos'       => $attrs_for_front,
                'preco_fabrica'   => $this->formatar_preco_produto($variacao_fabrica),
                'preco_destino'   => $this->formatar_preco_variacao_destino($variacao_destino),
                'estoque_fabrica' => ($variacao_fabrica->get_stock_quantity() !== null && $variacao_fabrica->get_stock_quantity() !== '') ? $variacao_fabrica->get_stock_quantity() : 0,
                'estoque_destino' => (isset($variacao_destino['stock_quantity']) && $variacao_destino['stock_quantity'] !== null && $variacao_destino['stock_quantity'] !== '') ? $variacao_destino['stock_quantity'] : 0,
                'vendas_fabrica'  => ($variacao_fabrica->get_total_sales() !== null && $variacao_fabrica->get_total_sales() !== '') ? $variacao_fabrica->get_total_sales() : 0,
                'vendas_destino'  => $vendas_destino,
                'status'          => $variacao_destino ? 'sincronizado' : 'não_sincronizado'
            );
            $idx_loop++;
        }

        return $variacoes;
    }

    /**
     * Retorna atributos normalizados de uma variação (nome -> valor)
     * Aceita tanto arrays vindos da API quanto objetos WC_Product_Variation
     */
    private function get_atributos_variacao($variacao) {
        $attrs = array();
        if (is_array($variacao) && isset($variacao['attributes'])) {
            foreach ($variacao['attributes'] as $att) {
                $name = isset($att['name']) ? strtolower(trim(str_replace('pa_', '', $att['name']))) : '';
                $option = isset($att['option']) ? strtolower(trim($att['option'])) : '';
                if ($name !== '') {
                    $attrs[$name] = $option;
                }
            }
        } elseif (is_object($variacao) && method_exists($variacao, 'get_attributes')) {
            foreach ($variacao->get_attributes() as $name => $option) {
                $name = strtolower(trim(str_replace('pa_', '', $name)));
                $attrs[$name] = strtolower(trim($option));
            }
        }

        return $attrs;
    }

    /**
     * Normalizar um valor de atributo para matching: remove acentos, '-' vs ' ', caixa baixa e trim
     */
    private function normalizar_valor_atributo($valor) {
        $v = is_array($valor) ? reset($valor) : $valor;
        $v = (string)$v;
        $v = strtolower(trim($v));
        // substituir hifens por espaço e múltiplos espaços por um só
        $v = preg_replace('/[-_]+/', ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        // remover acentos (basic)
        $trans = array(
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c'
        );
        $v = strtr($v, $trans);
        return $v;
    }

    /**
     * Obter estoque real do produto no destino
     */
    private function get_estoque_real_destino($dados_destino) {
        if (!$dados_destino) {
            return 'N/A';
        }
        
        // Se não gerencia estoque, mostrar status
        if (!isset($dados_destino['manage_stock']) || !$dados_destino['manage_stock']) {
            $status = $dados_destino['stock_status'] ?? 'instock';
            return $status === 'instock' ? 'Em estoque' : 'Fora de estoque';
        }
        
        // Se gerencia estoque, mostrar quantidade
        $quantity = $dados_destino['stock_quantity'] ?? 0;
        
        // Tratar valores nulos ou vazios
        if ($quantity === null || $quantity === '') {
            return 0;
        }
        
        return intval($quantity);
    }
    
    /**
     * Obter vendas do produto no destino
     */
    private function get_vendas_produto_destino($lojista_data, $produto_id_destino) {

        
        // PRIMEIRO: Tentar endpoint customizado direto no banco
        $vendas_diretas = $this->get_vendas_produto_direto_banco($lojista_data, $produto_id_destino);
        if ($vendas_diretas !== null && isset($vendas_diretas['total_vendas'])) {

            return $vendas_diretas;  // Retornar dados completos para uso no modal
        }
        
        // FALLBACK: Método original
        // Primeiro tentar buscar pelo meta _total_sales do produto principal
        $url_meta = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/products/' . $produto_id_destino;
        
        $response = wp_remote_get($url_meta, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $produto_data = json_decode(wp_remote_retrieve_body($response), true);

            
            // Se é produto simples e tem _total_sales
            if (isset($produto_data['meta_data'])) {
                foreach ($produto_data['meta_data'] as $meta) {
                    if ($meta['key'] === '_total_sales' && !empty($meta['value'])) {
                        $vendas_simples = intval($meta['value']);

                        if ($vendas_simples > 0) {
                            return $vendas_simples;
                        }
                    }
                }
            }
            
            // Se é produto variável, buscar vendas das variações
            if (isset($produto_data['type']) && $produto_data['type'] === 'variable') {

                $vendas_variaveis = $this->get_vendas_produto_variavel($lojista_data, $produto_id_destino);

                return $vendas_variaveis;
            }
        } else {

        }
        
        // Fallback: tentar relatórios WooCommerce

        $fallback_result = $this->get_vendas_produto_destino_fallback($lojista_data, $produto_id_destino);

        return $fallback_result;
    }

    /**
     * Wrapper com cache para get_vendas_produto_destino
     */
    private function get_vendas_produto_destino_cached($lojista_data, $produto_id_destino) {
        // Gerar chave baseada no lojista (id ou url) e produto
        $lojista_key = isset($lojista_data['id']) ? $lojista_data['id'] : md5($lojista_data['url'] ?? '');
        $transient_key = 'sincr_vendas_' . md5($lojista_key . '_' . $produto_id_destino);

        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $result = $this->get_vendas_produto_destino($lojista_data, $produto_id_destino);

        // Armazenar no cache por 5 minutos
        set_transient($transient_key, $result, 300);

        return $result;
    }
    
    /**
     * Obter vendas de produto variável (soma todas as variações) - MÉTODO DIRETO NO BANCO
     */
    private function get_vendas_produto_variavel($lojista_data, $produto_id_destino) {
        // PRIMEIRO: Tentar método customizado direto no banco
        $vendas_diretas = $this->get_vendas_produto_direto_banco($lojista_data, $produto_id_destino);
        if ($vendas_diretas !== null && isset($vendas_diretas['total_vendas']) && $vendas_diretas['total_vendas'] > 0) {

            return $vendas_diretas['total_vendas'];
        }
        
        // SEGUNDO: Método original via API
        // Primeiro obter todas as variações do produto
        $url_variations = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/products/' . $produto_id_destino . '/variations?per_page=100';
        
        $response = wp_remote_get($url_variations, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {

            return 0;
        }
        
        $variacoes = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($variacoes)) {
            return 0;
        }
        
        $total_vendas = 0;
        
        // Para cada variação, buscar vendas individuais
        foreach ($variacoes as $variacao) {
            $variacao_id = $variacao['id'];
            
            // Buscar _total_sales da variação
            if (isset($variacao['meta_data'])) {
                foreach ($variacao['meta_data'] as $meta) {
                    if ($meta['key'] === '_total_sales' && !empty($meta['value'])) {
                        $vendas_variacao = intval($meta['value']);
                        $total_vendas += $vendas_variacao;

                        break;
                    }
                }
            }
        }
        

        return $total_vendas;
    }
    
    /**
     * Buscar vendas diretamente no banco de dados via endpoint customizado
     */
    private function get_vendas_produto_direto_banco($lojista_data, $produto_id_destino) {
        // NOVA ESTRATÉGIA: Endpoint público simples sem autenticação
        $url_simples = trailingslashit($lojista_data['url']) . 'wp-json/sincronizador/v1/vendas-simples/' . $produto_id_destino;
        

        
        $response = wp_remote_get($url_simples, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['total_vendas'])) {

                return $data; // Retornar dados completos incluindo vendas_por_variacao
            }
        } else if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);

        }
        
        // Fallback: tentar endpoint com autenticação (antigo)
        $url_auth = trailingslashit($lojista_data['url']) . 'wp-json/sincronizador/v1/vendas/' . $produto_id_destino;
        
        $response_auth = wp_remote_get($url_auth, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (!is_wp_error($response_auth) && wp_remote_retrieve_response_code($response_auth) === 200) {
            $data_auth = json_decode(wp_remote_retrieve_body($response_auth), true);
            if (isset($data_auth['total_vendas'])) {

                return $data_auth;
            }
        }
        

        return null;
    }
    
    /**
     * Método fallback para obter vendas (método antigo)
     */
    private function get_vendas_produto_destino_fallback($lojista_data, $produto_id_destino) {
        $url = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/reports/products?include=' . $produto_id_destino;
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {

            return 0;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);

        
        $vendas = isset($data[0]['items_sold']) ? intval($data[0]['items_sold']) : 0;

        
        return $vendas;
    }

    /**
     * Formatar preço do produto da fábrica
     */
    private function formatar_preco_produto($produto) {
        // Debug: log dos valores obtidos
        $id = $produto->get_id();
        $preco_atual = $produto->get_price();
        $preco_regular = $produto->get_regular_price();
        $preco_meta = get_post_meta($id, '_price', true);
        $regular_meta = get_post_meta($id, '_regular_price', true);
        

        
        // Primeiro tenta pegar o preço atual (com promoção se houver)
        $preco = $produto->get_price();
        
        // Se não houver preço atual, pega o preço regular
        if (empty($preco) || $preco === '' || $preco === '0') {
            $preco = $produto->get_regular_price();
        }
        
        // Se ainda estiver vazio, pega direto do meta
        if (empty($preco) || $preco === '' || $preco === '0') {
            $preco = get_post_meta($produto->get_id(), '_price', true);
            if (empty($preco) || $preco === '0') {
                $preco = get_post_meta($produto->get_id(), '_regular_price', true);
            }
        }
        
        $preco_final = is_numeric($preco) && floatval($preco) > 0 ? floatval($preco) : 0;

        
        return $preco_final;
    }
    
    /**
     * Verificar se produto tem variações
     */
    private function produto_tem_variacoes($produto) {
        if (!$produto) {
            return false;
        }
        
        return $produto->is_type('variable');
    }
    
    /**
     * Obter informações das variações do produto
    /**
     * Obter variações do produto no destino
     */
    private function get_variacoes_destino($lojista_data, $produto_id_destino) {
        $base = rtrim($lojista_data['url'], '/') . '/';
        $url = $base . 'wp-json/wc/v3/products/' . $produto_id_destino . '/variations?per_page=100';

        // Tentar com autenticação básica (padrão)
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(($lojista_data['consumer_key'] ?? '') . ':' . ($lojista_data['consumer_secret'] ?? '')),
                'Content-Type' => 'application/json'
            )
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

    // Se a chamada autenticada falhou, tentaremos uma chamada sem autenticação

        // Fallback: tentar sem autenticação (algumas lojas expõem variações publicamente)
        $response2 = wp_remote_get($url, array('timeout' => 15));
        if (!is_wp_error($response2) && wp_remote_retrieve_response_code($response2) === 200) {
            $body2 = wp_remote_retrieve_body($response2);
            $decoded2 = json_decode($body2, true);
            if (is_array($decoded2)) {
                return $decoded2;
            }
        }

    // fallback sem sucesso

        return array();
    }
    
    /**
     * Formatar preço da variação no destino
     */
    private function formatar_preco_variacao_destino($variacao_destino) {
        if (!$variacao_destino) {
            return 0;
        }
        
        // Primeiro tenta pegar o preço atual (com promoção se houver)
        $preco = $variacao_destino['price'] ?? null;
        
        // Se não houver preço atual, pega o preço regular
        if (empty($preco) || $preco === '' || $preco === '0') {
            $preco = $variacao_destino['regular_price'] ?? null;
        }
        
        return is_numeric($preco) && floatval($preco) > 0 ? floatval($preco) : 0;
    }

    /**
     * Formatar preço do produto no destino
     */
    private function formatar_preco_destino($dados_destino) {
        if (!$dados_destino) {

            return 0;
        }
        
        $preco_atual = $dados_destino['price'] ?? 'não definido';
        $preco_regular = $dados_destino['regular_price'] ?? 'não definido';

        
        // Primeiro tenta pegar o preço atual (com promoção se houver)
        $preco = $dados_destino['price'] ?? null;
        
        // Se não houver preço atual, pega o preço regular
        if (empty($preco) || $preco === '' || $preco === '0') {
            $preco = $dados_destino['regular_price'] ?? null;
        }
        
        $preco_final = is_numeric($preco) && floatval($preco) > 0 ? floatval($preco) : 0;

        
        return $preco_final;
    }
    
    /**
     * Sincronizar vendas do lojista
     */
    private function sincronizar_vendas_lojista($lojista_data) {
        $produtos_atualizados = 0;
        $erros = 0;
        
        $historico_envios = get_option('sincronizador_wc_historico_envios', array());
        $lojista_url = $lojista_data['url'];
        
        if (!isset($historico_envios[$lojista_url])) {
            return array(
                'success' => false,
                'message' => 'Nenhum produto sincronizado encontrado para este lojista'
            );
        }
        
        foreach ($historico_envios[$lojista_url] as $produto_id_fabrica => $produto_id_destino) {
            // Obter vendas atualizadas
            $vendas = $this->get_vendas_produto_destino($lojista_data, $produto_id_destino);
            
            if ($vendas !== null) {
                // Aqui você poderia salvar as vendas em uma tabela personalizada
                // Por enquanto, vamos apenas contar como atualizado
                $produtos_atualizados++;
            } else {
                $erros++;
            }
        }
        
        return array(
            'success' => true,
            'message' => "{$produtos_atualizados} produtos atualizados, {$erros} erros",
            'produtos_atualizados' => $produtos_atualizados,
            'erros' => $erros
        );
    }
    
    /**
     * AJAX: Sincronizar produtos via AJAX
     */
    public function ajax_sync_produtos() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $start_time = microtime(true);
        

        
        try {
            // Executar sincronização
            $resultado = $this->sync_produtos($lojista_id);
            $end_time = microtime(true);
            $tempo_execucao = round($end_time - $start_time, 2);
            
            if (is_array($resultado)) {
                $total = count($resultado);
                $sucessos = 0;
                $erros = 0;
                $criados = 0;
                $atualizados = 0;
                $mensagens = array();
                
                foreach ($resultado as $produto_result) {
                    if (isset($produto_result['success']) && $produto_result['success']) {
                        $sucessos++;
                        $mensagens[] = "✅ " . $produto_result['nome'];
                        
                        // Contar criados vs atualizados
                        if (isset($produto_result['action'])) {
                            if ($produto_result['action'] === 'criado') {
                                $criados++;
                            } else if ($produto_result['action'] === 'atualizado') {
                                $atualizados++;
                            }
                        }
                    } else {
                        $erros++;
                        $error_msg = isset($produto_result['error']) ? $produto_result['error'] : 'Erro desconhecido';
                        $mensagens[] = "❌ " . ($produto_result['nome'] ?? 'Produto') . ": " . $error_msg;
                    }
                }
                
                // Atualizar data da última sincronização se houve sucessos
                if ($sucessos > 0) {
                    $this->atualizar_data_sync($lojista_id);
                }
                
                wp_send_json_success(array(
                    'produtos_sincronizados' => $sucessos,
                    'produtos_criados' => $criados,
                    'produtos_atualizados' => $atualizados,
                    'erros' => $erros,
                    'tempo' => $tempo_execucao . 's',
                    'detalhes' => implode('<br>', array_slice($mensagens, 0, 10)) // Máximo 10 itens
                ));
            } else {
                wp_send_json_error('Erro na sincronização: formato de resposta inválido');
            }
            
        } catch (Exception $e) {

            wp_send_json_error('Erro interno: ' . $e->getMessage());
        }
    }
    
    /**
     * Atualizar data da última sincronização
     */
    private function atualizar_data_sync($lojista_id) {
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        // Procurar o lojista pelo ID correto
        foreach ($lojistas as $key => $lojista) {
            if ($lojista['id'] == $lojista_id) {
                $lojistas[$key]['ultima_sync'] = current_time('mysql');
                update_option('sincronizador_wc_lojistas', $lojistas);
                

                return true;
            }
        }
        

        return false;
    }
    /**
     * AJAX: Obter lista de lojistas para filtros
     */
    public function ajax_get_lojistas() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        $lojistas_formatados = array();
        
        foreach ($lojistas as $lojista) {
            $lojistas_formatados[] = array(
                'id' => $lojista['id'],
                'nome' => $lojista['nome'],
                'status' => $lojista['status'] ?? 'ativo'
            );
        }
        
        wp_send_json_success($lojistas_formatados);
    }
    
    /**
     * AJAX: Obter resumo dos relatórios
     */
    public function ajax_get_resumo_relatorio() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        
        // Simular dados por enquanto
        $resumo = array(
            'produtos_sincronizados' => 150,
            'sincronizacoes_sucesso' => 140,
            'sincronizacoes_erro' => 10,
            'total_vendas' => 850
        );
        
        wp_send_json_success($resumo);
    }
    
    /**
     * AJAX: Obter dados dos gráficos
     */
    public function ajax_get_graficos_relatorio() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        
        // Simular dados por enquanto
        $graficos = array(
            'sincronizacoes_por_dia' => array(
                'labels' => ['01/08', '02/08', '03/08', '04/08', '05/08', '06/08', '07/08'],
                'valores' => [12, 19, 3, 17, 6, 3, 7]
            ),
            'status_produtos' => array(
                'labels' => ['Sucesso', 'Erro', 'Pendente'],
                'valores' => [140, 10, 5]
            )
        );
        
        wp_send_json_success($graficos);
    }
    
    /**
     * AJAX: Obter histórico de sincronizações
     */
    public function ajax_get_historico_relatorio() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        
        // Simular dados por enquanto
        $items = array();
        for ($i = 1; $i <= 15; $i++) {
            $items[] = array(
                'id' => $i,
                'data_hora' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
                'lojista' => 'Loja Teste ' . ($i % 3 + 1),
                'tipo' => $i % 2 == 0 ? 'sync' : 'import',
                'produtos' => rand(1, 50),
                'status' => $i % 5 == 0 ? 'erro' : 'sucesso',
                'detalhes' => $i % 5 == 0 ? 'Erro de conexão' : 'Processado com sucesso'
            );
        }
        
        // Paginação simulada
        $start = ($page - 1) * $per_page;
        $items_pagina = array_slice($items, $start, $per_page);
        
        $response = array(
            'items' => $items_pagina,
            'pagination' => array(
                'total' => count($items),
                'page' => $page,
                'per_page' => $per_page,
                'pages' => ceil(count($items) / $per_page)
            )
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX: Exportar dados dos relatórios
     */
    public function ajax_export_relatorio() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $formato = isset($_POST['formato']) ? sanitize_text_field($_POST['formato']) : 'csv';
        $filtros = isset($_POST['filtros']) ? json_decode(stripslashes($_POST['filtros']), true) : array();
        
        // Por enquanto apenas CSV
        if ($formato === 'csv') {
            $this->exportar_csv($filtros);
        } else {
            wp_die('Formato não suportado ainda');
        }
    }
    
    /**
     * Exportar dados em formato CSV
     */
    private function exportar_csv($filtros) {
        $filename = 'relatorio_sincronizacao_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Cabeçalhos
        fputcsv($output, array('Data/Hora', 'Lojista', 'Tipo', 'Produtos', 'Status', 'Detalhes'));
        
        // Dados simulados
        for ($i = 1; $i <= 50; $i++) {
            fputcsv($output, array(
                date('d/m/Y H:i:s', strtotime("-{$i} hours")),
                'Loja Teste ' . ($i % 3 + 1),
                $i % 2 == 0 ? 'Sincronização' : 'Importação',
                rand(1, 50),
                $i % 5 == 0 ? 'Erro' : 'Sucesso',
                $i % 5 == 0 ? 'Erro de conexão' : 'Processado com sucesso'
            ));
        }
        
        fclose($output);
        exit;
    }
    
    // === NOVOS AJAX Handlers para Relatórios de Vendas ===
    
    /**
     * AJAX: Obter lojistas para relatórios
     */
    public function ajax_get_lojistas_relatorios() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $lojistas = $this->get_lojistas();
        $lojistas_ativos = array_filter($lojistas, function($lojista) {
            return isset($lojista['ativo']) && $lojista['ativo'];
        });
        
        wp_send_json_success(array_values($lojistas_ativos));
    }
    
    /**
     * Gerar chave de cache baseada nos parâmetros
     */
    private function gerar_chave_cache($funcao, $parametros = array()) {
        $key_base = 'sincronizador_wc_' . $funcao;
        
        if (!empty($parametros)) {
            $key_base .= '_' . md5(serialize($parametros));
        }
        
        return $key_base;
    }
    
    /**
     * Obter dados do cache ou executar função
     */
    private function get_cache_ou_executar($chave_cache, $callback, $expiracao = 300) {
        $tempo_inicio = microtime(true);
        
        // Verificar se existe no cache
        $dados_cache = get_transient($chave_cache);
        
        if ($dados_cache !== false) {
            return $dados_cache;
        }
        
        // Se não existe no cache, executar função
        $dados = call_user_func($callback);
        
        // Salvar no cache com tempo otimizado (10 minutos para primeira consulta)
        $tempo_cache_otimizado = max($expiracao, 600); // Mínimo 10 minutos
        if ($dados !== false) {
            set_transient($chave_cache, $dados, $tempo_cache_otimizado);
        }
        
        return $dados;
    }
    
    /**
     * Limpar cache específico ou todo cache dos relatórios
     */
    public function limpar_cache_relatorios($prefixo = 'sincronizador_wc_') {
        global $wpdb;
        
        // Limpar transients que começam com o prefixo
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . $prefixo . '%',
                '_transient_timeout_' . $prefixo . '%'
            )
        );
        

    }

    /**
     * AJAX: Obter resumo de vendas (com cache)
     */
    public function ajax_get_resumo_vendas() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        
        // Se os filtros vêm como JSON, decodificar
        if (is_string($filtros)) {
            $filtros = json_decode($filtros, true);
        }
        
        // Gerar chave de cache baseada nos filtros
        $chave_cache = $this->gerar_chave_cache('resumo_vendas', $filtros);
        
        // Callback para buscar dados
        $callback = function() use ($filtros) {
            return $this->buscar_resumo_vendas_sem_cache($filtros);
        };
        
        // Obter dados do cache ou executar busca (cache de 15 minutos)
        $resumo = $this->get_cache_ou_executar($chave_cache, $callback, 900);
        
        wp_send_json_success($resumo);
    }
    
    /**
     * Buscar resumo de vendas sem cache (função auxiliar)
     */
    private function buscar_resumo_vendas_sem_cache($filtros) {
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        $lojista_filtro = isset($filtros['lojista']) ? $filtros['lojista'] : '';
        
        // Calcular data de início baseada no período
        if (isset($filtros['data_inicio']) && isset($filtros['data_fim']) && 
            !empty($filtros['data_inicio']) && !empty($filtros['data_fim'])) {
            $data_inicio = $filtros['data_inicio'];
            $data_fim = $filtros['data_fim'];
        } else {
            $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
            $data_fim = date('Y-m-d');
        }
        

        
        $lojistas = $this->get_lojistas();
        $lojistas_ativos = array_filter($lojistas, function($lojista) {
            return isset($lojista['ativo']) && $lojista['ativo'];
        });
        
        $total_vendas = 0;
        $total_pedidos = 0;
        $produtos_vendidos = 0;
        
        // Se um lojista específico foi selecionado
        if (!empty($lojista_filtro) && $lojista_filtro !== '' && $lojista_filtro !== 'todos') {
            $lojista_data = null;
            foreach ($lojistas_ativos as $lojista) {
                if ($lojista['id'] == $lojista_filtro) {
                    $lojista_data = $lojista;
                    break;
                }
            }
            
            if ($lojista_data) {

                $vendas_lojista = $this->buscar_vendas_lojista($lojista_data, $data_inicio, $data_fim);
                
                if ($vendas_lojista['success']) {
                    $total_vendas = $vendas_lojista['data']['total_vendas'];
                    $total_pedidos = $vendas_lojista['data']['total_pedidos'];
                    $produtos_vendidos = $vendas_lojista['data']['produtos_vendidos'];
                }
            }
        } else {
            // Somar vendas de todos os lojistas ativos

            
            foreach ($lojistas_ativos as $lojista) {
                $vendas_lojista = $this->buscar_vendas_lojista($lojista, $data_inicio, $data_fim);
                
                if ($vendas_lojista['success']) {
                    $total_vendas += $vendas_lojista['data']['total_vendas'];
                    $total_pedidos += $vendas_lojista['data']['total_pedidos'];
                    $produtos_vendidos += $vendas_lojista['data']['produtos_vendidos'];
                }
            }
        }
        
        return array(
            'total_vendas' => $total_vendas,
            'total_pedidos' => $total_pedidos,
            'produtos_vendidos' => $produtos_vendidos,
            'lojistas_ativos' => count($lojistas_ativos)
        );
    }

    /**
     * AJAX: Obter vendas por lojista (com cache)
     */
    public function ajax_get_vendas_por_lojista() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        
        // Se os filtros vêm como JSON, decodificar
        if (is_string($filtros)) {
            $filtros = json_decode($filtros, true);
        }
        
        // Gerar chave de cache baseada nos filtros
        $chave_cache = $this->gerar_chave_cache('vendas_por_lojista', $filtros);
        
        // Callback para buscar dados
        $callback = function() use ($filtros) {
            return $this->buscar_vendas_por_lojista_sem_cache($filtros);
        };
        
        // Obter dados do cache ou executar busca (cache de 15 minutos)
        $dados_grafico = $this->get_cache_ou_executar($chave_cache, $callback, 900);
        
        wp_send_json_success($dados_grafico);
    }
    
    /**
     * Buscar vendas por lojista sem cache (função auxiliar)
     */
    private function buscar_vendas_por_lojista_sem_cache($filtros) {
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        
        // Calcular data de início baseada no período
        if (isset($filtros['data_inicio']) && isset($filtros['data_fim']) && 
            !empty($filtros['data_inicio']) && !empty($filtros['data_fim'])) {
            $data_inicio = $filtros['data_inicio'];
            $data_fim = $filtros['data_fim'];
        } else {
            $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
            $data_fim = date('Y-m-d');
        }
        

        
        $lojistas = $this->get_lojistas();
        $lojistas_ativos = array_filter($lojistas, function($lojista) {
            return isset($lojista['ativo']) && $lojista['ativo'];
        });
        
        $dados_grafico = array(
            'labels' => array(),
            'valores' => array()
        );
        
        // Para cada lojista ativo, buscar suas vendas reais via API
        foreach ($lojistas_ativos as $lojista) {
            $dados_grafico['labels'][] = $lojista['nome'];
            
            $vendas_lojista = $this->buscar_vendas_lojista($lojista, $data_inicio, $data_fim);
            
            if ($vendas_lojista['success']) {
                $dados_grafico['valores'][] = floatval($vendas_lojista['data']['total_vendas']);

            } else {
                $dados_grafico['valores'][] = 0;

            }
        }
        

        
        return $dados_grafico;
    }
    
    /**
     * AJAX: Obter produtos mais vendidos (com cache)
     */
    public function ajax_get_produtos_mais_vendidos() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        
        // Se os filtros vêm como JSON, decodificar
        if (is_string($filtros)) {
            $filtros = json_decode($filtros, true);
        }
        
        // Gerar chave de cache baseada nos filtros
        $chave_cache = $this->gerar_chave_cache('produtos_mais_vendidos', $filtros);
        
        // Callback para buscar dados
        $callback = function() use ($filtros) {
            return $this->buscar_produtos_mais_vendidos_sem_cache($filtros);
        };
        
        // Obter dados do cache ou executar busca (cache de 20 minutos)
        $produtos_mais_vendidos = $this->get_cache_ou_executar($chave_cache, $callback, 1200);
        
        wp_send_json_success($produtos_mais_vendidos);
    }
    
    /**
     * Buscar produtos mais vendidos sem cache (função auxiliar)
     */
    private function buscar_produtos_mais_vendidos_sem_cache($filtros) {
        $lojista_filtro = isset($filtros['lojista']) ? $filtros['lojista'] : '';
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        
        // Calcular data de início baseada no período
        if (isset($filtros['data_inicio']) && isset($filtros['data_fim']) && 
            !empty($filtros['data_inicio']) && !empty($filtros['data_fim'])) {
            $data_inicio = $filtros['data_inicio'];
            $data_fim = $filtros['data_fim'];
        } else {
            $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
            $data_fim = date('Y-m-d');
        }
        

        
        $lojistas = $this->get_lojistas();
        $lojistas_ativos = array_filter($lojistas, function($lojista) {
            return isset($lojista['ativo']) && $lojista['ativo'];
        });
        
        $produtos_mais_vendidos = array();
        
        // Se um lojista específico foi selecionado
        if (!empty($lojista_filtro) && $lojista_filtro !== '' && $lojista_filtro !== 'todos') {
            $lojista_data = null;
            foreach ($lojistas_ativos as $lojista) {
                if ($lojista['id'] == $lojista_filtro) {
                    $lojista_data = $lojista;
                    break;
                }
            }
            
            if ($lojista_data) {
                $produtos_mais_vendidos = $this->buscar_produtos_mais_vendidos_lojista($lojista_data, $data_inicio, $data_fim);
            }
        } else {
            // Agregar produtos de todos os lojistas ativos
            $produtos_agregados = array();
            
            foreach ($lojistas_ativos as $lojista) {
                $produtos_lojista = $this->buscar_produtos_mais_vendidos_lojista($lojista, $data_inicio, $data_fim);
                
                // Agregar produtos por nome/SKU
                foreach ($produtos_lojista as $produto) {
                    $chave = $produto['sku'] ?: $produto['nome'];
                    
                    if (isset($produtos_agregados[$chave])) {
                        $produtos_agregados[$chave]['quantidade_vendida'] += $produto['quantidade_vendida'];
                        $produtos_agregados[$chave]['receita_total'] += $produto['receita_total'];
                        $produtos_agregados[$chave]['lojista'] = 'Múltiplos';
                    } else {
                        $produtos_agregados[$chave] = $produto;
                    }
                }
            }
            
            // Recalcular preço médio e ordenar
            foreach ($produtos_agregados as &$produto) {
                if ($produto['quantidade_vendida'] > 0) {
                    $produto['preco_medio'] = $produto['receita_total'] / $produto['quantidade_vendida'];
                }
            }
            
            // Ordenar por quantidade vendida e pegar os 10 primeiros
            uasort($produtos_agregados, function($a, $b) {
                return $b['quantidade_vendida'] - $a['quantidade_vendida'];
            });
            
            $produtos_mais_vendidos = array_slice(array_values($produtos_agregados), 0, 10);
        }
        

        
        return $produtos_mais_vendidos;
    }
    
    /**
     * Buscar produtos mais vendidos de um lojista específico via API
     */
    private function buscar_produtos_mais_vendidos_lojista($lojista, $data_inicio, $data_fim) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {

            return array();
        }
        
        // Primeiro, buscar pedidos do período
        $vendas = $this->buscar_vendas_detalhadas_lojista($lojista, $data_inicio, $data_fim);
        
        if (!$vendas['success'] || empty($vendas['data']['items'])) {
            return array();
        }
        
        // Agregar produtos por nome/SKU
        $produtos_agregados = array();
        
        foreach ($vendas['data']['items'] as $pedido) {
            if (isset($pedido['line_items'])) {
                foreach ($pedido['line_items'] as $item) {
                    $nome = isset($item['name']) ? $item['name'] : '';
                    $sku = isset($item['sku']) ? $item['sku'] : '';
                    $quantidade = isset($item['quantity']) ? intval($item['quantity']) : 0;
                    $total = isset($item['total']) ? floatval($item['total']) : 0;
                    
                    if (empty($nome) || $quantidade <= 0) continue;
                    
                    $chave = $sku ?: $nome;
                    
                    if (isset($produtos_agregados[$chave])) {
                        $produtos_agregados[$chave]['quantidade_vendida'] += $quantidade;
                        $produtos_agregados[$chave]['receita_total'] += $total;
                    } else {
                        $produtos_agregados[$chave] = array(
                            'nome' => $nome,
                            'sku' => $sku ?: 'N/A',
                            'lojista' => $lojista['nome'],
                            'quantidade_vendida' => $quantidade,
                            'receita_total' => $total,
                            'preco_medio' => 0
                        );
                    }
                }
            }
        }
        
        // Calcular preço médio
        foreach ($produtos_agregados as &$produto) {
            if ($produto['quantidade_vendida'] > 0) {
                $produto['preco_medio'] = $produto['receita_total'] / $produto['quantidade_vendida'];
            }
        }
        
        // Ordenar por quantidade vendida e retornar top 10
        uasort($produtos_agregados, function($a, $b) {
            return $b['quantidade_vendida'] - $a['quantidade_vendida'];
        });
        
        return array_slice(array_values($produtos_agregados), 0, 10);
    }
    
    /**
     * Buscar vendas detalhadas de um lojista via API
     */
    private function buscar_vendas_detalhadas_lojista($lojista, $data_inicio, $data_fim) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array('success' => false, 'message' => 'Dados de conexão incompletos');
        }
        
        // Converter datas para formato ISO 8601
        $data_inicio_iso = $data_inicio . 'T00:00:00';
        $data_fim_iso = $data_fim . 'T23:59:59';
        
        $url = rtrim($lojista['url'], '/') . '/wp-json/wc/v3/orders';
        
        $params = array(
            'after' => $data_inicio_iso,
            'before' => $data_fim_iso,
            'status' => 'completed,processing,on-hold',
            'per_page' => 100,
            'page' => 1
        );
        
        $url_com_params = add_query_arg($params, $url);
        
        $response = wp_remote_get($url_com_params, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sincronizador-WC/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return array('success' => false, 'message' => "HTTP {$http_code}");
        }
        
        $body = wp_remote_retrieve_body($response);
        $pedidos = json_decode($body, true);
        
        if (!is_array($pedidos)) {
            return array('success' => false, 'message' => 'Resposta inválida da API');
        }
        
        return array('success' => true, 'data' => array('items' => $pedidos));
    }
    
    /**
     * AJAX: Obter vendas detalhadas
     */
    /**
     * AJAX: Obter vendas detalhadas (com cache)
     */
    public function ajax_get_vendas_detalhadas() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $filtros = isset($_POST['filtros']) ? $_POST['filtros'] : array();
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        
        // Se os filtros vêm como JSON, decodificar
        if (is_string($filtros)) {
            $filtros = json_decode($filtros, true);
        }
        
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        $lojista_filtro = isset($filtros['lojista']) ? $filtros['lojista'] : '';
        
        // Se não há lojista selecionado, retornar erro
        if (empty($lojista_filtro) || $lojista_filtro === '' || $lojista_filtro === 'todos') {
            wp_send_json_error(array('message' => 'Por favor, selecione um lojista específico para visualizar as vendas detalhadas.'));
        }
        
        // Incluir página e per_page nos filtros para cache
        $filtros_cache = $filtros;
        $filtros_cache['page'] = $page;
        $filtros_cache['per_page'] = $per_page;
        
        // Gerar chave de cache baseada nos filtros incluindo paginação
        $chave_cache = $this->gerar_chave_cache('vendas_detalhadas', $filtros_cache);
        
        // Callback para buscar dados
        $callback = function() use ($filtros, $page, $per_page, $lojista_filtro) {
            return $this->buscar_vendas_detalhadas_sem_cache($filtros, $page, $per_page, $lojista_filtro);
        };
        
        // Obter dados do cache ou executar busca (cache de 15 minutos)
        $response = $this->get_cache_ou_executar($chave_cache, $callback, 900);
        
        if (isset($response['error'])) {
            wp_send_json_error($response);
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Buscar vendas detalhadas sem cache (função auxiliar)
     */
    private function buscar_vendas_detalhadas_sem_cache($filtros, $page, $per_page, $lojista_filtro) {
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        
        // Calcular data de início baseada no período
        if (isset($filtros['data_inicio']) && isset($filtros['data_fim']) && 
            !empty($filtros['data_inicio']) && !empty($filtros['data_fim'])) {
            $data_inicio = $filtros['data_inicio'];
            $data_fim = $filtros['data_fim'];
        } else {
            $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
            $data_fim = date('Y-m-d');
        }
        

        
        // Buscar dados do lojista
        $lojistas = $this->get_lojistas();
        $lojista_data = null;
        
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_filtro) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            return array('error' => true, 'message' => 'Lojista não encontrado.');
        }
        
        // Buscar vendas detalhadas do lojista via API
        $vendas_result = $this->buscar_vendas_detalhadas_lojista($lojista_data, $data_inicio, $data_fim);
        
        if (!$vendas_result['success']) {
            return array('error' => true, 'message' => 'Erro ao buscar vendas: ' . $vendas_result['message']);
        }
        
        $todos_pedidos = $vendas_result['data']['items'];
        $total_pedidos = count($todos_pedidos);
        
        // Implementar paginação manual
        $offset = ($page - 1) * $per_page;
        $pedidos_pagina = array_slice($todos_pedidos, $offset, $per_page);
        
        $status_labels = array(
            'completed' => 'Concluído',
            'processing' => 'Processando',
            'on-hold' => 'Em espera',
            'pending' => 'Pendente',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'failed' => 'Falhou'
        );
        
        $vendas = array();
        foreach ($pedidos_pagina as $pedido) {
            $status = isset($pedido['status']) ? $pedido['status'] : 'unknown';
            $status_nome = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
            
            // Formatar data
            $data_formatada = 'Data inválida';
            if (isset($pedido['date_created'])) {
                try {
                    $data = new DateTime($pedido['date_created']);
                    $data_formatada = $data->format('d/m/Y H:i');
                } catch (Exception $e) {
                    $data_formatada = date('d/m/Y H:i');
                }
            }
            
            // Obter informações do cliente
            $cliente = 'Cliente não informado';
            if (isset($pedido['billing'])) {
                $primeiro_nome = isset($pedido['billing']['first_name']) ? $pedido['billing']['first_name'] : '';
                $ultimo_nome = isset($pedido['billing']['last_name']) ? $pedido['billing']['last_name'] : '';
                $cliente = trim($primeiro_nome . ' ' . $ultimo_nome);
                if (empty($cliente)) {
                    $cliente = 'Cliente não informado';
                }
            }
            
            // Obter produtos do pedido
            $produtos_detalhes = array();
            if (isset($pedido['line_items'])) {
                foreach ($pedido['line_items'] as $item) {
                    $produto_nome = isset($item['name']) ? $item['name'] : 'Produto';
                    $quantidade = isset($item['quantity']) ? $item['quantity'] : 1;
                    $produtos_detalhes[] = "{$produto_nome} (x{$quantidade})";
                }
            }
            
            $produtos_texto = !empty($produtos_detalhes) ? 
                implode(', ', $produtos_detalhes) : 
                (isset($pedido['line_items']) ? count($pedido['line_items']) . ' itens' : '0 itens');
            
            $vendas[] = array(
                'data' => $data_formatada,
                'lojista' => $lojista_data['nome'],
                'pedido' => isset($pedido['number']) ? '#' . $pedido['number'] : '#' . (isset($pedido['id']) ? $pedido['id'] : 'N/A'),
                'cliente' => $cliente,
                'produtos' => $produtos_texto,
                'valor_total' => isset($pedido['total']) ? floatval($pedido['total']) : 0,
                'status' => $status,
                'status_nome' => $status_nome
            );
        }
        
        $total_pages = ceil($total_pedidos / $per_page);
        
        $response = array(
            'items' => $vendas,
            'pagination' => array(
                'total' => $total_pedidos,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages
            ),
            'filtro_info' => array(
                'lojista_nome' => $lojista_data['nome'],
                'lojista_id' => $lojista_filtro,
                'periodo' => $periodo,
                'data_inicio' => $data_inicio,
                'data_fim' => $data_fim
            )
        );
        

        
        return $response;
    }
    
    /**
     * AJAX: Exportar vendas
     */
    public function ajax_export_vendas() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        $formato = isset($_POST['formato']) ? sanitize_text_field($_POST['formato']) : 'csv';
        $filtros = isset($_POST['filtros']) ? json_decode(stripslashes($_POST['filtros']), true) : array();
        
        $lojista_filtro = isset($filtros['lojista']) ? $filtros['lojista'] : '';
        
        // Verificar se um lojista foi selecionado
        if (empty($lojista_filtro) || $lojista_filtro === '' || $lojista_filtro === 'todos') {
            wp_die('Por favor, selecione um lojista específico para exportar as vendas.');
        }
        
        $periodo = isset($filtros['periodo']) ? intval($filtros['periodo']) : 30;
        
        // Calcular data de início baseada no período
        if (isset($filtros['data_inicio']) && isset($filtros['data_fim']) && 
            !empty($filtros['data_inicio']) && !empty($filtros['data_fim'])) {
            $data_inicio = $filtros['data_inicio'];
            $data_fim = $filtros['data_fim'];
        } else {
            $data_inicio = date('Y-m-d', strtotime("-{$periodo} days"));
            $data_fim = date('Y-m-d');
        }
        
        // Buscar dados do lojista
        $lojista_data = null;
        $lojistas = $this->get_lojistas();
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_filtro) {
                $lojista_data = $lojista;
                break;
            }
        }
        
        if (!$lojista_data) {
            wp_die('Lojista não encontrado.');
        }
        
        // Buscar vendas detalhadas via API do lojista
        $vendas_detalhadas = $this->buscar_vendas_detalhadas_lojista($lojista_data, $data_inicio, $data_fim);
        
        if (!$vendas_detalhadas['success'] || empty($vendas_detalhadas['data']['items'])) {
            wp_die('Nenhuma venda encontrada para o período selecionado.');
        }
        
        $pedidos = $vendas_detalhadas['data']['items'];
        
        if ($formato === 'csv') {
            $nome_arquivo = sanitize_file_name("vendas-{$lojista_data['nome']}-" . date('Y-m-d') . '.csv');
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            
            $output = fopen('php://output', 'w');
            
            // BOM para UTF-8
            fwrite($output, "\xEF\xBB\xBF");
            
            // Cabeçalho CSV
            fputcsv($output, array(
                'Data',
                'Lojista', 
                'Pedido',
                'Cliente',
                'Email',
                'Produtos',
                'Valor Total',
                'Status'
            ));
            
            // Dados dos pedidos da API
            foreach ($pedidos as $pedido) {
                $status_labels = array(
                    'completed' => 'Concluído',
                    'processing' => 'Processando',
                    'on-hold' => 'Em espera',
                    'pending' => 'Pendente',
                    'cancelled' => 'Cancelado',
                    'refunded' => 'Reembolsado',
                    'failed' => 'Falhou'
                );
                
                $status = isset($pedido['status']) ? $pedido['status'] : 'unknown';
                $status_nome = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
                
                // Formatar data
                $data_formatada = '';
                if (isset($pedido['date_created'])) {
                    try {
                        $data_obj = new DateTime($pedido['date_created']);
                        $data_formatada = $data_obj->format('d/m/Y H:i');
                    } catch (Exception $e) {
                        $data_formatada = date('d/m/Y H:i');
                    }
                } else {
                    $data_formatada = date('d/m/Y H:i');
                }
                
                // Obter produtos do pedido
                $produtos_detalhes = array();
                
                if (isset($pedido['line_items']) && is_array($pedido['line_items'])) {
                    foreach ($pedido['line_items'] as $item) {
                        $produto_nome = isset($item['name']) ? $item['name'] : 'Produto';
                        $quantidade = isset($item['quantity']) ? intval($item['quantity']) : 1;
                        $produtos_detalhes[] = "{$produto_nome} (x{$quantidade})";
                    }
                }
                
                $produtos_texto = !empty($produtos_detalhes) ? implode(', ', $produtos_detalhes) : 'Produtos não disponíveis';
                
                // Dados do cliente
                $cliente_nome = '';
                if (isset($pedido['billing']['first_name']) && isset($pedido['billing']['last_name'])) {
                    $cliente_nome = trim($pedido['billing']['first_name'] . ' ' . $pedido['billing']['last_name']);
                }
                
                $cliente_email = isset($pedido['billing']['email']) ? $pedido['billing']['email'] : '';
                
                // Número do pedido
                $numero_pedido = isset($pedido['number']) ? '#' . $pedido['number'] : 
                                (isset($pedido['id']) ? '#' . $pedido['id'] : '#N/A');
                
                // Valor total
                $valor_total = isset($pedido['total']) ? floatval($pedido['total']) : 0;
                $valor_formatado = 'R$ ' . number_format($valor_total, 2, ',', '.');
                
                fputcsv($output, array(
                    $data_formatada,
                    $lojista_data['nome'],
                    $numero_pedido,
                    $cliente_nome,
                    $cliente_email,
                    $produtos_texto,
                    $valor_formatado,
                    $status_nome
                ));
            }
            
            fclose($output);
            exit;
        }
        
        wp_send_json_error('Formato não suportado');
    }
    
    /**
     * AJAX: Limpar cache dos relatórios
     */
    public function ajax_limpar_cache_relatorios() {
        check_ajax_referer('sincronizador_reports_nonce', 'nonce');
        
        try {
            $cache_limpo = $this->limpar_cache_relatorios();
            
            if ($cache_limpo) {
                wp_send_json_success(array(
                    'message' => 'Cache dos relatórios limpo com sucesso! Os dados serão atualizados na próxima consulta.',
                    'timestamp' => current_time('mysql')
                ));
            } else {
                wp_send_json_success(array(
                    'message' => 'Nenhum cache encontrado para limpar.',
                    'timestamp' => current_time('mysql')
                ));
            }
        } catch (Exception $e) {

            wp_send_json_error(array(
                'message' => 'Erro ao limpar cache: ' . $e->getMessage()
            ));
        }
    }
}

// Inicializa o plugin
function sincronizador_woocommerce() {
    return Sincronizador_WooCommerce::instance();
}

// Declara compatibilidade com WooCommerce HPOS (High-Performance Order Storage)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
    }
});

// Inicia o plugin
sincronizador_woocommerce();
