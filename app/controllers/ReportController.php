<?php

declare(strict_types=1);

final class ReportController
{
    /** @var list<string> */
    private const REPORT_TYPES = ['month', 'bank', 'month_bank', 'loan', 'entity'];

    /** Note when some period LOC could not be split by loan-balance weights (shown for By loan / By entity). */
    private string $locAllocUnallocatedNote = '';

    public function index(): void
    {
        $this->locAllocUnallocatedNote = '';
        $title = 'Report';
        $filter = date_range_filter_from_get($_GET);
        $reportType = $this->reportTypeFromGet($_GET);
        $detailRows = [];
        $totals = report_metrics_from_category_sums('0', '0', '0');

        if (!$filter['dateOrderError']) {
            $detailRows = match ($reportType) {
                'bank' => $this->buildByBankRows($filter['start'], $filter['end']),
                'month_bank' => $this->buildByMonthBankRows($filter['start'], $filter['end']),
                'loan' => $this->buildByLoanRows($filter['start'], $filter['end']),
                'entity' => $this->buildByEntityRows($filter['start'], $filter['end']),
                default => $this->buildMonthlyReportRows($filter['start'], $filter['end']),
            };
            foreach ($detailRows as $row) {
                $totals = report_metrics_add($totals, $row['metrics']);
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        render('report_index', [
            'title' => $title,
            'reportType' => $reportType,
            'range' => $filter['range'],
            'start' => $filter['start'],
            'end' => $filter['end'],
            'dateOrderError' => $filter['dateOrderError'],
            'detailRows' => $detailRows,
            'totals' => $totals,
            'locAllocUnallocatedNote' => $this->locAllocUnallocatedNote,
        ]);
    }

    /**
     * @param array<string, mixed> $get
     */
    private function reportTypeFromGet(array $get): string
    {
        $t = isset($get['report_type']) ? (string) $get['report_type'] : 'month';

        return in_array($t, self::REPORT_TYPES, true) ? $t : 'month';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string}
     */
    private function displayRowFromAgg(array $row): array
    {
        $metrics = report_metrics_from_category_sums(
            (string) ($row['interest_in_sum'] ?? '0'),
            (string) ($row['loc_interest_sum'] ?? '0'),
            (string) ($row['principal_net_sum'] ?? '0')
        );

        return [
            'metrics' => $metrics,
            'interestInDisp' => checks_format_money_display_2($metrics['interestIn']),
            'locInterestOutDisp' => checks_format_money_display_2($metrics['locInterestOut']),
            'netIncomeDisp' => checks_format_money_display_2($metrics['netIncome']),
            'principalPaidDisp' => checks_format_money_display_2($metrics['principalPaid']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string, allocLocComputed: true}
     */
    private function displayRowAllocatedLoc(array $row, string $locAllocPositive): array
    {
        $metrics = report_metrics_from_interest_principal_alloc_loc(
            (string) ($row['interest_in_sum'] ?? '0'),
            $locAllocPositive,
            (string) ($row['principal_net_sum'] ?? '0')
        );

        return [
            'metrics' => $metrics,
            'interestInDisp' => checks_format_money_display_2($metrics['interestIn']),
            'locInterestOutDisp' => checks_format_money_display_2($metrics['locInterestOut']),
            'netIncomeDisp' => checks_format_money_display_2($metrics['netIncome']),
            'principalPaidDisp' => checks_format_money_display_2($metrics['principalPaid']),
            'allocLocComputed' => true,
        ];
    }

    private function depositToKey(mixed $depositTo): string
    {
        if ($depositTo === null || (string) $depositTo === '') {
            return '';
        }

        return (string) $depositTo;
    }

    private function loanSegmentKey(mixed $loanId): string
    {
        if ($loanId === null || $loanId === '') {
            return 'loan:null';
        }

        return 'loan:' . (string) (int) $loanId;
    }

    private function entitySegmentKey(mixed $entityId): string
    {
        if ($entityId === null || $entityId === '') {
            return 'entity:null';
        }

        return 'entity:' . (string) (int) $entityId;
    }

    /**
     * @return array<string, string> deposit_to key => positive LOC pool for the period
     */
    private function locInterestPoolsByDeposit(string $start, string $end): array
    {
        $rows = dbAll(
            'SELECT ce.deposit_to, COALESCE(SUM(ce.amount), 0) AS loc_sum '
            . 'FROM cash_events ce '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? AND ce.category = ? '
            . 'GROUP BY ce.deposit_to',
            [$start, $end, 'loc_interest']
        );
        $out = [];
        foreach ($rows as $r) {
            $k = $this->depositToKey($r['deposit_to'] ?? null);
            $out[$k] = report_loc_interest_pool_positive((string) ($r['loc_sum'] ?? '0'));
        }

        return $out;
    }

    /**
     * @return list<array{loan_id: int|string|null, entity_id: int|string|null, funding_source: string|null, balance_raw: string|float}>
     */
    private function loanLedgerRowsForLocAllocation(): array
    {
        $balSub = loan_sql_cash_principal_balance_subquery();

        return dbAll(
            'SELECT l.id AS loan_id, l.entity_id, l.funding_source, ' . $balSub . ' AS balance_raw FROM loans l',
            []
        );
    }

    /**
     * Weights from each loan’s cash principal ledger balance (same as Loans list) and Funding source (matches Deposit to).
     *
     * @return array<string, array<string, string>>
     */
    private function locAllocationWeightsByLoanSegment(): array
    {
        $weights = [];
        foreach ($this->loanLedgerRowsForLocAllocation() as $r) {
            $seg = $this->loanSegmentKey($r['loan_id'] ?? null);
            $bk = $this->depositToKey($r['funding_source'] ?? null);
            $w = report_principal_ledger_balance_weight((string) ($r['balance_raw'] ?? '0'));
            if (!isset($weights[$seg])) {
                $weights[$seg] = [];
            }
            $prev = $weights[$seg][$bk] ?? '0.00';
            $weights[$seg][$bk] = checks_add_money_2($prev, $w);
        }

        return $weights;
    }

    /**
     * Same balances as {@see locAllocationWeightsByLoanSegment}, aggregated by borrowing entity.
     *
     * @return array<string, array<string, string>>
     */
    private function locAllocationWeightsByEntitySegment(): array
    {
        $weights = [];
        foreach ($this->loanLedgerRowsForLocAllocation() as $r) {
            $seg = $this->entitySegmentKey($r['entity_id'] ?? null);
            $bk = $this->depositToKey($r['funding_source'] ?? null);
            $w = report_principal_ledger_balance_weight((string) ($r['balance_raw'] ?? '0'));
            if (!isset($weights[$seg])) {
                $weights[$seg] = [];
            }
            $prev = $weights[$seg][$bk] ?? '0.00';
            $weights[$seg][$bk] = checks_add_money_2($prev, $w);
        }

        return $weights;
    }

    private function setLocAllocUnallocatedNoteIfNeeded(array $poolsByDeposit, array $allocBySegment): void
    {
        $totalPools = report_sum_money_map($poolsByDeposit);
        $sumAlloc = '0.00';
        foreach ($allocBySegment as $v) {
            $sumAlloc = checks_add_money_2($sumAlloc, $v);
        }
        if (extension_loaded('bcmath')) {
            $unalloc = bcsub($totalPools, $sumAlloc, 2);
            if (bccomp($unalloc, '0.01', 2) < 0) {
                return;
            }
        } else {
            $unalloc = number_format((float) $totalPools - (float) $sumAlloc, 2, '.', '');
            if ((float) $unalloc < 0.01) {
                return;
            }
        }
        $this->locAllocUnallocatedNote = 'Roughly ' . checks_format_money_display_2($unalloc)
            . ' of line-of-credit interest in this range is not attributed above (no loans with outstanding balance on record for that bank’s funding source to weight the split).';
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

    /**
     * @return list<array{bankLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string}>
     */
    private function buildByBankRows(string $start, string $end): array
    {
        $aggRows = dbAll(
            'SELECT ce.deposit_to AS deposit_key, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS interest_in_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS loc_interest_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category IN (?, ?) THEN ce.amount ELSE 0 END), 0) AS principal_net_sum '
            . 'FROM cash_events ce '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY ce.deposit_to '
            . 'ORDER BY COALESCE(ce.deposit_to, \'zzzzzz\') ASC',
            ['interest', 'loc_interest', 'principal_in', 'principal_out', $start, $end]
        );

        return $this->depositRowsToBankLabels($aggRows);
    }

    /**
     * @param list<array<string, mixed>> $aggRows
     *
     * @return list<array{bankLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string}>
     */
    private function depositRowsToBankLabels(array $aggRows): array
    {
        $out = [];
        foreach ($aggRows as $row) {
            $key = array_key_exists('deposit_key', $row) ? $row['deposit_key'] : null;
            $bankLabel = ($key !== null && (string) $key !== '')
                ? (string) $key
                : '—';
            $disp = $this->displayRowFromAgg($row);
            $out[] = array_merge(['bankLabel' => $bankLabel], $disp);
        }

        return $out;
    }

    /**
     * @return list<array{monthLabel: string, bankLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string}>
     */
    private function buildByMonthBankRows(string $start, string $end): array
    {
        $aggRows = dbAll(
            'SELECT DATE_FORMAT(ce.event_date, \'%Y-%m\') AS ym, ce.deposit_to AS deposit_key, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS interest_in_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS loc_interest_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category IN (?, ?) THEN ce.amount ELSE 0 END), 0) AS principal_net_sum '
            . 'FROM cash_events ce '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY ym, ce.deposit_to '
            . 'ORDER BY ym ASC, COALESCE(ce.deposit_to, \'zzzzzz\') ASC',
            ['interest', 'loc_interest', 'principal_in', 'principal_out', $start, $end]
        );

        $out = [];
        foreach ($aggRows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            $monthDt = DateTimeImmutable::createFromFormat('Y-m-d', $ym !== '' ? $ym . '-01' : '');
            $monthLabel = ($monthDt instanceof DateTimeImmutable) ? $monthDt->format('M Y') : $ym;
            $key = $row['deposit_key'] ?? null;
            $bankLabel = ($key !== null && (string) $key !== '')
                ? (string) $key
                : '—';
            $disp = $this->displayRowFromAgg($row);
            $out[] = array_merge([
                'monthLabel' => $monthLabel,
                'bankLabel' => $bankLabel,
            ], $disp);
        }

        return $out;
    }

    /**
     * @return list<array{loanLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string, allocLocComputed?: true}>
     */
    private function buildByLoanRows(string $start, string $end): array
    {
        $aggRows = dbAll(
            'SELECT ce.loan_id, l.name AS loan_name, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS interest_in_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category IN (?, ?) THEN ce.amount ELSE 0 END), 0) AS principal_net_sum '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY ce.loan_id, l.name '
            . 'ORDER BY (ce.loan_id IS NULL), loan_name ASC, ce.loan_id ASC',
            ['interest', 'principal_in', 'principal_out', $start, $end]
        );

        $pools = $this->locInterestPoolsByDeposit($start, $end);
        $weights = $this->locAllocationWeightsByLoanSegment();
        $allocBySeg = report_allocate_loc_interest_by_principal_weights($pools, $weights);
        $this->setLocAllocUnallocatedNoteIfNeeded($pools, $allocBySeg);

        $out = [];
        foreach ($aggRows as $row) {
            $lid = $row['loan_id'] ?? null;
            $loanName = (string) ($row['loan_name'] ?? '');
            if ($lid === null || $lid === '') {
                $loanLabel = 'Not on a loan';
            } elseif ($loanName !== '') {
                $loanLabel = $loanName;
            } else {
                $loanLabel = 'Loan #' . (string) (int) $lid;
            }
            $seg = $this->loanSegmentKey($lid);
            $locAlloc = $allocBySeg[$seg] ?? '0.00';
            $disp = $this->displayRowAllocatedLoc($row, $locAlloc);
            $out[] = array_merge(['loanLabel' => $loanLabel], $disp);
        }

        return $out;
    }

    /**
     * @return list<array{entityLabel: string, metrics: array{interestIn: string, locInterestOut: string, netIncome: string, principalPaid: string}, interestInDisp: string, locInterestOutDisp: string, netIncomeDisp: string, principalPaidDisp: string, allocLocComputed?: true}>
     */
    private function buildByEntityRows(string $start, string $end): array
    {
        $aggRows = dbAll(
            'SELECT l.entity_id, e.name AS entity_name, '
            . 'COALESCE(SUM(CASE WHEN ce.category = ? THEN ce.amount ELSE 0 END), 0) AS interest_in_sum, '
            . 'COALESCE(SUM(CASE WHEN ce.category IN (?, ?) THEN ce.amount ELSE 0 END), 0) AS principal_net_sum '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'LEFT JOIN entities e ON e.id = l.entity_id '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY l.entity_id, e.name '
            . 'ORDER BY (l.entity_id IS NULL), entity_name ASC, l.entity_id ASC',
            ['interest', 'principal_in', 'principal_out', $start, $end]
        );

        $pools = $this->locInterestPoolsByDeposit($start, $end);
        $weights = $this->locAllocationWeightsByEntitySegment();
        $allocBySeg = report_allocate_loc_interest_by_principal_weights($pools, $weights);
        $this->setLocAllocUnallocatedNoteIfNeeded($pools, $allocBySeg);

        $out = [];
        foreach ($aggRows as $row) {
            $eid = $row['entity_id'] ?? null;
            $ename = (string) ($row['entity_name'] ?? '');
            if ($eid === null || $eid === '') {
                $entityLabel = 'Not on an entity loan';
            } elseif ($ename !== '') {
                $entityLabel = $ename;
            } else {
                $entityLabel = 'Entity #' . (string) (int) $eid;
            }
            $seg = $this->entitySegmentKey($eid);
            $locAlloc = $allocBySeg[$seg] ?? '0.00';
            $disp = $this->displayRowAllocatedLoc($row, $locAlloc);
            $out[] = array_merge(['entityLabel' => $entityLabel], $disp);
        }

        return $out;
    }
}
