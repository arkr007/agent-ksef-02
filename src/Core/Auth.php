<?php

declare(strict_types=1);

namespace App\Core;

use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;

final class Auth
{
    private const SESSION_USER_ID = 'auth_user_id';

    private ?array $currentUserCache = null;
    private ?string $lastErrorMessage = null;

    public function __construct(
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository,
        private Config $config
    ) {
    }

    public function currentUser(): ?array
    {
        if ($this->currentUserCache !== null) {
            return $this->currentUserCache;
        }

        $userId = $_SESSION[self::SESSION_USER_ID] ?? null;
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        try {
            $user = $this->userRepository->findById((int) $userId);
        } catch (\Throwable $exception) {
            $this->lastErrorMessage = $exception->getMessage();

            return null;
        }
        $this->currentUserCache = $user;

        if ($user === null) {
            unset($_SESSION[self::SESSION_USER_ID]);
        }

        return $this->currentUserCache;
    }

    public function check(): bool
    {
        return $this->currentUser() !== null;
    }

    public function hasAnyUsers(): bool
    {
        try {
            $this->lastErrorMessage = null;

            return $this->userRepository->countAll() > 0;
        } catch (\Throwable $exception) {
            $this->lastErrorMessage = $exception->getMessage();

            return false;
        }
    }

    public function databaseReady(): bool
    {
        try {
            $this->lastErrorMessage = null;
            $this->userRepository->countAll();

            return true;
        } catch (\Throwable $exception) {
            $this->lastErrorMessage = $exception->getMessage();

            return false;
        }
    }

    public function lastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function guard(): ?Response
    {
        if (!$this->config->isConfigured()) {
            return Response::redirect($this->config->url('/login'));
        }

        if (!$this->databaseReady()) {
            return Response::redirect($this->config->url('/login'));
        }

        if (!$this->hasAnyUsers()) {
            return Response::redirect($this->config->url('/setup-admin'));
        }

        if (!$this->check()) {
            return Response::redirect($this->config->url('/login'));
        }

        return null;
    }

    public function attemptLogin(string $email, string $password, Request $request): bool
    {
        try {
            $user = $this->userRepository->findByEmail($email);
        } catch (\Throwable $exception) {
            $this->lastErrorMessage = $exception->getMessage();

            return false;
        }

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            $this->auditLogRepository->log(
                action: 'login_failed',
                userId: null,
                entityType: 'user',
                entityId: null,
                context: ['email' => $email],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            return false;
        }

        if (!(bool) $user['is_active']) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];
        $this->currentUserCache = $user;
        $this->userRepository->touchLastLogin((int) $user['id']);
        $this->auditLogRepository->log(
            action: 'login_success',
            userId: (int) $user['id'],
            entityType: 'user',
            entityId: (int) $user['id'],
            context: ['email' => $user['email']],
            ipAddress: $request->clientIp(),
            userAgent: $request->userAgent()
        );

        return true;
    }

    public function logout(Request $request): void
    {
        $user = $this->currentUser();
        if ($user !== null) {
            $this->auditLogRepository->log(
                action: 'logout',
                userId: (int) $user['id'],
                entityType: 'user',
                entityId: (int) $user['id'],
                context: ['email' => $user['email']],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
        session_start();
        $this->currentUserCache = null;
    }
}
