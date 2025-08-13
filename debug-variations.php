<?php
/**
 * Arquivo de debug para testar a importação de variações
 * Executar este arquivo para verificar se o sistema está funcionando
 */

// Simular ambiente WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', 'c:/laragon/www/loja-woocommerce/');
}

echo "=== TESTE DE DEPURAÇÃO SINCRONIZADOR WC ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

echo "1. Verificando se o arquivo da classe existe...\n";
$class_file = __DIR__ . '/includes/class-product-importer.php';
if (file_exists($class_file)) {
    echo "✓ Arquivo encontrado: {$class_file}\n";
} else {
    echo "✗ Arquivo não encontrado: {$class_file}\n";
    exit;
}

echo "\n2. Verificando estrutura do código...\n";
$content = file_get_contents($class_file);

// Verificar se as principais funções estão presentes
$functions_to_check = [
    'criar_variacoes_produto',
    'montar_dados_produto', 
    'importar_produto_para_destino',
    'ajax_import_produtos'
];

foreach ($functions_to_check as $function) {
    if (strpos($content, "function {$function}") !== false) {
        echo "✓ Função encontrada: {$function}\n";
    } else {
        echo "✗ Função não encontrada: {$function}\n";
    }
}

echo "\n3. Verificando logs importantes...\n";
$important_logs = [
    'INICIANDO CRIAÇÃO DE VARIAÇÕES',
    'VARIAÇÃO CRIADA COM SUCESSO',
    'PRODUTO PRINCIPAL CRIADO',
    'RESPOSTA CRIAÇÃO VARIAÇÃO'
];

foreach ($important_logs as $log) {
    if (strpos($content, $log) !== false) {
        echo "✓ Log encontrado: {$log}\n";
    } else {
        echo "✗ Log não encontrado: {$log}\n";
    }
}

echo "\n4. Verificando correções aplicadas...\n";
$corrections = [
    'ajax_import_produtos.*array.*this' => 'AJAX handler reativado',
    'INICIANDO CRIAÇÃO DE VARIAÇÕES' => 'Logging detalhado adicionado',
    'SKU PRÓPRIO' => 'Correção SKU variações',
    'variations_errors' => 'Tratamento de erros melhorado'
];

foreach ($corrections as $pattern => $description) {
    if (preg_match("/{$pattern}/i", $content)) {
        echo "✓ Correção aplicada: {$description}\n";
    } else {
        echo "✗ Correção não encontrada: {$description}\n";
    }
}

echo "\n=== RESUMO ===\n";
echo "O plugin foi atualizado com as seguintes melhorias:\n";
echo "- Handler AJAX reativado para importação\n";
echo "- Logging detalhado para processo de criação de variações\n";
echo "- Correção do problema de SKUs duplicados nas variações\n";
echo "- Melhor tratamento de erros e resposta da API\n";
echo "- Timeout aumentado para evitar problemas de rede\n";

echo "\n=== PRÓXIMOS PASSOS ===\n";
echo "1. Testar importação de produto variável no WordPress\n";
echo "2. Verificar logs em wp-content/debug.log\n";
echo "3. Confirmar se as variações são criadas no destino\n";
echo "4. Verificar se os atributos estão corretos\n";

echo "\n=== FIM DO TESTE ===\n";
