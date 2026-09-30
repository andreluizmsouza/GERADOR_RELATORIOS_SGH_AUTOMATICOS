-- 003_relacionamentos.sql
-- Relacionamentos entre TABELAS (o "N" aponta para o "1"), cada um com um ou mais "papéis";
-- cada papel é um conjunto de pares de colunas que só fazem sentido juntos (chave composta).
-- Ex.: MTTBCON -> MTTBSE1 tem 4 papéis (adquirente 1 a 4), cada um com CODEMP + CPF.
-- Substitui dic_relacao (coluna a coluna), cujos dados são convertidos.

IF OBJECT_ID(N'dbo.dic_relacao_tabela', N'U') IS NULL
CREATE TABLE dbo.dic_relacao_tabela (
    id                INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_relacao_tabela PRIMARY KEY,
    origem_tabela_id  INT            NOT NULL,   -- lado N (quem carrega a chave estrangeira)
    destino_tabela_id INT            NOT NULL,   -- lado 1
    tipo              VARCHAR(10)    NOT NULL,   -- fk | doc | chave | nome | manual
    estado            VARCHAR(10)    NOT NULL CONSTRAINT DF_dic_rel_estado DEFAULT ('sugerida'),
    confianca         VARCHAR(5)     NOT NULL CONSTRAINT DF_dic_rel_conf DEFAULT ('alta'),
    evidencia         NVARCHAR(500)  NOT NULL,
    aviso             NVARCHAR(500)  NULL,
    criado_por        INT            NULL,
    criado_em         DATETIME2(0)   NOT NULL CONSTRAINT DF_dic_rel_criado DEFAULT (SYSDATETIME()),
    decidido_por      INT            NULL,
    decidido_em       DATETIME2(0)   NULL,
    CONSTRAINT FK_dic_rel_origem  FOREIGN KEY (origem_tabela_id)  REFERENCES dbo.dic_tabela (id),
    CONSTRAINT FK_dic_rel_destino FOREIGN KEY (destino_tabela_id) REFERENCES dbo.dic_tabela (id),
    CONSTRAINT FK_dic_rel_criador FOREIGN KEY (criado_por)   REFERENCES dbo.usuario (id),
    CONSTRAINT FK_dic_rel_decisor FOREIGN KEY (decidido_por) REFERENCES dbo.usuario (id),
    CONSTRAINT UQ_dic_relacao_tabela UNIQUE (origem_tabela_id, destino_tabela_id, tipo),
    CONSTRAINT CK_dic_rel_tipo   CHECK (tipo IN ('fk', 'doc', 'chave', 'nome', 'manual')),
    CONSTRAINT CK_dic_rel_estado CHECK (estado IN ('sugerida', 'confirmada', 'rejeitada')),
    CONSTRAINT CK_dic_rel_conf   CHECK (confianca IN ('alta', 'media', 'baixa')),
    CONSTRAINT CK_dic_rel_dif    CHECK (origem_tabela_id <> destino_tabela_id)
);
GO

IF OBJECT_ID(N'dbo.dic_relacao_papel', N'U') IS NULL
CREATE TABLE dbo.dic_relacao_papel (
    id          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_relacao_papel PRIMARY KEY,
    relacao_id  INT            NOT NULL,
    nome        NVARCHAR(200)  NOT NULL,
    fonte       NVARCHAR(500)  NULL,
    CONSTRAINT FK_dic_papel_relacao FOREIGN KEY (relacao_id) REFERENCES dbo.dic_relacao_tabela (id) ON DELETE CASCADE,
    CONSTRAINT UQ_dic_relacao_papel UNIQUE (relacao_id, nome)
);
GO

