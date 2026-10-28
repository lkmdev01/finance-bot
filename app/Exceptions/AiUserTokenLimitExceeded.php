<?php

namespace App\Exceptions;

use RuntimeException;

class AiUserTokenLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $limit, public readonly int $used)
    {
        parent::__construct('O limite mensal de tokens de IA foi atingido.');
    }
}
