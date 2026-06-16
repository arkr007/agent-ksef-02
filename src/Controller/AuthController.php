<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;

final class AuthController
{
    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private Flash $flash,
        private UserRepository $userRepository,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    public function loginForm(Request $request): Response
    {
        unset($request);

        if ($this->config->isConfigured() && $this->auth->databaseReady() && !$this->auth->hasAnyUsers()) {
            return Response::redirect($this->config->url('/setup-admin'));
        }

        if ($this->auth->check()) {
            return Response::redirect($this->config->url('/'));
        }

        return Response::html($this->view->render('login', [
            'title' => 'Logowanie',
            'pageTitle' => 'Logowanie do aplikacji',
            'pageDescription' => 'Logowanie działa już z użyciem sesji, CSRF i audytu zdarzeń.',
            'alerts' => $this->configurationAlert(),
            'showSetupLink' => $this->config->isConfigured() && $this->auth->databaseReady() && !$this->auth->hasAnyUsers(),
        ]));
    }

    public function login(Request $request): Response
    {
        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidłowy token CSRF. Odśwież formularz i spróbuj ponownie.');

            return Response::redirect($this->config->url('/login'));
        }

        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            $this->flash->add('error', 'Podaj adres e-mail i hasło.');

            return Response::redirect($this->config->url('/login'));
        }

        if (!$this->auth->attemptLogin($email, $password, $request)) {
            $this->flash->add(
                'error',
                $this->auth->lastErrorMessage() !== null
                    ? 'Logowanie nie powiodło się, ponieważ baza danych jest niedostępna. Uruchom MySQL i zaimportuj schema.sql.'
                    : 'Nieprawidłowy login lub hasło.'
            );

            return Response::redirect($this->config->url('/login'));
        }

        $this->flash->add('info', 'Zalogowano pomyślnie.');

        return Response::redirect($this->config->url('/'));
    }

    public function logout(Request $request): Response
    {
        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nie udało się bezpiecznie wylogować. Spróbuj ponownie.');

            return Response::redirect($this->config->url('/'));
        }

        $this->auth->logout($request);
        $this->flash->add('info', 'Wylogowano z aplikacji.');

        return Response::redirect($this->config->url('/login'));
    }

    public function setupForm(Request $request): Response
    {
        unset($request);

        if (!$this->config->isConfigured()) {
            $this->flash->add('warning', 'Najpierw uzupełnij plik config/config.php.');

            return Response::redirect($this->config->url('/login'));
        }

        if (!$this->auth->databaseReady()) {
            $this->flash->add('warning', 'Baza danych jest niedostępna. Uruchom MySQL i zaimportuj database/schema.sql.');

            return Response::redirect($this->config->url('/login'));
        }

        if ($this->auth->hasAnyUsers()) {
            return Response::redirect($this->config->url('/login'));
        }

        return Response::html($this->view->render('setup_admin', [
            'title' => 'Konfiguracja pierwszego użytkownika',
            'pageTitle' => 'Utwórz pierwszego użytkownika',
            'pageDescription' => 'Ten formularz jest dostępny tylko do momentu utworzenia pierwszego konta.',
            'alerts' => [],
        ]));
    }

    public function setupAdmin(Request $request): Response
    {
        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidłowy token CSRF. Odśwież formularz i spróbuj ponownie.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        if (!$this->auth->databaseReady()) {
            $this->flash->add('warning', 'Baza danych jest niedostępna. Uruchom MySQL i zaimportuj database/schema.sql.');

            return Response::redirect($this->config->url('/login'));
        }

        if ($this->auth->hasAnyUsers()) {
            return Response::redirect($this->config->url('/login'));
        }

        $email = trim((string) $request->input('email'));
        $displayName = trim((string) $request->input('display_name'));
        $password = (string) $request->input('password');
        $passwordConfirm = (string) $request->input('password_confirm');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash->add('error', 'Podaj poprawny adres e-mail.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        if ($displayName === '') {
            $this->flash->add('error', 'Podaj nazwę użytkownika.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        if (strlen($password) < 12) {
            $this->flash->add('error', 'Hasło musi mieć co najmniej 12 znaków.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        if ($password !== $passwordConfirm) {
            $this->flash->add('error', 'Hasła nie są zgodne.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        $userId = $this->authCreateUser($email, $displayName, $password);

        if ($userId === null) {
            $this->flash->add('error', 'Nie udało się utworzyć użytkownika.');

            return Response::redirect($this->config->url('/setup-admin'));
        }

        $this->flash->add('info', 'Utworzono pierwszego użytkownika. Możesz się teraz zalogować.');

        return Response::redirect($this->config->url('/login'));
    }

    private function configurationAlert(): array
    {
        if ($this->config->isConfigured()) {
            $alerts = [];

            if (!$this->auth->databaseReady()) {
                $alerts[] = [
                    'type' => 'warning',
                    'message' => 'Baza danych jest niedostępna. Uruchom MySQL w XAMPP i zaimportuj database/schema.sql przed logowaniem.',
                ];
            }

            return $alerts;
        }

        return [[
            'type' => 'warning',
            'message' => 'Brakuje pliku config/config.php. Uzupełnij konfigurację lokalną przed logowaniem i połączeniem z bazą.',
        ]];
    }

    private function authCreateUser(string $email, string $displayName, string $password): ?int
    {
        $userId = $this->userRepository->create($email, $displayName, password_hash($password, PASSWORD_DEFAULT));
        $this->auditLogRepository->log(
            action: 'user_created',
            userId: $userId,
            entityType: 'user',
            entityId: $userId,
            context: ['email' => $email]
        );

        return $userId;
    }
}
