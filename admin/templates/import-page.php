<?php
/**
 * Template: Página de Importação de Produtos
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
    <h1>📦 Importar Produtos para Lojistas</h1>
    
    <!-- Seleção de Lojista -->
    <div class="card">
        <h2>🎯 Selecionar Lojista de Destino</h2>
        <div id="lojista-selection">
            <select id="lojista_destino" class="regular-text" required>
                <option value="">Selecione o lojista...</option>
                <?php foreach ($lojistas as $lojista): ?>
                    <option value="<?php echo esc_attr($lojista['id']); ?>">
                        <?php echo esc_html($lojista['nome']); ?> (<?php echo esc_html($lojista['url']); ?>)
                        - Status: <?php echo ucfirst($lojista['status']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="btn-validar-lojista" class="button button-primary" disabled>
                🔍 Validar Conexão
            </button>
        </div>
        <div id="validacao-status" style="display: none; margin-top: 15px;"></div>
    </div>

    <!-- Carregar Produtos -->
    <div class="card">
        <h2>📋 Produtos da Fábrica</h2>
        <p>Carregue os produtos disponíveis na fábrica para importação.</p>
        <button type="button" id="btn-carregar-produtos" class="button button-secondary" disabled>
            📋 Carregar Produtos
        </button>
    </div>

    <!-- Lista de Produtos -->
    <div id="produtos-section" style="display: none;">
        <div class="card">
            <h3>Produtos Disponíveis</h3>
            
            <!-- Controles -->
            <div class="controles-section">
                <div class="controles-row">
                    <label>
                        <input type="checkbox" id="selecionar-todos"> 
                        Selecionar Todos
                    </label>
                    <input type="text" id="buscar-produto" placeholder="🔍 Buscar por nome ou SKU..." class="regular-text">
                    <span id="produtos-count" class="contador-produtos">0 produtos selecionados</span>
                </div>
            </div>
            
            <!-- Grid de Produtos -->
            <div id="produtos-resumo"></div>
            <div id="produtos-grid"></div>
            <div id="produtos-pagination"></div>
        </div>
    </div>

    <!-- Opções de Importação -->
    <div id="opcoes-importacao" style="display: none;">
        <div class="card">
            <h3>⚙️ Opções de Importação</h3>
            <div class="opcoes-importacao">
                <div class="opcao-item">
                    <label>
                        <input type="checkbox" id="incluir_variacoes" checked>
                        Incluir Variações
                    </label>
                    <div class="opcao-descricao">
                        Importa todas as variações dos produtos variáveis
                    </div>
                </div>
                
                <div class="opcao-item">
                    <label>
                        <input type="checkbox" id="incluir_imagens" checked>
                        Incluir Imagens
                    </label>
                    <div class="opcao-descricao">
                        Copia imagens principais e galeria de fotos
                    </div>
                </div>
                
                <div class="opcao-item">
                    <label>
                        <input type="checkbox" id="manter_precos" checked>
                        Manter Preços Originais
                    </label>
                    <div class="opcao-descricao">
                        Preserva os preços da fábrica sem alterações
                    </div>
                </div>

                <div class="opcao-item percentual-section">
                    <label for="percentual_acrescimo">
                        💰 Percentual de Acréscimo no Preço
                    </label>
                    <div class="percentual-controls">
                        <input type="number" 
                               id="percentual_acrescimo" 
                               class="regular-text" 
                               value="0" 
                               min="0" 
                               max="1000" 
                               step="0.1"
                               placeholder="0"
                               style="width: 120px;">
                        <span class="percentual-symbol">%</span>
                    </div>
                    <div class="opcao-descricao">
                        <strong>Exemplos:</strong> 50 = +50% no preço | 0 = sem alteração | 25.5 = +25,5%
                        <br><small style="color: #666;">⚠️ Este percentual será aplicado automaticamente se "Manter Preços Originais" estiver <strong>desmarcado</strong></small>
                    </div>
                </div>                               
            </div>
        </div>
    </div>

    <!-- Botões de Ação -->
    <div id="botoes-acao" style="display: none;">
        <div class="botoes-acao">
            <button type="button" id="btn-iniciar-importacao" class="button button-primary button-hero" disabled>
                🚀 Iniciar Importação
            </button>
            <p><small>Certifique-se de que selecionou os produtos e configurou as opções desejadas.</small></p>
        </div>
    </div>
</div>

<!-- Modal de Resultado -->
<div id="modal-resultado" class="sincronizador-modal">
    <div class="sincronizador-modal-content">
        <div id="modal-resultado-conteudo"></div>
        <div class="sincronizador-modal-footer">
            <button type="button" class="button modal-close" aria-label="Fechar">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</div>
