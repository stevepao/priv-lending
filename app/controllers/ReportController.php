<?php

declare(strict_types=1);

final class ReportController
{
    public function index(): void
    {
        $title = 'Report';
        $filter = date_range_filter_from_get($_GET);
        $monthlyRows = [];
        $totals = report_metrics_from_category_sums('0', '0', '0');

        if (!$filter['dateOrderError']) {
            $monthlyRows = $this->buildMonthlyReportRows($filter['start'], $filter['end']);
            foreach ($monthlyRows as $row) {
                $totals = report_metrics_add($totals, $row['metrics']);
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        render('report_index', [
            'title' => $title,
            'range' => $filter['range'],
            'start' => $filter['start'],
            'end' => $filter['end'],
            'dateOrderError' => $filter['dateOrderError'],
            'monthlyRows' => $monthlyRows,
            'totals' => $totals,
        ]);
    }

    /**
     * @return list<array{monthLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string}>
     */
    private function buildMonthlyReportRows(string $start, string $end): array
    {
        $aggRows = dbAll(
            'SELECT DATE_FORMAT(event_date, \'%Y-%m\') AS ym, '
            . 'COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS interest_in_sum, '
            . 'COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS loc_interest_sum, '
            . 'COALESCE(SUM(CASE WHEN category IN (?, ?) THEN amount ELSE 0 END), 0) AS principal_net_sum '
            . 'FROM cash_events WHERE event_date >= ? AND event_date <= ? '
            . 'GROUP BY ym ORDER BY ym ASC',
            ['interest', 'loc_interest', 'principal_in', 'principal_out', $start, $end]
        );
        $byYm = [];
        foreach ($aggRows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $byYm[$ym] = $row;
            }
        }

        $out = [];
        foreach (report_month_ym_keys_in_range($start, $end) as $ym) {
            $agg = $byYm[$ym] ?? null;
            $interestInRaw = is_array($agg) ? (string) ($agg['interest_in_sum'] ?? '0') : '0';
            $locSumRaw = is_array($agg) ? (string) ($agg['loc_interest_sum'] ?? '0') : '0';
            $principalNetRaw = is_array($agg) ? (string) ($agg['principal_net_sum'] ?? '0') : '0';
            $metrics = report_metrics_from_category_sums($interestInRaw, $locSumRaw, $principalNetRaw);
            $monthDt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
            $monthLabel = $monthDt instanceof DateTimeImmutable ? $monthDt->format('M Y') : $ym;
            $out[] = [
                'monthLabel' => $monthLabel,
                'metrics' => $metrics,
                'interestInDisp' => checks_format_money_display_2($metrics['interestIn']),
                'locInterestOutDisp' => checks_format_money_display_2($metrics['locInterestOut']),
                'netIncomeDisp' => checks_format_money_display_2($metrics['netIncome']),
                'principalPaidDisp' => checks_format_money_display_2($metrics['principalPaid']),
            ];
        }

        return $out;
    }
}
