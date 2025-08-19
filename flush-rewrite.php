<?php
/**
 * Script para forçar atualização das regras de reescrita
 * Acesse: https://fabrica.prometas.store/wp-content/plugins/sincronizador-woocommerce/flush-rewrite.php
 */

// Carregar WordPress
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Verificar se é admin
if (!current_user_can('manage_options')) {
    die('Acesso negado');
}

echo "<h1>🔧 Flush Rewrite Rules</h1>";

// Forçar atualização das regras de reescrita
flush_rewrite_rules(true);

echo "<p>✅ Regras de reescrita atualizadas com sucesso!</p>";

// Listar todas as rotas REST registradas
$rest_server = rest_get_server();
$routes = $rest_server->get_routes();

echo "<h2>📋 Rotas REST Disponíveis:</h2>";
echo "<ul>";

$found_sincronizador = false;
foreach ($routes as $route => $handlers) {
    if (strpos($route, 'sincronizador-wc') !== false) {
        echo "<li><strong>$route</strong></li>";
        $found_sincronizador = true;
    }
}

if (!$found_sincronizador) {
    echo "<li style='color: red;'>❌ Nenhuma rota sincronizador-wc encontrada</li>";
}

echo "</ul>";

// Verificar se classe existe
echo "<h2>🔍 Verificação de Classes:</h2>";
echo "<ul>";

if (class_exists('Sincronizador_WC_Master_API')) {
    echo "<li>✅ Classe Sincronizador_WC_Master_API existe</li>";
} else {
    echo "<li style='color: red;'>❌ Classe Sincronizador_WC_Master_API NÃO existe</li>";
}

// Verificar arquivo
$file_path = WP_PLUGIN_DIR . '/sincronizador-woocommerce/api/class-master-api.php';
if (file_exists($file_path)) {
    echo "<li>✅ Arquivo master-api.php existe</li>";
} else {
    echo "<li style='color: red;'>❌ Arquivo master-api.php NÃO existe</li>";
}

echo "</ul>";

// Tentar carregar manualmente
if (!class_exists('Sincronizador_WC_Master_API') && file_exists($file_path)) {
    echo "<h2>🔄 Tentando carregar manualmente...</h2>";
    require_once $file_path;
    
    if (class_exists('Sincronizador_WC_Master_API')) {
        echo "<p>✅ Classe carregada com sucesso!</p>";
        
        // Tentar inicializar
        new Sincronizador_WC_Master_API();
        echo "<p>✅ Classe inicializada!</p>";
        
        // Atualizar rotas novamente
        flush_rewrite_rules(true);
        echo "<p>✅ Regras atualizadas novamente!</p>";
        
    } else {
        echo "<p style='color: red;'>❌ Falha ao carregar classe</p>";
    }
}

echo "<h2>🧪 Teste Manual:</h2>";
echo "<p>Agora teste estes endpoints:</p>";
echo "<ul>";
echo "<li><a href='" . home_url('/wp-json/') . "' target='_blank'>" . home_url('/wp-json/') . "</a></li>";
echo "<li><a href='" . home_url('/wp-json/sincronizador-wc/v1/master/health') . "' target='_blank'>" . home_url('/wp-json/sincronizador-wc/v1/master/health') . "</a></li>";
echo "<li><a href='" . home_url('/wp-json/sincronizador-wc/v1/master/fabrica-status') . "' target='_blank'>" . home_url('/wp-json/sincronizador-wc/v1/master/fabrica-status') . "</a></li>";
echo "</ul>";

echo "<p><strong>Lembre-se:</strong> Use Authorization: Bearer " . get_option('sincronizador_wc_master_token', 'TOKEN_NAO_ENCONTRADO') . "</p>";

?>
