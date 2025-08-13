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
        // AJAX handler reabilitado para importação
        add_action('wp_ajax_sincronizador_wc_import_produtos', array($this, 'ajax_import_produtos'));
        add_action('wp_ajax_sincronizador_wc_get_import_status', array($this, 'ajax_get_import_status'));
        add_action('wp_ajax_sincronizador_wc_validate_lojista', array($this, 'ajax_validate_lojista'));
        add_action('wp_ajax_sincronizador_wc_get_produtos_fabrica', array($this, 'ajax_get_produtos_fabrica'));
    }

    /**
     * Aplicar percentual de acréscimo ao preço baseado no lojista
     */
    private function aplicar_percentual_preco($preco_original, $lojista_data) {
        if (empty($preco_original) || !is_numeric($preco_original)) {
            return $preco_original;
        }
        
        $percentual = 0.0;
        if (isset($lojista_data['percentual_acrescimo']) && is_numeric($lojista_data['percentual_acrescimo'])) {
            $percentual = floatval($lojista_data['percentual_acrescimo']);
        }
        
        if ($percentual <= 0) {
            return $preco_original; // Sem acréscimo
        }
        
        $preco_final = floatval($preco_original) * (1 + ($percentual / 100));
        
        error_log("*** PERCENTUAL APLICADO: Preço original: {$preco_original}, Percentual: {$percentual}%, Preço final: {$preco_final} ***");
        
        return number_format($preco_final, 2, '.', '');
    }

    /**
     * Verificar se é uma URL válida e acessível externamente
     */
    private function is_valid_external_url($url) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Rejeitar URLs locais/desenvolvimento
        $invalid_domains = array('.test', '.local', 'localhost', '127.0.0.1', '::1');
        foreach ($invalid_domains as $invalid) {
            if (strpos($url, $invalid) !== false) {
                error_log("*** URL REJEITADA (DOMÍNIO LOCAL): {$url} ***");
                return false;
            }
        }
        
        // Aceitar apenas HTTP/HTTPS
        if (!preg_match('/^https?:\/\//', $url)) {
            error_log("*** URL REJEITADA (PROTOCOLO INVÁLIDO): {$url} ***");
            return false;
        }
        
        return true;
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
        error_log("*** SINCRONIZADOR-WC EXECUTANDO - VERSÃO 2.0 ***");
        error_log("*** TESTE IDENTIFICAÇÃO DO PLUGIN ***");
        
        $sku_produto = $produto['sku'] ?? '';
        
        error_log("=== INICIANDO IMPORTAÇÃO ===");
        error_log("Produto: " . $produto['nome']);
        error_log("SKU: " . $sku_produto);
        error_log("Destino: " . $lojista['url']);
        
        // VERIFICAÇÃO SIMPLES: Se tem SKU, verificar se já existe
        if (!empty($sku_produto)) {
            error_log("VERIFICANDO se produto SKU '$sku_produto' já existe...");
            $produto_existente_id = $this->verificar_produto_existente($sku_produto, $lojista);
            if ($produto_existente_id) {
                error_log("*** PRODUTO PULADO AUTOMATICAMENTE - SKU '{$sku_produto}' JÁ EXISTE ***");
                return array(
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Produto "' . $produto['nome'] . '" pulado automaticamente (já existe)'
                );
            }
            error_log("Produto SKU '$sku_produto' NÃO existe - continuando criação...");
        } else {
            error_log("SKU vazio - não pode verificar duplicação");
        }
        
        // Produto não existe - criar novo
        error_log("CRIANDO PRODUTO - SKU '{$sku_produto}' não existe no destino");
        
        $produto_wc = wc_get_product($produto['id']);
        if (!$produto_wc) {
            error_log("ERRO - Produto WooCommerce não encontrado: ID " . $produto['id']);
            return array(
                'success' => false,
                'message' => 'Produto não encontrado: ID ' . $produto['id']
            );
        }
        
        $data = $this->montar_dados_produto($produto_wc, $opcoes, $lojista);
        
        $result = $this->importar_produto_para_destino(
            $lojista['url'], 
            $lojista['consumer_key'], 
            $lojista['consumer_secret'], 
            $data, 
            null, // Sempre null = criar novo
            $opcoes
        );
        
        if ($result['success']) {
            error_log("PRODUTO CRIADO COM SUCESSO - ID: " . $result['product_id']);
            return array(
                'success' => true,
                'product_id' => $result['product_id'],
                'message' => 'Produto "' . $produto['nome'] . '" criado com sucesso'
            );
        } else {
            error_log("ERRO AO CRIAR PRODUTO: " . $result['message']);
            return array(
                'success' => false,
                'message' => 'Erro ao criar "' . $produto['nome'] . '": ' . $result['message']
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
     * Importar produto para múltiplos lojistas com percentuais específicos
     */
    public function import_product_to_lojistas($product_id, $lojistas_ids, $percentuais = array()) {
        $results = array();
        $lojistas = get_option('sincronizador_wc_lojistas', array());
        
        foreach ($lojistas_ids as $lojista_id) {
            // Encontrar lojista
            $lojista = null;
            foreach ($lojistas as $l) {
                if ($l['id'] == $lojista_id) {
                    $lojista = $l;
                    break;
                }
            }
            
            if (!$lojista) {
                $results[] = array(
                    'success' => false,
                    'message' => "Lojista ID {$lojista_id} não encontrado"
                );
                continue;
            }
            
            // Aplicar percentual específico para este lojista se fornecido
            if (isset($percentuais[$lojista_id]) && is_numeric($percentuais[$lojista_id])) {
                $lojista['percentual_acrescimo'] = floatval($percentuais[$lojista_id]);
                error_log("*** PERCENTUAL ESPECÍFICO APLICADO - Lojista {$lojista['nome']}: {$lojista['percentual_acrescimo']}% ***");
            }
            
            // Criar produto estruturado
            $produto = array('id' => $product_id);
            
            // Definir opções padrão
            $opcoes = array(
                'incluir_variacoes' => true,
                'incluir_imagens' => true
            );
            
            $result = $this->importar_produto_para_lojista($produto, $lojista, $opcoes);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * Processar importação em lote
     */
    public function processar_importacao($dados) {
        error_log('*** SINCRONIZADOR-WC VERSÃO 2.0 - PROCESSAMENTO INICIADO ***');
        error_log('*** PLUGIN: SINCRONIZADOR-WOOCOMMERCE ***');
        
        // Debug: verificar dados recebidos
        error_log('IMPORTAÇÃO DEBUG - Dados recebidos: ' . print_r($dados, true));
        
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
        $pulados = 0;
        $logs = array();
        $opcoes = array(
            'incluir_variacoes' => !empty($dados['incluir_variacoes']),
            'incluir_imagens' => !empty($dados['incluir_imagens']),
            'manter_precos' => !empty($dados['manter_precos'])
        );

        // ** NOVO: APLICAR PERCENTUAL DE ACRÉSCIMO **
        $percentual_acrescimo = isset($dados['percentual_acrescimo']) ? floatval($dados['percentual_acrescimo']) : 0;
        if ($percentual_acrescimo > 0) {
            // Aplicar percentual temporariamente ao lojista para esta importação
            $lojista['percentual_acrescimo'] = $percentual_acrescimo;
            error_log("*** PERCENTUAL GLOBAL APLICADO: {$percentual_acrescimo}% para lojista {$lojista['nome']} ***");
        }

        foreach ($produtos_para_importar as $index => $produto) {
            $produto_numero = $index + 1;
            error_log("PROCESSANDO produto {$produto_numero}/{" . count($produtos_para_importar) . "} - SKU: " . ($produto['sku'] ?? 'sem SKU'));
            
            $result = $this->importar_produto_para_lojista($produto, $lojista, $opcoes);
            
            if ($result['success']) {
                if (isset($result['skipped']) && $result['skipped']) {
                    $pulados++;
                    $logs[] = array(
                        'type' => 'info',
                        'message' => $result['message']
                    );
                } else {
                    $sucessos++;
                    $logs[] = array(
                        'type' => 'success',
                        'message' => $result['message']
                    );
                }
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
        
        $total_processados = $sucessos + $erros + $pulados;
        $message = "Importação concluída: {$sucessos} sucessos, {$erros} erros";
        if ($pulados > 0) {
            $message .= ", {$pulados} pulados";
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'sucessos' => $sucessos,
            'erros' => $erros,
            'pulados' => $pulados,
            'total' => $total_processados,
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
    public function montar_dados_produto($produto, $opcoes = array(), $lojista_data = null) {
        // Log das opções recebidas
        error_log('IMPORTAÇÃO DEBUG - Opções recebidas: ' . print_r($opcoes, true));
        
        $dados = array(
            'name' => $produto->get_name(),
            'sku' => $produto->get_sku(),
            'type' => $produto->get_type(),
            'status' => $produto->get_status(),
            'description' => $produto->get_description(),
            'short_description' => $produto->get_short_description(),
            'regular_price' => $this->aplicar_percentual_preco($produto->get_regular_price(), $lojista_data),
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
        
        // Preço de venda (aplicar percentual também)
        if ($produto->get_sale_price()) {
            $dados['sale_price'] = $this->aplicar_percentual_preco($produto->get_sale_price(), $lojista_data);
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

        // Marcas (brands) - tentar diferentes taxonomias
        $taxonomias_marca = array('product_brand', 'pwb-brand', 'brand', 'brands');
        $marcas_encontradas = array();
        
        foreach ($taxonomias_marca as $taxonomia) {
            $marcas = wp_get_post_terms($produto->get_id(), $taxonomia);
            if (!empty($marcas) && !is_wp_error($marcas)) {
                $marcas_encontradas = $marcas;
                error_log("IMPORTAÇÃO DEBUG - Marcas encontradas na taxonomia '{$taxonomia}': " . print_r($marcas, true));
                break;
            }
        }
        
        if (!empty($marcas_encontradas)) {
            $dados['brands'] = array();
            foreach ($marcas_encontradas as $marca) {
                $dados['brands'][] = array(
                    'name' => $marca->name,
                    'slug' => $marca->slug
                );
                error_log('IMPORTAÇÃO DEBUG - Marca adicionada: ' . $marca->name . ' (slug: ' . $marca->slug . ')');
            }
            error_log('IMPORTAÇÃO DEBUG - Total de marcas a serem enviadas: ' . count($dados['brands']));
        } else {
            error_log('IMPORTAÇÃO DEBUG - Nenhuma marca encontrada para o produto');
        }
        
        // Imagens se solicitado
        if (isset($opcoes['incluir_imagens']) && $opcoes['incluir_imagens']) {
            error_log('IMPORTAÇÃO DEBUG - Processando imagens para produto: ' . $produto->get_name());
            $images = array();
            
            // Imagem principal
            if ($produto->get_image_id()) {
                $image_url = wp_get_attachment_image_url($produto->get_image_id(), 'full');
                if ($image_url && $this->is_valid_external_url($image_url)) {
                    $images[] = array('src' => $image_url);
                    error_log('IMPORTAÇÃO DEBUG - Imagem principal adicionada: ' . $image_url);
                } else {
                    error_log('IMPORTAÇÃO DEBUG - Imagem principal ignorada (URL inválida): ' . $image_url);
                }
            }
            
            // Galeria
            $gallery_ids = $produto->get_gallery_image_ids();
            error_log('IMPORTAÇÃO DEBUG - IDs da galeria: ' . print_r($gallery_ids, true));
            foreach ($gallery_ids as $image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'full');
                if ($image_url && $this->is_valid_external_url($image_url)) {
                    $images[] = array('src' => $image_url);
                    error_log('IMPORTAÇÃO DEBUG - Imagem da galeria adicionada: ' . $image_url);
                } else {
                    error_log('IMPORTAÇÃO DEBUG - Imagem da galeria ignorada (URL inválida): ' . $image_url);
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
                
                // Se for um atributo taxonomia (pa_), buscar os nomes dos termos
                if ($attribute->is_taxonomy()) {
                    $taxonomy = $attribute->get_name();
                    $term_names = array();
                    foreach ($options as $term_id) {
                        $term = get_term($term_id);
                        if ($term && !is_wp_error($term)) {
                            $term_names[] = $term->name;
                        }
                    }
                    $options = $term_names;
                }
                
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
            
            foreach ($variation_ids as $index => $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    // ** CORREÇÃO DOS ATRIBUTOS DAS VARIAÇÕES **
                    $variation_attributes = $variation->get_variation_attributes();
                    $attributes_array = array();
                    
                    foreach ($variation_attributes as $attribute_name => $attribute_value) {
                        // Manter o nome original do atributo (pa_cor, etc.) para compatibilidade com WooCommerce
                        $clean_attribute_name = str_replace('attribute_', '', $attribute_name);
                        
                        // Garantir que o atributo tenha nome e valor
                        if (!empty($attribute_value)) {
                            $attributes_array[] = array(
                                'id' => 0, // Para atributos personalizados
                                'name' => $clean_attribute_name, // Manter nome original (pa_cor)
                                'option' => $attribute_value
                            );
                            
                            error_log("*** ATRIBUTO VARIAÇÃO - Nome: {$clean_attribute_name}, Valor: {$attribute_value} ***");
                        }
                    }
                    
                    // ** CORREÇÃO DEFINITIVA: GERAR SKU ÚNICO PARA CADA VARIAÇÃO **
                    $original_sku = $variation->get_sku();
                    $variation_data = array();
                    
                    // ** ESTRATÉGIA: GERAR SKU ÚNICO COM TIMESTAMP **
                    if (!empty($original_sku)) {
                        // Gerar SKU único: SKU original + timestamp + index da variação
                        $unique_sku = $original_sku . '-' . time() . '-' . ($index + 1);
                        $variation_data['sku'] = $unique_sku;
                        error_log('*** VARIAÇÃO COM SKU ÚNICO GERADO: ' . $unique_sku . ' ***');
                    } else {
                        // Se não tem SKU, gerar um baseado no produto pai + timestamp
                        $produto_sku = $produto->get_sku();
                        if (!empty($produto_sku)) {
                            $unique_sku = $produto_sku . '-VAR-' . time() . '-' . ($index + 1);
                            $variation_data['sku'] = $unique_sku;
                            error_log('*** VARIAÇÃO SEM SKU ORIGINAL - GERADO: ' . $unique_sku . ' ***');
                        } else {
                            error_log('*** VARIAÇÃO SEM SKU - DEIXANDO VAZIA ***');
                            // Não incluir SKU - deixar WooCommerce gerar
                        }
                    }
                    
                    // Adicionar TODOS os dados da variação (preços, dimensões, peso, imagem, etc.)
                    $variation_data = array_merge($variation_data, array(
                        'regular_price' => $this->aplicar_percentual_preco($variation->get_regular_price(), $lojista_data),
                        'sale_price' => $this->aplicar_percentual_preco($variation->get_sale_price(), $lojista_data),
                        'manage_stock' => (bool) $variation->get_manage_stock(),
                        'stock_quantity' => (int) $variation->get_stock_quantity(),
                        'stock_status' => $variation->get_stock_status(),
                        'weight' => $variation->get_weight(),
                        'dimensions' => array(
                            'length' => $variation->get_length(),
                            'width' => $variation->get_width(),
                            'height' => $variation->get_height()
                        ),
                        'description' => $variation->get_description(),
                        'attributes' => $attributes_array
                    ));
                    
                    // Adicionar imagem da variação se existir E for uma URL válida
                    $variation_image_id = $variation->get_image_id();
                    if ($variation_image_id) {
                        $image_url = wp_get_attachment_url($variation_image_id);
                        if ($image_url && $this->is_valid_external_url($image_url)) {
                            $variation_data['image'] = array('src' => $image_url);
                            error_log("*** VARIAÇÃO COM IMAGEM VÁLIDA: {$image_url} ***");
                        } else {
                            error_log("*** VARIAÇÃO - IMAGEM IGNORADA (URL INVÁLIDA): {$image_url} ***");
                        }
                    }
                    
                    $dados['_variations_data'][] = $variation_data;
                    error_log('*** VARIAÇÃO PROCESSADA - DADOS: ' . print_r($variation_data, true) . ' ***');
                    error_log('IMPORTAÇÃO DEBUG - Atributos da variação: ' . print_r($attributes_array, true));
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
     * Verificar se produto já existe no destino (por SKU)
     */
    public function verificar_produto_existente($sku, $lojista_data) {
        if (empty($sku)) {
            error_log('*** VERIFICAÇÃO FALHADA - SKU VAZIO ***');
            return null;
        }
        
        error_log("*** VERIFICAÇÃO DE DUPLICATA - SKU: {$sku} ***");
        error_log("*** URL DESTINO: " . $lojista_data['url'] . " ***");
        
        $url = trailingslashit($lojista_data['url']) . 'wp-json/wc/v3/products?sku=' . urlencode($sku);
        error_log("*** URL CONSULTA: {$url} ***");
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($lojista_data['consumer_key'] . ':' . $lojista_data['consumer_secret']),
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('*** ERRO - Verificação produto existente: ' . $response->get_error_message() . ' ***');
            return null;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        error_log("*** CÓDIGO RESPOSTA: {$response_code} ***");
        
        $body_raw = wp_remote_retrieve_body($response);
        error_log("*** RESPOSTA RAW: " . substr($body_raw, 0, 500) . " ***");
        
        $body = json_decode($body_raw, true);
        if (!empty($body) && is_array($body) && count($body) > 0) {
            error_log('*** PRODUTO JÁ EXISTE - SKU: ' . $sku . ' (ID: ' . $body[0]['id'] . ') ***');
            error_log('*** RETORNANDO ID PARA PULAR: ' . $body[0]['id'] . ' ***');
            return $body[0]['id'];
        }
        
        error_log('*** PRODUTO NÃO EXISTE - SKU: ' . $sku . ' (será criado) ***');
        error_log('*** RETORNANDO NULL PARA CRIAR ***');
        return null;
    }

    /**
     * Importar produto para destino (usando as credenciais diretamente)
     */
    public function importar_produto_para_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto, $id_destino = null, $opcoes = array()) {
        // Separar dados das variações (se existirem) do produto principal
        $variations_data = null;
        if (isset($dados_produto['_variations_data'])) {
            $variations_data = $dados_produto['_variations_data'];
            unset($dados_produto['_variations_data']);
        }
        
        // Resolver categorias no destino
        if (isset($dados_produto['categories']) && !empty($dados_produto['categories'])) {
            $categorias_resolvidas = $this->resolver_categorias_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto['categories']);
            $dados_produto['categories'] = $categorias_resolvidas;
        }

        // Resolver marcas no destino
        if (isset($dados_produto['brands']) && !empty($dados_produto['brands'])) {
            $marcas_resolvidas = $this->resolver_marcas_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto['brands']);
            $dados_produto['brands'] = $marcas_resolvidas;
        }
        
        // ** CORREÇÃO CRÍTICA: RESOLVER ATRIBUTOS NO DESTINO **
        if (isset($dados_produto['attributes']) && !empty($dados_produto['attributes'])) {
            $atributos_resolvidos = $this->resolver_atributos_destino($destino_url, $consumer_key, $consumer_secret, $dados_produto['attributes']);
            $dados_produto['attributes'] = $atributos_resolvidos;
        }
        
        // Criar novo produto (sempre null = criar novo)
        $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products';
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            ),
            'body'    => json_encode($dados_produto),
            'timeout' => 30,
        ));
        
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
            'message' => 'Produto criado com sucesso'
        );
        
        // Criar variações se necessário
        if ($variations_data && $product_id) {
            error_log("*** INICIANDO PROCESSO DE CRIAÇÃO DE VARIAÇÕES ***");
            error_log("*** PRODUTO PRINCIPAL CRIADO - ID: {$product_id} ***");
            error_log("*** QUANTIDADE DE VARIAÇÕES A CRIAR: " . count($variations_data) . " ***");
            
            $variations_result = $this->criar_variacoes_produto($destino_url, $consumer_key, $consumer_secret, $product_id, $variations_data);
            
            if ($variations_result['success']) {
                $result['message'] .= ' com ' . $variations_result['created_count'] . ' variações criadas';
                error_log("*** VARIAÇÕES CRIADAS COM SUCESSO: " . $variations_result['created_count'] . " ***");
            } else {
                $result['message'] .= ' mas falhou ao criar ' . (count($variations_data) - $variations_result['created_count']) . ' variações';
                error_log("*** FALHA NA CRIAÇÃO DE VARIAÇÕES: " . $variations_result['message'] . " ***");
                
                // Incluir detalhes dos erros no resultado
                if (isset($variations_result['errors'])) {
                    $result['variations_errors'] = $variations_result['errors'];
                }
            }
            
            // Adicionar estatísticas das variações ao resultado
            $result['variations_created'] = $variations_result['created_count'];
            $result['variations_total'] = count($variations_data);
        } else {
            if (!$variations_data) {
                error_log("*** PRODUTO NÃO TEM VARIAÇÕES PARA CRIAR ***");
            } else {
                error_log("*** ERRO: PRODUTO PRINCIPAL NÃO FOI CRIADO (ID: {$product_id}) ***");
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
        $skipped_count = 0;
        
        error_log("*** INICIANDO CRIAÇÃO DE VARIAÇÕES ***");
        error_log("*** PRODUTO ID NO DESTINO: {$product_id} ***");
        error_log("*** TOTAL DE VARIAÇÕES PARA CRIAR: " . count($variations_data) . " ***");
        
        foreach ($variations_data as $index => $variation_data) {
            $variation_number = $index + 1;
            error_log("*** PROCESSANDO VARIAÇÃO {$variation_number}/" . count($variations_data) . " ***");
            
            // Log dos dados da variação sendo enviados
            error_log("*** DADOS DA VARIAÇÃO {$variation_number}: " . print_r($variation_data, true) . " ***");
            
            if (!empty($variation_data['sku'])) {
                error_log("*** VARIAÇÃO {$variation_number} COM SKU: '{$variation_data['sku']}' ***");
            } else {
                error_log("*** VARIAÇÃO {$variation_number} SEM SKU ***");
            }
            
            $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/' . $product_id . '/variations';
            error_log("*** URL CRIAÇÃO VARIAÇÃO: {$url} ***");
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                ),
                'body'    => json_encode($variation_data),
                'timeout' => 60, // Aumentar timeout para evitar problemas de rede
            ));
            
            if (is_wp_error($response)) {
                $error_msg = "Erro ao criar variação {$variation_number}: " . $response->get_error_message();
                error_log("*** ERRO WP_ERROR - VARIAÇÃO {$variation_number}: " . $response->get_error_message() . " ***");
                $errors[] = $error_msg;
                continue;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            error_log("*** RESPOSTA CRIAÇÃO VARIAÇÃO {$variation_number} - CÓDIGO: {$response_code} ***");
            error_log("*** RESPOSTA CRIAÇÃO VARIAÇÃO {$variation_number} - BODY: " . substr($response_body, 0, 500) . " ***");
            
            if ($response_code >= 300) {
                $error_msg = "Erro HTTP {$response_code} ao criar variação {$variation_number}: {$response_body}";
                error_log("*** ERRO HTTP {$response_code} - VARIAÇÃO {$variation_number}: {$response_body} ***");
                $errors[] = $error_msg;
                continue;
            }
            
            // Tentar decodificar a resposta para verificar se foi criada com sucesso
            $response_data = json_decode($response_body, true);
            if ($response_data && isset($response_data['id'])) {
                $created_count++;
                error_log("*** VARIAÇÃO {$variation_number} CRIADA COM SUCESSO - ID: {$response_data['id']} ***");
                
                // Log adicional dos dados da variação criada
                if (!empty($response_data['sku'])) {
                    error_log("*** VARIAÇÃO CRIADA COM SKU: '{$response_data['sku']}' ***");
                }
                if (!empty($response_data['attributes'])) {
                    error_log("*** VARIAÇÃO CRIADA COM ATRIBUTOS: " . print_r($response_data['attributes'], true) . " ***");
                }
            } else {
                $error_msg = "Resposta inválida ao criar variação {$variation_number}: sem ID na resposta";
                error_log("*** ERRO RESPOSTA INVÁLIDA - VARIAÇÃO {$variation_number}: sem ID na resposta ***");
                $errors[] = $error_msg;
                continue;
            }
        }
        
        $total_variations = count($variations_data);
        error_log("*** RESULTADO FINAL CRIAÇÃO VARIAÇÕES ***");
        error_log("*** TOTAL TENTATIVAS: {$total_variations} ***");
        error_log("*** CRIADAS COM SUCESSO: {$created_count} ***");
        error_log("*** ERROS: " . count($errors) . " ***");
        
        if (!empty($errors)) {
            error_log("*** DETALHES DOS ERROS: " . print_r($errors, true) . " ***");
        }
        
        $message_parts = array();
        
        if ($created_count > 0) {
            $message_parts[] = "{$created_count} variações criadas";
        }
        if ($skipped_count > 0) {
            $message_parts[] = "{$skipped_count} variações puladas (já existiam)";
        }
        if (count($errors) > 0) {
            $message_parts[] = count($errors) . " variações falharam";
        }
        
        $success_message = implode(', ', $message_parts);
        
        if (count($errors) === 0) {
            return array(
                'success' => true,
                'created_count' => $created_count,
                'skipped_count' => $skipped_count,
                'message' => $success_message
            );
        } else {
            return array(
                'success' => false,
                'created_count' => $created_count,
                'skipped_count' => $skipped_count,
                'errors' => $errors,
                'message' => $success_message . ': ' . implode(', ', array_slice($errors, 0, 3)) // Limitar a 3 erros na mensagem
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
    
    /**
     * Resolver atributos no destino (conectar com taxonomias existentes)
     */
    private function resolver_atributos_destino($destino_url, $consumer_key, $consumer_secret, $atributos) {
        $atributos_resolvidos = array();
        
        foreach ($atributos as $atributo_data) {
            $nome_atributo = $atributo_data['name'];
            
            // Buscar atributo existente no destino
            $atributo_id = $this->buscar_atributo_por_nome($destino_url, $consumer_key, $consumer_secret, $nome_atributo);
            
            if ($atributo_id) {
                error_log("IMPORTAÇÃO DEBUG - Atributo encontrado no destino: {$nome_atributo} (ID: {$atributo_id})");
                
                // ** CORREÇÃO CRÍTICA: CONECTAR COM TAXONOMIA EXISTENTE **
                $atributos_resolvidos[] = array(
                    'id' => $atributo_id, // ID da taxonomia no destino
                    'name' => $nome_atributo,
                    'options' => $atributo_data['options'], // Termos que queremos
                    'visible' => $atributo_data['visible'],
                    'variation' => $atributo_data['variation']
                );
            } else {
                error_log("IMPORTAÇÃO DEBUG - Atributo NÃO encontrado no destino: {$nome_atributo} - criando como local");
                
                // Se não encontrar, criar como atributo local (comportamento atual)
                $atributos_resolvidos[] = $atributo_data;
            }
        }
        
        return $atributos_resolvidos;
    }
    
    /**
     * Buscar atributo por nome no destino
     */
    private function buscar_atributo_por_nome($destino_url, $consumer_key, $consumer_secret, $nome_atributo) {
        $url = trailingslashit($destino_url) . 'wp-json/wc/v3/products/attributes';
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log("IMPORTAÇÃO DEBUG - Erro ao buscar atributos: " . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body) && is_array($body)) {
            foreach ($body as $atributo) {
                // Verificar se o nome corresponde (com ou sem prefixo pa_)
                $nome_limpo = str_replace('pa_', '', $nome_atributo);
                $nome_destino_limpo = str_replace('pa_', '', $atributo['name']);
                
                if (strtolower($nome_limpo) === strtolower($nome_destino_limpo) || 
                    strtolower($nome_atributo) === strtolower($atributo['name']) ||
                    strtolower($nome_atributo) === strtolower($atributo['slug'])) {
                    error_log("IMPORTAÇÃO DEBUG - Correspondência encontrada: {$nome_atributo} -> {$atributo['name']} (ID: {$atributo['id']})");
                    return $atributo['id'];
                }
            }
        }
        
        error_log("IMPORTAÇÃO DEBUG - Atributo '{$nome_atributo}' não encontrado no destino");
        return false;
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

    /**
     * Resolver marcas no destino
     */
    private function resolver_marcas_destino($destino_url, $consumer_key, $consumer_secret, $marcas) {
        $marcas_resolvidas = array();
        
        foreach ($marcas as $marca_data) {
            // Buscar marca existente por slug
            $marca_id = $this->buscar_marca_por_slug($destino_url, $consumer_key, $consumer_secret, $marca_data['slug']);
            
            if ($marca_id) {
                error_log('IMPORTAÇÃO DEBUG - Marca encontrada no destino: ' . $marca_data['name'] . ' (ID: ' . $marca_id . ')');
                $marcas_resolvidas[] = array('id' => $marca_id);
            } else {
                // Criar nova marca
                $nova_marca_id = $this->criar_marca_destino($destino_url, $consumer_key, $consumer_secret, $marca_data);
                if ($nova_marca_id) {
                    error_log('IMPORTAÇÃO DEBUG - Marca criada no destino: ' . $marca_data['name'] . ' (ID: ' . $nova_marca_id . ')');
                    $marcas_resolvidas[] = array('id' => $nova_marca_id);
                } else {
                    error_log('IMPORTAÇÃO DEBUG - Falha ao criar marca no destino: ' . $marca_data['name']);
                }
            }
        }
        
        return $marcas_resolvidas;
    }

    /**
     * Buscar marca por slug no destino
     */
    private function buscar_marca_por_slug($destino_url, $consumer_key, $consumer_secret, $slug) {
        // Tentar diferentes endpoints para marcas (depende do plugin usado)
        $endpoints = array(
            'wp-json/wc/v3/products/brands?slug=' . urlencode($slug),
            'wp-json/wp/v2/product_brand?slug=' . urlencode($slug)
        );
        
        foreach ($endpoints as $endpoint) {
            $url = trailingslashit($destino_url) . $endpoint;
            
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                )
            ));
            
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body) && is_array($body) && isset($body[0]['id'])) {
                    return $body[0]['id'];
                }
            }
        }
        
        return false;
    }

    /**
     * Criar marca no destino
     */
    private function criar_marca_destino($destino_url, $consumer_key, $consumer_secret, $marca_data) {
        // Tentar diferentes endpoints para criar marcas
        $endpoints = array(
            'wp-json/wc/v3/products/brands',
            'wp-json/wp/v2/product_brand'
        );
        
        foreach ($endpoints as $endpoint) {
            $url = trailingslashit($destino_url) . $endpoint;
            
            $response = wp_remote_post($url, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($marca_data)
            ));
            
            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 201) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['id'])) {
                        return $body['id'];
                    }
                }
            }
        }
        
        return false;
    }
}
