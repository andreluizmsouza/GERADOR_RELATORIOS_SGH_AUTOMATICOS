<?php

declare(strict_types=1);

namespace Elogica\Security;

use Elogica\Config;
use RuntimeException;

/** Criptografia simétrica (libsodium secretbox) para segredos guardados no banco de controle. */
final class Crypto
{
    private string $key;

    public function __construct(string $keyBase64)
    {
        $key = base64_decode($keyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY inválida: deve ter 32 bytes em base64.');
        }
        $this->key = $key;
    }

    public static function fromConfig(): self
    {
        return new self(Config::require('APP_KEY'));
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Segredo criptografado inválido.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Falha ao descriptografar: chave incorreta ou dado adulterado.');
        }

        return $plain;
    }
}
