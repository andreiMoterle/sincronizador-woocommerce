<?php
/**
 * Classe para gerenciar chamadas da API WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_API_Handler {
    
    private $lojista_data;
    
    public function __construct($lojista_data = null) {
        $this->lojista_data = $lojista_data;
    }
    
    /**
     * Conecta à API do lojista
     */
    private function get_api_client($lojista_id = null) {
        if ($lojista_id) {
            global $wpdb;
            $lojista = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
                $lojista_id
            ));
            
            if (!$lojista) {
                throw new Exception(__('Lojista não encontrado', 'sincronizador-wc'));
            }
            
            $this->lojista_data = $lojista;
        }
        
        if (!$this->lojista_data) {
            throw new Exception(__('Dados do lojista não fornecidos', 'sincronizador-wc'));
        }
        
        require_once SINCRONIZADOR_WC_PLUGIN_DIR . 'vendor/autoload.php';
        
        return new Automattic\WooCommerce\Client(
            $this->lojista_data->url_loja,
            $this->lojista_data->consumer_key,
            $this->lojista_data->consumer_secret,
            [
                'wp_api' => true,
                'version' => 'wc/v3',
                'timeout' => 30
            ]
        );
    }
    
    /**
     * Testa conexão com a API do lojista
     */
    public function test_connection($lojista_id) {
        try {
            $client = $this->get_api_client($lojista_id);
            $response = $client->get('system_status');
            
            if (isset($response->environment)) {
                return array('success' => true, 'message' => __('Conexão estabelecida com sucesso', 'sincronizador-wc'));
            }
            
            return array('success' => false, 'message' => __('Resposta inválida da API', 'sincronizador-wc'));
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Envia produto para o lojista
     */
    public function send_product_to_lojista($produto_id, $lojista_id) {
        try {
            $client = $this->get_api_client($lojista_id);
            
            // Obter dados do produto da fábrica
            $produto = wc_get_product($produto_id);
            
            if (!$produto) {
                throw new Exception(__('Produto não encontrado', 'sincronizador-wc'));
            }
            
            // Preparar dados do produto
            $product_data = $this->prepare_product_data($produto);
            
            // Verificar se produto já existe no lojista (por SKU)
            $existing_products = $client->get('products', ['sku' => $produto->get_sku()]);
            
            if (!empty($existing_products)) {
                // Atualizar produto existente
                $response = $client->put('products/' . $existing_products[0]->id, $product_data);
                $action = 'updated';
            } else {
                // Criar novo produto
                $response = $client->post('products', $product_data);
                $action = 'created';
            }
            
            // Registrar na tabela de sincronização
            $this->update_sync_record($lojista_id, $produto_id, $response->id, $produto->get_sku(), 'sincronizado');
            
            // Log da operação
            $this->log_operation($lojista_id, 'importacao', "Produto {$action}: {$produto->get_name()} (SKU: {$produto->get_sku()})");
            
            return array(
                'success' => true,
                'message' => sprintf(__('Produto %s com sucesso', 'sincronizador-wc'), $action === 'created' ? 'criado' : 'atualizado'),
                'product_id' => $response->id
            );
            
        } catch (Exception $e) {
            // Registrar erro na sincronização
            $this->update_sync_record($lojista_id, $produto_id, null, $produto->get_sku(), 'erro', $e->getMessage());
            
            // Log do erro
            $this->log_operation($lojista_id, 'erro', "Erro ao enviar produto: {$e->getMessage()}");
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Prepara dados do produto para envio
     */
    private function prepare_product_data($produto) {
        $data = array(
            'name' => $produto->get_name(),
            'type' => $produto->get_type(),
            'regular_price' => $produto->get_regular_price(),
            'sale_price' => $produto->get_sale_price(),
            'description' => $produto->get_description(),
            'short_description' => $produto->get_short_description(),
            'sku' => $produto->get_sku(),
            'manage_stock' => $produto->get_manage_stock(),
            'stock_quantity' => $produto->get_stock_quantity(),
            'in_stock' => $produto->is_in_stock(),
            'weight' => $produto->get_weight(),
            'dimensions' => array(
                'length' => $produto->get_length(),
                'width' => $produto->get_width(),
                'height' => $produto->get_height()
            ),
            'categories' => $this->get_product_categories($produto),
            'images' => $this->get_product_images($produto),
            'attributes' => $this->get_product_attributes($produto),
            'meta_data' => array(
                array(
                    'key' => '_sincronizado_fabrica',
                    'value' => 'sim'
                ),
                array(
                    'key' => '_produto_id_fabrica',
                    'value' => $produto->get_id()
                )
            )
        );
        
        // Se for produto variável, incluir variações
        if ($produto->is_type('variable')) {
            $data['variations'] = $this->get_product_variations($produto);
        }
        
        return $data;
    }
    
    /**
     * Obtém categorias do produto
     */
    private function get_product_categories($produto) {
        $categories = array();
        $terms = wp_get_post_terms($produto->get_id(), 'product_cat');
        
        foreach ($terms as $term) {
            $categories[] = array(
                'name' => $term->name,
                'slug' => $term->slug
            );
        }
        
        return $categories;
    }
    
    /**
     * Obtém imagens do produto
     */
    private function get_product_images($produto) {
        $images = array();
        
        // Imagem principal
        $image_id = $produto->get_image_id();
        if ($image_id) {
            $images[] = array(
                'src' => wp_get_attachment_url($image_id),
                'name' => get_the_title($image_id),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
            );
        }
        
        // Galeria
        $gallery_ids = $produto->get_gallery_image_ids();
        foreach ($gallery_ids as $gallery_id) {
            $images[] = array(
                'src' => wp_get_attachment_url($gallery_id),
                'name' => get_the_title($gallery_id),
                'alt' => get_post_meta($gallery_id, '_wp_attachment_image_alt', true)
            );
        }
        
        return $images;
    }
    
    /**
     * Obtém atributos do produto
     */
    private function get_product_attributes($produto) {
        $attributes = array();
        $product_attributes = $produto->get_attributes();
        
        foreach ($product_attributes as $attribute) {
            $attributes[] = array(
                'name' => $attribute->get_name(),
                'options' => $attribute->get_options(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation()
            );
        }
        
        return $attributes;
    }
    
    /**
     * Obtém variações do produto
     */
    private function get_product_variations($produto) {
        $variations = array();
        $variation_ids = $produto->get_children();
        
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            if ($variation) {
                $variations[] = array(
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'sku' => $variation->get_sku(),
                    'manage_stock' => $variation->get_manage_stock(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'in_stock' => $variation->is_in_stock(),
                    'weight' => $variation->get_weight(),
                    'dimensions' => array(
                        'length' => $variation->get_length(),
                        'width' => $variation->get_width(),
                        'height' => $variation->get_height()
                    ),
                    'attributes' => $variation->get_variation_attributes(),
                    'image' => array(
                        'src' => wp_get_attachment_url($variation->get_image_id())
                    )
                );
            }
        }
        
        return $variations;
    }
    
    /**
     * Obtém dados de vendas do lojista
     */
    public function get_sales_data($lojista_id, $date_from = null, $date_to = null) {
        try {
            $client = $this->get_api_client($lojista_id);
            
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
            
            $orders = $client->get('orders', $params);
            
            return $this->process_sales_data($orders, $lojista_id);
            
        } catch (Exception $e) {
            $this->log_operation($lojista_id, 'erro', "Erro ao obter dados de vendas: {$e->getMessage()}");
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Processa dados de vendas
     */
    private function process_sales_data($orders, $lojista_id) {
        global $wpdb;
        
        $sales_data = array();
        
        foreach ($orders as $order) {
            foreach ($order->line_items as $item) {
                $sku = $item->sku;
                
                if (empty($sku)) continue;
                
                // Buscar produto da fábrica pelo SKU
                $produto_id_fabrica = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
                    $sku
                ));
                
                if (!$produto_id_fabrica) continue;
                
                $periodo = date('Y-m', strtotime($order->date_created));
                
                $sales_data[] = array(
                    'lojista_id' => $lojista_id,
                    'produto_id_fabrica' => $produto_id_fabrica,
                    'sku' => $sku,
                    'quantidade_vendida' => $item->quantity,
                    'valor_total' => $item->total,
                    'data_venda' => $order->date_created,
                    'periodo_referencia' => $periodo
                );
            }
        }
        
        return array('success' => true, 'data' => $sales_data);
    }
    
    /**
     * Atualiza registro de sincronização
     */
    private function update_sync_record($lojista_id, $produto_id_fabrica, $produto_id_lojista, $sku, $status, $erro = null) {
        global $wpdb;
        
        $data = array(
            'lojista_id' => $lojista_id,
            'produto_id_fabrica' => $produto_id_fabrica,
            'produto_id_lojista' => $produto_id_lojista,
            'sku' => $sku,
            'status_sincronizacao' => $status,
            'data_ultima_sincronizacao' => current_time('mysql'),
            'erro_mensagem' => $erro
        );
        
        // Verificar se já existe registro
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sincronizador_produtos WHERE lojista_id = %d AND sku = %s",
            $lojista_id,
            $sku
        ));
        
        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'sincronizador_produtos',
                $data,
                array('id' => $existing)
            );
        } else {
            $wpdb->insert($wpdb->prefix . 'sincronizador_produtos', $data);
        }
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
