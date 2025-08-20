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
        $lojistas_ativos = array_filter($lojistas, function($l) { 
            return isset($l['ativo']) && $l['ativo']; 
        });
        
        // Produtos sincronizados
        $total_produtos = $this->get_total_produtos_sincronizados();
        
        // Obter estatísticas de vendas (últimos 30 dias)
        $estatisticas_vendas = $this->get_estatisticas_vendas_fabrica();
        
        // Obter top 5 produtos mais vendidos
        $top_produtos = $this->get_top_produtos_vendidos(5);
        
        // Formatar dados dos revendedores com informações detalhadas
        $revendedores_formatados = array();
        foreach ($lojistas_ativos as $lojista) {
            $estatisticas_lojista = $this->get_estatisticas_lojista($lojista);
            $top_produtos_lojista = $this->get_top_produtos_lojista($lojista, 5);
            $ultimas_vendas_lojista = $this->get_ultimas_vendas_lojista($lojista, 5);
            
            $revendedores_formatados[] = array(
                'id' => $lojista['id'],
                'nome' => $lojista['nome'],
                'url' => $lojista['url'],
                'percentual_acrescimo' => $lojista['percentual_acrescimo'] ?? 0,
                'ativo' => $lojista['ativo'] ?? true,
                'status' => isset($lojista['ativo']) && $lojista['ativo'] ? 'ativo' : 'inativo',
                'ultima_sync' => $lojista['ultima_sync'] ?? null,
                'criado_em' => $lojista['criado_em'] ?? null,
                'estatisticas_gerais' => $estatisticas_lojista,
                'top_5_produtos' => $top_produtos_lojista,
                'ultimas_vendas' => $ultimas_vendas_lojista
            );
        }
        
        $response_data = array(
            'fabrica' => array(
                'nome' => $site_name,
                'url' => $site_url,
                'status' => 'ativo',
                'ultima_atualizacao' => current_time('mysql'),
                'versao_plugin' => SINCRONIZADOR_WC_VERSION,
                'codigo_atualizado' => '2025-08-20-v2' // Flag para confirmar atualização
            ),
            'estatisticas' => array(
                'total_revendedores' => $total_lojistas,
                'revendedores_ativos' => count($lojistas_ativos),
                'total_produtos_sincronizados' => $total_produtos,
                'total_vendas_mes' => $estatisticas_vendas['total_vendas'],
                'total_pedidos_mes' => $estatisticas_vendas['total_pedidos'],
                'produtos_vendidos_mes' => $estatisticas_vendas['produtos_vendidos']
            ),
            'top_5_produtos_geral' => $top_produtos,
            'revendedores' => $revendedores_formatados
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
     * Obter estatísticas de vendas da fábrica (últimos 30 dias)
     */
    private function get_estatisticas_vendas_fabrica() {
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = date('Y-m-d');
        
        $lojistas = $this->get_lojistas_data();
        $lojistas_ativos = array_filter($lojistas, function($l) { 
            return isset($l['ativo']) && $l['ativo']; 
        });
        
        $total_vendas = 0;
        $total_pedidos = 0;
        $produtos_vendidos = 0;
        
        foreach ($lojistas_ativos as $lojista) {
            $vendas_lojista = $this->buscar_vendas_lojista_api($lojista, $data_inicio, $data_fim);
            
            if ($vendas_lojista['success']) {
                $total_vendas += $vendas_lojista['data']['total_vendas'];
                $total_pedidos += $vendas_lojista['data']['total_pedidos'];
                $produtos_vendidos += $vendas_lojista['data']['produtos_vendidos'];
            }
        }
        
        return array(
            'total_vendas' => $total_vendas,
            'total_pedidos' => $total_pedidos,
            'produtos_vendidos' => $produtos_vendidos
        );
    }
    
    /**
     * Obter top produtos mais vendidos geral
     */
    private function get_top_produtos_vendidos($limit = 5) {
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = date('Y-m-d');
        
        $lojistas = $this->get_lojistas_data();
        $lojistas_ativos = array_filter($lojistas, function($l) { 
            return isset($l['ativo']) && $l['ativo']; 
        });
        
        $produtos_agregados = array();
        
        foreach ($lojistas_ativos as $lojista) {
            $produtos_lojista = $this->buscar_produtos_mais_vendidos_lojista_api($lojista, $data_inicio, $data_fim);
            
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
        
        // Recalcular preço médio
        foreach ($produtos_agregados as &$produto) {
            if ($produto['quantidade_vendida'] > 0) {
                $produto['preco_medio'] = $produto['receita_total'] / $produto['quantidade_vendida'];
            }
        }
        
        // Ordenar por quantidade vendida
        uasort($produtos_agregados, function($a, $b) {
            return $b['quantidade_vendida'] - $a['quantidade_vendida'];
        });
        
        return array_slice(array_values($produtos_agregados), 0, $limit);
    }
    
    /**
     * Obter estatísticas de um lojista específico
     */
    private function get_estatisticas_lojista($lojista) {
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = date('Y-m-d');
        
        // Buscar estatísticas de vendas do lojista
        $vendas_lojista = $this->buscar_vendas_lojista_api($lojista, $data_inicio, $data_fim);
        
        // Buscar estatísticas históricas (simulando - pode ser implementado no futuro)
        $total_vendas_historico = 0;
        
        // Buscar status de vendas via API (simulando)
        $status_vendas = $this->buscar_status_vendas_lojista($lojista);
        
        return array(
            'total_vendas_mes' => $vendas_lojista['success'] ? $vendas_lojista['data']['total_vendas'] : 0,
            'total_pedidos_mes' => $vendas_lojista['success'] ? $vendas_lojista['data']['total_pedidos'] : 0,
            'produtos_vendidos_mes' => $vendas_lojista['success'] ? $vendas_lojista['data']['produtos_vendidos'] : 0,
            'total_vendas_historico' => $total_vendas_historico,
            'status_vendas' => $status_vendas,
            'taxa_conversao' => '0.4%', // Valor exemplo - pode ser calculado
            'cliente_fidelidade' => '0%' // Valor exemplo - pode ser calculado
        );
    }
    
    /**
     * Obter top produtos de um lojista específico
     */
    private function get_top_produtos_lojista($lojista, $limit = 5) {
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = date('Y-m-d');
        
        return $this->buscar_produtos_mais_vendidos_lojista_api($lojista, $data_inicio, $data_fim, $limit);
    }
    
    /**
     * Obter últimas vendas de um lojista específico
     */
    private function get_ultimas_vendas_lojista($lojista, $limit = 5) {
        return $this->buscar_ultimas_vendas_lojista_api($lojista, $limit);
    }
    
    /**
     * Buscar vendas de um lojista via API
     */
    private function buscar_vendas_lojista_api($lojista, $data_inicio, $data_fim) {
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
        
        return array(
            'success' => true, 
            'data' => array(
                'total_vendas' => $total_vendas,
                'total_pedidos' => $total_pedidos,
                'produtos_vendidos' => $produtos_vendidos
            )
        );
    }
    
    /**
     * Buscar produtos mais vendidos de um lojista via API
     */
    private function buscar_produtos_mais_vendidos_lojista_api($lojista, $data_inicio, $data_fim, $limit = 10) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array();
        }
        
        // Primeiro, buscar pedidos do período
        $vendas = $this->buscar_vendas_detalhadas_lojista_api($lojista, $data_inicio, $data_fim);
        
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
        
        // Ordenar por quantidade vendida
        uasort($produtos_agregados, function($a, $b) {
            return $b['quantidade_vendida'] - $a['quantidade_vendida'];
        });
        
        return array_slice(array_values($produtos_agregados), 0, $limit);
    }
    
    /**
     * Buscar vendas detalhadas de um lojista via API
     */
    private function buscar_vendas_detalhadas_lojista_api($lojista, $data_inicio, $data_fim) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array('success' => false, 'message' => 'Dados de conexão incompletos');
        }
        
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
     * Buscar últimas vendas de um lojista
     */
    private function buscar_ultimas_vendas_lojista_api($lojista, $limit = 5) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array();
        }
        
        $url = rtrim($lojista['url'], '/') . '/wp-json/wc/v3/orders';
        
        $params = array(
            'status' => 'completed,processing,on-hold',
            'per_page' => $limit,
            'orderby' => 'date',
            'order' => 'desc'
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
            return array();
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code !== 200) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $pedidos = json_decode($body, true);
        
        if (!is_array($pedidos)) {
            return array();
        }
        
        $ultimas_vendas = array();
        
        foreach ($pedidos as $pedido) {
            $ultimas_vendas[] = array(
                'id' => $pedido['id'] ?? 0,
                'data' => $pedido['date_created'] ?? '',
                'total' => floatval($pedido['total'] ?? 0),
                'status' => $pedido['status'] ?? '',
                'customer_name' => $pedido['billing']['first_name'] . ' ' . $pedido['billing']['last_name']
            );
        }
        
        return $ultimas_vendas;
    }
    
    /**
     * Buscar status de vendas de um lojista
     */
    private function buscar_status_vendas_lojista($lojista) {
        // Retorna valores simulados - pode ser implementado futuramente
        return array(
            'vendas_pendentes' => 0,
            'vendas_processando' => 0,
            'vendas_concluidas' => 0,
            'vendas_canceladas' => 0,
            'vendas_reembolsadas' => 0
        );
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
