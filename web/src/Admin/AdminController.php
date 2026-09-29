<?php

declare(strict_types=1);

namespace Elogica\Admin;

use Elogica\Auth\Auth;
use Elogica\Db\ClientConnection;
use Elogica\Db\ConexaoRepository;
use Elogica\Tenant\TenantResolver;

final class AdminController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ConexaoRepository $conexoes,
        private readonly string $templates,
        private readonly TenantResolver $tenants,
    ) {
    }

    public function handle(string $method, string $path): void
    {
        $rota = '/' . trim(substr($path, strlen('/admin')), '/');

        if ($rota === '/login') {
            $this->login($method);

            return;
        }
        if (!$this->auth->isAdmin()) {
            $this->redirect('/admin/login');
        }
        if ($method === 'POST' && !$this->auth->verifyCsrf($_POST['csrf'] ?? null)) {
            $this->abort(400, 'Requisição inválida (token de segurança). Recarregue a página.');
        }

        match (true) {
            $rota === '/logout' && $method === 'POST' => $this->logout(),
            $rota === '/' => $this->render('dashboard', ['conexoes' => $this->conexoes->all()]),
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
        $dados = $atual ?? ['slug' => '', 'cliente' => '', 'servidor' => '', 'banco' => '', 'usuario_readonly' => '', 'filtro_prefixo' => 'MTTB', 'ativo' => 1];
        $exclusoes = $id === null ? ConexaoRepository::EXCLUSOES_PADRAO : $this->conexoes->exclusoes($id);

        if ($method === 'POST') {
            $v = ConexaoForm::validar($_POST, $id === null);
            $erros = $v['erros'];
            if ($erros === [] && $this->conexoes->slugExists($v['dados']['slug'], $id)) {
                $erros[] = 'Já existe um cliente com este identificador (URL).';
            }
            $dados = $v['dados'];
            $exclusoes = $v['exclusoes'];
            if ($erros === []) {
                $senha = (string) ($_POST['senha'] ?? '');
                if ($id === null) {
                    $id = $this->conexoes->create($dados, $senha, $exclusoes);
                } else {
                    $this->conexoes->update($id, $dados, $senha, $exclusoes);
                }
                $_SESSION['flash'] = 'Conexão salva.';
                $this->redirect('/admin/conexoes/' . $id);
            }
        }
        $this->render('conexao', ['id' => $id, 'dados' => $dados, 'exclusoes' => $exclusoes, 'erros' => $erros]);
    }

    private function testar(int $id): void
    {
        $c = $this->conexoes->find($id);
        if ($c === null) {
            $this->abort(404, 'Conexão não encontrada.');
        }
        $r = ClientConnection::test((string) $c['servidor'], (string) $c['banco'], (string) $c['usuario_readonly'], $this->conexoes->senha($id), (string) $c['filtro_prefixo']);
        $_SESSION['flash'] = $r['mensagem'];
        $_SESSION['flash_ok'] = $r['ok'];
        $this->redirect('/admin/conexoes/' . $id);
    }

    /** @param array<string, mixed> $vars */
    private function render(string $view, array $vars, bool $layout = true): void
    {
        $auth = $this->auth;
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
