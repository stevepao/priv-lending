<?php

declare(strict_types=1);

final class CashEventsController
{
    /**
     * Validate and normalize posted amount for a cash event. LOC interest and principal out are stored negative; interest and principal in are positive.
     *
     * @return string|false normalized to 2 decimal places, or false if invalid
     */
    private static function cash_event_amount_for_category(string $category, string $postAmount): string|false
    {
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            return false;
        }
        $raw = loan_normalize_decimal_input($postAmount);
        $t = trim($raw);
        $outflow = in_array($category, ['loc_interest', 'principal_out'], true);
        if ($outflow) {
            if ($t === '' || !preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $t)) {
                return false;
            }
            $norm = checks_normalize_money_2($t);
            if (extension_loaded('bcmath')) {
                if (bccomp($norm, '0', 2) >= 0) {
                    return false;
                }
            } elseif ((float) $norm >= 0.0) {
                return false;
            }

            return $norm;
        }
        if ($t === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $t)) {
            return false;
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($t, '0', 2) !== 1) {
                return false;
            }
        } elseif ((float) $t <= 0.0) {
            return false;
        }

        return checks_normalize_money_2($t);
    }

    public function index(): void
    {
        $title = 'Cash events';
        $filter = $this->cashEventsIndexDateFilterFromRequest();
        $schSel = schema_table_has_column('cash_events', 'scheduled_check_ym')
            ? 'ce.scheduled_check_ym'
            : 'CAST(NULL AS CHAR(7)) AS scheduled_check_ym';
        $rows = dbAll(
            'SELECT ce.id, ce.loan_id, ' . $schSel . ', ce.event_date, ce.amount, ce.category, ce.deposit_to, ce.notes, '
            . 'l.name AS loan_name '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'WHERE ce.event_date >= ? AND ce.event_date <= ? '
            . 'ORDER BY ce.event_date ASC, ce.id ASC',
            [$filter['start'], $filter['end']]
        );
        header('Content-Type: text/html; charset=utf-8');
        render('cash_events_index', [
            'title' => $title,
            'rows' => $rows,
            'range' => $filter['range'],
            'start' => $filter['start'],
            'end' => $filter['end'],
            'dateOrderError' => $filter['dateOrderError'],
        ]);
    }

    /**
     * @return array{range: string, start: string, end: string, dateOrderError: bool}
     */
    private function cashEventsIndexDateFilterFromRequest(): array
    {
        $today = new DateTimeImmutable('today');
        $default = $this->cashEventsPresetDateRange('last_3_months', $today);
        $range = isset($_GET['range']) ? (string) $_GET['range'] : 'last_3_months';
        $valid = ['last_3_months', 'last_full_year', 'ytd', 'quarter', 'custom'];
        if (!in_array($range, $valid, true)) {
            $range = 'last_3_months';
        }

        if ($range === 'custom') {
            $start = $this->parseYmdOrNull(isset($_GET['start']) ? (string) $_GET['start'] : '');
            $end = $this->parseYmdOrNull(isset($_GET['end']) ? (string) $_GET['end'] : '');
            if ($start === null || $end === null) {
                return [
                    'range' => 'last_3_months',
                    'start' => $default['start'],
                    'end' => $default['end'],
                    'dateOrderError' => false,
                ];
            }
            if ($start > $end) {
                return [
                    'range' => 'custom',
                    'start' => $start,
                    'end' => $end,
                    'dateOrderError' => true,
                ];
            }

            return [
                'range' => 'custom',
                'start' => $start,
                'end' => $end,
                'dateOrderError' => false,
            ];
        }

        $preset = $this->cashEventsPresetDateRange($range, $today);

        return [
            'range' => $range,
            'start' => $preset['start'],
            'end' => $preset['end'],
            'dateOrderError' => false,
        ];
    }

    private function parseYmdOrNull(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

        return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
    }

    /**
     * @return array{start: string, end: string}
     */
    private function cashEventsPresetDateRange(string $preset, DateTimeImmutable $today): array
    {
        $end = $today->format('Y-m-d');
        if ($preset === 'last_full_year') {
            $y = (int) $today->format('Y') - 1;

            return ['start' => $y . '-01-01', 'end' => $y . '-12-31'];
        }
        if ($preset === 'ytd') {
            return ['start' => $today->format('Y') . '-01-01', 'end' => $end];
        }
        if ($preset === 'quarter') {
            $month = (int) $today->format('n');
            $qStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
            $start = $today->setDate((int) $today->format('Y'), $qStartMonth, 1)->format('Y-m-d');

            return ['start' => $start, 'end' => $end];
        }

        $start = $today->modify('first day of -2 months')->format('Y-m-d');

        return ['start' => $start, 'end' => $end];
    }

    public function newForm(): void
    {
        $title = 'New cash event';
        $loans = dbAll(
            'SELECT l.id, l.name, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        $eventDateVal = (new DateTimeImmutable('today'))->format('Y-m-d');
        $rawEd = isset($_GET['event_date']) ? trim((string) $_GET['event_date']) : '';
        if ($rawEd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEd) === 1) {
            $parsedPrefill = DateTimeImmutable::createFromFormat('Y-m-d', $rawEd);
            if ($parsedPrefill instanceof DateTimeImmutable && $parsedPrefill->format('Y-m-d') === $rawEd) {
                $eventDateVal = $rawEd;
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        render('cash_events_new', [
            'title' => $title,
            'loans' => $loans,
            'eventDateVal' => $eventDateVal,
            'showInvalid' => isset($_GET['invalid']),
        ]);
    }

    public function create(): void
    {
        csrf_verify_or_die();

        $redirectInvalid = static function (?string $validEventDateYmd): void {
            $q = ['invalid' => '1'];
            if ($validEventDateYmd !== null && $validEventDateYmd !== '') {
                $q['event_date'] = $validEventDateYmd;
            }
            header('Location: /cash-events/new?' . http_build_query($q));
            exit;
        };

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEv = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if (!$parsedEv instanceof DateTimeImmutable || $parsedEv->format('Y-m-d') !== $eventDateRaw) {
            $redirectInvalid(null);
        }
        $validEventDateForRedirect = $eventDateRaw;

        $loanIdRaw = trim((string) ($_POST['loan_id'] ?? ''));
        $loanId = $loanIdRaw === '' ? null : (int) $loanIdRaw;
        if ($loanId !== null && $loanId < 1) {
            $redirectInvalid($validEventDateForRedirect);
        }

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            $redirectInvalid($validEventDateForRedirect);
        }

        $amountStr = self::cash_event_amount_for_category($category, (string) ($_POST['amount'] ?? ''));
        if ($amountStr === false) {
            $redirectInvalid($validEventDateForRedirect);
        }

        $depRaw = trim((string) ($_POST['deposit_to'] ?? ''));
        $depositTo = $depRaw === '' ? null : $depRaw;
        if ($depositTo !== null && !in_array($depositTo, ['JPM', 'NTRS'], true)) {
            $redirectInvalid($validEventDateForRedirect);
        }

        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if ($loanId !== null) {
            $exists = dbOne('SELECT id FROM loans WHERE id = ?', [$loanId]);
            if ($exists === null) {
                $redirectInvalid($validEventDateForRedirect);
            }
        }

        if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
            $stmt = db()->prepare(
                'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO cash_events (loan_id, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes]);
        }
        header('Location: /cash-events');
        exit;
    }

    public function editForm(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /cash-events');
            exit;
        }
        $schCol = schema_table_has_column('cash_events', 'scheduled_check_ym');
        $schSel = $schCol
            ? 'ce.scheduled_check_ym'
            : 'CAST(NULL AS CHAR(7)) AS scheduled_check_ym';
        $event = dbOne(
            'SELECT ce.id, ce.loan_id, ' . $schSel . ', ce.event_date, ce.amount, ce.category, ce.deposit_to, ce.notes '
            . 'FROM cash_events ce WHERE ce.id = ?',
            [$id]
        );
        if ($event === null) {
            header('Location: /cash-events');
            exit;
        }
        $title = 'Edit cash event';
        $loans = dbAll(
            'SELECT l.id, l.name, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        $curLoanId = $event['loan_id'] !== null && $event['loan_id'] !== '' ? (string) (int) $event['loan_id'] : '';
        $eventDateVal = (string) ($event['event_date'] ?? '');
        $amountVal = $event['amount'] !== null && $event['amount'] !== '' ? (string) $event['amount'] : '';
        $catVal = (string) ($event['category'] ?? 'interest');
        $depVal = $event['deposit_to'] !== null && $event['deposit_to'] !== '' ? (string) $event['deposit_to'] : '';
        $notesVal = $event['notes'] !== null && $event['notes'] !== '' ? (string) $event['notes'] : '';
        $scmVal = $event['scheduled_check_ym'] !== null && $event['scheduled_check_ym'] !== '' ? (string) $event['scheduled_check_ym'] : '';
        header('Content-Type: text/html; charset=utf-8');
        render('cash_events_edit', [
            'title' => $title,
            'eventId' => $id,
            'schCol' => $schCol,
            'scmVal' => $scmVal,
            'showInvalid' => isset($_GET['invalid']),
            'loans' => $loans,
            'curLoanId' => $curLoanId,
            'eventDateVal' => $eventDateVal,
            'amountVal' => $amountVal,
            'catVal' => $catVal,
            'depVal' => $depVal,
            'notesVal' => $notesVal,
        ]);
    }

    public function update(): void
    {
        csrf_verify_or_die();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            header('Location: /cash-events');
            exit;
        }

        $schCol = schema_table_has_column('cash_events', 'scheduled_check_ym');
        $sel = $schCol ? 'loan_id, scheduled_check_ym, category' : 'loan_id, category';
        $existing = dbOne('SELECT ' . $sel . ' FROM cash_events WHERE id = ?', [$id]);
        if ($existing === null) {
            header('Location: /cash-events');
            exit;
        }
        $oldLoanId = $existing['loan_id'] !== null && $existing['loan_id'] !== '' ? (int) $existing['loan_id'] : null;

        $schYmVal = null;
        if ($schCol) {
            $v = $existing['scheduled_check_ym'] ?? null;
            $schYmVal = is_string($v) && $v !== '' ? $v : null;
        }

        $redirectInvalid = static function (int $eid): void {
            header('Location: /cash-events/edit?id=' . $eid . '&invalid=1');
            exit;
        };

        $loanIdRaw = trim((string) ($_POST['loan_id'] ?? ''));
        $loanId = $loanIdRaw === '' ? null : (int) $loanIdRaw;
        if ($loanId !== null && $loanId < 1) {
            $redirectInvalid($id);
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEv = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if (!$parsedEv instanceof DateTimeImmutable || $parsedEv->format('Y-m-d') !== $eventDateRaw) {
            $redirectInvalid($id);
        }

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            $redirectInvalid($id);
        }

        $amountStr = self::cash_event_amount_for_category($category, (string) ($_POST['amount'] ?? ''));
        if ($amountStr === false) {
            $redirectInvalid($id);
        }

        $depRaw = trim((string) ($_POST['deposit_to'] ?? ''));
        $depositTo = $depRaw === '' ? null : $depRaw;
        if ($depositTo !== null && !in_array($depositTo, ['JPM', 'NTRS'], true)) {
            $redirectInvalid($id);
        }

        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if ($loanId !== null) {
            $exists = dbOne('SELECT id FROM loans WHERE id = ?', [$loanId]);
            if ($exists === null) {
                $redirectInvalid($id);
            }
        }

        if ($schYmVal !== null) {
            $newLoanId = $loanId;
            if ($oldLoanId !== $newLoanId) {
                $catExisting = (string) ($existing['category'] ?? 'interest');
                $dup = dbOne(
                    'SELECT id FROM cash_events WHERE loan_id <=> ? AND scheduled_check_ym = ? AND category = ? AND id != ?',
                    [$newLoanId, $schYmVal, $catExisting, $id]
                );
                if ($dup !== null) {
                    $redirectInvalid($id);
                }
            }
        }

        $stmt = db()->prepare(
            'UPDATE cash_events SET loan_id = ?, event_date = ?, amount = ?, category = ?, deposit_to = ?, notes = ? WHERE id = ?'
        );
        $stmt->execute([$loanId, $eventDateRaw, $amountStr, $category, $depositTo, $notes, $id]);
        header('Location: /cash-events');
        exit;
    }

    public function destroy(): void
    {
        csrf_verify_or_die();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            header('Location: /cash-events');
            exit;
        }

        $stmt = db()->prepare('DELETE FROM cash_events WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: /cash-events');
        exit;
    }
}
