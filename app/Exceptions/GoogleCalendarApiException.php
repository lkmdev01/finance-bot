<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarApiException extends RuntimeException
{
    public function __construct(public readonly int $statusCode, ?string $reason = null)
    {
        $allowedReasons = ['accessNotConfigured', 'insufficientPermissions', 'userRateLimitExceeded', 'rateLimitExceeded'];
        $this->reason = in_array($reason, $allowedReasons, true) ? $reason : null;
        parent::__construct('A solicitacao ao Google Calendar falhou (HTTP '.$statusCode.').');
    }

    public readonly ?string $reason;
}
