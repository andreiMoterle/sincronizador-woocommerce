<?php
/**
 * Script para mostrar logs do debug
 */

echo "=== LOGS DE DEBUG IMPORTAÇÃO ===\n";

// Verificar se existe log do WordPress
$wp_log = ini_get('error_log');
if ($wp_log && file_exists($wp_log)) {
    echo "=== WordPress Error Log ===\n";
    $lines = file($wp_log);
    $recent_lines = array_slice($lines, -50); // Últimas 50 linhas
    foreach ($recent_lines as $line) {
        if (strpos($line, 'DEBUG AJAX IMPORTAÇÃO') !== false) {
            echo $line;
        }
    }
}

// Verificar log local
if (file_exists('debug-importacao.log')) {
    echo "\n=== Log Local ===\n";
    echo file_get_contents('debug-importacao.log');
}

echo "\n=== FIM DOS LOGS ===\n";
?>
