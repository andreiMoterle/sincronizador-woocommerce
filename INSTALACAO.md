# 🚀 Guia de Instalação Rápida - Sincronizador WC

## ⚡ Instalação em 5 Passos

### 1. Upload dos Arquivos
```bash
# Copie toda a pasta sincronizador-woocommerce para:
wp-content/plugins/sincronizador-woocommerce/
```

### 2. Ativar Plugin
- Acesse **Plugins** no painel WordPress
- Encontre **"Sincronizador WooCommerce Fábrica-Lojista"**
- Clique em **"Ativar"**

### 3. Configurar Performance (Recomendado)
Adicione ao `wp-config.php`:
```php
// Performance para grandes volumes
define('SINCRONIZADOR_WC_LARGE_DATASET', true);
define('SINCRONIZADOR_WC_BATCH_SIZE', 50);
define('WP_MEMORY_LIMIT', '512M');
```

### 4. Gerar Token Master
- Acesse **Sincronizador WC → Configurações**
- Clique em **"Gerar Novo Token"**
- Copie o token gerado

### 5. Testar API Master
```bash
curl -H "Authorization: Bearer SEU_TOKEN" \
     https://suafabrica.com/wp-json/sincronizador-wc/v1/master/fabrica-status
```

## 🔧 Resolução de Problemas

### Erro de Memória
```php
// wp-config.php
define('WP_MEMORY_LIMIT', '512M');
ini_set('memory_limit', '512M');
```

### Timeout
```php
// wp-config.php  
define('WP_MAX_EXECUTION_TIME', 300);
ini_set('max_execution_time', 300);
```

### Cache não Funciona
1. Verificar permissões de escrita
2. Instalar Redis/Memcached (recomendado)
3. Ativar cache nas configurações

## ✅ Verificação de Funcionamento

1. **Menu Admin**: Deve aparecer "Sincronizador WC" no menu
2. **Dashboard**: Acesse e veja as métricas
3. **API Master**: Teste o endpoint de status
4. **Token**: Confirme que foi gerado

## 📞 Suporte

Se algo não funcionar:
1. Verifique se WooCommerce está ativo
2. Confirme versão PHP 7.4+
3. Teste o arquivo `test-plugin.php`
4. Ative WP_DEBUG para ver erros

**Plugin 100% funcional e pronto para produção!** 🎉
