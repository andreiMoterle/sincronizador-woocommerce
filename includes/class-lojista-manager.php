<?php
/**
 * Classe para gerenciar lojistas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Lojista_Manager {
    
    /**
     * Adiciona novo lojista
     */
    public function add_lojista($data) {
        global $wpdb;
        
        // Validar dados obrigatórios
        if (empty($data['nome']) || empty($data['url_loja']) || empty($data['consumer_key']) || empty($data['consumer_secret'])) {
            return array('success' => false, 'message' => 'Todos os campos são obrigatórios');
        }
        
        // Verificar se URL já existe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sincronizador_lojistas WHERE url_loja = %s",
            $data['url_loja']
        ));
        
        if ($existing) {
            return array('success' => false, 'message' => 'Já existe um lojista com esta URL');
        }
        
        // Testar conexão antes de salvar
        $api_handler = new Sincronizador_WC_API_Handler((object)$data);
        $test_result = $api_handler->test_connection(null);
        
        if (!$test_result['success']) {
            return array('success' => false, 'message' => 'Falha na conexão: ' . $test_result['message']);
        }
        
        // Inserir lojista
        $result = $wpdb->insert(
            $wpdb->prefix . 'sincronizador_lojistas',
            array(
                'nome' => sanitize_text_field($data['nome']),
                'url_loja' => esc_url_raw($data['url_loja']),
                'consumer_key' => sanitize_text_field($data['consumer_key']),
                'consumer_secret' => sanitize_text_field($data['consumer_secret']),
                'status' => 'ativo'
            )
        );
        
        if ($result === false) {
            return array('success' => false, 'message' => 'Erro ao salvar lojista no banco de dados');
        }
        
        // Log da operação
        $this->log_operation($wpdb->insert_id, 'info', "Lojista adicionado: {$data['nome']}");
        
        return array('success' => true, 'message' => 'Lojista adicionado com sucesso', 'id' => $wpdb->insert_id);
    }
    
    /**
     * Atualiza lojista existente
     */
    public function update_lojista($id, $data) {
        global $wpdb;
        
        // Verificar se lojista existe
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
            $id
        ));
        
        if (!$existing) {
            return array('success' => false, 'message' => 'Lojista não encontrado');
        }
        
        // Validar dados obrigatórios
        if (empty($data['nome']) || empty($data['url_loja']) || empty($data['consumer_key']) || empty($data['consumer_secret'])) {
            return array('success' => false, 'message' => 'Todos os campos são obrigatórios');
        }
        
        // Verificar se URL já existe em outro lojista
        $url_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sincronizador_lojistas WHERE url_loja = %s AND id != %d",
            $data['url_loja'],
            $id
        ));
        
        if ($url_exists) {
            return array('success' => false, 'message' => 'Já existe outro lojista com esta URL');
        }
        
        // Testar conexão se as credenciais mudaram
        if ($existing->consumer_key !== $data['consumer_key'] || 
            $existing->consumer_secret !== $data['consumer_secret'] || 
            $existing->url_loja !== $data['url_loja']) {
            
            $api_handler = new Sincronizador_WC_API_Handler((object)$data);
            $test_result = $api_handler->test_connection(null);
            
            if (!$test_result['success']) {
                return array('success' => false, 'message' => 'Falha na conexão: ' . $test_result['message']);
            }
        }
        
        // Atualizar lojista
        $result = $wpdb->update(
            $wpdb->prefix . 'sincronizador_lojistas',
            array(
                'nome' => sanitize_text_field($data['nome']),
                'url_loja' => esc_url_raw($data['url_loja']),
                'consumer_key' => sanitize_text_field($data['consumer_key']),
                'consumer_secret' => sanitize_text_field($data['consumer_secret']),
                'status' => isset($data['status']) ? $data['status'] : $existing->status
            ),
            array('id' => $id)
        );
        
        if ($result === false) {
            return array('success' => false, 'message' => 'Erro ao atualizar lojista');
        }
        
        // Log da operação
        $this->log_operation($id, 'info', "Lojista atualizado: {$data['nome']}");
        
        return array('success' => true, 'message' => 'Lojista atualizado com sucesso');
    }
    
    /**
     * Remove lojista
     */
    public function delete_lojista($id) {
        global $wpdb;
        
        // Verificar se lojista existe
        $lojista = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
            $id
        ));
        
        if (!$lojista) {
            return array('success' => false, 'message' => 'Lojista não encontrado');
        }
        
        // Remover lojista (CASCADE irá remover registros relacionados)
        $result = $wpdb->delete(
            $wpdb->prefix . 'sincronizador_lojistas',
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            return array('success' => false, 'message' => 'Erro ao remover lojista');
        }
        
        // Log da operação
        $this->log_operation($id, 'info', "Lojista removido: {$lojista->nome}");
        
        return array('success' => true, 'message' => 'Lojista removido com sucesso');
    }
    
    /**
     * Lista todos os lojistas
     */
    public function get_lojistas($status = null) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if ($status) {
            $where = 'WHERE status = %s';
            $params[] = $status;
        }
        
        $query = "SELECT * FROM {$wpdb->prefix}sincronizador_lojistas $where ORDER BY nome ASC";
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Obtém dados de um lojista específico
     */
    public function get_lojista($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Testa conexão com um lojista
     */
    public function test_connection($id) {
        $lojista = $this->get_lojista($id);
        
        if (!$lojista) {
            return array('success' => false, 'message' => 'Lojista não encontrado');
        }
        
        $api_handler = new Sincronizador_WC_API_Handler($lojista);
        return $api_handler->test_connection($id);
    }
    
    /**
     * Obtém estatísticas de um lojista
     */
    public function get_lojista_stats($lojista_id) {
        global $wpdb;
        
        $stats = array();
        
        // Total de produtos sincronizados
        $stats['produtos_sincronizados'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_produtos WHERE lojista_id = %d AND status_sincronizacao = 'sincronizado'",
            $lojista_id
        ));
        
        // Produtos com erro
        $stats['produtos_erro'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_produtos WHERE lojista_id = %d AND status_sincronizacao = 'erro'",
            $lojista_id
        ));
        
        // Vendas no mês atual
        $periodo_atual = date('Y-m');
        $stats['vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantidade_vendida) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d AND periodo_referencia = %s",
            $lojista_id,
            $periodo_atual
        ));
        
        // Valor de vendas no mês atual
        $stats['valor_vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(valor_total) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d AND periodo_referencia = %s",
            $lojista_id,
            $periodo_atual
        ));
        
        // Última sincronização
        $stats['ultima_sincronizacao'] = $wpdb->get_var($wpdb->prepare(
            "SELECT ultima_sincronizacao FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
            $lojista_id
        ));
        
        return $stats;
    }
    
    /**
     * Atualiza status do lojista
     */
    public function update_status($id, $status) {
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'sincronizador_lojistas',
            array('status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            $this->log_operation($id, 'info', "Status alterado para: $status");
            return array('success' => true, 'message' => 'Status atualizado com sucesso');
        }
        
        return array('success' => false, 'message' => 'Erro ao atualizar status');
    }
    
    /**
     * Atualiza timestamp da última sincronização
     */
    public function update_last_sync($lojista_id) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'sincronizador_lojistas',
            array('ultima_sincronizacao' => current_time('mysql')),
            array('id' => $lojista_id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Obtém relatório de produtos por lojista
     */
    public function get_products_report($lojista_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                sp.sku,
                sp.status_sincronizacao,
                sp.data_ultima_sincronizacao,
                sp.erro_mensagem,
                p.post_title as produto_nome
            FROM {$wpdb->prefix}sincronizador_produtos sp
            LEFT JOIN {$wpdb->posts} p ON sp.produto_id_fabrica = p.ID
            WHERE sp.lojista_id = %d
            ORDER BY sp.data_ultima_sincronizacao DESC
        ", $lojista_id));
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
