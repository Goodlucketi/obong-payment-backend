<?php

declare(strict_types=1);

namespace Obong\Payment\Core;

final class Router
{
    public function __construct(private readonly array $routes)
    {
    }

    public function dispatch(Request $request): never
    {
        foreach ($this->routes as $route) {
            [$method, $pattern, $handler] = $route;
            $authOptions = $route[3] ?? [];
            $params = $this->match($pattern, $request->path);
            if ($params === null) {
                continue;
            }

            if ($method !== $request->method) {
                continue;
            }

            [$controllerName, $action] = explode('@', $handler, 2);
            $class = 'Obong\\Payment\\Controllers\\' . $controllerName;
            if (!class_exists($class) || !method_exists($class, $action)) {
                throw new HttpException('Route handler is not available.', 501);
            }

            $db = null;
            $actor = null;
            if ($authOptions || is_subclass_of($class, Controller::class)) {
                $db = Database::connection();
            }
            if ($authOptions) {
                $actor = Authenticator::authenticate($db, $request, $authOptions);
            }

            $controller = is_subclass_of($class, Controller::class) ? new $class($db) : new $class();
            $result = $controller->{$action}($request, $params, $actor);
            Response::json(is_array($result) ? $result : ['data' => $result]);
        }

        throw new HttpException('Route not found.', 404);
    }

    private function match(string $pattern, string $path): ?array
    {
        $names = [];
        $quoted = preg_quote($pattern, '#');
        $regex = preg_replace_callback('/\\\\\{([a-zA-Z][a-zA-Z0-9_]*)\\\\\}/', static function (array $matches) use (&$names): string {
            $names[] = $matches[1];
            return '([^/]+)';
        }, $quoted);

        if (!preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        array_shift($matches);
        $params = [];
        foreach ($names as $index => $name) {
            $params[$name] = rawurldecode($matches[$index]);
        }

        return $params;
    }
}