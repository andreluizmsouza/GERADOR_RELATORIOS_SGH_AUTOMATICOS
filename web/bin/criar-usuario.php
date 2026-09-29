<?php

declare(strict_types=1);

// Uso: php bin/criar-usuario.php <login> "<nome>" <admin|consulta>   (a senha é pedida no terminal)
require __DIR__ . '/../vendor/autoload.php';

use Elogica\Config;
use Elogica\Db\Control;
use Elogica\Db\UsuarioRepository;

Config::load(dirname(__DIR__), dirname(__DIR__, 2));

[$script, $login, $nome, $perfil] = $argv + [null, null, null, 'consulta'];
if (!$login || !$nome || !in_array($perfil, ['admin', 'consulta'], true)) {
    fwrite(STDERR, "Uso: php bin/criar-usuario.php <login> \"<nome>\" <admin|consulta>\n");
    exit(1);
}
fwrite(STDOUT, 'Senha (mín. 10 caracteres): ');
$senha = trim((string) fgets(STDIN));
if (strlen($senha) < 10) {
    fwrite(STDERR, "Senha muito curta.\n");
    exit(1);
}
(new UsuarioRepository(Control::pdo()))->create($login, $nome, $senha, $perfil);
echo "Usuário {$login} criado.\n";
