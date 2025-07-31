/**
 * JavaScript do Admin - Sincronizador WooCommerce
 * @version 1.1.0
 */

(function($) {
    'use strict';

    // Variáveis globais
    window.SincronizadorWC = {
        ajaxurl: ajaxurl || '',
        nonce: '',
        currentImportId: null,
        progressInterval: null,
        produtosSincronizados: []
    };

    $(document).ready(function() {
        initSincronizador();
    });

    function initSincronizador() {
        // Inicializar diferentes páginas
        if ($('#lojista_destino').length) {
            initImportPage();
        }
        
        if ($('#tabela-sincronizados').length) {
            initSyncPage();
        }
        
        // Inicializar teste de conexão
        if ($('#btn-test-connection').length) {
            initConnectionTest();
        }
        
        // Eventos globais
        initGlobalEvents();
    }

    // === PÁGINA DE IMPORTAÇÃO === //
    function initImportPage() {
        let currentImportId = null;
        let progressInterval = null;

        // Habilitar botão quando lojista selecionado
        $("#lojista_destino").on("change", function() {
            const lojistaId = $(this).val();
            $("#btn-validar-lojista").prop("disabled", !lojistaId);
            
            if (!lojistaId) {
                resetImportPage();
            }
        });

        // Validar lojista
        $("#btn-validar-lojista").on("click", function() {
            const lojistaId = $("#lojista_destino").val();
            if (!lojistaId) {
                alert("Selecione um lojista primeiro!");
                return;
            }
            
            validateLojista(lojistaId);
        });

        // Carregar produtos
        $("#btn-carregar-produtos").on("click", function() {
            loadProdutos();
        });

        // Iniciar importação
        $("#btn-iniciar-importacao").on("click", function() {
            startImport();
        });

        // Eventos de produtos
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
        grid.empty();
        
        if (produtos.length === 0) {
            grid.html('<p class="text-center">Nenhum produto encontrado</p>');
            return;
        }
        
        produtos.forEach(function(produto) {
            const isDisabled = produto.status !== "ativo";
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
                        <p><strong>Preço:</strong> R$ ${formatPrice(produto.preco)}</p>
                        <p><strong>Estoque:</strong> ${produto.estoque} unidades</p>
                        <span class="produto-status status-${produto.status}">${produto.status}</span>
                    </div>
                </div>
            `);
            
            grid.append(card);
        });
        
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
        // Habilitar botões quando lojista selecionado
        $("#lojista_destino").on("change", function() {
            const lojistaId = $(this).val();
            $("#btn-carregar-sincronizados").prop("disabled", !lojistaId);
            $("#btn-sincronizar-vendas").prop("disabled", !lojistaId);
            
            if (!lojistaId) {
                $("#tabela-sincronizados").hide();
            }
        });
        
        // Carregar produtos sincronizados
        $("#btn-carregar-sincronizados").on("click", function() {
            loadProdutosSincronizados();
        });
        
        // Sincronizar vendas
        $("#btn-sincronizar-vendas").on("click", function() {
            syncVendas();
        });
        
        // Busca
        $("#buscar-sincronizado").on("input", function() {
            const termo = this.value.toLowerCase();
            const produtosFiltrados = SincronizadorWC.produtosSincronizados.filter(produto => 
                produto.nome.toLowerCase().includes(termo) || 
                produto.sku.toLowerCase().includes(termo)
            );
            renderProdutosSincronizados(produtosFiltrados);
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
    }

    // === UTILITY FUNCTIONS === //
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

    // Expor funções globalmente se necessário
    window.SincronizadorWC.showNotice = showNotice;
    window.SincronizadorWC.formatPrice = formatPrice;

})(jQuery);
