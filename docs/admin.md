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
`php web/tests/run.php` (resolução de host, criptografia e validação do formulário; sem dependências).

## Próximas etapas do admin
1. Sincronizar tabelas e campos a partir de um banco de referência (qualquer cliente), aplicando o escopo.
2. Importar a documentação HTML (descrição, valores possíveis).
3. Tela de tabelas/campos com revisão.
4. Na execução, checar se as tabelas do dicionário existem no banco do cliente (detecta cliente desatualizado).
