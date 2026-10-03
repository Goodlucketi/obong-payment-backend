<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Request;

final class HealthController
{
    public function show(Request $request): array
    {
        return [
            'status' => 'success',
            'data' => [
                'service' => 'obong-payment-api',
                'status' => 'ok',
            ],
        ];
    }
}