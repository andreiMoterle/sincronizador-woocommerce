/**
 * Sistema de Modais - Sincronizador WooCommerce
 * @version 1.0.0
 */

(function($) {
    'use strict';

    // Namespace para os modais
    if (typeof window.SincronizadorModals === 'undefined') {
        window.SincronizadorModals = {};
    }

    const Modals = window.SincronizadorModals;

    /**
     * Configurações padrão dos modais
     */
    const defaultConfig = {
        closeOnOverlay: true,
        closeOnEscape: true,
        showCloseButton: true,
        animation: 'slideIn',
        autoClose: false,
        autoCloseDelay: 5000
    };

    /**
     * Limpar todos os modais órfãos do DOM
     */
    Modals.limparModaisOrfaos = function() {
        $('.modal-overlay, #modal-progresso, #modal-relatorio, #modal-erro').remove();
    };

    /**
     * Criar estrutura base do modal
     */
    function criarModalBase(id, config = {}) {
        const settings = Object.assign({}, defaultConfig, config);
        
        return `
            <div id="${id}" class="modal-overlay" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header ${settings.headerClass || ''}">
                        ${settings.showCloseButton ? '<button class="modal-close" data-modal="close">&times;</button>' : ''}
                        <h3>${settings.title || ''}</h3>
                        ${settings.subtitle ? `<p>${settings.subtitle}</p>` : ''}
                    </div>
                    <div class="modal-body">
                        ${settings.content || ''}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Mostrar modal com animação
     */
    function mostrarModal(modalId, config = {}) {
        const $modal = $('#' + modalId);
        
        if ($modal.length === 0) return false;

        $modal.addClass('show').fadeIn(300);
        
        if (config.animation) {
            $modal.find('.modal-content').addClass('modal-' + config.animation);
        }

        // Auto-close se configurado
        if (config.autoClose && config.autoCloseDelay) {
            setTimeout(() => {
                fecharModal(modalId);
            }, config.autoCloseDelay);
        }

        return true;
    }

    /**
     * Fechar modal com animação
     */
    function fecharModal(modalId) {
        const $modal = $('#' + modalId);
        
        if ($modal.length === 0) return false;

        $modal.removeClass('show').fadeOut(300, function() {
            $(this).remove();
        });

        return true;
    }

    /**
     * Modal de Progresso
     */
    Modals.mostrarModalProgresso = function(lojistaName = 'Lojista', config = {}) {
        Modals.limparModaisOrfaos();

        const modalConfig = Object.assign({
            title: '🔄 Sincronizando Produtos',
            subtitle: lojistaName,
            headerClass: 'modal-progresso',
            closeOnOverlay: false,
            closeOnEscape: false,
            showCloseButton: false,
            content: `
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill"></div>
                    </div>
                    <div class="progress-info">
                        <div class="progress-text" id="progress-text">Iniciando sincronização...</div>
                        <div class="progress-percentage" id="progress-percentage">0%</div>
                    </div>
                </div>
                <div class="sync-status">
                    <div class="spinner"></div>
                    <p>Processando produtos da fábrica...<br>
                    <small>Aguarde, esta operação pode levar alguns minutos.</small></p>
                </div>
            `
        }, config);

        const modal = criarModalBase('modal-progresso', modalConfig);
        $('body').append(modal);
        
        return mostrarModal('modal-progresso', modalConfig);
    };

    /**
     * Atualizar progresso
     */
    Modals.atualizarProgresso = function(porcentagem, texto, detalhes) {
        $('#progress-fill').css('width', porcentagem + '%');
        $('#progress-text').text(texto);
        $('#progress-percentage').text(Math.floor(porcentagem) + '%');
        
        if (detalhes) {
            $('.sync-status p').html(detalhes);
        }
    };

    /**
     * Fechar modal de progresso
     */
    Modals.fecharModalProgresso = function() {
        return fecharModal('modal-progresso');
    };

    /**
     * Modal de Relatório de Sincronização
     */
    Modals.mostrarRelatorioSync = function(dados, lojistaName, config = {}) {
        Modals.limparModaisOrfaos();

        const total = dados.produtos_sincronizados || 0;
        const criados = dados.produtos_criados || 0;
        const atualizados = dados.produtos_atualizados || 0;
        const erros = dados.erros || 0;
        const tempo = dados.tempo || 'N/A';

        const resumoHTML = `
            <div class="relatorio-resumo">
                <div class="resumo-item success">
                    <div class="resumo-numero">${total}</div>
                    <div class="resumo-label">Sincronizados</div>
                </div>
                <div class="resumo-item info">
                    <div class="resumo-numero">${criados}</div>
                    <div class="resumo-label">Criados</div>
                </div>
                <div class="resumo-item warning">
                    <div class="resumo-numero">${atualizados}</div>
                    <div class="resumo-label">Atualizados</div>
                </div>
                <div class="resumo-item error">
                    <div class="resumo-numero">${erros}</div>
                    <div class="resumo-label">Erros</div>
                </div>
            </div>
            
            <div class="tempo-execucao">
                <span>⏱️ Tempo de execução: </span>
                <span>${tempo}</span>
            </div>
            
            ${dados.detalhes ? `
            <div class="relatorio-detalhes">
                <h4>📋 Detalhes:</h4>
                <div>${dados.detalhes}</div>
            </div>
            ` : ''}
            
            <div style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef;">
                <button class="modal-btn primary" data-modal="close">
                    ✅ Fechar Relatório
                </button>
            </div>
        `;

        const modalConfig = Object.assign({
            title: '✅ Sincronização Concluída',
            subtitle: lojistaName,
            headerClass: 'modal-relatorio',
            content: resumoHTML,
            animation: 'slideIn'
        }, config);

        const modal = criarModalBase('modal-relatorio', modalConfig);
        $('body').append(modal);
        
        return mostrarModal('modal-relatorio', modalConfig);
    };

    /**
     * Modal de Erro
     */
    Modals.mostrarErro = function(mensagem, titulo = '❌ Erro na Sincronização', config = {}) {
        Modals.limparModaisOrfaos();

        const modalConfig = Object.assign({
            title: titulo,
            headerClass: 'modal-erro',
            content: `
                <div class="erro-message">
                    ${mensagem}
                </div>
                <div style="text-align: center;">
                    <button class="modal-btn danger" data-modal="close">
                        Fechar
                    </button>
                </div>
            `,
            animation: 'fadeIn'
        }, config);

        const modal = criarModalBase('modal-erro', modalConfig);
        $('body').append(modal);
        
        return mostrarModal('modal-erro', modalConfig);
    };

    /**
     * Modal de Sucesso
     */
    Modals.mostrarSucesso = function(mensagem, titulo = '✅ Sucesso', config = {}) {
        const modalConfig = Object.assign({
            title: titulo,
            headerClass: 'modal-sucesso',
            content: `
                <div style="text-align: center; padding: 20px;">
                    <div style="font-size: 48px; margin-bottom: 20px;">✅</div>
                    <p style="font-size: 16px; margin-bottom: 30px;">${mensagem}</p>
                    <button class="modal-btn success" data-modal="close">
                        OK
                    </button>
                </div>
            `,
            animation: 'fadeIn',
            autoClose: true,
            autoCloseDelay: 3000
        }, config);

        const modal = criarModalBase('modal-sucesso', modalConfig);
        $('body').append(modal);
        
        return mostrarModal('modal-sucesso', modalConfig);
    };

    /**
     * Modal de Confirmação
     */
    Modals.mostrarConfirmacao = function(mensagem, callback, config = {}) {
        const modalId = 'modal-confirmacao-' + Date.now();
        
        const modalConfig = Object.assign({
            title: '❓ Confirmação',
            content: `
                <div style="text-align: center; padding: 20px;">
                    <p style="font-size: 16px; margin-bottom: 30px;">${mensagem}</p>
                    <div style="display: flex; gap: 15px; justify-content: center;">
                        <button class="modal-btn danger" data-action="cancel">
                            ❌ Cancelar
                        </button>
                        <button class="modal-btn success" data-action="confirm">
                            ✅ Confirmar
                        </button>
                    </div>
                </div>
            `,
            closeOnOverlay: false,
            closeOnEscape: true,
            animation: 'slideIn'
        }, config);

        const modal = criarModalBase(modalId, modalConfig);
        $('body').append(modal);
        
        // Event listeners específicos para confirmação
        $('#' + modalId).on('click', '[data-action="confirm"]', function() {
            fecharModal(modalId);
            if (typeof callback === 'function') {
                callback(true);
            }
        });

        $('#' + modalId).on('click', '[data-action="cancel"]', function() {
            fecharModal(modalId);
            if (typeof callback === 'function') {
                callback(false);
            }
        });
        
        return mostrarModal(modalId, modalConfig);
    };

    /**
     * Event Listeners Globais para Modais
     */
    $(document).ready(function() {
        // Fechar modal com botão close
        $(document).on('click', '[data-modal="close"]', function(e) {
            e.preventDefault();
            const $modal = $(this).closest('.modal-overlay');
            if ($modal.length) {
                const modalId = $modal.attr('id');
                fecharModal(modalId);
            }
        });

        // Fechar modal clicando no overlay
        $(document).on('click', '.modal-overlay', function(e) {
            if (e.target === this) {
                const modalId = $(this).attr('id');
                const closeOnOverlay = !$(this).hasClass('no-overlay-close');
                
                if (closeOnOverlay) {
                    fecharModal(modalId);
                }
            }
        });

        // Fechar modal com ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                const $visibleModals = $('.modal-overlay:visible');
                if ($visibleModals.length > 0) {
                    const $lastModal = $visibleModals.last();
                    const modalId = $lastModal.attr('id');
                    const closeOnEscape = !$lastModal.hasClass('no-escape-close');
                    
                    if (closeOnEscape) {
                        fecharModal(modalId);
                    }
                }
            }
        });
    });

    /**
     * Utility: Loading state para botões
     */
    Modals.setButtonLoading = function(selector, loading = true, originalText = null) {
        const $btn = $(selector);
        
        if (loading) {
            const text = originalText || $btn.text();
            $btn.data('original-text', text)
                .prop('disabled', true)
                .addClass('loading')
                .text('🔄 Carregando...');
        } else {
            const text = originalText || $btn.data('original-text') || 'Botão';
            $btn.prop('disabled', false)
                .removeClass('loading')
                .text(text);
        }
    };

    /**
     * Utility: Toast notifications (pequenas notificações)
     */
    Modals.showToast = function(mensagem, tipo = 'info', duracao = 3000) {
        const toastId = 'toast-' + Date.now();
        const iconMap = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        
        const toast = `
            <div id="${toastId}" class="toast toast-${tipo}" style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 15px 20px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1000000;
                min-width: 300px;
                display: flex;
                align-items: center;
                gap: 10px;
                transform: translateX(100%);
                transition: transform 0.3s ease;
            ">
                <span style="font-size: 20px;">${iconMap[tipo] || 'ℹ️'}</span>
                <span style="flex: 1;">${mensagem}</span>
                <button onclick="$('#${toastId}').fadeOut(200, function(){$(this).remove();})" 
                        style="background: none; border: none; color: #999; cursor: pointer; font-size: 18px;">×</button>
            </div>
        `;
        
        $('body').append(toast);
        
        // Animar entrada
        setTimeout(() => {
            $('#' + toastId).css('transform', 'translateX(0)');
        }, 100);
        
        // Auto-remover
        setTimeout(() => {
            $('#' + toastId).fadeOut(300, function() {
                $(this).remove();
            });
        }, duracao);
    };

    // Expor métodos principais globalmente
    window.SincronizadorModals = Modals;

})(jQuery);
