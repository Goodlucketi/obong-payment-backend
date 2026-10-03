<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Environment.php';

Obong\Payment\Core\Environment::load(dirname(__DIR__) . '/.env');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Obong\\Payment\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$allowedOrigin = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $routes = require dirname(__DIR__) . '/routes/api.php';
    $router = new Obong\Payment\Core\Router($routes);
    $router->dispatch(Obong\Payment\Core\Request::fromGlobals());
} catch (Obong\Payment\Core\HttpException $exception) {
    Obong\Payment\Core\Response::json([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ], $exception->statusCode());
} catch (Throwable $exception) {
    error_log($exception->__toString());
    Obong\Payment\Core\Response::json([
        'status' => 'error',
        'message' => getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : 'Internal server error.',
    ], 500);
}