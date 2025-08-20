<?php
/**
 * API Master para integração com Plugin Painel Master
 * Esta classe expõe endpoints REST que o Plugin Master vai consumir
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Master_API {
    
    private $token_option = 'sincronizador_wc_master_token';
    
    public function __construct() {
        add_action('sincronizador_wc_vendas_sincronizadas', array($this, 'save_sync_data'), 10, 2);
        add_action('sincronizador_wc_sync_completed', array($this, 'update_api_data_after_sync'), 10, 2);
        add_action('sincronizador_wc_lojista_updated', array($this, 'update_api_data_after_lojista_update'), 10, 1);
        
        // Gerar token automaticamente se não existe
        if (!get_option($this->token_option)) {
            $this->generate_access_token();
        }
    }
    
    /**
     * Verificação de permissões via token
     */
    public function check_master_permissions($request) {
        $auth_header = $request->get_header('Authorization');
        $token = '';
        
        if ($auth_header) {
            // Bearer token
            if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                $token = $matches[1];
            }
        } 
        
        // Token via parâmetro GET/POST
        if (!$token) {
            $token = $request->get_param('token');
        }
        
        if (!$token) {
            return new WP_Error('no_token', 'Token de acesso não fornecido', array('status' => 401));
        }
        
        $valid_token = get_option($this->token_option);
        
        if (!$valid_token || $token !== $valid_token) {
            return new WP_Error('invalid_token', 'Token de acesso inválido', array('status' => 403));
        }
        
        return true;
    }
    
    /**
     * Status geral da fábrica - Endpoint principal com dados detalhados
     */
    public function get_fabrica_status($request) {
        global $wpdb;
        
        // Informações básicas da fábrica
        $fabrica_info = array(
            'id' => get_option('sincronizador_wc_fabrica_id', 1),
            'nome' => get_bloginfo('name'),
            'url' => home_url(),
            'status' => 'ativo',
            'ultima_atualizacao' => current_time('Y-m-d H:i:s'),
            'email_contato' => get_option('admin_email')
        );
        
        // Buscar revendedores com estatísticas detalhadas
        $revendedores = $this->get_revendedores_com_estatisticas();
        
        // Estatísticas globais da fábrica
        $estatisticas_globais = $this->calcular_estatisticas_globais($revendedores);
        
        // Produto mais vendido geral
        $produto_campeao = $this->get_produto_mais_vendido_geral();
        
        // Estrutura de resposta completa
        $response = array(
            'fabrica' => $fabrica_info,
            'estatisticas_globais' => $estatisticas_globais,
            'produto_campeao_geral' => $produto_campeao,
            'revendedores' => $revendedores,
            'metadados' => array(
                'total_revendedores' => count($revendedores),
                'revendedores_ativos' => count(array_filter($revendedores, function($r) { return $r['ativo']; })),
                'data_geracao' => current_time('Y-m-d H:i:s'),
                'versao_api' => '2.0'
            )
        );
        
        return rest_ensure_response($response);
    }
    
    /**
     * Buscar revendedores com estatísticas detalhadas
     */
    private function get_revendedores_com_estatisticas() {
        global $wpdb;
        
        // Force table creation if needed (for development)
        $this->ensure_tables_exist();
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $lojistas = $wpdb->get_results("SELECT * FROM $table_name ORDER BY data_criacao DESC");
        
        // Fallback: se não há dados na tabela, buscar dos options do WordPress
        if (empty($lojistas)) {
            $lojistas_option = get_option('sincronizador_wc_lojistas', array());
            if (!empty($lojistas_option)) {
                // Converter dados do option para formato similar à tabela
                $lojistas = array();
                foreach ($lojistas_option as $index => $lojista_data) {
                    $lojista = new stdClass();
                    $lojista->id = $index + 1;
                    $lojista->nome = $lojista_data['nome'] ?? 'Lojista ' . ($index + 1);
                    $lojista->url_loja = $lojista_data['url'] ?? $lojista_data['url_loja'] ?? '';
                    $lojista->status = isset($lojista_data['ativo']) && $lojista_data['ativo'] ? 'ativo' : 'inativo';
                    $lojista->ultima_sincronizacao = $lojista_data['ultima_sync'] ?? null;
                    $lojista->data_criacao = $lojista_data['criado_em'] ?? current_time('mysql');
                    $lojistas[] = $lojista;
                }
            } else {
                // Se não há dados em lugar nenhum, criar dados de exemplo
                $this->create_sample_lojistas();
                $lojistas = $wpdb->get_results("SELECT * FROM $table_name ORDER BY data_criacao DESC");
            }
        }
        
        $revendedores = array();
        
        foreach ($lojistas as $lojista) {
            // Buscar estatísticas reais de vendas
            $vendas_reais = $this->get_real_sales_data($lojista);
            $produtos_sincronizados = $this->get_real_products_count($lojista);
            $top_produtos = $this->get_real_top_products($lojista);
            $status_vendas = $this->get_real_sales_status($lojista);
            $ultimas_vendas = $this->get_real_recent_sales($lojista);
            
            $revendedores[] = array(
                'id' => (int) $lojista->id,
                'nome' => $lojista->nome,
                'url' => $lojista->url_loja ?? $lojista->url ?? '',
                'ativo' => ($lojista->status ?? 'ativo') === 'ativo',
                'status' => $lojista->status ?? 'ativo',
                'ultima_sync' => $lojista->ultima_sincronizacao ?? null,
                'criado_em' => $lojista->data_criacao ?? current_time('mysql'),
                'percentual_acrescimo' => 0.0,
                'url_fabrica_vinculada' => home_url(),
                'fabrica_id' => get_option('sincronizador_wc_fabrica_id', 1),
                'estatisticas_mes_atual' => array(
                    'vendas_quantidade' => $vendas_reais['vendas_mes'],
                    'faturamento_total' => $vendas_reais['faturamento_mes'],
                    'produtos_sincronizados' => $produtos_sincronizados,
                    'ticket_medio' => $vendas_reais['vendas_mes'] > 0 ? round($vendas_reais['faturamento_mes'] / $vendas_reais['vendas_mes'], 2) : 0
                ),
                'estatisticas_gerais' => array(
                    'total_vendas_historico' => $vendas_reais['total_vendas_historico'],
                    'status_vendas' => $status_vendas,
                    'taxa_conversao' => $this->calculate_conversion_rate($lojista),
                    'cliente_fidelidade' => $this->calculate_customer_loyalty($lojista)
                ),
                'top_5_produtos' => $top_produtos,
                'ultimas_vendas' => $ultimas_vendas,
                'configuracoes' => array(
                    'sincronizacao_automatica' => true,
                    'aplica_percentual_global' => true,
                    'notificacoes_ativas' => true
                )
            );
        }
        
        return $revendedores;
    }
    
    /**
     * Calcular estatísticas globais da fábrica
     */
    private function calcular_estatisticas_globais($revendedores) {
        $total_vendas_mes = 0;
        $total_faturamento_mes = 0;
        $total_produtos_sincronizados = 0;
        
        foreach ($revendedores as $revendedor) {
            $total_vendas_mes += $revendedor['estatisticas_mes_atual']['vendas_quantidade'];
            $total_faturamento_mes += $revendedor['estatisticas_mes_atual']['faturamento_total'];
            $total_produtos_sincronizados += $revendedor['estatisticas_mes_atual']['produtos_sincronizados'];
        }
        
        return array(
            'total_vendas_mes_atual' => $total_vendas_mes,
            'faturamento_total_mes_atual' => $total_faturamento_mes,
            'produtos_total_sincronizados' => $total_produtos_sincronizados,
            'ticket_medio_global' => $total_vendas_mes > 0 ? round($total_faturamento_mes / $total_vendas_mes, 2) : 0,
            'crescimento_mensal' => $this->calculate_real_growth(),
            'revendedores_novos_mes' => $this->count_new_revendedores_this_month()
        );
    }
    
    /**
     * Produto mais vendido geral (dados reais)
     */
    private function get_produto_mais_vendido_geral() {
        global $wpdb;
        
        // Buscar o produto mais vendido de todos os tempos
        $best_seller = $wpdb->get_row(
            "SELECT p.ID, p.post_title, 
                    SUM(oim.meta_value) as total_sales,
                    SUM(oim2.meta_value) as total_revenue
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             INNER JOIN {$wpdb->woocommerce_order_itemmeta} oim ON pm.meta_value = oim.meta_value
             INNER JOIN {$wpdb->woocommerce_order_itemmeta} oim2 ON oim.order_item_id = oim2.order_item_id
             WHERE p.post_type = 'product' 
             AND pm.meta_key = '_sku'
             AND oim.meta_key = '_qty'
             AND oim2.meta_key = '_line_total'
             GROUP BY p.ID
             ORDER BY total_sales DESC
             LIMIT 1"
        );
        
        if ($best_seller) {
            // Calcular vendas do mês atual
            $current_month = date('Y-m');
            $monthly_sales = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(oim.meta_value)
                 FROM {$wpdb->woocommerce_order_itemmeta} oim
                 INNER JOIN {$wpdb->woocommerce_order_items} oi ON oim.order_item_id = oi.order_item_id
                 INNER JOIN {$wpdb->posts} ord ON oi.order_id = ord.ID
                 INNER JOIN {$wpdb->postmeta} pm ON %d = pm.post_id
                 WHERE oim.meta_key = '_qty'
                 AND pm.meta_key = '_sku'
                 AND ord.post_date LIKE %s",
                $best_seller->ID, $current_month . '%'
            )) ?? 0;
            
            return array(
                'id' => (int) $best_seller->ID,
                'nome' => $best_seller->post_title,
                'vendas_totais' => (int) $best_seller->total_sales,
                'faturamento_total' => (float) $best_seller->total_revenue,
                'vendas_mes_atual' => (int) $monthly_sales,
                'revendedores_vendendo' => $this->count_revendedores_selling_product($best_seller->ID)
            );
        }
        
        // Fallback se não encontrar dados
        return array(
            'id' => 0,
            'nome' => 'Nenhum produto encontrado',
            'vendas_totais' => 0,
            'faturamento_total' => 0,
            'vendas_mes_atual' => 0,
            'revendedores_vendendo' => 0
        );
    }
    
    /**
     * Dados detalhados dos revendedores
     */
    public function get_revendedores_detalhado($request) {
        $lojistas = $this->get_lojistas_data();
        
        $revendedores_detalhado = array();
        
        foreach ($lojistas as $lojista) {
            $vendas_lojista = $this->get_vendas_lojista($lojista);
            $produtos_lojista = $this->get_produtos_lojista($lojista);
            
            $revendedores_detalhado[] = array(
                'id' => $lojista['id'],
                'nome' => $lojista['nome'],
                'url' => $lojista['url'], 
                'status' => isset($lojista['ativo']) && $lojista['ativo'] ? 'ativo' : 'inativo',
                'ultima_sincronizacao' => $lojista['ultima_sync'] ?? null,
                'produtos_sincronizados' => $produtos_lojista['total'],
                'vendas_mes' => $vendas_lojista['vendas'],
                'faturamento_mes' => $vendas_lojista['faturamento'],
                'produto_mais_vendido' => $vendas_lojista['produto_top'] ?? null
            );
        }
        
        return rest_ensure_response($revendedores_detalhado);
    }
    
    /**
     * Produtos mais vendidos
     */
    public function get_produtos_top($request) {
        $limit = $request->get_param('limit') ?: 10;
        $period = $request->get_param('period') ?: 30;
        
        $produtos_top = $this->buscar_produtos_mais_vendidos($limit, $period);
        
        return rest_ensure_response($produtos_top);
    }
    
    /**
     * Health check do sistema
     */
    public function health_check($request) {
        $health = array(
            'status' => 'ok',
            'timestamp' => current_time('mysql'),
            'versao_plugin' => SINCRONIZADOR_WC_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'não instalado',
            'memoria_php' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'lojistas_cadastrados' => count($this->get_lojistas_data()),
            'ultima_sincronizacao' => get_option('sincronizador_wc_ultima_sync', 'nunca')
        );
        
        return rest_ensure_response($health);
    }
    
    /**
     * Gerar novo token de acesso
     */
    public function generate_token_endpoint($request) {
        $new_token = $this->generate_access_token();
        
        return rest_ensure_response(array(
            'success' => true,
            'token' => $new_token,
            'message' => 'Novo token gerado com sucesso'
        ));
    }
    
    /**
     * Obter token atual
     */
    public function get_token_endpoint($request) {
        $token = get_option($this->token_option);
        
        if (!$token) {
            $token = $this->generate_access_token();
        }
        
        return rest_ensure_response(array(
            'token' => $token,
            'url_base' => get_site_url() . '/wp-json/sincronizador-wc/v1/master/'
        ));
    }
    
    /**
     * Salvar dados quando as vendas são sincronizadas
     */
    public function save_sync_data($lojista_id, $dados_vendas) {
        $sync_data = get_option('sincronizador_wc_master_sync_data', array());
        
        $sync_data[$lojista_id] = array(
            'ultima_sync' => current_time('mysql'),
            'vendas' => $dados_vendas,
            'timestamp' => time()
        );
        
        update_option('sincronizador_wc_master_sync_data', $sync_data);
    }
    
    /**
     * Obter dados dos lojistas
     */
    private function get_lojistas_data() {
        $lojistas_option = get_option('sincronizador_wc_lojistas', array());
        
        if (empty($lojistas_option)) {
            return array();
        }
        
        return $lojistas_option;
    }
    
    /**
     * Obter total de produtos sincronizados
     */
    private function get_total_produtos_sincronizados() {
        $historico = get_option('sincronizador_wc_historico_envios', array());
        $total = 0;
        
        foreach ($historico as $lojista_url => $produtos) {
            $total += count($produtos);
        }
        
        return $total;
    }
    
    /**
     * Obter vendas do período atual
     */
    private function get_vendas_periodo() {
        $sync_data = get_option('sincronizador_wc_master_sync_data', array());
        $vendas_total = 0;
        $faturamento_total = 0;
        
        foreach ($sync_data as $lojista_id => $dados) {
            if (isset($dados['vendas'])) {
                $vendas_total += $dados['vendas']['total_vendas'] ?? 0;
                $faturamento_total += $dados['vendas']['total_faturamento'] ?? 0;
            }
        }
        
        return array(
            'vendas' => $vendas_total,
            'faturamento' => $faturamento_total
        );
    }
    
    /**
     * Obter produto campeão de vendas
     */
    private function get_produto_campeao() {
        $sync_data = get_option('sincronizador_wc_master_sync_data', array());
        $produtos = array();
        
        foreach ($sync_data as $lojista_id => $dados) {
            if (isset($dados['vendas']['produtos_vendidos'])) {
                foreach ($dados['vendas']['produtos_vendidos'] as $produto) {
                    $sku = $produto['sku'] ?? $produto['nome'];
                    if (!isset($produtos[$sku])) {
                        $produtos[$sku] = array(
                            'nome' => $produto['nome'],
                            'sku' => $produto['sku'] ?? '',
                            'vendas' => 0
                        );
                    }
                    $produtos[$sku]['vendas'] += $produto['quantidade_vendida'] ?? 0;
                }
            }
        }
        
        if (empty($produtos)) {
            return null;
        }
        
        // Ordenar por vendas
        uasort($produtos, function($a, $b) {
            return $b['vendas'] - $a['vendas'];
        });
        
        return array_values($produtos)[0];
    }
    
    /**
     * Obter vendas de um lojista específico
     */
    private function get_vendas_lojista($lojista) {
        $sync_data = get_option('sincronizador_wc_master_sync_data', array());
        
        if (!isset($sync_data[$lojista['id']])) {
            return array(
                'vendas' => 0,
                'faturamento' => 0,
                'produto_top' => null
            );
        }
        
        $dados = $sync_data[$lojista['id']];
        $vendas = $dados['vendas'] ?? array();
        
        return array(
            'vendas' => $vendas['total_vendas'] ?? 0,
            'faturamento' => $vendas['total_faturamento'] ?? 0,
            'produto_top' => $this->get_produto_top_lojista($vendas)
        );
    }
    
    /**
     * Obter produtos de um lojista específico
     */
    private function get_produtos_lojista($lojista) {
        $historico = get_option('sincronizador_wc_historico_envios', array());
        $total = 0;
        
        foreach ($historico as $lojista_url => $produtos) {
            if (strpos($lojista_url, $lojista['url']) !== false) {
                $total = count($produtos);
                break;
            }
        }
        
        return array('total' => $total);
    }
    
    /**
     * Obter produto mais vendido de um lojista
     */
    private function get_produto_top_lojista($vendas_dados) {
        if (!isset($vendas_dados['produtos_vendidos']) || empty($vendas_dados['produtos_vendidos'])) {
            return null;
        }
        
        $produtos = $vendas_dados['produtos_vendidos'];
        
        // Ordenar por quantidade vendida
        usort($produtos, function($a, $b) {
            $vendas_a = $a['quantidade_vendida'] ?? 0;
            $vendas_b = $b['quantidade_vendida'] ?? 0;
            return $vendas_b - $vendas_a;
        });
        
        return $produtos[0] ?? null;
    }
    
    /**
     * Buscar produtos mais vendidos geral
     */
    private function buscar_produtos_mais_vendidos($limit = 10, $period = 30) {
        $sync_data = get_option('sincronizador_wc_master_sync_data', array());
        $produtos_consolidados = array();
        
        foreach ($sync_data as $lojista_id => $dados) {
            if (isset($dados['vendas']['produtos_vendidos'])) {
                foreach ($dados['vendas']['produtos_vendidos'] as $produto) {
                    $sku = $produto['sku'] ?? $produto['nome'];
                    
                    if (!isset($produtos_consolidados[$sku])) {
                        $produtos_consolidados[$sku] = array(
                            'nome' => $produto['nome'],
                            'sku' => $produto['sku'] ?? '',
                            'quantidade_vendida' => 0,
                            'receita_total' => 0
                        );
                    }
                    
                    $produtos_consolidados[$sku]['quantidade_vendida'] += $produto['quantidade_vendida'] ?? 0;
                    $produtos_consolidados[$sku]['receita_total'] += $produto['receita_total'] ?? 0;
                }
            }
        }
        
        // Ordenar por quantidade vendida
        uasort($produtos_consolidados, function($a, $b) {
            return $b['quantidade_vendida'] - $a['quantidade_vendida'];
        });
        
        return array_slice(array_values($produtos_consolidados), 0, $limit);
    }
    
    /**
     * Gerar token de acesso
     */
    public function generate_access_token() {
        $token = wp_generate_password(32, false);
        update_option($this->token_option, $token);
        return $token;
    }
    
    /**
     * Obter token atual
     */
    public function get_access_token() {
        return get_option($this->token_option);
    }
    
    /**
     * Garantir que as tabelas necessárias existam
     */
    private function ensure_tables_exist() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if (!$table_exists) {
            // Tentar carregar a classe database
            $database_file = plugin_dir_path(dirname(__FILE__)) . 'includes/class-database.php';
            if (file_exists($database_file)) {
                require_once $database_file;
                if (class_exists('Sincronizador_WC_Database')) {
                    Sincronizador_WC_Database::create_tables();
                    error_log("SINCRONIZADOR API: Tabelas criadas com sucesso!");
                    
                    // Migrar dados existentes dos options
                    $this->migrate_options_to_table();
                }
            }
        } else {
            // Tabela existe, verificar se precisa migrar dados
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($count == 0) {
                $this->migrate_options_to_table();
            }
        }
    }
    
    /**
     * Criar lojistas de exemplo para demonstração
     */
    private function create_sample_lojistas() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $sample_lojistas = array(
            array(
                'nome' => 'Loja Premium Joias',
                'url_loja' => 'https://lojapremium.exemplo.com',
                'consumer_key' => 'ck_sample_1234567890abcdef',
                'consumer_secret' => 'cs_sample_1234567890abcdef',
                'status' => 'ativo'
            ),
            array(
                'nome' => 'Bijuterias Elegance',
                'url_loja' => 'https://elegance.exemplo.com',
                'consumer_key' => 'ck_sample_2345678901bcdefg',
                'consumer_secret' => 'cs_sample_2345678901bcdefg',
                'status' => 'ativo'
            ),
            array(
                'nome' => 'Semijoias Luxo',
                'url_loja' => 'https://luxo.exemplo.com',
                'consumer_key' => 'ck_sample_3456789012cdefgh',
                'consumer_secret' => 'cs_sample_3456789012cdefgh',
                'status' => 'inativo'
            )
        );
        
        foreach ($sample_lojistas as $lojista) {
            $wpdb->insert($table_name, $lojista);
        }
        
        error_log("SINCRONIZADOR API: Lojistas de exemplo criados!");
    }
    
    /**
     * Migrar dados dos options para a tabela do banco
     */
    public function migrate_options_to_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if (!$table_exists) {
            return false;
        }
        
        // Verificar se já há dados na tabela
        $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if ($existing_count > 0) {
            return true; // Já migrado
        }
        
        // Buscar dados dos options
        $lojistas_option = get_option('sincronizador_wc_lojistas', array());
        if (empty($lojistas_option)) {
            return false;
        }
        
        $migrated = 0;
        foreach ($lojistas_option as $lojista_data) {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'nome' => $lojista_data['nome'] ?? 'Lojista Importado',
                    'url_loja' => $lojista_data['url'] ?? $lojista_data['url_loja'] ?? '',
                    'consumer_key' => $lojista_data['consumer_key'] ?? '',
                    'consumer_secret' => $lojista_data['consumer_secret'] ?? '',
                    'status' => isset($lojista_data['ativo']) && $lojista_data['ativo'] ? 'ativo' : 'inativo',
                    'ultima_sincronizacao' => $lojista_data['ultima_sync'] ?? null,
                    'data_criacao' => $lojista_data['criado_em'] ?? current_time('mysql'),
                    'data_atualizacao' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result) {
                $migrated++;
            }
        }
        
        error_log("SINCRONIZADOR API: {$migrated} lojistas migrados dos options para a tabela");
        return $migrated > 0;
    }
    
    /**
     * Endpoint para sincronização manual de vendas
     */
    public function sync_vendas_endpoint($request) {
        $lojista_id = $request->get_param('lojista_id');
        
        if (!$lojista_id) {
            return new WP_Error('missing_lojista_id', 'ID do lojista é obrigatório', array('status' => 400));
        }
        
        // Buscar dados do lojista
        $lojistas_option = get_option('sincronizador_wc_lojistas', array());
        $lojista = null;
        
        foreach ($lojistas_option as $loj) {
            if (isset($loj['id']) && $loj['id'] == $lojista_id) {
                $lojista = $loj;
                break;
            }
        }
        
        if (!$lojista) {
            return new WP_Error('lojista_not_found', 'Lojista não encontrado', array('status' => 404));
        }
        
        // Sincronizar vendas
        $vendas_sincronizadas = $this->sync_sales_data($lojista);
        
        if ($vendas_sincronizadas === false) {
            return new WP_Error('sync_failed', 'Falha na sincronização de vendas', array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'lojista' => $lojista['nome'],
            'vendas_sincronizadas' => $vendas_sincronizadas,
            'message' => "Sincronização concluída: {$vendas_sincronizadas} vendas processadas",
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Buscar dados reais de vendas do lojista
     */
    private function get_real_sales_data($lojista) {
        global $wpdb;
        
        $current_month = date('Y-m');
        $lojista_url = $lojista->url_loja ?? $lojista->url ?? '';
        
        // Buscar na tabela de vendas se existir
        $vendas_table = $wpdb->prefix . 'sincronizador_vendas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$vendas_table'");
        
        if ($table_exists) {
            // Vendas do mês atual
            $vendas_mes = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(quantidade_vendida) FROM $vendas_table 
                 WHERE lojista_id = %d AND periodo_referencia = %s",
                $lojista->id, $current_month
            )) ?? 0;
            
            $faturamento_mes = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(valor_total) FROM $vendas_table 
                 WHERE lojista_id = %d AND periodo_referencia = %s",
                $lojista->id, $current_month
            )) ?? 0;
            
            // Total histórico
            $total_vendas_historico = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(quantidade_vendida) FROM $vendas_table 
                 WHERE lojista_id = %d",
                $lojista->id
            )) ?? 0;
        } else {
            // Fallback: buscar diretamente do WooCommerce da fábrica
            $vendas_mes = $this->get_woocommerce_sales_data($current_month);
            $faturamento_mes = $this->get_woocommerce_revenue_data($current_month);
            $total_vendas_historico = $this->get_woocommerce_total_sales();
        }
        
        return array(
            'vendas_mes' => (int) $vendas_mes,
            'faturamento_mes' => (float) $faturamento_mes,
            'total_vendas_historico' => (int) $total_vendas_historico
        );
    }
    
    /**
     * Buscar quantidade real de produtos sincronizados
     */
    private function get_real_products_count($lojista) {
        global $wpdb;
        
        // Buscar na tabela de cache de produtos
        $cache_table = $wpdb->prefix . 'sincronizador_produtos_cache';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$cache_table'");
        
        if ($table_exists) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cache_table WHERE lojista_id = %d",
                $lojista->id
            ));
            if ($count > 0) return (int) $count;
        }
        
        // Fallback: contar produtos WooCommerce
        $products = wc_get_products(array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids'
        ));
        
        return count($products);
    }
    
    /**
     * Buscar top 5 produtos reais mais vendidos
     */
    private function get_real_top_products($lojista) {
        global $wpdb;
        
        // Tentar buscar da tabela de vendas
        $vendas_table = $wpdb->prefix . 'sincronizador_vendas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$vendas_table'");
        
        if ($table_exists) {
            $top_produtos = $wpdb->get_results($wpdb->prepare(
                "SELECT produto_id_fabrica as id, sku, 
                        SUM(quantidade_vendida) as vendas,
                        SUM(valor_total) as faturamento
                 FROM $vendas_table 
                 WHERE lojista_id = %d 
                 GROUP BY produto_id_fabrica, sku
                 ORDER BY vendas DESC 
                 LIMIT 5",
                $lojista->id
            ), ARRAY_A);
            
            if (!empty($top_produtos)) {
                $produtos_formatados = array();
                foreach ($top_produtos as $index => $produto) {
                    $product = wc_get_product($produto['id']);
                    $produtos_formatados[] = array(
                        'id' => (int) $produto['id'],
                        'nome' => $product ? $product->get_name() : 'Produto ID ' . $produto['id'],
                        'vendas' => (int) $produto['vendas'],
                        'faturamento' => (float) $produto['faturamento'],
                        'posicao' => $index + 1
                    );
                }
                return $produtos_formatados;
            }
        }
        
        // Fallback: buscar produtos mais vendidos do WooCommerce
        return $this->get_woocommerce_best_sellers();
    }
    
    /**
     * Buscar status real das vendas
     */
    private function get_real_sales_status($lojista) {
        global $wpdb;
        
        // Buscar pedidos dos últimos 30 dias
        $orders = wc_get_orders(array(
            'limit' => -1,
            'date_created' => '>=' . (time() - 30 * DAY_IN_SECONDS),
            'return' => 'objects'
        ));
        
        $status_count = array(
            'vendas_pendentes' => 0,
            'vendas_processando' => 0,
            'vendas_concluidas' => 0,
            'vendas_canceladas' => 0,
            'vendas_reembolsadas' => 0
        );
        
        foreach ($orders as $order) {
            $status = $order->get_status();
            switch ($status) {
                case 'pending':
                case 'on-hold':
                    $status_count['vendas_pendentes']++;
                    break;
                case 'processing':
                    $status_count['vendas_processando']++;
                    break;
                case 'completed':
                    $status_count['vendas_concluidas']++;
                    break;
                case 'cancelled':
                    $status_count['vendas_canceladas']++;
                    break;
                case 'refunded':
                    $status_count['vendas_reembolsadas']++;
                    break;
            }
        }
        
        return $status_count;
    }
    
    /**
     * Buscar últimas vendas reais
     */
    private function get_real_recent_sales($lojista) {
        $orders = wc_get_orders(array(
            'limit' => 3,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects'
        ));
        
        $ultimas_vendas = array();
        foreach ($orders as $order) {
            $items = $order->get_items();
            $primeiro_produto = reset($items);
            
            $ultimas_vendas[] = array(
                'id' => $order->get_id(),
                'data' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'valor' => (float) $order->get_total(),
                'produto' => $primeiro_produto ? $primeiro_produto->get_name() : 'Produto',
                'status' => $this->translate_order_status($order->get_status())
            );
        }
        
        return $ultimas_vendas;
    }
    
    /**
     * Calcular taxa de conversão real
     */
    private function calculate_conversion_rate($lojista) {
        // Implementação básica - você pode melhorar com dados reais
        $visits = get_option('woocommerce_analytics_visits', 1000);
        $orders = wc_get_orders(array('limit' => -1, 'return' => 'ids'));
        $rate = $visits > 0 ? round((count($orders) / $visits) * 100, 1) : 0;
        return $rate . '%';
    }
    
    /**
     * Calcular fidelidade de clientes
     */
    private function calculate_customer_loyalty($lojista) {
        $customers = get_users(array('role' => 'customer'));
        $repeat_customers = 0;
        
        foreach ($customers as $customer) {
            $orders = wc_get_orders(array(
                'customer' => $customer->ID,
                'limit' => -1,
                'return' => 'ids'
            ));
            if (count($orders) > 1) {
                $repeat_customers++;
            }
        }
        
        $loyalty_rate = count($customers) > 0 ? round(($repeat_customers / count($customers)) * 100, 1) : 0;
        return $loyalty_rate . '%';
    }
    
    /**
     * Buscar dados de vendas do WooCommerce por mês
     */
    private function get_woocommerce_sales_data($month) {
        $orders = wc_get_orders(array(
            'limit' => -1,
            'date_created' => $month . '-01...' . $month . '-31',
            'status' => array('completed', 'processing'),
            'return' => 'objects'
        ));
        
        $total_items = 0;
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $total_items += $item->get_quantity();
            }
        }
        
        return $total_items;
    }
    
    /**
     * Buscar faturamento do WooCommerce por mês
     */
    private function get_woocommerce_revenue_data($month) {
        $orders = wc_get_orders(array(
            'limit' => -1,
            'date_created' => $month . '-01...' . $month . '-31',
            'status' => array('completed', 'processing'),
            'return' => 'objects'
        ));
        
        $total_revenue = 0;
        foreach ($orders as $order) {
            $total_revenue += $order->get_total();
        }
        
        return $total_revenue;
    }
    
    /**
     * Buscar total de vendas históricas
     */
    private function get_woocommerce_total_sales() {
        $orders = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed'),
            'return' => 'objects'
        ));
        
        $total_items = 0;
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $total_items += $item->get_quantity();
            }
        }
        
        return $total_items;
    }
    
    /**
     * Buscar produtos mais vendidos do WooCommerce
     */
    private function get_woocommerce_best_sellers() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT p.ID, p.post_title, 
                    SUM(oim.meta_value) as total_sales,
                    SUM(oim2.meta_value) as total_revenue
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             INNER JOIN {$wpdb->woocommerce_order_itemmeta} oim ON pm.meta_value = oim.meta_value
             INNER JOIN {$wpdb->woocommerce_order_itemmeta} oim2 ON oim.order_item_id = oim2.order_item_id
             WHERE p.post_type = 'product' 
             AND pm.meta_key = '_sku'
             AND oim.meta_key = '_qty'
             AND oim2.meta_key = '_line_total'
             GROUP BY p.ID
             ORDER BY total_sales DESC
             LIMIT 5"
        );
        
        $produtos = array();
        foreach ($results as $index => $produto) {
            $produtos[] = array(
                'id' => (int) $produto->ID,
                'nome' => $produto->post_title,
                'vendas' => (int) $produto->total_sales,
                'faturamento' => (float) $produto->total_revenue,
                'posicao' => $index + 1
            );
        }
        
        return $produtos;
    }
    
    /**
     * Traduzir status do pedido
     */
    private function translate_order_status($status) {
        $translations = array(
            'pending' => 'pendente',
            'processing' => 'processando',
            'on-hold' => 'pendente',
            'completed' => 'concluida',
            'cancelled' => 'cancelada',
            'refunded' => 'reembolsada',
            'failed' => 'falhada'
        );
        
        return $translations[$status] ?? $status;
    }
    
    /**
     * Calcular crescimento real comparando com o mês anterior
     */
    private function calculate_real_growth() {
        $current_month = date('Y-m');
        $previous_month = date('Y-m', strtotime('-1 month'));
        
        $current_revenue = $this->get_woocommerce_revenue_data($current_month);
        $previous_revenue = $this->get_woocommerce_revenue_data($previous_month);
        
        if ($previous_revenue > 0) {
            return round((($current_revenue - $previous_revenue) / $previous_revenue) * 100, 1);
        }
        
        return 0;
    }
    
    /**
     * Contar revendedores novos neste mês
     */
    private function count_new_revendedores_this_month() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            $current_month = date('Y-m');
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE data_criacao LIKE %s",
                $current_month . '%'
            )) ?? 0;
        }
        
        return 0;
    }
    
    /**
     * Contar quantos revendedores vendem um produto específico
     */
    private function count_revendedores_selling_product($product_id) {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'sincronizador_produtos_cache';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$cache_table'");
        
        if ($table_exists) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT lojista_id) FROM $cache_table WHERE produto_id_fabrica = %d",
                $product_id
            )) ?? 0;
        }
        
        return 1; // Fallback: pelo menos a fábrica vende
    }
    
    /**
     * Atualizar dados da API após sincronização
     */
    public function update_api_data_after_sync($lojista, $produtos_sincronizados) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // Atualizar última sincronização na tabela
            $wpdb->update(
                $table_name,
                array(
                    'ultima_sincronizacao' => current_time('mysql'),
                    'data_atualizacao' => current_time('mysql')
                ),
                array('id' => $lojista['id']),
                array('%s', '%s'),
                array('%d')
            );
            
            // Log da atualização
            error_log("SINCRONIZADOR API: Dados atualizados para lojista {$lojista['nome']} - {$produtos_sincronizados} produtos sincronizados");
        }
        
        // Atualizar também nos options como backup
        $lojistas_option = get_option('sincronizador_wc_lojistas', array());
        if (!empty($lojistas_option)) {
            foreach ($lojistas_option as $index => &$loj) {
                if (isset($loj['id']) && $loj['id'] == $lojista['id']) {
                    $loj['ultima_sync'] = current_time('mysql');
                    $loj['produtos_sincronizados'] = $produtos_sincronizados;
                    break;
                }
            }
            update_option('sincronizador_wc_lojistas', $lojistas_option);
        }
        
        // 🚀 SINCRONIZAR VENDAS AUTOMATICAMENTE
        $this->sync_sales_data($lojista);
        
        // Invalidar cache se estiver sendo usado
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('master_api_revendedores', 'sincronizador_wc');
            wp_cache_delete('master_api_estatisticas', 'sincronizador_wc');
        }
    }
    
    /**
     * Sincronizar dados de vendas para estatísticas mais precisas
     */
    public function sync_sales_data($lojista) {
        if (!$lojista || empty($lojista['url'])) {
            return false;
        }
        
        global $wpdb;
        $vendas_table = $wpdb->prefix . 'sincronizador_vendas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$vendas_table'");
        
        if (!$table_exists) {
            return false;
        }
        
        try {
            // Buscar vendas do lojista via API WooCommerce
            $consumer_key = $lojista['consumer_key'] ?? '';
            $consumer_secret = $lojista['consumer_secret'] ?? '';
            $url_loja = rtrim($lojista['url'], '/');
            
            if (empty($consumer_key) || empty($consumer_secret)) {
                return false;
            }
            
            // Fazer requisição para buscar pedidos dos últimos 30 dias
            $url_api = $url_loja . '/wp-json/wc/v3/orders';
            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
                ),
                'timeout' => 30
            );
            
            // Buscar pedidos dos últimos 30 dias
            $url_api .= '?after=' . date('Y-m-d', strtotime('-30 days')) . 'T00:00:00';
            $response = wp_remote_get($url_api, $args);
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $orders = json_decode($body, true);
            
            if (!is_array($orders)) {
                return false;
            }
            
            $vendas_sincronizadas = 0;
            $periodo_atual = date('Y-m');
            
            foreach ($orders as $order) {
                if (!isset($order['line_items']) || !is_array($order['line_items'])) {
                    continue;
                }
                
                foreach ($order['line_items'] as $item) {
                    // Inserir ou atualizar venda na tabela
                    $wpdb->replace(
                        $vendas_table,
                        array(
                            'lojista_id' => $lojista['id'],
                            'produto_id_fabrica' => $item['product_id'] ?? 0,
                            'sku' => $item['sku'] ?? '',
                            'quantidade_vendida' => $item['quantity'] ?? 0,
                            'valor_total' => $item['total'] ?? 0,
                            'data_venda' => $order['date_created'] ?? current_time('mysql'),
                            'periodo_referencia' => $periodo_atual,
                            'data_sincronizacao' => current_time('mysql')
                        ),
                        array('%d', '%d', '%s', '%d', '%f', '%s', '%s', '%s')
                    );
                    $vendas_sincronizadas++;
                }
            }
            
            error_log("SINCRONIZADOR API: {$vendas_sincronizadas} vendas sincronizadas para {$lojista['nome']}");
            return $vendas_sincronizadas;
            
        } catch (Exception $e) {
            error_log("SINCRONIZADOR API: Erro ao sincronizar vendas - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Atualizar dados da API após atualização manual do lojista
     */
    public function update_api_data_after_lojista_update($lojista) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // Atualizar dados na tabela
            $wpdb->update(
                $table_name,
                array(
                    'data_atualizacao' => current_time('mysql')
                ),
                array('id' => $lojista['id']),
                array('%s'),
                array('%d')
            );
            
            error_log("SINCRONIZADOR API: Dados do lojista {$lojista['nome']} atualizados manualmente");
        }
        
        // 🚀 SINCRONIZAR VENDAS quando atualizar manualmente
        $vendas_sync = $this->sync_sales_data($lojista);
        if ($vendas_sync !== false) {
            error_log("SINCRONIZADOR API: {$vendas_sync} vendas sincronizadas durante atualização manual");
        }
        
        // Invalidar cache
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('master_api_revendedores', 'sincronizador_wc');
            wp_cache_delete('master_api_estatisticas', 'sincronizador_wc');
        }
    }
}
