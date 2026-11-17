<?php

declare(strict_types=1);

namespace App\Routing;

class RoutingUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message = 'Routing service is temporarily unavailable.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
