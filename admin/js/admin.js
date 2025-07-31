/**
 * JavaScript para administração do Sincronizador WooCommerce
 */

(function($) {
    'use strict';
    
    var SincronizadorWCAdmin = {
        
        init: function() {
            this.bindEvents();
            this.initComponents();
        },
        
        bindEvents: function() {
            // Teste de conexão com lojista
            $(document).on('click', '.test-connection', this.testConnection);
            
            // Exclusão de lojista
            $(document).on('click', '.delete-lojista', this.deleteLojista);
            
            // Sincronização forçada
            $(document).on('click', '.force-sync', this.forceSync);
            
            // Toggle de status de lojista
            $(document).on('change', '.lojista-status-toggle', this.toggleLojistaStatus);
            
            // Formulário de lojista
            $(document).on('submit', '#lojista-form', this.validateLojistaForm);
            
            // Modal handlers
            $(document).on('click', '.modal-close, .modal-backdrop', this.closeModal);
            $(document).on('click', '.btn-modal', this.openModal);
        },
        
        initComponents: function() {
            // Inicializar datepickers se disponível
            if ($.fn.datepicker) {
                $('.date-picker').datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true
                });
            }
            
            // Inicializar tooltips
            this.initTooltips();
            
            // Auto-refresh de stats a cada 5 minutos
            if ($('.dashboard-stats').length > 0) {
                setInterval(this.refreshStats, 300000);
            }
        },
        
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                var $this = $(this);
                var title = $this.data('tooltip');
                
                $this.hover(
                    function() {
                        var tooltip = $('<div class="sincronizador-tooltip">' + title + '</div>');
                        $('body').append(tooltip);
                        
                        var pos = $this.offset();
                        tooltip.css({
                            top: pos.top - tooltip.outerHeight() - 5,
                            left: pos.left + ($this.outerWidth() / 2) - (tooltip.outerWidth() / 2)
                        }).fadeIn(200);
                    },
                    function() {
                        $('.sincronizador-tooltip').fadeOut(200, function() {
                            $(this).remove();
                        });
                    }
                );
            });
        },
        
        testConnection: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var lojistaId = $button.data('lojista-id');
            var originalText = $button.text();
            
            $button.text(sincronizador_wc_ajax.strings.processing).prop('disabled', true);
            
            $.ajax({
                url: sincronizador_wc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sincronizador_wc_test_connection',
                    lojista_id: lojistaId,
                    nonce: sincronizador_wc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'success');
                    } else {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    SincronizadorWCAdmin.showNotice(sincronizador_wc_ajax.strings.error, 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        deleteLojista: function(e) {
            e.preventDefault();
            
            if (!confirm(sincronizador_wc_ajax.strings.confirm_delete)) {
                return;
            }
            
            var $button = $(this);
            var lojistaId = $button.data('lojista-id');
            
            $.ajax({
                url: sincronizador_wc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sincronizador_wc_delete_lojista',
                    lojista_id: lojistaId,
                    nonce: sincronizador_wc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $button.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                        SincronizadorWCAdmin.showNotice(response.data.message, 'success');
                    } else {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    SincronizadorWCAdmin.showNotice(sincronizador_wc_ajax.strings.error, 'error');
                }
            });
        },
        
        forceSync: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var lojistaId = $button.data('lojista-id') || null;
            var originalText = $button.text();
            
            $button.text('Sincronizando...').prop('disabled', true);
            
            $.ajax({
                url: sincronizador_wc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sincronizador_wc_force_sync',
                    lojista_id: lojistaId,
                    nonce: sincronizador_wc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'success');
                        // Recarregar stats se estiver no dashboard
                        if ($('.dashboard-stats').length > 0) {
                            SincronizadorWCAdmin.refreshStats();
                        }
                    } else {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    SincronizadorWCAdmin.showNotice(sincronizador_wc_ajax.strings.error, 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        },
        
        toggleLojistaStatus: function() {
            var $toggle = $(this);
            var lojistaId = $toggle.data('lojista-id');
            var newStatus = $toggle.is(':checked') ? 'ativo' : 'inativo';
            
            $.ajax({
                url: sincronizador_wc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sincronizador_wc_toggle_lojista_status',
                    lojista_id: lojistaId,
                    status: newStatus,
                    nonce: sincronizador_wc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        SincronizadorWCAdmin.showNotice(response.data.message, 'success');
                    } else {
                        // Reverter toggle em caso de erro
                        $toggle.prop('checked', !$toggle.is(':checked'));
                        SincronizadorWCAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    // Reverter toggle em caso de erro
                    $toggle.prop('checked', !$toggle.is(':checked'));
                    SincronizadorWCAdmin.showNotice(sincronizador_wc_ajax.strings.error, 'error');
                }
            });
        },
        
        validateLojistaForm: function(e) {
            var $form = $(this);
            var isValid = true;
            var errors = [];
            
            // Validar campos obrigatórios
            $form.find('[required]').each(function() {
                var $field = $(this);
                if (!$field.val().trim()) {
                    isValid = false;
                    $field.addClass('invalid');
                    errors.push('Campo "' + $field.prev('label').text() + '" é obrigatório');
                } else {
                    $field.removeClass('invalid');
                }
            });
            
            // Validar URL
            var urlField = $form.find('input[name="url_loja"]');
            if (urlField.length && urlField.val()) {
                var urlPattern = /^https?:\/\/.+/i;
                if (!urlPattern.test(urlField.val())) {
                    isValid = false;
                    urlField.addClass('invalid');
                    errors.push('URL da loja deve começar com http:// ou https://');
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                SincronizadorWCAdmin.showNotice(errors.join('<br>'), 'error');
                return false;
            }
            
            // Mostrar loading
            $form.find('[type="submit"]').text(sincronizador_wc_ajax.strings.processing).prop('disabled', true);
        },
        
        openModal: function(e) {
            e.preventDefault();
            var targetModal = $(this).data('modal');
            $('#' + targetModal).fadeIn(200);
        },
        
        closeModal: function(e) {
            if (e.target === this || $(e.target).hasClass('modal-close')) {
                $('.modal').fadeOut(200);
            }
        },
        
        showNotice: function(message, type) {
            var noticeClass = 'notice-' + type;
            var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dispensar este aviso.</span></button></div>');
            
            $('.wrap h1').after(notice);
            
            // Auto-hide depois de 5 segundos
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Handler para botão de fechar
            notice.find('.notice-dismiss').click(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },
        
        refreshStats: function() {
            $.ajax({
                url: sincronizador_wc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'sincronizador_wc_get_stats',
                    nonce: sincronizador_wc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var stats = response.data;
                        
                        // Atualizar valores nas cards
                        $('.stat-lojistas-ativos h3').text(stats.lojistas_ativos);
                        $('.stat-produtos-sincronizados h3').text(stats.produtos_sincronizados);
                        $('.stat-vendas-mes-atual h3').text(stats.vendas_mes_atual);
                        $('.stat-valor-vendas-mes-atual h3').text('R$ ' + Number(stats.valor_vendas_mes_atual).toLocaleString('pt-BR', {minimumFractionDigits: 2}));
                    }
                }
            });
        },
        
        // Utility functions
        formatCurrency: function(value) {
            return 'R$ ' + Number(value).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },
        
        formatDate: function(dateString) {
            var date = new Date(dateString);
            return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR');
        }
    };
    
    // Inicializar quando o documento estiver pronto
    $(document).ready(function() {
        SincronizadorWCAdmin.init();
    });
    
    // Exportar para uso global
    window.SincronizadorWCAdmin = SincronizadorWCAdmin;
    
})(jQuery);
