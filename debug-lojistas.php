<?php
/**
 * Arquivo de debug para verificar os lojistas cadastrados
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

echo "<h2>Debug dos Lojistas Cadastrados</h2>\n";

$lojistas = get_option('sincronizador_wc_lojistas', array());

echo "<p><strong>Total de lojistas encontrados:</strong> " . count($lojistas) . "</p>\n";

if (empty($lojistas)) {
    echo "<p style='color: red;'><strong>❌ NENHUM LOJISTA CADASTRADO!</strong></p>\n";
    echo "<p>Para cadastrar lojistas, vá em <strong>WordPress Admin > Sincronizador WC > Dashboard</strong></p>\n";
} else {
    echo "<h3>Lista de Lojistas:</h3>\n";
    
    foreach ($lojistas as $index => $lojista) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
        echo "<h4>Lojista #" . ($index + 1) . "</h4>\n";
        
        // Verificar campos obrigatórios
        $campos_obrigatorios = ['id', 'nome', 'url', 'consumer_key', 'consumer_secret'];
        $campos_faltando = [];
        
        foreach ($campos_obrigatorios as $campo) {
            if (!isset($lojista[$campo]) || empty($lojista[$campo])) {
                $campos_faltando[] = $campo;
            }
        }
        
        if (!empty($campos_faltando)) {
            echo "<p style='color: red;'><strong>❌ CAMPOS FALTANDO:</strong> " . implode(', ', $campos_faltando) . "</p>\n";
        } else {
            echo "<p style='color: green;'><strong>✅ TODOS OS CAMPOS OBRIGATÓRIOS PRESENTES</strong></p>\n";
        }
        
        // Mostrar dados do lojista (mascarando credenciais)
        echo "<table border='1' cellpadding='5'>\n";
        foreach ($lojista as $key => $value) {
            $display_value = $value;
            
            // Mascarar credenciais sensíveis
            if (in_array($key, ['consumer_key', 'consumer_secret'])) {
                $display_value = substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
            }
            
            echo "<tr><td><strong>" . htmlspecialchars($key) . "</strong></td><td>" . htmlspecialchars($display_value) . "</td></tr>\n";
        }
        echo "</table>\n";
        echo "</div>\n";
    }
}

// Verificar se a estrutura de dados está correta
echo "<h3>Estrutura de Dados (JSON):</h3>\n";
echo "<pre style='background: #f0f0f0; padding: 10px; overflow: auto;'>\n";
echo htmlspecialchars(json_encode($lojistas, JSON_PRETTY_PRINT));
echo "</pre>\n";

// Testar uma conexão se há lojistas
if (!empty($lojistas)) {
    echo "<h3>Teste de Conexão (primeiro lojista):</h3>\n";
    $primeiro_lojista = $lojistas[0];
    
    if (isset($primeiro_lojista['url']) && isset($primeiro_lojista['consumer_key']) && isset($primeiro_lojista['consumer_secret'])) {
        $url = trailingslashit($primeiro_lojista['url']) . 'wp-json/wc/v3/system_status';
        
        echo "<p><strong>URL de teste:</strong> " . htmlspecialchars($url) . "</p>\n";
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($primeiro_lojista['consumer_key'] . ':' . $primeiro_lojista['consumer_secret']),
                'Content-Type' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            echo "<p style='color: red;'><strong>❌ Erro de conexão:</strong> " . $response->get_error_message() . "</p>\n";
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            echo "<p><strong>Response Code:</strong> " . $response_code . "</p>\n";
            
            if ($response_code === 200) {
                echo "<p style='color: green;'><strong>✅ CONEXÃO FUNCIONANDO!</strong></p>\n";
                
                $data = json_decode($body, true);
                if (isset($data['environment']['version'])) {
                    echo "<p><strong>WooCommerce versão:</strong> " . $data['environment']['version'] . "</p>\n";
                }
            } else {
                echo "<p style='color: red;'><strong>❌ Erro HTTP " . $response_code . "</strong></p>\n";
                echo "<p><strong>Response Body:</strong></p>\n";
                echo "<pre style='background: #ffe6e6; padding: 10px; overflow: auto;'>" . htmlspecialchars(substr($body, 0, 500)) . "</pre>\n";
            }
        }
    } else {
        echo "<p style='color: red;'><strong>❌ Dados insuficientes para teste de conexão</strong></p>\n";
    }
}

?>
