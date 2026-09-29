<?php

declare(strict_types=1);

require __DIR__ . '/../src/Security/Crypto.php';

echo Elogica\Security\Crypto::generateKey() . PHP_EOL;
