# Módulo admin (etapa 1)

## Modelo
- Um banco de controle `ReportService` por ambiente SQL Server.
- **Dicionário único (matriz):** os bancos de todos os clientes têm a mesma estrutura, então tabelas, campos, descrições,
  valores e relações não pertencem a nenhum cliente. O prefixo (`MTTB`) e as exclusões também são globais
  (menu "Escopo do dicionário"). A conexão do cliente serve só para executar os relatórios (e ler metadados de referência).
- Um IIS pode atender vários clientes. O cliente é definido pela URL: `relatorios-<slug>.elogica.info` (coberto por certificado `*.elogica.info`).
  O `slug` é único em `dbo.conexao`; o modelo do host fica em `TENANT_HOST_TEMPLATE` (`.env`).
  O host `relatorios.elogica.info` (sem cliente) serve o `/admin`.
- O admin é único, em `/admin` de qualquer host, e cadastra todos os clientes.
- Login: usuários locais (`dbo.usuario`, perfil `admin` ou `consulta`). Depois: login do sistema via DLL/COM legada
  (ponto de troca: `Elogica\Auth\Auth::attempt`).

## Instalação
1. Criar o banco `ReportService` e rodar `db/migrations/*.sql` em ordem (001, depois 002).
2. `cp .env.example .env` e preencher. O `.env` fica fora da pasta pública: ao lado de `src/` (em `web/`) ou na raiz do repositório; vale o primeiro encontrado. Gerar `APP_KEY` com `php web/bin/gerar-chave.php`.
3. `cd web && composer install --no-dev`.
4. Criar o primeiro admin: `php web/bin/criar-usuario.php admin "Nome" admin`.
5. IIS: raiz do site em `web/public` (o `web.config` já reescreve para `index.php`); DNS e binding por cliente: um registro/binding `relatorios-<slug>.elogica.info` a cada novo cliente (o IIS não aceita curinga no meio do nome do host).

## Segurança
- Senha da conexão do cliente: libsodium (`APP_KEY`), nunca exibida nem exportada.
- "Testar conexão" recusa login com db_owner, db_datawriter, db_ddladmin ou sysadmin.
- Formulário valida servidor/banco/usuário contra injeção na string de conexão; todas as consultas usam prepared statements.
- Escrita protegida por token CSRF; sessão com HttpOnly e SameSite.

## Testes
`php web/tests/run.php` (resolução de host, criptografia e validação dos formulários; sem dependências).
`php web/tests/sync_test.php` (planejamento e gravação da sincronização do dicionário; usa SQLite em memória, requer `pdo_sqlite`).
`php web/tests/doc_test.php` (leitura dos HTMLs da documentação, consolidação de duplicados e gravação; requer `pdo_sqlite` e `mbstring`).

## Sincronização do dicionário
Menu **Sincronizar**: escolhe um cliente de referência, lê `sys.tables`, `sys.columns` e as FKs declaradas (só catálogo, nunca dados),
aplicando o prefixo e as exclusões do menu **Escopo**, e compara com o dicionário. Primeiro **Analisar** (nada é gravado), depois **Aplicar**.

- Tabelas e colunas novas são criadas (`situacao = incluir`, `status_revisao = sugerido_ia`).
- Tipo, tamanho e nulidade divergentes são atualizados.
- Itens que sumiram do banco (ou saíram do escopo) são só marcados `existe_no_banco = 0`; nada é apagado.
- **Descrições, nomes de negócio, sensível e situação nunca são sobrescritos.**
- FKs declaradas viram relações `origem = fk`, confirmadas.
- É idempotente: reaplicar não duplica.

Menu **Tabelas**: lista o dicionário (filtro por nome).

## Importação da documentação
Menu **Documentação**: envia o `.zip` (ou vários `.htm`/`.html`) da documentação técnica. Os arquivos são lidos em memória (nada é extraído em disco;
limites: 8 MB por arquivo, 200 MB no total, 5000 arquivos).

- O nome e a descrição da tabela vêm do texto `Tabela : X / Descrição : Y` do próprio HTML; os campos, da tabela de 3 colunas (campo, tipo, descrição).
- Versões anteriores (`Anterior_*`, `ANT*`) e arquivos que não descrevem tabela (programas, manuais, bibliotecas) são ignorados.
- Com mais de um arquivo para a mesma tabela, vale o cujo nome é o da tabela; o restante vira aviso. Nome digitado errado no cabeçalho
  (ex.: `MTTEND` em `mttbend.htm`) é corrigido pelo nome do arquivo.
- Valores possíveis: lista em linhas (`0 = Normal`, `1 - Ativo`, `Pessoa Física = 1`) e lista na mesma linha (`1-Ativo 2-Cancelada`).
  Neste segundo caso a descrição original é mantida inteira. Os valores importados devem ser revisados.
- Prévia antes de gravar. O que está **vazio** no dicionário é preenchido; onde já existe texto **diferente**, é conflito: você marca
  tabela a tabela (ou "sobrescrever todos"). Sem marcar, o conflito é mantido como está.
- Nunca altera nome de negócio, sensível, situação, tipo ou estrutura.

## Próximas etapas do admin
1. Tela de revisão de tabelas e campos (editar descrição, nome de negócio, sensível, situação, valores possíveis).
2. Na execução, checar se as tabelas do dicionário existem no banco do cliente (detecta cliente desatualizado).
