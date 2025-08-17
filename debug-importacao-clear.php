<?php
/**
 * Script para debug do plugin
 */

// Limpar log anterior
file_put_contents('debug-importacao.log', '');

echo "=== DEBUG IMPORTAÇÃO ===\n";
echo "Log limpo. Acesse a página de importação e teste a funcionalidade.\n";
echo "Depois execute: php debug-importacao-show.php\n";
echo "========================\n";
?>
