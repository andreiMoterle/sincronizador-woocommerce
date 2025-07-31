<?php
/**
 * Configurações de exemplo para o Sincronizador WooCommerce
 * 
 * Adicione essas configurações ao seu wp-config.php conforme necessário
 */

// ============================================================================
// CONFIGURAÇÕES BÁSICAS
// ============================================================================

// Desabilitar sincronização automática (padrão: false)
define('SINCRONIZADOR_WC_DISABLE_AUTO_SYNC', false);

// Intervalo de sincronização em segundos (padrão: 86400 = 24 horas)
define('SINCRONIZADOR_WC_SYNC_INTERVAL', 86400);

// Intervalo de verificação de produtos em segundos (padrão: 21600 = 6 horas)
define('SINCRONIZADOR_WC_PRODUCT_CHECK_INTERVAL', 21600);

// ============================================================================
// CONFIGURAÇÕES DE PERFORMANCE
// ============================================================================

// Número máximo de produtos por sincronização (padrão: 100)
define('SINCRONIZADOR_WC_MAX_PRODUCTS_PER_SYNC', 100);

// Timeout para conexões API em segundos (padrão: 30)
define('SINCRONIZADOR_WC_API_TIMEOUT', 30);

// Limite de memória para sincronização (padrão: 256M)
define('SINCRONIZADOR_WC_MEMORY_LIMIT', '256M');

// Tempo máximo de execução para sincronização (padrão: 300 segundos)
define('SINCRONIZADOR_WC_MAX_EXECUTION_TIME', 300);

// ============================================================================
// CONFIGURAÇÕES DE LOG E DEBUG
// ============================================================================

// Habilitar modo debug (padrão: false)
define('SINCRONIZADOR_WC_DEBUG', false);

// Nível de log (error, warning, info, debug)
define('SINCRONIZADOR_WC_LOG_LEVEL', 'info');

// Manter logs por X dias (padrão: 30)
define('SINCRONIZADOR_WC_LOG_RETENTION_DAYS', 30);

// Tamanho máximo do arquivo de log em MB (padrão: 10)
define('SINCRONIZADOR_WC_MAX_LOG_SIZE', 10);

// ============================================================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================================================

// Chave de criptografia para dados sensíveis (gere uma única e forte)
define('SINCRONIZADOR_WC_ENCRYPTION_KEY', 'sua-chave-super-segura-aqui-32-chars');

// Habilitar verificação SSL para APIs (padrão: true)
define('SINCRONIZADOR_WC_VERIFY_SSL', true);

// Limitar acesso por IP (deixe vazio para permitir todos)
define('SINCRONIZADOR_WC_ALLOWED_IPS', '');

// ============================================================================
// CONFIGURAÇÕES DE CACHE
// ============================================================================

// Habilitar cache de dados (padrão: true)
define('SINCRONIZADOR_WC_ENABLE_CACHE', true);

// Tempo de cache em segundos (padrão: 3600 = 1 hora)
define('SINCRONIZADOR_WC_CACHE_TIME', 3600);

// ============================================================================
// CONFIGURAÇÕES DE NOTIFICAÇÕES
// ============================================================================

// Email para notificações de erro
define('SINCRONIZADOR_WC_ADMIN_EMAIL', 'andreimoterle@gmail.com');

// Habilitar notificações por email (padrão: false)
define('SINCRONIZADOR_WC_EMAIL_NOTIFICATIONS', false);

// Habilitar notificações push (requer configuração adicional)
define('SINCRONIZADOR_WC_PUSH_NOTIFICATIONS', false);

// ============================================================================
// CONFIGURAÇÕES AVANÇADAS
// ============================================================================

// Prefixo personalizado para tabelas do banco (padrão: sincronizador_)
define('SINCRONIZADOR_WC_DB_PREFIX', 'sincronizador_');

// Usar fila de tarefas para sincronização (requer Action Scheduler)
define('SINCRONIZADOR_WC_USE_QUEUE', false);

// Número máximo de tentativas para operações que falharam (padrão: 3)
define('SINCRONIZADOR_WC_MAX_RETRIES', 3);

// Intervalo entre tentativas em segundos (padrão: 300 = 5 minutos)
define('SINCRONIZADOR_WC_RETRY_INTERVAL', 300);

// ============================================================================
// EXEMPLOS DE CONFIGURAÇÃO POR AMBIENTE
// ============================================================================

