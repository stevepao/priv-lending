<?php

declare(strict_types=1);

final class DashboardController
{
    public function index(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        render('dashboard_index', [
            'title' => 'Dashboard',
            'heading' => 'Dashboard',
        ]);
    }
}
