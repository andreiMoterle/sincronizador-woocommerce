<?php
/**
 * Sistema de Cache para o Sincronizador WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sincronizador_WC_Cache {
    
    private static $instance = null;
    private $cache_group = 'sincronizador_wc';
    private $default_expiration = 3600; // 1 hora
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Configurar cache baseado no ambiente
        $this->setup_cache_config();
        
        // Limpar cache quando necessário
        add_action('sincronizador_wc_clear_cache', array($this, 'clear_all_cache'));
        add_action('sincronizador_wc_produto_atualizado', array($this, 'clear_product_cache'));
        add_action('sincronizador_wc_lojista_atualizado', array($this, 'clear_lojista_cache'));
    }
    
    private function setup_cache_config() {
        // Configurações baseadas no volume de dados
        if (defined('SINCRONIZADOR_WC_LARGE_DATASET') && SINCRONIZADOR_WC_LARGE_DATASET) {
            $this->default_expiration = 7200; // 2 horas para datasets grandes
        }
        
        // Cache persistente para Redis/Memcached se disponível
        if (class_exists('Redis') || class_exists('Memcached')) {
            add_filter('wp_cache_key_salt', array($this, 'add_cache_salt'));
        }
    }
    
    /**
     * Armazena dados no cache
     */
    public function set($key, $data, $expiration = null) {
        if ($expiration === null) {
            $expiration = $this->default_expiration;
        }
        
        $cache_key = $this->get_cache_key($key);
        
        // Usar cache persistente se disponível
        if (function_exists('wp_cache_set')) {
            return wp_cache_set($cache_key, $data, $this->cache_group, $expiration);
        }
        
        // Fallback para transients
        return set_transient($cache_key, $data, $expiration);
    }
    
    /**
     * Obtém dados do cache
     */
    public function get($key) {
        $cache_key = $this->get_cache_key($key);
        
        // Usar cache persistente se disponível
        if (function_exists('wp_cache_get')) {
            $data = wp_cache_get($cache_key, $this->cache_group);
            if ($data !== false) {
                return $data;
            }
        }
        
        // Fallback para transients
        return get_transient($cache_key);
    }
    
    /**
     * Remove item específico do cache
     */
    public function delete($key) {
        $cache_key = $this->get_cache_key($key);
        
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($cache_key, $this->cache_group);
        }
        
        delete_transient($cache_key);
    }
    
    /**
     * Cache de lista de produtos com paginação
     */
    public function cache_products_list($page = 1, $per_page = 100, $search = '') {
        $cache_key = "products_list_{$page}_{$per_page}_" . md5($search);
        
        $cached = $this->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Buscar produtos do banco
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        $search_sql = '';
        $params = array();
        
        if (!empty($search)) {
            $search_sql = "AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $query = "
            SELECT p.ID, p.post_title, pm.meta_value as sku, pm2.meta_value as price,
                   pm3.meta_value as stock_status, pm4.meta_value as stock_quantity
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_regular_price'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_stock_status'
            LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_stock_quantity'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            {$search_sql}
            ORDER BY p.post_title ASC
            LIMIT %d OFFSET %d
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $products = $wpdb->get_results($wpdb->prepare($query, $params));
        
        // Cache por 30 minutos
        $this->set($cache_key, $products, 1800);
        
        return $products;
    }
    
    /**
     * Cache de estatísticas dos lojistas
     */
    public function cache_lojista_stats($lojista_id) {
        $cache_key = "lojista_stats_{$lojista_id}";
        
        $cached = $this->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $stats = array();
        
        // Produtos sincronizados
        $stats['produtos_sincronizados'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_produtos WHERE lojista_id = %d AND status_sincronizacao = 'sincronizado'",
            $lojista_id
        ));
        
        // Produtos com erro
        $stats['produtos_erro'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_produtos WHERE lojista_id = %d AND status_sincronizacao = 'erro'",
            $lojista_id
        ));
        
        // Vendas do mês atual
        $periodo_atual = date('Y-m');
        $stats['vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantidade_vendida) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d AND periodo_referencia = %s",
            $lojista_id,
            $periodo_atual
        ));
        
        // Valor de vendas do mês atual
        $stats['valor_vendas_mes_atual'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(valor_total) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d AND periodo_referencia = %s",
            $lojista_id,
            $periodo_atual
        ));
        
        // Vendas totais
        $stats['vendas_totais'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(quantidade_vendida) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d",
            $lojista_id
        ));
        
        // Valor total de vendas
        $stats['valor_vendas_totais'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(valor_total) FROM {$wpdb->prefix}sincronizador_vendas WHERE lojista_id = %d",
            $lojista_id
        ));
        
        // Última sincronização
        $stats['ultima_sincronizacao'] = $wpdb->get_var($wpdb->prepare(
            "SELECT ultima_sincronizacao FROM {$wpdb->prefix}sincronizador_lojistas WHERE id = %d",
            $lojista_id
        ));
        
        // Cache por 15 minutos
        $this->set($cache_key, $stats, 900);
        
        return $stats;
    }
    
    /**
     * Cache de dados gerais da fábrica
     */
    public function cache_fabrica_overview() {
        $cache_key = 'fabrica_overview';
        
        $cached = $this->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $overview = array();
        
        // Total de lojistas
        $overview['total_lojistas'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_lojistas"
        );
        
        // Lojistas ativos
        $overview['lojistas_ativos'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sincronizador_lojistas WHERE status = 'ativo'"
        );
        
        // Total de produtos únicos sincronizados
        $overview['produtos_sincronizados'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT produto_id_fabrica) FROM {$wpdb->prefix}sincronizador_produtos WHERE status_sincronizacao = 'sincronizado'"
        );
        
        // Vendas totais
        $overview['vendas_totais'] = $wpdb->get_var(
            "SELECT SUM(quantidade_vendida) FROM {$wpdb->prefix}sincronizador_vendas"
        );
        
        // Valor total de vendas
        $overview['valor_vendas_totais'] = $wpdb->get_var(
            "SELECT SUM(valor_total) FROM {$wpdb->prefix}sincronizador_vendas"
        );
        
        // Produtos mais vendidos (top 10)
        $overview['produtos_mais_vendidos'] = $wpdb->get_results(
            "SELECT 
                sv.sku,
                p.post_title as nome,
                SUM(sv.quantidade_vendida) as total_vendas,
                SUM(sv.valor_total) as valor_total
            FROM {$wpdb->prefix}sincronizador_vendas sv
            LEFT JOIN {$wpdb->posts} p ON sv.produto_id_fabrica = p.ID
            GROUP BY sv.sku
            ORDER BY total_vendas DESC
            LIMIT 10"
        );
        
        // Performance por lojista
        $overview['performance_lojistas'] = $wpdb->get_results(
            "SELECT 
                l.nome,
                l.status,
                COUNT(DISTINCT sp.produto_id_fabrica) as produtos_sincronizados,
                COALESCE(SUM(sv.quantidade_vendida), 0) as total_vendas,
                COALESCE(SUM(sv.valor_total), 0) as valor_total
            FROM {$wpdb->prefix}sincronizador_lojistas l
            LEFT JOIN {$wpdb->prefix}sincronizador_produtos sp ON l.id = sp.lojista_id AND sp.status_sincronizacao = 'sincronizado'
            LEFT JOIN {$wpdb->prefix}sincronizador_vendas sv ON l.id = sv.lojista_id
            GROUP BY l.id
            ORDER BY valor_total DESC"
        );
        
        // Cache por 10 minutos
        $this->set($cache_key, $overview, 600);
        
        return $overview;
    }
    
    /**
     * Limpa todo o cache
     */
    public function clear_all_cache() {
        global $wpdb;
        
        // Limpar transients do plugin
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sincronizador_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sincronizador_%'");
        
        // Limpar cache de objeto se disponível
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group($this->cache_group);
        }
        
        do_action('sincronizador_wc_cache_cleared');
    }
    
    /**
     * Limpa cache relacionado a produtos
     */
    public function clear_product_cache($product_id = null) {
        if ($product_id) {
            $this->delete("product_details_{$product_id}");
            $this->delete("product_sync_status_{$product_id}");
        }
        
        // Limpar cache de listas de produtos
        $this->clear_cache_pattern('products_list_');
        $this->clear_cache_pattern('fabrica_overview');
    }
    
    /**
     * Limpa cache relacionado a lojistas
     */
    public function clear_lojista_cache($lojista_id = null) {
        if ($lojista_id) {
            $this->delete("lojista_stats_{$lojista_id}");
        }
        
        $this->clear_cache_pattern('fabrica_overview');
    }
    
    /**
     * Limpa cache por padrão
     */
    private function clear_cache_pattern($pattern) {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_sincronizador_' . $pattern . '%'
        ));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_timeout_sincronizador_' . $pattern . '%'
        ));
    }
    
    /**
     * Gera chave de cache consistente
     */
    private function get_cache_key($key) {
        return 'sincronizador_' . md5($key);
    }
    
    /**
     * Adiciona salt ao cache
     */
    public function add_cache_salt($salt) {
        return $salt . '_sincronizador_wc_v' . SINCRONIZADOR_WC_VERSION;
    }
    
    /**
     * Preaquecimento de cache para dados críticos
     */
    public function warm_up_cache() {
        // Preaquecer dados da fábrica
        $this->cache_fabrica_overview();
        
        // Preaquecer estatísticas de lojistas ativos
        global $wpdb;
        $lojistas_ativos = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}sincronizador_lojistas WHERE status = 'ativo'"
        );
        
        foreach ($lojistas_ativos as $lojista_id) {
            $this->cache_lojista_stats($lojista_id);
        }
        
        do_action('sincronizador_wc_cache_warmed_up');
    }
}
