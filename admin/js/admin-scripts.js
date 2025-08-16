/**
 * JavaScript do Admin - Sincronizador WooCommerce
 * @version 2.0.0 - Refatorado e Simplificado
 */

(function($) {
    'use strict';

    // Controle de inicialização
    if (window.SincronizadorWCInitialized) {
        return;
    }
    window.SincronizadorWCInitialized = true;

    // Namespace principal
    if (typeof window.SincronizadorWC === 'undefined') {
        window.SincronizadorWC = {};
    }
    
    // Configurações globais
    const WC = window.SincronizadorWC;
    WC.ajaxurl = WC.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    WC.nonce = WC.nonce || '';
    WC.currentImportId = null;
    WC.produtosSincronizados = [];
    WC.syncInProgress = false;

    // Inicialização principal
    $(document).ready(function() {
        initSincronizador();
        setupGlobalEvents();
    });

    /**
     * Inicialização principal do sistema
     */
    function initSincronizador() {
        const currentPage = detectCurrentPage();
        
        console.log('🚀 Inicializando Sincronizador - Página:', currentPage);
        
        // Verificar nonce
        if (!WC.nonce) {
            console.warn('⚠️ NONCE não encontrado! Tentando localizar...');
            const metaNonce = $('meta[name="sincronizador-wc-nonce"]').attr('content');
            if (metaNonce) {
                WC.nonce = metaNonce;
                console.log('✅ NONCE encontrado via meta tag');
            }
        }
        
        // Inicializar página específica
        switch(currentPage) {
            case 'importar':
                initImportPage();
                break;
            case 'sincronizados':
                initSyncPage();
                break;
            case 'lojistas':
                initLojistasPage();
                break;
            case 'add-lojista':
                initAddLojistaPage();
                break;
            default:
                console.log('📋 Página padrão ou dashboard');
        }
    }

    /**
     * Detectar página atual
     */
    function detectCurrentPage() {
        const url = window.location.href;
        
        if (url.includes('sincronizador-wc-importar')) return 'importar';
        if (url.includes('sincronizador-wc-sincronizados')) return 'sincronizados';
        if (url.includes('sincronizador-wc-add-lojista')) return 'add-lojista';
        if (url.includes('sincronizador-wc-lojistas')) return 'lojistas';
        
        return 'dashboard';
    }

    /**
     * PÁGINA DE LOJISTAS
     */
    function initLojistasPage() {
        console.log('📋 Inicializando página de lojistas');
        
        // Preparar botões de sincronização
        $('form input[name="action"][value="sync_produtos"]').each(function() {
            const form = $(this).closest('form');
            const lojistaId = form.find('input[name="lojista_id"]').val();
            const submitBtn = form.find('button[type="submit"]');
            
            if (lojistaId && submitBtn.length) {
                submitBtn.addClass('btn-sincronizar').attr('data-lojista', lojistaId);
            }
        });
    }

    /**
     * PÁGINA DE IMPORTAÇÃO
     */
    function initImportPage() {
        console.log('📥 Inicializando página de importação');
        
        // Configurar valores padrão das checkboxes
        setTimeout(() => {
            $("#incluir_variacoes, #incluir_imagens, #manter_precos").prop("checked", true);
        }, 100);
    }

    /**
     * PÁGINA DE PRODUTOS SINCRONIZADOS
     */
    function initSyncPage() {
        console.log('📊 Inicializando página de produtos sincronizados');
        
        // Busca em tempo real
        $(document).on('input', '#buscar-sincronizado', function() {
            const termo = this.value.toLowerCase();
            if (WC.produtosSincronizados) {
                filterProdutosSincronizados(termo);
            }
        });
    }

    /**
     * PÁGINA DE ADICIONAR LOJISTA
     */
    function initAddLojistaPage() {
        console.log('➕ Inicializando página de adicionar lojista');
    }

    /**
     * EVENTOS GLOBAIS
     */
    function setupGlobalEvents() {
        // Sincronização de produtos
        $(document).on('click', '.btn-sincronizar', handleSincronizacao);
        
        // Teste de conexão
        $(document).on('click', '.btn-test-connection', handleTesteConexao);
        
        // Validar lojista
        $(document).on('click', '#btn-validar-lojista', handleValidarLojista);
        
        // Carregar produtos
        $(document).on('click', '#btn-carregar-produtos', handleCarregarProdutos);
        
        // Iniciar importação
        $(document).on('click', '#btn-iniciar-importacao', handleIniciarImportacao);
        
        // Carregar produtos sincronizados
        $(document).on('click', '#btn-carregar-sincronizados', handleCarregarSincronizados);
        
        // Mudança de lojista
        $(document).on('change', '#lojista_destino', handleMudancaLojista);
        
        // Formulários de sincronização
        $(document).on('submit', 'form', handleSubmitForm);
    }

    /**
     * HANDLERS DE EVENTOS
     */
    function handleSincronizacao(e) {
        e.preventDefault();
        const lojistaId = $(this).data('lojista') || $(this).closest('form').find('input[name="lojista_id"]').val();
        const lojistaName = $(this).closest('tr').find('td').first().text().trim() || 'Lojista';
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('ID do lojista não encontrado!');
            return;
        }
        
        executarSincronizacao(lojistaId, lojistaName);
    }

    function handleTesteConexao(e) {
        e.preventDefault();
        const lojistaId = $(this).data('lojista-id') || $('#lojista_destino').val();
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            return;
        }
        
        testarConexao(lojistaId, $(this));
    }

    function handleValidarLojista(e) {
        e.preventDefault();
        const lojistaId = $("#lojista_destino").val();
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            return;
        }
        
        validarLojista(lojistaId);
    }

    function handleCarregarProdutos(e) {
        e.preventDefault();
        carregarProdutos();
    }

    function handleIniciarImportacao(e) {
        e.preventDefault();
        iniciarImportacao();
    }

    function handleCarregarSincronizados(e) {
        e.preventDefault();
        const lojistaId = $("#lojista_destino").val();
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            return;
        }
        
        carregarProdutosSincronizados(lojistaId);
    }

    function handleMudancaLojista(e) {
        const lojistaId = this.value;
        console.log('🔄 Lojista alterado:', lojistaId);
        
        // Limpar dados anteriores
        $("#produtos-section, #opcoes-importacao, #botoes-acao").hide();
        $("#btn-carregar-produtos").prop("disabled", !lojistaId);
    }

    function handleSubmitForm(e) {
        const form = $(this);
        const action = form.find('input[name="action"]').val();
        
        if (action === 'sync_produtos') {
            e.preventDefault();
            const lojistaId = form.find('input[name="lojista_id"]').val();
            const lojistaName = form.closest('tr').find('td').first().text().trim();
            executarSincronizacao(lojistaId, lojistaName);
        }
    }

    /**
     * FUNÇÕES PRINCIPAIS
     */
    function executarSincronizacao(lojistaId, lojistaName) {
        if (WC.syncInProgress) {
            SincronizadorModals.mostrarErro('Já há uma sincronização em andamento!');
            return;
        }
        
        WC.syncInProgress = true;
        
        // Mostrar modal de progresso usando o novo sistema
        SincronizadorModals.mostrarModalProgresso(lojistaName);
        
        // Atualizar progresso inicial
        SincronizadorModals.atualizarProgresso(5, `Conectando com ${lojistaName}...`);
        
        // Fazer a sincronização
        $.post(WC.ajaxurl, {
            action: 'sync_produtos',
            lojista_id: lojistaId,
            nonce: WC.nonce
        })
        .done(function(response) {
            // Completar progresso
            SincronizadorModals.atualizarProgresso(100, 'Sincronização concluída!');
            
            setTimeout(() => {
                SincronizadorModals.fecharModalProgresso();
                
                if (response.success) {
                    // Atualizar "Última Sync" na tabela
                    atualizarUltimaSync(lojistaId);
                    
                    // Mostrar relatório usando o novo sistema
                    SincronizadorModals.mostrarRelatorioSync(response.data, lojistaName);
                } else {
                    SincronizadorModals.mostrarErro('Erro na sincronização: ' + (response.data || 'Erro desconhecido'));
                }
                
                WC.syncInProgress = false;
            }, 1000);
        })
        .fail(function(xhr, status, error) {
            SincronizadorModals.fecharModalProgresso();
            
            let errorMsg = 'Erro na sincronização.';
            if (xhr.responseText) {
                try {
                    const errorData = JSON.parse(xhr.responseText);
                    errorMsg += ' Detalhes: ' + (errorData.data || errorData.message || xhr.responseText);
                } catch(e) {
                    errorMsg += ' Resposta do servidor: ' + xhr.responseText.substring(0, 200);
                }
            }
            
            SincronizadorModals.mostrarErro(errorMsg);
            WC.syncInProgress = false;
        });
    }

    function testarConexao(lojistaId, buttonElement) {
        SincronizadorModals.setButtonLoading(buttonElement, true);
        
        $.post(WC.ajaxurl, {
            action: 'sincronizador_wc_validate_lojista',
            nonce: WC.nonce,
            lojista_id: lojistaId
        })
        .done(function(response) {
            if (response.success) {
                SincronizadorModals.showToast('✅ Conexão estabelecida com sucesso!', 'success');
                buttonElement.text('✅ Conectado');
            } else {
                SincronizadorModals.showToast('❌ Falha na conexão: ' + (response.data || 'Erro desconhecido'), 'error');
                buttonElement.text('❌ Erro');
            }
        })
        .fail(function() {
            SincronizadorModals.showToast('❌ Erro de comunicação com o servidor', 'error');
            buttonElement.text('❌ Erro');
        })
        .always(function() {
            SincronizadorModals.setButtonLoading(buttonElement, false, '🔗 Testar Conexão');
        });
    }

    function validarLojista(lojistaId) {
        const btn = $("#btn-validar-lojista");
        SincronizadorModals.setButtonLoading(btn, true);
        
        $.post(WC.ajaxurl, {
            action: "sincronizador_wc_validate_lojista",
            nonce: WC.nonce,
            lojista_id: lojistaId
        })
        .done(function(response) {
            const statusDiv = $("#validacao-status");
            
            if (response.success) {
                statusDiv.html('<div class="notice notice-success"><p>✅ Lojista validado com sucesso!</p></div>').show();
                $("#btn-carregar-produtos").prop("disabled", false);
                SincronizadorModals.showToast('✅ Lojista validado!', 'success');
            } else {
                statusDiv.html('<div class="notice notice-error"><p>❌ ' + (response.data || 'Erro na validação') + '</p></div>').show();
                SincronizadorModals.showToast('❌ Erro na validação: ' + response.data, 'error');
            }
        })
        .fail(function() {
            SincronizadorModals.mostrarErro('Erro de comunicação com o servidor');
        })
        .always(function() {
            SincronizadorModals.setButtonLoading(btn, false, '✅ Validar Lojista');
        });
    }

    function carregarProdutos() {
        const btn = $("#btn-carregar-produtos");
        SincronizadorModals.setButtonLoading(btn, true);
        
        $.post(WC.ajaxurl, {
            action: "sincronizador_wc_get_produtos_fabrica",
            nonce: WC.nonce
        })
        .done(function(response) {
            if (response.success) {
                renderProdutos(response.data);
                showImportSections();
                SincronizadorModals.showToast('✅ Produtos carregados com sucesso!', 'success');
            } else {
                SincronizadorModals.mostrarErro('Erro ao carregar produtos: ' + (response.data || 'Erro desconhecido'));
            }
        })
        .fail(function() {
            SincronizadorModals.mostrarErro('Erro de comunicação com o servidor');
        })
        .always(function() {
            SincronizadorModals.setButtonLoading(btn, false, '📋 Carregar Produtos');
        });
    }

    function iniciarImportacao() {
        const produtosSelecionados = [];
        $("input[name='produtos_selecionados[]']:checked").each(function() {
            produtosSelecionados.push(this.value);
        });
        
        if (produtosSelecionados.length === 0) {
            SincronizadorModals.mostrarErro('Selecione pelo menos um produto!');
            return;
        }

        const lojistaId = $("#lojista_destino").val();
        const lojistaName = $("#lojista_destino option:selected").text() || 'Lojista';
        
        // Mostrar modal de progresso
        SincronizadorModals.mostrarModalProgresso(lojistaName);
        
        const dados = {
            action: "sincronizador_wc_import_produtos",
            nonce: WC.nonce,
            lojista_destino: lojistaId,
            produtos_selecionados: produtosSelecionados,
            incluir_variacoes: $("#incluir_variacoes").is(":checked") ? 1 : 0,
            incluir_imagens: $("#incluir_imagens").is(":checked") ? 1 : 0,
            manter_precos: $("#manter_precos").is(":checked") ? 1 : 0,
            percentual_acrescimo: parseFloat($("#percentual_acrescimo").val()) || 0
        };
        
        // Simular progresso
        let progress = 0;
        const progressInterval = setInterval(() => {
            progress = Math.min(progress + Math.random() * 8, 90);
            SincronizadorModals.atualizarProgresso(progress, 'Importando produtos...');
        }, 300);
        
        $.post(WC.ajaxurl, dados)
        .done(function(response) {
            clearInterval(progressInterval);
            SincronizadorModals.atualizarProgresso(100, 'Importação concluída!');
            
            setTimeout(() => {
                SincronizadorModals.fecharModalProgresso();
                
                if (response.success) {
                    SincronizadorModals.mostrarSucesso('Importação realizada com sucesso!');
                    showImportResults(response.data);
                } else {
                    SincronizadorModals.mostrarErro('Erro na importação: ' + (response.data || 'Erro desconhecido'));
                }
            }, 1000);
        })
        .fail(function() {
            clearInterval(progressInterval);
            SincronizadorModals.fecharModalProgresso();
            SincronizadorModals.mostrarErro('Erro de comunicação com o servidor');
        });
    }

    function carregarProdutosSincronizados(lojistaId) {
        const btn = $("#btn-carregar-sincronizados");
        SincronizadorModals.setButtonLoading(btn, true);
        
        $.post(WC.ajaxurl, {
            action: "sincronizador_wc_get_produtos_sincronizados",
            nonce: WC.nonce,
            lojista_id: lojistaId,
            cache_bust: new Date().getTime(),
            force_refresh: true
        })
        .done(function(response) {
            if (response.success) {
                WC.produtosSincronizados = response.data;
                renderProdutosSincronizados(response.data);
                SincronizadorModals.showToast('✅ Produtos sincronizados carregados!', 'success');
            } else {
                SincronizadorModals.mostrarErro('Erro ao carregar produtos: ' + (response.data || 'Erro desconhecido'));
            }
        })
        .fail(function() {
            SincronizadorModals.mostrarErro('Erro de comunicação com o servidor');
        })
        .always(function() {
            SincronizadorModals.setButtonLoading(btn, false, '📊 Carregar Sincronizados');
        });
    }

    /**
     * FUNÇÕES AUXILIARES
     */
    function atualizarUltimaSync(lojistaId) {
        const agora = new Date().toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const botaoSync = $(`.btn-sincronizar[data-lojista="${lojistaId}"]`);
        const linha = botaoSync.closest('tr');
        const colunaUltimaSync = linha.find('td').eq(3);
        
        if (colunaUltimaSync.length) {
            colunaUltimaSync.html(`<span style="color: #46b450; font-weight: bold;">${agora}</span>`);
        }
    }

    function formatPrice(price) {
        if (!price || price === '' || price === null || price === undefined) {
            return '0,00';
        }
        
        const priceStr = String(price).replace(/[^\d.,]/g, '').replace(',', '.');
        const priceNum = parseFloat(priceStr);
        
        if (isNaN(priceNum)) {
            return '0,00';
        }
        
        return priceNum.toFixed(2).replace('.', ',');
    }

    function showNotice(message, type = 'success') {
        SincronizadorModals.showToast(message, type);
    }

    // Funções que serão implementadas conforme necessário
    function renderProdutos(produtos) {
        console.log('📦 Renderizando produtos:', produtos.length);
        // TODO: Implementar renderização de produtos
    }

    function showImportSections() {
        $("#produtos-section, #opcoes-importacao, #botoes-acao").show().addClass("fade-in");
        $("#btn-carregar-produtos").text("✅ Produtos Carregados");
    }

    function showImportResults(data) {
        console.log('📊 Resultados da importação:', data);
        // TODO: Implementar exibição de resultados
    }

    function renderProdutosSincronizados(produtos) {
        console.log('📋 Renderizando produtos sincronizados:', produtos.length);
        // TODO: Implementar renderização de produtos sincronizados
    }

    function filterProdutosSincronizados(termo) {
        console.log('🔍 Filtrando produtos:', termo);
        // TODO: Implementar filtro de produtos
    }

    // Expor funções principais globalmente
    WC.showNotice = showNotice;
    WC.formatPrice = formatPrice;
    WC.executarSincronizacao = executarSincronizacao;

})(jQuery);
