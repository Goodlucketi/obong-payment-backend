<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function redirect(string $url): never
    {
        if (!preg_match('#^https?://#i', $url) || str_contains($url, "\r") || str_contains($url, "\n")) {
            throw new \InvalidArgumentException('Redirect URL must be an absolute HTTP URL.');
        }

        header('Location: ' . $url, true, 302);
        exit;
    }
}