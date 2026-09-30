<?php

declare(strict_types=1);

namespace Elogica\Admin;

use Elogica\Auth\Auth;
use Elogica\Db\ClientConnection;
use Elogica\Db\ConexaoRepository;
use Elogica\Db\DicionarioConfigRepository;
use Elogica\Metadata\DicionarioRepository;
use Elogica\Metadata\DocImport;
use Elogica\Metadata\DocLote;
use Elogica\Metadata\SyncService;
use Elogica\Relacionamentos\RelacionamentoRepository;
use Elogica\Relacionamentos\Sugestor;
use Elogica\Tenant\TenantResolver;

final class AdminController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ConexaoRepository $conexoes,
        private readonly DicionarioConfigRepository $escopo,
        private readonly DicionarioRepository $dicionario,
        private readonly SyncService $sync,
        private readonly RelacionamentoRepository $relacionamentos,
        private readonly string $templates,
        private readonly TenantResolver $tenants,
    ) {
    }

    private string $rotaAtual = '/';

    public function handle(string $method, string $path): void
    {
        $rota = '/' . trim(substr($path, strlen('/admin')), '/');
        $this->rotaAtual = $rota;

        if ($rota === '/login') {
            $this->login($method);

            return;
        }
        if (!$this->auth->isAdmin()) {
            $this->redirect('/admin/login');
        }
        if ($method === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->abort(413, 'O envio passou do limite do servidor (post_max_size). Envie menos arquivos por vez.');
        }
        if ($method === 'POST' && !$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->abort(400, 'Requisição inválida (token de segurança). Recarregue a página.');
        }

        match (true) {
            $rota === '/logout' && $method === 'POST' => $this->logout(),
            $rota === '/' => $this->render('dashboard', ['conexoes' => $this->conexoes->all()]),
            $rota === '/relacionamentos' => $this->paginaRelacionamentos(),
            $rota === '/relacionamentos/dados' => $this->json($this->dadosRelacionamentos()),
            $rota === '/relacionamentos/mapa' => $this->mapaRelacionamentos(),
            $rota === '/relacionamentos/colunas' => $this->json($this->relacionamentos->colunas((string) ($_GET['tabela'] ?? ''))),
            $rota === '/relacionamentos/acao' && $method === 'POST' => $this->acaoRelacionamento(),
            $rota === '/dicionario' => $this->tabelas(),
            preg_match('#^/dicionario/tabela/(\d+)$#', $rota, $m) === 1 => $this->tabela($method, (int) $m[1]),
            $rota === '/dicionario/documentacao' => $this->documentacao($method),
            $rota === '/dicionario/sincronizar' => $this->sincronizar($method),
            $rota === '/dicionario/escopo' => $this->escopo($method),
            $rota === '/conexoes/nova' => $this->form($method, null),
            preg_match('#^/conexoes/(\d+)$#', $rota, $m) === 1 => $this->form($method, (int) $m[1]),
            preg_match('#^/conexoes/(\d+)/testar$#', $rota, $m) === 1 && $method === 'POST' => $this->testar((int) $m[1]),
            default => $this->abort(404, 'Página não encontrada.'),
        };
    }

    private function login(string $method): void
    {
        $erro = null;
        if ($method === 'POST') {
            if (!$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
                $erro = 'Requisição inválida. Tente novamente.';
            } elseif ($this->auth->attempt(trim((string) ($_POST['login'] ?? '')), (string) ($_POST['senha'] ?? ''))) {
                $this->redirect('/admin');
            } else {
                $erro = 'Usuário ou senha inválidos.';
            }
        }
        $this->render('login', ['erro' => $erro], false);
    }

    private function logout(): void
    {
        $this->auth->logout();
        $this->redirect('/admin/login');
    }

    private function form(string $method, ?int $id): void
    {
        $atual = $id === null ? null : $this->conexoes->find($id);
        if ($id !== null && $atual === null) {
            $this->abort(404, 'Conexão não encontrada.');
        }
        $erros = [];
        $dados = $atual ?? ['slug' => '', 'cliente' => '', 'servidor' => '', 'banco' => '', 'usuario_readonly' => '', 'ativo' => 1];

        if ($method === 'POST') {
            $post = $_POST;
            $post['slug'] = $this->tenants->normalizarSlug((string) ($post['slug'] ?? ''));
            $v = ConexaoForm::validar($post, $id === null);
            $erros = $v['erros'];
            if ($erros === [] && $this->conexoes->slugExists($v['dados']['slug'], $id)) {
                $erros[] = 'Já existe um cliente com este identificador (URL).';
            }
            $dados = $v['dados'];
            if ($erros === []) {
                $senha = (string) ($_POST['senha'] ?? '');
                if ($id === null) {
                    $id = $this->conexoes->create($dados, $senha);
                } else {
                    $this->conexoes->update($id, $dados, $senha);
                }
                $_SESSION['flash'] = 'Conexão salva.';
                $this->redirect('/admin/conexoes/' . $id);
            }
        }
        $this->render('conexao', ['id' => $id, 'dados' => $dados, 'erros' => $erros]);
    }

    /** @return array<string, mixed> */
    private function dadosRelacionamentos(): array
    {
        return ['tabelas' => $this->relacionamentos->tabelas(), 'resumo' => $this->relacionamentos->resumo(), 'maes' => $this->relacionamentos->tabelasMae()];
    }

    private function paginaRelacionamentos(): void
    {
        $dados = $this->dadosRelacionamentos();
        $nomes = array_column($dados['tabelas'], 'n');
        $foco = strtoupper((string) ($_GET['tabela'] ?? ''));
        if (!in_array($foco, $nomes, true)) {
            $foco = $dados['maes'][0] ?? '';
        }
        if (!in_array($foco, $nomes, true)) {
            $foco = $nomes[0] ?? '';
        }
        $dados['foco'] = $foco;
        $dados['csrf'] = $this->auth->csrfToken();
        $this->render('relacionamentos', ['dados' => $dados]);
    }

    private function mapaRelacionamentos(): never
    {
        $mapa = $this->relacionamentos->mapa((string) ($_GET['tabela'] ?? ''), (int) ($_GET['saltos'] ?? 1));
        $mapa === null ? $this->json(['erro' => 'Tabela não encontrada.'], 404) : $this->json($mapa);
    }

    /** Ações da tela de relacionamentos (POST, resposta em JSON). */
    private function acaoRelacionamento(): never
    {
        $uid = (int) ($this->auth->user()['id'] ?? 0);
        $agora = date('Y-m-d H:i:s');
        $rel = $this->relacionamentos;
        try {
            switch ((string) ($_POST['acao'] ?? '')) {
                case 'estado':
                    $this->json(['ok' => $rel->definirEstado((int) ($_POST['id'] ?? 0), (string) ($_POST['estado'] ?? ''), $uid, $agora)]);
                    // no break: json() encerra a requisição
                case 'lote':
                    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                    $this->json(['ok' => true, 'aprovadas' => $rel->aprovarLote($ids, $uid, $agora)]);
                case 'excluir':
                    $this->json(['ok' => $rel->excluirManual((int) ($_POST['id'] ?? 0))]);
                case 'criar':
                    $pares = [];
                    foreach ((array) ($_POST['pares'] ?? []) as $p) {
                        if (is_array($p) && (trim((string) ($p[0] ?? '')) !== '' || trim((string) ($p[1] ?? '')) !== '')) {
                            $pares[] = [(string) ($p[0] ?? ''), (string) ($p[1] ?? '')];
                        }
                    }
                    $id = $rel->criarManual((string) ($_POST['a'] ?? ''), (string) ($_POST['b'] ?? ''), (string) ($_POST['papel'] ?? ''), $pares, $uid, $agora);
                    $this->json(['ok' => true, 'id' => $id]);
                case 'gerar':
                    set_time_limit(180);
                    if (isset($_POST['maes'])) {
                        $maes = array_values(array_filter(array_map(static fn (string $s): string => strtoupper(trim($s)), explode(',', (string) $_POST['maes']))));
                        foreach ($maes as $m) {
                            if (preg_match('/^[A-Z0-9_]{1,128}$/', $m) !== 1) {
                                throw new \InvalidArgumentException("Nome de tabela-mãe inválido: {$m}");
                            }
                        }
                        $rel->salvarMaes($maes);
                    }
                    $sugestoes = Sugestor::gerar($rel->tabelasParaSugestor(), $rel->tabelasMae());
                    $this->json(['ok' => true] + $rel->inserirCandidatos($sugestoes, $uid, $agora) + ['geradas' => count($sugestoes)]);
                default:
                    $this->json(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
            }
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }
    }

    private function json(mixed $dados, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function tabelas(): void
    {
        $filtro = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, RevisaoForm::STATUS, true) ? $status : '';
        $this->render('tabelas', ['filtro' => $filtro, 'status' => $status, 'tabelas' => $this->dicionario->listarTabelas($filtro, $status)]);
    }

    private function tabela(string $method, int $id): void
    {
        $t = $this->dicionario->tabela($id);
        if ($t === null) {
            $this->abort(404, 'Tabela não encontrada.');
        }
        $erros = [];
        $colunas = $this->dicionario->colunasDaTabela($id);
        if ($method === 'POST') {
            if (!isset($_POST['fim'])) {
                $erros[] = 'O formulário chegou incompleto (limite max_input_vars do PHP). Nada foi gravado; aumente o limite no php.ini.';
            } else {
                $v = RevisaoForm::validar($_POST, array_map(static fn (array $c): int => (int) $c['id'], $colunas));
                $erros = $v['erros'];
                if ($erros === []) {
                    $r = $this->dicionario->salvarRevisao($id, $v['tabela'], $v['colunas'], (int) ($this->auth->user()['id'] ?? 0), date('Y-m-d H:i:s'));
                    $_SESSION['flash'] = sprintf('Revisão salva: %d campos e %d listas de valores alterados.', $r['colunas'], $r['valores']);
                    $this->redirect('/admin/dicionario/tabela/' . $id);
                }
            }
        }
        $this->render('tabela', ['t' => $t, 'colunas' => $colunas, 'erros' => $erros, 'post' => $method === 'POST' ? $_POST : null]);
    }

    private function sincronizar(string $method): void
    {
        $resultado = null;
        $erro = null;
        $conexaoId = (int) ($_POST['conexao_id'] ?? 0);
        if ($method === 'POST') {
            set_time_limit(300);
            try {
                $resultado = $this->sync->executar($conexaoId, ($_POST['acao'] ?? '') === 'aplicar');
            } catch (\Throwable $e) {
                error_log((string) $e);
                $erro = 'Não foi possível ler o banco do cliente. Use "Testar conexão" no cadastro do cliente e confira o log do PHP.';
            }
        }
        $this->render('sincronizar', ['conexoes' => $this->conexoes->all(), 'conexaoId' => $conexaoId, 'resultado' => $resultado, 'erro' => $erro]);
    }

    private function documentacao(string $method): void
    {
        $erros = [];
        if ($method === 'POST') {
            $acao = (string) ($_POST['acao'] ?? '');
            if ($acao === 'enviar') {
                set_time_limit(300);
                $c = DocUpload::coletar($_FILES['arquivos'] ?? []);
                $erros = $c['erros'];
                if ($c['arquivos'] === []) {
                    $erros[] = 'Nenhum arquivo .htm/.html foi encontrado no envio.';
                } else {
                    $lote = DocLote::consolidar($c['arquivos']);
                    $lote['arquivos'] = count($c['arquivos']);
                    $_SESSION['doc_lote'] = $lote;
                    $this->redirect('/admin/dicionario/documentacao');
                }
            } elseif ($acao === 'descartar') {
                unset($_SESSION['doc_lote']);
                $this->redirect('/admin/dicionario/documentacao');
            } elseif ($acao === 'aplicar' && isset($_SESSION['doc_lote'])) {
                $plano = DocImport::planejar($_SESSION['doc_lote']['tabelas'], $this->dicionario->carregarTextos());
                $escolhidas = [];
                foreach ((array) ($_POST['sobrescrever'] ?? []) as $nome) {
                    $nome = strtoupper((string) $nome);
                    if (isset($plano['itens'][$nome])) {
                        $escolhidas[$nome] = true;
                    }
                }
                $r = $this->dicionario->aplicarDocumentacao($plano['itens'], $escolhidas);
                unset($_SESSION['doc_lote']);
                $_SESSION['flash'] = sprintf('Documentação aplicada: %d descrições e %d listas de valores gravadas; %d conflitos mantidos como estavam.', $r['descricoes'], $r['valores'], $r['conflitos_mantidos']);
                $this->redirect('/admin/dicionario/documentacao');
            }
        }
        $lote = $_SESSION['doc_lote'] ?? null;
        $plano = $lote === null ? null : DocImport::planejar($lote['tabelas'], $this->dicionario->carregarTextos());
        $this->render('documentacao', ['lote' => $lote, 'plano' => $plano, 'erros' => $erros]);
    }

    private function escopo(string $method): void
    {
        $erros = [];
        $prefixo = $this->escopo->prefixo();
        $exclusoes = $this->escopo->exclusoes();
        if ($exclusoes === []) {
            $exclusoes = DicionarioConfigRepository::EXCLUSOES_PADRAO;
        }
        if ($method === 'POST') {
            $v = EscopoForm::validar($_POST);
            $erros = $v['erros'];
            $prefixo = $v['prefixo'];
            $exclusoes = $v['exclusoes'];
            if ($erros === []) {
                $this->escopo->salvar($prefixo, $exclusoes);
                $_SESSION['flash'] = 'Escopo do dicionário salvo.';
                $this->redirect('/admin/dicionario/escopo');
            }
        }
        $this->render('escopo', ['prefixo' => $prefixo, 'exclusoes' => $exclusoes, 'erros' => $erros]);
    }

    private function testar(int $id): void
    {
        $c = $this->conexoes->find($id);
        if ($c === null) {
            $this->abort(404, 'Conexão não encontrada.');
        }
        $r = ClientConnection::test((string) $c['servidor'], (string) $c['banco'], (string) $c['usuario_readonly'], $this->conexoes->senha($id), $this->escopo->prefixo());
        $_SESSION['flash'] = $r['mensagem'];
        $_SESSION['flash_ok'] = $r['ok'];
        $this->redirect('/admin/conexoes/' . $id);
    }

    /** @param array<string, mixed> $vars */
    private function render(string $view, array $vars, bool $layout = true): void
    {
        $auth = $this->auth;
        $nav = $this->rotaAtual;
        [$hostAntes, $hostDepois] = $this->tenants->partes();
        $hostFor = fn (string $slug): string => $this->tenants->hostFor($slug);
        $flash = $_SESSION['flash'] ?? null;
        $flashOk = $_SESSION['flash_ok'] ?? true;
        unset($_SESSION['flash'], $_SESSION['flash_ok']);
        extract($vars, EXTR_SKIP);
        ob_start();
        require $this->templates . "/admin/{$view}.php";
        $conteudo = (string) ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
        require $this->templates . '/admin/layout.php';
    }

    private function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    private function abort(int $code, string $msg): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}
