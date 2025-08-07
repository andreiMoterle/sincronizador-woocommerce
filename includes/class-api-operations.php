<?php
/**
 * Classe para operações de API WooCommerce
 * Centraliza todas as operações de sincronização com lojistas
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_API_Operations {
    
    /**
     * Instância singleton
     */
    private static $instance = null;
    
    /**
     * Utilitário de produtos
     */
    private $product_utils;
    
    /**
     * Retorna instância singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->product_utils = Sincronizador_WC_Product_Utils::get_instance();
    }
    
    /**
     * Busca produto no destino pelo SKU
     * 
     * @param array $lojista Dados do lojista
     * @param string $sku SKU do produto
     * @return int|false ID do produto ou false se não encontrado
     */
    public function buscar_produto_no_destino($lojista, $sku) {
        if (empty($sku) || empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return false;
        }
        
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products?sku=' . urlencode($sku);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sincronizador-WC/' . SINCRONIZADOR_WC_VERSION
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('SINCRONIZADOR WC: Erro ao buscar produto por SKU: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log("SINCRONIZADOR WC: HTTP {$response_code} ao buscar produto por SKU: {$sku}");
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body) && isset($body[0]['id'])) {
            error_log("SINCRONIZADOR WC: Produto encontrado - SKU: {$sku}, ID: {$body[0]['id']}");
            return $body[0]['id'];
        }
        
        error_log("SINCRONIZADOR WC: Produto não encontrado - SKU: {$sku}");
        return false;
    }
    
    /**
     * Cria produto no destino
     * 
     * @param array $lojista Dados do lojista
     * @param array $produto_data Dados do produto
     * @param array $options Opções de criação
     * @return int|false ID do produto criado ou false em caso de erro
     */
    public function criar_produto_no_destino($lojista, $produto_data, $options = array()) {
        $default_options = array(
            'incluir_imagens' => true,
            'incluir_variacoes' => true,
            'incluir_categorias' => false, // Por padrão não criar categorias automaticamente
            'status' => 'publish'
        );
        
        $options = wp_parse_args($options, $default_options);
        
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products';
        
        // Preparar dados do produto
        $dados_produto = $this->prepare_product_data_for_api($produto_data, $options);
        
        $response = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sincronizador-WC/' . SINCRONIZADOR_WC_VERSION
            ),
            'body' => json_encode($dados_produto)
        ));
        
        if (is_wp_error($response)) {
            error_log('SINCRONIZADOR WC: Erro ao criar produto: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code === 201) {
            $created_product = json_decode($body, true);
            if (!empty($created_product) && isset($created_product['id'])) {
                $product_id = $created_product['id'];
                error_log("SINCRONIZADOR WC: Produto criado com sucesso - ID: {$product_id}, SKU: {$produto_data['sku']}");
                
                // Se tem variações, criar elas também
                if ($options['incluir_variacoes'] && !empty($produto_data['variations'])) {
                    $this->criar_variacoes_produto($lojista, $product_id, $produto_data['variations']);
                }
                
                return $product_id;
            }
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : 'Erro desconhecido';
            error_log("SINCRONIZADOR WC: Erro HTTP {$response_code} ao criar produto: {$error_message}");
        }
        
        return false;
    }
    
    /**
     * Atualiza produto no destino
     * 
     * @param array $lojista Dados do lojista
     * @param int $produto_id ID do produto no destino
     * @param array $produto_data Dados do produto
     * @param array $options Opções de atualização
     * @return bool Sucesso da atualização
     */
    public function atualizar_produto_no_destino($lojista, $produto_id, $produto_data, $options = array()) {
        $default_options = array(
            'incluir_imagens' => true,
            'incluir_variacoes' => true,
            'atualizar_precos' => true,
            'atualizar_estoque' => true
        );
        
        $options = wp_parse_args($options, $default_options);
        
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/products/' . $produto_id;
        
        // Preparar dados apenas com campos que devem ser atualizados
        $dados_produto = $this->prepare_product_data_for_update($produto_data, $options);
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'Sincronizador-WC/' . SINCRONIZADOR_WC_VERSION
            ),
            'body' => json_encode($dados_produto)
        ));
        
        if (is_wp_error($response)) {
            error_log('SINCRONIZADOR WC: Erro ao atualizar produto: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            error_log("SINCRONIZADOR WC: Produto atualizado com sucesso - ID: {$produto_id}, SKU: {$produto_data['sku']}");
            
            // Atualizar variações se necessário
            if ($options['incluir_variacoes'] && !empty($produto_data['variations'])) {
                $this->sincronizar_variacoes_produto($lojista, $produto_id, $produto_data['variations']);
            }
            
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : 'Erro desconhecido';
            error_log("SINCRONIZADOR WC: Erro HTTP {$response_code} ao atualizar produto: {$error_message}");
        }
        
        return false;
    }
    
    /**
     * Testa conexão com lojista
     * 
     * @param array $lojista Dados do lojista
     * @return array Resultado do teste
     */
    public function testar_conexao_lojista($lojista) {
        if (empty($lojista['url']) || empty($lojista['consumer_key']) || empty($lojista['consumer_secret'])) {
            return array(
                'success' => false,
                'message' => 'Dados de conexão incompletos',
                'code' => 'missing_credentials'
            );
        }
        
        // Testar com endpoint system_status que é mais leve
        $url = trailingslashit($lojista['url']) . 'wp-json/wc/v3/system_status';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                'User-Agent' => 'Sincronizador-WC/' . SINCRONIZADOR_WC_VERSION
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Erro de conexão: ' . $response->get_error_message(),
                'code' => 'connection_error'
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $wc_version = isset($body['settings']['version']) ? $body['settings']['version'] : 'desconhecida';
            
            return array(
                'success' => true,
                'message' => "Conexão OK! WooCommerce v{$wc_version}",
                'code' => 'success',
                'wc_version' => $wc_version
            );
        } elseif ($response_code === 401) {
            return array(
                'success' => false,
                'message' => 'Credenciais inválidas. Verifique Consumer Key e Secret.',
                'code' => 'invalid_credentials'
            );
        } elseif ($response_code === 404) {
            return array(
                'success' => false,
                'message' => 'WooCommerce não encontrado na URL fornecida.',
                'code' => 'woocommerce_not_found'
            );
        } else {
            return array(
                'success' => false,
                'message' => "Erro HTTP {$response_code}. Verifique a URL e configurações.",
                'code' => 'http_error'
            );
        }
    }
    
    /**
     * Prepara dados do produto para API
     * 
     * @param array $produto_data
     * @param array $options
     * @return array
     */
    private function prepare_product_data_for_api($produto_data, $options) {
        $dados_produto = array(
            'name' => $produto_data['name'],
            'slug' => $produto_data['slug'] ?? '',
            'sku' => $produto_data['sku'],
            'type' => $produto_data['type'] ?? 'simple',
            'regular_price' => (string) $produto_data['regular_price'],
            'sale_price' => (string) $produto_data['sale_price'],
            'stock_quantity' => intval($produto_data['stock_quantity']),
            'stock_status' => $produto_data['stock_status'] ?? 'instock',
            'manage_stock' => (bool) ($produto_data['manage_stock'] ?? true),
            'description' => $produto_data['description'] ?? '',
            'short_description' => $produto_data['short_description'] ?? '',
            'status' => $options['status'],
            'catalog_visibility' => 'visible',
            'weight' => $produto_data['weight'] ?? '',
            'dimensions' => $produto_data['dimensions'] ?? array()
        );
        
        // Adicionar imagens se solicitado
        if ($options['incluir_imagens'] && !empty($produto_data['images'])) {
            $dados_produto['images'] = $this->prepare_images_for_api($produto_data['images']);
        }
        
        // Adicionar categorias se solicitado e não for produto variável (variações herdam)
        if ($options['incluir_categorias'] && !empty($produto_data['categories']) && $produto_data['type'] !== 'variable') {
            $dados_produto['categories'] = $this->prepare_categories_for_api($produto_data['categories']);
        }
        
        // Adicionar tags
        if (!empty($produto_data['tags'])) {
            $dados_produto['tags'] = $this->prepare_tags_for_api($produto_data['tags']);
        }
        
        // Adicionar atributos se for produto variável
        if ($produto_data['type'] === 'variable' && !empty($produto_data['attributes'])) {
            $dados_produto['attributes'] = $this->prepare_attributes_for_api($produto_data['attributes']);
        }
        
        return $dados_produto;
    }
    
    /**
     * Prepara dados do produto para atualização
     * 
     * @param array $produto_data
     * @param array $options
     * @return array
     */
    private function prepare_product_data_for_update($produto_data, $options) {
        $dados_produto = array(
            'name' => $produto_data['name'],
            'description' => $produto_data['description'] ?? '',
            'short_description' => $produto_data['short_description'] ?? ''
        );
        
        if ($options['atualizar_precos']) {
            $dados_produto['regular_price'] = (string) $produto_data['regular_price'];
            $dados_produto['sale_price'] = (string) $produto_data['sale_price'];
        }
        
        if ($options['atualizar_estoque']) {
            $dados_produto['stock_quantity'] = intval($produto_data['stock_quantity']);
            $dados_produto['stock_status'] = $produto_data['stock_status'] ?? 'instock';
            $dados_produto['manage_stock'] = (bool) ($produto_data['manage_stock'] ?? true);
        }
        
        if ($options['incluir_imagens'] && !empty($produto_data['images'])) {
            $dados_produto['images'] = $this->prepare_images_for_api($produto_data['images']);
        }
        
        return $dados_produto;
    }
    
    /**
     * Prepara imagens para API
     * 
     * @param array $images
     * @return array
     */
    private function prepare_images_for_api($images) {
        $api_images = array();
        
        foreach ($images as $image_url) {
            if (is_string($image_url) && $this->product_utils->is_valid_image_url($image_url)) {
                $api_images[] = array(
                    'src' => $image_url,
                    'alt' => ''
                );
            }
        }
        
        return $api_images;
    }
    
    /**
     * Prepara categorias para API
     * 
     * @param array $categories
     * @return array
     */
    private function prepare_categories_for_api($categories) {
        $api_categories = array();
        
        foreach ($categories as $category) {
            if (is_array($category) && isset($category['name'])) {
                $api_categories[] = array(
                    'name' => $category['name']
                );
            }
        }
        
        return $api_categories;
    }
    
    /**
     * Prepara tags para API
     * 
     * @param array $tags
     * @return array
     */
    private function prepare_tags_for_api($tags) {
        $api_tags = array();
        
        foreach ($tags as $tag) {
            if (is_array($tag) && isset($tag['name'])) {
                $api_tags[] = array(
                    'name' => $tag['name']
                );
            }
        }
        
        return $api_tags;
    }
    
    /**
     * Prepara atributos para API
     * 
     * @param array $attributes
     * @return array
     */
    private function prepare_attributes_for_api($attributes) {
        $api_attributes = array();
        
        foreach ($attributes as $attribute) {
            if (is_array($attribute) && isset($attribute['name'])) {
                $api_attributes[] = array(
                    'name' => $attribute['name'],
                    'visible' => (bool) ($attribute['visible'] ?? true),
                    'variation' => (bool) ($attribute['variation'] ?? false),
                    'options' => $attribute['options'] ?? array()
                );
            }
        }
        
        return $api_attributes;
    }
    
    /**
     * Cria variações do produto
     * 
     * @param array $lojista
     * @param int $product_id
     * @param array $variations
     * @return bool
     */
    private function criar_variacoes_produto($lojista, $product_id, $variations) {
        if (empty($variations)) {
            return true;
        }
        
        $success_count = 0;
        
        foreach ($variations as $variation_data) {
            $url = trailingslashit($lojista['url']) . "wp-json/wc/v3/products/{$product_id}/variations";
            
            $dados_variacao = array(
                'sku' => $variation_data['sku'] ?? '',
                'regular_price' => (string) ($variation_data['regular_price'] ?? ''),
                'sale_price' => (string) ($variation_data['sale_price'] ?? ''),
                'stock_quantity' => intval($variation_data['stock_quantity'] ?? 0),
                'stock_status' => $variation_data['stock_status'] ?? 'instock',
                'manage_stock' => (bool) ($variation_data['manage_stock'] ?? true),
                'attributes' => $variation_data['attributes'] ?? array()
            );
            
            if (!empty($variation_data['image'])) {
                $dados_variacao['image'] = array(
                    'src' => $variation_data['image'][0] ?? ''
                );
            }
            
            $response = wp_remote_post($url, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($lojista['consumer_key'] . ':' . $lojista['consumer_secret']),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($dados_variacao)
            ));
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
                $success_count++;
                error_log("SINCRONIZADOR WC: Variação criada - SKU: {$variation_data['sku']}");
            } else {
                $error = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
                error_log("SINCRONIZADOR WC: Erro ao criar variação {$variation_data['sku']}: {$error}");
            }
        }
        
        error_log("SINCRONIZADOR WC: {$success_count} de " . count($variations) . " variações criadas para produto {$product_id}");
        return $success_count > 0;
    }
    
    /**
     * Sincroniza variações do produto (atualiza existentes, cria novas)
     * 
     * @param array $lojista
     * @param int $product_id
     * @param array $variations
     * @return bool
     */
    private function sincronizar_variacoes_produto($lojista, $product_id, $variations) {
        // Por simplicidade, vamos apenas criar variações novas
        // Em uma versão mais sofisticada, poderia buscar existentes e comparar
        return $this->criar_variacoes_produto($lojista, $product_id, $variations);
    }
}
