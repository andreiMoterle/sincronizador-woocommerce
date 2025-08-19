<?php
/**
 * Teste da integração Master API
 * Execute este arquivo para testar se os dados estão sendo salvos corretamente
 */

// Simular ambiente WordPress mínimo para teste
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        echo "✅ update_option('$option', " . json_encode($value, JSON_PRETTY_PRINT) . ")\n\n";
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        echo "🔍 get_option('$option')\n";
        return $default;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show) {
        return 'Fábrica Teste';
    }
}

if (!function_exists('home_url')) {
    function home_url() {
        return 'https://fabrica-teste.com.br';
    }
}

if (!function_exists('do_action')) {
    function do_action($action, ...$args) {
        echo "🚀 Hook disparado: $action\n";
        echo "   Argumentos: " . json_encode($args) . "\n\n";
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        echo "📝 Log: $message\n";
    }
}

echo "🧪 TESTE DA INTEGRAÇÃO MASTER API\n";
echo "==================================\n\n";

// Incluir apenas as funções necessárias da classe principal
class TesteMasterAPI {
    
    /**
     * Simular dados de lojistas
     */
    private function get_lojistas() {
        return array(
            array(
                'id' => 1,
                'nome' => 'Loja Teste 1',
                'url' => 'https://loja1.com.br',
                'ativo' => true,
                'status' => 'ativo',
                'ultima_sync' => '2024-01-15 10:30:00'
            ),
            array(
                'id' => 2,
                'nome' => 'Loja Teste 2',
                'url' => 'https://loja2.com.br',
                'ativo' => true,
                'status' => 'ativo',
                'ultima_sync' => '2024-01-14 15:20:00'
            )
        );
    }
    
    /**
     * Simular produtos locais
     */
    private function get_produtos_locais() {
        return array(
            array(
                'id' => 101,
                'name' => 'Produto Teste 1',
                'sku' => 'TEST-001',
                'price' => '29.90'
            ),
            array(
                'id' => 102,
                'name' => 'Produto Teste 2',
                'sku' => 'TEST-002',
                'price' => '49.90'
            )
        );
    }
    
    /**
     * Simular produtos mais vendidos
     */
    private function obter_produtos_mais_vendidos_local() {
        return array(
            array(
                'id' => 101,
                'nome' => 'Produto Teste 1',
                'sku' => 'TEST-001',
                'vendas_total' => 150,
                'preco' => '29.90',
                'estoque' => 50
            ),
            array(
                'id' => 102,
                'nome' => 'Produto Teste 2',
                'sku' => 'TEST-002',
                'vendas_total' => 89,
                'preco' => '49.90',
                'estoque' => 25
            )
        );
    }
    
    /**
     * Simular vendas por lojista
     */
    private function calcular_vendas_lojista_mes($lojista) {
        return array(
            'total_vendas' => rand(1000, 5000),
            'total_pedidos' => rand(10, 50),
            'mes' => date('Y-m')
        );
    }
    
    /**
     * Obter dados da fábrica formatados para Master API
     */
    private function obter_dados_fabrica_master_api() {
        $lojistas = $this->get_lojistas();
        $produtos_locais = $this->get_produtos_locais();
        
        $total_lojistas = count($lojistas);
        $lojistas_ativos = count(array_filter($lojistas, function($l) { return $l['ativo']; }));
        $total_produtos = count($produtos_locais);
        
        $produtos_top = $this->obter_produtos_mais_vendidos_local();
        
        $vendas_por_lojista = array();
        foreach ($lojistas as $lojista) {
            if ($lojista['ativo']) {
                $vendas_por_lojista[] = array(
                    'id' => $lojista['id'],
                    'nome' => $lojista['nome'],
                    'url' => $lojista['url'],
                    'status' => $lojista['status'],
                    'ultima_sync' => $lojista['ultima_sync'] ?? 'Nunca',
                    'vendas_mes' => $this->calcular_vendas_lojista_mes($lojista)
                );
            }
        }
        
        return array(
            'fabrica_nome' => get_bloginfo('name'),
            'fabrica_url' => home_url(),
            'status' => 'ativo',
            'total_lojistas' => $total_lojistas,
            'lojistas_ativos' => $lojistas_ativos,
            'total_produtos' => $total_produtos,
            'produtos_top' => $produtos_top,
            'vendas_por_lojista' => $vendas_por_lojista,
            'ultima_atualizacao' => current_time('mysql'),
            'timestamp' => time()
        );
    }
    
    /**
     * Salvar dados do Master API após sincronização
     */
    public function salvar_dados_master_api($lojista_nome, $dados_sync) {
        echo "📊 SALVANDO DADOS MASTER API\n";
        echo "Lojista: $lojista_nome\n";
        echo "Dados da sincronização: " . json_encode($dados_sync, JSON_PRETTY_PRINT) . "\n\n";
        
        $dados_fabrica = $this->obter_dados_fabrica_master_api();
        
        update_option('sincronizador_wc_master_data', $dados_fabrica);
        
        error_log("Master API: Dados atualizados após sincronização com {$lojista_nome}");
    }
    
    /**
     * Simular sincronização completa
     */
    public function simular_sincronizacao() {
        echo "🔄 SIMULANDO SINCRONIZAÇÃO\n";
        echo "=========================\n\n";
        
        // Simular dados de sincronização
        $lojista_nome = "Loja Teste 1";
        $dados_sync = array(
            'produtos_sincronizados' => 5,
            'produtos_criados' => 2,
            'produtos_atualizados' => 3,
            'erros' => 0,
            'status' => 'completo'
        );
        
        // Disparar hook de sincronização
        do_action('sincronizador_wc_after_sync', $lojista_nome, $dados_sync);
        
        // Chamar método diretamente
        $this->salvar_dados_master_api($lojista_nome, $dados_sync);
        
        echo "✅ Sincronização simulada com sucesso!\n\n";
    }
    
    /**
     * Simular sincronização individual de produto
     */
    public function simular_produto_sincronizado() {
        echo "📦 SIMULANDO PRODUTO SINCRONIZADO\n";
        echo "=================================\n\n";
        
        $lojista_nome = "Loja Teste 2";
        $produto_id = 101;
        $venda_data = array(
            'sku' => 'TEST-001',
            'nome' => 'Produto Teste 1',
            'destino_id' => 555
        );
        
        // Disparar hook de produto sincronizado
        do_action('sincronizador_wc_produto_sincronizado', $lojista_nome, $produto_id, $venda_data);
        
        echo "✅ Produto sincronizado simulado com sucesso!\n\n";
    }
}

// Executar testes
$teste = new TesteMasterAPI();

echo "1️⃣ Testando sincronização completa:\n";
$teste->simular_sincronizacao();

echo "2️⃣ Testando produto individual:\n";
$teste->simular_produto_sincronizado();

echo "🎉 TESTES CONCLUÍDOS!\n";
echo "Os dados acima mostram como a Master API será integrada.\n";
echo "Quando uma sincronização real acontecer, esses dados serão salvos automaticamente.\n";
