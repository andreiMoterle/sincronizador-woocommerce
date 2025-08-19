<?php
/**
 * Teste simples para verificar se as rotas estão registradas
 * Adicione este código no final do functions.php do tema ou execute via WP-CLI
 */

// Hook para verificar as rotas quando o WordPress carrega
add_action('wp_loaded', function() {
    if (current_user_can('manage_options')) {
        $rest_server = rest_get_server();
        $routes = $rest_server->get_routes();
        
        $sincronizador_routes = array();
        foreach ($routes as $route => $handlers) {
            if (strpos($route, 'sincronizador-wc') !== false) {
                $sincronizador_routes[$route] = $handlers;
            }
        }
        
        // Log das rotas encontradas
        error_log("=== ROTAS SINCRONIZADOR ENCONTRADAS ===");
        if (empty($sincronizador_routes)) {
            error_log("❌ Nenhuma rota encontrada para sincronizador-wc");
        } else {
            foreach ($sincronizador_routes as $route => $handlers) {
                error_log("✅ Rota encontrada: $route");
                foreach ($handlers as $handler) {
                    $methods = isset($handler['methods']) ? implode(', ', array_keys($handler['methods'])) : 'N/A';
                    error_log("   Métodos: $methods");
                }
            }
        }
        error_log("=== FIM DAS ROTAS ===");
    }
});

// Função para testar classe
add_action('admin_init', function() {
    if (current_user_can('manage_options')) {
        error_log("=== TESTE DE CLASSES ===");
        
        if (class_exists('Sincronizador_WC_Master_API')) {
            error_log("✅ Classe Sincronizador_WC_Master_API existe");
        } else {
            error_log("❌ Classe Sincronizador_WC_Master_API NÃO existe");
        }
        
        // Verificar se o arquivo existe
        $file_path = WP_PLUGIN_DIR . '/sincronizador-woocommerce/api/class-master-api.php';
        if (file_exists($file_path)) {
            error_log("✅ Arquivo master-api encontrado: $file_path");
        } else {
            error_log("❌ Arquivo master-api NÃO encontrado: $file_path");
        }
        
        error_log("=== FIM TESTE CLASSES ===");
    }
});

?>
