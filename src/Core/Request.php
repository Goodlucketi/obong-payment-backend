<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $rawBody
    ) {
    }

    public static function fromGlobals(): self
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $body = json_decode($rawBody, true);

        if ($rawBody !== '' && !is_array($body)) {
            throw new HttpException('Request body must be valid JSON.', 400);
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = array_change_key_case($headers ?: [], CASE_LOWER);
        if (!isset($headers['authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDirectory !== '/' && $scriptDirectory !== '.' && str_starts_with($path, $scriptDirectory . '/')) {
            $path = substr($path, strlen($scriptDirectory));
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $_GET,
            is_array($body) ? $body : [],
            $headers,
            $rawBody
        );
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ? trim($matches[1]) : null;
    }
}