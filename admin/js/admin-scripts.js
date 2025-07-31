/**
 * JavaScript do Admin - Sincronizador WooCommerce
 * @version 1.1.0
 */

(function($) {
    'use strict';

    // Variáveis globais
    window.SincronizadorWC = window.SincronizadorWC || {
        ajaxurl: (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
        nonce: '',
        currentImportId: null,
        progressInterval: null,
        produtosSincronizados: []
    };

    $(document).ready(function() {
        console.log('=== SINCRONIZADOR WC DEBUG ===');
        console.log('SincronizadorWC object:', window.SincronizadorWC);
        console.log('SincronizadorWC carregado:', typeof SincronizadorWC !== 'undefined');
        console.log('jQuery carregado:', typeof $ !== 'undefined');
        console.log('AJAX URL:', window.SincronizadorWC.ajaxurl);
        console.log('Nonce:', window.SincronizadorWC.nonce);
        
        // Verificar se existe o nonce
        if (!window.SincronizadorWC.nonce || window.SincronizadorWC.nonce === '') {
            console.warn('⚠️ NONCE NÃO DEFINIDO! Os requests AJAX podem falhar.');
            
            // Tentar obter nonce de outro lugar se possível
            const metaNonce = $('meta[name="sincronizador-wc-nonce"]').attr('content');
            if (metaNonce) {
                window.SincronizadorWC.nonce = metaNonce;
                console.log('✅ Nonce encontrado em meta tag:', metaNonce);
            }
        }
        
        console.log('Elementos encontrados:', {
            lojista_destino: $('#lojista_destino').length,
            btn_validar: $('#btn-validar-lojista').length,
            btn_carregar: $('#btn-carregar-produtos').length,
            btn_carregar_sync: $('#btn-carregar-sincronizados').length,
            btn_test_connection: $('#btn-test-connection').length,
            tabela_sincronizados: $('#tabela-sincronizados').length
        });
        
        console.log('=== INICIANDO SINCRONIZADOR ===');
        initSincronizador();
    });

    function initSincronizador() {
        // Detectar qual página estamos
        const currentPage = detectCurrentPage();
        console.log('📍 Página atual detectada:', currentPage);
        
        // Inicializar baseado na página
        switch(currentPage) {
            case 'importar':
                console.log('📦 Inicializando página de importação');
                if ($('#lojista_destino').length) {
                    initImportPage();
                }
                break;
            case 'sincronizados':
                console.log('📊 Inicializando página de produtos sincronizados');
                if ($('#tabela-sincronizados').length) {
                    initSyncPage();
                }
                break;
            case 'lojistas':
                console.log('👥 Inicializando página de lojistas');
                initLojistasPage();
                break;
            case 'add-lojista':
                console.log('➕ Inicializando página de adicionar lojista');
                if ($('#btn-test-connection').length) {
                    initConnectionTest();
                }
                break;
            default:
                console.log('🏠 Página padrão ou dashboard');
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
        console.log('👥 Configurando página de lojistas...');
        
        // Contar lojistas na página
        const totalLojistas = $('table.wp-list-table tbody tr').length;
        console.log('📊 Total de lojistas encontrados:', totalLojistas);
        
        // Adicionar data-lojista aos botões de sincronização
        $('form input[name="action"][value="sync_produtos"]').each(function() {
            const form = $(this).closest('form');
            const lojistaId = form.find('input[name="lojista_id"]').val();
            const submitBtn = form.find('button[type="submit"]');
            
            if (lojistaId && submitBtn.length) {
                submitBtn.addClass('btn-sincronizar').attr('data-lojista', lojistaId);
                console.log('✅ Botão configurado para lojista:', lojistaId);
            }
        });
    }

    // === PÁGINA DE IMPORTAÇÃO === //
    function initImportPage() {
        console.log('📦 Inicializando página de importação');
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
        console.log('📊 Inicializando página de produtos sincronizados');
        
        // Os eventos agora são tratados na função initGlobalEvents()
        // Apenas configurações específicas aqui
        
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
        
        const btn = $("#btn-carregar-sincronizados");
        btn.prop("disabled", true).addClass("btn-loading");
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_get_produtos_sincronizados",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId
        }, function(response) {
            if (response.success) {
                SincronizadorWC.produtosSincronizados = response.data;
                renderProdutosSincronizados(response.data);
                $("#tabela-sincronizados").show().addClass("fade-in");
                $("#total-produtos").text(`(${response.data.length} produtos)`);
            } else {
                alert("Erro ao carregar produtos: " + response.data);
            }
        }).fail(function() {
            alert("Erro de comunicação com o servidor");
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
            const vendasText = produto.vendas !== null ? produto.vendas : "N/A";
            
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
                        <small>SKU: ${produto.sku}</small>
                    </td>
                    <td><strong>${produto.id_destino || "N/A"}</strong></td>
                    <td><span class="produto-status ${statusClass}">${produto.status}</span></td>
                    <td><strong>${vendasText}</strong></td>
                    <td>
                        <button type="button" class="button button-small btn-ver-detalhes" 
                                data-produto='${JSON.stringify(produto)}'>👁️ Ver</button>
                        <button type="button" class="button button-small btn-testar-sync" 
                                data-id="${produto.id_fabrica}">🔄 Testar</button>
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
            mostrarDetalhes(produto);
        });
        
        $(".btn-testar-sync").off("click").on("click", function() {
            const produtoId = $(this).attr("data-id");
            testarSincronizacao(produtoId);
        });
    }

    function mostrarDetalhes(produto) {
        const modal = $("#modal-detalhes");
        const titulo = $("#modal-titulo");
        const conteudo = $("#modal-conteudo");
        
        titulo.text("Detalhes: " + produto.nome);
        
        const detalhes = `
            <table class="form-table">
                <tr><th>ID Fábrica:</th><td>${produto.id_fabrica}</td></tr>
                <tr><th>ID Destino:</th><td>${produto.id_destino || "N/A"}</td></tr>
                <tr><th>SKU:</th><td>${produto.sku}</td></tr>
                <tr><th>Status:</th><td><span class="produto-status ${produto.status === "sincronizado" ? "status-ativo" : "status-erro"}">${produto.status}</span></td></tr>
                <tr><th>Preço Fábrica:</th><td>R$ ${formatPrice(produto.preco_fabrica || 0)}</td></tr>
                <tr><th>Preço Destino:</th><td>R$ ${formatPrice(produto.preco_destino || 0)}</td></tr>
                <tr><th>Estoque Fábrica:</th><td>${produto.estoque_fabrica || 0}</td></tr>
                <tr><th>Estoque Destino:</th><td>${produto.estoque_destino || 0}</td></tr>
                <tr><th>Vendas:</th><td>${produto.vendas !== null ? produto.vendas : "N/A"}</td></tr>
                <tr><th>Última Sincronização:</th><td>${produto.ultima_sync || "Nunca"}</td></tr>
            </table>
        `;
        
        conteudo.html(detalhes);
        modal.show().addClass("fade-in");
    }

    function testarSincronizacao(produtoId) {
        const lojistaId = $("#lojista_destino").val();
        
        $.post(SincronizadorWC.ajaxurl, {
            action: "sincronizador_wc_testar_sync_produto",
            nonce: SincronizadorWC.nonce,
            lojista_id: lojistaId,
            produto_id: produtoId
        }, function(response) {
            if (response.success) {
                alert("✅ Teste de sincronização bem-sucedido: " + response.data.message);
                loadProdutosSincronizados(); // Recarregar
            } else {
                alert("❌ Erro no teste: " + response.data);
            }
        }).fail(function() {
            alert("Erro de comunicação com o servidor");
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
        
        // ESC para fechar modais
        $(document).on("keyup", function(e) {
            if (e.keyCode === 27) { // ESC
                $(".sincronizador-modal").hide().removeClass("fade-in");
            }
        });
        
        // === DELEGAÇÃO DE EVENTOS PARA BOTÕES === //
        
        // Botão validar lojista (página de importação)
        $(document).on('click', '#btn-validar-lojista', function() {
            console.log('🔍 Clique no botão validar lojista');
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
            console.log('📋 Clique no botão carregar produtos');
            loadProdutos();
        });
        
        // Botão carregar sincronizados (página de produtos sincronizados)
        $(document).on('click', '#btn-carregar-sincronizados', function() {
            console.log('📊 Clique no botão carregar sincronizados');
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
        
        // Event listeners para sincronização (funcionam em qualquer página)
        $(document).on('click', '.btn-sincronizar', function(e) {
            e.preventDefault();
            const lojista = $(this).data('lojista');
            console.log('🔄 Sincronizar clicado para lojista:', lojista);
            
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
                console.log('🔄 Formulário de sincronização submetido para lojista:', lojistaId);
                
                if (!lojistaId) {
                    alert('❌ ERRO: ID do lojista não encontrado no formulário!');
                    return;
                }
                
                executarSincronizacao(lojistaId);
            }
        });
        
        // Event listeners para teste de conexão
        $(document).on('click', '#btn-test-connection', function(e) {
            e.preventDefault();
            const lojistaId = $(this).data('lojista-id');
            console.log('🔍 Testar conexão para lojista:', lojistaId);
            
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
            console.log('🎯 Lojista selecionado:', selectedValue, '-', selectedText);
            
            // Ativar botão validar quando lojista for selecionado
            const btnValidar = $("#btn-validar-lojista");
            if (selectedValue) {
                btnValidar.prop("disabled", false);
                console.log('✅ Destino validado:', {id: selectedValue, text: selectedText});
            } else {
                btnValidar.prop("disabled", true);
                $("#btn-carregar-produtos").prop("disabled", true);
                $("#validacao-status").hide();
            }
        });
        
        // Botão sincronizar vendas (página de produtos sincronizados)
        $(document).on('click', '#btn-sincronizar-vendas', function() {
            console.log('🔄 Clique no botão sincronizar vendas');
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
            console.log('🔄 Clique no botão test connection');
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
            console.log('🎯 Lojista selecionado:', lojistaId);
            
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
                console.log('Resposta do teste de conexão:', response);
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
        console.log('🔧 Inicializando teste de conexão');
        // A funcionalidade agora está na delegação de eventos globais
    }

    // === UTILITY FUNCTIONS === //
    function validateDestinoExists(lojistaId) {
        // Verificar se o select tem a opção selecionada
        const selectedOption = $("#lojista_destino option:selected");
        if (!selectedOption.length || selectedOption.val() === '') {
            console.error('❌ Nenhuma opção válida selecionada');
            return false;
        }
        
        // Verificar se o texto da opção contém informações válidas
        const optionText = selectedOption.text();
        if (!optionText || optionText.indexOf('http') === -1) {
            console.error('❌ URL do destino não encontrada na opção selecionada');
            return false;
        }
        
        console.log('✅ Destino validado:', {
            id: lojistaId,
            text: optionText
        });
        
        return true;
    }

    function formatPrice(price) {
        return parseFloat(price || 0).toFixed(2).replace(".", ",");
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
        $('#modal-progresso').fadeOut(300, function() {
            $(this).remove();
        });
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
            console.log(`✅ Atualizada "Última Sync" para lojista ${lojistaId}: ${dataFormatada}`);
        }
    }
    
    function mostrarRelatorioSync(dados) {
        const relatorio = `
            <div id="modal-relatorio" class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Relatório de Sincronização</h3>
                        <button class="modal-close" onclick="$('#modal-relatorio').fadeOut(300, function(){ $(this).remove(); })">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="relatorio-resumo">
                            <div class="resumo-item success">
                                <span class="resumo-numero">${dados.produtos_sincronizados}</span>
                                <span class="resumo-label">Produtos Sincronizados</span>
                            </div>
                            <div class="resumo-item info">
                                <span class="resumo-numero">${dados.produtos_criados || 0}</span>
                                <span class="resumo-label">Produtos Criados</span>
                            </div>
                            <div class="resumo-item warning">
                                <span class="resumo-numero">${dados.produtos_atualizados || 0}</span>
                                <span class="resumo-label">Produtos Atualizados</span>
                            </div>
                            <div class="resumo-item error">
                                <span class="resumo-numero">${dados.erros || 0}</span>
                                <span class="resumo-label">Erros</span>
                            </div>
                        </div>
                        ${dados.detalhes ? '<div class="relatorio-detalhes">' + dados.detalhes + '</div>' : ''}
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(relatorio);
        $('#modal-relatorio').fadeIn(300);
    }
    
    // Event listeners para sincronização
    $(document).on('click', '.btn-sincronizar', function(e) {
        e.preventDefault();
        const lojista = $(this).data('lojista');
        console.log('DEBUG: Sincronizar clicado para lojista:', lojista);
        
        if (!lojista) {
            console.error('ERRO: ID do lojista não encontrado');
            return;
        }
        
        // Mostrar modal de progresso
        mostrarModalProgresso();
        
        // Executar sincronização real
        executarSincronizacao(lojista);
    });
    
    function executarSincronizacao(lojistaId) {
        console.log('🚀 Iniciando sincronização para lojista:', lojistaId);
        
        // Verificar se o lojista existe e tem API key
        const lojistaRow = $(`.btn-sincronizar[data-lojista="${lojistaId}"]`).closest('tr');
        const lojistaName = lojistaRow.find('td:first').text().trim();
        
        if (!lojistaName) {
            alert('❌ Lojista não encontrado!');
            return;
        }
        
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
            action: 'sincronizar_produtos',
            lojista_id: lojistaId,
            nonce: SincronizadorWC.nonce
        })
        .done(function(response) {
            clearInterval(progressInterval);
            console.log('DEBUG: Resposta da sincronização:', response);
            
            // Completar progresso
            atualizarProgresso(100, 'Sincronização concluída!');
            
            setTimeout(() => {
                // Fechar modal de progresso
                fecharModalProgresso();
                
                if (response.success) {
                    // Atualizar "Última Sync" na tabela
                    atualizarUltimaSync(lojistaId);
                    
                    mostrarRelatorioSync(response.data);
                    
                    // Não recarregar a página automaticamente
                    console.log('✅ Sincronização concluída com sucesso!');
                } else {
                    alert('❌ Erro na sincronização: ' + (response.data || 'Erro desconhecido'));
                }
            }, 1000);
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
        });
    }

    // Expor funções globalmente se necessário
    window.SincronizadorWC.showNotice = showNotice;
    window.SincronizadorWC.formatPrice = formatPrice;

})(jQuery);
