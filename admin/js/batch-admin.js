/**
 * JavaScript para processamento em lote
 */
(function($) {
    'use strict';
    
    var SincronizadorBatch = {
        currentBatchId: null,
        statusCheckInterval: null,
        isProcessing: false,
        
        init: function() {
            this.bindEvents();
            this.initExistingBatches();
        },
        
        bindEvents: function() {
            $(document).on('click', '.batch-btn-start', this.startBatch.bind(this));
            $(document).on('click', '.batch-btn-pause', this.pauseBatch.bind(this));
            $(document).on('click', '.batch-btn-stop', this.stopBatch.bind(this));
            $(document).on('click', '.batch-btn-retry', this.retryBatch.bind(this));
            $(document).on('click', '.cache-btn', this.handleCacheAction.bind(this));
            $(document).on('change', '#batch-size-selector', this.updateBatchSize.bind(this));
            
            // Auto-save form data
            $(document).on('change', '.batch-form input, .batch-form select', this.saveFormState.bind(this));
            
            // Restore form data on load
            this.restoreFormState();
        },
        
        startBatch: function(e) {
            e.preventDefault();
            
            if (this.isProcessing) {
                this.showToast('Já existe um processamento em andamento', 'warning');
                return;
            }
            
            var $btn = $(e.currentTarget);
            var produtos = this.getSelectedProducts();
            var lojistas = this.getSelectedLojistas();
            
            if (produtos.length === 0) {
                this.showToast('Selecione pelo menos um produto', 'error');
                return;
            }
            
            if (lojistas.length === 0) {
                this.showToast('Selecione pelo menos um lojista', 'error');
                return;
            }
            
            this.showToast('Iniciando processamento em lote...', 'info');
            
            $btn.prop('disabled', true).html('<span class="batch-processing-loader"></span>Iniciando...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sincronizador_start_batch',
                    nonce: sincronizador_ajax.nonce,
                    produtos: produtos,
                    lojistas: lojistas,
                    options: this.getBatchOptions()
                },
                success: function(response) {
                    if (response.success) {
                        this.currentBatchId = response.data.batch_id;
                        this.isProcessing = true;
                        this.updateBatchProgress(response.data.progress);
                        this.startStatusMonitoring();
                        this.showToast('Processamento iniciado com sucesso!', 'success');
                    } else {
                        this.showToast('Erro ao iniciar processamento: ' + (response.data?.message || 'Erro desconhecido'), 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showToast('Erro de conexão ao iniciar processamento', 'error');
                }.bind(this),
                complete: function() {
                    $btn.prop('disabled', false).html('Iniciar Processamento');
                }
            });
        },
        
        startStatusMonitoring: function() {
            if (this.statusCheckInterval) {
                clearInterval(this.statusCheckInterval);
            }
            
            this.statusCheckInterval = setInterval(function() {
                this.checkBatchStatus();
            }.bind(this), 2000); // Verificar a cada 2 segundos
        },
        
        checkBatchStatus: function() {
            if (!this.currentBatchId) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'sincronizador_get_batch_status',
                    nonce: sincronizador_ajax.nonce,
                    batch_id: this.currentBatchId
                },
                success: function(response) {
                    if (response.success) {
                        this.updateBatchProgress(response.data);
                        
                        if (response.data.status === 'completed') {
                            this.completeBatch(response.data);
                        } else if (response.data.status === 'error') {
                            this.errorBatch(response.data);
                        }
                    }
                }.bind(this),
                error: function() {
                    console.log('Erro ao verificar status do lote');
                }
            });
        },
        
        updateBatchProgress: function(data) {
            var progress = data.progress || 0;
            var processed = data.processed || 0;
            var total = data.total || 0;
            var successful = data.successful || 0;
            var failed = data.failed || 0;
            
            // Atualizar barra de progresso
            $('.batch-progress-fill').css('width', progress + '%');
            $('.batch-progress-text').text(progress.toFixed(1) + '%');
            
            // Atualizar estatísticas
            $('.batch-stat-processed .batch-stat-number').text(processed);
            $('.batch-stat-total .batch-stat-number').text(total);
            $('.batch-stat-successful .batch-stat-number').text(successful);
            $('.batch-stat-failed .batch-stat-number').text(failed);
            
            // Atualizar log de erros se houver
            if (data.errors && data.errors.length > 0) {
                this.updateErrorLog(data.errors);
            }
            
            // Atualizar tempo estimado
            if (data.execution_time) {
                var remaining = ((total - processed) / (processed / data.execution_time));
                $('.batch-stat-eta .batch-stat-number').text(this.formatTime(remaining));
            }
        },
        
        completeBatch: function(data) {
            this.isProcessing = false;
            clearInterval(this.statusCheckInterval);
            
            this.showToast('Processamento concluído com sucesso!', 'success');
            
            // Habilitar botão de novo processamento
            $('.batch-btn-start').prop('disabled', false);
            $('.batch-btn-pause, .batch-btn-stop').prop('disabled', true);
            
            // Atualizar dashboard
            this.refreshDashboard();
        },
        
        errorBatch: function(data) {
            this.isProcessing = false;
            clearInterval(this.statusCheckInterval);
            
            this.showToast('Erro durante o processamento. Verifique os logs.', 'error');
            
            // Habilitar botões de retry
            $('.batch-btn-retry').prop('disabled', false);
        },
        
        pauseBatch: function(e) {
            e.preventDefault();
            
            if (!this.currentBatchId) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sincronizador_pause_batch',
                    nonce: sincronizador_ajax.nonce,
                    batch_id: this.currentBatchId
                },
                success: function(response) {
                    if (response.success) {
                        this.isProcessing = false;
                        clearInterval(this.statusCheckInterval);
                        this.showToast('Processamento pausado', 'info');
                    }
                }.bind(this)
            });
        },
        
        stopBatch: function(e) {
            e.preventDefault();
            
            if (!confirm('Tem certeza que deseja parar o processamento? Esta ação não pode ser desfeita.')) {
                return;
            }
            
            if (!this.currentBatchId) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sincronizador_stop_batch',
                    nonce: sincronizador_ajax.nonce,
                    batch_id: this.currentBatchId
                },
                success: function(response) {
                    if (response.success) {
                        this.isProcessing = false;
                        clearInterval(this.statusCheckInterval);
                        this.currentBatchId = null;
                        this.showToast('Processamento interrompido', 'warning');
                        this.resetBatchUI();
                    }
                }.bind(this)
            });
        },
        
        retryBatch: function(e) {
            e.preventDefault();
            // Implementar retry do último lote com falha
            this.showToast('Funcionalidade de retry será implementada', 'info');
        },
        
        handleCacheAction: function(e) {
            e.preventDefault();
            
            var $btn = $(e.currentTarget);
            var action = $btn.data('action');
            
            if (action === 'clear' && !confirm('Tem certeza que deseja limpar todo o cache?')) {
                return;
            }
            
            $btn.prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sincronizador_cache_action',
                    nonce: sincronizador_ajax.nonce,
                    cache_action: action
                },
                success: function(response) {
                    if (response.success) {
                        this.showToast('Ação de cache executada com sucesso', 'success');
                        this.updateCacheStatus();
                    } else {
                        this.showToast('Erro ao executar ação de cache', 'error');
                    }
                }.bind(this),
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },
        
        updateCacheStatus: function() {
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'sincronizador_get_cache_status',
                    nonce: sincronizador_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.renderCacheStatus(response.data);
                    }
                }.bind(this)
            });
        },
        
        renderCacheStatus: function(data) {
            var status = data.status || 'unknown';
            var $container = $('.cache-status');
            
            $container.removeClass('warning error').addClass(status);
            $container.find('.cache-status-text').text(data.message || 'Status desconhecido');
        },
        
        getSelectedProducts: function() {
            var produtos = [];
            $('.product-checkbox:checked').each(function() {
                produtos.push($(this).val());
            });
            return produtos;
        },
        
        getSelectedLojistas: function() {
            var lojistas = [];
            $('.lojista-checkbox:checked').each(function() {
                lojistas.push($(this).val());
            });
            return lojistas;
        },
        
        getBatchOptions: function() {
            return {
                batch_size: $('#batch-size-selector').val() || 50,
                include_variations: $('#include-variations').is(':checked'),
                update_existing: $('#update-existing').is(':checked'),
                sync_images: $('#sync-images').is(':checked'),
                priority: $('#batch-priority').val() || 'normal'
            };
        },
        
        updateBatchSize: function(e) {
            var newSize = $(e.target).val();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sincronizador_update_batch_size',
                    nonce: sincronizador_ajax.nonce,
                    batch_size: newSize
                }
            });
        },
        
        saveFormState: function() {
            var formData = {
                selected_products: this.getSelectedProducts(),
                selected_lojistas: this.getSelectedLojistas(),
                options: this.getBatchOptions()
            };
            
            localStorage.setItem('sincronizador_batch_form', JSON.stringify(formData));
        },
        
        restoreFormState: function() {
            var savedData = localStorage.getItem('sincronizador_batch_form');
            
            if (!savedData) return;
            
            try {
                var data = JSON.parse(savedData);
                
                // Restaurar produtos selecionados
                if (data.selected_products) {
                    data.selected_products.forEach(function(productId) {
                        $('.product-checkbox[value="' + productId + '"]').prop('checked', true);
                    });
                }
                
                // Restaurar lojistas selecionados
                if (data.selected_lojistas) {
                    data.selected_lojistas.forEach(function(lojistaId) {
                        $('.lojista-checkbox[value="' + lojistaId + '"]').prop('checked', true);
                    });
                }
                
                // Restaurar opções
                if (data.options) {
                    Object.keys(data.options).forEach(function(key) {
                        var $field = $('#' + key.replace(/_/g, '-'));
                        if ($field.is(':checkbox')) {
                            $field.prop('checked', data.options[key]);
                        } else {
                            $field.val(data.options[key]);
                        }
                    });
                }
            } catch (e) {
                console.log('Erro ao restaurar estado do formulário:', e);
            }
        },
        
        initExistingBatches: function() {
            // Verificar se há batches em andamento
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'sincronizador_get_active_batches',
                    nonce: sincronizador_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        var activeBatch = response.data[0];
                        this.currentBatchId = activeBatch.id;
                        this.isProcessing = true;
                        this.updateBatchProgress(activeBatch);
                        this.startStatusMonitoring();
                        this.showToast('Processamento em andamento recuperado', 'info');
                    }
                }.bind(this)
            });
        },
        
        refreshDashboard: function() {
            // Recarregar widgets do dashboard
            $('.performance-widget').each(function() {
                var $widget = $(this);
                var widgetType = $widget.data('widget-type');
                
                if (widgetType) {
                    SincronizadorBatch.loadWidget(widgetType, $widget);
                }
            });
        },
        
        loadWidget: function(type, $container) {
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'sincronizador_get_widget_data',
                    nonce: sincronizador_ajax.nonce,
                    widget_type: type
                },
                success: function(response) {
                    if (response.success) {
                        $container.html(response.data.html);
                    }
                }
            });
        },
        
        updateErrorLog: function(errors) {
            var $errorLog = $('.batch-error-log');
            
            if (errors.length === 0) {
                $errorLog.hide();
                return;
            }
            
            $errorLog.show();
            var $errorList = $errorLog.find('.batch-error-list');
            
            $errorList.empty();
            
            errors.forEach(function(error) {
                var $errorItem = $('<div class="batch-error-item">');
                $errorItem.html(
                    '<span class="batch-error-timestamp">' + error.timestamp + '</span>' +
                    '<span class="batch-error-message">' + error.error + '</span>'
                );
                $errorList.append($errorItem);
            });
        },
        
        resetBatchUI: function() {
            $('.batch-progress-fill').css('width', '0%');
            $('.batch-progress-text').text('0%');
            $('.batch-stat-number').text('0');
            $('.batch-error-log').hide();
            $('.batch-btn-start').prop('disabled', false);
            $('.batch-btn-pause, .batch-btn-stop, .batch-btn-retry').prop('disabled', true);
        },
        
        formatTime: function(seconds) {
            if (!seconds || seconds < 0) return '--';
            
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            var secs = Math.floor(seconds % 60);
            
            if (hours > 0) {
                return hours + 'h ' + minutes + 'm';
            } else if (minutes > 0) {
                return minutes + 'm ' + secs + 's';
            } else {
                return secs + 's';
            }
        },
        
        showToast: function(message, type) {
            type = type || 'info';
            
            var $toast = $('<div class="sincronizador-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);
            
            setTimeout(function() {
                $toast.fadeOut(function() {
                    $toast.remove();
                });
            }, 5000);
        }
    };
    
    // Inicializar quando o documento estiver pronto
    $(document).ready(function() {
        SincronizadorBatch.init();
    });
    
    // Exportar para uso global
    window.SincronizadorBatch = SincronizadorBatch;
    
})(jQuery);
