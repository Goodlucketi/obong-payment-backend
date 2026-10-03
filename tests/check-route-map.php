<?php

declare(strict_types=1);

$backendRoot = dirname(__DIR__);
$prefix = 'Obong' . chr(92) . 'Payment' . chr(92);

spl_autoload_register(static function (string $class) use ($prefix, $backendRoot): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace(chr(92), DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = $backendRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$failures = [];
foreach (require $backendRoot . '/routes/api.php' as $route) {
    [$method, $path, $handler] = $route;
    [$controllerName, $action] = explode('@', $handler, 2);
    $class = $prefix . 'Controllers' . chr(92) . $controllerName;

    if (!class_exists($class) || !method_exists($class, $action)) {
        $failures[] = "{$method} {$path} -> {$handler}";
        continue;
    }

    echo "OK {$method} {$path}\n";
}

$router = new Obong\Payment\Core\Router([]);
$matchMethod = new ReflectionMethod($router, 'match');
$params = $matchMethod->invoke($router, '/api/v1/payments/verify/{reference}', '/api/v1/payments/verify/OBONG-REF-123');
if ($params !== ['reference' => 'OBONG-REF-123']) {
    $failures[] = 'Dynamic route parameter extraction failed.';
}

if ($failures) {
    fwrite(STDERR, "Missing route handlers:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "All route handlers exist.\n";