/**
 * JavaScript Utilitário Centralizado - Sincronizador WooCommerce
 * Elimina duplicações entre admin.js e admin-scripts.js
 * @version 1.2.0
 */

(function($) {
    'use strict';

    // Namespace global centralizado
    if (typeof window.SincronizadorWC === 'undefined') {
        window.SincronizadorWC = {};
    }
    
    // Configuração centralizada de AJAX
    window.SincronizadorWC.ajaxConfig = {
        url: window.SincronizadorWC.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
        nonce: window.SincronizadorWC.nonce || '',
        timeout: 30000,
        retries: 3
    };

    // Mensagens padronizadas
    window.SincronizadorWC.messages = {
        loading: 'Carregando...',
        processing: 'Processando...',
        error: 'Erro na operação',
        success: 'Operação realizada com sucesso',
        confirmDelete: 'Tem certeza que deseja remover?',
        validatingConnection: 'Testando conexão...',
        connectionSuccess: 'Conexão OK!',
        connectionError: 'Erro na conexão',
        selectProducts: 'Selecione pelo menos um produto',
        importing: 'Importando produtos...',
        syncing: 'Sincronizando dados...'
    };

    /**
     * CLASSE UTILITÁRIA CENTRALIZADA
     * Elimina duplicações e centraliza funcionalidades comuns
     */
    window.SincronizadorWC.Utils = {
        
        /**
         * Faz requisição AJAX padronizada - CENTRALIZADA
         * Remove duplicação de configurações AJAX
         * 
         * @param {Object} options Opções da requisição
         * @returns {Promise}
         */
        ajaxRequest: function(options) {
            const defaults = {
                url: this.ajaxConfig.url,
                type: 'POST',
                dataType: 'json',
                timeout: this.ajaxConfig.timeout,
                data: {
                    nonce: this.ajaxConfig.nonce
                }
            };
            
            const settings = $.extend(true, {}, defaults, options);
            
            // Garantir que sempre temos nonce
            if (!settings.data.nonce && this.ajaxConfig.nonce) {
                settings.data.nonce = this.ajaxConfig.nonce;
            }
            
            return $.ajax(settings);
        }.bind(window.SincronizadorWC),
        
        /**
         * Teste de conexão centralizado - ELIMINA DUPLICAÇÃO
         * Substitui testConnection() em admin.js e admin-scripts.js
         * 
         * @param {number} lojistaId ID do lojista
         * @param {Object} elements Elementos DOM para atualizar
         */
        testConnection: function(lojistaId, elements = {}) {
            const $button = elements.button || $('.test-connection[data-lojista-id="' + lojistaId + '"]');
            const $status = elements.status || $('.connection-status');
            
            if (!lojistaId) {
                this.showError($status, 'ID do lojista não encontrado');
                return Promise.reject('ID inválido');
            }
            
            // Estado de loading
            this.setLoadingState($button, '🔄 Testando...');
            this.showInfo($status, '⏳ ' + this.messages.validatingConnection);
            
            return this.ajaxRequest({
                data: {
                    action: 'sincronizador_wc_test_connection',
                    lojista_id: lojistaId
                }
            }).done(function(response) {
                if (response.success) {
                    this.showSuccess($status, '✅ ' + response.data.message);
                    this.showNotice(response.data.message, 'success');
                } else {
                    const errorMsg = response.data?.message || response.data || 'Erro na conexão';
                    this.showError($status, '❌ ' + errorMsg);
                    this.showNotice(errorMsg, 'error');
                }
            }.bind(this)).fail(function(xhr, status, error) {
                console.error('Erro na requisição de teste:', error);
                const errorMsg = 'Erro na comunicação: ' + error;
                this.showError($status, '❌ ' + errorMsg);
                this.showNotice(errorMsg, 'error');
            }.bind(this)).always(function() {
                this.clearLoadingState($button, '🔄 Testar Conexão');
            }.bind(this));
        }.bind(window.SincronizadorWC),
        
        /**
         * Exclusão de lojista centralizada - ELIMINA DUPLICAÇÃO
         * 
         * @param {number} lojistaId ID do lojista
         * @param {Object} elements Elementos DOM
         */
        deleteLojista: function(lojistaId, elements = {}) {
            if (!confirm(this.messages.confirmDelete)) {
                return Promise.reject('Cancelado pelo usuário');
            }
            
            const $button = elements.button || $('.delete-lojista[data-lojista-id="' + lojistaId + '"]');
            const $row = elements.row || $button.closest('tr');
            
            this.setLoadingState($button, this.messages.processing);
            
            return this.ajaxRequest({
                data: {
                    action: 'sincronizador_wc_delete_lojista',
                    lojista_id: lojistaId
                }
            }).done(function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    this.showNotice(response.data.message, 'success');
                } else {
                    this.showNotice(response.data.message || 'Erro ao excluir lojista', 'error');
                }
            }.bind(this)).fail(function(xhr, status, error) {
                this.showNotice('Erro na comunicação: ' + error, 'error');
            }.bind(this)).always(function() {
                this.clearLoadingState($button);
            }.bind(this));
        }.bind(window.SincronizadorWC),
        
        /**
         * Sincronização forçada centralizada - ELIMINA DUPLICAÇÃO
         * 
         * @param {number|null} lojistaId ID do lojista (null = todos)
         * @param {Object} elements Elementos DOM
         */
        forceSync: function(lojistaId = null, elements = {}) {
            const $button = elements.button || $('.force-sync');
            
            this.setLoadingState($button, this.messages.syncing);
            
            return this.ajaxRequest({
                data: {
                    action: 'sincronizador_wc_force_sync',
                    lojista_id: lojistaId
                }
            }).done(function(response) {
                if (response.success) {
                    this.showNotice(response.data.message || 'Sincronização realizada com sucesso', 'success');
                } else {
                    this.showNotice(response.data.message || 'Erro na sincronização', 'error');
                }
            }.bind(this)).fail(function(xhr, status, error) {
                this.showNotice('Erro na sincronização: ' + error, 'error');
            }.bind(this)).always(function() {
                this.clearLoadingState($button, 'Sincronizar');
            }.bind(this));
        }.bind(window.SincronizadorWC),
        
        /**
         * Estados de loading centralizados - ELIMINA DUPLICAÇÃO
         */
        setLoadingState: function($element, text) {
            if ($element.length) {
                $element.data('original-text', $element.text())
                        .text(text || this.messages.loading)
                        .prop('disabled', true);
            }
        }.bind(window.SincronizadorWC),
        
        clearLoadingState: function($element, text) {
            if ($element.length) {
                const originalText = text || $element.data('original-text') || 'OK';
                $element.text(originalText).prop('disabled', false);
            }
        }.bind(window.SincronizadorWC),
        
        /**
         * Sistema de notificações centralizado - ELIMINA DUPLICAÇÃO
         */
        showNotice: function(message, type = 'info', duration = 5000) {
            // Remover notificações existentes
            $('.sincronizador-notice').remove();
            
            const noticeClass = 'notice notice-' + type + ' sincronizador-notice';
            const $notice = $('<div class="' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Inserir após h1 ou no início do wrap
            const $target = $('.wrap h1').first();
            if ($target.length) {
                $target.after($notice);
            } else {
                $('.wrap').prepend($notice);
            }
            
            // Auto-remover após tempo determinado
            setTimeout(function() {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, duration);
            
            return $notice;
        }.bind(window.SincronizadorWC),
        
        /**
         * Helpers para status visual centralizados
         */
        showSuccess: function($element, message) {
            if ($element.length) {
                $element.html('<span style="color: green;">' + message + '</span>');
            }
        },
        
        showError: function($element, message) {
            if ($element.length) {
                $element.html('<span style="color: red;">' + message + '</span>');
            }
        },
        
        showInfo: function($element, message) {
            if ($element.length) {
                $element.html('<span style="color: #0073aa;">' + message + '</span>');
            }
        },
        
        /**
         * Validação centralizada - ELIMINA DUPLICAÇÃO
         */
        validateId: function(id, fieldName = 'ID') {
            const validId = parseInt(id);
            if (!validId || validId <= 0) {
                this.showNotice(fieldName + ' inválido fornecido', 'error');
                return false;
            }
            return validId;
        }.bind(window.SincronizadorWC),
        
        validateNonce: function() {
            if (!this.ajaxConfig.nonce) {
                console.warn('⚠️ NONCE NÃO DEFINIDO! Os requests AJAX podem falhar.');
                return false;
            }
            return true;
        }.bind(window.SincronizadorWC)
    };

    /**
     * INICIALIZAÇÃO CENTRALIZADA
     * Substitui múltiplos $(document).ready()
     */
    $(document).ready(function() {
        // Verificar nonce
        window.SincronizadorWC.Utils.validateNonce();
        
        // Event delegation centralizada - ELIMINA DUPLICAÇÃO
        $(document)
            // Teste de conexão
            .on('click', '.test-connection', function(e) {
                e.preventDefault();
                const lojistaId = $(this).data('lojista-id');
                window.SincronizadorWC.Utils.testConnection(lojistaId, {
                    button: $(this)
                });
            })
            
            // Exclusão de lojista
            .on('click', '.delete-lojista', function(e) {
                e.preventDefault();
                const lojistaId = $(this).data('lojista-id');
                window.SincronizadorWC.Utils.deleteLojista(lojistaId, {
                    button: $(this)
                });
            })
            
            // Sincronização forçada
            .on('click', '.force-sync', function(e) {
                e.preventDefault();
                const lojistaId = $(this).data('lojista-id') || null;
                window.SincronizadorWC.Utils.forceSync(lojistaId, {
                    button: $(this)
                });
            })
            
            // Fechar notificações
            .on('click', '.sincronizador-notice .notice-dismiss', function() {
                $(this).closest('.sincronizador-notice').fadeOut(300, function() {
                    $(this).remove();
                });
            });
    });

})(jQuery);
