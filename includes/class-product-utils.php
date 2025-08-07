<?php
/**
 * Classe utilitária para funções relacionadas a produtos
 * Centraliza funções duplicadas e comuns do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Product_Utils {
    
    /**
     * Instância singleton
     */
    private static $instance = null;
    
    /**
     * Retorna instância singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Obtém imagens do produto de forma unificada
     * Remove duplicação entre class-api-handler e sincronizador-woocommerce
     * 
     * @param WC_Product $produto
     * @param string $format Formato de retorno: 'simple' (URLs apenas) ou 'detailed' (com metadados)
     * @return array
     */
    public function get_product_images($produto, $format = 'simple') {
        if (!$produto || !is_a($produto, 'WC_Product')) {
            return array();
        }
        
        $images = array();
        
        // Imagem principal
        $image_id = $produto->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            
            if ($this->is_valid_image_url($image_url)) {
                if ($format === 'detailed') {
                    $images[] = array(
                        'id' => $image_id,
                        'src' => $image_url,
                        'name' => get_the_title($image_id),
                        'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
                    );
                } else {
                    $images[] = $image_url;
                }
            }
        }
        
        // Galeria de imagens
        $gallery_ids = $produto->get_gallery_image_ids();
        foreach ($gallery_ids as $gallery_id) {
            $image_url = wp_get_attachment_image_url($gallery_id, 'full');
            
            if ($this->is_valid_image_url($image_url)) {
                if ($format === 'detailed') {
                    $images[] = array(
                        'id' => $gallery_id,
                        'src' => $image_url,
                        'name' => get_the_title($gallery_id),
                        'alt' => get_post_meta($gallery_id, '_wp_attachment_image_alt', true)
                    );
                } else {
                    $images[] = $image_url;
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Valida URL de imagem antes de usar
     * Centraliza a validação de URLs de imagem
     * 
     * @param string $url
     * @return bool
     */
    public function is_valid_image_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // Em desenvolvimento, permitir URLs locais
        $is_dev_environment = $this->is_development_environment($url);
        if ($is_dev_environment) {
            error_log("SINCRONIZADOR WC: Permitindo URL de desenvolvimento - $url");
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
                    error_log("SINCRONIZADOR WC: URL de desenvolvimento bloqueada em produção - $url");
                    return false;
                }
            }
        }
        
        // Verificar se a URL é acessível (cache para evitar múltiplas verificações)
        $cache_key = 'swc_image_check_' . md5($url);
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            return $cached_result === 'valid';
        }
        
        $response = wp_remote_head($url, array(
            'timeout' => 5,
            'redirection' => 3
        ));
        
        $is_valid = false;
        
        if (!is_wp_error($response)) {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                // Verificar content-type se disponível
                $content_type = wp_remote_retrieve_header($response, 'content-type');
                if (!$content_type || strpos($content_type, 'image/') === 0) {
                    $is_valid = true;
                }
            }
        }
        
        if (!$is_valid) {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : "HTTP " . wp_remote_retrieve_response_code($response);
            error_log("SINCRONIZADOR WC: Imagem inacessível - $url - $error_msg");
        }
        
        // Cache por 1 hora
        set_transient($cache_key, $is_valid ? 'valid' : 'invalid', HOUR_IN_SECONDS);
        
        return $is_valid;
    }
    
    /**
     * Detecta se está em ambiente de desenvolvimento
     * 
     * @param string $url
     * @return bool
     */
    private function is_development_environment($url = '') {
        // Verificar por URL se fornecida
        if (!empty($url)) {
            $dev_patterns = array('.test', '.local', 'localhost', '127.0.0.1', '192.168.');
            foreach ($dev_patterns as $pattern) {
                if (strpos($url, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        // Verificar ambiente WordPress
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtém produtos locais formatados para sincronização
     * Centraliza a lógica de busca de produtos
     * 
     * @param array $args Argumentos adicionais para WP_Query
     * @return array
     */
    public function get_produtos_locais($args = array()) {
        $default_args = array(
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
        
        $args = wp_parse_args($args, $default_args);
        
        $produtos = get_posts($args);
        $produtos_formatados = array();
        
        foreach ($produtos as $produto_post) {
            $produto = wc_get_product($produto_post->ID);
            
            if ($produto && $produto->get_sku()) {
                $produto_data = $this->format_product_data($produto);
                if ($produto_data) {
                    $produtos_formatados[] = $produto_data;
                }
            }
        }
        
        // Se não há produtos reais, usar produtos de demonstração
        if (empty($produtos_formatados)) {
            $produtos_formatados = $this->get_produtos_demonstracao();
        }
        
        return $produtos_formatados;
    }
    
    /**
     * Formata dados do produto para sincronização
     * 
     * @param WC_Product $produto
     * @return array|null
     */
    public function format_product_data($produto) {
        if (!$produto || !$produto->get_sku()) {
            return null;
        }
        
        // Obter preços com tratamento especial para produtos variáveis
        $precos = $this->get_product_prices($produto);
        
        error_log("SINCRONIZADOR WC: Formatando produto ID {$produto->get_id()}: preços = " . json_encode($precos));
        
        $produto_data = array(
            'id' => $produto->get_id(),
            'name' => $produto->get_name(),
            'slug' => $produto->get_slug(),
            'sku' => $produto->get_sku(),
            'type' => $produto->get_type(),
            'regular_price' => $precos['regular_price'],
            'sale_price' => $precos['sale_price'],
            'price' => $precos['price'],
            'stock_quantity' => $produto->get_stock_quantity() ?: 0,
            'stock_status' => $produto->get_stock_status(),
            'manage_stock' => $produto->get_manage_stock(),
            'description' => $produto->get_description(),
            'short_description' => $produto->get_short_description(),
            'categories' => $this->get_product_categories($produto),
            'tags' => $this->get_product_tags($produto),
            'attributes' => $this->get_product_attributes($produto),
            'images' => $this->get_product_images($produto),
            'weight' => $produto->get_weight(),
            'dimensions' => array(
                'length' => $produto->get_length(),
                'width' => $produto->get_width(),
                'height' => $produto->get_height()
            ),
            'variations' => array() // Será preenchido por método específico se necessário
        );
        
        // Adicionar variações se for produto variável
        if ($produto->is_type('variable')) {
            $produto_data['variations'] = $this->get_product_variations($produto);
        }
        
        return $produto_data;
    }
    
    /**
     * Obtém preços do produto com tratamento especial para variações
     * 
     * @param WC_Product $produto
     * @return array
     */
    private function get_product_prices($produto) {
        $regular_price = $produto->get_regular_price();
        $sale_price = $produto->get_sale_price();
        $price = $produto->get_price();
        
        // Para produtos variáveis, buscar preços das variações
        if ($produto->is_type('variable')) {
            $variation_prices = $produto->get_variation_prices();
            
            if (!empty($variation_prices['price'])) {
                $price = min($variation_prices['price']);
                error_log("SINCRONIZADOR WC: Produto variável ID {$produto->get_id()}: menor preço das variações = {$price}");
            }
            
            if (!empty($variation_prices['regular_price'])) {
                $regular_price = min($variation_prices['regular_price']);
            }
            
            if (!empty($variation_prices['sale_price'])) {
                $precos_promocionais = array_filter($variation_prices['sale_price'], function($p) {
                    return $p !== '';
                });
                if (!empty($precos_promocionais)) {
                    $sale_price = min($precos_promocionais);
                }
            }
        }
        
        // Fallback para meta direto se preços estão vazios
        if (empty($price) || $price === '0') {
            $price_meta = get_post_meta($produto->get_id(), '_price', true);
            $regular_meta = get_post_meta($produto->get_id(), '_regular_price', true);
            
            if (!empty($price_meta) && $price_meta !== '0') {
                $price = $price_meta;
            } elseif (!empty($regular_meta) && $regular_meta !== '0') {
                $price = $regular_meta;
            }
        }
        
        return array(
            'regular_price' => $regular_price ?: '0',
            'sale_price' => $sale_price ?: '',
            'price' => $price ?: '0'
        );
    }
    
    /**
     * Obtém categorias do produto
     * 
     * @param WC_Product $produto
     * @return array
     */
    private function get_product_categories($produto) {
        $categories = wp_get_post_terms($produto->get_id(), 'product_cat', array('fields' => 'all'));
        $category_data = array();
        
        foreach ($categories as $category) {
            $category_data[] = array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug
            );
        }
        
        return $category_data;
    }
    
    /**
     * Obtém tags do produto
     * 
     * @param WC_Product $produto
     * @return array
     */
    private function get_product_tags($produto) {
        $tags = wp_get_post_terms($produto->get_id(), 'product_tag', array('fields' => 'all'));
        $tag_data = array();
        
        foreach ($tags as $tag) {
            $tag_data[] = array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug
            );
        }
        
        return $tag_data;
    }
    
    /**
     * Obtém atributos do produto
     * 
     * @param WC_Product $produto
     * @return array
     */
    private function get_product_attributes($produto) {
        $attributes = array();
        $product_attributes = $produto->get_attributes();
        
        foreach ($product_attributes as $attribute) {
            $attribute_data = array(
                'name' => $attribute->get_name(),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'options' => array()
            );
            
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($produto->get_id(), $attribute->get_name());
                foreach ($terms as $term) {
                    $attribute_data['options'][] = $term->name;
                }
            } else {
                $attribute_data['options'] = $attribute->get_options();
            }
            
            $attributes[] = $attribute_data;
        }
        
        return $attributes;
    }
    
    /**
     * Obtém variações do produto variável
     * 
     * @param WC_Product $produto
     * @return array
     */
    private function get_product_variations($produto) {
        if (!$produto->is_type('variable')) {
            return array();
        }
        
        $variations = array();
        $variation_ids = $produto->get_children();
        
        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $variation_data = array(
                    'id' => $variation->get_id(),
                    'sku' => $variation->get_sku(),
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'price' => $variation->get_price(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'stock_status' => $variation->get_stock_status(),
                    'manage_stock' => $variation->get_manage_stock(),
                    'attributes' => $variation->get_variation_attributes(),
                    'image' => $this->get_product_images($variation)
                );
                
                $variations[] = $variation_data;
            }
        }
        
        return $variations;
    }
    
    /**
     * Produtos de demonstração para desenvolvimento/teste
     * 
     * @return array
     */
    public function get_produtos_demonstracao() {
        return array(
            array(
                'id' => 999001,
                'name' => 'Produto Demo 1 - Camiseta Básica',
                'sku' => 'CAM-001',
                'type' => 'simple',
                'regular_price' => '29.90',
                'sale_price' => '24.90',
                'price' => '24.90',
                'stock_quantity' => 100,
                'stock_status' => 'instock',
                'manage_stock' => true,
                'description' => 'Camiseta básica 100% algodão, confortável e durável.',
                'short_description' => 'Camiseta básica de alta qualidade',
                'categories' => array(
                    array('id' => 1, 'name' => 'Roupas', 'slug' => 'roupas'),
                    array('id' => 2, 'name' => 'Camisetas', 'slug' => 'camisetas')
                ),
                'tags' => array(),
                'attributes' => array(),
                'images' => array('https://via.placeholder.com/800x800/4CAF50/FFFFFF?text=CAM001'),
                'variations' => array()
            ),
            array(
                'id' => 999002,
                'name' => 'Produto Demo 2 - Calça Jeans',
                'sku' => 'CAL-002',
                'type' => 'simple',
                'regular_price' => '89.90',
                'sale_price' => '79.90',
                'price' => '79.90',
                'stock_quantity' => 50,
                'stock_status' => 'instock',
                'manage_stock' => true,
                'description' => 'Calça jeans masculina, corte reto, tecido resistente.',
                'short_description' => 'Calça jeans de qualidade premium',
                'categories' => array(
                    array('id' => 1, 'name' => 'Roupas', 'slug' => 'roupas'),
                    array('id' => 3, 'name' => 'Calças', 'slug' => 'calcas')
                ),
                'tags' => array(),
                'attributes' => array(),
                'images' => array('https://via.placeholder.com/800x800/2196F3/FFFFFF?text=CAL002'),
                'variations' => array()
            ),
            // Produto variável de exemplo
            array(
                'id' => 999003,
                'name' => 'Produto Demo 3 - Camiseta Variável',
                'sku' => 'CAM-VAR-001',
                'type' => 'variable',
                'regular_price' => '35.90',
                'sale_price' => '',
                'price' => '29.90', // Menor preço das variações
                'stock_quantity' => 0, // Controlado pelas variações
                'stock_status' => 'instock',
                'manage_stock' => false,
                'description' => 'Camiseta com múltiplas cores e tamanhos disponíveis.',
                'short_description' => 'Camiseta variável com opções',
                'categories' => array(
                    array('id' => 1, 'name' => 'Roupas', 'slug' => 'roupas'),
                    array('id' => 2, 'name' => 'Camisetas', 'slug' => 'camisetas')
                ),
                'tags' => array(),
                'attributes' => array(
                    array(
                        'name' => 'Tamanho',
                        'visible' => true,
                        'variation' => true,
                        'options' => array('P', 'M', 'G')
                    ),
                    array(
                        'name' => 'Cor',
                        'visible' => true,
                        'variation' => true,
                        'options' => array('Azul', 'Vermelho', 'Verde')
                    )
                ),
                'images' => array('https://via.placeholder.com/800x800/FF9800/FFFFFF?text=VAR001'),
                'variations' => array(
                    array(
                        'id' => 999301,
                        'sku' => 'CAM-VAR-001-P-AZUL',
                        'regular_price' => '35.90',
                        'sale_price' => '',
                        'price' => '35.90',
                        'stock_quantity' => 10,
                        'stock_status' => 'instock',
                        'manage_stock' => true,
                        'attributes' => array('tamanho' => 'P', 'cor' => 'Azul'),
                        'image' => array()
                    ),
                    array(
                        'id' => 999302,
                        'sku' => 'CAM-VAR-001-M-AZUL',
                        'regular_price' => '32.90',
                        'sale_price' => '29.90',
                        'price' => '29.90',
                        'stock_quantity' => 15,
                        'stock_status' => 'instock',
                        'manage_stock' => true,
                        'attributes' => array('tamanho' => 'M', 'cor' => 'Azul'),
                        'image' => array()
                    )
                )
            )
        );
    }
}
