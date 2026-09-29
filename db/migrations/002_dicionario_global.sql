-- 002_dicionario_global.sql
-- Os bancos de todos os clientes têm a mesma estrutura: o dicionário passa a ser único (matriz),
-- sem vínculo com conexão. O prefixo e as exclusões passam a valer para o dicionário inteiro.
-- A conexão do cliente é usada apenas na execução dos relatórios (e na leitura de metadados de referência).

-- 1) Escopo global do dicionário
IF OBJECT_ID(N'dbo.dic_config', N'U') IS NULL
CREATE TABLE dbo.dic_config (
    chave  VARCHAR(50)    NOT NULL CONSTRAINT PK_dic_config PRIMARY KEY,
    valor  NVARCHAR(500)  NOT NULL
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.dic_config WHERE chave = 'prefixo')
    INSERT INTO dbo.dic_config (chave, valor) VALUES ('prefixo', N'MTTB');
GO

IF OBJECT_ID(N'dbo.dic_exclusao', N'U') IS NULL
CREATE TABLE dbo.dic_exclusao (
    id      INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_exclusao PRIMARY KEY,
    padrao  VARCHAR(128)      NOT NULL,   -- padrão LIKE do T-SQL
    CONSTRAINT UQ_dic_exclusao UNIQUE (padrao)
);
GO

-- Aproveita exclusões já cadastradas por conexão; sem elas, usa o padrão.
IF OBJECT_ID(N'dbo.conexao_exclusao', N'U') IS NOT NULL
    INSERT INTO dbo.dic_exclusao (padrao)
    SELECT DISTINCT e.padrao FROM dbo.conexao_exclusao e
    WHERE NOT EXISTS (SELECT 1 FROM dbo.dic_exclusao d WHERE d.padrao = e.padrao);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.dic_exclusao)
    INSERT INTO dbo.dic_exclusao (padrao) VALUES
        ('%[_]BKP%'), ('%[_]OLD'), ('%[_]ANT'), ('%[_]ALT'), ('%[_]CTRL'),
        ('%[_]LIMPEZA'), ('%[_]PENDENCIAS'), ('%[_]20[0-9][0-9]%'), ('MTTBCOB'), ('MTTBEXC');
GO

-- 2) dic_tabela deixa de pertencer a uma conexão
IF COL_LENGTH('dbo.dic_tabela', 'conexao_id') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM dbo.dic_tabela GROUP BY [schema], tabela HAVING COUNT(*) > 1)
        THROW 50001, 'dic_tabela tem tabelas repetidas entre conexões: unifique antes de aplicar a migration 002.', 1;

    ALTER TABLE dbo.dic_tabela DROP CONSTRAINT FK_dic_tabela_conexao;
    ALTER TABLE dbo.dic_tabela DROP CONSTRAINT UQ_dic_tabela;
    ALTER TABLE dbo.dic_tabela DROP COLUMN conexao_id;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = 'UQ_dic_tabela' AND parent_object_id = OBJECT_ID('dbo.dic_tabela'))
    ALTER TABLE dbo.dic_tabela ADD CONSTRAINT UQ_dic_tabela UNIQUE ([schema], tabela);
GO

-- 3) conexao: sem prefixo nem exclusões (agora globais)
IF OBJECT_ID(N'dbo.conexao_exclusao', N'U') IS NOT NULL
    DROP TABLE dbo.conexao_exclusao;
GO

IF COL_LENGTH('dbo.conexao', 'filtro_prefixo') IS NOT NULL
BEGIN
    ALTER TABLE dbo.conexao DROP CONSTRAINT DF_conexao_prefixo;
    ALTER TABLE dbo.conexao DROP COLUMN filtro_prefixo;
END
GO
