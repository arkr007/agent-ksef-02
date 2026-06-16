<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    private const SESSION_KEY = '_flash_messages';

    public function add(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public function consume(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($messages) ? $messages : [];
    }
}
