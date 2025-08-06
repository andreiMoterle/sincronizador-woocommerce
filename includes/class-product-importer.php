<?php
/**
 * Classe responsável pela importação de produtos
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure WordPress functions are loaded
if (!function_exists('wp_remote_get')) {
    require_once ABSPATH . WPINC . '/http.php';
}

if (!function_exists('trailingslashit')) {
    require_once ABSPATH . WPINC . '/formatting.php';
}

class Sincronizador_WC_Product_Importer {
    
    public function __construct() {
        add_action('wp_ajax_sincronizador_wc_import_produtos', array($this, 'ajax_import_produtos'));
        add_action('wp_ajax_sincronizador_wc_get_import_status', array($this, 'ajax_get_import_status'));
        add_action('wp_ajax_sincronizador_wc_validate_lojista', array($this, 'ajax_validate_lojista'));
        add_action('wp_ajax_sincronizador_wc_get_produtos_fabrica', array($this, 'ajax_get_produtos_fabrica'));
    }
    
    /**
     * Valida lojista
     */
    public function validate_lojista($lojista_data) {
        if (empty($lojista_data['url']) || empty($lojista_data['consumer_key']) || empty($lojista_data['consumer_secret'])) {
            return array(
                'valid' => false,
                'error' => 'Dados de API incompletos para o lojista'
            );
        }
        
        // Testa URL
        $url_parts = parse_url($lojista_data['url']);
        if (!$url_parts || empty($url_parts['host'])) {
            return array(
                'valid' => false,
                'error' => 'URL do lojista é inválida'
            );
        }
        
        // Teste básico de conexão
        $test_url = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/system_status';
        $response = wp_remote_get($test_url, array(
            'headers' => $this->get_auth_headers($lojista_data['consumer_key'], $lojista_data['consumer_secret']),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => 'Erro de conexão: ' . $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array(
                'valid' => false,
                'error' => 'Falha na autenticação (código: ' . $code . ')'
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return array(
            'valid' => true,
            'store_info' => array(
                'name' => $body['settings']['title'] ?? 'Loja Conectada',
                'version' => $body['environment']['version'] ?? '5.0.0'
            )
        );
    }
    
    /**
     * Importa produto para lojista
     */
    public function importar_produto_para_lojista($produto, $lojista, $opcoes = array()) {
        $destino_url = $lojista['url'];
        $produto_id = $produto['id'];
        
        // Verificar se já existe
        $id_destino = $this->obter_id_destino_produto($produto_id, $destino_url);
        
        // Carregar produto WooCommerce
        $produto_wc = wc_get_product($produto_id);
        if (!$produto_wc) {
            return array(
                'success' => false,
                'message' => 'Produto não encontrado: ID ' . $produto_id
            );
        }
        
        // Montar dados do produto
        $data = $this->montar_dados_produto($produto_wc, $opcoes);
        
        $log_prefix = '[Sincronizador WC] Produto "' . $produto['nome'] . '" - ';
        
        // Usar o método que resolve categorias automaticamente
        $result = $this->importar_produto_para_destino(
            $destino_url, 
            $lojista['consumer_key'], 
            $lojista['consumer_secret'], 
            $data, 
            $id_destino
        );
        
        if ($result['success']) {
            error_log($log_prefix . ($id_destino ? 'Atualizado' : 'Criado') . ' com sucesso');
            
            // Registrar no histórico se for novo produto
            if (!$id_destino && $result['product_id']) {
                $this->registrar_envio_produto($produto_id, $destino_url, $result['product_id']);
            }
            
            return array(
                'success' => true,
                'product_id' => $result['product_id'],
                'message' => 'Produto "' . $produto['nome'] . '" importado com sucesso'
            );
        } else {
            error_log($log_prefix . 'Erro: ' . $result['message']);
            return array(
                'success' => false,
                'message' => 'Erro ao enviar "' . $produto['nome'] . '": ' . $result['message']
            );
        }
    }
    
    /**
     * Headers de autenticação
     */
    private function get_auth_headers($consumer_key, $consumer_secret) {
        return array(
            'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
            'Content-Type' => 'application/json'
        );
    }
    
    /**
     * Obter ID do produto no destino
     */
    private function obter_id_destino_produto($produto_id, $destino_url) {
        $historico = get_option('sincronizador_wc_historico_envios', array());
        return $historico[$destino_url][$produto_id] ?? false;
    }
    
    /**
     * Registrar envio do produto
     */
    private function registrar_envio_produto($produto_id, $destino_url, $id_destino) {
        $historico = get_option('sincronizador_wc_historico_envios', array());
        if (!isset($historico[$destino_url])) {
            $historico[$destino_url] = array();
        }
        $historico[$destino_url][$produto_id] = $id_destino;
        update_option('sincronizador_wc_historico_envios', $historico);
    }
    
    /**
     * Processar importação em lote
     */
    public function processar_importacao($dados) {
        // Validações básicas
        if (empty($dados['lojista_destino']) || empty($dados['produtos_selecionados'])) {
            return array(
                'success' => false,
                'message' => 'Dados obrigatórios não fornecidos'
            );
        }
        
        $lojista = $this->get_lojista_by_id($dados['lojista_destino']);
        if (!$lojista) {
            return array(
                'success' => false,
                'message' => 'Lojista não encontrado'
            );
        }
        
        // Validar lojista
        $validation = $this->validate_lojista($lojista);
        if (!$validation['valid']) {
            return array(
                'success' => false,
                'message' => 'Erro na validação do lojista: ' . $validation['error']
            );
        }
        
        $produtos_fabrica = $this->get_produtos_fabrica();
        $produtos_para_importar = array();
        
        // Filtrar produtos válidos
        foreach ($dados['produtos_selecionados'] as $produto_id) {
            $produto = $this->find_produto_by_id($produtos_fabrica, $produto_id);
            if ($produto && $produto['status'] === 'ativo') {
                $produtos_para_importar[] = $produto;
            }
        }
        
        if (empty($produtos_para_importar)) {
            return array(
                'success' => false,
                'message' => 'Nenhum produto válido encontrado para importação'
            );
        }
        
        // Processar produtos
        $sucessos = 0;
        $erros = 0;
        $logs = array();
        $opcoes = array(
            'incluir_variacoes' => !empty($dados['incluir_variacoes']),
            'incluir_imagens' => !empty($dados['incluir_imagens']),
            'manter_precos' => !empty($dados['manter_precos'])
        );
        
        foreach ($produtos_para_importar as $produto) {
            $result = $this->importar_produto_para_lojista($produto, $lojista, $opcoes);
            
            if ($result['success']) {
                $sucessos++;
                $logs[] = array(
                    'type' => 'success',
                    'message' => $result['message']
                );
            } else {
                $erros++;
                $logs[] = array(
                    'type' => 'error',
                    'message' => $result['message']
                );
            }
        }
        
        // Salvar no histórico
        $this->salvar_historico_importacao($lojista['nome'], $sucessos, $erros, $logs);
        
        return array(
            'success' => true,
            'message' => "Importação concluída: {$sucessos} sucessos, {$erros} erros",
            'sucessos' => $sucessos,
            'erros' => $erros,
            'logs' => $logs
        );
    }
    
    // AJAX Methods
    public function ajax_validate_lojista() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $lojista_id = intval($_POST['lojista_id']);
        $lojista = $this->get_lojista_by_id($lojista_id);
        
        if (!$lojista) {
            wp_send_json_error('Lojista não encontrado');
        }
        
        $validation = $this->validate_lojista($lojista);
        
        if ($validation['valid']) {
            wp_send_json_success(array(
                'message' => 'Lojista validado com sucesso',
                'store_info' => $validation['store_info']
            ));
        } else {
            wp_send_json_error($validation['error']);
        }
    }
    
    public function ajax_get_produtos_fabrica() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $produtos = $this->get_produtos_fabrica();
        wp_send_json_success($produtos);
    }
    
    public function ajax_import_produtos() {
        check_ajax_referer('sincronizador_wc_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Sem permissão');
        }
        
        $result = $this->processar_importacao($_POST);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    public function ajax_get_import_status() {
        // Para compatibilidade - retorna status simples
        wp_send_json_success(array(
            'status' => 'concluido',
            'progress' => 100,
            'message' => 'Importação finalizada'
        ));
    }
    
    // Helper methods
    private function get_lojista_by_id($id) {
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        foreach ($lojistas as $lojista) {
            if ($lojista['id'] == $id) {
                return $lojista;
            }
        }
        return null;
    }
    
    private function find_produto_by_id($produtos, $id) {
        foreach ($produtos as $produto) {
            if ($produto['id'] == $id) {
                return $produto;
            }
        }
        return null;
    }
    
    /**
     * Buscar produto por SKU no destino
     */
    public function buscar_produto_por_sku($destino_url, $consumer_key, $consumer_secret, $sku) {
        $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products?sku=' . urlencode($sku);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body) && is_array($body) && isset($body[0]['id'])) {
            return $body[0]['id'];
        }
        
        return false;
    }
    
    /**
     * Montar dados do produto para envio
     */
    public function montar_dados_produto($produto, $opcoes = array()) {
        // Log das opções recebidas
        error_log('IMPORTAÇÃO DEBUG - Opções recebidas: ' . print_r($opcoes, true));
        
        $dados = array(
            'name' => $produto->get_name(),
            'sku' => $produto->get_sku(),
            'type' => $produto->get_type(),
            'status' => $produto->get_status(),
            'description' => $produto->get_description(),
            'short_description' => $produto->get_short_description(),
            'regular_price' => $produto->get_regular_price(),
            'manage_stock' => $produto->get_manage_stock(),
            'stock_quantity' => $produto->get_stock_quantity(),
            'stock_status' => $produto->get_stock_status(),
            'weight' => $produto->get_weight(),
            'dimensions' => array(
                'length' => $produto->get_length(),
                'width' => $produto->get_width(),
                'height' => $produto->get_height()
            )
        );
        
        // Preço de venda
        if ($produto->get_sale_price()) {
            $dados['sale_price'] = $produto->get_sale_price();
        }
        
        // Manter preços originais se solicitado
        if (!isset($opcoes['manter_precos']) || !$opcoes['manter_precos']) {
            // Aqui poderia aplicar regras de preço específicas
        }
        
        // Categorias
        $categorias = wp_get_post_terms($produto->get_id(), 'product_cat');
        error_log('IMPORTAÇÃO DEBUG - Categorias encontradas: ' . print_r($categorias, true));
        if (!empty($categorias)) {
            $dados['categories'] = array();
            foreach ($categorias as $categoria) {
                $dados['categories'][] = array(
                    'name' => $categoria->name,
                    'slug' => $categoria->slug
                );
                error_log('IMPORTAÇÃO DEBUG - Categoria adicionada: ' . $categoria->name . ' (slug: ' . $categoria->slug . ')');
            }
            error_log('IMPORTAÇÃO DEBUG - Total de categorias a serem enviadas: ' . count($dados['categories']));
        } else {
            error_log('IMPORTAÇÃO DEBUG - Nenhuma categoria encontrada para o produto');
        }
        
        // Imagens se solicitado
        if (isset($opcoes['incluir_imagens']) && $opcoes['incluir_imagens']) {
            error_log('IMPORTAÇÃO DEBUG - Processando imagens para produto: ' . $produto->get_name());
            $images = array();
            
            // Imagem principal
            if ($produto->get_image_id()) {
                $image_url = wp_get_attachment_image_url($produto->get_image_id(), 'full');
                if ($image_url) {
                    // Pular URLs de desenvolvimento que o destino não consegue acessar
                    if (strpos($image_url, '.test') !== false || strpos($image_url, 'localhost') !== false) {
                        error_log('IMPORTAÇÃO DEBUG - Pulando imagem de desenvolvimento (destino não consegue acessar): ' . $image_url);
                    } else {
                        $images[] = array('src' => $image_url);
                        error_log('IMPORTAÇÃO DEBUG - Imagem principal adicionada: ' . $image_url);
                    }
                }
            }
            
            // Galeria
            $gallery_ids = $produto->get_gallery_image_ids();
            error_log('IMPORTAÇÃO DEBUG - IDs da galeria: ' . print_r($gallery_ids, true));
            foreach ($gallery_ids as $image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'full');
                if ($image_url) {
                    // Pular URLs de desenvolvimento que o destino não consegue acessar
                    if (strpos($image_url, '.test') !== false || strpos($image_url, 'localhost') !== false) {
                        error_log('IMPORTAÇÃO DEBUG - Pulando imagem da galeria de desenvolvimento: ' . $image_url);
                    } else {
                        $images[] = array('src' => $image_url);
                        error_log('IMPORTAÇÃO DEBUG - Imagem da galeria adicionada: ' . $image_url);
                    }
                }
            }
            
            if (!empty($images)) {
                $dados['images'] = $images;
                error_log('IMPORTAÇÃO DEBUG - Total de imagens a serem enviadas: ' . count($images));
            } else {
                error_log('IMPORTAÇÃO DEBUG - Nenhuma imagem válida encontrada (URLs de desenvolvimento puladas)');
            }
        } else {
            error_log('IMPORTAÇÃO DEBUG - Incluir imagens está DESABILITADO ou não foi passado');
        }
        
        // Atributos
        $attributes = $produto->get_attributes();
        if (!empty($attributes)) {
            $dados['attributes'] = array();
            foreach ($attributes as $attribute) {
                $options = $attribute->get_options();
                
                // Garantir que options é um array de strings
                if (is_array($options)) {
                    $options = array_map('strval', $options);
                } else {
                    $options = array(strval($options));
                }
                
                $dados['attributes'][] = array(
                    'name' => $attribute->get_name(),
                    'options' => $options,
                    'visible' => $attribute->get_visible(),
                    'variation' => $attribute->get_variation()
                );
            }
        }
        
        // Variações se for produto variável e solicitado
        if ($produto->is_type('variable') && isset($opcoes['incluir_variacoes']) && $opcoes['incluir_variacoes']) {
            error_log('IMPORTAÇÃO DEBUG - Processando variações para produto variável: ' . $produto->get_name());
            $variation_ids = $produto->get_children();
            error_log('IMPORTAÇÃO DEBUG - IDs das variações encontradas: ' . print_r($variation_ids, true));
            
            // IMPORTANTE: As variações não devem ser incluídas no array principal do produto
            // Elas serão criadas separadamente após o produto principal ser criado
            $dados['_variations_data'] = array(); // Campo temporário para armazenar dados das variações
            
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variation_data = array(
                        'sku' => $variation->get_sku(),
                        'regular_price' => $variation->get_regular_price(),
                        'sale_price' => $variation->get_sale_price(),
                        'manage_stock' => $variation->get_manage_stock(),
                        'stock_quantity' => $variation->get_stock_quantity(),
                        'stock_status' => $variation->get_stock_status(),
                        'attributes' => $variation->get_variation_attributes()
                    );
                    $dados['_variations_data'][] = $variation_data;
                    error_log('IMPORTAÇÃO DEBUG - Variação adicionada: SKU=' . $variation->get_sku() . ', Preço=' . $variation->get_regular_price());
                }
            }
            
            error_log('IMPORTAÇÃO DEBUG - Total de variações a serem criadas separadamente: ' . count($dados['_variations_data']));
        } else {
            if ($produto->is_type('variable')) {
                error_log('IMPORTAÇÃO DEBUG - Produto é variável mas incluir_variacoes está DESABILITADO');
            } else {
                error_log('IMPORTAÇÃO DEBUG - Produto não é variável (tipo: ' . $produto->get_type() . ')');
            }
        }
        
        // Log dos dados finais
        error_log('IMPORTAÇÃO DEBUG - Dados finais do produto a serem enviados: ' . print_r($dados, true));
        
        return $dados;
    }
    
    /**
     * Importar produto para destino (usando as credenciais diretamente)
     */
    public function importar_produto_para_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto, $id_destino = null) {
        // Separar dados das variações (se existirem) do produto principal
        $variations_data = null;
        if (isset($dados_produto['_variations_data'])) {
            $variations_data = $dados_produto['_variations_data'];
            unset($dados_produto['_variations_data']); // Remover do payload principal
            error_log('IMPORTAÇÃO DEBUG - Separando dados das variações para criação posterior');
        }
        
        // Resolver categorias no destino (buscar por slug ou criar)
        if (isset($dados_produto['categories']) && !empty($dados_produto['categories'])) {
            error_log('IMPORTAÇÃO DEBUG - Resolvendo categorias no destino...');
            $categorias_resolvidas = $this->resolver_categorias_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto['categories']);
            $dados_produto['categories'] = $categorias_resolvidas;
            error_log('IMPORTAÇÃO DEBUG - Categorias resolvidas: ' . count($categorias_resolvidas));
        }
        
        if ($id_destino) {
            // Atualizar produto existente
            $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/' . $id_destino;
            $response = wp_remote_request($url, array(
                'method'  => 'PUT',
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                ),
                'body'    => json_encode($dados_produto),
                'timeout' => 30,
            ));
        } else {
            // Criar novo produto
            $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products';
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                ),
                'body'    => json_encode($dados_produto),
                'timeout' => 30,
            ));
        }
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Erro ao enviar produto: ' . $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            $body = wp_remote_retrieve_body($response);
            return array(
                'success' => false,
                'message' => 'Erro HTTP ' . $code . ': ' . $body
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $product_id = $body['id'] ?? null;
        
        $result = array(
            'success' => true,
            'product_id' => $product_id,
            'message' => 'Produto ' . ($id_destino ? 'atualizado' : 'criado') . ' com sucesso'
        );
        
        // Se há variações e o produto foi criado/atualizado com sucesso, criar as variações
        if ($variations_data && $product_id) {
            error_log('IMPORTAÇÃO DEBUG - Criando variações para produto ID: ' . $product_id);
            $variations_result = $this->criar_variacoes_produto($destino_url, $consumer_key, $consumer_secret, $product_id, $variations_data);
            
            if ($variations_result['success']) {
                $result['message'] .= ' com ' . $variations_result['created_count'] . ' variações';
                error_log('IMPORTAÇÃO DEBUG - Variações criadas com sucesso: ' . $variations_result['created_count']);
            } else {
                $result['message'] .= ' mas falhou ao criar variações: ' . $variations_result['message'];
                error_log('IMPORTAÇÃO DEBUG - Erro ao criar variações: ' . $variations_result['message']);
            }
        }
        
        return $result;
    }
    
    /**
     * Criar variações para um produto
     */
    private function criar_variacoes_produto($destino_url, $consumer_key, $consumer_secret, $product_id, $variations_data) {
        $created_count = 0;
        $errors = array();
        
        foreach ($variations_data as $variation_data) {
            $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/' . $product_id . '/variations';
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                ),
                'body'    => json_encode($variation_data),
                'timeout' => 30,
            ));
            
            if (is_wp_error($response)) {
                $errors[] = 'Erro ao criar variação: ' . $response->get_error_message();
                continue;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 300) {
                $body = wp_remote_retrieve_body($response);
                $errors[] = 'Erro HTTP ' . $code . ' ao criar variação: ' . $body;
                continue;
            }
            
            $created_count++;
        }
        
        if (count($errors) === 0) {
            return array(
                'success' => true,
                'created_count' => $created_count,
                'message' => $created_count . ' variações criadas com sucesso'
            );
        } else {
            return array(
                'success' => false,
                'created_count' => $created_count,
                'message' => 'Criadas ' . $created_count . ' variações, ' . count($errors) . ' falharam: ' . implode(', ', $errors)
            );
        }
    }
    
    /**
     * Resolver categorias no destino (buscar por slug ou criar)
     */
    private function resolver_categorias_destino($destino_url, $consumer_key, $consumer_secret, $categorias) {
        $categorias_resolvidas = array();
        
        foreach ($categorias as $categoria_data) {
            // Buscar categoria existente por slug
            $categoria_id = $this->buscar_categoria_por_slug($destino_url, $consumer_key, $consumer_secret, $categoria_data['slug']);
            
            if ($categoria_id) {
                error_log('IMPORTAÇÃO DEBUG - Categoria encontrada no destino: ' . $categoria_data['name'] . ' (ID: ' . $categoria_id . ')');
                $categorias_resolvidas[] = array('id' => $categoria_id);
            } else {
                // Criar nova categoria
                $nova_categoria_id = $this->criar_categoria_destino($destino_url, $consumer_key, $consumer_secret, $categoria_data);
                if ($nova_categoria_id) {
                    error_log('IMPORTAÇÃO DEBUG - Categoria criada no destino: ' . $categoria_data['name'] . ' (ID: ' . $nova_categoria_id . ')');
                    $categorias_resolvidas[] = array('id' => $nova_categoria_id);
                } else {
                    error_log('IMPORTAÇÃO DEBUG - Falha ao criar categoria no destino: ' . $categoria_data['name']);
                }
            }
        }
        
        return $categorias_resolvidas;
    }
    
    /**
     * Buscar categoria por slug no destino
     */
    private function buscar_categoria_por_slug($destino_url, $consumer_key, $consumer_secret, $slug) {
        $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/categories?slug=' . urlencode($slug);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body) && is_array($body) && isset($body[0]['id'])) {
            return $body[0]['id'];
        }
        
        return false;
    }
    
    /**
     * Criar categoria no destino
     */
    private function criar_categoria_destino($destino_url, $consumer_key, $consumer_secret, $categoria_data) {
        $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/categories';
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            ),
            'body'    => json_encode($categoria_data),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['id'] ?? false;
    }
    
    private function get_produtos_fabrica() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 50, // Limitar para performance
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_sku',
                    'value' => '',
                    'compare' => '!='
                )
            )
        );
        
        $query = new WP_Query($args);
        $produtos = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $produto = wc_get_product(get_the_ID());
                
                if (!$produto) {
                    continue;
                }
                
                // Obter categoria principal
                $categories = wp_get_post_terms($produto->get_id(), 'product_cat');
                $categoria_nome = !empty($categories) ? $categories[0]->name : 'Sem categoria';
                
                $produtos[] = array(
                    'id' => $produto->get_id(),
                    'nome' => $produto->get_name(),
                    'sku' => $produto->get_sku(),
                    'categoria' => $categoria_nome,
                    'preco' => floatval($produto->get_regular_price()),
                    'estoque' => $produto->get_stock_quantity() ? intval($produto->get_stock_quantity()) : 0,
                    'status' => $produto->get_status() === 'publish' ? 'ativo' : 'inativo',
                    'imagem' => wp_get_attachment_image_url($produto->get_image_id(), 'thumbnail') ?: 'https://via.placeholder.com/80x80'
                );
            }
            wp_reset_postdata();
        }
        
        return $produtos;
    }
    
    private function salvar_historico_importacao($lojista_nome, $sucessos, $erros, $logs) {
        $historico = get_option('sincronizador_wc_historico_importacoes', array());
        
        $item = array(
            'data' => current_time('mysql'),
            'lojista' => $lojista_nome,
            'produtos' => $sucessos,
            'produtos_erro' => $erros,
            'status' => 'concluido',
            'logs' => $logs
        );
        
        array_unshift($historico, $item);
        
        // Manter apenas os últimos 50 registros
        if (count($historico) > 50) {
            $historico = array_slice($historico, 0, 50);
        }
        
        update_option('sincronizador_wc_historico_importacoes', $historico);
    }
}
