/**
 * JavaScript do Admin - Sincronizador WooCommerce
 * @version 1.1.0
 */

(function($) {
    'use strict';

    // Controle de inicialização mais inteligente - baseado apenas no DOM ready
    if (window.SincronizadorWCInitialized) {
        return;
    }
    window.SincronizadorWCInitialized = true;

    // Variáveis globais - Inicialização segura
    if (typeof window.SincronizadorWC === 'undefined') {
        window.SincronizadorWC = {};
    }
    
    // Garantir que todas as propriedades existam
    window.SincronizadorWC.ajaxurl = window.SincronizadorWC.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
    window.SincronizadorWC.nonce = window.SincronizadorWC.nonce || '';
    window.SincronizadorWC.currentImportId = window.SincronizadorWC.currentImportId || null;
    window.SincronizadorWC.progressInterval = window.SincronizadorWC.progressInterval || null;
    window.SincronizadorWC.produtosSincronizados = window.SincronizadorWC.produtosSincronizados || [];

    $(document).ready(function() {
        // Limpar modais órfãos imediatamente
        limparModaisOrfaos();
        
        // Verificar se existe o nonce
        if (!window.SincronizadorWC.nonce || window.SincronizadorWC.nonce === '') {
            console.warn('⚠️ NONCE NÃO DEFINIDO! Os requests AJAX podem falhar.');
            
            // Tentar obter nonce de outro lugar se possível
            const metaNonce = $('meta[name="sincronizador-wc-nonce"]').attr('content');
            if (metaNonce) {
                window.SincronizadorWC.nonce = metaNonce;
            }
        }
        
        initSincronizador();
    });

    // Função para limpar modais órfãos do DOM
    function limparModaisOrfaos() {
        // Remover todos os modais de progresso órfãos
        $('.modal-overlay, #modal-progresso, #modal-relatorio').remove();
    }

    function initSincronizador() {
        // Detectar qual página estamos
        const currentPage = detectCurrentPage();
        
        // Inicializar baseado na página
        switch(currentPage) {
            case 'importar':
                if ($('#lojista_destino').length) {
                    initImportPage();
                }
                break;
            case 'sincronizados':
                if ($('#tabela-sincronizados').length) {
                    initSyncPage();
                }
                break;
            case 'lojistas':
                initLojistasPage();
                break;
            case 'add-lojista':
                if ($('#btn-test-connection').length) {
                    initConnectionTest();
                }
                break;
        }
        
        // Eventos globais que funcionam em todas as páginas
        initGlobalEvents();
    }
    
    function detectCurrentPage() {
        const url = window.location.href;
        
        if (url.includes('sincronizador-wc-importar')) return 'importar';
        if (url.includes('sincronizador-wc-sincronizados')) return 'sincronizados';
        if (url.includes('sincronizador-wc-add-lojista')) return 'add-lojista';
        if (url.includes('sincronizador-wc-lojistas')) return 'lojistas';
        
        return 'dashboard';
    }
    
    function initLojistasPage() {
        // Contar lojistas na página
        const totalLojistas = $('table.wp-list-table tbody tr').length;
        
        // Adicionar data-lojista aos botões de sincronização
        $('form input[name="action"][value="sync_produtos"]').each(function() {
            const form = $(this).closest('form');
            const lojistaId = form.find('input[name="lojista_id"]').val();
            const submitBtn = form.find('button[type="submit"]');
            
            if (lojistaId && submitBtn.length) {
                submitBtn.addClass('btn-sincronizar').attr('data-lojista', lojistaId);
            }
        });
    }

    // === PÁGINA DE IMPORTAÇÃO === //
    function initImportPage() {
        let currentImportId = null;
        let progressInterval = null;

        // Os eventos agora são tratados na função initGlobalEvents()
        // Apenas configurações específicas aqui
        
        setupProductEvents();
    }

    function validateLojista(lojistaId) {
        const btn = $("#btn-validar-lojista");
        btn.prop("disabled", true).addClass("btn-loading");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_validate_lojista",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId
        }, function(response) {
            const statusDiv = $("#validacao-status");
            
            if (response.success) {
                statusDiv.html(`
                    <div class="notice notice-success">
                        <p>✅ Lojista validado! ${response.data.message}</p>
                    </div>
                `).show().addClass("fade-in");
                
                $("#btn-carregar-produtos").prop("disabled", false);
            } else {
                statusDiv.html(`
                    <div class="notice notice-error">
                        <p>❌ Erro: ${response.data}</p>
                    </div>
                `).show().addClass("fade-in");
                
                $("#btn-carregar-produtos").prop("disabled", true);
            }
        }).fail(function() {
            alert("Erro de comunicação com o servidor");
        }).always(function() {
            btn.prop("disabled", false).removeClass("btn-loading");
        });
    }

    function loadProdutos() {
        const btn = $("#btn-carregar-produtos");
        btn.prop("disabled", true).addClass("btn-loading");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_get_produtos_fabrica",
            nonce: SincronizadorWC.nonce
        }, function(response) {
            if (response.success) {
                renderProdutos(response.data);
                showImportSections();
            } else {
                alert("Erro ao carregar produtos: " + response.data);
            }
        }).fail(function() {
            alert("Erro de comunicação com o servidor");
        }).always(function() {
            btn.prop("disabled", false).removeClass("btn-loading");
        });
    }

    function testarConexaoLojista(lojistaId, buttonElement) {
        const originalText = buttonElement.text();
        buttonElement.prop("disabled", true).text("🔄 Testando...");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_validate_lojista",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId
        })
        .done(function(response) {
            if (response.success) {
                const data = response.data || {};
                showNotice('✅ Conexão OK: ' + data.nome, 'success');
                buttonElement.removeClass('button-secondary').addClass('button-primary').text('✅ Conectado');
                
                // Voltar ao estado original após 3 segundos
                setTimeout(function() {
                    buttonElement.removeClass('button-primary').addClass('button-secondary').text(originalText);
                }, 3000);
            } else {
                const errorMessage = (response.data && response.data.message) ? response.data.message : 'Erro na conexão';
                showNotice('❌ ' + errorMessage, 'error');
                buttonElement.removeClass('button-secondary').addClass('button-primary').css('background-color', '#dc3232').text('❌ Erro');
                
                // Voltar ao estado original após 3 segundos
                setTimeout(function() {
                    buttonElement.removeClass('button-primary').addClass('button-secondary').css('background-color', '').text(originalText);
                }, 3000);
            }
        })
        .fail(function(xhr, status, error) {
            showNotice('❌ Erro de conexão: ' + error, 'error');
            buttonElement.text('❌ Erro');
            
            setTimeout(function() {
                buttonElement.text(originalText);
            }, 3000);
        })
        .always(function() {
            buttonElement.prop("disabled", false);
        });
    }

    function renderProdutos(produtos) {
        const grid = $("#produtos-grid");
        const paginationContainer = $("#produtos-pagination");
        
        grid.empty();
        paginationContainer.empty();
        
        if (produtos.length === 0) {
            grid.html('<p class="text-center">Nenhum produto encontrado</p>');
            return;
        }
        
        // Configurações de paginação
        const produtosPorPagina = 12;
        const totalPaginas = Math.ceil(produtos.length / produtosPorPagina);
        let paginaAtual = 1;
        
        function renderPagina(pagina) {
            grid.empty();
            
            const inicio = (pagina - 1) * produtosPorPagina;
            const fim = inicio + produtosPorPagina;
            const produtosPagina = produtos.slice(inicio, fim);
            
            produtosPagina.forEach(function(produto) {
                const isDisabled = produto.status !== "ativo";
                const precoFinal = produto.preco_promocional ? produto.preco_promocional : produto.preco;
                const precoOriginal = produto.preco_promocional ? produto.preco : '';
                
                let precoHTML = `<span class="preco-atual">R$ ${formatPrice(precoFinal)}</span>`;
                if (precoOriginal) {
                    precoHTML += ` <del class="preco-original">R$ ${formatPrice(precoOriginal)}</del>`;
                }
                
                const card = $(`
                    <div class="produto-card ${isDisabled ? 'disabled' : ''}" 
                         data-categoria="${produto.categoria}" 
                         data-status="${produto.status}">
                        <div class="produto-checkbox">
                            <input type="checkbox" 
                                   name="produtos_selecionados[]" 
                                   value="${produto.id}" 
                                   id="produto_${produto.id}"
                                   ${isDisabled ? 'disabled' : ''}>
                        </div>
                        <div class="produto-imagem">
                            <img src="${produto.imagem}" 
                                 alt="${produto.nome}" 
                                 width="80" height="80">
                        </div>
                        <div class="produto-info">
                            <h4><label for="produto_${produto.id}">${produto.nome}</label></h4>
                            <p><strong>SKU:</strong> ${produto.sku}</p>
                            <p><strong>Categoria:</strong> ${produto.categoria}</p>
                            <p><strong>Preço:</strong> ${precoHTML}</p>
                            <p><strong>Estoque:</strong> ${produto.estoque} unidades</p>
                            <span class="produto-status status-${produto.status}">${produto.status}</span>
                        </div>
                    </div>
                `);
                
                grid.append(card);
            });
            
            // Atualizar controles de paginação
            renderPaginationControls(pagina, totalPaginas);
        }
        
        function renderPaginationControls(pagina, total) {
            if (total <= 1) return;
            
            let paginationHTML = `
                <div class="pagination-info">
                    Mostrando ${((pagina - 1) * produtosPorPagina) + 1}-${Math.min(pagina * produtosPorPagina, produtos.length)} de ${produtos.length} produtos
                </div>
                <div class="pagination-controls">
            `;
            
            // Botão anterior
            if (pagina > 1) {
                paginationHTML += `<button class="button pagination-btn" data-page="${pagina - 1}">‹ Anterior</button>`;
            }
            
            // Números das páginas
            for (let i = 1; i <= total; i++) {
                if (i === pagina) {
                    paginationHTML += `<button class="button button-primary pagination-btn" data-page="${i}">${i}</button>`;
                } else if (i === 1 || i === total || (i >= pagina - 2 && i <= pagina + 2)) {
                    paginationHTML += `<button class="button pagination-btn" data-page="${i}">${i}</button>`;
                } else if (i === pagina - 3 || i === pagina + 3) {
                    paginationHTML += `<span class="pagination-dots">...</span>`;
                }
            }
            
            // Botão próximo
            if (pagina < total) {
                paginationHTML += `<button class="button pagination-btn" data-page="${pagina + 1}">Próximo ›</button>`;
            }
            
            paginationHTML += '</div>';
            
            paginationContainer.html(paginationHTML);
            
            // Event listeners para paginação
            paginationContainer.find('.pagination-btn').on('click', function() {
                const novaPagina = parseInt($(this).data('page'));
                paginaAtual = novaPagina;
                renderPagina(novaPagina);
            });
        }
        
        // Renderizar primeira página
        renderPagina(1);
        
        // Mostrar resumo
        $("#produtos-resumo").html(`
            <div class="produtos-summary">
                <strong>📊 Resumo:</strong> 
                ${produtos.length} produtos encontrados | 
                ${produtos.filter(p => p.status === 'ativo').length} ativos | 
                ${produtos.filter(p => p.preco_promocional).length} em promoção
            </div>
        `);
        
        setupProductEvents();
    }

    function setupProductEvents() {
        // Selecionar todos
        $("#selecionar-todos").off("change").on("change", function() {
            $("input[name='produtos_selecionados[]']:not(:disabled)")
                .prop("checked", this.checked);
            updateCounter();
        });
        
        // Atualizar contador
        $("input[name='produtos_selecionados[]']").off("change").on("change", function() {
            updateCounter();
            
            // Adicionar classe visual ao card
            const card = $(this).closest('.produto-card');
            if (this.checked) {
                card.addClass('selected');
            } else {
                card.removeClass('selected');
            }
        });
        
        // Busca
        $("#buscar-produto").off("input").on("input", function() {
            const termo = this.value.toLowerCase();
            $(".produto-card").each(function() {
                const nome = $(this).find("h4").text().toLowerCase();
                const sku = $(this).find("p").first().text().toLowerCase();
                const visible = nome.includes(termo) || sku.includes(termo);
                $(this).toggle(visible);
            });
        });
        
        // Click no card para selecionar
        $(".produto-card").off("click").on("click", function(e) {
            if (e.target.type !== 'checkbox' && e.target.tagName !== 'LABEL') {
                const checkbox = $(this).find('input[type="checkbox"]');
                if (!checkbox.prop('disabled')) {
                    checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
                }
            }
        });
    }

    function updateCounter() {
        const selecionados = $("input[name='produtos_selecionados[]']:checked").length;
        const total = $("input[name='produtos_selecionados[]']:not(:disabled)").length;
        
        $("#produtos-count").text(`${selecionados} de ${total} produtos selecionados`);
        $("#btn-iniciar-importacao").prop("disabled", selecionados === 0);
    }

    function startImport() {
        const produtosSelecionados = [];
        $("input[name='produtos_selecionados[]']:checked").each(function() {
            produtosSelecionados.push(this.value);
        });
        
        if (produtosSelecionados.length === 0) {
            alert("Selecione pelo menos um produto!");
            return;
        }
        
        const dados = {
            action: "sincronizador_wc_import_produtos",
            nonce: SincronizadorWC.nonce,
            lojista_destino: $("#lojista_destino").val(),
            produtos_selecionados: produtosSelecionados,
            incluir_variacoes: $("#incluir_variacoes").is(":checked") ? 1 : 0,
            incluir_imagens: $("#incluir_imagens").is(":checked") ? 1 : 0,
            manter_precos: $("#manter_precos").is(":checked") ? 1 : 0
        };
        
        $("#btn-iniciar-importacao").prop("disabled", true).addClass("btn-loading");
        
        $.post(SincronizadorWC.ajaxurl, dados, function(response) {
            if (response.success) {
                showImportResults(response.data);
            } else {
                alert("Erro ao iniciar importação: " + response.data.message);
            }
        }).fail(function() {
            alert("Erro de comunicação com o servidor");
        }).always(function() {
            $("#btn-iniciar-importacao").prop("disabled", false).removeClass("btn-loading");
        });
    }

    function showImportResults(data) {
        const modal = $("#modal-resultado");
        const conteudo = $("#modal-resultado-conteudo");
        
        let html = `
            <h3>Resultado da Importação</h3>
            <div class="import-summary">
                <p><strong>Total:</strong> ${data.total} produtos</p>
                <p><strong>Sucessos:</strong> <span style="color: green">${data.sucessos}</span></p>
                <p><strong>Erros:</strong> <span style="color: red">${data.erros}</span></p>
            </div>
        `;
        
        if (data.detalhes && data.detalhes.length > 0) {
            html += '<div class="import-details"><h4>Detalhes:</h4><ul>';
            data.detalhes.forEach(function(item) {
                const icon = item.success ? '✅' : '❌';
                const color = item.success ? 'green' : 'red';
                html += `<li style="color: ${color}">${icon} ${item.message}</li>`;
            });
            html += '</ul></div>';
        }
        
        conteudo.html(html);
        modal.show().addClass("fade-in");
    }

    function showImportSections() {
        $("#produtos-section").show().addClass("fade-in");
        $("#opcoes-importacao").show().addClass("fade-in");
        $("#botoes-acao").show().addClass("fade-in");
        $("#btn-carregar-produtos").text("✅ Produtos Carregados");
    }

    function resetImportPage() {
        $("#produtos-section").hide();
        $("#opcoes-importacao").hide();
        $("#botoes-acao").hide();
        $("#validacao-status").hide();
        $("#btn-carregar-produtos").prop("disabled", true).text("📋 Carregar Produtos");
    }

    // === PÁGINA DE PRODUTOS SINCRONIZADOS === //
    function initSyncPage() {
        // Busca em tempo real
        $(document).on('input', '#buscar-sincronizado', function() {
            const termo = this.value.toLowerCase();
            if (SincronizadorWC.produtosSincronizados) {
                const produtosFiltrados = SincronizadorWC.produtosSincronizados.filter(produto => 
                    produto.nome.toLowerCase().includes(termo) || 
                    produto.sku.toLowerCase().includes(termo)
                );
                renderProdutosSincronizados(produtosFiltrados);
            }
        });
    }

    function loadProdutosSincronizados() {
        const lojistaId = $("#lojista_destino").val();
        if (!lojistaId) return;
        
        // Primeiro verificar se o lojista tem configuração válida
        $.post(SincronizadorWC.ajaxurl, {
            action: 'verificar_lojista_config',
            lojista_id: lojistaId,
            nonce: SincronizadorWC.nonce
        })
        .done(function(validationResponse) {
            if (!validationResponse.success) {
                alert('❌ ' + (validationResponse.data || 'Lojista não configurado corretamente. Configure a API key antes de carregar produtos.'));
                return;
            }
            
            // Se chegou até aqui, pode prosseguir com o carregamento
            carregarProdutosSincronizados(lojistaId);
        })
        .fail(function(xhr, status, error) {
            console.error('ERRO na validação do lojista:', {xhr, status, error});
            alert('❌ Erro ao verificar configuração do lojista. Verifique se a API key está configurada corretamente.');
        });
    }
    
    function carregarProdutosSincronizados(lojistaId) {
        const btn = $("#btn-carregar-sincronizados");
        btn.prop("disabled", true).addClass("btn-loading");
        
        // Adicionar timestamp para forçar cache bust
        const timestamp = new Date().getTime();
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_get_produtos_sincronizados",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId,
            cache_bust: timestamp, // Forçar bypass de cache
            force_refresh: true     // Flag para limpeza de cache
        }, function(response) {
            if (response.success) {
                SincronizadorWC.produtosSincronizados = response.data;
                renderProdutosSincronizados(response.data);
                $("#tabela-sincronizados").show().addClass("fade-in");
                $("#total-produtos").text(`(${response.data.length} produtos)`);
            } else {
                alert("❌ Erro ao carregar produtos: " + response.data);
                console.error('ERRO ao carregar produtos:', response.data);
            }
        }).fail(function(xhr, status, error) {
            console.error('ERRO ao carregar produtos sincronizados:', {xhr, status, error});
            alert("❌ Erro de comunicação com o servidor");
        }).always(function() {
            btn.prop("disabled", false).removeClass("btn-loading");
        });
    }

    function renderProdutosSincronizados(produtos) {
        const tbody = $("#produtos-sincronizados-tbody");
        tbody.empty();
        
        if (produtos.length === 0) {
            tbody.html(`
                <tr>
                    <td colspan="7" class="text-center" style="padding: 40px;">
                        Nenhum produto sincronizado encontrado
                    </td>
                </tr>
            `);
            return;
        }
        
        produtos.forEach(function(produto) {
            const statusClass = produto.status === "sincronizado" ? "status-ativo" : "status-erro";
            
            // Processar vendas - pode ser um número ou objeto complexo
            let vendasText = "N/A";
            if (produto.vendas !== null && produto.vendas !== undefined) {
                if (typeof produto.vendas === 'object' && produto.vendas.total_vendas !== undefined) {
                    // Se vendas é um objeto com total_vendas
                    vendasText = produto.vendas.total_vendas;
                } else if (typeof produto.vendas === 'number') {
                    // Se vendas é um número
                    vendasText = produto.vendas;
                } else if (typeof produto.vendas === 'string' && !isNaN(produto.vendas)) {
                    // Se vendas é uma string numérica
                    vendasText = produto.vendas;
                } else {
                    // Fallback para outros casos
                    vendasText = "N/A";
                }
            }
            
            const tipoProduto = produto.tipo_produto || 'simples';
            const tipoIcon = tipoProduto === 'variável' ? '📦' : '📄';
            const tipoClass = tipoProduto === 'variável' ? 'produto-tipo-variavel' : '';
            const variacoesInfo = produto.tem_variacoes && produto.variacoes ? ` (${produto.variacoes.length} var.)` : '';
            
            const row = $(`
                <tr>
                    <td>
                        <img src="${produto.imagem}" 
                             alt="${produto.nome}" 
                             width="50" height="50" 
                             style="border-radius: 4px; object-fit: cover;">
                    </td>
                    <td><strong>${produto.id_fabrica}</strong></td>
                    <td>
                        <strong>${produto.nome}</strong><br>
                        <small>SKU: ${produto.sku}</small><br>
                        <small>
                            ${tipoIcon} <span class="${tipoClass}">${tipoProduto}</span>
                            ${variacoesInfo ? `<span class="produto-info-variacoes">${variacoesInfo}</span>` : ''}
                        </small>
                    </td>
                    <td><strong>${produto.id_destino || "N/A"}</strong></td>
                    <td><span class="produto-status ${statusClass}">${produto.status}</span></td>
                    <td><strong>${vendasText}</strong></td>
                    <td>
                        <button type="button" class="button button-small btn-ver-detalhes" 
                                data-produto='${JSON.stringify(produto)}'>👁️ Ver</button>
                        <button type="button" class="button button-small btn-testar-sync" 
                                data-id="${produto.id_fabrica}" 
                                title="Testar conexão e ler dados do produto no destino (não modifica nada)">� Testar Conexão</button>
                    </td>
                </tr>
            `);
            tbody.append(row);
        });
        
        // Setup event handlers
        setupSyncEvents();
    }

    function setupSyncEvents() {
        $(".btn-ver-detalhes").off("click").on("click", function() {
            const produto = JSON.parse($(this).attr("data-produto"));
            mostrarDetalhesCompletos(produto);
        });
        
        $(".btn-testar-sync").off("click").on("click", function() {
            const produtoId = $(this).attr("data-id");
            testarConexaoProduto(produtoId);
        });
    }
    
    function mostrarDetalhesCompletos(produto) {
        const modal = $("#modal-detalhes");
        const titulo = $("#modal-titulo");
        const conteudo = $("#modal-conteudo");
        
        titulo.text("Carregando detalhes: " + produto.nome);
        conteudo.html('<div style="text-align: center; padding: 20px;"><span class="spinner is-active"></span><p>Carregando dados completos...</p></div>');
        modal.show().addClass("fade-in");
        
        // Buscar detalhes completos via AJAX
        const lojistaId = $("#lojista_destino").val();
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_obter_detalhes_produto",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId,
            produto_id_fabrica: produto.id_fabrica
        }, function(response) {
            if (response.success) {
                const produtoCompleto = response.data;
                mostrarDetalhes(produtoCompleto);
            } else {
                conteudo.html(`
                    <div style="text-align: center; padding: 20px; color: #dc3232;">
                        <h3>❌ Erro ao carregar detalhes</h3>
                        <p>${response.data || 'Erro desconhecido'}</p>
                        <button type="button" class="button" onclick="$('#modal-detalhes').hide()">Fechar</button>
                    </div>
                `);
            }
        }).fail(function(xhr, status, error) {
            console.error('Erro ao carregar detalhes:', {xhr, status, error});
            conteudo.html(`
                <div style="text-align: center; padding: 20px; color: #dc3232;">
                    <h3>❌ Erro de comunicação</h3>
                    <p>Não foi possível carregar os detalhes do produto.</p>
                    <button type="button" class="button" onclick="$('#modal-detalhes').hide()">Fechar</button>
                </div>
            `);
        });
    }

    function mostrarDetalhes(produto) {
        const modal = $("#modal-detalhes");
        const titulo = $("#modal-titulo");
        const conteudo = $("#modal-conteudo");
        
        titulo.text("Detalhes: " + produto.nome);
        
        // Processar vendas para exibição
        let vendasDisplay = "N/A";
        if (produto.vendas !== null && produto.vendas !== undefined) {
            if (typeof produto.vendas === 'object' && produto.vendas.total_vendas !== undefined) {
                vendasDisplay = produto.vendas.total_vendas + ' unidades vendidas';
            } else if (typeof produto.vendas === 'number') {
                vendasDisplay = produto.vendas + ' unidades vendidas';
            } else if (typeof produto.vendas === 'string' && !isNaN(produto.vendas)) {
                vendasDisplay = produto.vendas + ' unidades vendidas';
            }
        }
        
        let detalhes = `
            <table class="form-table">
                <tr><th>ID Fábrica:</th><td><strong>${produto.id_fabrica}</strong></td></tr>
                <tr><th>ID Destino:</th><td><strong>${produto.id_destino || "N/A"}</strong></td></tr>
                <tr><th>SKU:</th><td><code>${produto.sku}</code></td></tr>
                <tr><th>Tipo:</th><td><span class="produto-tipo" style="background: #f0f8ff; padding: 2px 8px; border-radius: 3px;">${produto.tipo_produto || 'simples'}</span></td></tr>
                <tr><th>Status:</th><td><span class="produto-status ${produto.status === "sincronizado" ? "status-ativo" : "status-erro"}">${produto.status}</span></td></tr>
                <tr><th>Preço Fábrica:</th><td><span style="color: #0073aa; font-weight: bold; font-size: 16px;">R$ ${formatPrice(produto.preco_fabrica)}</span></td></tr>
                <tr><th>Preço Destino:</th><td><span style="color: #0073aa; font-weight: bold; font-size: 16px;">R$ ${formatPrice(produto.preco_destino)}</span></td></tr>
                <tr><th>Estoque Fábrica:</th><td><span style="color: ${(produto.estoque_fabrica || 0) > 0 ? '#46b450' : '#dc3232'}; font-weight: bold;">${produto.estoque_fabrica || 0} unidades</span></td></tr>
                <tr><th>Estoque Destino:</th><td><span style="color: ${(produto.estoque_destino || 0) > 0 ? '#46b450' : '#dc3232'}; font-weight: bold;">${produto.estoque_destino || 0} unidades</span></td></tr>
                <tr><th>Vendas Totais:</th><td><strong style="color: #d63638; font-size: 18px; background: #fff8dc; padding: 5px 10px; border-radius: 4px; border: 2px solid #ddd;">📊 ${vendasDisplay}</strong></td></tr>
                <tr><th>Última Sincronização:</th><td>${produto.ultima_sync || "Nunca"}</td></tr>
            </table>
        `;
        
        // Adicionar variações se existirem
        if (produto.tem_variacoes && produto.variacoes && produto.variacoes.length > 0) {
            detalhes += `
                <h3 style="margin-top: 20px; color: #2271b1;">📦 Variações (${produto.variacoes.length})</h3>
                <div class="variacoes-container">
            `;
            
            produto.variacoes.forEach((variacao, index) => {
                const statusVariacao = variacao.status === 'sincronizado' ? 'status-ativo' : 'status-erro';
                const atributosText = variacao.atributos.map(attr => `${attr.nome}: ${attr.valor}`).join(', ');
                
                detalhes += `
                    <div class="variacao-item" style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px; background: #f9f9f9;">
                        <div class="variacao-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0; color: #1d2327;">Variação ${index + 1}</h4>
                            <span class="produto-status ${statusVariacao}" style="font-size: 12px; padding: 3px 8px;">${variacao.status}</span>
                        </div>
                        <div class="variacao-details" style="font-size: 14px;">
                            <p><strong>SKU:</strong> ${variacao.sku || 'N/A'}</p>
                            <p><strong>Atributos:</strong> ${atributosText || 'Nenhum'}</p>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                                <div>
                                    <strong>🏭 Fábrica:</strong><br>
                                    Preço: <span style="color: #0073aa; font-weight: bold;">R$ ${formatPrice(variacao.preco_fabrica)}</span><br>
                                    Estoque: <span style="color: ${(variacao.estoque_fabrica || 0) > 0 ? '#46b450' : '#dc3232'};">${variacao.estoque_fabrica || 0} unidades</span>
                                </div>
                                <div>
                                    <strong>🏪 Destino:</strong><br>
                                    Preço: <span style="color: #0073aa; font-weight: bold;">R$ ${formatPrice(variacao.preco_destino)}</span><br>
                                    Estoque: <span style="color: ${(variacao.estoque_destino || 0) > 0 ? '#46b450' : '#dc3232'};">${variacao.estoque_destino || 0} unidades</span><br>
                                    <strong>💰 Vendas:</strong> <span style="color: #d63638; font-weight: bold; font-size: 16px;">${variacao.vendas || 0} unidades vendidas</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            detalhes += `</div>`;
        }
        
        conteudo.html(detalhes);
        modal.show().addClass("fade-in");
    }

    function testarConexaoProduto(produtoId) {
        const lojistaId = $("#lojista_destino").val();
        const btn = $(`.btn-testar-sync[data-id="${produtoId}"]`);
        
        // Desabilitar botão durante o teste
        btn.prop("disabled", true).text("🔄 Testando...");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_validate_lojista", // Usar ação existente
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId,
            produto_id: produtoId
        }, function(response) {
            if (response.success) {
                const data = response.data;
                let message = "✅ Conexão OK! Produto encontrado no destino:\n";
                message += `• ID Destino: ${data.id_destino || 'N/A'}\n`;
                message += `• Nome: ${data.nome || 'N/A'}\n`;
                message += `• SKU: ${data.sku || 'N/A'}\n`;
                message += `• Preço: R$ ${formatPrice(data.preco) || '0,00'}\n`;
                message += `• Estoque: ${data.estoque || '0'} unidades`;
                
                alert(message);
                
                // Opcional: Atualizar apenas os dados do produto na tabela sem recarregar tudo
                // loadProdutosSincronizados();
            } else {
                alert("❌ Erro no teste de conexão: " + response.data);
            }
        }).fail(function(xhr, status, error) {
            console.error('Erro no teste de conexão:', {xhr, status, error});
            alert("❌ Erro de comunicação com o servidor");
        }).always(function() {
            // Reabilitar botão
            btn.prop("disabled", false).text("� Testar Conexão");
        });
    }

    function syncVendas() {
        const lojistaId = $("#lojista_destino").val();
        if (!lojistaId) return;
        
        const btn = $("#btn-sincronizar-vendas");
        btn.prop("disabled", true).addClass("btn-loading");
        $("#status-sync").text("Sincronizando vendas...").addClass("status-loading");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_sync_vendas",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId
        }, function(response) {
            if (response.success) {
                $("#status-sync")
                    .text("✅ Vendas sincronizadas: " + response.data.message)
                    .removeClass("status-loading").addClass("status-success");
                loadProdutosSincronizados(); // Recarregar produtos
            } else {
                $("#status-sync")
                    .text("❌ Erro: " + response.data)
                    .removeClass("status-loading").addClass("status-error");
            }
        }).fail(function() {
            $("#status-sync")
                .text("❌ Erro de comunicação")
                .removeClass("status-loading").addClass("status-error");
        }).always(function() {
            btn.prop("disabled", false).removeClass("btn-loading");
        });
    }

    // === TESTE DE CONEXÃO === //
    function initConnectionTest() {
        $('#btn-test-connection').on('click', function() {
            const $btn = $(this);
            const lojistaId = $btn.data('lojista-id');
            const $status = $('#connection-status');
            
            if (!lojistaId) {
                $status.html('<span style="color: red;">❌ ID do lojista não encontrado</span>');
                return;
            }
            
            // Desabilitar botão e mostrar loading
            $btn.prop('disabled', true).text('🔄 Testando...');
            $status.html('<span style="color: #0073aa;">⏳ ' + SincronizadorWC.strings.validatingConnection + '</span>');
            
            // Fazer requisição AJAX
            $.ajax({
                url: SincronizadorWC.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sincronizador_wc_test_connection',
                    nonce: SincronizadorWC.nonce,
                    lojista_id: lojistaId
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                    } else {
                        $status.html('<span style="color: red;">❌ ' + response.data + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro na requisição:', error);
                    $status.html('<span style="color: red;">❌ Erro na comunicação: ' + error + '</span>');
                },
                complete: function() {
                    // Reabilitar botão
                    $btn.prop('disabled', false).text('🔄 Testar Conexão');
                }
            });
        });
    }

    // === EVENTOS GLOBAIS === //
    function initGlobalEvents() {
        // Fechar modais
        $(document).on("click", ".sincronizador-modal, #btn-fechar-modal, .modal-close", function(e) {
            if (e.target === this || $(e.target).hasClass('modal-close')) {
                $(this).closest('.sincronizador-modal').hide().removeClass("fade-in");
            }
        });
        
        // Fechar modais de relatório e progresso
        $(document).on('click', '[data-modal="relatorio"]', function(e) {
            e.preventDefault();
            $('#modal-relatorio').fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // ESC para fechar modais
        $(document).on("keyup", function(e) {
            if (e.keyCode === 27) { // ESC
                $(".sincronizador-modal, .modal-overlay").fadeOut(300, function() {
                    $(this).remove();
                });
            }
        });
        
        // === DELEGAÇÃO DE EVENTOS PARA BOTÕES === //
        
        // Botão testar conexão (lista de lojistas)
        $(document).on('click', '.btn-test-connection', function() {
            const lojistaId = $(this).data('lojista-id');
            if (lojistaId) {
                testarConexaoLojista(lojistaId, $(this));
            }
        });
        
        // Botão sincronizar (lista de lojistas)
        $(document).on('click', '.btn-sync', function(e) {
            e.preventDefault();
            const lojistaId = $(this).data('lojista-id');
            const lojistaName = $(this).closest('tr').find('td').first().text().trim();
            
            if (lojistaId) {
                executarSincronizacao(lojistaId, lojistaName);
            }
        });
        
        // Botão validar lojista (página de importação)
        $(document).on('click', '#btn-validar-lojista', function() {
            const lojistaId = $("#lojista_destino").val();
            if (!lojistaId) {
                alert("Selecione um lojista primeiro!");
                return;
            }
            
            // VALIDAÇÃO CRÍTICA: Verificar se o destino realmente existe antes de validar
            if (!validateDestinoExists(lojistaId)) {
                alert("❌ ERRO: O destino selecionado não existe ou não está configurado corretamente!");
                return;
            }
            
            validateLojista(lojistaId);
        });
        
        // Botão carregar produtos (página de importação)
        $(document).on('click', '#btn-carregar-produtos', function() {
            loadProdutos();
        });
        
        // Botão carregar sincronizados (página de produtos sincronizados)
        $(document).on('click', '#btn-carregar-sincronizados', function() {
            const lojistaId = $("#lojista_destino").val();
            
            if (!lojistaId) {
                alert("Selecione um lojista primeiro!");
                return;
            }
            
            if (!validateDestinoExists(lojistaId)) {
                alert("❌ ERRO: O destino selecionado não existe! Configure um lojista válido primeiro.");
                return;
            }
            
            loadProdutosSincronizados();
        });

        // Botão limpar cache (página de produtos sincronizados)
        $(document).on('click', '#btn-limpar-cache', function() {
            const lojistaId = $("#lojista_destino").val();
            
            if (!lojistaId) {
                alert("Selecione um lojista primeiro!");
                return;
            }
            
            const btn = $(this);
            const originalText = btn.text();
            
            btn.prop("disabled", true).text("🗑️ Limpando...");
            
            $.post(SincronizadorWC.ajaxurl, {
                action: "sincronizador_wc_clear_cache",
                nonce: SincronizadorWC.nonce,
                lojista_id: lojistaId
            })
            .done(function(response) {
                if (response.success) {
                    showNotice('✅ Cache limpo com sucesso! Os produtos serão recarregados.', 'success');
                    // Recarregar automaticamente os produtos
                    setTimeout(() => {
                        loadProdutosSincronizados();
                    }, 1000);
                } else {
                    showNotice('❌ Erro ao limpar cache: ' + (response.data || 'Erro desconhecido'), 'error');
                }
            })
            .fail(function(xhr, status, error) {
                showNotice('❌ Erro de comunicação: ' + error, 'error');
            })
            .always(function() {
                btn.prop("disabled", false).text(originalText);
            });
        });
        
        // Event listeners para sincronização (funcionam em qualquer página)
        $(document).on('click', '.btn-sincronizar', function(e) {
            e.preventDefault();
            const lojista = $(this).data('lojista');
            
            if (!lojista) {
                console.error('❌ ID do lojista não encontrado no botão');
                alert('❌ ERRO: ID do lojista não encontrado!');
                return;
            }
            
            executarSincronizacao(lojista);
        });
        
        // Event listeners para formulários de sincronização
        $(document).on('submit', 'form', function(e) {
            const action = $(this).find('input[name="action"]').val();
            
            if (action === 'sync_produtos') {
                e.preventDefault();
                const lojistaId = $(this).find('input[name="lojista_id"]').val();
                
                if (!lojistaId) {
                    alert('❌ ERRO: ID do lojista não encontrado no formulário!');
                    return;
                }
                
                executarSincronizacao(lojistaId);
            } else if (action === 'atualizar_lojista') {
                e.preventDefault();
                const lojistaId = $(this).find('input[name="lojista_id"]').val();
                
                if (!lojistaId) {
                    alert('❌ ERRO: ID do lojista não encontrado!');
                    return;
                }
                
                // BLOQUEAR AÇÃO DE ATUALIZAÇÃO AUTOMÁTICA
                const confirmacao = confirm('⚠️ ATENÇÃO: O botão "Atualizar" pode modificar dados no destino!\n\nEsta ação deveria apenas LER os dados do lojista.\n\nDeseja continuar mesmo assim? (Recomendamos cancelar e usar apenas as funções de leitura)');
                
                if (!confirmacao) {
                    return;
                }
                
                // Se o usuário insistir, permitir mas com aviso
                // Remover preventDefault() para deixar o formulário ser submetido normalmente
                // (o PHP vai processar)
            }
        });
        
        // Event listeners para teste de conexão
        $(document).on('click', '#btn-test-connection', function(e) {
            e.preventDefault();
            const lojistaId = $(this).data('lojista-id');
            
            if (lojistaId) {
                testConnection(lojistaId);
            } else {
                alert('❌ ERRO: ID do lojista não encontrado para teste de conexão!');
            }
        });
        
        // Mudança de seleção de lojista
        $(document).on('change', '#lojista_destino', function() {
            const selectedValue = $(this).val();
            const selectedText = $(this).find('option:selected').text();
            
            // Ativar botão validar quando lojista for selecionado
            const btnValidar = $("#btn-validar-lojista");
            if (selectedValue) {
                btnValidar.prop("disabled", false);
            } else {
                btnValidar.prop("disabled", true);
                $("#btn-carregar-produtos").prop("disabled", true);
                $("#validacao-status").hide();
            }
        });
        
        // Botão sincronizar vendas (página de produtos sincronizados)
        $(document).on('click', '#btn-sincronizar-vendas', function() {
            const lojistaId = $("#lojista_destino").val();
            
            if (!lojistaId) {
                alert("Selecione um lojista primeiro!");
                return;
            }
            
            if (!validateDestinoExists(lojistaId)) {
                alert("❌ ERRO: Não é possível sincronizar vendas! O destino não existe ou não está configurado.");
                return;
            }
            
            // Confirmar ação
            if (!confirm("Deseja sincronizar as vendas deste lojista? Esta operação pode demorar alguns minutos.")) {
                return;
            }
            
            syncVendas();
        });
        
        // Botão test connection (página de edição de lojista)
        $(document).on('click', '#btn-test-connection', function() {
            const lojistaId = $(this).data('lojista-id');
            if (!lojistaId) {
                $('#connection-status').html('<span style="color: red;">❌ ID do lojista não encontrado</span>');
                return;
            }
            testConnection(lojistaId);
        });
        
        // Change event para select de lojista
        $(document).on('change', '#lojista_destino', function() {
            const lojistaId = $(this).val();
            
            // Atualizar botões na página de importação
            $("#btn-validar-lojista").prop("disabled", !lojistaId);
            
            // Atualizar botões na página de sincronização
            $("#btn-carregar-sincronizados").prop("disabled", !lojistaId);
            $("#btn-sincronizar-vendas").prop("disabled", !lojistaId);
            
            if (!lojistaId) {
                resetImportPage();
                $("#tabela-sincronizados").hide();
            }
        });
    }

    // === TESTE DE CONEXÃO === //
    function testConnection(lojistaId) {
        const $btn = $('#btn-test-connection');
        const $status = $('#connection-status');
        
        if (!lojistaId) {
            $status.html('<span style="color: red;">❌ ID do lojista não encontrado</span>');
            return;
        }
        
        // Desabilitar botão e mostrar loading
        $btn.prop('disabled', true).text('🔄 Testando...');
        $status.html('<span style="color: #0073aa;">⏳ ' + (SincronizadorWC.strings ? SincronizadorWC.strings.validatingConnection : 'Testando conexão...') + '</span>');
        
        // Fazer requisição AJAX
        $.ajax({
            url: SincronizadorWC.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'sincronizador_wc_test_connection',
                nonce: SincronizadorWC.nonce,
                lojista_id: lojistaId
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                } else {
                    $status.html('<span style="color: red;">❌ ' + response.data + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Erro na requisição:', error);
                $status.html('<span style="color: red;">❌ Erro na comunicação: ' + error + '</span>');
            },
            complete: function() {
                // Reabilitar botão
                $btn.prop('disabled', false).text('🔄 Testar Conexão');
            }
        });
    }

    function initConnectionTest() {
        // A funcionalidade agora está na delegação de eventos globais
    }

    // === UTILITY FUNCTIONS === //
    function validateDestinoExists(lojistaId) {
        // Verificar se o select tem a opção selecionada
        const selectedOption = $("#lojista_destino option:selected");
        if (!selectedOption.length || selectedOption.val() === '') {
            return false;
        }
        
        // Verificar se o texto da opção contém informações válidas
        const optionText = selectedOption.text();
        if (!optionText || optionText.indexOf('http') === -1) {
            return false;
        }
        
        return true;
    }

    function formatPrice(price) {
        // Verificar se o preço é válido
        if (!price || price === '' || price === null || price === undefined) {
            return '0,00';
        }
        
        // Converter para string e limpar caracteres não numéricos (exceto ponto e vírgula)
        let priceStr = String(price).replace(/[^\d.,]/g, '');
        
        // Substituir vírgula por ponto para parseFloat
        priceStr = priceStr.replace(',', '.');
        
        // Converter para número
        const priceNum = parseFloat(priceStr);
        
        // Verificar se é um número válido
        if (isNaN(priceNum)) {
            return '0,00';
        }
        
        // Formatar com 2 casas decimais e trocar ponto por vírgula
        return priceNum.toFixed(2).replace('.', ',');
    }

    function showNotice(message, type = 'success') {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const notice = $(`
            <div class="notice ${noticeClass} is-dismissible fade-in">
                <p>${message}</p>
            </div>
        `);
        
        $('.sincronizador-wc-wrap').prepend(notice);
        
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // Sistema de progresso e sincronização
    function mostrarModalProgresso(lojistaName = 'Lojista') {
        // Primeiro, remover todos os modais existentes
        $('.modal-overlay, #modal-progresso').remove();
        
        const modal = `
            <div id="modal-progresso" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>🔄 Sincronizando ${lojistaName}</h3>
                    </div>
                    <div class="modal-body">
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progress-fill" style="width: 0%;"></div>
                            </div>
                            <div class="progress-text" id="progress-text">Iniciando sincronização...</div>
                            <div class="progress-percentage" id="progress-percentage">0%</div>
                        </div>
                        <div class="sync-details" id="sync-details">
                            <div class="detail-item">
                                <span>📦 Produtos encontrados:</span>
                                <span id="produtos-encontrados">0</span>
                            </div>
                            <div class="detail-item">
                                <span>✅ Produtos sincronizados:</span>
                                <span id="produtos-sincronizados">0</span>
                            </div>
                            <div class="detail-item">
                                <span>🆕 Produtos criados:</span>
                                <span id="produtos-criados">0</span>
                            </div>
                            <div class="detail-item">
                                <span>🔄 Produtos atualizados:</span>
                                <span id="produtos-atualizados">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modal);
        $('#modal-progresso').fadeIn(300);
    }
    
    function fecharModalProgresso() {
        // Remover todos os modais de progresso existentes imediatamente
        $('.modal-overlay, #modal-progresso').remove();
        
        // Garantir que não há modais restantes no DOM
        setTimeout(() => {
            $('[id*="modal-progresso"], .modal-overlay').remove();
        }, 100);
    }
    
    function atualizarProgresso(porcentagem, texto, detalhes) {
        $('#progress-fill').css('width', porcentagem + '%');
        $('#progress-text').text(texto);
        $('#progress-percentage').text(Math.floor(porcentagem) + '%');
        
        if (detalhes) {
            if (detalhes.encontrados !== undefined) {
                $('#produtos-encontrados').text(detalhes.encontrados);
            }
            if (detalhes.sincronizados !== undefined) {
                $('#produtos-sincronizados').text(detalhes.sincronizados);
            }
            if (detalhes.criados !== undefined) {
                $('#produtos-criados').text(detalhes.criados);
            }
            if (detalhes.atualizados !== undefined) {
                $('#produtos-atualizados').text(detalhes.atualizados);
            }
        }
    }
    
    // Função para atualizar "Última Sync" na tabela
    function atualizarUltimaSync(lojistaId) {
        const agora = new Date();
        const dataFormatada = agora.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        // Encontrar a linha do lojista e atualizar a coluna "Última Sync"
        const botaoSync = $(`.btn-sincronizar[data-lojista="${lojistaId}"]`);
        const linha = botaoSync.closest('tr');
        const colunaUltimaSync = linha.find('td').eq(3); // 4ª coluna (índice 3)
        
        if (colunaUltimaSync.length) {
            colunaUltimaSync.html(`<span style="color: #28a745; font-weight: bold;">✅ ${dataFormatada}</span>`);
        }
    }
    
    function mostrarRelatorioSync(dados) {
        // Remover modais de relatório existentes
        $('#modal-relatorio').remove();
        
        const relatorio = `
            <div id="modal-relatorio" class="modal-overlay" style="z-index: 999999 !important;">
                <div class="modal-content" style="max-width: 600px; margin: 50px auto; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                    <div class="modal-header" style="padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #0073aa;">📊 Relatório de Sincronização</h3>
                        <button class="modal-close" data-modal="close" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
                    </div>
                    <div class="modal-body" style="padding: 20px;">
                        <div class="relatorio-resumo" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px;">
                            <div class="resumo-item success" style="background: #d4edda; padding: 15px; border-radius: 5px; text-align: center; border-left: 4px solid #28a745;">
                                <span class="resumo-numero" style="display: block; font-size: 24px; font-weight: bold; color: #155724;">${dados.produtos_sincronizados || 0}</span>
                                <span class="resumo-label" style="font-size: 12px; color: #155724;">Produtos Sincronizados</span>
                            </div>
                            <div class="resumo-item info" style="background: #d1ecf1; padding: 15px; border-radius: 5px; text-align: center; border-left: 4px solid #17a2b8;">
                                <span class="resumo-numero" style="display: block; font-size: 24px; font-weight: bold; color: #0c5460;">${dados.produtos_criados || 0}</span>
                                <span class="resumo-label" style="font-size: 12px; color: #0c5460;">Produtos Criados</span>
                            </div>
                            <div class="resumo-item warning" style="background: #fff3cd; padding: 15px; border-radius: 5px; text-align: center; border-left: 4px solid #ffc107;">
                                <span class="resumo-numero" style="display: block; font-size: 24px; font-weight: bold; color: #856404;">${dados.produtos_atualizados || 0}</span>
                                <span class="resumo-label" style="font-size: 12px; color: #856404;">Produtos Atualizados</span>
                            </div>
                            <div class="resumo-item error" style="background: #f8d7da; padding: 15px; border-radius: 5px; text-align: center; border-left: 4px solid #dc3545;">
                                <span class="resumo-numero" style="display: block; font-size: 24px; font-weight: bold; color: #721c24;">${dados.erros || 0}</span>
                                <span class="resumo-label" style="font-size: 12px; color: #721c24;">Erros</span>
                            </div>
                        </div>
                        ${dados.detalhes ? '<div class="relatorio-detalhes" style="background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 300px; overflow-y: auto;">' + dados.detalhes + '</div>' : ''}
                        <div style="text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <button class="button button-primary" data-modal="close">✅ Fechar Relatório</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(relatorio);
        
        // Mostrar modal com animação
        $('#modal-relatorio').hide().fadeIn(500);
    }
    
    function executarSincronizacao(lojistaId) {
        // Verificar se já há uma sincronização em andamento
        if (window.syncInProgress) {
            return;
        }
        
        window.syncInProgress = true;
        
        // Limpar modais existentes completamente para evitar duplicatas
        limparModaisOrfaos();
        
        // Verificar se o lojista existe e tem API key
        const lojistaRow = $(`.btn-sincronizar[data-lojista="${lojistaId}"]`).closest('tr');
        const lojistaName = lojistaRow.find('td:first').text().trim();
        
        if (!lojistaName) {
            window.syncInProgress = false;
            alert('❌ Lojista não encontrado!');
            return;
        }
        
        // Primeiro verificar se o lojista tem configuração válida
        $.post(SincronizadorWC.ajaxurl, {
            action: 'verificar_lojista_config',
            lojista_id: lojistaId,
            nonce: SincronizadorWC.nonce
        })
        .done(function(validationResponse) {
            if (!validationResponse.success) {
                window.syncInProgress = false;
                alert('❌ ' + (validationResponse.data || 'Lojista não configurado corretamente'));
                return;
            }
            
            // Se chegou até aqui, pode prosseguir com a sincronização
            iniciarProcessoSincronizacao(lojistaId, lojistaName);
        })
        .fail(function(xhr, status, error) {
            window.syncInProgress = false;
            console.error('ERRO na validação do lojista:', {xhr, status, error});
            alert('❌ Erro ao verificar configuração do lojista. Verifique as configurações de API.');
        });
    }
    
    function iniciarProcessoSincronizacao(lojistaId, lojistaName) {
        // Garantir que não há modais duplicados
        limparModaisOrfaos();
        
        // Mostrar modal de progresso com informações do lojista
        mostrarModalProgresso(lojistaName);
        
        // Simular progresso em tempo real
        let progresso = 0;
        const progressInterval = setInterval(() => {
            progresso += Math.random() * 15;
            if (progresso > 90) progresso = 90; // Deixar 10% para o final real
            
            atualizarProgresso(progresso, `Sincronizando produtos do ${lojistaName}...`);
        }, 200);
        
        $.post(SincronizadorWC.ajaxurl, {
            action: 'sync_produtos',
            lojista_id: lojistaId,
            nonce: SincronizadorWC.nonce
        })
        .done(function(response) {
            clearInterval(progressInterval);
            
            // Completar progresso
            atualizarProgresso(100, 'Sincronização concluída!');
            
            // AGUARDAR APENAS 500ms para mostrar "100%" e então mostrar relatório
            setTimeout(() => {
                // Fechar modal de progresso
                fecharModalProgresso();
                
                if (response.success) {
                    // Atualizar "Última Sync" na tabela
                    atualizarUltimaSync(lojistaId);
                    
                    // Mostrar relatório sem fechar automaticamente
                    mostrarRelatorioSync(response.data);
                } else {
                    alert('❌ Erro na sincronização: ' + (response.data || 'Erro desconhecido'));
                }
                
                // Liberar flag de sincronização em andamento
                window.syncInProgress = false;
            }, 500);
        })
        .fail(function(xhr, status, error) {
            clearInterval(progressInterval);
            console.error('ERRO na sincronização AJAX:', {xhr, status, error});
            fecharModalProgresso();
            
            let errorMsg = '❌ Erro na sincronização.';
            if (xhr.responseText) {
                try {
                    const errorData = JSON.parse(xhr.responseText);
                    errorMsg += ' Detalhes: ' + (errorData.data || errorData.message || xhr.responseText);
                } catch(e) {
                    errorMsg += ' Resposta do servidor: ' + xhr.responseText.substring(0, 200);
                }
            }
            
            alert(errorMsg);
            
            // Liberar flag de sincronização em andamento
            window.syncInProgress = false;
        });
    }

    // Event listeners globais para controle de modais
    $(document).on('keydown', function(e) {
        // ESC para fechar modais - EXCETO modal de relatório
        if (e.keyCode === 27) {
            fecharModalProgresso();
            // NÃO fechar modal de relatório automaticamente
        }
    });
    
    // Clique fora do modal para fechar - APENAS para modal de progresso
    $(document).on('click', '.modal-overlay', function(e) {
        if (e.target === this && $(this).attr('id') !== 'modal-relatorio') {
            fecharModalProgresso();
        }
    });
    
    // Botão de fechar modal - ESPECÍFICO para cada tipo
    $(document).on('click', '[data-modal="close"]', function(e) {
        e.preventDefault();
        $('#modal-relatorio').fadeOut(300, function() {
            $(this).remove();
        });
    });

    // Expor funções globalmente se necessário
    window.SincronizadorWC.showNotice = showNotice;
    window.SincronizadorWC.formatPrice = formatPrice;
    window.SincronizadorWC.limparModaisOrfaos = limparModaisOrfaos;
    
    // Função específica para fechar modal de relatório apenas quando usuário clica
    window.fecharModalRelatorioManual = function() {
        $('#modal-relatorio').fadeOut(300, function() {
            $(this).remove();
        });
    };

})(jQuery);
