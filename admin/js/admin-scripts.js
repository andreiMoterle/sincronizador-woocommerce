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
    
    // Fallback para nonce se não estiver disponível
    if (!WC.nonce) {
        const nonceMeta = document.querySelector('meta[name="sincronizador-wc-nonce"]');
        if (nonceMeta) {
            WC.nonce = nonceMeta.getAttribute('content');
        }
    }
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
        
        // Verificar nonce
        if (!WC.nonce) {
            const metaNonce = $('meta[name="sincronizador-wc-nonce"]').attr('content');
            if (metaNonce) {
                WC.nonce = metaNonce;
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
        // Configurar valores padrão das checkboxes
        setTimeout(() => {
            $("#incluir_variacoes, #incluir_imagens, #manter_precos").prop("checked", true);
        }, 100);

        // Busca com debounce
        let searchTimeout;
        $(document).on('input', '#buscar-produto', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                carregarProdutos(1);
            }, 300);
        });

        // Filtro de categoria
        $(document).on('change', '#filtro-categoria', function() {
            carregarProdutos(1);
        });

        // Paginação
        $(document).on('click', '.paginacao-produtos a', function(e) {
            e.preventDefault();
            const pagina = $(this).data('pagina');
            if (pagina) carregarProdutos(pagina);
        });

        // Tamanho da página
        $(document).on('change', '#tamanho-pagina', function() {
            carregarProdutos(1);
        });
    }

    /**
     * PÁGINA DE PRODUTOS SINCRONIZADOS
     */
    function initSyncPage() {
        // 🚀 OTIMIZAÇÃO: Busca em tempo real com debounce para melhor performance
        let searchTimeout;
        $(document).on('input', '#buscar-sincronizado', function() {
            const termo = this.value.toLowerCase();
            
            // Limpar timeout anterior
            clearTimeout(searchTimeout);
            
            // Aguardar 300ms antes de filtrar (debounce)
            searchTimeout = setTimeout(() => {
                if (WC.produtosSincronizados) {
                    filterProdutosSincronizados(termo);
                }
            }, 300);
        });
        
        // 🚀 NOVA FUNCIONALIDADE: Verificar se há um lojista selecionado e carregar automaticamente
        setTimeout(() => {
            const lojistaId = $("#lojista_destino").val();
            if (lojistaId) {
                carregarProdutosSincronizados(lojistaId);
            }
        }, 500); // Pequeno delay para garantir que a página carregou completamente
    }

    /**
     * PÁGINA DE ADICIONAR LOJISTA
     */
    function initAddLojistaPage() {
        }

    /**
     * EVENTOS GLOBAIS
     */
    function setupGlobalEvents() {
        // Verificar se já foram configurados para evitar duplicação
        if (window.sincronizadorEventsSetup) {
            return;
        }
        
        // Sincronização de produtos
        $(document).on('click', '.btn-sincronizar', handleSincronizacao);
        
        // Teste de conexão
        $(document).on('click', '.btn-test-connection', handleTesteConexao);
        
    // Atualizar preços (AJAX + background)
    $(document).on('click', '.btn-atualizar-precos', handleAtualizarPrecos);
        
        // Validar lojista
        $(document).on('click', '#btn-validar-lojista', handleValidarLojista);
        
        // Carregar produtos
        $(document).on('click', '#btn-carregar-produtos', handleCarregarProdutos);
        
        // Iniciar importação
        $(document).on('click', '#btn-iniciar-importacao', handleIniciarImportacao);
        
        // Carregar produtos sincronizados
        $(document).on('click', '#btn-carregar-sincronizados', handleCarregarSincronizados);
        
        // Sincronizar vendas
        $(document).on('click', '#btn-sincronizar-vendas', handleSincronizarVendas);
        
        // Limpar cache
        $(document).on('click', '#btn-limpar-cache', handleLimparCache);
        
        // Mudança de lojista
        $(document).on('change', '#lojista_destino', handleMudancaLojista);
        
        // Formulários de sincronização
        $(document).on('submit', 'form', handleSubmitForm);
        
        // Marcar como configurado
        window.sincronizadorEventsSetup = true;
        
        // Selecionar todos os produtos
        $(document).on('change', '#selecionar-todos', function() {
            const isChecked = $(this).is(':checked');
            $("input[name='produtos_selecionados[]']").prop('checked', isChecked);
            atualizarContadorProdutos();
        });
        
        // Atualizar contador quando produtos individuais são selecionados
        $(document).on('change', "input[name='produtos_selecionados[]']", function() {
            atualizarContadorProdutos();
            
            // Atualizar estado do "selecionar todos"
            const total = $("input[name='produtos_selecionados[]']").length;
            const selecionados = $("input[name='produtos_selecionados[]']:checked").length;
            $('#selecionar-todos').prop('checked', total > 0 && selecionados === total);
        });
        
        // Busca de produtos
        $(document).on('keyup', '#buscar-produto', function() {
            const termo = $(this).val().toLowerCase();
            $('.produto-card').each(function() {
                const nome = $(this).find('.produto-nome').text().toLowerCase();
                const sku = $(this).find('.produto-sku').text().toLowerCase();
                const match = nome.includes(termo) || sku.includes(termo);
                $(this).toggle(match);
            });
        });
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

    /**
     * Inicia atualização de preços via AJAX (cria job e abre modal de progresso)
     */
    function handleAtualizarPrecos(e) {
        e.preventDefault();
        const btn = $(this);
        const form = btn.closest('.form-atualizar-precos');
        const lojistaId = form.find('input[name="lojista_id"]').val();
        const lojistaName = btn.closest('tr').find('td').first().text().trim() || 'Lojista';

        if (!lojistaId) {
            SincronizadorModals.mostrarErro('ID do lojista não encontrado!');
            return;
        }

        // Mostrar modal de progresso e iniciar job
        SincronizadorModals.mostrarModalProgresso(lojistaName);
        SincronizadorModals.atualizarProgresso(2, 'Solicitando início da atualização de preços...');

        // Enviar solicitação para iniciar job
        $.post(WC.ajaxurl, {
            action: 'sincronizador_wc_start_price_update',
            nonce: WC.nonce,
            lojista_id: lojistaId
        }).done(function(response) {
            if (response.success) {
                // Iniciar polling
                pollPriceUpdateStatus(lojistaName);
            } else {
                SincronizadorModals.mostrarErro('Erro ao iniciar atualização: ' + (response.data || 'erro desconhecido'));
            }
        }).fail(function(xhr, status, err) {
            SincronizadorModals.mostrarErro('Erro de comunicação: ' + err);
        });
    }

    function pollPriceUpdateStatus(lojistaName) {
        const pollInterval = 2000;

        const poll = function() {
            $.post(WC.ajaxurl, {
                action: 'sincronizador_wc_price_update_status',
                nonce: WC.nonce
            }).done(function(response) {
                if (!response.success) {
                    SincronizadorModals.mostrarErro('Erro ao obter status: ' + (response.data || 'nenhuma resposta'));
                    return;
                }

                const job = response.data;
                const total = job.products_list ? job.products_list.length : 0;
                const processed = job.processed || 0;
                const percent = total > 0 ? Math.min(100, Math.floor((processed / total) * 100)) : (job.status === 'completed' ? 100 : 0);

                SincronizadorModals.atualizarProgresso(percent, `Processados: ${processed} / ${total}`, `Atualizando preços...`);

                if (job.status === 'completed' || job.status === 'failed') {
                    // Mostrar relatório final e encerrar polling
                    SincronizadorModals.atualizarProgresso(100, job.status === 'completed' ? 'Atualização finalizada' : 'Falha na atualização');
                    setTimeout(function() {
                        SincronizadorModals.fecharModalProgresso();
                        // Mostrar relatório resumido
                        const detalhes = (job.errors_list && job.errors_list.length) ? job.errors_list.join('<br>') : '';
                        const dadosRelatorio = {
                            produtos_sincronizados: job.updated || 0,
                            produtos_criados: 0,
                            produtos_atualizados: job.updated || 0,
                            erros: job.errors || 0,
                            tempo: job.finished_at || 'N/A',
                            detalhes: detalhes
                        };
                        SincronizadorModals.mostrarRelatorioSync(dadosRelatorio, lojistaName);
                    }, 600);
                    return;
                }

                // continuar polling
                setTimeout(poll, pollInterval);
            }).fail(function() {
                SincronizadorModals.mostrarErro('Falha ao consultar status do job');
            });
        };

        // iniciar primeira chamada
        poll();
    }

    function handleTesteConexao(e) {
        e.preventDefault();
        const lojistaId = $(this).data('lojista-id') || $('#lojista_destino').val();
        if (!lojistaId) {
            // Verificar se SincronizadorModals está disponível
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            } else {
                alert('Selecione um lojista primeiro!');
                }
            return;
        }
        
        testarConexao(lojistaId, $(this));
    }

    function handleValidarLojista(e) {
        e.preventDefault();
        const lojistaId = $("#lojista_destino").val();
        if (!lojistaId) {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            } else {
                alert('Selecione um lojista primeiro!');
            }
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

    function handleSincronizarVendas(e) {
        e.preventDefault();
        const lojistaId = $("#lojista_destino").val();
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            return;
        }
        
        // Desabilitar botão durante a operação
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('🔄 Sincronizando...');
        
        // Fazer chamada AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sincronizador_wc_sync_vendas',
                nonce: WC.nonce,
                lojista_id: lojistaId
            },
            success: function(response) {
                if (response.success) {
                    SincronizadorModals.mostrarSucesso(`✅ Vendas sincronizadas com sucesso!`);

                    // Recarregar produtos sincronizados para mostrar vendas atualizadas
                    setTimeout(() => {
                        carregarProdutosSincronizados(lojistaId, true); // Force refresh após sync vendas
                    }, 1000);
                } else {
                    console.error('❌ Erro na sincronização de vendas:', response.data);
                    SincronizadorModals.mostrarErro('❌ Erro ao sincronizar vendas: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro AJAX na sincronização de vendas:', error);
                SincronizadorModals.mostrarErro('❌ Erro de comunicação: ' + error);
            },
            complete: function() {
                // Reabilitar botão
                btn.prop('disabled', false).html(originalText);
            }
        });
    }

    function handleLimparCache(e) {
        e.preventDefault();
        const lojistaId = $("#lojista_destino").val();
        
        if (!lojistaId) {
            SincronizadorModals.mostrarErro('Selecione um lojista primeiro!');
            return;
        }
        
        // Desabilitar botão durante a operação
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('🔄 Limpando...');
        
        // Fazer chamada AJAX para limpar cache
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sincronizador_wc_clear_cache',
                nonce: WC.nonce,
                lojista_id: lojistaId
            },
            success: function(response) {
                if (response.success) {
                    SincronizadorModals.mostrarSucesso('🗑️ Cache limpo! Recarregando produtos...');
                    
                    // Recarregar produtos sincronizados após limpar cache
                    setTimeout(() => {
                        carregarProdutosSincronizados(lojistaId, true); // Force refresh após limpar cache
                    }, 500);
                } else {
                    console.error('❌ Erro ao limpar cache:', response.data);
                    SincronizadorModals.mostrarErro('❌ Erro ao limpar cache: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Erro AJAX ao limpar cache:', error);
                SincronizadorModals.mostrarErro('❌ Erro de comunicação: ' + error);
            },
            complete: function() {
                // Reabilitar botão
                btn.prop('disabled', false).html(originalText);
            }
        });
    }

    function handleMudancaLojista(e) {
        const lojistaId = this.value;
        // Limpar dados anteriores
        $("#produtos-section, #opcoes-importacao, #botoes-acao").hide();
        $("#btn-carregar-produtos").prop("disabled", !lojistaId);
        
        // Habilitar/desabilitar botão de validar lojista
        $("#btn-validar-lojista").prop("disabled", !lojistaId);
        // Habilitar/desabilitar botões relacionados à conexão
        $("#btn-test-connection, #btn-carregar-sincronizados, #btn-sincronizar-vendas, #btn-limpar-cache").prop("disabled", !lojistaId);
        
        // Se há um lojista selecionado, atualizar o data-lojista-id do botão de teste
        if (lojistaId) {
            $("#btn-test-connection").attr("data-lojista-id", lojistaId);
        }
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
        // Verificar se SincronizadorModals está disponível
        if (typeof SincronizadorModals !== 'undefined') {
            SincronizadorModals.setButtonLoading(buttonElement, true);
        } else {
            buttonElement.text('Testando...').prop('disabled', true);
        }
        
        $.post(WC.ajaxurl, {
            action: 'sincronizador_wc_validate_lojista',
            nonce: WC.nonce,
            lojista_id: lojistaId
        })
        .done(function(response) {
            if (response.success) {
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.showToast('✅ Conexão estabelecida com sucesso!', 'success');
                } else {
                    alert('✅ Conexão estabelecida com sucesso!');
                }
                buttonElement.text('✅ Conectado');
            } else {
                const errorMsg = response.data || response.message || 'Erro desconhecido';
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.showToast('❌ Falha na conexão: ' + errorMsg, 'error');
                } else {
                    alert('❌ Falha na conexão: ' + errorMsg);
                }
                buttonElement.text('❌ Erro');
            }
        })
        .fail(function(xhr, status, error) {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.showToast('❌ Erro de comunicação com o servidor', 'error');
            } else {
                alert('❌ Erro de comunicação com o servidor');
            }
            buttonElement.text('❌ Erro');
        })
        .always(function() {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.setButtonLoading(buttonElement, false, '🔗 Testar Conexão');
            } else {
                buttonElement.text('🔗 Testar').prop('disabled', false);
            }
        });
    }

    function validarLojista(lojistaId) {
        const btn = $("#btn-validar-lojista");
        
        // Verificar se SincronizadorModals está disponível
        if (typeof SincronizadorModals !== 'undefined') {
            SincronizadorModals.setButtonLoading(btn, true);
        } else {
            btn.prop('disabled', true).text('⏳ Validando...');
        }
        
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
                
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.showToast('✅ Lojista validado!', 'success');
                } else {
                    alert('✅ Lojista validado com sucesso!');
                }
            } else {
                statusDiv.html('<div class="notice notice-error"><p>❌ ' + (response.data || 'Erro na validação') + '</p></div>').show();
                
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.showToast('❌ Erro na validação: ' + response.data, 'error');
                } else {
                    alert('❌ Erro na validação: ' + response.data);
                }
            }
        })
        .fail(function(xhr, status, error) {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.mostrarErro('Erro de comunicação com o servidor: ' + error);
            } else {
                alert('Erro de comunicação com o servidor: ' + error);
            }
        })
        .always(function() {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.setButtonLoading(btn, false, '🔍 Validar Conexão');
            } else {
                btn.prop('disabled', false).text('🔍 Validar Conexão');
            }
        });
    }

        function carregarProdutos(pagina = 1) {
            const btn = $("#btn-carregar-produtos");
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.setButtonLoading(btn, true);
            } else {
                btn.prop('disabled', true).text('⏳ Carregando...');
            }
            const search = $('#buscar-produto').val() || '';
            const categoria = $('#filtro-categoria').val() || '';
            const tamanho_pagina = $('#tamanho-pagina').val() || 20;
            $.post(WC.ajaxurl, {
                action: "sincronizador_wc_get_produtos_fabrica",
                nonce: WC.nonce,
                search: search,
                categoria: categoria,
                pagina: pagina,
                tamanho_pagina: tamanho_pagina
            })
            .done(function(response) {
                if (response.success) {
                    renderProdutos(response.data);
                    showImportSections();
                    if (typeof SincronizadorModals !== 'undefined') {
                        SincronizadorModals.showToast('✅ Produtos carregados com sucesso!', 'success');
                    }
                } else {
                    if (typeof SincronizadorModals !== 'undefined') {
                        SincronizadorModals.mostrarErro('Erro ao carregar produtos: ' + (response.data || 'Erro desconhecido'));
                    } else {
                        alert('Erro ao carregar produtos: ' + (response.data || 'Erro desconhecido'));
                    }
                }
            })
            .fail(function(xhr, status, error) {
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.mostrarErro('Erro de comunicação com o servidor');
                } else {
                    alert('Erro de comunicação com o servidor');
                }
            })
            .always(function() {
                if (typeof SincronizadorModals !== 'undefined') {
                    SincronizadorModals.setButtonLoading(btn, false, '📋 Carregar Produtos');
                } else {
                    btn.prop('disabled', false).text('📋 Carregar Produtos');
                }
            });
        }

    function iniciarImportacao() {
        const produtosSelecionados = [];
        $("input[name='produtos_selecionados[]']:checked").each(function() {
            produtosSelecionados.push(this.value);
        });
        
        if (produtosSelecionados.length === 0) {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.mostrarErro('Selecione pelo menos um produto!');
            } else {
                alert('Selecione pelo menos um produto!');
            }
            return;
        }

        const lojistaId = $("#lojista_destino").val();
        const lojistaName = $("#lojista_destino option:selected").text() || 'Lojista';
        
        // Mostrar modal de progresso
        if (typeof SincronizadorModals !== 'undefined') {
            SincronizadorModals.mostrarModalProgresso(lojistaName);
        }
        
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
        .fail(function(xhr, status, error) {
            clearInterval(progressInterval);
            SincronizadorModals.fecharModalProgresso();
            SincronizadorModals.mostrarErro('Erro de comunicação com o servidor: ' + error);
        });
    }

    function carregarProdutosSincronizados(lojistaId, forceRefresh = false) {
        const btn = $("#btn-carregar-sincronizados");
        SincronizadorModals.setButtonLoading(btn, true);
        
        // Verificar se já temos dados em cache e não é refresh forçado
        if (!forceRefresh && WC.produtosSincronizados && WC.produtosSincronizados.length > 0 && WC.ultimoLojistaCarregado === lojistaId) {
            renderProdutosSincronizados(WC.produtosSincronizados);
            SincronizadorModals.setButtonLoading(btn, false, '📊 Carregar Sincronizados');
            SincronizadorModals.showToast('📦 Dados carregados do cache', 'info');
            return;
        }
        
        const requestData = {
            action: "sincronizador_wc_get_produtos_sincronizados",
            nonce: WC.nonce,
            lojista_id: lojistaId
        };
        
        // Só forçar refresh se explicitamente solicitado (ex: botão limpar cache)
        if (forceRefresh) {
            requestData.cache_bust = new Date().getTime();
            requestData.force_refresh = true;
            }
        
        $.post(WC.ajaxurl, requestData)
        .done(function(response) {
            if (response.success) {
                WC.produtosSincronizados = response.data;
                WC.ultimoLojistaCarregado = lojistaId;
                renderProdutosSincronizados(response.data);
                
                // 🚀 Detectar se veio do cache do banco e mostrar indicador
                const isFromDbCache = response.data && response.data.length > 0 && !forceRefresh;
                let cacheMsg;
                
                if (forceRefresh) {
                    cacheMsg = '✅ Produtos atualizados do servidor!';
                } else if (isFromDbCache) {
                    cacheMsg = '⚡ Produtos carregados! do cache do banco!';
                } else {
                    cacheMsg = '✅ Produtos carregados!';
                }
                
                SincronizadorModals.showToast(cacheMsg, 'success');
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
        const grid = $('#produtos-grid');
        const resumo = $('#produtos-resumo');
        
        if (!produtos || produtos.length === 0) {
            grid.html('<div style="text-align: center; padding: 40px; color: #666;">Nenhum produto encontrado.</div>');
            resumo.html('<p>0 produtos disponíveis</p>');
            return;
        }
        
        // Resumo
        resumo.html(`<p>${produtos.length} produtos disponíveis para importação</p>`);
        
        // Grid de produtos usando a estrutura CSS existente
        let html = '';
        
        produtos.forEach(function(produto) {
            const precoDisplay = produto.em_promocao ? 
                `<p><strong>R$ ${produto.preco_promocional.toFixed(2)}</strong> <del>R$ ${produto.preco.toFixed(2)}</del></p>` :
                `<p><strong>R$ ${produto.preco.toFixed(2)}</strong></p>`;
            
            const statusEstoque = produto.estoque > 0 ? 
                `<p style="color: #27ae60;">✅ ${produto.estoque} em estoque</p>` :
                `<p style="color: #e74c3c;">❌ Sem estoque</p>`;
            
            html += `
                <div class="produto-card">
                    <div class="produto-checkbox">
                        <input type="checkbox" name="produtos_selecionados[]" value="${produto.id}" id="produto-${produto.id}">
                    </div>
                    <div class="produto-imagem">
                        <img src="${produto.imagem}" alt="${produto.nome}" style="width: 80px; height: 80px;" loading="lazy">
                    </div>
                    <div class="produto-info">
                        <h4>${produto.nome}</h4>
                        <p><strong>SKU:</strong> ${produto.sku}</p>
                        <p><strong>Categoria:</strong> ${produto.categoria}</p>
                        ${precoDisplay}
                        ${statusEstoque}
                        ${produto.em_promocao ? '<span class="produto-status status-info" style="background: #e74c3c; color: white;">🔥 PROMOÇÃO</span>' : ''}
                    </div>
                </div>
            `;
        });
        
        grid.html(html);
        
        // Atualizar contador
        atualizarContadorProdutos();
    }

    function atualizarContadorProdutos() {
        const selecionados = $("input[name='produtos_selecionados[]']:checked").length;
        const total = $("input[name='produtos_selecionados[]']").length;
        
        $('#produtos-count').text(`${selecionados} de ${total} produtos selecionados`);
        
        // Habilitar/desabilitar botão de importação (ID correto)
        const btnImportar = $('#btn-iniciar-importacao');
        if (btnImportar.length) {
            btnImportar.prop('disabled', selecionados === 0);
        } else {
            }
    }

    function showImportSections() {
        $("#produtos-section, #opcoes-importacao, #botoes-acao").show().addClass("fade-in");
        $("#btn-carregar-produtos").text("✅ Produtos Carregados");
    }

    function showImportResults(data) {
        if (!data) {
            return;
        }
        
        // Verificar se SincronizadorModals está disponível
        if (typeof SincronizadorModals === 'undefined') {
            alert('Importação concluída! Produtos processados: ' + (data.total || data.produtos_importados || 0));
            return;
        }
        
        // Adaptar os dados para o formato esperado pela função mostrarRelatorioSync
        // O backend retorna: sucessos, erros, pulados, total, message, logs
        const dadosRelatorio = {
            produtos_sincronizados: data.total || data.produtos_importados || 0,
            produtos_criados: data.sucessos || data.produtos_criados || 0,
            produtos_atualizados: data.pulados || data.produtos_atualizados || 0,
            erros: data.erros || 0,
            tempo: data.tempo || 'N/A',
            detalhes: data.message || data.detalhes || data.mensagem
        };
        
        // Obter nome do lojista se disponível
        const lojistaName = $("#lojista_destino option:selected").text() || 'Lojista de Destino';
        
        // Adicionar logs se disponíveis
        if (data.logs && Array.isArray(data.logs) && data.logs.length > 0) {
            let logDetails = '<div class="import-logs">';
            data.logs.forEach(log => {
                const logClass = log.type === 'error' ? 'error' : log.type === 'success' ? 'success' : 'info';
                logDetails += `<div class="log-item ${logClass}"><span class="log-icon">${log.type === 'error' ? '❌' : log.type === 'success' ? '✅' : 'ℹ️'}</span> ${log.message}</div>`;
            });
            logDetails += '</div>';
            dadosRelatorio.detalhes = (dadosRelatorio.detalhes || '') + logDetails;
        }
        
        // Mostrar modal de relatório usando a função existente
        try {
            if (typeof SincronizadorModals.mostrarRelatorioSync === 'function') {
                SincronizadorModals.mostrarRelatorioSync(dadosRelatorio, lojistaName, {
                    title: '📦 Importação Concluída',
                    subtitle: `Produtos importados para ${lojistaName}`
                });
            } else {
                // Fallback usando função de sucesso
                const resumo = `
                    Importação concluída com sucesso!
                    
                    ✅ Produtos importados: ${dadosRelatorio.produtos_criados}
                    📦 Total processados: ${dadosRelatorio.produtos_sincronizados}
                    ${dadosRelatorio.produtos_atualizados > 0 ? `⚠️ Pulados: ${dadosRelatorio.produtos_atualizados}` : ''}
                    ${dadosRelatorio.erros > 0 ? `❌ Erros: ${dadosRelatorio.erros}` : ''}
                    ${dadosRelatorio.tempo !== 'N/A' ? `⏱️ Tempo: ${dadosRelatorio.tempo}` : ''}
                `;
                SincronizadorModals.mostrarSucesso(resumo, '📦 Importação Concluída');
            }
        } catch (error) {
            console.error('📊 RESULTADOS - Erro ao mostrar modal:', error);
            const totais = `Produtos importados: ${dadosRelatorio.produtos_criados}\nTotal processados: ${dadosRelatorio.produtos_sincronizados}\nErros: ${dadosRelatorio.erros}`;
            alert(`Importação concluída!\n\n${totais}`);
        }
        
        // 🚀 NOVA FUNCIONALIDADE: Carregar automaticamente produtos sincronizados após importação
        const lojistaId = $("#lojista_destino").val();
        if (lojistaId && (dadosRelatorio.produtos_criados > 0 || dadosRelatorio.produtos_atualizados > 0)) {
            // Verificar se estamos na página de produtos sincronizados
            const isOnSyncPage = window.location.href.includes('sincronizados');
            
            if (isOnSyncPage) {
                setTimeout(() => {
                    carregarProdutosSincronizados(lojistaId);
                }, 2000); // Aguardar 2 segundos para não sobrecarregar
            } else {
                // Se não estiver na página, mostrar sugestão
                setTimeout(() => {
                    SincronizadorModals.showToast('💡 Vá para "Produtos Sincronizados" para ver os produtos importados', 'info', 5000);
                }, 3000);
            }
        }
    }

    function renderProdutosSincronizados(produtos) {
        const tbody = $('#produtos-sincronizados-tbody');
        const tabela = $('#tabela-sincronizados');
        const totalSpan = $('#total-produtos');
        
        if (!produtos || produtos.length === 0) {
            tbody.html('<tr><td colspan="7" style="text-align: center; padding: 20px;">Nenhum produto sincronizado encontrado.</td></tr>');
            totalSpan.text('(0 produtos)');
            tabela.show();
            return;
        }
        
        // 🚀 OTIMIZAÇÃO: Usar DocumentFragment para renderização muito mais rápida
        const startTime = performance.now();
        const fragment = document.createDocumentFragment();
        
        produtos.forEach(function(produto) {
            const statusClass = produto.status === 'publish' ? 'publish' : 'draft';
            const statusText = produto.status === 'publish' ? 'Publicado' : 'Rascunho';
            
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="column-foto">
                    ${produto.imagem ? 
                        `<img src="${produto.imagem}" alt="${produto.nome}" style="width: 50px; height: 50px; object-fit: cover;" loading="lazy">` : 
                        '<span class="dashicons dashicons-format-image" style="font-size: 30px; color: #ccc;"></span>'
                    }
                </td>
                <td class="column-id">${produto.id_fabrica}</td>
                <td>
                    <strong>${produto.nome}</strong><br>
                    <small>SKU: ${produto.sku}</small><br>
                    <small>Preço: R$ ${produto.preco_fabrica}</small>
                </td>
                <td class="column-id">${produto.id_destino || 'N/A'}</td>
                <td class="column-status">
                    <span class="status-${statusClass}">${statusText}</span>
                </td>
                <td class="column-vendas">
                    ${produto.vendas && produto.vendas > 0 ? 
                        `<strong>${parseInt(produto.vendas, 10)}</strong>` : 
                        '<small>Sem vendas</small>'
                    }
                </td>
                <td class="column-acoes">
                    <button type="button" class="button button-small" onclick="verDetalhesProduto(${produto.id_fabrica})">
                        Ver Detalhes
                    </button>
                </td>
            `;
            fragment.appendChild(tr);
        });
        
        // Limpar e adicionar tudo de uma vez (muito mais rápido)
        tbody[0].innerHTML = '';
        tbody[0].appendChild(fragment);
        
        totalSpan.text(`(${produtos.length} produtos)`);
        tabela.show();
        
        const endTime = performance.now();

    }

    function filterProdutosSincronizados(termo) {
        if (!WC.produtosSincronizados || WC.produtosSincronizados.length === 0) {
            return;
        }
        
        // 🚀 OTIMIZAÇÃO: Se termo vazio, mostrar todos os produtos rapidamente
        if (!termo || termo.trim() === '') {
            renderProdutosSincronizados(WC.produtosSincronizados);
            return;
        }
        
        const startTime = performance.now();
        const termoLower = termo.toLowerCase();
        
        // 🚀 OTIMIZAÇÃO: Filtro mais eficiente
        const produtosFiltrados = WC.produtosSincronizados.filter(produto => {
            return produto.nome.toLowerCase().includes(termoLower) ||
                   produto.sku.toLowerCase().includes(termoLower) ||
                   (produto.id_fabrica && produto.id_fabrica.toString().includes(termoLower));
        });
        
        const endTime = performance.now();

        renderProdutosSincronizados(produtosFiltrados);
    }

    // Expor funções principais globalmente
    WC.showNotice = showNotice;
    WC.formatPrice = formatPrice;
    WC.executarSincronizacao = executarSincronizacao;
    
    // Função global para ver detalhes do produto
    window.verDetalhesProduto = function(produtoId) {
        const produto = WC.produtosSincronizados.find(p => p.id_fabrica == produtoId);
        if (!produto) {
            if (typeof SincronizadorModals !== 'undefined') {
                SincronizadorModals.mostrarErro('Produto não encontrado');
            } else {
                alert('Produto não encontrado');
            }
            return;
        }
        
        // Função auxiliar para formatar preço
        const formatarPreco = (preco) => {
            if (!preco || preco === 0 || preco === '0') return 'R$ 0,00';
            const valor = parseFloat(preco.toString().replace(',', '.'));
            return `R$ ${valor.toFixed(2).replace('.', ',')}`;
        };
        
        // Função auxiliar para status
        const getStatusBadge = (status) => {
            const statusMap = {
                'publish': { text: 'Publicado', class: 'status-publicado', icon: '✅' },
                'draft': { text: 'Rascunho', class: 'status-rascunho', icon: '📝' },
                'private': { text: 'Privado', class: 'status-privado', icon: '🔒' },
                'pending': { text: 'Pendente', class: 'status-pendente', icon: '⏳' }
            };
            const info = statusMap[status] || { text: status, class: 'status-outro', icon: '❓' };
            return `<span class="status-badge ${info.class}">${info.icon} ${info.text}</span>`;
        };
        
        // Gerar HTML das variações se existirem
        let variacoesHtml = '';
        if (produto.tem_variacoes && produto.variacoes && produto.variacoes.length > 0) {
            variacoesHtml = `
                <div class="variacoes-section">
                    <h4 style="margin: 20px 0 10px 0; color: #23282d;">🔄 Variações (${produto.variacoes.length})</h4>
                    <div class="variacoes-grid" style="display: grid; gap: 10px; max-height: 300px; overflow-y: auto;">
            `;
            
            produto.variacoes.forEach((variacao, index) => {
                let atributosText = '';
                if (variacao.atributos && variacao.atributos.length > 0) {
                    atributosText = variacao.atributos.map(attr => `<strong>${attr.nome}:</strong> ${attr.valor}`).join(', ');
                }
                
                variacoesHtml += `
                    <div class="variacao-item" style="background: #f9f9f9; padding: 12px; border-radius: 6px; border-left: 4px solid #0073aa;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; font-size: 13px;">
                            <div>
                                <strong>📋 Variação ${index + 1}</strong><br>
                                ${variacao.sku ? `<small><strong>SKU:</strong> ${variacao.sku}</small><br>` : ''}
                                ${atributosText ? `<small>${atributosText}</small>` : '<small><em>Sem atributos</em></small>'}
                            </div>
                            <div>
                                <strong>💰 Preços</strong><br>
                                <small><strong>Fábrica:</strong> ${formatarPreco(variacao.preco_fabrica)}</small><br>
                                <small><strong>Lojista:</strong> ${formatarPreco(variacao.preco_destino || 0)}</small>
                            </div>
                            <div>
                                <strong>📦 Estoque</strong><br>
                                <small><strong>Fábrica:</strong> ${variacao.estoque_fabrica || 0}</small><br>
                                <small><strong>Lojista:</strong> ${variacao.estoque_destino || 0}</small>
                                ${typeof variacao.vendas_destino !== 'undefined' ? `<br><small><strong>Vendas (loja):</strong> ${variacao.vendas_destino}</small>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            variacoesHtml += `
                    </div>
                </div>
            `;
        } else if (produto.tem_variacoes) {
            variacoesHtml = `
                <div class="variacoes-section">
                    <h4 style="margin: 20px 0 10px 0; color: #23282d;">🔄 Variações</h4>
                    <p style="color: #666; font-style: italic;">Este produto possui variações, mas os dados não foram carregados. Isso pode ser por questões de performance.</p>
                </div>
            `;
        }
        
        // Gerar HTML das vendas
        let vendasHtml = '';
        if (produto.vendas && produto.vendas > 0) {
            vendasHtml = `
                <div class="vendas-section" style="background: #e8f5e8; padding: 15px; border-radius: 6px; border-left: 4px solid #46b450;">
                    <h4 style="margin: 0 0 8px 0; color: #23282d;">💰 Vendas</h4>
                    <p style="margin: 0; font-size: 16px;"><strong>Total:</strong> ${parseInt(produto.vendas, 10)}</p>
                </div>
            `;
        } else {
            vendasHtml = `
                <div class="vendas-section" style="background: #f0f0f1; padding: 15px; border-radius: 6px; border-left: 4px solid #999;">
                    <h4 style="margin: 0 0 8px 0; color: #23282d;">💰 Vendas</h4>
                    <p style="margin: 0; color: #666; font-style: italic;">Sem dados de vendas registradas</p>
                </div>
            `;
        }
        
        const modalContent = `
            <div style="max-width: 700px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">
                <!-- Cabeçalho do produto -->
                <div style="display: flex; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e1e1e1;">
                    ${produto.imagem ? `<img src="${produto.imagem}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 6px; margin-right: 15px;" alt="${produto.nome}">` : ''}
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 5px 0; color: #23282d; font-size: 20px;">${produto.nome}</h3>
                        <p style="margin: 0; color: #666;">
                            <strong>SKU:</strong> ${produto.sku || 'N/A'} • 
                            <strong>Tipo:</strong> ${produto.tipo_produto || 'simples'} • 
                            ${getStatusBadge(produto.status)}
                        </p>
                    </div>
                </div>
                
                <!-- Grid principal de informações -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <!-- Dados da Fábrica -->
                    <div style="background: #f0f6ff; padding: 15px; border-radius: 8px; border-left: 4px solid #0073aa;">
                        <h4 style="margin: 0 0 12px 0; color: #0073aa; font-size: 16px;">🏭 Fábrica (Origem)</h4>
                        <div style="line-height: 1.6;">
                            <p style="margin: 0 0 8px 0;"><strong>ID:</strong> ${produto.id_fabrica}</p>
                            <p style="margin: 0 0 8px 0;"><strong>Preço:</strong> ${formatarPreco(produto.preco_fabrica)}</p>
                            <p style="margin: 0 0 8px 0;"><strong>Estoque:</strong> ${produto.estoque_fabrica || 0} unidades</p>
                            <p style="margin: 0;"><strong>Tipo:</strong> ${produto.tipo_produto || 'simples'}</p>
                        </div>
                    </div>
                    
                    <!-- Dados do Lojista -->
                    <div style="background: #fff3e0; padding: 15px; border-radius: 8px; border-left: 4px solid #ff9800;">
                        <h4 style="margin: 0 0 12px 0; color: #ff9800; font-size: 16px;">🏪 Lojista (Destino)</h4>
                        <div style="line-height: 1.6;">
                            <p style="margin: 0 0 8px 0;"><strong>ID:</strong> ${produto.id_destino || 'N/A'}</p>
                            <p style="margin: 0 0 8px 0;"><strong>Preço:</strong> ${formatarPreco(produto.preco_destino || 0)}</p>
                            <p style="margin: 0 0 8px 0;"><strong>Estoque:</strong> ${produto.estoque_destino || 0} unidades</p>
                            <p style="margin: 0;"><strong>Última Sync:</strong> ${produto.ultima_sync ? new Date(produto.ultima_sync).toLocaleString('pt-BR') : 'N/A'}</p>
                        </div>
                    </div>
                </div>
                
                <!-- Seção de vendas -->
                ${vendasHtml}
                
                <!-- Seção de variações -->
                ${variacoesHtml}
            </div>
            
            <style>
                .status-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                .status-publicado { background: #e8f5e8; color: #2e7d32; }
                .status-rascunho { background: #fff3e0; color: #f57c00; }
                .status-privado { background: #fce4ec; color: #c2185b; }
                .status-pendente { background: #e3f2fd; color: #1976d2; }
                .status-outro { background: #f5f5f5; color: #666; }
                
                .variacoes-grid::-webkit-scrollbar {
                    width: 6px;
                }
                .variacoes-grid::-webkit-scrollbar-track {
                    background: #f1f1f1;
                }
                .variacoes-grid::-webkit-scrollbar-thumb {
                    background: #c1c1c1;
                    border-radius: 3px;
                }
                .variacoes-grid::-webkit-scrollbar-thumb:hover {
                    background: #a8a8a8;
                }
            </style>
        `;
        
        $('#modal-conteudo').html(modalContent);
        $('#modal-titulo').text(`Detalhes: ${produto.nome}`);
        $('#modal-detalhes').show();
    };
    
    // Event listener para fechar modal
    $(document).on('click', '#btn-fechar-modal', function() {
        $('#modal-detalhes').hide();
    });

})(jQuery);


