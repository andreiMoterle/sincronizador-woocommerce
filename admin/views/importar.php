<?php
/**
 * View para importar produtos
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Importar Produtos', 'sincronizador-wc'); ?></h1>
    
    <div class="sincronizador-import">
        <!-- Formulário de Busca -->
        <div class="search-form">
            <form method="get" action="">
                <input type="hidden" name="page" value="sincronizador-wc-importar">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="search"><?php _e('Buscar Produto', 'sincronizador-wc'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="search" name="search" 
                                   value="<?php echo esc_attr($_GET['search'] ?? ''); ?>" 
                                   placeholder="<?php _e('Nome do produto ou SKU', 'sincronizador-wc'); ?>"
                                   class="regular-text">
                            <input type="submit" class="button button-secondary" 
                                   value="<?php _e('Buscar', 'sincronizador-wc'); ?>">
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        
        <!-- Resultados da Busca -->
        <?php if (!empty($products)): ?>
            <div class="products-results">
                <h2><?php printf(__('Encontrados %d produtos', 'sincronizador-wc'), count($products)); ?></h2>
                
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card" data-product-id="<?php echo esc_attr($product['id']); ?>">
                            <div class="product-image">
                                <?php if ($product['image']): ?>
                                    <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['name']); ?>">
                                <?php else: ?>
                                    <div class="no-image">
                                        <span class="dashicons dashicons-format-image"></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-info">
                                <h3><?php echo esc_html($product['name']); ?></h3>
                                
                                <div class="product-meta">
                                    <p><strong><?php _e('SKU:', 'sincronizador-wc'); ?></strong> <?php echo esc_html($product['sku']); ?></p>
                                    <p><strong><?php _e('Preço:', 'sincronizador-wc'); ?></strong> R$ <?php echo number_format($product['price'], 2, ',', '.'); ?></p>
                                    <p><strong><?php _e('Tipo:', 'sincronizador-wc'); ?></strong> <?php echo esc_html($product['type']); ?></p>
                                    
                                    <?php if ($product['has_variations']): ?>
                                        <p><strong><?php _e('Variações:', 'sincronizador-wc'); ?></strong> <?php echo esc_html($product['has_variations']); ?></p>
                                    <?php endif; ?>
                                    
                                    <p><strong><?php _e('Estoque:', 'sincronizador-wc'); ?></strong> 
                                        <span class="stock-status stock-<?php echo esc_attr($product['stock_status']); ?>">
                                            <?php 
                                            switch ($product['stock_status']) {
                                                case 'instock':
                                                    _e('Em estoque', 'sincronizador-wc');
                                                    if ($product['stock_quantity']) {
                                                        echo ' (' . $product['stock_quantity'] . ')';
                                                    }
                                                    break;
                                                case 'outofstock':
                                                    _e('Fora de estoque', 'sincronizador-wc');
                                                    break;
                                                case 'onbackorder':
                                                    _e('Sob encomenda', 'sincronizador-wc');
                                                    break;
                                            }
                                            ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <div class="product-actions">
                                    <button class="button button-secondary view-details" 
                                            data-product-id="<?php echo esc_attr($product['id']); ?>">
                                        <?php _e('Ver Detalhes', 'sincronizador-wc'); ?>
                                    </button>
                                    
                                    <button class="button button-primary import-product" 
                                            data-product-id="<?php echo esc_attr($product['id']); ?>">
                                        <?php _e('Importar', 'sincronizador-wc'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif (isset($_GET['search']) && !empty($_GET['search'])): ?>
            <div class="no-results">
                <p><?php _e('Nenhum produto encontrado com os critérios de busca.', 'sincronizador-wc'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal para detalhes do produto -->
<div id="product-details-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="product-modal-title"><?php _e('Detalhes do Produto', 'sincronizador-wc'); ?></h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="product-details-content">
                <!-- Conteúdo será carregado via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Modal para seleção de lojistas -->
<div id="lojistas-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2><?php _e('Selecionar Lojistas', 'sincronizador-wc'); ?></h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <p><?php _e('Selecione os lojistas para os quais deseja enviar este produto:', 'sincronizador-wc'); ?></p>
            
            <form id="import-form">
                <input type="hidden" id="import-product-id" name="product_id" value="">
                
                <div class="lojistas-list">
                    <?php foreach ($lojistas as $lojista): ?>
                        <label class="lojista-checkbox">
                            <input type="checkbox" name="lojistas[]" value="<?php echo esc_attr($lojista->id); ?>">
                            <span class="lojista-name"><?php echo esc_html($lojista->nome); ?></span>
                            <span class="lojista-url"><?php echo esc_html($lojista->url_loja); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="button button-secondary cancel-import">
                        <?php _e('Cancelar', 'sincronizador-wc'); ?>
                    </button>
                    <button type="submit" class="button button-primary confirm-import">
                        <?php _e('Importar Produto', 'sincronizador-wc'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.sincronizador-import {
    max-width: 1200px;
}

.search-form {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.product-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.product-image {
    text-align: center;
    margin-bottom: 15px;
}

.product-image img {
    max-width: 100%;
    height: auto;
    max-height: 150px;
    border-radius: 4px;
}

.no-image {
    width: 150px;
    height: 150px;
    background: #f6f7f7;
    border: 2px dashed #ccd0d4;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    border-radius: 4px;
}

.no-image .dashicons {
    font-size: 40px;
    color: #a7aaad;
}

.product-info h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #23282d;
}

.product-meta p {
    margin: 5px 0;
    font-size: 14px;
    color: #646970;
}

.stock-status {
    font-weight: 500;
}

.stock-instock {
    color: #00a32a;
}

.stock-outofstock {
    color: #d63638;
}

.stock-onbackorder {
    color: #dba617;
}

.product-actions {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}

.product-actions .button {
    flex: 1;
}

/* Modal Styles */
.modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fff;
    margin: 5% auto;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    width: 80%;
    max-width: 600px;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #ccd0d4;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
}

