# 🚀 Sincronizador WooCommerce Fábrica-Lojista

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)
![Version](https://img.shields.io/badge/Version-1.1.0-green.svg)

## 📋 Descrição

Plugin profissional para WordPress que permite a sincronização completa de produtos entre uma loja **fábrica** e múltiplas **lojistas** via API REST do WooCommerce. Desenvolvido para otimizar a gestão de inventário e vendas em redes de distribuição.

## ✨ Principais Funcionalidades

### 🏭 **Gestão de Fábrica**
- ✅ Dashboard completo com visão geral do sistema
- ✅ Listagem de todos os produtos disponíveis para sincronização
- ✅ Seleção múltipla de produtos com filtros avançados
- ✅ Importação em lote com feedback em tempo real

### 🏪 **Gestão de Lojistas**
- ✅ Cadastro e gerenciamento de lojistas com credenciais API
- ✅ Validação automática de conectividade
- ✅ Histórico completo de sincronizações
- ✅ Monitoramento de status de cada lojista

### 📦 **Sincronização Inteligente**
- ✅ Importação completa de produtos (dados, imagens, variações)
- ✅ Verificação de duplicatas por SKU
- ✅ Atualização automática de produtos existentes
- ✅ Sincronização de dados de vendas
- ✅ Relatórios detalhados de importação

### 📊 **Monitoramento Avançado**
- ✅ **Página dedicada para produtos sincronizados**
- ✅ Visualização comparativa: Fábrica vs Lojista
- ✅ Dados de vendas em tempo real
- ✅ Teste individual de sincronização
- ✅ Histórico de modificações e erros

## 🛠️ Instalação

### Pré-requisitos
- WordPress 5.0 ou superior
- WooCommerce 5.0 ou superior
- PHP 7.4 ou superior
- Acesso às credenciais da API REST do WooCommerce de cada lojista

### Passos de Instalação

1. **Faça o download do plugin**

2. **Envie para o WordPress**
   - Faça upload da pasta `sincronizador-woocommerce` para `/wp-content/plugins/`
   - Ou faça upload do arquivo ZIP via admin do WordPress

3. **Ative o plugin**
   - Vá em `Plugins` → `Plugins Instalados`
   - Clique em `Ativar` no "Sincronizador WooCommerce"

4. **Configure o menu**
   - O menu **"Sincronizador WC"** aparecerá no admin do WordPress
   - Acesse `Sincronizador WC` → `Dashboard` para confirmar a instalação

## 🔧 Configuração

### 1. **Configurar Credenciais API**

Acesse `Sincronizador WC` → `Configurações`:

- **Token Master**: Gere um token para autenticação
- **Cache**: Configure tempo de cache para performance
- **Logs**: Ative logs detalhados para debug

### 2. **Cadastrar Lojistas**

Acesse `Sincronizador WC` → `Lojistas` → `Adicionar Novo`:

```
Nome do Lojista: Loja Exemplo LTDA
URL da Loja: https://loja-exemplo.com.br
Consumer Key: ck_1234567890abcdef...
Consumer Secret: cs_abcdef1234567890...
Status: Ativo
```

> **💡 Dica:** As credenciais API são obtidas em `WooCommerce` → `Configurações` → `Avançado` → `API REST` na loja de destino.

### 3. **Testar Conectividade**

- Vá em `Sincronizador WC` → `Lojistas`
- Clique no botão **"🔄 Sincronizar"** ao lado do lojista
- Confirme se o status está **"✅ Conectado"**

## 🚀 Como Usar

### **Processo de Importação**

1. **Acesse a página de importação**
   ```
   Sincronizador WC → Importar Produtos
   ```

2. **Selecione o lojista de destino**
   - Escolha na lista de lojistas cadastrados
   - Clique em **"🔍 Validar Conexão"**

3. **Carregue os produtos da fábrica**
   - Clique em **"📋 Carregar Produtos"**
   - Visualize todos os produtos disponíveis

4. **Configure as opções de importação**
   - ☑️ **Incluir Variações**: Importa todas as variações do produto
   - ☑️ **Incluir Imagens**: Copia imagens da galeria
   - ☑️ **Manter Preços**: Preserva preços originais da fábrica

5. **Selecione produtos e execute**
   - Marque os produtos desejados
   - Clique em **"🚀 Iniciar Importação"**
   - Acompanhe o progresso em tempo real

### **Monitoramento de Produtos Sincronizados**

1. **Acesse a página de monitoramento**
   ```
   Sincronizador WC → Produtos Sincronizados
   ```

2. **Selecione o lojista**
   - Escolha o lojista para visualizar produtos
   - Clique em **"📊 Carregar Produtos"**

3. **Visualize informações detalhadas**
   - **Foto**: Miniatura do produto
   - **ID Fábrica**: ID do produto na loja principal
   - **Nome**: Nome completo do produto
   - **ID Destino**: ID do produto na loja de destino
   - **Status**: Status da sincronização (sincronizado/erro)
   - **Vendas**: Quantidade total vendida no lojista

4. **Ações disponíveis**
   - **👁️ Ver**: Detalhes completos do produto
   - **🔄 Testar**: Teste individual de sincronização
   - **🔄 Sincronizar Vendas**: Atualiza dados de vendas de todos os produtos

## 📊 Funcionalidades Detalhadas

### **Dashboard Principal**
- Status geral do sistema
- Informações sobre WooCommerce
- Links rápidos para todas as funcionalidades
- Estatísticas de uso

### **Gestão de Lojistas**
- Lista completa de lojistas cadastrados
- Status de conectividade em tempo real
- Botões de ação: Sincronizar, Atualizar, Editar
- Histórico de operações

### **Importação de Produtos**
- Interface visual com cards de produtos
- Filtros por categoria e status
- Busca por nome ou SKU
- Seleção múltipla inteligente
- Progresso de importação em tempo real

### **Produtos Sincronizados**
- Tabela comparativa completa
- Dados da fábrica vs lojista
- Informações de vendas atualizadas
- Testes individuais de sincronização
- Detalhes expandidos por produto

### **Sistema de Logs**
- Histórico completo de operações
- Registros de sucessos e erros
- Filtragem por data e lojista
- Exportação de relatórios

## 🔐 Segurança

- ✅ **Nonces CSRF**: Proteção contra ataques CSRF
- ✅ **Capabilities**: Controle de permissões do WordPress
- ✅ **Sanitização**: Todos os dados são sanitizados
- ✅ **Validação**: Validação rigorosa de entradas
- ✅ **HTTPS**: Comunicação criptografada entre lojas

## 🏗️ Arquitetura Técnica

### **Estrutura de Arquivos**
```
sincronizador-woocommerce/
├── admin/                     # Interfaces administrativas
│   └── class-admin-menu.php
├── api/                       # Handlers de API
│   └── class-master-api.php
├── config/                    # Configurações
│   └── database-schema.sql
├── includes/                  # Classes principais
│   ├── class-activator.php
│   ├── class-deactivator.php
│   ├── class-database.php
│   └── class-product-importer.php
├── sincronizador-woocommerce.php  # Arquivo principal
├── README.md                  # Este arquivo
├── INSTALACAO.md             # Instruções detalhadas
└── COMO-USAR.md              # Guia de uso
```

### **Classes Principais**

#### `Sincronizador_WooCommerce` (Principal)
- Inicialização do plugin
- Gerenciamento de menus
- Handlers AJAX
- Coordenação entre componentes

#### `Sincronizador_WC_Product_Importer`
- Lógica de importação de produtos
- Comunicação com APIs WooCommerce
- Validação de dados
- Tratamento de erros

#### `Sincronizador_WC_Database`
- Gerenciamento de dados
- Histórico de operações
- Cache de informações
- Otimizações de performance

### **Fluxo de Dados**

1. **Validação de Lojista**
   ```
   Frontend → AJAX → validate_lojista() → API WooCommerce → Resposta
   ```

2. **Importação de Produtos**
   ```
   Seleção → Validação → Preparação → Envio API → Confirmação → Log
   ```

3. **Sincronização de Vendas**
   ```
   Produtos Sincronizados → API Reports → Atualização → Cache → Interface
   ```

## 🔧 Personalização

### **Hooks Disponíveis**

```php
// Antes da importação de um produto
do_action('sincronizador_wc_before_import_product', $produto, $lojista);

// Após importação bem-sucedida
do_action('sincronizador_wc_after_import_success', $produto, $lojista, $resultado);

// Em caso de erro na importação
do_action('sincronizador_wc_import_error', $produto, $lojista, $erro);
```

### **Filtros Disponíveis**

```php
// Modificar dados do produto antes do envio
$dados = apply_filters('sincronizador_wc_product_data', $dados, $produto);

// Personalizar opções de importação
$opcoes = apply_filters('sincronizador_wc_import_options', $opcoes);

// Customizar validação de lojista
$validacao = apply_filters('sincronizador_wc_validate_lojista', $validacao, $lojista);
```

## 🐛 Solução de Problemas

### **Problemas Comuns**

#### ❌ **"Plugin não aparece no menu"**
- **Causa**: Usuário sem permissão `manage_woocommerce`
- **Solução**: Verificar permissões do usuário ou usar conta Administrator

#### ❌ **"Erro de conexão com lojista"**
- **Causa**: Credenciais API incorretas ou URL inválida
- **Solução**: Verificar Consumer Key/Secret e testar URL manualmente

#### ❌ **"Produtos não carregam"**
- **Causa**: Produtos sem SKU ou não publicados
- **Solução**: Verificar se produtos têm SKU válido e status "Publicado"

#### ❌ **"Erro de timeout na importação"**
- **Causa**: Muitos produtos ou conexão lenta
- **Solução**: Importar em lotes menores ou aumentar timeout

### **Debug e Logs**

Ative logs detalhados em `Configurações`:

```php
// Verificar logs do WordPress
tail -f /wp-content/debug.log

// Logs específicos do plugin
Sincronizador WC → Configurações → Ativar Logs Detalhados
```

## 📈 Performance

### **Otimizações Implementadas**
- ✅ Cache inteligente de dados de produtos
- ✅ Limitação de produtos por página (50 itens)
- ✅ Timeouts otimizados para APIs
- ✅ Processamento em lote eficiente
- ✅ Cleanup automático de logs antigos

### **Recomendações**
- Use PHP 8.0+ para melhor performance
- Configure cache Redis/Memcached se disponível
- Monitore logs de erro regularmente
- Execute importações fora do horário de pico

## 🤝 Suporte

### **Reportar Problemas**
- Abra uma issue no GitHub com logs detalhados
- Inclua versões do WordPress, WooCommerce e PHP
- Descreva passos para reproduzir o problema

### **Solicitações de Funcionalidade**
- Envie sugestões via GitHub Issues
- Descreva o caso de uso detalhadamente
- Inclua mockups se possível

## 📋 Changelog

### **v1.1.0** (Atual)
- ✨ **NOVO**: Página de produtos sincronizados
- ✨ **NOVO**: Sincronização de dados de vendas
- ✨ **NOVO**: Teste individual de produtos
- ✨ **NOVO**: Interface visual melhorada
- 🐛 **CORREÇÃO**: Sistema de importação simplificado
- 🐛 **CORREÇÃO**: Validação de lojistas aprimorada
- 🔧 **MELHORIA**: Performance otimizada

### **v1.0.0**
- 🚀 **LANÇAMENTO**: Versão inicial
- ✅ **RECURSO**: Importação básica de produtos
- ✅ **RECURSO**: Gestão de lojistas
- ✅ **RECURSO**: Sistema de configurações

## 📄 Licença

Este projeto está licenciado sob a [GPL v2 ou posterior](https://www.gnu.org/licenses/gpl-2.0.html).

## 👨‍💻 Desenvolvimento

**Desenvolvido por:** Moterle Andrei  
**Versão:** 1.1.0  
**Compatibilidade:** WordPress 5.0+, WooCommerce 5.0+, PHP 7.4+

---

## 🎯 Casos de Uso

### **Fábrica de Roupas → Múltiplas Lojas**
```
Fábrica (Produtos Master) → Loja A, Loja B, Loja C
• Sincronização automática de novos produtos
• Controle centralizado de preços e estoque
• Relatórios de vendas consolidados
```

### **Distribuidora → Revendedores**
```
Distribuidora → Revendedor 1, Revendedor 2...
• Produtos com diferentes margens por revendedor
• Sincronização de disponibilidade
• Monitoramento de performance de vendas
```

### **Franquia → Franqueados**
```
Franqueador → Franqueado A, Franqueado B...
• Padronização de catálogo
• Controle de compliance
• Análise comparativa de vendas
```

---

**🚀 Pronto para revolucionar sua gestão de produtos WooCommerce!**

Para suporte técnico ou dúvidas, consulte a documentação completa ou abra uma issue no repositório.
