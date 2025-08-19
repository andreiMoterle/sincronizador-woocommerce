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
        add_action('rest_api_init', array($this, 'register_master_routes'));
        add_action('sincronizador_wc_vendas_sincronizadas', array($this, 'save_sync_data'), 10, 2);
        
        // Gerar token automaticamente se não existe
        if (!get_option($this->token_option)) {
            $this->generate_access_token();
        }
    }
    
    public function register_master_routes() {
        // Endpoint principal para o painel master - dados completos da fábrica
        register_rest_route('sincronizador-wc/v1', '/master/fabrica-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_fabrica_status'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint para dados detalhados dos lojistas/revendedores
        register_rest_route('sincronizador-wc/v1', '/master/revendedores', array(
            'methods' => 'GET', 
            'callback' => array($this, 'get_revendedores_detalhado'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint para produtos mais vendidos
        register_rest_route('sincronizador-wc/v1', '/master/produtos-top', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_produtos_top'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint para health check
        register_rest_route('sincronizador-wc/v1', '/master/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint público para gerar token (apenas admin)
        register_rest_route('sincronizador-wc/v1', '/master/generate-token', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_token_endpoint'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        // Endpoint para obter token atual (apenas admin)
        register_rest_route('sincronizador-wc/v1', '/master/get-token', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_token_endpoint'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
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
     * Status geral da fábrica - Endpoint principal
     */
    public function get_fabrica_status($request) {
        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        
        // Obter dados dos lojistas
        $lojistas = $this->get_lojistas_data();
        
        // Estatísticas gerais
        $total_lojistas = count($lojistas);
        $lojistas_ativos = count(array_filter($lojistas, function($l) { 
            return isset($l['ativo']) && $l['ativo']; 
        }));
        
        // Produtos sincronizados
        $total_produtos = $this->get_total_produtos_sincronizados();
        
        // Vendas do período
        $vendas_periodo = $this->get_vendas_periodo();
        
        // Produto campeão
        $produto_campeao = $this->get_produto_campeao();
        
        $response_data = array(
            'fabrica' => array(
                'nome' => $site_name,
                'url' => $site_url,
                'status' => 'ativo',
                'ultima_atualizacao' => current_time('mysql'),
                'versao_plugin' => SINCRONIZADOR_WC_VERSION
            ),
            'estatisticas' => array(
                'total_revendedores' => $total_lojistas,
                'revendedores_ativos' => $lojistas_ativos,
                'total_produtos_sincronizados' => $total_produtos,
                'vendas_mes_atual' => $vendas_periodo['vendas'],
                'faturamento_mes_atual' => $vendas_periodo['faturamento']
            ),
            'produto_campeao' => $produto_campeao,
            'revendedores' => $lojistas
        );
        
        return rest_ensure_response($response_data);
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
}
