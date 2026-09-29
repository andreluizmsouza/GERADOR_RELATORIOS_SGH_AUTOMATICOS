<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Instalação incompleta: execute "composer install" na pasta web.';
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../templates/helpers.php';

use Elogica\Admin\AdminController;
use Elogica\Auth\Auth;
use Elogica\Config;
use Elogica\Db\ConexaoRepository;
use Elogica\Db\Control;
use Elogica\Db\DicionarioConfigRepository;
use Elogica\Db\UsuarioRepository;
use Elogica\Metadata\DicionarioRepository;
use Elogica\Metadata\SyncService;
use Elogica\Security\Crypto;
use Elogica\Tenant\TenantResolver;

// .env fica fora da pasta pública: ao lado de src/ (web/) ou na raiz do repositório.
Config::load(dirname(__DIR__), dirname(__DIR__, 2));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = Control::pdo();
    $auth = new Auth(new UsuarioRepository($pdo));
    $auth->start();
    $conexoes = new ConexaoRepository($pdo, Crypto::fromConfig());

    $resolver = new TenantResolver(Config::get('TENANT_HOST_TEMPLATE', TenantResolver::MODELO_PADRAO) ?? TenantResolver::MODELO_PADRAO);

    // O admin é único e independe do host.
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $escopo = new DicionarioConfigRepository($pdo);
        $dicionario = new DicionarioRepository($pdo);
        (new AdminController($auth, $conexoes, $escopo, $dicionario, new SyncService($conexoes, $escopo, $dicionario), dirname(__DIR__) . '/templates', $resolver))
            ->handle($method, $path);
        exit;
    }

    // Demais rotas: o cliente é definido pela URL.
    $slug = $resolver->slugFromHost($_SERVER['HTTP_HOST'] ?? '');
    $cliente = $slug === null ? null : $conexoes->findAtivaBySlug($slug);
    if ($cliente === null) {
        http_response_code(404);
        echo 'Cliente não encontrado.';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Relatórios</title><h1>' . e($cliente['cliente']) . '</h1><p>Área de relatórios em construção.</p>';
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo 'Erro interno. Contate o suporte.';
}