IF OBJECT_ID(N'dbo.dic_relacao_par', N'U') IS NULL
CREATE TABLE dbo.dic_relacao_par (
    id                 INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_relacao_par PRIMARY KEY,
    papel_id           INT NOT NULL,
    ordem              INT NOT NULL CONSTRAINT DF_dic_par_ordem DEFAULT (0),
    origem_coluna_id   INT NOT NULL,
    destino_coluna_id  INT NOT NULL,
    CONSTRAINT FK_dic_par_papel   FOREIGN KEY (papel_id) REFERENCES dbo.dic_relacao_papel (id) ON DELETE CASCADE,
    CONSTRAINT FK_dic_par_origem  FOREIGN KEY (origem_coluna_id)  REFERENCES dbo.dic_coluna (id),
    CONSTRAINT FK_dic_par_destino FOREIGN KEY (destino_coluna_id) REFERENCES dbo.dic_coluna (id),
    CONSTRAINT UQ_dic_relacao_par UNIQUE (papel_id, origem_coluna_id, destino_coluna_id)
);
GO

-- Tabelas-mãe da chave composta (ex.: MTTBCON), separadas por vírgula.
IF NOT EXISTS (SELECT 1 FROM dbo.dic_config WHERE chave = 'tabelas_mae')
    INSERT INTO dbo.dic_config (chave, valor) VALUES ('tabelas_mae', N'MTTBCON');
GO

-- Converte o modelo anterior (coluna a coluna) e o remove.
IF OBJECT_ID(N'dbo.dic_relacao', N'U') IS NOT NULL
    INSERT INTO dbo.dic_relacao_tabela (origem_tabela_id, destino_tabela_id, tipo, estado, confianca, evidencia)
    SELECT co.dic_tabela_id, cd.dic_tabela_id,
           CASE r.origem WHEN 'fk' THEN 'fk' WHEN 'manual' THEN 'manual' ELSE 'nome' END,
           CASE WHEN MIN(CAST(r.confirmada AS INT)) = 1 THEN 'confirmada' ELSE 'sugerida' END,
           'alta', N'Migrada do modelo anterior.'
    FROM dbo.dic_relacao r
    JOIN dbo.dic_coluna co ON co.id = r.origem_coluna_id
    JOIN dbo.dic_coluna cd ON cd.id = r.destino_coluna_id
    WHERE co.dic_tabela_id <> cd.dic_tabela_id
    GROUP BY co.dic_tabela_id, cd.dic_tabela_id, CASE r.origem WHEN 'fk' THEN 'fk' WHEN 'manual' THEN 'manual' ELSE 'nome' END;
GO

IF OBJECT_ID(N'dbo.dic_relacao', N'U') IS NOT NULL
    INSERT INTO dbo.dic_relacao_papel (relacao_id, nome, fonte)
    SELECT x.id, N'Migrado', N'Modelo anterior' FROM dbo.dic_relacao_tabela x
    WHERE x.evidencia = N'Migrada do modelo anterior.'
      AND NOT EXISTS (SELECT 1 FROM dbo.dic_relacao_papel p WHERE p.relacao_id = x.id);
GO

IF OBJECT_ID(N'dbo.dic_relacao', N'U') IS NOT NULL
    INSERT INTO dbo.dic_relacao_par (papel_id, ordem, origem_coluna_id, destino_coluna_id)
    SELECT p.id, 0, r.origem_coluna_id, r.destino_coluna_id
    FROM dbo.dic_relacao r
    JOIN dbo.dic_coluna co ON co.id = r.origem_coluna_id
    JOIN dbo.dic_coluna cd ON cd.id = r.destino_coluna_id
    JOIN dbo.dic_relacao_tabela t ON t.origem_tabela_id = co.dic_tabela_id AND t.destino_tabela_id = cd.dic_tabela_id
         AND t.tipo = CASE r.origem WHEN 'fk' THEN 'fk' WHEN 'manual' THEN 'manual' ELSE 'nome' END
    JOIN dbo.dic_relacao_papel p ON p.relacao_id = t.id AND p.nome = N'Migrado'
    WHERE co.dic_tabela_id <> cd.dic_tabela_id
      AND NOT EXISTS (SELECT 1 FROM dbo.dic_relacao_par q WHERE q.papel_id = p.id AND q.origem_coluna_id = r.origem_coluna_id AND q.destino_coluna_id = r.destino_coluna_id);
GO

IF OBJECT_ID(N'dbo.dic_relacao', N'U') IS NOT NULL
    DROP TABLE dbo.dic_relacao;
GO
