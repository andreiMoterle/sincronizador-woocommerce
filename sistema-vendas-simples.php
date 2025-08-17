<?php
/**
 * SISTEMA SIMPLES DE VENDAS - ADICIONAR NO FUNCTIONS.PHP DA LOJA DE DESTINO
 * 
 * Este código:
 * 1. Cria uma tabela para armazenar vendas por produto
 * 2. Atualiza automaticamente quando há vendas
 * 3. Fornece endpoint público (sem autenticação) para consultar vendas
 */

// Prevenir carregamento múltiplo
if (defined('SINCRONIZADOR_VENDAS_LOADED')) {
    return;
}
define('SINCRONIZADOR_VENDAS_LOADED', true);

// ========================================
// 1. CRIAR TABELA DE VENDAS
// ========================================

function sincronizador_criar_tabela_vendas() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'sincronizador_vendas';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        produto_id int(11) NOT NULL,
        variacao_id int(11) DEFAULT NULL,
        total_vendas int(11) DEFAULT 0,
        atributos text DEFAULT NULL,
        ultima_atualizacao datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY produto_variacao (produto_id, variacao_id),
        KEY produto_id (produto_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    error_log("SINCRONIZADOR VENDAS - Tabela criada/atualizada: $table_name");
}

// Criar tabela na ativação do tema ou quando necessário
add_action('after_setup_theme', 'sincronizador_criar_tabela_vendas');

// ========================================
// 2. SINCRONIZAR VENDAS AUTOMATICAMENTE
// ========================================

function sincronizador_atualizar_vendas_produto($produto_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'sincronizador_vendas';
    
    error_log("SINCRONIZADOR VENDAS - Atualizando vendas para produto: $produto_id");
    
    $produto = wc_get_product($produto_id);
    if (!$produto) {
        return;
    }
    
    if ($produto->is_type('simple')) {
        // Produto simples
        $total_vendas = get_post_meta($produto_id, '_total_sales', true) ?: 0;
        
        $wpdb->replace(
            $table_name,
            array(
                'produto_id' => $produto_id,
                'variacao_id' => null,
                'total_vendas' => $total_vendas,
                'atributos' => null
            ),
            array('%d', '%s', '%d', '%s')
        );
        
        error_log("SINCRONIZADOR VENDAS - Produto simples $produto_id: $total_vendas vendas");
        
    } else if ($produto->is_type('variable')) {
        // Produto variável - calcular total e por variação
        $total_vendas_produto = 0;
        $variacoes = $produto->get_children();
        
        foreach ($variacoes as $variacao_id) {
            // Contar vendas desta variação
            $sql_variacao = "
                SELECT COUNT(*) as vendas_variacao
                FROM {$wpdb->prefix}woocommerce_order_items oi 
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id 
                WHERE im.meta_key = '_variation_id' 
                AND im.meta_value = %d
            ";
            
            $vendas_variacao = intval($wpdb->get_var($wpdb->prepare($sql_variacao, $variacao_id)) ?: 0);
            $total_vendas_produto += $vendas_variacao;
            
            // Buscar atributos da variação
            $variacao_obj = wc_get_product($variacao_id);
            $atributos = '';
            if ($variacao_obj) {
                $variation_attributes = $variacao_obj->get_variation_attributes();
                $attr_parts = array();
                
                foreach ($variation_attributes as $attr_name => $attr_value) {
                    $clean_name = str_replace(['attribute_', 'pa_'], '', $attr_name);
                    $clean_name = ucfirst(str_replace('_', ' ', $clean_name));
                    
                    if (!empty($attr_value)) {
                        $attr_parts[] = $clean_name . ': ' . $attr_value;
                    }
                }
                
                $atributos = implode(', ', $attr_parts);
            }
            
            // Salvar vendas da variação
            $wpdb->replace(
                $table_name,
                array(
                    'produto_id' => $produto_id,
                    'variacao_id' => $variacao_id,
                    'total_vendas' => $vendas_variacao,
                    'atributos' => $atributos
                ),
                array('%d', '%d', '%d', '%s')
            );
            
            error_log("SINCRONIZADOR VENDAS - Variação $variacao_id: $vendas_variacao vendas - $atributos");
        }
        
        // Salvar total do produto variável
        $wpdb->replace(
            $table_name,
            array(
                'produto_id' => $produto_id,
                'variacao_id' => null,
                'total_vendas' => $total_vendas_produto,
                'atributos' => null
            ),
            array('%d', '%s', '%d', '%s')
        );
        
        error_log("SINCRONIZADOR VENDAS - Produto variável $produto_id: $total_vendas_produto vendas totais");
    }
}

// ========================================
// 3. HOOKS PARA ATUALIZAR AUTOMATICAMENTE
// ========================================

// Atualizar quando pedido muda de status
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    if (in_array($new_status, array('completed', 'processing', 'on-hold'))) {
        $order = wc_get_order($order_id);
        if ($order) {
            foreach ($order->get_items() as $item) {
                $produto_id = $item->get_product_id();
                if ($produto_id) {
                    sincronizador_atualizar_vendas_produto($produto_id);
                }
            }
        }
    }
}, 10, 3);

