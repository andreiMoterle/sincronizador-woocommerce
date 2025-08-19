<?php
/**
 * Debug das rotas REST API
 * Acesse: https://fabrica.prometas.store/wp-content/plugins/sincronizador-woocommerce/debug-routes.php
 */

// Simular ambiente WordPress mínimo
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

header('Content-Type: application/json');

echo "🔍 DEBUG DAS ROTAS REST API\n";
echo "==========================\n\n";

// Verificar se a classe Master API existe
if (class_exists('Sincronizador_WC_Master_API')) {
    echo "✅ Classe Sincronizador_WC_Master_API carregada\n";
} else {
    echo "❌ Classe Sincronizador_WC_Master_API NÃO encontrada\n";
    
    // Tentar carregar manualmente
    $file_path = dirname(__FILE__) . '/api/class-master-api.php';
    if (file_exists($file_path)) {
        echo "📂 Arquivo encontrado em: $file_path\n";
        require_once $file_path;
        
        if (class_exists('Sincronizador_WC_Master_API')) {
            echo "✅ Classe carregada manualmente com sucesso\n";
        } else {
            echo "❌ Erro ao carregar classe manualmente\n";
        }
    } else {
        echo "❌ Arquivo class-master-api.php não encontrado em: $file_path\n";
    }
}

echo "\n";

// Listar todas as rotas disponíveis
$rest_server = rest_get_server();
$routes = $rest_server->get_routes();

echo "📋 ROTAS REST DISPONÍVEIS:\n";
echo "--------------------------\n";

$found_master_routes = false;

foreach ($routes as $route => $handlers) {
    if (strpos($route, 'sincronizador-wc') !== false) {
        echo "✅ $route\n";
        $found_master_routes = true;
        
        foreach ($handlers as $handler) {
            $methods = isset($handler['methods']) ? implode(', ', array_keys($handler['methods'])) : 'N/A';
            echo "   Métodos: $methods\n";
            
            if (isset($handler['callback'])) {
                if (is_array($handler['callback'])) {
                    $callback_info = get_class($handler['callback'][0]) . '::' . $handler['callback'][1];
                } else {
                    $callback_info = $handler['callback'];
                }
                echo "   Callback: $callback_info\n";
            }
        }
        echo "\n";
    }
}

if (!$found_master_routes) {
    echo "❌ Nenhuma rota do sincronizador-wc encontrada\n\n";
    
    echo "🔧 VERIFICAÇÕES ADICIONAIS:\n";
    echo "---------------------------\n";
    
    // Verificar se o plugin está ativo
    if (is_plugin_active('sincronizador-woocommerce/sincronizador-woocommerce.php')) {
        echo "✅ Plugin Sincronizador WC está ativo\n";
    } else {
        echo "❌ Plugin Sincronizador WC NÃO está ativo\n";
    }
    
    // Verificar hook rest_api_init
    echo "\n📡 HOOKS rest_api_init:\n";
    global $wp_filter;
    if (isset($wp_filter['rest_api_init'])) {
        $hooks = $wp_filter['rest_api_init']->callbacks;
        foreach ($hooks as $priority => $callbacks) {
            foreach ($callbacks as $hook_id => $callback_data) {
                if (strpos($hook_id, 'master') !== false || strpos($hook_id, 'sincronizador') !== false) {
                    echo "   Prioridade $priority: $hook_id\n";
                }
            }
        }
    }
}

echo "\n🔗 TESTE DIRETO:\n";
echo "----------------\n";

// Tentar acessar endpoint diretamente
$endpoint_url = home_url('/wp-json/sincronizador-wc/v1/master/health');
echo "URL de teste: $endpoint_url\n";

// Fazer request interno
$request = new WP_REST_Request('GET', '/sincronizador-wc/v1/master/health');
$response = rest_do_request($request);

if ($response->is_error()) {
    echo "❌ Erro no request interno: " . $response->get_error_message() . "\n";
} else {
    echo "✅ Request interno funcionou\n";
    echo "Response: " . json_encode($response->get_data()) . "\n";
}

echo "\n📊 INFORMAÇÕES DO SISTEMA:\n";
echo "---------------------------\n";
echo "WordPress Version: " . get_bloginfo('version') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Plugin Dir: " . plugin_dir_path(__FILE__) . "\n";
echo "Home URL: " . home_url() . "\n";
echo "Rest URL Base: " . rest_url() . "\n";

?>
