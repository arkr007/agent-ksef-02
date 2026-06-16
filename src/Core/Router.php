<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][self::normalizePath($path)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = self::normalizePath($request->path());

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            return Response::html('<h1>404</h1><p>Nie znaleziono strony.</p>', 404);
        }

        $result = $handler($request);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        throw new \RuntimeException('Handler trasy nie zwrócił poprawnej odpowiedzi.');
    }

    private static function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