// Atualizar diariamente (cron job)
add_action('wp', function() {
    if (!wp_next_scheduled('sincronizador_atualizar_vendas_diario')) {
        wp_schedule_event(time(), 'daily', 'sincronizador_atualizar_vendas_diario');
    }
});

add_action('sincronizador_atualizar_vendas_diario', function() {
    global $wpdb;
    
    // Buscar todos os produtos que foram vendidos nos últimos 30 dias
    $produtos_vendidos = $wpdb->get_col("
        SELECT DISTINCT p.post_parent 
        FROM {$wpdb->prefix}woocommerce_order_items oi 
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON oi.order_item_id = im.order_item_id 
        JOIN {$wpdb->prefix}posts p ON im.meta_value = p.ID 
        JOIN {$wpdb->prefix}posts order_post ON oi.order_id = order_post.ID
        WHERE im.meta_key = '_variation_id' 
        AND p.post_type = 'product_variation'
        AND order_post.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    foreach ($produtos_vendidos as $produto_id) {
        if ($produto_id) {
            sincronizador_atualizar_vendas_produto($produto_id);
        }
    }
    
    error_log("SINCRONIZADOR VENDAS - Atualização diária concluída: " . count($produtos_vendidos) . " produtos");
});

// ========================================
// 4. ENDPOINT PÚBLICO (SEM AUTENTICAÇÃO!)
// ========================================

add_action('rest_api_init', function () {
    register_rest_route('sincronizador/v1', '/vendas-simples/(?P<produto_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'sincronizador_get_vendas_simples',
        'permission_callback' => '__return_true', // PÚBLICO!
        'args' => array(
            'produto_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});

function sincronizador_get_vendas_simples($request) {
    global $wpdb;
    
    $produto_id = $request['produto_id'];
    $table_name = $wpdb->prefix . 'sincronizador_vendas';
    
    error_log("SINCRONIZADOR VENDAS SIMPLES - Consultando produto: $produto_id");
    
    // Buscar dados da tabela
    $vendas_data = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $table_name 
        WHERE produto_id = %d 
        ORDER BY variacao_id ASC
    ", $produto_id));
    
    if (empty($vendas_data)) {
        // Se não tem dados, tentar atualizar primeiro
        sincronizador_atualizar_vendas_produto($produto_id);
        
        // Buscar novamente
        $vendas_data = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_name 
            WHERE produto_id = %d 
            ORDER BY variacao_id ASC
        ", $produto_id));
    }
    
    $total_vendas = 0;
    $vendas_por_variacao = array();
    
    foreach ($vendas_data as $venda) {
        if (is_null($venda->variacao_id)) {
            // Total do produto
            $total_vendas = intval($venda->total_vendas);
        } else {
            // Vendas por variação
            $vendas_por_variacao[] = array(
                'variacao_id' => intval($venda->variacao_id),
                'vendas' => intval($venda->total_vendas),
                'atributos' => $venda->atributos ?: ''
            );
        }
    }
    
    $produto = wc_get_product($produto_id);
    $tipo_produto = $produto ? $produto->get_type() : 'desconhecido';
    
    $response = array(
        'produto_id' => $produto_id,
        'tipo_produto' => $tipo_produto,
        'total_vendas' => $total_vendas,
        'metodo' => 'tabela_sincronizada',
        'timestamp' => date('Y-m-d H:i:s')
    );
    
    if (!empty($vendas_por_variacao)) {
        $response['vendas_por_variacao'] = $vendas_por_variacao;
    }
    
    error_log("SINCRONIZADOR VENDAS SIMPLES - Resposta: " . json_encode($response));
    
    return $response;
}

// ========================================
// 5. COMANDO MANUAL PARA SINCRONIZAR TUDO
// ========================================

// Adicionar link no admin para sincronizar manualmente
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (current_user_can('manage_options')) {
        $wp_admin_bar->add_node(array(
            'id' => 'sincronizar_vendas_manual',
            'title' => 'Sincronizar Vendas',
            'href' => admin_url('admin.php?action=sincronizar_vendas_manual'),
        ));
    }
}, 100);

add_action('admin_action_sincronizar_vendas_manual', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão');
    }
    
    global $wpdb;
    
    // Buscar todos os produtos variáveis
    $produtos = $wpdb->get_col("
        SELECT ID FROM {$wpdb->prefix}posts 
        WHERE post_type = 'product' 
        AND post_status = 'publish'
    ");
    
    $count = 0;
    foreach ($produtos as $produto_id) {
        sincronizador_atualizar_vendas_produto($produto_id);
        $count++;
    }
    
    wp_redirect(admin_url('?sincronizacao_vendas=ok&produtos=' . $count));
    exit;
});

add_action('admin_notices', function() {
    if (isset($_GET['sincronizacao_vendas']) && $_GET['sincronizacao_vendas'] == 'ok') {
        $produtos = intval($_GET['produtos'] ?? 0);
        echo '<div class="notice notice-success"><p><strong>Vendas sincronizadas!</strong> ' . $produtos . ' produtos atualizados.</p></div>';
    }
});

// Log de sistema carregado apenas em debug mode
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("SINCRONIZADOR VENDAS - Sistema carregado com sucesso!");
}
