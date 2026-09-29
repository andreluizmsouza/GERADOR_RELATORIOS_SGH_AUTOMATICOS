<?php

declare(strict_types=1);

// Testes sem dependências: php tests/run.php
require __DIR__ . '/../src/Security/Crypto.php';
require __DIR__ . '/../src/Tenant/TenantResolver.php';
require __DIR__ . '/../src/Admin/ConexaoForm.php';

use Elogica\Admin\ConexaoForm;
use Elogica\Security\Crypto;
use Elogica\Tenant\TenantResolver;

$falhas = 0;
$total = 0;
function check(string $nome, bool $ok): void
{
    global $falhas, $total;
    $total++;
    if (!$ok) {
        $falhas++;
        echo "FALHOU: {$nome}\n";
    }
}
function lanca(callable $f): bool
{
    try {
        $f();
    } catch (Throwable) {
        return true;
    }

    return false;
}

// --- TenantResolver ---
$r = new TenantResolver('relatorios-{slug}.elogica.info');
check('slug simples', $r->slugFromHost('relatorios-campinas.elogica.info') === 'campinas');
check('slug com porta e caixa', $r->slugFromHost('Relatorios-Campinas.Elogica.Info:8443') === 'campinas');
check('slug com hífen', $r->slugFromHost('relatorios-cohab-sp.elogica.info') === 'cohab-sp');
check('host do admin sem cliente', $r->slugFromHost('relatorios.elogica.info') === null);
check('host de outro domínio', $r->slugFromHost('relatorios-campinas.evil.com') === null);
check('sufixo malicioso', $r->slugFromHost('relatorios-campinas.elogica.info.evil.com') === null);
check('subdomínio extra', $r->slugFromHost('x.relatorios-campinas.elogica.info') === null);
check('host vazio', $r->slugFromHost('') === null);
check('slug curto demais', $r->slugFromHost('relatorios-a.elogica.info') === null);
check('hostFor', $r->hostFor('campinas') === 'relatorios-campinas.elogica.info');
check('modelo sem {slug} rejeitado', lanca(fn () => new TenantResolver('relatorios.elogica.info')));
check('slug validação', TenantResolver::isValidSlug('cehab-rj') && !TenantResolver::isValidSlug('Campinas') && !TenantResolver::isValidSlug('a_b'));

// --- Crypto ---
$c = new Crypto(Crypto::generateKey());
$enc = $c->encrypt('Senha@123');
check('crypto ida e volta', $c->decrypt($enc) === 'Senha@123');
check('crypto nonce aleatório', $c->encrypt('x') !== $c->encrypt('x'));
check('crypto chave errada falha', lanca(fn () => (new Crypto(Crypto::generateKey()))->decrypt($enc)));
check('crypto adulterado falha', lanca(fn () => $c->decrypt(substr($enc, 0, -4) . 'AAAA')));
check('chave inválida rejeitada', lanca(fn () => new Crypto('curta')));

// --- ConexaoForm ---
$ok = ['slug' => 'Campinas', 'cliente' => 'COHAB Campinas', 'servidor' => 'SRV01\\SQL2019', 'banco' => 'SGH_CAMP', 'usuario_readonly' => 'rs_ro', 'filtro_prefixo' => 'mttb', 'senha' => 'x', 'exclusoes' => "MTTBCOB\n%[_]BKP%\n\n", 'ativo' => 'on'];
$v = ConexaoForm::validar($ok, true);
check('form válido', $v['erros'] === [] && $v['dados']['slug'] === 'campinas' && $v['dados']['filtro_prefixo'] === 'MTTB');
check('form exclusões', $v['exclusoes'] === ['MTTBCOB', '%[_]BKP%']);
check('form ativo', $v['dados']['ativo'] === true && ConexaoForm::validar(['ativo' => null] + $ok, true)['dados']['ativo'] === false);
check('form slug inválido', ConexaoForm::validar(['slug' => 'a b'] + $ok, true)['erros'] !== []);
check('form injeção no servidor', ConexaoForm::validar(['servidor' => 'srv;Database=master'] + $ok, true)['erros'] !== []);
check('form injeção no banco', ConexaoForm::validar(['banco' => 'db}; --'] + $ok, true)['erros'] !== []);
check('form senha obrigatória ao criar', ConexaoForm::validar(['senha' => ''] + $ok, true)['erros'] !== []);
check('form senha opcional ao editar', ConexaoForm::validar(['senha' => ''] + $ok, false)['erros'] === []);
check('form padrão de exclusão inválido', ConexaoForm::validar(['exclusoes' => "a'; DROP TABLE x"] + $ok, true)['erros'] !== []);

echo $falhas === 0 ? "OK: {$total} testes\n" : "{$falhas} de {$total} falharam\n";
exit($falhas === 0 ? 0 : 1);
