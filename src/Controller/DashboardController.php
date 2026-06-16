<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repository\AuditLogRepository;

final class DashboardController
{
    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    public function index(Request $request): Response
    {
        unset($request);

        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html($this->view->render('dashboard', [
            'title' => 'Dashboard',
            'pageTitle' => 'Panel MVP agenta KSeF',
            'pageDescription' => 'Panel jest już chroniony logowaniem, sesją i CSRF. Kolejne etapy dokładują logikę biznesową integracji.',
            'alerts' => $this->buildAlerts(),
            'cards' => [
                [
                    'title' => 'Funkcja 1',
                    'text' => 'Pobieranie faktur kosztowych z KSeF, walidacja i eksport CSV.',
                    'link' => '/ksef',
                ],
                [
                    'title' => 'Funkcja 2',
                    'text' => 'Import CSV, walidacja przelewów i eksport do pain.001.001.09.',
                    'link' => '/bank-import',
                ],
                [
                    'title' => 'Funkcja 3',
                    'text' => 'Porównanie danych z KSeF z plikiem JPK i wynik CSV.',
                    'link' => '/accounting-compare',
                ],
            ],
        ]));
    }

    public function history(Request $request): Response
    {
        unset($request);

        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        return Response::html($this->view->render('history', [
            'title' => 'Historia operacji',
            'pageTitle' => 'Historia operacji',
            'pageDescription' => 'Na tym ekranie widać audit log z logowań i zdarzeń bezpieczeństwa. Kolejne etapy dołożą wpisy operacyjne modułów.',
            'alerts' => [],
            'entries' => $this->auditLogRepository->recent(30),
        ]));
    }

    private function buildAlerts(): array
    {
        $alerts = [[
            'type' => 'info',
            'message' => 'Etap 2 dołożył logowanie, sesje, CSRF, nagłówki bezpieczeństwa i audit log.',
        ]];

        if (!$this->config->isConfigured()) {
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Utwórz plik config/config.php na podstawie config/config.example.php przed uruchomieniem połączeń do bazy i integracji.',
            ];
        }

        return $alerts;
    }
}
