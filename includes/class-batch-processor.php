<?php
/**
 * Sistema de Processamento em Lote para Grandes Volumes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Batch_Processor {
    
    private $batch_size = 50; // Produtos por lote
    private $max_execution_time = 30; // Segundos máximos por lote
    private $memory_limit_threshold = 0.8; // 80% da memória disponível
    
    public function __construct() {
        // Configurações baseadas no servidor
        $this->setup_batch_config();
        
        // Actions para processamento em background
        add_action('wp_ajax_sincronizador_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_sincronizador_get_batch_status', array($this, 'ajax_get_batch_status'));
        
        // Cron para processamento em background
        add_action('sincronizador_wc_process_batch_cron', array($this, 'process_batch_cron'));
        
        // Limpar jobs antigos
        add_action('sincronizador_wc_cleanup_batch_jobs', array($this, 'cleanup_old_batch_jobs'));
    }
    
    private function setup_batch_config() {
        // Ajustar configurações baseadas no ambiente
        $memory_limit = $this->get_memory_limit();
        $max_execution = ini_get('max_execution_time');
        
        if ($memory_limit > 512 * 1024 * 1024) { // 512MB+
            $this->batch_size = 100;
        } elseif ($memory_limit > 256 * 1024 * 1024) { // 256MB+
            $this->batch_size = 75;
        } else {
            $this->batch_size = 25; // Servidor com pouca memória
        }
        
        if ($max_execution > 60) {
            $this->max_execution_time = 45;
        } elseif ($max_execution > 30) {
            $this->max_execution_time = 25;
        } else {
            $this->max_execution_time = 15;
        }
        
        // Permitir override via configuração
        if (defined('SINCRONIZADOR_WC_BATCH_SIZE')) {
            $this->batch_size = SINCRONIZADOR_WC_BATCH_SIZE;
        }
    }
    
    /**
     * Inicia processamento em lote de produtos
     */
    public function start_batch_import($produtos_ids, $lojistas_ids, $options = array()) {
        // Criar job de lote
        $batch_id = $this->create_batch_job($produtos_ids, $lojistas_ids, $options);
        
        if (!$batch_id) {
            return array('success' => false, 'message' => 'Erro ao criar job de lote');
        }
        
        // Processar primeiro lote imediatamente
        $result = $this->process_batch($batch_id);
        
        // Se não terminou, agendar próximo lote
        if (!$result['completed']) {
            wp_schedule_single_event(time() + 5, 'sincronizador_wc_process_batch_cron', array($batch_id));
        }
        
        return array(
            'success' => true,
            'batch_id' => $batch_id,
            'message' => 'Processamento iniciado',
            'progress' => $result['progress']
        );
    }
    
    /**
     * Cria um job de lote
     */
    private function create_batch_job($produtos_ids, $lojistas_ids, $options = array()) {
        global $wpdb;
        
        $batch_data = array(
            'produtos_ids' => $produtos_ids,
            'lojistas_ids' => $lojistas_ids,
            'options' => $options,
            'total_produtos' => count($produtos_ids),
            'total_lojistas' => count($lojistas_ids),
            'processed_products' => 0,
            'successful_imports' => 0,
            'failed_imports' => 0,
            'current_offset' => 0,
            'errors' => array(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'sincronizador_batch_jobs',
            array(
                'batch_data' => wp_json_encode($batch_data),
                'status' => 'processing',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Processa um lote de produtos
     */
    public function process_batch($batch_id) {
        global $wpdb;
        
        $start_time = time();
        $start_memory = memory_get_usage();
        
        // Buscar dados do job
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sincronizador_batch_jobs WHERE id = %d",
            $batch_id
        ));
        
        if (!$job || $job->status !== 'processing') {
            return array('success' => false, 'message' => 'Job não encontrado ou não está em processamento');
        }
        
        $batch_data = json_decode($job->batch_data, true);
        
        // Produtos para processar neste lote
        $produtos_lote = array_slice(
            $batch_data['produtos_ids'],
            $batch_data['current_offset'],
            $this->batch_size
        );
        
        $product_importer = new Sincronizador_WC_Product_Importer();
        $results = array();
        
        foreach ($produtos_lote as $produto_id) {
            // Verificar limites de tempo e memória
            if ($this->should_stop_processing($start_time, $start_memory)) {
                break;
            }
            
            // Processar produto para todos os lojistas
            $produto_results = $product_importer->import_product_to_lojistas(
                $produto_id,
                $batch_data['lojistas_ids'],
                $batch_data['options']
            );
            
            foreach ($produto_results as $result) {
                if ($result['success']) {
                    $batch_data['successful_imports']++;
                } else {
                    $batch_data['failed_imports']++;
                    $batch_data['errors'][] = array(
                        'produto_id' => $produto_id,
                        'lojista_id' => $result['lojista_id'],
                        'error' => $result['message'],
                        'timestamp' => current_time('mysql')
                    );
                }
            }
            
            $batch_data['processed_products']++;
            
            // Log de progresso a cada 10 produtos
            if ($batch_data['processed_products'] % 10 === 0) {
                $this->log_batch_progress($batch_id, $batch_data);
            }
        }
        
        // Atualizar offset
        $batch_data['current_offset'] += count($produtos_lote);
        $batch_data['updated_at'] = current_time('mysql');
        
        // Verificar se terminou
        $completed = $batch_data['current_offset'] >= $batch_data['total_produtos'];
        
        if ($completed) {
            $batch_data['completed_at'] = current_time('mysql');
            $status = 'completed';
        } else {
            $status = 'processing';
        }
        
        // Atualizar job no banco
        $wpdb->update(
            $wpdb->prefix . 'sincronizador_batch_jobs',
            array(
                'batch_data' => wp_json_encode($batch_data),
                'status' => $status,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $batch_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        // Calcular progresso
        $progress = ($batch_data['processed_products'] / $batch_data['total_produtos']) * 100;
        
        return array(
            'success' => true,
            'completed' => $completed,
            'progress' => round($progress, 2),
            'processed' => $batch_data['processed_products'],
            'total' => $batch_data['total_produtos'],
            'successful' => $batch_data['successful_imports'],
            'failed' => $batch_data['failed_imports'],
            'execution_time' => time() - $start_time,
            'memory_used' => $this->format_bytes(memory_get_usage() - $start_memory)
        );
    }
    
    /**
     * Verifica se deve parar o processamento
     */
    private function should_stop_processing($start_time, $start_memory) {
        // Verificar tempo limite
        if ((time() - $start_time) >= $this->max_execution_time) {
            return true;
        }
        
        // Verificar memória
        $current_memory = memory_get_usage();
        $memory_limit = $this->get_memory_limit();
        
        if ($current_memory > ($memory_limit * $this->memory_limit_threshold)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Log de progresso do lote
     */
    private function log_batch_progress($batch_id, $batch_data) {
        $progress = ($batch_data['processed_products'] / $batch_data['total_produtos']) * 100;
        
        // Log removido para produção
    }
    
    /**
     * AJAX para processar próximo lote
     */
    public function ajax_process_batch() {
        check_ajax_referer('sincronizador_batch_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die('Permissões insuficientes');
        }
        
        $batch_id = intval($_POST['batch_id']);
        $result = $this->process_batch($batch_id);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX para obter status do lote
     */
    public function ajax_get_batch_status() {
        check_ajax_referer('sincronizador_batch_nonce', 'nonce');
        
        if (!current_user_can('manage_sincronizador_wc')) {
            wp_die('Permissões insuficientes');
        }
        
        $batch_id = intval($_GET['batch_id']);
        $status = $this->get_batch_status($batch_id);
        
        wp_send_json($status);
    }
    
    /**
     * Obtém status do lote
     */
    public function get_batch_status($batch_id) {
        global $wpdb;
        
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sincronizador_batch_jobs WHERE id = %d",
            $batch_id
        ));
        
        if (!$job) {
            return array('success' => false, 'message' => 'Job não encontrado');
        }
        
        $batch_data = json_decode($job->batch_data, true);
        $progress = ($batch_data['processed_products'] / $batch_data['total_produtos']) * 100;
        
        return array(
            'success' => true,
            'status' => $job->status,
            'progress' => round($progress, 2),
            'processed' => $batch_data['processed_products'],
            'total' => $batch_data['total_produtos'],
            'successful' => $batch_data['successful_imports'],
            'failed' => $batch_data['failed_imports'],
            'errors' => array_slice($batch_data['errors'], -10), // Últimos 10 erros
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
            'completed_at' => isset($batch_data['completed_at']) ? $batch_data['completed_at'] : null
        );
    }
    
    /**
     * Processamento via cron
     */
    public function process_batch_cron($batch_id) {
        $result = $this->process_batch($batch_id);
        
        // Se não terminou, agendar próximo lote
        if (!$result['completed']) {
            wp_schedule_single_event(time() + 5, 'sincronizador_wc_process_batch_cron', array($batch_id));
        }
    }
    
    /**
     * Limpa jobs antigos
     */
    public function cleanup_old_batch_jobs() {
        global $wpdb;
        
        // Remover jobs completos com mais de 7 dias
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE status = 'completed' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        // Remover jobs com erro com mais de 3 dias
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE status = 'error' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)"
        );
    }
    
    /**
     * Obtém limite de memória em bytes
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                $memory_limit = $matches[1] * 1024 * 1024;
            } elseif ($matches[2] == 'K') {
                $memory_limit = $matches[1] * 1024;
            } elseif ($matches[2] == 'G') {
                $memory_limit = $matches[1] * 1024 * 1024 * 1024;
            }
        }
        
        return $memory_limit;
    }
    
    /**
     * Formata bytes em formato legível
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Obtém estatísticas de performance
     */
    public function get_performance_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Jobs dos últimos 30 dias
        $stats['jobs_mes'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Produtos processados no mês
        $stats['produtos_processados_mes'] = $wpdb->get_var(
            "SELECT SUM(JSON_EXTRACT(batch_data, '$.processed_products')) 
             FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Taxa de sucesso
        $success_data = $wpdb->get_row(
            "SELECT 
                SUM(JSON_EXTRACT(batch_data, '$.successful_imports')) as sucessos,
                SUM(JSON_EXTRACT(batch_data, '$.failed_imports')) as falhas
             FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        if ($success_data && ($success_data->sucessos + $success_data->falhas) > 0) {
            $stats['taxa_sucesso'] = round(
                ($success_data->sucessos / ($success_data->sucessos + $success_data->falhas)) * 100,
                2
            );
        } else {
            $stats['taxa_sucesso'] = 0;
        }
        
        // Tempo médio por produto
        $avg_time = $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at) / JSON_EXTRACT(batch_data, '$.processed_products'))
             FROM {$wpdb->prefix}sincronizador_batch_jobs 
             WHERE status = 'completed' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND JSON_EXTRACT(batch_data, '$.processed_products') > 0"
        );
        
        $stats['tempo_medio_por_produto'] = $avg_time ? round($avg_time, 2) : 0;
        
        return $stats;
    }
}
