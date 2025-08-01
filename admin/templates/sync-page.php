<?php
/**
 * Template: Página de Produtos Sincronizados
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$lojistas = get_option('sincronizador_wc_lojistas', array());
$nonce = wp_create_nonce('sincronizador_wc_nonce');
?>

<meta name="sincronizador-wc-nonce" content="<?php echo $nonce; ?>">

<div class="wrap sincronizador-wc-wrap">
    <h1>📊 Produtos Sincronizados</h1>
    
    <!-- Seletor de lojista -->
    <div class="card">
        <h3>Selecionar Lojista</h3>
        <div class="controles-row">
            <label for="lojista_destino">Lojista:</label>
            <select id="lojista_destino" style="min-width: 300px;">
                <option value="">Selecione um lojista...</option>
                <?php foreach ($lojistas as $lojista): ?>
                    <option value="<?php echo esc_attr($lojista['id']); ?>">
                        <?php echo esc_html($lojista['nome']); ?> (<?php echo esc_html($lojista['url']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="btn-carregar-sincronizados" class="button button-primary" disabled>
                📊 Carregar Produtos
            </button>
            <button type="button" id="btn-sincronizar-vendas" class="button button-secondary" disabled>
                🔄 Sincronizar Vendas
            </button>
        </div>
    </div>
    
    <!-- Status da operação -->
    <div id="status-operacao" style="display: none;"></div>
    
    <!-- Tabela de produtos sincronizados -->
    <div id="tabela-sincronizados" style="display: none;">
        <div class="card">
            <h3>Produtos Sincronizados <span id="total-produtos"></span></h3>
            
            <!-- Botão para limpar cache -->
            <div style="margin-bottom: 15px;">
                <button type="button" class="button button-secondary" id="btn-limpar-cache">
                    🗑️ Limpar Cache
                </button>
                <span class="description">Clique para forçar o recarregamento dos produtos sincronizados</span>
            </div>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <input type="text" id="buscar-sincronizado" 
                           placeholder="🔍 Buscar por nome ou SKU..." 
                           style="width: 250px;">
                </div>
                <div class="alignright">
                    <span id="status-sync" class="status-info"></span>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-foto">Foto</th>
                        <th class="column-id">ID Fábrica</th>
                        <th>Nome</th>
                        <th class="column-id">ID Destino</th>
                        <th class="column-status">Status</th>
                        <th class="column-vendas">Vendas</th>
                        <th class="column-acoes">Ações</th>
                    </tr>
                </thead>
                <tbody id="produtos-sincronizados-tbody">
                    <!-- Conteúdo carregado via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de detalhes do produto -->
<div id="modal-detalhes" class="sincronizador-modal">
    <div class="sincronizador-modal-content">
        <h3 id="modal-titulo">Detalhes do Produto</h3>
        <div id="modal-conteudo"></div>
        <div class="sincronizador-modal-footer">
            <button type="button" id="btn-fechar-modal" class="button">Fechar</button>
        </div>
    </div>
</div>
