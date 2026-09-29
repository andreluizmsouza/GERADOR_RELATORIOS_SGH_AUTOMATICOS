# Elógica Report Service

## Contexto
Sistema de relatórios assistido por IA para bancos SQL Server legados (SGH/FCVS, ElogicaRH)
da Elógica Processamento de Dados. Clientes: órgãos públicos de habitação (CEHAB-RJ,
COHAB-SP, COHAB-MG, AGEHAB etc.). Os bancos são legados, com poucas FKs declaradas e
nomenclatura abreviada (ex.: nr_contrato, vl_sld_dev, dt_base).

Objetivo:
1. Extrair metadados do SQL Server e gerar um dicionário de dados documentado (com revisão humana).
2. Gerar definições de relatório (JSON) a partir de pedidos em linguagem natural, usando o dicionário.
3. Executar essas definições de forma determinística e exportar em HTML, PDF, XLSX e CSV.

A IA NUNCA executa SQL diretamente em produção. Ela gera definições; um humano valida e salva;
o motor PHP executa.

## Stack
- **web/** PHP 8.2+, IIS, extensão sqlsrv (PDO_SQLSRV). Sem framework pesado; usar Composer com
  dependências mínimas: mpdf/mpdf, phpoffice/phpspreadsheet, vlucas/phpdotenv.
  Front: HTML + Bootstrap 5 + DataTables + Chart.js.
- **worker/** Python 3.11+, FastAPI, sqlglot (dialeto tsql), pyodbc, anthropic SDK.
  Responsável por: perfilar colunas, chamar a API de IA e validar SQL.
- **db/** scripts T-SQL do banco de controle próprio (ReportService), separado dos bancos de clientes.
- A comunicação PHP → worker é via HTTP interno (localhost), com token em header.

## Estrutura do repositório
/web
  /public          index.php, assets
  /src
    /Metadata      leitura de schema, tela de revisão do dicionário
    /Reports       CRUD de definições, execução, parâmetros
    /Export        Html, Pdf, Xlsx, Csv
    /Db            conexões (controle vs. clientes), somente leitura nos clientes
    /Auth          login e permissões por cliente
  /templates
/worker
  /app
    metadata.py    extração e perfil (count, distinct, % nulos, min/max; NUNCA linhas)
    describe.py    sugestão de descrições de campos via IA
    relations.py   inferência de relacionamentos por nome e tipo de coluna
    generate.py    linguagem natural → definição JSON
    validate.py    validação da definição com sqlglot
/db
  /migrations
/docs

## Banco de controle (ReportService)
Tabelas mínimas:
- conexao (id, cliente, servidor, banco, usuario_readonly, ativo)
- dic_tabela (id, conexao_id, schema, tabela, descricao, status_revisao, revisado_por, revisado_em)
- dic_coluna (id, dic_tabela_id, coluna, tipo, nulo, descricao, nome_negocio, sinonimos, sensivel BIT, status_revisao)
- dic_relacao (id, origem_coluna_id, destino_coluna_id, origem ENUM('fk','inferida','manual'), confirmada BIT)
- relatorio (id, conexao_id, titulo, definicao_json, versao, status ENUM('rascunho','aprovado','inativo'), criado_por, aprovado_por)
- execucao_log (id, relatorio_id, usuario, parametros_json, linhas, duracao_ms, erro, dt)

Status de revisão: 'sugerido_ia' | 'revisado' | 'rejeitado'.

## Formato da definição de relatório
{
  "titulo": "string",
  "descricao": "string",
  "parametros": [{"nome": "dt_base", "rotulo": "Data-base", "tipo": "date|int|string|lista", "obrigatorio": true, "opcoes_sql": null}],
  "sql": "SELECT ... WHERE x = :param",
  "colunas": [{"campo": "vl_sld_dev", "rotulo": "Saldo devedor", "formato": "moeda|numero|data|texto|cpf", "total": "soma|media|contagem|null", "sensivel": false}],
  "agrupamento": ["campo"],
  "ordenacao": [{"campo": "x", "direcao": "asc"}],
  "grafico": {"tipo": "barra|linha|pizza|null", "x": "campo", "y": "campo"},
  "limite_linhas": 50000
}
Validar contra um JSON Schema em /docs/report-definition.schema.json.

## Regras de segurança (obrigatórias)
- Conexões com bancos de clientes usam SEMPRE login somente leitura (db_datareader ou GRANT SELECT em views).
- O SQL da definição precisa passar no validate.py antes de salvar:
  - uma única instrução, somente SELECT (CTEs permitidas);
  - proibido: INSERT/UPDATE/DELETE/MERGE/EXEC/DDL/SELECT INTO/OPENROWSET/OPENQUERY/linked servers/xp_;
  - só tabelas e views presentes no dicionário da conexão;
  - parâmetros apenas como :nome, sempre bindados no PHP e nunca concatenados.
- A execução usa QueryTimeout configurável (padrão 60s) e respeita limite_linhas.
- Para a API de IA vão SOMENTE metadados e estatísticas agregadas. Nunca enviar linhas reais,
  CPF, nomes, endereços ou valores individuais (LGPD).
- Colunas marcadas como sensivel = 1 são mascaradas na exportação, salvo permissão específica.
- Segredos ficam em .env (nunca commitados); .env.example documenta as variáveis.
- A chave e o modelo da API de IA são configuráveis via .env (ANTHROPIC_API_KEY, AI_MODEL).

## Convenções de código
- PHP: PSR-12, strict_types, tipagem em tudo, prepared statements sempre. Mensagens ao usuário em PT-BR.
- Python: type hints, pydantic para os modelos, ruff para lint.
- SQL: palavras-chave em maiúsculas, schema explícito (dbo.tabela), sem SELECT *.
- Commits em português, imperativo curto: "Adiciona exportação XLSX".
- Não adicionar dependência nova sem justificar no PR.

## Fases
1. Banco de controle + cadastro de conexões + extrator de metadados (FKs reais).
2. Perfil de colunas, sugestão de descrições e inferência de relações via IA, com tela de revisão.
3. Exportação do dicionário (HTML/PDF/XLSX) e gravação opcional em MS_Description.
4. Gerador de definição JSON + validador + tela de teste com pré-visualização (TOP 100).
5. Motor de execução, parâmetros e exportações.
6. Catálogo de relatórios, versionamento, permissões por cliente e log de execução.

## Como trabalhar neste repositório
- Antes de alterar, ler os arquivos envolvidos e manter o padrão existente.
- Mudanças pequenas e focadas; não refatorar o que não foi pedido.
- Toda alteração no validador de SQL precisa de teste novo em worker/tests.
- Ao criar tabela ou coluna no banco de controle, gerar migration numerada em /db/migrations.
- Se algo contrariar as regras de segurança, parar e apontar o problema em vez de implementar.
- Ao terminar, resumir o que mudou e como testar.
