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
            'pageDescription' => 'Panel jest juz chroniony logowaniem, sesja i CSRF. Kolejne etapy dokladaja logike biznesowa integracji.',
            'alerts' => $this->buildAlerts(),
            'cards' => [
                [
                    'title' => 'Funkcja 1',
                    'text' => 'Pobieranie faktur kosztowych z KSeF, walidacja i eksport CSV.',
                    'link' => '/ksef',
                ],
                [
                    'title' => 'Funkcja 2',
                    'text' => 'Import CSV, walidacja przelewow i eksport do pain.001.001.09.',
                    'link' => '/bank-import',
                ],
                [
                    'title' => 'Funkcja 3',
                    'text' => 'Porownanie danych z KSeF z plikiem JPK i wynik CSV.',
                    'link' => '/accounting-compare',
                ],
                [
                    'title' => 'Funkcja 4',
                    'text' => 'Pakiet dla ksiegowej: kontrola kompletnej paczki faktur kosztowych za miesiac.',
                    'link' => '/accountant-package',
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
            'pageDescription' => 'Na tym ekranie widac audit log z logowan i zdarzen bezpieczenstwa. Kolejne etapy doloza wpisy operacyjne modulow.',
            'alerts' => [],
            'entries' => $this->auditLogRepository->recent(30),
        ]));
    }

    private function buildAlerts(): array
    {
        $alerts = [[
            'type' => 'info',
            'message' => 'Etap 2 dolozyl logowanie, sesje, CSRF, naglowki bezpieczenstwa i audit log.',
        ]];

        if (!$this->config->isConfigured()) {
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Utworz plik config/config.php na podstawie config/config.example.php przed uruchomieniem polaczen do bazy i integracji.',
            ];
        }

        return $alerts;
    }
}
