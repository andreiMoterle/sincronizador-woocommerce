/**
 * ARQUIVO OBSOLETO - admin.js
 * 
 * ⚠️ AVISO DE MIGRAÇÃO ⚠️
 * 
 * Este arquivo foi marcado como OBSOLETO durante a refatoração para eliminar duplicações.
 * Todas as funcionalidades foram migradas para:
 * 
 * 1. sincronizador-utils.js - Utilitários centralizados (testConnection, showNotice, etc.)
 * 2. admin-scripts.js - Funcionalidades específicas do admin (refatorado)
 * 
 * DUPLICAÇÕES ELIMINADAS:
 * - testConnection() - Agora em sincronizador-utils.js
 * - showNotice() - Agora em sincronizador-utils.js  
 * - deleteLojista() - Agora em sincronizador-utils.js
 * - forceSync() - Agora em sincronizador-utils.js
 * - Event handlers - Centralizados em sincronizador-utils.js
 * 
 * STATUS: Mantido apenas para compatibilidade. 
 * RECOMENDAÇÃO: Não adicionar código novo aqui.
 * 
 * @deprecated Usar window.SincronizadorWC.Utils.* para novas funcionalidades
 * @see admin/js/sincronizador-utils.js
 * @see admin/js/admin-scripts.js
 */

// Verificar se utilitários foram carregados
if (typeof window.SincronizadorWC !== 'undefined' && window.SincronizadorWC.Utils) {
    console.log('✅ Utilitários centralizados carregados. admin.js é obsoleto.');
} else {
    console.warn('⚠️ Utilitários centralizados não encontrados!');
}