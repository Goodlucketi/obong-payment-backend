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
$routes = require $backendRoot . '/routes/api.php';
foreach ($routes as $route) {
    [$method, $path, $handler] = $route;
    [$controllerName, $action] = explode('@', $handler, 2);
    $class = $prefix . 'Controllers' . chr(92) . $controllerName;

    if (!class_exists($class) || !method_exists($class, $action)) {
        $failures[] = "{$method} {$path} -> {$handler}";
        continue;
    }

    echo "OK {$method} {$path}\n";
}

$criticalFlowRoutes = [
    ['POST', '/api/v1/auth/student/register', 'AuthController@studentRegister'],
    ['GET', '/api/v1/auth/student/verify-email', 'AuthController@verifyStudentEmail'],
    ['POST', '/api/v1/auth/student/resend-verification', 'AuthController@resendStudentEmailVerification'],
    ['POST', '/api/v1/auth/student/login', 'AuthController@studentLogin'],
    ['POST', '/api/v1/payments/initialize', 'PaymentController@initialize'],
    ['GET', '/api/v1/payments/verify/{reference}', 'PaymentController@verify'],
    ['GET', '/api/v1/transactions/{reference}', 'TransactionController@show'],
    ['GET', '/api/v1/receipts/{receiptOrReference}', 'ReceiptController@show'],
];
foreach ($criticalFlowRoutes as [$method, $path, $handler]) {
    $matches = array_values(array_filter(
        $routes,
        static fn (array $route): bool => $route[0] === $method && $route[1] === $path
    ));
    if (count($matches) !== 1 || $matches[0][2] !== $handler) {
        $failures[] = "Critical flow route is missing, duplicated, or mapped incorrectly: {$method} {$path} -> {$handler}";
    }
}

$router = new Obong\Payment\Core\Router([]);
$matchMethod = new ReflectionMethod($router, 'match');
$reference = 'OBONG-REF-123';
$referenceRoutes = [
    ['/api/v1/payments/verify/{reference}', '/api/v1/payments/verify/' . $reference, 'reference'],
    ['/api/v1/transactions/{reference}', '/api/v1/transactions/' . $reference, 'reference'],
    ['/api/v1/receipts/{receiptOrReference}', '/api/v1/receipts/' . $reference, 'receiptOrReference'],
];
foreach ($referenceRoutes as [$pattern, $path, $parameter]) {
    $params = $matchMethod->invoke($router, $pattern, $path);
    if ($params !== [$parameter => $reference]) {
        $failures[] = "Transaction reference is not extracted consistently for {$path}.";
    }
}

if ($failures) {
    fwrite(STDERR, "Missing route handlers:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "All route handlers exist and critical flow routes are consistent.\n";