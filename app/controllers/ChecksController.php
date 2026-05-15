<?php

declare(strict_types=1);

final class ChecksController
{
    public function index(): void
    {
        $title = 'Monthly Check Batches';
        $monthParam = $_GET['month'] ?? '';
        $selectedYm = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $parsedMonth = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($parsedMonth instanceof DateTimeImmutable && $parsedMonth->format('Y-m') === $monthParam) {
                $selectedYm = $monthParam;
            }
        }

        $rows = checks_fetch_loan_rows_for_checks_page($selectedYm);
        $monthlyRows = [];
        $prepaidRows = [];
        foreach ($rows as $row) {
            $ptype = (string) ($row['payment_type'] ?? '');
            if ($ptype === 'prepaid') {
                $pDateRaw = $row['prepaid_interest_date'] ?? null;
                $pDateStr = $pDateRaw !== null && $pDateRaw !== '' ? (string) $pDateRaw : null;
                if (checks_selected_month_within_prepaid_window($pDateStr, $selectedYm)) {
                    $prepaidRows[] = $row;
                } else {
                    $origin = (string) ($row['origin_date'] ?? '');
                    if (checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
                        $monthlyRows[] = $row;
                    }
                }

                continue;
            }
            if (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                $origin = (string) ($row['origin_date'] ?? '');
                if (checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
                    $monthlyRows[] = $row;
                }
            }
        }

        $monthlyDisplayRows = $this->sortChecksDisplayRowsByFundingThenLoan(
            $this->buildMonthlyDisplayRows($monthlyRows, $selectedYm)
        );
        $prepaidDisplayRows = $this->sortChecksDisplayRowsByFundingThenLoan(
            $this->buildPrepaidDisplayRows($prepaidRows)
        );

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $showScheduledCheckMigrationBanner = !schema_table_has_column('cash_events', 'scheduled_check_ym');
        $showPrepaidMigrationBanner = !schema_table_has_column('loans', 'prepaid_interest_received');
        $showChecksCategoryUniqueMigrationBanner = schema_table_has_column('cash_events', 'scheduled_check_ym')
            && !schema_cash_events_has_scheduled_category_unique();
        $postedSuccess = isset($_GET['posted']) && (string) $_GET['posted'] === '1';
        $postedFailure = isset($_GET['posted']) && (string) $_GET['posted'] === '0';

