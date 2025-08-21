# Sincronizador WooCommerce - API Master

## Visão Geral
Esta API permite integrar e consultar dados de vendas, revendedores, produtos e estatísticas do ecossistema WooCommerce centralizado no Painel Master.

## Endpoints Principais

### 1. Fábrica Status
`GET /wp-json/sincronizador-wc/v1/master/fabrica-status`
- Retorna dados gerais da fábrica, estatísticas agregadas, top produtos e lista detalhada dos revendedores.

### 2. Revendedores Detalhado
`GET /wp-json/sincronizador-wc/v1/master/revendedores`
- Retorna lista detalhada dos revendedores, incluindo vendas, faturamento e produto mais vendido.

### 3. Produtos Mais Vendidos
`GET /wp-json/sincronizador-wc/v1/master/   `
- Retorna os produtos mais vendidos no período.

### 4. Health Check
`GET /wp-json/sincronizador-wc/v1/master/health`
- Retorna informações de status do sistema e ambiente.

## Estrutura dos Dados dos Revendedores
Cada revendedor retorna:
- `id`, `nome`, `url`, `status`, `ultima_sync`, `criado_em`
- `estatisticas_gerais`:
  - `total_vendas_mes`: Valor total vendido no mês
  - `total_pedidos_mes`: Total de pedidos no mês
  - `produtos_vendidos_mes`: Quantidade de produtos vendidos no mês
  - `total_vendas_historico`: Valor histórico (pode ser 0 se não implementado)
  - `status_vendas`: Contagem de pedidos por status (`pendentes`, `processando`, `concluídos`, `cancelados`, `reembolsados`)
  - `taxa_conversao`: Percentual de pedidos concluídos sobre o total
  - `cliente_fidelidade`: Percentual de clientes recorrentes
- `top_5_produtos`: Lista dos produtos mais vendidos do revendedor
- `ultimas_vendas`: Lista dos últimos pedidos (id, data, total, status, nome do cliente)

## Possibilidades de Implementação
- Gráficos de vendas: Use `total_vendas_mes`, `total_pedidos_mes` e `produtos_vendidos_mes` para gráficos de linha, barra ou pizza.
- Gráficos de status de pedidos: Monte gráficos de pizza ou barra com os dados de `status_vendas`.
- Taxa de conversão: Exiba como indicador ou gráfico de tendência.
- Fidelidade de clientes: Mostre evolução ou ranking de revendedores mais fiéis.
- Ranking de produtos: Use `top_5_produtos` para gráficos de barra ou listas.
- Histórico de vendas: Utilize `ultimas_vendas` para tabelas ou timelines.

## Limitações
- O campo `total_vendas_historico` pode retornar 0 se não houver implementação de histórico.
- Os dados dependem da sincronização correta entre as lojas e o painel master.
- O cálculo de fidelidade depende do preenchimento correto do e-mail do cliente nos pedidos.
- O endpoint pode ter limitação de performance para grandes volumes de dados.
- Os status de pedidos são baseados nos padrões do WooCommerce.

## Autenticação
- Para endpoints protegidos, utilize o token gerado via endpoint `/master/generate-token` (admin).
- Os endpoints retornam dados em JSON, prontos para consumo em dashboards, BI, ou integrações.
- Recomenda-se cachear resultados para evitar sobrecarga de requisições.

## Exemplo de Resposta (revendedor)
```json
{
  "id": 2,
  "nome": "TESTEE2",
  "url": "https://lojista.prometas.store",
  "status": "ativo",
  "ultima_sync": "2025-08-20 21:30:51",
  "criado_em": "2025-08-20 21:30:22",
  "estatisticas_gerais": {
    "total_vendas_mes": 1989.86,
    "total_pedidos_mes": 10,
    "produtos_vendidos_mes": 13,
    "total_vendas_historico": 0,
    "status_vendas": {
      "vendas_pendentes": 0,
      "vendas_processando": 0,
      "vendas_concluidas": 0,
      "vendas_canceladas": 0,
      "vendas_reembolsadas": 0
    },
    "taxa_conversao": "40.0%",
    "cliente_fidelidade": "0%"
  },
  "top_5_produtos": [ ... ],
  "ultimas_vendas": [ ... ]
}
```

---

Para dúvidas ou sugestões, consulte o desenvolvedor do plugin ou abra uma issue no repositório.
