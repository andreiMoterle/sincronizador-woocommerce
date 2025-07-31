<?php
/**
 * Configurações Avançadas do Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Configurações de cache
    'cache' => array(
        'enabled' => true,
        'default_expiration' => 3600, // 1 hora
        'max_memory_usage' => '256M',
        'use_redis' => false, // Habilitar se Redis estiver disponível
        'use_memcached' => false, // Habilitar se Memcached estiver disponível
        'warm_up_on_activation' => true
    ),
    
    // Configurações de processamento em lote
    'batch_processing' => array(
        'default_batch_size' => 50,
        'max_batch_size' => 200,
        'max_execution_time' => 30, // segundos
        'memory_limit_threshold' => 0.8, // 80%
        'retry_failed_items' => true,
        'max_retries' => 3,
        'cleanup_completed_jobs_after' => 7, // dias
        'cleanup_failed_jobs_after' => 3, // dias
    ),
    
    // Configurações de API
    'api' => array(
        'timeout' => 30, // segundos
        'retry_attempts' => 3,
        'retry_delay' => 2, // segundos entre tentativas
        'user_agent' => 'Sincronizador-WC/' . SINCRONIZADOR_WC_VERSION,
        'verify_ssl' => true,
        'follow_redirects' => true,
        'max_redirects' => 3
    ),
    
    // Configurações de sincronização
    'sync' => array(
        'cron_interval' => 'hourly', // hourly, twicedaily, daily
        'auto_sync_enabled' => true,
        'sync_variations' => true,
        'sync_images' => true,
        'sync_categories' => true,
        'sync_stock' => true,
        'sync_prices' => true,
        'max_sync_items_per_run' => 100,
        'delete_orphaned_products' => false
    ),
    
    // Configurações de log
    'logging' => array(
        'enabled' => true,
        'log_level' => 'info', // error, warning, info, debug
        'max_log_size' => '10M',
        'log_rotation' => true,
        'keep_logs_for' => 30, // dias
        'log_api_requests' => false, // Só habilitar para debug
        'log_database_queries' => false // Só habilitar para debug
    ),
    
    // Configurações de performance
    'performance' => array(
        'enable_query_optimization' => true,
        'use_transients' => true,
        'preload_critical_data' => true,
        'lazy_load_images' => true,
        'compress_api_responses' => true,
        'enable_gzip' => true
    ),
    
    // Configurações de segurança
    'security' => array(
        'require_ssl' => false, // Forçar HTTPS para APIs
        'token_expiration' => 2592000, // 30 dias
        'max_failed_attempts' => 5,
        'lockout_duration' => 900, // 15 minutos
        'ip_whitelist' => array(), // IPs permitidos (vazio = todos)
        'rate_limiting' => array(
            'enabled' => false,
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000
        )
    ),
    
    // Configurações de notificações
    'notifications' => array(
        'email_notifications' => false,
        'admin_email' => get_option('admin_email'),
        'notify_on_errors' => true,
        'notify_on_completion' => false,
        'notification_threshold' => 10, // Notificar após X erros
        'webhook_url' => '', // URL para webhook de notificações
    ),
    
    // Configurações específicas para grandes volumes
    'large_dataset' => array(
        'enabled' => true,
        'threshold_products' => 1000, // Considerar grande volume
        'use_background_processing' => true,
        'chunk_size' => 100,
        'memory_management' => true,
        'progress_tracking' => true,
        'pause_on_error' => false
    ),
    
    // Configurações de monitoramento
    'monitoring' => array(
        'track_performance' => true,
        'track_api_calls' => true,
        'track_memory_usage' => true,
        'track_execution_time' => true,
        'alert_on_high_memory' => true,
        'alert_memory_threshold' => '90%',
        'alert_on_slow_queries' => true,
        'slow_query_threshold' => 5 // segundos
    ),
    
    // Configurações de backup
    'backup' => array(
        'auto_backup_before_sync' => false,
        'backup_retention' => 7, // dias
        'backup_path' => WP_CONTENT_DIR . '/uploads/sincronizador-backups/',
        'compress_backups' => true
    ),
    
    // Configurações de debug
    'debug' => array(
        'enabled' => defined('WP_DEBUG') && WP_DEBUG,
        'log_all_requests' => false,
        'log_all_responses' => false,
        'save_debug_files' => false,
        'profiling' => false,
        'memory_profiling' => false
    ),
    
    // Configurações de compatibilidade
    'compatibility' => array(
        'wp_min_version' => '5.0',
        'wc_min_version' => '5.0',
        'php_min_version' => '7.4',
        'mysql_min_version' => '5.6',
        'check_requirements' => true,
        'fallback_mode' => false // Modo compatibilidade para servidores antigos
    ),
    
    // Configurações experimentais
    'experimental' => array(
        'use_wp_background_processing' => false,
        'parallel_processing' => false,
        'async_image_processing' => false,
        'advanced_caching' => false,
        'ai_categorization' => false // Futura funcionalidade
    )
);
