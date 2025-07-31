# 🎯 Como Usar o Sincronizador WooCommerce - Moterle Andrei

## ✅ PLUGIN ATIVADO COM SUCESSO! 

**Autor:** Moterle Andrei  
**Versão:** 1.1.0 (Compatível com HPOS)

---

## 📍 ONDE ENCONTRAR AS CONFIGURAÇÕES

### 1. **No Painel WordPress:**
- Vá para o **menu lateral esquerdo**
- Procure por **"Sincronizador WC"** (ícone de atualização 🔄)
- Clique para abrir o menu

### 2. **Páginas Disponíveis:**
- **📊 Dashboard** - Visão geral e estatísticas
- **👥 Lojistas** - Adicionar/gerenciar lojistas  
- **📦 Importar** - Importação de produtos
- **📈 Relatórios** - Relatórios de vendas
- **⚙️ Configurações** - Token Master e configurações
- **📋 Logs** - Logs do sistema

---

## 🚀 PRIMEIROS PASSOS

### Passo 1: Configure um Token Master
1. Vá em **Sincronizador WC → Configurações**
2. Seção **"Token Master"**
3. Clique em **"Gerar Novo Token"**
4. Copie o token gerado

### Passo 2: Adicione um Lojista
1. Vá em **Sincronizador WC → Lojistas**
2. Clique em **"Adicionar Novo Lojista"**
3. Preencha:
   - **Nome da Loja**: Ex: "Loja João Silva"
   - **URL da Loja**: Ex: "https://lojajoao.com.br"
   - **Consumer Key**: (da API WooCommerce do lojista)
   - **Consumer Secret**: (da API WooCommerce do lojista)

### Passo 3: Teste a Conexão
1. Na lista de lojistas, clique em **"Testar Conexão"**
2. Verifique se aparece **✅ Conectado**

---

## ⚠️ MENU NÃO APARECE? SIGA ESTES PASSOS:

### 🔧 **Solução Rápida:**

1. **Desative o plugin:**
   - Vá em **Plugins → Plugins Instalados**
   - Encontre "Sincronizador WooCommerce Fábrica-Lojista"
   - Clique em **"Desativar"**

2. **Ative novamente:**
   - Clique em **"Ativar"**
   - Agora o menu deve aparecer!

3. **Se ainda não aparecer:**
   - Verifique se você é **Administrador**
   - Verifique se o **WooCommerce está ativo**
   - Limpe o cache do navegador (**Ctrl+F5**)

### 📍 **Onde deve aparecer o menu:**
- **Local:** Menu lateral esquerdo do WordPress
- **Nome:** "Sincronizador WC" 
- **Ícone:** 🔄 (ícone de atualização)
- **Entre:** WooCommerce e outros menus

---

## ⚠️ AVISO DE COMPATIBILIDADE RESOLVIDO

A mensagem sobre **"Armazenamento de pedidos de alto desempenho"** foi resolvida!

✅ **O plugin agora é totalmente compatível com HPOS (WooCommerce 8.2+)**

Se ainda aparecer o aviso, desative e ative o plugin novamente.

---

## 🔌 API MASTER - Para Integração Externa

**Endpoint:** `https://seusite.com.br/wp-json/sincronizador-wc/v1/master/fabrica-status`  
**Autenticação:** `Authorization: Bearer SEU_TOKEN_AQUI`

---

## 📞 SUPORTE

Se precisar de ajuda:
1. Verifique os **Logs** em: **Sincronizador WC → Logs**
2. Consulte o arquivo **INSTALACAO.md** para troubleshooting

---

**🎉 Seu plugin está funcionando perfeitamente!**
