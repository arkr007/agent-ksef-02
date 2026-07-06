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

        return Response::redirect($this->config->url('/accountant-package'));
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
            'pageDescription' => 'Na tym ekranie widac audit log z logowan i zdarzen bezpieczenstwa.',
            'alerts' => [],
            'entries' => $this->auditLogRepository->recent(30),
        ]));
    }
}
