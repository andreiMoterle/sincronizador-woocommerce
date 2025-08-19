<?php
/**
 * Script para verificar se a Master API está funcionando
 * Execute: php test-api-simple.php
 */

// Simular algumas funções do WordPress
if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'https://fabrica.prometas.store' . $path;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = '') {
        // Simular opções para teste
        $options = array(
            'sincronizador_wc_master_token' => 'sync_58dce278652371214434585cffca87a6'
        );
        return isset($options[$option]) ? $options[$option] : $default;
    }
}

echo "🔧 TESTE SIMPLES DA MASTER API\n";
echo "==============================\n\n";

echo "📍 URL Base: " . home_url() . "\n";
echo "🔑 Token: " . get_option('sincronizador_wc_master_token') . "\n\n";

// URLs para testar
$urls = array(
    home_url('/wp-json/'),
    home_url('/wp-json/wp/v2/'),
    home_url('/wp-json/sincronizador-wc/v1/master/health'),
    home_url('/wp-json/sincronizador-wc/v1/master/fabrica-status')
);

foreach ($urls as $url) {
    echo "🌐 Testando: $url\n";
    
    // Usar cURL para testar
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Adicionar token para endpoints do sincronizador
    if (strpos($url, 'sincronizador-wc') !== false) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . get_option('sincronizador_wc_master_token'),
            'Content-Type: application/json'
        ));
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "   ❌ Erro cURL: $error\n";
    } else {
        echo "   📊 HTTP $http_code\n";
        
        if ($http_code == 200) {
            echo "   ✅ Resposta OK\n";
            
            // Tentar decodificar JSON
            $data = json_decode($response, true);
            if ($data) {
                echo "   📄 JSON válido recebido\n";
                if (isset($data['success'])) {
                    echo "   🎯 API funcionando: " . ($data['success'] ? 'SIM' : 'NÃO') . "\n";
                }
            } else {
                echo "   📄 Resposta (primeiros 100 chars): " . substr($response, 0, 100) . "...\n";
            }
        } elseif ($http_code == 404) {
            echo "   ❌ Rota não encontrada\n";
        } elseif ($http_code == 401 || $http_code == 403) {
            echo "   🔒 Problema de autenticação\n";
        } else {
            echo "   ⚠️  Código inesperado\n";
        }
    }
    
    echo "\n";
}

echo "📋 POSSÍVEIS SOLUÇÕES:\n";
echo "======================\n";
echo "1. ✅ Verificar se o plugin está ativo\n";
echo "2. ✅ Limpar cache do WordPress\n";
echo "3. ✅ Verificar permalinks (Configurações > Links permanentes > Salvar)\n";
echo "4. ✅ Verificar se o arquivo class-master-api.php existe\n";
echo "5. ✅ Verificar logs de erro do WordPress\n";
echo "6. ✅ Testar endpoint básico: " . home_url('/wp-json/') . "\n\n";

?>
