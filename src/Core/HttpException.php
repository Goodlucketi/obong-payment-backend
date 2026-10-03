<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->httpStatus;
    }
}