        header('Content-Type: text/html; charset=utf-8');
        render('checks', [
            'title' => $title,
            'selectedYm' => $selectedYm,
            'monthlyDisplayRows' => $monthlyDisplayRows,
            'prepaidDisplayRows' => $prepaidDisplayRows,
            'monthlyRowsEmpty' => $monthlyRows === [],
            'prepaidRowsEmpty' => $prepaidRows === [],
            'today' => $today,
            'showScheduledCheckMigrationBanner' => $showScheduledCheckMigrationBanner,
            'showPrepaidMigrationBanner' => $showPrepaidMigrationBanner,
            'showChecksCategoryUniqueMigrationBanner' => $showChecksCategoryUniqueMigrationBanner,
            'postedSuccess' => $postedSuccess,
            'postedFailure' => $postedFailure,
        ]);
    }

    /**
     * @param list<array{fundingSource: string, loanName: string}> $rows
     *
     * @return list<array{fundingSource: string, loanName: string}>
     */
    private function sortChecksDisplayRowsByFundingThenLoan(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $byFunding = strcasecmp($a['fundingSource'], $b['fundingSource']);
            if ($byFunding !== 0) {
                return $byFunding;
            }

            return strcasecmp($a['loanName'], $b['loanName']);
        });

        return $rows;
    }

    private function checksExpectedPaymentAmountHtml(string $amount, string $lineClass): string
    {
        return '<div class="' . $lineClass . ' text-right font-mono tabular-nums">'
            . e(checks_format_money_display_2($amount)) . '</div>';
    }

    private function checksExpectedPaymentLabeledHtml(string $label, string $amount, string $lineClass): string
    {
        return '<div class="' . $lineClass . ' text-right font-mono tabular-nums">'
            . e($label) . e(checks_format_money_display_2($amount)) . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $monthlyRows
     *
     * @return list<array{fundingSource: string, loanName: string, calcMethod: string, expectedCellHtml: string, postCell: string, statusCell: string}>
     */
    private function buildMonthlyDisplayRows(array $monthlyRows, string $selectedYm): array
    {
        $out = [];
        foreach ($monthlyRows as $row) {
            $loanId = (int) ($row['id'] ?? 0);
            $fundingSrc = checks_funding_source_for_row($row);
            $fundingSource = $fundingSrc !== null ? $fundingSrc : '—';
            $loanName = (string) ($row['name'] ?? '');
            $origin = (string) ($row['origin_date'] ?? '');
            $principalStr = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '0.00';
            $annualStr = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '0.000';
            $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
            if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
                $calcMethod = 'fixed';
            }
            $mppStr = $row['principal_payment_monthly'] !== null && $row['principal_payment_monthly'] !== '' ? (string) $row['principal_payment_monthly'] : '0.00';
            $monthlyIntStr = $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';

            $expectedCellHtml = '<span class="text-slate-400">—</span>';
            $paidOff = false;
            if ($calcMethod === 'fixed') {
                if ($monthlyIntStr !== '') {
                    $paymentStr = checks_normalize_money_2($monthlyIntStr);
                } else {
                    $paymentStr = loan_simple_monthly_interest($principalStr, $annualStr);
                }
                $expectedCellHtml = $this->checksExpectedPaymentAmountHtml($paymentStr, 'font-medium text-slate-900');
            } else {
                $monthsElapsed = loan_months_elapsed_to_calendar_month($origin, $selectedYm);
                $remainingStr = loan_remaining_principal_after_paydowns($principalStr, $mppStr, $monthsElapsed);
                if (extension_loaded('bcmath')) {
                    $paidOff = bccomp($remainingStr, '0', 2) <= 0;
                } else {
                    $paidOff = (float) $remainingStr <= 0.0;
                }
                if ($paidOff) {
                    $expectedCellHtml = '<div class="text-right"><div class="font-medium text-slate-400">—</div>'
                        . '<div class="text-xs text-slate-500">Paid off</div></div>';
                } else {
                    $interestStr = checks_declining_monthly_interest($remainingStr, $annualStr);
                    $principalPortionStr = checks_normalize_money_2($mppStr);
                    $paymentStr = checks_add_money_2($interestStr, $principalPortionStr);
                    $expectedCellHtml = '<div class="space-y-0.5">'
                        . $this->checksExpectedPaymentAmountHtml($paymentStr, 'font-medium text-slate-900')
                        . $this->checksExpectedPaymentLabeledHtml('interest: ', $interestStr, 'text-xs text-slate-500')
                        . $this->checksExpectedPaymentLabeledHtml('principal: ', $principalPortionStr, 'text-xs text-slate-500')
                        . '</div>';
                }
            }

            $monthlyPosted = checks_monthly_check_already_posted($row);
            $paymentTotal = checks_expected_payment_total_for_row($row, $selectedYm);

            if ($monthlyPosted) {
                $postCell = '<span class="text-slate-400">—</span>';
                $statusCell = '<span class="font-medium text-emerald-800">Posted</span>';
            } elseif ($calcMethod === 'declining_balance' && $paidOff) {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" disabled class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">Paid off</span>';
            } elseif ($paymentTotal === null) {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" disabled class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">No payment</span>';
            } else {
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" name="loan_ids[]" value="'
                    . e((string) $loanId) . '" class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post this check</span></label>';
                $statusCell = '<span class="text-slate-600">Not posted</span>';
            }

            $out[] = [
                'fundingSource' => $fundingSource,
                'loanName' => $loanName,
                'calcMethod' => $calcMethod,
                'expectedCellHtml' => $expectedCellHtml,
                'postCell' => $postCell,
                'statusCell' => $statusCell,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $prepaidRows
     *
     * @return list<array{fundingSource: string, loanName: string, pAmtCellHtml: string, postCell: string, statusCell: string}>
     */
    private function buildPrepaidDisplayRows(array $prepaidRows): array
    {
        $out = [];
        foreach ($prepaidRows as $row) {
            $loanId = (int) ($row['id'] ?? 0);
            $fundingSrc = checks_funding_source_for_row($row);
            $fundingSource = $fundingSrc !== null ? $fundingSrc : '—';
            $loanName = (string) ($row['name'] ?? '');
            $pAmt = checks_prepaid_interest_amount_db_string($row);
            $pAmtCellHtml = $pAmt !== null
                ? $this->checksExpectedPaymentAmountHtml($pAmt, 'font-medium text-slate-900')
                : '<div class="text-right font-medium text-slate-400">—</div>';
            $received = checks_prepaid_interest_already_received($row);
            $postCell = '<span class="text-slate-400">—</span>';
            $statusCell = '<span class="text-slate-500">—</span>';
            if ($received) {
                $statusCell = '<span class="font-medium text-emerald-800">Posted</span>';
            } elseif ($pAmt === null) {
                $statusCell = '<span class="text-amber-800">Invalid amount</span>';
            } elseif (!schema_table_has_column('loans', 'prepaid_interest_received')) {
                $statusCell = '<span class="text-slate-500">Migration required</span>';
            } else {
                $statusCell = '<span class="text-slate-600">Not posted</span>';
                $postCell = '<label class="inline-flex items-center gap-2"><input type="checkbox" name="prepaid_loan_ids[]" value="'
                    . e((string) $loanId) . '" class="h-4 w-4 rounded border-slate-300"> <span class="sr-only">Post prepaid interest</span></label>';
            }

            $out[] = [
                'fundingSource' => $fundingSource,
                'loanName' => $loanName,
                'pAmtCellHtml' => $pAmtCellHtml,
                'postCell' => $postCell,
                'statusCell' => $statusCell,
            ];
        }

        return $out;
    }

    public function store(): void
    {
        csrf_verify_or_die();

        $monthParam = trim((string) ($_POST['month'] ?? ''));
        $selectedYm = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (preg_match('/^\d{4}-\d{2}$/', $monthParam) === 1) {
            $parsedMonth = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($parsedMonth instanceof DateTimeImmutable && $parsedMonth->format('Y-m') === $monthParam) {
                $selectedYm = $monthParam;
            }
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEvent = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        $eventDate = $parsedEvent instanceof DateTimeImmutable && $parsedEvent->format('Y-m-d') === $eventDateRaw
            ? $eventDateRaw
            : null;

        $loanIdsPost = $_POST['loan_ids'] ?? [];
        if (!is_array($loanIdsPost)) {
            $loanIdsPost = [];
        }
        $loanIdSet = [];
        foreach ($loanIdsPost as $raw) {
            $lid = (int) $raw;
            if ($lid > 0) {
                $loanIdSet[$lid] = true;
            }
        }
        $loanIdList = array_keys($loanIdSet);

        $prepaidIdsPost = $_POST['prepaid_loan_ids'] ?? [];
        if (!is_array($prepaidIdsPost)) {
            $prepaidIdsPost = [];
        }
        $prepaidIdSet = [];
        foreach ($prepaidIdsPost as $raw) {
            $pid = (int) $raw;
            if ($pid > 0) {
                $prepaidIdSet[$pid] = true;
            }
        }
        $prepaidIdList = array_keys($prepaidIdSet);

        if ($eventDate === null || ($loanIdList === [] && $prepaidIdList === [])) {
            header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=0');
            exit;
        }

        $rows = checks_fetch_loan_rows_for_checks_page($selectedYm);
        $eligibleMonthlyById = [];
        $eligiblePrepaidById = [];
        foreach ($rows as $row) {
            $lid = (int) ($row['id'] ?? 0);
            if ($lid < 1) {
                continue;
            }
            $ptype = (string) ($row['payment_type'] ?? '');
            if ($ptype === 'prepaid') {
                $pDateRaw = $row['prepaid_interest_date'] ?? null;
                $pDateStr = $pDateRaw !== null && $pDateRaw !== '' ? (string) $pDateRaw : null;
                if (checks_selected_month_within_prepaid_window($pDateStr, $selectedYm)) {
                    $eligiblePrepaidById[$lid] = $row;
                } else {
                    $origin = (string) ($row['origin_date'] ?? '');
                    if (checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
                        $eligibleMonthlyById[$lid] = $row;
                    }
                }
            } elseif (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                $origin = (string) ($row['origin_date'] ?? '');
                if (checks_selected_month_is_after_loan_origin_month($origin, $selectedYm)) {
                    $eligibleMonthlyById[$lid] = $row;
                }
            }
        }

        if (!schema_table_has_column('cash_events', 'scheduled_check_ym')) {
            header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=0');
            exit;
        }

        $pdo = db();
        $insertCashStmt = $pdo->prepare(
            'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $posted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($loanIdList as $loanId) {
                if (!isset($eligibleMonthlyById[$loanId])) {
                    continue;
                }
                $row = $eligibleMonthlyById[$loanId];
                if (checks_monthly_check_already_posted($row)) {
                    continue;
                }
                $split = checks_expected_payment_interest_principal_split_for_row($row, $selectedYm);
                if ($split === null) {
                    continue;
                }
                $depositTo = checks_funding_source_for_row($row);
                if ($depositTo === null) {
                    continue;
                }
                $interestAmt = $split['interest'];
                $principalAmt = $split['principal_in'];
                $interestPositive = extension_loaded('bcmath')
                    ? bccomp($interestAmt, '0', 2) === 1
                    : (float) $interestAmt > 0.0;
                $principalPositive = extension_loaded('bcmath')
                    ? bccomp($principalAmt, '0', 2) === 1
                    : (float) $principalAmt > 0.0;
                if (!$interestPositive && !$principalPositive) {
                    continue;
                }
                $baseNotes = 'Checks ' . $selectedYm . ' (from /checks)';
                $sp = 'sp_ce_' . $loanId;
                $pdo->exec('SAVEPOINT `' . $sp . '`');
                try {
                    if ($interestPositive) {
                        $insertCashStmt->execute([
                            $loanId,
                            $selectedYm,
                            $eventDate,
                            $interestAmt,
                            'interest',
                            $depositTo,
                            $baseNotes . ' — interest',
                        ]);
                    }
                    if ($principalPositive) {
                        $insertCashStmt->execute([
                            $loanId,
                            $selectedYm,
                            $eventDate,
                            $principalAmt,
                            'principal_in',
                            $depositTo,
                            $baseNotes . ' — principal',
                        ]);
                    }
                    $pdo->exec('RELEASE SAVEPOINT `' . $sp . '`');
                    ++$posted;
                } catch (PDOException $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT `' . $sp . '`');
                    $sqlState = $e->errorInfo[1] ?? null;
                    if ((int) $sqlState === 1062) {
                        continue;
                    }
                    throw $e;
                }
            }

            if ($prepaidIdList !== [] && schema_table_has_column('loans', 'prepaid_interest_received')) {
                $updPrepaid = $pdo->prepare(
                    'UPDATE loans SET prepaid_interest_received = 1 WHERE id = ? AND payment_type = \'prepaid\' AND prepaid_interest_received = 0'
                );
                $delEvent = $pdo->prepare('DELETE FROM cash_events WHERE id = ?');
                foreach ($prepaidIdList as $loanId) {
                    if (!isset($eligiblePrepaidById[$loanId])) {
                        continue;
                    }
                    $row = $eligiblePrepaidById[$loanId];
                    if (checks_prepaid_interest_already_received($row)) {
                        continue;
                    }
                    $amountStr = checks_prepaid_interest_amount_db_string($row);
                    if ($amountStr === null) {
                        continue;
                    }
                    $depositTo = checks_funding_source_for_row($row);
                    if ($depositTo === null) {
                        continue;
                    }
                    $notes = 'Prepaid interest (Checks; month viewed ' . $selectedYm . ')';
                    $insertCashStmt->execute([$loanId, null, $eventDate, $amountStr, 'interest', $depositTo, $notes]);
                    $newEventId = (int) $pdo->lastInsertId();
                    $updPrepaid->execute([$loanId]);
                    if ($updPrepaid->rowCount() !== 1) {
                        if ($newEventId > 0) {
                            $delEvent->execute([$newEventId]);
                        }

                        continue;
                    }
                    ++$posted;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /checks?month=' . rawurlencode($selectedYm) . '&posted=' . ($posted > 0 ? '1' : '0'));
        exit;
    }
}
