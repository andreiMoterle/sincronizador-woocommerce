<?php
/**
 * View do Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Dashboard - Sincronizador WooCommerce', 'sincronizador-wc'); ?></h1>
    
    <div class="sincronizador-dashboard">
        <!-- Cards de Estatísticas -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="dashicons dashicons-groups"></span>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($stats['lojistas_ativos'] ?? 0); ?></h3>
                    <p><?php _e('Lojistas Ativos', 'sincronizador-wc'); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="dashicons dashicons-products"></span>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($stats['produtos_sincronizados'] ?? 0); ?></h3>
                    <p><?php _e('Produtos Sincronizados', 'sincronizador-wc'); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="dashicons dashicons-chart-area"></span>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($stats['vendas_mes_atual'] ?? 0); ?></h3>
                    <p><?php _e('Vendas Este Mês', 'sincronizador-wc'); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="stat-content">
                    <h3>R$ <?php echo number_format($stats['valor_vendas_mes_atual'] ?? 0, 2, ',', '.'); ?></h3>
                    <p><?php _e('Faturamento Este Mês', 'sincronizador-wc'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Ações Rápidas -->
        <div class="dashboard-actions">
            <h2><?php _e('Ações Rápidas', 'sincronizador-wc'); ?></h2>
            
            <div class="actions-grid">
                <div class="action-card">
                    <h3><?php _e('Adicionar Lojista', 'sincronizador-wc'); ?></h3>
                    <p><?php _e('Cadastre um novo lojista para começar a sincronização', 'sincronizador-wc'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=sincronizador-wc-lojistas'); ?>" class="button button-primary">
                        <?php _e('Gerenciar Lojistas', 'sincronizador-wc'); ?>
                    </a>
                </div>
                
                <div class="action-card">
                    <h3><?php _e('Importar Produtos', 'sincronizador-wc'); ?></h3>
                    <p><?php _e('Envie produtos para seus lojistas de forma individual', 'sincronizador-wc'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=sincronizador-wc-importar'); ?>" class="button button-primary">
                        <?php _e('Importar Agora', 'sincronizador-wc'); ?>
                    </a>
                </div>
                
                <div class="action-card">
                    <h3><?php _e('Sincronizar Dados', 'sincronizador-wc'); ?></h3>
                    <p><?php _e('Force uma sincronização manual dos dados de vendas', 'sincronizador-wc'); ?></p>
                    <button id="force-sync" class="button button-secondary">
                        <?php _e('Sincronizar Agora', 'sincronizador-wc'); ?>
                    </button>
                </div>
                
                <div class="action-card">
                    <h3><?php _e('Ver Relatórios', 'sincronizador-wc'); ?></h3>
                    <p><?php _e('Acompanhe o desempenho de vendas por lojista', 'sincronizador-wc'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=sincronizador-wc-relatorios'); ?>" class="button button-secondary">
                        <?php _e('Ver Relatórios', 'sincronizador-wc'); ?>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Últimas Atividades -->
        <div class="dashboard-recent">
            <h2><?php _e('Últimas Atividades', 'sincronizador-wc'); ?></h2>
            
            <div class="recent-activities">
                <?php 
                $sync_manager = new Sincronizador_WC_Sync_Manager();
                $recent_logs = $sync_manager->get_sync_logs(null, 10);
                
                if (!empty($recent_logs)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Data/Hora', 'sincronizador-wc'); ?></th>
                                <th><?php _e('Lojista', 'sincronizador-wc'); ?></th>
                                <th><?php _e('Ação', 'sincronizador-wc'); ?></th>
                                <th><?php _e('Tipo', 'sincronizador-wc'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->data_criacao))); ?></td>
                                    <td><?php echo esc_html($log->lojista_nome ?? __('Sistema', 'sincronizador-wc')); ?></td>
                                    <td><?php echo esc_html($log->acao); ?></td>
                                    <td>
                                        <span class="log-type log-type-<?php echo esc_attr($log->tipo); ?>">
                                            <?php echo esc_html(ucfirst($log->tipo)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p class="view-all-logs">
                        <a href="<?php echo admin_url('admin.php?page=sincronizador-wc-logs'); ?>">
                            <?php _e('Ver todos os logs', 'sincronizador-wc'); ?>
                        </a>
                    </p>
                <?php else: ?>
                    <p><?php _e('Nenhuma atividade registrada ainda.', 'sincronizador-wc'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.sincronizador-dashboard {
    max-width: 1200px;
}

.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.stat-icon {
    margin-right: 15px;
}

.stat-icon .dashicons {
    font-size: 40px;
    color: #0073aa;
}

.stat-content h3 {
    margin: 0 0 5px 0;
    font-size: 32px;
    font-weight: 600;
    color: #23282d;
}

.stat-content p {
    margin: 0;
    color: #646970;
    font-size: 14px;
}

.dashboard-actions {
    margin-bottom: 30px;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.action-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.action-card h3 {
    margin-top: 0;
    color: #23282d;
}

.action-card p {
    color: #646970;
    margin-bottom: 15px;
}

.dashboard-recent {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.log-type {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.log-type-info {
    background: #d1ecf1;
    color: #0c5460;
}

.log-type-sincronizacao {
    background: #d4edda;
    color: #155724;
}

.log-type-importacao {
    background: #fff3cd;
    color: #856404;
}

.log-type-erro {
    background: #f8d7da;
    color: #721c24;
}

.view-all-logs {
    text-align: center;
    margin-top: 15px;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#force-sync').click(function() {
        var button = $(this);
        var originalText = button.text();
        
        button.text('<?php _e('Sincronizando...', 'sincronizador-wc'); ?>').prop('disabled', true);
        
        $.ajax({
            url: sincronizador_wc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sincronizador_wc_force_sync',
                nonce: sincronizador_wc_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('<?php _e('Erro na comunicação', 'sincronizador-wc'); ?>');
            },
            complete: function() {
                button.text(originalText).prop('disabled', false);
            }
        });
    });
});
</script>
