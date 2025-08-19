<?php
/**
 * Endpoints para integração com Plugin Master das Fábricas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Master_API {
    
    private $token_option = 'sincronizador_wc_master_token';
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_master_routes'));
        // Gerar token automaticamente se não existe
        if (!get_option($this->token_option)) {
            $this->generate_access_token();
        }
    }
    
    public function register_master_routes() {
        // Endpoint principal para o painel master
        register_rest_route('sincronizador-wc/v1', '/master/fabrica-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_fabrica_status'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint para dados detalhados dos lojistas
        register_rest_route('sincronizador-wc/v1', '/master/lojistas-detalhado', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_lojistas_detalhado'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Endpoint para produtos mais vendidos
        register_rest_route('sincronizador-wc/v1', '/master/produtos-top', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_produtos_top'),
            'permission_callback' => array($this, 'check_master_permissions'),
            'args' => array(
                'limit' => array(
                    'default' => 20,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 100;
                    }
                ),
                'period' => array(
                    'default' => '30',
                    'validate_callback' => function($param) {
                        return in_array($param, array('7', '30', '90', '365', 'all'));
                    }
                )
            )
        ));
        
        // Endpoint para relatório de performance
        register_rest_route('sincronizador-wc/v1', '/master/performance', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_performance_report'),
            'permission_callback' => array($this, 'check_master_permissions'),
            'args' => array(
                'period' => array(
                    'default' => '30',
                    'validate_callback' => function($param) {
                        return in_array($param, array('7', '30', '90', '365'));
                    }
                )
            )
        ));
        
        // Endpoint para sincronização forçada
        register_rest_route('sincronizador-wc/v1', '/master/force-sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'force_sync'),
            'permission_callback' => array($this, 'check_master_permissions'),
            'args' => array(
                'lojista_id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'type' => array(
                    'default' => 'all',
                    'validate_callback' => function($param) {
                        return in_array($param, array('all', 'sales', 'products', 'status'));
                    }
                )
            )
        ));
        
        // Endpoint para health check
        register_rest_route('sincronizador-wc/v1', '/master/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'health_check'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
        
        // Webhook para receber atualizações do master
        register_rest_route('sincronizador-wc/v1', '/master/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'master_webhook'),
            'permission_callback' => array($this, 'check_master_permissions')
        ));
    }
    
    /**
     * Verificação de permissões via token
     */
    public function check_master_permissions($request) {
        $auth_header = $request->get_header('Authorization');
        
        if (!$auth_header) {
            // Tentar token via query param como fallback
            $token = $request->get_param('token');
        } else {
            $token = str_replace(array('Bearer ', 'Token '), '', $auth_header);
        }
        
        if (!$token) {
            return new WP_Error('no_token', 'Token de autenticação necessário', array('status' => 401));
        }
        
        $valid_token = get_option($this->token_option);
        
        if (!$valid_token || $token !== $valid_token) {
            return new WP_Error('invalid_token', 'Token inválido', array('status' => 403));
        }
        
        return true;
    }
    
    /**
     * Status geral da fábrica
     */
    public function get_fabrica_status($request) {
        // Verificar cache
        $cached_data = $this->cache->get('master_fabrica_status');
        if ($cached_data !== false) {
            return rest_ensure_response($cached_data);
        }
        
        global $wpdb;
        
        // Dados básicos da fábrica
        $fabrica_info = array(
            'nome_fabrica' => get_bloginfo('name'),
            'url_fabrica' => home_url(),
            'versao_plugin' => SINCRONIZADOR_WC_VERSION,
            'ultimo_update' => current_time('c'),
            'timezone' => wp_timezone_string()
        );
        
        // Estatísticas dos lojistas
        $lojistas_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_lojistas,
                SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as lojistas_ativos,
                SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as lojistas_inativos,
                SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) as lojistas_erro,
                AVG(CASE WHEN ultima_sincronizacao IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, ultima_sincronizacao, NOW()) 
                    ELSE NULL END) as horas_desde_ultima_sync
            FROM {$wpdb->prefix}sincronizador_lojistas"
        );
        
        // Produtos sincronizados
        $produtos_stats = $wpdb->get_row(
            "SELECT 
                COUNT(DISTINCT produto_id_fabrica) as produtos_unicos_sincronizados,
                COUNT(*) as total_sincronizacoes,
                SUM(CASE WHEN status_sincronizacao = 'sincronizado' THEN 1 ELSE 0 END) as sincronizacoes_sucesso,
                SUM(CASE WHEN status_sincronizacao = 'erro' THEN 1 ELSE 0 END) as sincronizacoes_erro
            FROM {$wpdb->prefix}sincronizador_produtos"
        );
        
        // Vendas totais
        $vendas_stats = $wpdb->get_row(
            "SELECT 
                COALESCE(SUM(quantidade_vendida), 0) as total_vendas,
                COALESCE(SUM(valor_total), 0) as valor_total_vendas,
                COUNT(DISTINCT lojista_id) as lojistas_com_vendas
            FROM {$wpdb->prefix}sincronizador_vendas"
        );
        
        // Performance do último mês
        $performance_mes = $wpdb->get_row(
            "SELECT 
                COALESCE(SUM(quantidade_vendida), 0) as vendas_mes,
                COALESCE(SUM(valor_total), 0) as valor_vendas_mes,
                COUNT(DISTINCT produto_id_fabrica) as produtos_vendidos_mes
            FROM {$wpdb->prefix}sincronizador_vendas 
            WHERE data_venda >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        
        // Status de saúde do sistema
        $health_status = $this->get_system_health();
        
        $response_data = array(
            'fabrica' => $fabrica_info,
            'lojistas' => array(
                'total' => intval($lojistas_stats->total_lojistas),
                'ativos' => intval($lojistas_stats->lojistas_ativos),
                'inativos' => intval($lojistas_stats->lojistas_inativos),
                'com_erro' => intval($lojistas_stats->lojistas_erro),
                'horas_desde_ultima_sync' => round($lojistas_stats->horas_desde_ultima_sync, 1)
            ),
            'produtos' => array(
                'unicos_sincronizados' => intval($produtos_stats->produtos_unicos_sincronizados),
                'total_sincronizacoes' => intval($produtos_stats->total_sincronizacoes),
                'sucessos' => intval($produtos_stats->sincronizacoes_sucesso),
                'erros' => intval($produtos_stats->sincronizacoes_erro),
                'taxa_sucesso' => $produtos_stats->total_sincronizacoes > 0 
                    ? round(($produtos_stats->sincronizacoes_sucesso / $produtos_stats->total_sincronizacoes) * 100, 2)
                    : 0
            ),
            'vendas' => array(
                'total_unidades' => intval($vendas_stats->total_vendas),
                'valor_total' => floatval($vendas_stats->valor_total_vendas),
                'lojistas_com_vendas' => intval($vendas_stats->lojistas_com_vendas),
                'mes_atual' => array(
                    'unidades' => intval($performance_mes->vendas_mes),
                    'valor' => floatval($performance_mes->valor_vendas_mes),
                    'produtos_diferentes' => intval($performance_mes->produtos_vendidos_mes)
                )
            ),
            'sistema' => $health_status,
            'cache_info' => array(
                'cached_at' => current_time('c'),
                'expires_in' => 300 // 5 minutos
            )
        );
        
        // Cache por 5 minutos
        $this->cache->set('master_fabrica_status', $response_data, 300);
        
        return rest_ensure_response($response_data);
    }
    
    /**
     * Dados detalhados dos lojistas
     */
    public function get_lojistas_detalhado($request) {
        $cached_data = $this->cache->get('master_lojistas_detalhado');
        if ($cached_data !== false) {
            return rest_ensure_response($cached_data);
        }
        
        global $wpdb;
        
        $lojistas = $wpdb->get_results(
            "SELECT 
                l.*,
                COUNT(DISTINCT sp.produto_id_fabrica) as produtos_sincronizados,
                SUM(CASE WHEN sp.status_sincronizacao = 'sincronizado' THEN 1 ELSE 0 END) as produtos_sucesso,
                SUM(CASE WHEN sp.status_sincronizacao = 'erro' THEN 1 ELSE 0 END) as produtos_erro,
                COALESCE(SUM(sv.quantidade_vendida), 0) as total_vendas,
                COALESCE(SUM(sv.valor_total), 0) as valor_total_vendas,
                MAX(sv.data_venda) as ultima_venda,
                COUNT(DISTINCT sv.produto_id_fabrica) as produtos_com_vendas
            FROM {$wpdb->prefix}sincronizador_lojistas l
            LEFT JOIN {$wpdb->prefix}sincronizador_produtos sp ON l.id = sp.lojista_id
            LEFT JOIN {$wpdb->prefix}sincronizador_vendas sv ON l.id = sv.lojista_id
            GROUP BY l.id
            ORDER BY valor_total_vendas DESC"
        );
        
        $lojistas_detalhado = array();
        
        foreach ($lojistas as $lojista) {
            // Performance do último mês
            $performance_mes = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COALESCE(SUM(quantidade_vendida), 0) as vendas_mes,
                    COALESCE(SUM(valor_total), 0) as valor_vendas_mes
                FROM {$wpdb->prefix}sincronizador_vendas 
                WHERE lojista_id = %d 
                AND data_venda >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                $lojista->id
            ));
            
            // Top 5 produtos do lojista
            $top_produtos = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    sv.sku,
                    p.post_title as nome_produto,
                    SUM(sv.quantidade_vendida) as total_vendido,
                    SUM(sv.valor_total) as valor_total
                FROM {$wpdb->prefix}sincronizador_vendas sv
                LEFT JOIN {$wpdb->posts} p ON sv.produto_id_fabrica = p.ID
                WHERE sv.lojista_id = %d
                GROUP BY sv.sku
                ORDER BY total_vendido DESC
                LIMIT 5",
                $lojista->id
            ));
            
            $lojistas_detalhado[] = array(
                'id' => intval($lojista->id),
                'nome' => $lojista->nome,
                'url_loja' => $lojista->url_loja,
                'status' => $lojista->status,
                'data_cadastro' => $lojista->data_cadastro,
                'ultima_sincronizacao' => $lojista->ultima_sincronizacao,
                'produtos' => array(
                    'sincronizados' => intval($lojista->produtos_sincronizados),
                    'sucessos' => intval($lojista->produtos_sucesso),
                    'erros' => intval($lojista->produtos_erro),
                    'taxa_sucesso' => $lojista->produtos_sincronizados > 0 
                        ? round(($lojista->produtos_sucesso / $lojista->produtos_sincronizados) * 100, 2)
                        : 0
                ),
                'vendas' => array(
                    'total_unidades' => intval($lojista->total_vendas),
                    'valor_total' => floatval($lojista->valor_total_vendas),
                    'produtos_com_vendas' => intval($lojista->produtos_com_vendas),
                    'ultima_venda' => $lojista->ultima_venda,
                    'mes_atual' => array(
                        'unidades' => intval($performance_mes->vendas_mes),
                        'valor' => floatval($performance_mes->valor_vendas_mes)
                    )
                ),
                'top_produtos' => $top_produtos,
                'configuracao' => array(
                    'consumer_key' => substr($lojista->consumer_key, 0, 8) . '...',
                    'url_api' => $lojista->url_loja . '/wp-json/wc/v3/',
                    'ultima_conexao' => $lojista->ultima_verificacao_conexao,
                    'status_conexao' => $lojista->status_conexao
                )
            );
        }
        
        // Cache por 10 minutos
        $this->cache->set('master_lojistas_detalhado', $lojistas_detalhado, 600);
        
        return rest_ensure_response($lojistas_detalhado);
    }
    
    /**
     * Produtos mais vendidos
     */
    public function get_produtos_top($request) {
        $limit = $request['limit'];
        $period = $request['period'];
        
        $cache_key = "master_produtos_top_{$limit}_{$period}";
        $cached_data = $this->cache->get($cache_key);
        if ($cached_data !== false) {
            return rest_ensure_response($cached_data);
        }
        
        global $wpdb;
        
        $date_filter = '';
        if ($period !== 'all') {
            $date_filter = $wpdb->prepare("WHERE sv.data_venda >= DATE_SUB(CURDATE(), INTERVAL %d DAY)", intval($period));
        }
        
        $produtos_top = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                sv.sku,
                p.post_title as nome_produto,
                p.post_excerpt as descricao_curta,
                pm.meta_value as preco_regular,
                pm2.meta_value as imagem_id,
                SUM(sv.quantidade_vendida) as total_vendido,
                SUM(sv.valor_total) as valor_total,
                COUNT(DISTINCT sv.lojista_id) as lojistas_venderam,
                AVG(sv.preco_unitario) as preco_medio_venda,
                MAX(sv.data_venda) as ultima_venda
            FROM {$wpdb->prefix}sincronizador_vendas sv
            LEFT JOIN {$wpdb->posts} p ON sv.produto_id_fabrica = p.ID
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_regular_price'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_thumbnail_id'
            {$date_filter}
            GROUP BY sv.sku
            ORDER BY total_vendido DESC
            LIMIT %d",
            $limit
        ));
        
        foreach ($produtos_top as &$produto) {
            // Buscar URL da imagem
            if ($produto->imagem_id) {
                $produto->imagem_url = wp_get_attachment_image_url($produto->imagem_id, 'medium');
            } else {
                $produto->imagem_url = wc_placeholder_img_src('medium');
            }
            
            // Converter tipos
            $produto->total_vendido = intval($produto->total_vendido);
            $produto->valor_total = floatval($produto->valor_total);
            $produto->lojistas_venderam = intval($produto->lojistas_venderam);
            $produto->preco_regular = floatval($produto->preco_regular);
            $produto->preco_medio_venda = floatval($produto->preco_medio_venda);
        }
        
        // Cache por 15 minutos
        $this->cache->set($cache_key, $produtos_top, 900);
        
        return rest_ensure_response($produtos_top);
    }
    
    /**
     * Relatório de performance
     */
    public function get_performance_report($request) {
        $period = $request['period'];
        
        $cache_key = "master_performance_{$period}";
        $cached_data = $this->cache->get($cache_key);
        if ($cached_data !== false) {
            return rest_ensure_response($cached_data);
        }
        
        global $wpdb;
        
        // Performance por período
        $performance_data = array();
        
        // Dados por dia nos últimos X dias
        $daily_performance = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(data_venda) as data,
                SUM(quantidade_vendida) as vendas,
                SUM(valor_total) as valor,
                COUNT(DISTINCT lojista_id) as lojistas_ativos,
                COUNT(DISTINCT produto_id_fabrica) as produtos_vendidos
            FROM {$wpdb->prefix}sincronizador_vendas 
            WHERE data_venda >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY DATE(data_venda)
            ORDER BY data DESC",
            intval($period)
        ));
        
        // Comparação com período anterior
        $periodo_anterior = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(quantidade_vendida) as vendas_anterior,
                SUM(valor_total) as valor_anterior
            FROM {$wpdb->prefix}sincronizador_vendas 
            WHERE data_venda >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            AND data_venda < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            intval($period * 2),
            intval($period)
        ));
        
        $vendas_atual = array_sum(array_column($daily_performance, 'vendas'));
        $valor_atual = array_sum(array_column($daily_performance, 'valor'));
        
        $crescimento_vendas = 0;
        $crescimento_valor = 0;
        
        if ($periodo_anterior && $periodo_anterior->vendas_anterior > 0) {
            $crescimento_vendas = (($vendas_atual - $periodo_anterior->vendas_anterior) / $periodo_anterior->vendas_anterior) * 100;
        }
        
        if ($periodo_anterior && $periodo_anterior->valor_anterior > 0) {
            $crescimento_valor = (($valor_atual - $periodo_anterior->valor_anterior) / $periodo_anterior->valor_anterior) * 100;
        }
        
        $performance_report = array(
            'periodo_dias' => intval($period),
            'resumo' => array(
                'total_vendas' => intval($vendas_atual),
                'valor_total' => floatval($valor_atual),
                'crescimento_vendas_percent' => round($crescimento_vendas, 2),
                'crescimento_valor_percent' => round($crescimento_valor, 2),
                'media_diaria_vendas' => round($vendas_atual / intval($period), 2),
                'media_diaria_valor' => round($valor_atual / intval($period), 2)
            ),
            'performance_diaria' => $daily_performance,
            'gerado_em' => current_time('c')
        );
        
        // Cache por 1 hora
        $this->cache->set($cache_key, $performance_report, 3600);
        
        return rest_ensure_response($performance_report);
    }
    
    /**
     * Força sincronização
     */
    public function force_sync($request) {
        $lojista_id = $request->get_param('lojista_id');
        $type = $request['type'];
        
        $sync_manager = new Sincronizador_WC_Sync_Manager();
        
        if ($lojista_id) {
            // Sincronizar lojista específico
            $result = $sync_manager->sync_lojista($lojista_id, $type);
        } else {
            // Sincronizar todos
            $result = $sync_manager->force_sync_all($type);
        }
        
        // Limpar cache relacionado
        $this->cache->clear_cache_pattern('master_');
        
        return rest_ensure_response($result);
    }
    
    /**
     * Health check do sistema
     */
    public function health_check($request) {
        $health = $this->get_system_health();
        return rest_ensure_response($health);
    }
    
    /**
     * Webhook para receber dados do master
     */
    public function master_webhook($request) {
        $body = $request->get_json_params();
        
        if (!isset($body['action'])) {
            return new WP_Error('invalid_webhook', 'Ação não especificada', array('status' => 400));
        }
        
        $response = array('success' => false);
        
        switch ($body['action']) {
            case 'clear_cache':
                $this->cache->clear_all_cache();
                $response = array('success' => true, 'message' => 'Cache limpo');
                break;
                
            case 'update_lojista_status':
                if (isset($body['lojista_id']) && isset($body['status'])) {
                    $lojista_manager = new Sincronizador_WC_Lojista_Manager();
                    $result = $lojista_manager->update_lojista_status($body['lojista_id'], $body['status']);
                    $response = array('success' => $result, 'message' => $result ? 'Status atualizado' : 'Erro ao atualizar');
                }
                break;
                
            case 'force_product_sync':
                if (isset($body['produto_id']) && isset($body['lojista_id'])) {
                    $product_importer = new Sincronizador_WC_Product_Importer();
                    $result = $product_importer->import_product_to_lojistas($body['produto_id'], array($body['lojista_id']));
                    $response = array('success' => !empty($result[0]['success']), 'result' => $result);
                }
                break;
        }
        
        return rest_ensure_response($response);
    }
    
    /**
     * Verifica saúde do sistema
     */
    private function get_system_health() {
        global $wpdb;
        
        $health = array(
            'status' => 'ok',
            'checks' => array(),
            'score' => 100
        );
        
        // Verificar conexão com banco
        try {
            $wpdb->get_var("SELECT 1");
            $health['checks']['database'] = array('status' => 'ok', 'message' => 'Conexão OK');
        } catch (Exception $e) {
            $health['checks']['database'] = array('status' => 'error', 'message' => 'Erro de conexão');
            $health['score'] -= 30;
        }
        
        // Verificar se WooCommerce está ativo
        if (class_exists('WooCommerce')) {
            $health['checks']['woocommerce'] = array('status' => 'ok', 'message' => 'WooCommerce ativo');
        } else {
            $health['checks']['woocommerce'] = array('status' => 'error', 'message' => 'WooCommerce não encontrado');
            $health['score'] -= 40;
        }
        
        // Verificar cron jobs
        $next_sync = wp_next_scheduled('sincronizador_wc_sync_cron');
        if ($next_sync) {
            $health['checks']['cron'] = array('status' => 'ok', 'message' => 'Próxima sincronização: ' . date('Y-m-d H:i:s', $next_sync));
        } else {
            $health['checks']['cron'] = array('status' => 'warning', 'message' => 'Cron não agendado');
            $health['score'] -= 10;
        }
        
        // Verificar lojistas com erro
        $lojistas_erro = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_lojistas WHERE status = 'erro'");
        if ($lojistas_erro > 0) {
            $health['checks']['lojistas'] = array('status' => 'warning', 'message' => "{$lojistas_erro} lojista(s) com erro");
            $health['score'] -= ($lojistas_erro * 5);
        } else {
            $health['checks']['lojistas'] = array('status' => 'ok', 'message' => 'Todos os lojistas OK');
        }
        
        // Status geral
        if ($health['score'] >= 90) {
            $health['status'] = 'excellent';
        } elseif ($health['score'] >= 70) {
            $health['status'] = 'good';
        } elseif ($health['score'] >= 50) {
            $health['status'] = 'warning';
        } else {
            $health['status'] = 'critical';
        }
        
        return $health;
    }
    
    /**
     * Gera ou atualiza token de acesso
     */
    public function generate_access_token() {
        $token = wp_generate_password(32, false);
        update_option($this->token_option, $token);
        return $token;
    }
    
    /**
     * Obtém token atual
     */
    public function get_access_token() {
        return get_option($this->token_option);
    }
}