.close {
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #a7aaad;
}

.close:hover {
    color: #000;
}

.modal-body {
    padding: 20px;
}

.lojistas-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 20px;
}

.lojista-checkbox {
    display: block;
    padding: 10px;
    border-bottom: 1px solid #f0f0f1;
    cursor: pointer;
}

.lojista-checkbox:last-child {
    border-bottom: none;
}

.lojista-checkbox:hover {
    background: #f6f7f7;
}

.lojista-name {
    font-weight: 500;
    display: block;
}

.lojista-url {
    font-size: 12px;
    color: #646970;
    display: block;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.no-results {
    text-align: center;
    padding: 40px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}
</style>

<script>
jQuery(document).ready(function($) {
    
    // Ver detalhes do produto
    $('.view-details').click(function() {
        var productId = $(this).data('product-id');
        
        $.ajax({
            url: sincronizador_wc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sincronizador_wc_get_product_details',
                product_id: productId,
                nonce: sincronizador_wc_ajax.nonce
            },
            beforeSend: function() {
                $('#product-details-content').html('<p><?php _e('Carregando...', 'sincronizador-wc'); ?></p>');
                $('#product-details-modal').show();
            },
            success: function(response) {
                if (response.success) {
                    $('#product-details-content').html(response.data.html);
                } else {
                    $('#product-details-content').html('<p>Erro: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('#product-details-content').html('<p><?php _e('Erro na comunicação', 'sincronizador-wc'); ?></p>');
            }
        });
    });
    
    // Importar produto
    $('.import-product').click(function() {
        var productId = $(this).data('product-id');
        $('#import-product-id').val(productId);
        $('#lojistas-modal').show();
    });
    
    // Fechar modais
    $('.close, .cancel-import').click(function() {
        $('.modal').hide();
    });
    
    // Fechar modal clicando fora
    $(window).click(function(event) {
        if ($(event.target).hasClass('modal')) {
            $('.modal').hide();
        }
    });
    
    // Confirmar importação
    $('#import-form').submit(function(e) {
        e.preventDefault();
        
        var productId = $('#import-product-id').val();
        var selectedLojistas = [];
        
        $('input[name="lojistas[]"]:checked').each(function() {
            selectedLojistas.push($(this).val());
        });
        
        if (selectedLojistas.length === 0) {
            alert('<?php _e('Selecione pelo menos um lojista', 'sincronizador-wc'); ?>');
            return;
        }
        
        $.ajax({
            url: sincronizador_wc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sincronizador_wc_import_product',
                product_id: productId,
                lojistas: selectedLojistas,
                nonce: sincronizador_wc_ajax.nonce
            },
            beforeSend: function() {
                $('.confirm-import').text('<?php _e('Importando...', 'sincronizador-wc'); ?>').prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#lojistas-modal').hide();
                    location.reload();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('<?php _e('Erro na comunicação', 'sincronizador-wc'); ?>');
            },
            complete: function() {
                $('.confirm-import').text('<?php _e('Importar Produto', 'sincronizador-wc'); ?>').prop('disabled', false);
            }
        });
    });
});
</script>
