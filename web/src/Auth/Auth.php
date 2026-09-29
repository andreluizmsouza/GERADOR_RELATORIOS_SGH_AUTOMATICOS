<?php

declare(strict_types=1);

namespace Elogica\Auth;

use Elogica\Db\UsuarioRepository;

/** Autenticação por sessão com usuários locais. Ponto de troca futura pelo login do sistema (COM/DLL legada). */
final class Auth
{
    // Hash fixo para igualar o tempo de resposta quando o usuário não existe.
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private readonly UsuarioRepository $usuarios)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            session_name('ELOGICASESS');
            session_start();
        }
    }

    /** @return array{id: int, login: string, nome: string, perfil: string}|null */
    public function user(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['perfil'] ?? '') === 'admin';
    }

    public function attempt(string $login, string $senha): bool
    {
        $u = $this->usuarios->findByLogin($login);
        $hash = $u['senha_hash'] ?? self::DUMMY_HASH;
        $ok = password_verify($senha, (string) $hash);
        if (!$ok || $u === null || !(bool) $u['ativo']) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => (int) $u['id'], 'login' => $u['login'], 'nome' => $u['nome'], 'perfil' => $u['perfil']];

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function verifyCsrf(?string $token): bool
    {
        return $token !== null && hash_equals($this->csrfToken(), $token);
    }
}
