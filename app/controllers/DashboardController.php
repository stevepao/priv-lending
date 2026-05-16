<?php

declare(strict_types=1);

final class DashboardController
{
    private const FUNDING_JPM = 'JPM';
    private const FUNDING_NTRS = 'NTRS';

    public function index(): void
    {
        $slices = $this->dashboardRecentThreeMonthSlices();
        $jpmSection = $this->dashboardLoansSectionForFunding(self::FUNDING_JPM);
        $ntrsSection = $this->dashboardLoansSectionForFunding(self::FUNDING_NTRS);
        $jpmLoans = $jpmSection['rows'];
        $jpmLoansTotalBalanceDisp = $jpmSection['totalBalanceDisp'];
        $ntrsLoans = $ntrsSection['rows'];
        $ntrsLoansTotalBalanceDisp = $ntrsSection['totalBalanceDisp'];
        $jpmMonths = $this->dashboardMonthlyRowsForBank(self::FUNDING_JPM, $slices);
        $ntrsMonths = $this->dashboardMonthlyRowsForBank(self::FUNDING_NTRS, $slices);

        header('Content-Type: text/html; charset=utf-8');
        render('dashboard_index', [
            'title' => 'Dashboard',
            'heading' => 'Dashboard',
            'jpmLoans' => $jpmLoans,
            'jpmLoansTotalBalanceDisp' => $jpmLoansTotalBalanceDisp,
            'ntrsLoans' => $ntrsLoans,
            'ntrsLoansTotalBalanceDisp' => $ntrsLoansTotalBalanceDisp,
            'jpmMonths' => $jpmMonths,
            'ntrsMonths' => $ntrsMonths,
        ]);
    }

    /**
     * @return list<array{ym: string, label: string, start: string, end: string}>
     */
    private function dashboardRecentThreeMonthSlices(): array
    {
        $today = new DateTimeImmutable('today');
        $monthStart = $today->modify('first day of this month');
        $out = [];
        for ($i = 2; $i >= 0; $i--) {
            $first = $monthStart->modify('-' . $i . ' months');
            $ym = $first->format('Y-m');
            $label = $first->format('M Y');
            if ($ym === $today->format('Y-m')) {
                $end = $today;
            } else {
                $end = $first->modify('last day of this month');
            }
            $out[] = [
                'ym' => $ym,
                'label' => $label,
                'start' => $first->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
            ];
        }

        return $out;
    }

    /**
     * @return array{rows: list<array{entityName: string, loanName: string, balanceDisp: string}>, totalBalanceDisp: string}
     */
    private function dashboardLoansSectionForFunding(string $funding): array
    {
        $openOnlySql = schema_table_has_column('loans', 'closed_date')
            ? ' AND l.closed_date IS NULL'
            : '';
        $rows = dbAll(
            'SELECT l.name AS loan_name, e.name AS entity_name, '
            . loan_sql_cash_principal_balance_subquery() . ' AS balance_raw '
            . 'FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
            . 'WHERE l.funding_source = ?' . $openOnlySql . ' '
            . 'ORDER BY l.origin_date ASC, l.id ASC',
            [$funding]
        );
        $out = [];
        $total = '0.00';
        foreach ($rows as $r) {
            $bal = checks_normalize_money_2((string) ($r['balance_raw'] ?? '0'));
            $total = checks_add_money_2($total, $bal);
            $out[] = [
                'entityName' => (string) ($r['entity_name'] ?? ''),
                'loanName' => (string) ($r['loan_name'] ?? ''),
                'balanceDisp' => checks_format_money_display_2($bal),
            ];
        }

        return [
            'rows' => $out,
            'totalBalanceDisp' => checks_format_money_display_2($total),
        ];
    }

    /**
     * @param list<array{ym: string, label: string, start: string, end: string}> $slices
     *
     * @return list<array{monthLabel: string, interestDisp: string, locDisp: string, principalInDisp: string}>
     */
    private function dashboardMonthlyRowsForBank(string $bank, array $slices): array
    {
        if ($slices === []) {
            return [];
        }
        $minStart = $slices[0]['start'];
        $maxEnd = $slices[count($slices) - 1]['end'];
        $aggRows = dbAll(
            'SELECT DATE_FORMAT(ce.event_date, \'%Y-%m\') AS ym, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS interest_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS loc_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS principal_in_sum '
            . 'FROM cash_events ce '
            . 'WHERE ce.deposit_to = ? AND ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY ym ORDER BY ym ASC',
            ['interest', 'loc_interest', 'principal_in', $bank, $minStart, $maxEnd]
        );
        $byYm = [];
        foreach ($aggRows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $byYm[$ym] = $row;
            }
        }

        $out = [];
        foreach ($slices as $sl) {
            $ym = $sl['ym'];
            $agg = $byYm[$ym] ?? null;
            $interestRaw = is_array($agg) ? (string) ($agg['interest_sum'] ?? '0') : '0';
            $locRaw = is_array($agg) ? (string) ($agg['loc_sum'] ?? '0') : '0';
            $piRaw = is_array($agg) ? (string) ($agg['principal_in_sum'] ?? '0') : '0';
            $out[] = [
                'monthLabel' => $sl['label'],
                'interestDisp' => checks_format_money_display_2(checks_normalize_money_2($interestRaw)),
                'locDisp' => checks_format_money_display_2(report_loc_interest_pool_positive($locRaw)),
                'principalInDisp' => checks_format_money_display_2(checks_normalize_money_2($piRaw)),
            ];
        }

        return $out;
    }
}
