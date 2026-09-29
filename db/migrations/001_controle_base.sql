-- 001_controle_base.sql
-- Banco de controle ReportService: usuários, conexões de clientes e dicionário de dados.
-- Executar no banco ReportService (já criado). Idempotente para reexecução.

IF OBJECT_ID(N'dbo.usuario', N'U') IS NULL
CREATE TABLE dbo.usuario (
    id          INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_usuario PRIMARY KEY,
    login       VARCHAR(60)   NOT NULL,
    nome        NVARCHAR(120) NOT NULL,
    senha_hash  VARCHAR(255)  NOT NULL,
    perfil      VARCHAR(10)   NOT NULL CONSTRAINT DF_usuario_perfil DEFAULT ('consulta'),
    ativo       BIT           NOT NULL CONSTRAINT DF_usuario_ativo DEFAULT (1),
    criado_em   DATETIME2(0)  NOT NULL CONSTRAINT DF_usuario_criado DEFAULT (SYSDATETIME()),
    CONSTRAINT UQ_usuario_login UNIQUE (login),
    CONSTRAINT CK_usuario_perfil CHECK (perfil IN ('admin', 'consulta'))
);
GO

-- Um registro por cliente. O slug identifica o cliente na URL (relatorios-<slug>.elogica.info).
IF OBJECT_ID(N'dbo.conexao', N'U') IS NULL
CREATE TABLE dbo.conexao (
    id               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_conexao PRIMARY KEY,
    slug             VARCHAR(60)    NOT NULL,
    cliente          NVARCHAR(120)  NOT NULL,
    servidor         VARCHAR(200)   NOT NULL,
    banco            VARCHAR(128)   NOT NULL,
    usuario_readonly VARCHAR(128)   NOT NULL,
    senha_cripto     VARCHAR(1000)  NOT NULL,  -- libsodium secretbox em base64; chave em APP_KEY
    filtro_prefixo   VARCHAR(30)    NOT NULL CONSTRAINT DF_conexao_prefixo DEFAULT ('MTTB'),
    ativo            BIT            NOT NULL CONSTRAINT DF_conexao_ativo DEFAULT (1),
    criado_em        DATETIME2(0)   NOT NULL CONSTRAINT DF_conexao_criado DEFAULT (SYSDATETIME()),
    CONSTRAINT UQ_conexao_slug UNIQUE (slug),
    CONSTRAINT CK_conexao_slug CHECK (slug NOT LIKE '%[^a-z0-9-]%' AND LEN(slug) >= 2)
);
GO

-- Padrões LIKE (T-SQL) de tabelas a ignorar na sincronização, por conexão.
IF OBJECT_ID(N'dbo.conexao_exclusao', N'U') IS NULL
CREATE TABLE dbo.conexao_exclusao (
    id         INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_conexao_exclusao PRIMARY KEY,
    conexao_id INT           NOT NULL,
    padrao     VARCHAR(128)  NOT NULL,
    CONSTRAINT FK_conexao_exclusao_conexao FOREIGN KEY (conexao_id) REFERENCES dbo.conexao (id) ON DELETE CASCADE,
    CONSTRAINT UQ_conexao_exclusao UNIQUE (conexao_id, padrao)
);
GO

IF OBJECT_ID(N'dbo.dic_tabela', N'U') IS NULL
CREATE TABLE dbo.dic_tabela (
    id               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_tabela PRIMARY KEY,
    conexao_id       INT            NOT NULL,
    [schema]         VARCHAR(128)   NOT NULL CONSTRAINT DF_dic_tabela_schema DEFAULT ('dbo'),
    tabela           VARCHAR(128)   NOT NULL,
    descricao        NVARCHAR(1000) NULL,
    situacao         VARCHAR(10)    NOT NULL CONSTRAINT DF_dic_tabela_situacao DEFAULT ('incluir'),
    existe_no_banco  BIT            NOT NULL CONSTRAINT DF_dic_tabela_existe DEFAULT (1),
    status_revisao   VARCHAR(12)    NOT NULL CONSTRAINT DF_dic_tabela_status DEFAULT ('sugerido_ia'),
    revisado_por     INT            NULL,
    revisado_em      DATETIME2(0)   NULL,
    CONSTRAINT FK_dic_tabela_conexao FOREIGN KEY (conexao_id) REFERENCES dbo.conexao (id) ON DELETE CASCADE,
    CONSTRAINT FK_dic_tabela_revisor FOREIGN KEY (revisado_por) REFERENCES dbo.usuario (id),
    CONSTRAINT UQ_dic_tabela UNIQUE (conexao_id, [schema], tabela),
    CONSTRAINT CK_dic_tabela_situacao CHECK (situacao IN ('incluir', 'excluir', 'interna')),
    CONSTRAINT CK_dic_tabela_status CHECK (status_revisao IN ('sugerido_ia', 'revisado', 'rejeitado'))
);
GO

