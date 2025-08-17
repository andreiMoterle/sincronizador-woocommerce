<?php
/**
 * Debug do histórico de envios e produtos sincronizados
 */

// Carrega o WordPress
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Verifica se é chamada via browser
if (!defined('WP_CLI') && php_sapi_name() !== 'cli') {
    // Verifica permissões do usuário
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
}

echo "<h2>Debug do Histórico de Envios</h2>\n";

$historico_envios = get_option('sincronizador_wc_historico_envios', array());

echo "<p><strong>Total de lojistas no histórico:</strong> " . count($historico_envios) . "</p>\n";

if (empty($historico_envios)) {
    echo "<p style='color: red;'><strong>❌ NENHUM HISTÓRICO DE ENVIOS ENCONTRADO!</strong></p>\n";
    echo "<p>Isso significa que nenhum produto foi sincronizado ainda.</p>\n";
    echo "<p>Para sincronizar produtos:</p>\n";
    echo "<ol>\n";
    echo "<li>Vá em <strong>Sincronizador WC > Dashboard</strong></li>\n";
    echo "<li>Clique em <strong>'Sincronizar Produtos'</strong> para algum lojista</li>\n";
    echo "<li>Ou vá em <strong>Importar Produtos</strong> e importe alguns produtos</li>\n";
    echo "</ol>\n";
} else {
    echo "<h3>Histórico de Envios por Lojista:</h3>\n";
    
    foreach ($historico_envios as $lojista_url => $produtos) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
        echo "<h4>Lojista: " . htmlspecialchars($lojista_url) . "</h4>\n";
        echo "<p><strong>Total de produtos sincronizados:</strong> " . count($produtos) . "</p>\n";
        
        if (!empty($produtos)) {
            echo "<table border='1' cellpadding='5'>\n";
            echo "<tr><th>ID Fábrica</th><th>ID Destino</th><th>Produto (Nome/SKU)</th></tr>\n";
            
            foreach ($produtos as $produto_id_fabrica => $produto_id_destino) {
                $produto = wc_get_product($produto_id_fabrica);
                $nome_produto = $produto ? $produto->get_name() . ' (SKU: ' . $produto->get_sku() . ')' : 'Produto não encontrado';
                
                echo "<tr>\n";
                echo "<td>" . htmlspecialchars($produto_id_fabrica) . "</td>\n";
                echo "<td>" . htmlspecialchars($produto_id_destino) . "</td>\n";
                echo "<td>" . htmlspecialchars($nome_produto) . "</td>\n";
                echo "</tr>\n";
            }
            
            echo "</table>\n";
        }
        echo "</div>\n";
    }
}

// Verificar a estrutura de dados
echo "<h3>Estrutura Completa (JSON):</h3>\n";
echo "<pre style='background: #f0f0f0; padding: 10px; overflow: auto; max-height: 300px;'>\n";
echo htmlspecialchars(json_encode($historico_envios, JSON_PRETTY_PRINT));
echo "</pre>\n";

// Verificar lojistas cadastrados para comparação
echo "<h3>Lojistas Cadastrados:</h3>\n";
$lojistas = get_option('sincronizador_wc_lojistas', array());
if (!empty($lojistas)) {
    echo "<ul>\n";
    foreach ($lojistas as $lojista) {
        echo "<li><strong>" . htmlspecialchars($lojista['nome']) . "</strong> - " . htmlspecialchars($lojista['url']) . "</li>\n";
    }
    echo "</ul>\n";
} else {
    echo "<p style='color: red;'>Nenhum lojista cadastrado.</p>\n";
}

?>
