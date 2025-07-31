<?php
/**
 * Classe para gerenciar sincronização automática
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Sync_Manager {
    
    private $api_handler;
    private $lojista_manager;
    
    public function __construct() {
        $this->api_handler = new Sincronizador_WC_API_Handler();
        $this->lojista_manager = new Sincronizador_WC_Lojista_Manager();
        
        // Agendar tarefas automáticas
        add_action('init', array($this, 'schedule_sync_tasks'));
        add_action('sincronizador_wc_sync_vendas', array($this, 'sync_all_sales_data'));
        add_action('sincronizador_wc_sync_produtos', array($this, 'sync_products_status'));
    }
    
    /**
     * Agenda tarefas de sincronização
     */
    public function schedule_sync_tasks() {
        // Sincronizar dados de vendas diariamente
        if (!wp_next_scheduled('sincronizador_wc_sync_vendas')) {
            wp_schedule_event(time(), 'daily', 'sincronizador_wc_sync_vendas');
        }
        
        // Sincronizar status de produtos a cada 6 horas
        if (!wp_next_scheduled('sincronizador_wc_sync_produtos')) {
            wp_schedule_event(time(), 'twicedaily', 'sincronizador_wc_sync_produtos');
        }
    }
    
    /**
     * Sincroniza dados de vendas de todos os lojistas
     */
    public function sync_all_sales_data() {
        $lojistas = $this->lojista_manager->get_lojistas('ativo');
        
        foreach ($lojistas as $lojista) {
            $this->sync_lojista_sales($lojista->id);
        }
    }
    
    /**
     * Sincroniza dados de vendas de um lojista específico
     */
    public function sync_lojista_sales($lojista_id, $date_from = null, $date_to = null) {
        try {
            // Definir período padrão (último mês)
            if (!$date_from) {
                $date_from = date('Y-m-01', strtotime('-1 month'));
            }
            
            if (!$date_to) {
                $date_to = date('Y-m-t', strtotime('-1 month'));
            }
            
            // Obter dados de vendas via API
            $result = $this->api_handler->get_sales_data($lojista_id, $date_from, $date_to);
            
            if (!$result['success']) {
                throw new Exception($result['message']);
            }
            
            // Processar e salvar dados
            $this->save_sales_data($result['data']);
            
            // Atualizar timestamp da última sincronização
            $this->lojista_manager->update_last_sync($lojista_id);
            
            // Log de sucesso
            $this->log_operation($lojista_id, 'sincronizacao', 
                "Sincronização de vendas concluída para período: {$date_from} - {$date_to}");
            
            return array('success' => true, 'message' => 'Sincronização concluída com sucesso');
            
        } catch (Exception $e) {
            // Log de erro
            $this->log_operation($lojista_id, 'erro', 
                "Erro na sincronização de vendas: {$e->getMessage()}");
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Salva dados de vendas no banco
     */
    private function save_sales_data($sales_data) {
        global $wpdb;
        
        foreach ($sales_data as $sale) {
            // Verificar se já existe registro para este período
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}sincronizador_vendas 
                WHERE lojista_id = %d AND sku = %s AND periodo_referencia = %s
            ", $sale['lojista_id'], $sale['sku'], $sale['periodo_referencia']));
            
            if ($existing) {
                // Atualizar registro existente
                $wpdb->update(
                    $wpdb->prefix . 'sincronizador_vendas',
                    array(
                        'quantidade_vendida' => $sale['quantidade_vendida'],
                        'valor_total' => $sale['valor_total'],
                        'data_venda' => $sale['data_venda'],
                        'data_sincronizacao' => current_time('mysql')
                    ),
                    array('id' => $existing)
                );
            } else {
                // Inserir novo registro
                $wpdb->insert(
                    $wpdb->prefix . 'sincronizador_vendas',
                    $sale
                );
            }
        }
    }
    
    /**
     * Sincroniza status de produtos em todos os lojistas
     */
    public function sync_products_status() {
        global $wpdb;
        
        // Obter produtos que precisam ser verificados
        $products = $wpdb->get_results("
            SELECT DISTINCT produto_id_fabrica, sku
            FROM {$wpdb->prefix}sincronizador_produtos 
            WHERE status_sincronizacao = 'sincronizado'
        ");
        
        foreach ($products as $product) {
            $this->check_product_status_in_lojistas($product->produto_id_fabrica, $product->sku);
        }
    }
    
    /**
     * Verifica status de um produto em todos os lojistas
     */
    private function check_product_status_in_lojistas($produto_id_fabrica, $sku) {
        $lojistas = $this->lojista_manager->get_lojistas('ativo');
        
        foreach ($lojistas as $lojista) {
            $this->check_product_in_lojista($lojista->id, $produto_id_fabrica, $sku);
        }
    }
    
    /**
     * Verifica se produto existe em um lojista específico
     */
    private function check_product_in_lojista($lojista_id, $produto_id_fabrica, $sku) {
        try {
            $client = $this->api_handler->get_api_client($lojista_id);
            $products = $client->get('products', ['sku' => $sku]);
            
            global $wpdb;
            
            if (empty($products)) {
                // Produto não encontrado no lojista
                $wpdb->update(
                    $wpdb->prefix . 'sincronizador_produtos',
                    array(
                        'status_sincronizacao' => 'erro',
                        'erro_mensagem' => 'Produto não encontrado no lojista',
                        'data_ultima_sincronizacao' => current_time('mysql')
                    ),
                    array(
                        'lojista_id' => $lojista_id,
                        'produto_id_fabrica' => $produto_id_fabrica
                    )
                );
            } else {
                // Produto encontrado, atualizar dados
                $product = $products[0];
                
                $wpdb->update(
                    $wpdb->prefix . 'sincronizador_produtos',
                    array(
                        'produto_id_lojista' => $product->id,
                        'status_sincronizacao' => 'sincronizado',
                        'erro_mensagem' => null,
                        'data_ultima_sincronizacao' => current_time('mysql')
                    ),
                    array(
                        'lojista_id' => $lojista_id,
                        'produto_id_fabrica' => $produto_id_fabrica
                    )
                );
            }
            
        } catch (Exception $e) {
            // Log do erro sem interromper o processo
            $this->log_operation($lojista_id, 'erro', 
                "Erro ao verificar produto {$sku}: {$e->getMessage()}");
        }
    }
    
    /**
     * Obtém relatório de vendas por período
     */
    public function get_sales_report($date_from = null, $date_to = null, $lojista_id = null) {
        global $wpdb;
        
        $where_conditions = array();
        $params = array();
        
        if ($date_from && $date_to) {
            $periodo_from = date('Y-m', strtotime($date_from));
            $periodo_to = date('Y-m', strtotime($date_to));
            
            $where_conditions[] = "sv.periodo_referencia BETWEEN %s AND %s";
            $params[] = $periodo_from;
            $params[] = $periodo_to;
        }
        
        if ($lojista_id) {
            $where_conditions[] = "sv.lojista_id = %d";
            $params[] = $lojista_id;
        }
        
        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }
        
        $query = "
            SELECT 
                l.nome as lojista_nome,
                sv.sku,
                p.post_title as produto_nome,
                SUM(sv.quantidade_vendida) as total_quantidade,
                SUM(sv.valor_total) as total_valor,
                sv.periodo_referencia
            FROM {$wpdb->prefix}sincronizador_vendas sv
            INNER JOIN {$wpdb->prefix}sincronizador_lojistas l ON sv.lojista_id = l.id
            LEFT JOIN {$wpdb->posts} p ON sv.produto_id_fabrica = p.ID
            {$where_clause}
            GROUP BY sv.lojista_id, sv.sku, sv.periodo_referencia
            ORDER BY sv.periodo_referencia DESC, l.nome ASC
        ";
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Obtém resumo de vendas por lojista
     */
    public function get_lojistas_summary($periodo = null) {
        global $wpdb;
        
        $where_clause = '';
        $params = array();
        
        if ($periodo) {
            $where_clause = 'WHERE sv.periodo_referencia = %s';
            $params[] = $periodo;
        } else {
            // Período atual por padrão
            $where_clause = 'WHERE sv.periodo_referencia = %s';
            $params[] = date('Y-m');
        }
        
        $query = "
            SELECT 
                l.nome as lojista_nome,
                l.status,
                l.ultima_sincronizacao,
                COUNT(DISTINCT sv.sku) as produtos_vendidos,
                SUM(sv.quantidade_vendida) as total_quantidade,
                SUM(sv.valor_total) as total_valor
            FROM {$wpdb->prefix}sincronizador_lojistas l
            LEFT JOIN {$wpdb->prefix}sincronizador_vendas sv ON l.id = sv.lojista_id
            {$where_clause}
            GROUP BY l.id
            ORDER BY total_valor DESC
        ";
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Force sincronização manual
     */
    public function force_sync($lojista_id = null) {
        if ($lojista_id) {
            return $this->sync_lojista_sales($lojista_id);
        } else {
            $this->sync_all_sales_data();
            return array('success' => true, 'message' => 'Sincronização geral iniciada');
        }
    }
    
    /**
     * Obtém logs de sincronização
     */
    public function get_sync_logs($lojista_id = null, $limit = 100) {
        global $wpdb;
        
        $where_clause = '';
        $params = array();
        
        if ($lojista_id) {
            $where_clause = 'WHERE sl.lojista_id = %d';
            $params[] = $lojista_id;
        }
        
        $query = "
            SELECT 
                sl.*,
                l.nome as lojista_nome
            FROM {$wpdb->prefix}sincronizador_logs sl
            LEFT JOIN {$wpdb->prefix}sincronizador_lojistas l ON sl.lojista_id = l.id
            {$where_clause}
            ORDER BY sl.data_criacao DESC
            LIMIT %d
        ";
        
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($query, $params));
    }
    
    /**
     * Registra operação no log
     */
    private function log_operation($lojista_id, $tipo, $detalhes) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'sincronizador_logs',
            array(
                'lojista_id' => $lojista_id,
                'tipo' => $tipo,
                'acao' => substr($detalhes, 0, 100),
                'detalhes' => $detalhes,
                'data_criacao' => current_time('mysql')
            )
        );
    }
}