// Desenvolvimento
if (defined('WP_DEBUG') && WP_DEBUG) {
    define('SINCRONIZADOR_WC_DEBUG', true);
    define('SINCRONIZADOR_WC_LOG_LEVEL', 'debug');
    define('SINCRONIZADOR_WC_API_TIMEOUT', 60);
    define('SINCRONIZADOR_WC_VERIFY_SSL', false); // Apenas para desenvolvimento local
}

// Produção
if (defined('WP_ENV') && WP_ENV === 'production') {
    define('SINCRONIZADOR_WC_DEBUG', false);
    define('SINCRONIZADOR_WC_LOG_LEVEL', 'error');
    define('SINCRONIZADOR_WC_EMAIL_NOTIFICATIONS', true);
    define('SINCRONIZADOR_WC_USE_QUEUE', true);
}

// Staging
if (defined('WP_ENV') && WP_ENV === 'staging') {
    define('SINCRONIZADOR_WC_DEBUG', true);
    define('SINCRONIZADOR_WC_LOG_LEVEL', 'info');
    define('SINCRONIZADOR_WC_SYNC_INTERVAL', 3600); // Sincronizar a cada hora
}

// ============================================================================
// CONFIGURAÇÕES DE HOOKS CUSTOMIZADOS
// ============================================================================

// Personalizar comportamento via hooks
add_action('sincronizador_wc_before_sync', function($lojista_id) {
    // Sua lógica personalizada antes da sincronização
    do_action('minha_funcao_pre_sync', $lojista_id);
});

add_filter('sincronizador_wc_product_data', function($data, $product_id) {
    // Modificar dados do produto antes do envio
    $data['custom_field'] = get_post_meta($product_id, '_meu_campo', true);
    return $data;
}, 10, 2);

add_filter('sincronizador_wc_api_params', function($params, $lojista_id) {
    // Modificar parâmetros da API por lojista
    if ($lojista_id === 1) {
        $params['timeout'] = 60; // Timeout maior para lojista específico
    }
    return $params;
}, 10, 2);

// ============================================================================
// CONFIGURAÇÕES DE INTEGRAÇÃO COM OUTROS PLUGINS
// ============================================================================

// WPML - Suporte a multilíngua
if (defined('ICL_SITEPRESS_VERSION')) {
    define('SINCRONIZADOR_WC_WPML_SUPPORT', true);
}

// WooCommerce Subscriptions
if (class_exists('WC_Subscriptions')) {
    define('SINCRONIZADOR_WC_SUBSCRIPTIONS_SUPPORT', true);
}

// WooCommerce Bookings
if (class_exists('WC_Bookings')) {
    define('SINCRONIZADOR_WC_BOOKINGS_SUPPORT', true);
}

// Action Scheduler (para filas)
if (class_exists('ActionScheduler')) {
    define('SINCRONIZADOR_WC_USE_QUEUE', true);
}

// ============================================================================
// CONFIGURAÇÕES DE MONITORAMENTO
// ============================================================================

// Habilitar métricas de performance
define('SINCRONIZADOR_WC_ENABLE_METRICS', true);

// Enviar métricas para serviço externo (opcional)
define('SINCRONIZADOR_WC_METRICS_ENDPOINT', '');

// Habilitar health check endpoint
define('SINCRONIZADOR_WC_ENABLE_HEALTH_CHECK', true);

// ============================================================================
// NOTAS IMPORTANTES
// ============================================================================

/*
IMPORTANTE: 
- Sempre faça backup antes de alterar configurações em produção
- Teste todas as configurações em ambiente de desenvolvimento primeiro
- Monitore os logs após mudanças de configuração
- Algumas configurações requerem restart do servidor web
- Consulte a documentação completa para detalhes de cada configuração

PERFORMANCE:
- Para sites com muitos lojistas (>10), considere usar fila de tarefas
- Ajuste SINCRONIZADOR_WC_MAX_PRODUCTS_PER_SYNC baseado na sua infraestrutura
- Monitor o uso de memória e CPU durante sincronizações

SEGURANÇA:
- Sempre use HTTPS em produção
- Mantenha as chaves API seguras e rotacione periodicamente
- Configure SINCRONIZADOR_WC_ALLOWED_IPS se necessário
- Use uma SINCRONIZADOR_WC_ENCRYPTION_KEY forte e única

BACKUP:
- Faça backup das configurações de lojistas regularmente
- Mantenha backup dos logs importantes
- Documente suas configurações personalizadas
*/
