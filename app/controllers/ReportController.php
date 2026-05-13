<?php

declare(strict_types=1);

final class ReportController
{
    public function index(): void
    {
        $title = 'Report';
        $parseYmd = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $defaultStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $defaultEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

        $startRaw = isset($_GET['start']) ? (string) $_GET['start'] : '';
        $endRaw = isset($_GET['end']) ? (string) $_GET['end'] : '';
        $start = $parseYmd($startRaw);
        $end = $parseYmd($endRaw);
        if ($start === null) {
            $start = $defaultStart;
        }
        if ($end === null) {
            $end = $defaultEnd;
        }

        $dateOrderError = $start > $end;

        $interestInDisp = '0.00';
        $locInterestOutDisp = '0.00';
        $principalPaidDisp = '0.00';
        $netIncomeDisp = '0.00';

        if (!$dateOrderError) {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS interest_in_sum, '
                . 'COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS loc_interest_sum, '
                . 'COALESCE(SUM(CASE WHEN category IN (?, ?) THEN amount ELSE 0 END), 0) AS principal_net_sum '
                . 'FROM cash_events WHERE event_date >= ? AND event_date <= ?'
            );
            $stmt->execute(['interest', 'loc_interest', 'principal_in', 'principal_out', $start, $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $interestInRaw = is_array($row) ? (string) ($row['interest_in_sum'] ?? '0') : '0';
            $locSumRaw = is_array($row) ? (string) ($row['loc_interest_sum'] ?? '0') : '0';
            $principalNetSumRaw = is_array($row) ? (string) ($row['principal_net_sum'] ?? '0') : '0';

            $interestInDisp = checks_normalize_money_2($interestInRaw);
            $locSumNorm = checks_normalize_money_2($locSumRaw);
            $principalPaidDisp = checks_normalize_money_2($principalNetSumRaw);

            if (extension_loaded('bcmath')) {
                $locInterestOutDisp = bcmul($locSumNorm, '-1', 2);
                $netIncomeDisp = bcsub($interestInDisp, $locInterestOutDisp, 2);
            } else {
                $locInterestOutDisp = number_format(-(float) $locSumNorm, 2, '.', '');
                $netIncomeDisp = number_format((float) $interestInDisp - (float) $locInterestOutDisp, 2, '.', '');
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-6">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<form class="flex flex-wrap items-end gap-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/report">';
        echo '<div><label class="mb-1 block text-xs font-medium text-slate-600" for="start">Start date</label>';
        echo '<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="start" name="start" type="date" value="' . e($start) . '"></div>';
        echo '<div><label class="mb-1 block text-xs font-medium text-slate-600" for="end">End date</label>';
        echo '<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="end" name="end" type="date" value="' . e($end) . '"></div>';
        echo '<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Run report</button>';
        echo '</form>';
        if ($dateOrderError) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Start date must be on or before end date.</p>';
        }
        echo '<div class="space-y-3 rounded border border-slate-200 bg-white p-4 shadow-sm">';
        echo '<h2 class="text-lg font-semibold text-slate-800">Totals</h2>';
        echo '<dl class="grid grid-cols-1 gap-2 text-sm">';
        echo '<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">Interest In</dt><dd class="font-mono font-medium text-slate-900">' . e($interestInDisp) . '</dd></div>';
        echo '<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">LOC Interest Out</dt><dd class="font-mono font-medium text-slate-900">' . e($locInterestOutDisp) . '</dd></div>';
        echo '<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">Net Income</dt><dd class="font-mono font-medium text-slate-900">' . e($netIncomeDisp) . '</dd></div>';
        echo '</dl>';
        echo '<p class="text-xs font-medium uppercase tracking-wide text-slate-500">FYI</p>';
        echo '<dl class="grid grid-cols-1 gap-2 text-sm">';
        echo '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-600">Principal Paid</dt><dd class="font-mono font-medium text-slate-900">' . e($principalPaidDisp) . '</dd></div>';
        echo '</dl>';
        echo '<p class="text-xs text-slate-500">Interest In: sum of <code class="text-xs">cash_events.amount</code> where <code class="text-xs">category = interest</code> and <code class="text-xs">event_date</code> is in range (inclusive). LOC Interest Out uses <code class="text-xs">-SUM(amount)</code> for <code class="text-xs">loc_interest</code>. Principal Paid is <code class="text-xs">SUM(amount)</code> for <code class="text-xs">principal_in</code> plus <code class="text-xs">principal_out</code> (repayments positive, funding and bank principal draws negative). Net Income = Interest In − LOC Interest Out.</p>';
        echo '</div></div></body></html>';
    }
}
