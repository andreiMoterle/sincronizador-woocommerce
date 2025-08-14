<?php
/**
 * Template da página de relatórios
 * 
 * @package Sincronizador_WooCommerce
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap sincronizador-wc-wrap">
    <h1 class="wp-heading-inline">📊 Relatórios de Vendas</h1>
    
    <!-- Filtros -->
    <div class="card">
        <h3>📅 Filtros</h3>
        <div class="filtros-relatorio">
            <div class="filtro-item">
                <label for="filtro-lojista">Lojista:</label>
                <select id="filtro-lojista">
                    <option value="">Todos os lojistas</option>
                </select>
            </div>
            <div class="filtro-item">
                <label for="filtro-periodo">Período:</label>
                <select id="filtro-periodo">
                    <option value="7">Últimos 7 dias</option>
                    <option value="30" selected>Últimos 30 dias</option>
                    <option value="90">Últimos 90 dias</option>
                    <option value="custom">Período personalizado</option>
                </select>
            </div>
            <div class="filtro-item filtro-datas" style="display: none;">
                <label for="data-inicio">De:</label>
                <input type="date" id="data-inicio">
                <label for="data-fim">Até:</label>
                <input type="date" id="data-fim">
            </div>
            <div class="filtro-item">
                <button type="button" class="button button-primary" id="btn-aplicar-filtros">
                    🔍 Aplicar Filtros
                </button>
                <button type="button" class="button" id="btn-limpar-filtros">
                    🧹 Limpar
                </button>
            </div>
        </div>
    </div>

    <!-- Cards de Resumo de Vendas -->
    <div class="cards-resumo">
        <div class="card card-resumo">
            <div class="card-icon">💰</div>
            <div class="card-content">
                <h3 id="total-vendas">R$ -</h3>
                <p>Total de Vendas</p>
            </div>
        </div>
        
        <div class="card card-resumo">
            <div class="card-icon">🛒</div>
            <div class="card-content">
                <h3 id="total-pedidos">-</h3>
                <p>Total de Pedidos</p>
            </div>
        </div>
        
        <div class="card card-resumo">
            <div class="card-icon">📦</div>
            <div class="card-content">
                <h3 id="produtos-vendidos">-</h3>
                <p>Produtos Vendidos</p>
            </div>
        </div>
        
        <div class="card card-resumo">
            <div class="card-icon">🏪</div>
            <div class="card-content">
                <h3 id="lojistas-ativos">-</h3>
                <p>Lojistas Ativos</p>
            </div>
        </div>
    </div>

    <!-- Gráfico de Vendas por Lojista -->
    <div class="graficos-container">
        <div class="card full-width">
            <h3>📊 Vendas por Lojista</h3>
            <div class="grafico-vendas-container">
                <canvas id="grafico-vendas-lojista" width="800" height="300"></canvas>
            </div>
        </div>
    </div>

    <!-- Produtos Mais Vendidos -->
    <div class="card full-width">
        <h3>🏆 Produtos Mais Vendidos</h3>
        <div class="produtos-mais-vendidos-container">
            
            <div class="tabela-container">
                <table class="wp-list-table widefat fixed striped produtos-table">
                    <thead>
                        <tr>
                            <th class="ranking-col">#</th>
                            <th class="produto-col">Produto</th>
                            <th class="sku-col">SKU</th>
                            <th class="lojista-col">Lojista</th>
                            <th class="quantidade-col">Qtd. Vendida</th>
                            <th class="receita-col">Receita Total</th>
                            <th class="preco-col">Preço Médio</th>
                        </tr>
                    </thead>
                    <tbody id="produtos-mais-vendidos-tbody">
                        <tr>
                            <td colspan="7" class="loading">
                                🔄 Carregando produtos mais vendidos...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Vendas Detalhadas -->
    <div class="card full-width">
        <h3>📋 Vendas Detalhadas</h3>
        
        <div id="vendas-detalhadas-info" class="notice notice-info" style="margin: 10px 0;">
            <p>💡 <strong>Selecione um lojista específico</strong> nos filtros acima para visualizar as vendas detalhadas.</p>
        </div>
        
        <div class="vendas-detalhadas-container">
            <div class="vendas-actions">
                <div id="filtro-info-vendas" style="display: none; margin-bottom: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #007cba;">
                    <strong>🏪 Exibindo vendas de:</strong> <span id="lojista-selecionado-nome">-</span><br>
                    <strong>📅 Período:</strong> <span id="periodo-selecionado">-</span>
                </div>
                
                <button type="button" class="button" id="btn-exportar-csv">
                    📥 Exportar CSV
                </button>
            </div>
            
            <div class="tabela-container">
                <table class="wp-list-table widefat fixed striped vendas-table">
                    <thead>
                        <tr>
                            <th class="data-col">Data</th>
                            <th class="lojista-col">Lojista</th>
                            <th class="pedido-col">Pedido</th>
                            <th class="cliente-col">Cliente</th>
                            <th class="produtos-col">Produtos</th>
                            <th class="valor-col">Valor Total</th>
                            <th class="status-col">Status</th>
                        </tr>
                    </thead>
                    <tbody id="vendas-detalhadas-tbody">
                        <tr>
                            <td colspan="7" class="loading">
                                🔄 Carregando vendas...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginação -->
            <div class="tablenav">
                <div class="alignleft actions">
                    <span class="displaying-num" id="displaying-num">Mostrando 1 a 10 de 0 registros</span>
                </div>
                <div class="tablenav-pages" id="pagination-container">
                    <span class="pagination-links">
                        <a class="first-page button" href="#" id="first-page" title="Primeira página">«</a>
                        <a class="prev-page button" href="#" id="prev-page" title="Página anterior">‹</a>
                        <span class="paging-input">
                            Página 
                            <input class="current-page" id="current-page" name="paged" value="1" size="2" aria-describedby="table-paging" type="text">
                            de 
                            <span class="total-pages" id="total-pages">1</span>
                        </span>
                        <a class="next-page button" href="#" id="next-page" title="Próxima página">›</a>
                        <a class="last-page button" href="#" id="last-page" title="Última página">»</a>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loading-overlay" style="display: none;">
        <div class="loading-content">
            <div class="loading-spinner">🔄</div>
            <p>Carregando...</p>
        </div>
    </div>
</div>