IF OBJECT_ID(N'dbo.dic_coluna', N'U') IS NULL
CREATE TABLE dbo.dic_coluna (
    id               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_coluna PRIMARY KEY,
    dic_tabela_id    INT            NOT NULL,
    coluna           VARCHAR(128)   NOT NULL,
    ordem            INT            NOT NULL CONSTRAINT DF_dic_coluna_ordem DEFAULT (0),
    tipo             VARCHAR(30)    NOT NULL,
    tamanho          INT            NULL,
    nulo             BIT            NOT NULL CONSTRAINT DF_dic_coluna_nulo DEFAULT (1),
    descricao        NVARCHAR(2000) NULL,
    nome_negocio     NVARCHAR(200)  NULL,
    sinonimos        NVARCHAR(500)  NULL,
    sensivel         BIT            NOT NULL CONSTRAINT DF_dic_coluna_sensivel DEFAULT (0),
    existe_no_banco  BIT            NOT NULL CONSTRAINT DF_dic_coluna_existe DEFAULT (1),
    status_revisao   VARCHAR(12)    NOT NULL CONSTRAINT DF_dic_coluna_status DEFAULT ('sugerido_ia'),
    CONSTRAINT FK_dic_coluna_tabela FOREIGN KEY (dic_tabela_id) REFERENCES dbo.dic_tabela (id) ON DELETE CASCADE,
    CONSTRAINT UQ_dic_coluna UNIQUE (dic_tabela_id, coluna),
    CONSTRAINT CK_dic_coluna_status CHECK (status_revisao IN ('sugerido_ia', 'revisado', 'rejeitado'))
);
GO

-- Valores possíveis de um campo (ex.: 0 = Normal, 1 = Atraso).
IF OBJECT_ID(N'dbo.dic_coluna_valor', N'U') IS NULL
CREATE TABLE dbo.dic_coluna_valor (
    id             INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_coluna_valor PRIMARY KEY,
    dic_coluna_id  INT            NOT NULL,
    codigo         VARCHAR(60)    NOT NULL,
    significado    NVARCHAR(400)  NOT NULL,
    ordem          INT            NOT NULL CONSTRAINT DF_dic_coluna_valor_ordem DEFAULT (0),
    CONSTRAINT FK_dic_coluna_valor_coluna FOREIGN KEY (dic_coluna_id) REFERENCES dbo.dic_coluna (id) ON DELETE CASCADE,
    CONSTRAINT UQ_dic_coluna_valor UNIQUE (dic_coluna_id, codigo)
);
GO

IF OBJECT_ID(N'dbo.dic_relacao', N'U') IS NULL
CREATE TABLE dbo.dic_relacao (
    id                 INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_dic_relacao PRIMARY KEY,
    origem_coluna_id   INT          NOT NULL,
    destino_coluna_id  INT          NOT NULL,
    origem             VARCHAR(10)  NOT NULL,
    confirmada         BIT          NOT NULL CONSTRAINT DF_dic_relacao_confirmada DEFAULT (0),
    CONSTRAINT FK_dic_relacao_origem FOREIGN KEY (origem_coluna_id) REFERENCES dbo.dic_coluna (id),
    CONSTRAINT FK_dic_relacao_destino FOREIGN KEY (destino_coluna_id) REFERENCES dbo.dic_coluna (id),
    CONSTRAINT UQ_dic_relacao UNIQUE (origem_coluna_id, destino_coluna_id),
    CONSTRAINT CK_dic_relacao_origem CHECK (origem IN ('fk', 'inferida', 'manual'))
);
GO
