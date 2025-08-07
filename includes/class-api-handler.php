<?php
/**
 * Classe para gerenciar chamadas da API WooCommerce - REFATORADA
 * Agora usa as classes utilitárias para evitar duplicação
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_API_Handler {
    
    private $lojista_data;
    
    /**
     * Instâncias das classes utilitárias
     */
    private $product_utils;
    private $api_operations;
    
    public function __construct($lojista_data = null) {
        $this->lojista_data = $lojista_data;
        
        // Inicializar classes utilitárias
        $this->product_utils = Sincronizador_WC_Product_Utils::get_instance();
        $this->api_operations = Sincronizador_WC_API_Operations::get_instance();
    }
    
    /**
     * MÉTODO REMOVIDO: get_api_client()
     * 
     * Este método foi removido pois agora usamos as novas classes utilitárias:
     * - Sincronizador_WC_API_Operations para operações de API
     * - wp_remote_get/wp_remote_post nativos do WordPress
     * 
     * Isso elimina a dependência de bibliotecas externas e melhora a performance.
     */
    
    /**
     * Testa conexão com a API do lojista - REFATORADO
     * Agora usa a classe de operações de API
     */
    public function test_connection($lojista_id) {
        try {
            // Buscar dados do lojista
            $lojista = $this->get_lojista_data($lojista_id);
            
            if (!$lojista) {
                return array('success' => false, 'message' => __('Lojista não encontrado', 'sincronizador-wc'));
            }
            
            // Usar a nova classe de operações
            $result = $this->api_operations->testar_conexao_lojista($lojista);
            
            if ($result['success']) {
                return array('success' => true, 'message' => __('Conexão estabelecida com sucesso', 'sincronizador-wc'));
            } else {
                return array('success' => false, 'message' => $result['message']);
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Envia produto para o lojista - REFATORADO
     * Agora usa as novas classes utilitárias para preparar dados e enviar
     */
    public function send_product_to_lojista($produto_id, $lojista_id) {
        try {
            // Buscar dados do lojista
            $lojista = $this->get_lojista_data($lojista_id);
            
            if (!$lojista) {
                throw new Exception(__('Lojista não encontrado', 'sincronizador-wc'));
            }
            
            // Obter dados do produto da fábrica
            $produto = wc_get_product($produto_id);
            
            if (!$produto) {
                throw new Exception(__('Produto não encontrado', 'sincronizador-wc'));
            }
            
            // Usar a classe utilitária para formatar dados do produto
            $product_data = $this->product_utils->format_product_data($produto);
            
            if (!$product_data) {
                throw new Exception(__('Erro ao formatar dados do produto', 'sincronizador-wc'));
            }
            
            // Adicionar metadados específicos para rastreamento
            $product_data['meta_data'] = array(
                array(
                    'key' => '_sincronizado_fabrica',
                    'value' => 'sim'
                ),
                array(
                    'key' => '_produto_id_fabrica',
                    'value' => $produto->get_id()
                ),
                array(
                    'key' => '_data_sincronizacao',
                    'value' => current_time('mysql')
                )
            );
            
            // Verificar se produto já existe no destino
            $existing_product_id = $this->api_operations->buscar_produto_no_destino($lojista, $produto->get_sku());
            
            $response_data = null;
            $action = '';
            
            if ($existing_product_id) {
                // Atualizar produto existente
                $options = array(
                    'incluir_imagens' => true,
                    'incluir_variacoes' => true,
                    'atualizar_precos' => true,
                    'atualizar_estoque' => true
                );
                
                $success = $this->api_operations->atualizar_produto_no_destino($lojista, $existing_product_id, $product_data, $options);
                
                if ($success) {
                    $response_data = array('id' => $existing_product_id);
                    $action = 'updated';
                } else {
                    throw new Exception(__('Erro ao atualizar produto no destino', 'sincronizador-wc'));
                }
                
            } else {
                // Criar novo produto
                $options = array(
                    'incluir_imagens' => true,
                    'incluir_variacoes' => true,
                    'incluir_categorias' => false, // Não criar categorias automaticamente
                    'status' => 'publish'
                );
                
                $new_product_id = $this->api_operations->criar_produto_no_destino($lojista, $product_data, $options);
                
                if ($new_product_id) {
                    $response_data = array('id' => $new_product_id);
                    $action = 'created';
                } else {
                    throw new Exception(__('Erro ao criar produto no destino', 'sincronizador-wc'));
                }
            }
            
            // Registrar na tabela de sincronização
            $this->update_sync_record($lojista_id, $produto_id, $response_data['id'], $produto->get_sku(), 'sincronizado');
            
            // Log da operação
            $message = sprintf("Produto %s: %s (SKU: %s)", $action === 'created' ? 'criado' : 'atualizado', $produto->get_name(), $produto->get_sku());
            $this->log_operation($lojista_id, 'importacao', $message);
            
            error_log("SINCRONIZADOR WC API HANDLER: " . $message);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Produto %s com sucesso', 'sincronizador-wc'), $action === 'created' ? 'criado' : 'atualizado'),
                'product_id' => $response_data['id'],
                'action' => $action
            );
            
        } catch (Exception $e) {
            // Registrar erro na sincronização
            $this->update_sync_record($lojista_id, $produto_id, null, $produto->get_sku() ?: 'N/A', 'erro', $e->getMessage());
            
            // Log do erro
            $error_message = "Erro ao enviar produto ID {$produto_id}: {$e->getMessage()}";
            $this->log_operation($lojista_id, 'erro', $error_message);
            
            error_log("SINCRONIZADOR WC API HANDLER ERRO: " . $error_message);
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Prepara dados do produto para envio - REMOVIDO
     * Agora usa a classe utilitária Sincronizador_WC_Product_Utils::format_product_data()
     */
    private function prepare_product_data($produto) {
        // Método mantido para compatibilidade, mas delega para a classe utilitária
        return $this->product_utils->format_product_data($produto);
    }
    
    /**
     * Obtém categorias do produto - REFATORADO
     * Agora usa a classe utilitária
     */
    private function get_product_categories($produto) {
        // Método privado na classe utilitária, vamos reimplementar de forma simples
        $categories = array();
        $terms = wp_get_post_terms($produto->get_id(), 'product_cat');
        
        if (is_wp_error($terms)) {
            return $categories;
        }
        
        foreach ($terms as $term) {
            $categories[] = array(
                'name' => $term->name,
                'slug' => $term->slug
            );
        }
        
        return $categories;
    }

    /**
     * Obtém imagens do produto - REMOVIDO
     * Agora usa a classe utilitária
     */
    private function get_product_images($produto) {
        return $this->product_utils->get_product_images($produto, 'detailed');
    }

    /**
     * Obtém atributos do produto - REMOVIDO
     * Agora usa a classe utilitária
     */
    private function get_product_attributes($produto) {
        return $this->product_utils->get_product_attributes($produto);
    }

    /**
     * Obtém variações do produto - REMOVIDO
     * Agora usa a classe utilitária
     */
    private function get_product_variations($produto) {
        return $this->product_utils->get_product_variations($produto);
    }    /**
     * Busca dados do lojista - NOVO MÉTODO
     * Compatibilidade com diferentes formatos de armazenamento
     */
    private function get_lojista_data($lojista_id) {
        // Primeiro tentar buscar da opção (formato atual)
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $lojista_id) {
                return $lojista;
            }
        }
        
        // Se não encontrar, tentar buscar do banco de dados (formato antigo)
        global $wpdb;
        $table_name = $wpdb->prefix . 'sincronizador_lojistas';
        
        // Verificar se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            $lojista = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $lojista_id
            ), ARRAY_A);
            
            if ($lojista) {
                // Converter para formato esperado
                return array(
                    'id' => $lojista['id'],
                    'nome' => $lojista['nome_loja'] ?? $lojista['nome'] ?? '',
                    'url' => $lojista['url_loja'] ?? $lojista['url'] ?? '',
                    'consumer_key' => $lojista['consumer_key'] ?? '',
                    'consumer_secret' => $lojista['consumer_secret'] ?? ''
                );
            }
        }
        
        return null;
    }

    /**
     * Obtém dados de vendas do lojista - MELHORADO
     */
    public function get_sales_data($lojista_id, $date_from = null, $date_to = null) {
        try {
            // Buscar dados do lojista usando o novo método
            $lojista = $this->get_lojista_data($lojista_id);
            
            if (!$lojista) {
                throw new Exception(__('Lojista não encontrado', 'sincronizador-wc'));
            }
            
            // Testar conexão primeiro
            $connection_test = $this->api_operations->testar_conexao_lojista($lojista);
            
            if (!$connection_test['success']) {
                throw new Exception('Erro de conexão: ' . $connection_test['message']);
            }
            
            // Usar a API nativa do WordPress para fazer a requisição
            $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/orders';
            $params = array(
                'status' => 'completed',
                'per_page' => 100
            );
            
            if ($date_from) {
                $params['after'] = $date_from;
            }
            
            if ($date_to) {
                $params['before'] = $date_to;
            }
            
            $url_with_params = add_query_arg($params, $url);
            
            $response = wp_remote_get($url_with_params, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Sincronizador-WC/' . (defined('SINCRONIZADOR_WC_VERSION') ? SINCRONIZADOR_WC_VERSION : '1.0')
                )
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('Erro na requisição: ' . $response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception("Erro HTTP {$response_code} ao buscar dados de vendas");
            }
            
            $body = wp_remote_retrieve_body($response);
            $orders = json_decode($body, true);
            
            if (!$orders || !is_array($orders)) {
                throw new Exception('Resposta inválida da API de vendas');
            }
            
            return $this->process_sales_data($orders, $lojista_id);
            
        } catch (Exception $e) {
            $error_message = "Erro ao obter dados de vendas: {$e->getMessage()}";
            $this->log_operation($lojista_id, 'erro', $error_message);
            
            error_log("SINCRONIZADOR WC API HANDLER: " . $error_message);
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Processa dados de vendas - MELHORADO
     * Melhor tratamento de dados e logs
     */
    private function process_sales_data($orders, $lojista_id) {
        global $wpdb;
        
        $sales_data = array();
        $processed_count = 0;
        $skipped_count = 0;
        
        foreach ($orders as $order) {
            // Verificar se $order é array (já decodificado) ou objeto
            $order_data = is_array($order) ? $order : (array) $order;
            $line_items = $order_data['line_items'] ?? array();
            
            foreach ($line_items as $item) {
                $item_data = is_array($item) ? $item : (array) $item;
                $sku = $item_data['sku'] ?? '';
                
                if (empty($sku)) {
                    $skipped_count++;
                    continue;
                }
                
                // Buscar produto da fábrica pelo SKU
                $produto_id_fabrica = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
                    $sku
                ));
                
                if (!$produto_id_fabrica) {
                    $skipped_count++;
                    continue;
                }
                
                $order_date = $order_data['date_created'] ?? current_time('mysql');
                $periodo = date('Y-m', strtotime($order_date));
                
                $sales_data[] = array(
                    'lojista_id' => $lojista_id,
                    'produto_id_fabrica' => $produto_id_fabrica,
                    'sku' => $sku,
                    'quantidade_vendida' => intval($item_data['quantity'] ?? 0),
                    'valor_total' => floatval($item_data['total'] ?? 0),
                    'data_venda' => $order_date,
                    'periodo_referencia' => $periodo
                );
                
                $processed_count++;
            }
        }
        
        // Log do processamento
        $log_message = "Dados de vendas processados: {$processed_count} itens processados, {$skipped_count} ignorados";
        $this->log_operation($lojista_id, 'vendas', $log_message);
        error_log("SINCRONIZADOR WC API HANDLER: " . $log_message);
        
        return array(
            'success' => true, 
            'data' => $sales_data,
            'processed_count' => $processed_count,
            'skipped_count' => $skipped_count
        );
    }
    
    /**
     * Atualiza registro de sincronização - MELHORADO
     * Melhor tratamento de dados e verificação de tabelas
     */
    private function update_sync_record($lojista_id, $produto_id_fabrica, $produto_id_lojista, $sku, $status, $erro = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_produtos';
        
        // Verificar se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            error_log("SINCRONIZADOR WC API HANDLER: Tabela {$table_name} não existe. Registro de sincronização não foi salvo.");
            return false;
        }
        
        $data = array(
            'lojista_id' => intval($lojista_id),
            'produto_id_fabrica' => intval($produto_id_fabrica),
            'produto_id_lojista' => $produto_id_lojista ? intval($produto_id_lojista) : null,
            'sku' => sanitize_text_field($sku),
            'status_sincronizacao' => sanitize_text_field($status),
            'data_ultima_sincronizacao' => current_time('mysql'),
            'erro_mensagem' => $erro ? sanitize_text_field(substr($erro, 0, 500)) : null // Limitar tamanho do erro
        );
        
        // Verificar se já existe registro
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE lojista_id = %d AND sku = %s",
            $lojista_id,
            $sku
        ));
        
        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                $data,
                array('id' => $existing),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            $operation = 'atualizado';
        } else {
            $result = $wpdb->insert(
                $table_name, 
                $data,
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
            );
            
            $operation = 'criado';
        }
        
        if ($result === false) {
            error_log("SINCRONIZADOR WC API HANDLER: Erro ao salvar registro de sincronização - SKU: {$sku}, Erro: {$wpdb->last_error}");
        } else {
            error_log("SINCRONIZADOR WC API HANDLER: Registro de sincronização {$operation} - SKU: {$sku}, Status: {$status}");
        }
        
        return $result !== false;
    }
    
    /**
     * Registra operação no log - MELHORADO  
     * Melhor tratamento de dados e verificação de tabelas
     */
    private function log_operation($lojista_id, $tipo, $detalhes) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sincronizador_logs';
        
        // Verificar se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            // Se não existe, apenas logar no error_log
            error_log("SINCRONIZADOR WC API HANDLER LOG [{$tipo}]: {$detalhes}");
            return false;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'lojista_id' => intval($lojista_id),
                'tipo' => sanitize_text_field($tipo),
                'acao' => sanitize_text_field(substr($detalhes, 0, 100)),
                'detalhes' => sanitize_text_field(substr($detalhes, 0, 1000)), // Limitar tamanho
                'data_criacao' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log("SINCRONIZADOR WC API HANDLER: Erro ao salvar log - {$wpdb->last_error}");
        }
        
        // Sempre logar no error_log também
        error_log("SINCRONIZADOR WC API HANDLER LOG [{$tipo}]: {$detalhes}");
        
        return $result !== false;
    }
}
