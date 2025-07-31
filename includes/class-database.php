<?php
/**
 * Classe responsável pela criação e gerenciamento das tabelas do plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabela de lojistas
        $table_lojistas = $wpdb->prefix . 'sincronizador_lojistas';
        $sql_lojistas = "CREATE TABLE $table_lojistas (
            id int(11) NOT NULL AUTO_INCREMENT,
            nome varchar(255) NOT NULL,
            url_loja varchar(255) NOT NULL,
            consumer_key varchar(255) NOT NULL,
            consumer_secret varchar(255) NOT NULL,
            status enum('ativo','inativo') DEFAULT 'ativo',
            ultima_sincronizacao datetime NULL,
            data_criacao datetime DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY url_loja (url_loja)
        ) $charset_collate;";
        
        // Tabela de sincronização de produtos
        $table_sync_produtos = $wpdb->prefix . 'sincronizador_produtos';
        $sql_sync_produtos = "CREATE TABLE $table_sync_produtos (
            id int(11) NOT NULL AUTO_INCREMENT,
            lojista_id int(11) NOT NULL,
            produto_id_fabrica int(11) NOT NULL,
            produto_id_lojista int(11) NULL,
            sku varchar(100) NOT NULL,
            status_sincronizacao enum('pendente','sincronizado','erro') DEFAULT 'pendente',
            data_ultima_sincronizacao datetime NULL,
            erro_mensagem text NULL,
            data_criacao datetime DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (lojista_id) REFERENCES $table_lojistas(id) ON DELETE CASCADE,
            INDEX idx_sku (sku),
            INDEX idx_lojista_produto (lojista_id, produto_id_fabrica),
            UNIQUE KEY unique_lojista_produto (lojista_id, sku)
        ) $charset_collate;";
        
        // Tabela de relatórios de vendas
        $table_vendas = $wpdb->prefix . 'sincronizador_vendas';
        $sql_vendas = "CREATE TABLE $table_vendas (
            id int(11) NOT NULL AUTO_INCREMENT,
            lojista_id int(11) NOT NULL,
            produto_id_fabrica int(11) NOT NULL,
            sku varchar(100) NOT NULL,
            quantidade_vendida int(11) DEFAULT 0,
            valor_total decimal(10,2) DEFAULT 0.00,
            data_venda datetime NOT NULL,
            periodo_referencia varchar(20) NOT NULL COMMENT 'YYYY-MM para agrupar por mês',
            data_sincronizacao datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (lojista_id) REFERENCES $table_lojistas(id) ON DELETE CASCADE,
            INDEX idx_periodo (periodo_referencia),
            INDEX idx_lojista_periodo (lojista_id, periodo_referencia),
            INDEX idx_sku_periodo (sku, periodo_referencia)
        ) $charset_collate;";
        
        // Tabela de log de sincronização
        $table_logs = $wpdb->prefix . 'sincronizador_logs';
        $sql_logs = "CREATE TABLE $table_logs (
            id int(11) NOT NULL AUTO_INCREMENT,
            lojista_id int(11) NULL,
            tipo enum('importacao','sincronizacao','erro','info') NOT NULL,
            acao varchar(100) NOT NULL,
            detalhes text NULL,
            data_criacao datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_tipo_data (tipo, data_criacao),
            INDEX idx_lojista_data (lojista_id, data_criacao)
        ) $charset_collate;";
        
        // Tabela para jobs de processamento em lote
        $table_batch_jobs = $wpdb->prefix . 'sincronizador_batch_jobs';
        $sql_batch_jobs = "CREATE TABLE $table_batch_jobs (
            id int(11) NOT NULL AUTO_INCREMENT,
            batch_data longtext NOT NULL COMMENT 'JSON com dados do lote',
            status enum('processing','completed','error','paused') NOT NULL DEFAULT 'processing',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status_created (status, created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_lojistas);
        dbDelta($sql_sync_produtos);
        dbDelta($sql_vendas);
        dbDelta($sql_logs);
        dbDelta($sql_batch_jobs);
        
        // Adiciona opção de versão do banco
        add_option('sincronizador_wc_db_version', '1.0.0');
    }
    
    public static function drop_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'sincronizador_logs',
            $wpdb->prefix . 'sincronizador_vendas',
            $wpdb->prefix . 'sincronizador_produtos',
            $wpdb->prefix . 'sincronizador_lojistas'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        delete_option('sincronizador_wc_db_version');
    }
    
    /**
     * Obtém estatísticas gerais do sistema
     */
    public static function get_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Total de lojistas ativos
        $stats['lojistas_ativos'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_lojistas WHERE status = 'ativo'"
        );
        
        // Total de produtos sincronizados
        $stats['produtos_sincronizados'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_produtos WHERE status_sincronizacao = 'sincronizado'"
        );
        
        // Total de vendas no mês atual
        $periodo_atual = date('Y-m');
        $stats['vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantidade_vendida) FROM {$wpdb->prefix}sincronizador_vendas WHERE periodo_referencia = %s",
            $periodo_atual
        ));
        
        // Valor total de vendas no mês atual
        $stats['valor_vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(valor_total) FROM {$wpdb->prefix}sincronizador_vendas WHERE periodo_referencia = %s",
            $periodo_atual
        ));
        
        return $stats;
    }
}
