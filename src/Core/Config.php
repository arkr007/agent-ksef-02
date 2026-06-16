<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    public function __construct(
        private array $values,
        private bool $configured
    ) {
    }

    public static function load(string $configDirectory): self
    {
        $examplePath = $configDirectory . '/config.example.php';
        $localPath = $configDirectory . '/config.php';

        if (!is_file($examplePath)) {
            throw new \RuntimeException('Brakuje pliku config/config.example.php.');
        }

        $exampleConfig = require $examplePath;
        $localConfig = is_file($localPath) ? require $localPath : [];

        if (!is_array($exampleConfig) || !is_array($localConfig)) {
            throw new \RuntimeException('Pliki konfiguracyjne muszą zwracać tablicę.');
        }

        return new self(
            array_replace_recursive($exampleConfig, $localConfig),
            is_file($localPath)
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->values;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function url(string $path = '/'): string
    {
        $baseUrl = rtrim((string) $this->get('app.base_url', ''), '/');
        $normalizedPath = '/' . ltrim($path, '/');

        return $baseUrl . ($normalizedPath === '/' ? '' : $normalizedPath);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
