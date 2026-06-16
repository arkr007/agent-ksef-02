<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $parsedBody,
        private array $files,
        private array $server,
        private array $cookies,
        private array $session
    ) {
    }

    public static function capture(string $basePath = '', string $sessionName = 'agent_ksef_session'): self
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            session_name($sessionName);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? $uriPath : '/';
        $normalizedBasePath = rtrim($basePath, '/');

        if ($normalizedBasePath !== '' && str_starts_with($uriPath, $normalizedBasePath)) {
            $uriPath = substr($uriPath, strlen($normalizedBasePath)) ?: '/';
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . ltrim($uriPath, '/'),
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $_COOKIE,
            $_SESSION
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->parsedBody;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function session(): array
    {
        return $this->session;
    }

    public function sessionValue(string $key, mixed $default = null): mixed
    {
        return $this->session[$key] ?? $default;
    }

    public function clientIp(): string
    {
        $ip = $this->server('HTTP_X_FORWARDED_FOR')
            ?? $this->server('REMOTE_ADDR')
            ?? '';

        if (!is_string($ip)) {
            return '';
        }

        return trim(explode(',', $ip)[0]);
    }

    public function userAgent(): string
    {
        $value = $this->server('HTTP_USER_AGENT', '');

        return is_string($value) ? $value : '';
    }
}
