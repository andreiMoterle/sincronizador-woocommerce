<?php
/**
 * Classe de desativação do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Deactivator {
    
    public static function deactivate() {
        // Limpar agendamentos
        wp_clear_scheduled_hook('sincronizador_wc_sync_vendas');
        wp_clear_scheduled_hook('sincronizador_wc_sync_produtos');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Remover capacidades (opcional - pode querer manter)
        // self::remove_capabilities();
    }
    
    private static function remove_capabilities() {
        // Remover capacidades de administradores
        $role = get_role('administrator');
        
        if ($role) {
            $role->remove_cap('manage_sincronizador_wc');
            $role->remove_cap('view_sincronizador_wc_reports');
        }
        
        // Remover capacidades de shop managers
        $role = get_role('shop_manager');
        
        if ($role) {
            $role->remove_cap('manage_sincronizador_wc');
            $role->remove_cap('view_sincronizador_wc_reports');
        }
    }
}
