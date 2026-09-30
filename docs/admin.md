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

## Visual
O admin usa uma folha de estilo própria (`web/public/assets/admin.css`), sem Bootstrap, com os mesmos tokens do protótipo de
`docs/prototipos/`: interface grafite, tema claro e escuro automáticos (segue o sistema), fontes IBM Plex Sans e Plex Mono (Google Fonts, com
alternativa do sistema se o servidor estiver sem internet). A cor só aparece onde significa algo: azul = informação/FK, verde = sugerido ou ok,
roxo = confirmado, âmbar = atenção, vermelho = erro. Para mudar a aparência, altere as variáveis `--*` no início do `admin.css`.

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
`php web/tests/revisao_test.php` (tela de revisão: validação, sugestão de sensíveis e gravação; requer `pdo_sqlite` e `mbstring`).

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

## Revisão de tabelas e campos
Menu **Tabelas**: lista com filtro por nome e por status, contagem de campos e de campos revisados. Clicando numa tabela abre a revisão:

- Tabela: descrição, **situação** (`incluir` / `excluir` / `interna`; as duas últimas ficam fora dos relatórios) e status (`sugerido_ia` / `revisado` / `rejeitado`).
  Ao marcar `revisado`, ficam gravados o usuário e a data.
- Cada campo: descrição, nome de negócio, sinônimos, **sensível**, valores possíveis (uma linha por valor: `código = significado`) e status.
- **Sensíveis:** o sistema só *sugere* (selo "possível") campos que parecem dado pessoal (CPF/CGC, nome, endereço, nascimento, renda...).
  O botão "Marcar sugeridos como sensíveis" marca as sugestões; nada vale até você salvar. Campos sensíveis são mascarados na exportação.
- "Marcar tudo como revisado ao salvar" fecha a tabela de uma vez.
- Estrutura (tipo, tamanho, nulidade) não é editável aqui: vem da sincronização.

**Importante — `max_input_vars`:** uma tabela grande (ex.: `MTTBCON`, 332 campos) envia ~1.700 campos de formulário, e o padrão do PHP é 1000
(o excedente seria descartado em silêncio). O `docs/php/php.ini` já traz `max_input_vars = 10000`. Se o formulário chegar incompleto, a tela avisa e
**não grava nada**; campos ausentes do envio nunca são apagados.

## Próximas etapas do admin
1. Na execução, checar se as tabelas do dicionário existem no banco do cliente (detecta cliente desatualizado).
2. Fase 4: gerador de definição de relatório (JSON) a partir do dicionário revisado, com validador de SQL.
