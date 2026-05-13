<?php

declare(strict_types=1);

final class DashboardController
{
    public function index(): void
    {
        $title = 'Dashboard';
        $heading = 'Dashboard';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<p>' . e($heading) . '</p>';
        echo '<p><a href="/borrowers">Borrowers</a> · <a href="/entities">Entities</a> · <a href="/loans">Loans</a> · <a href="/checks">Checks</a> · <a href="/cash-events">Cash events</a> · <a href="/bank">Bank</a> · <a href="/report">Report</a></p>';
        echo '<form method="post" action="/logout">' . csrf_field() . '<button type="submit">Sign out</button></form>';
        echo '</body></html>';
    }
}
