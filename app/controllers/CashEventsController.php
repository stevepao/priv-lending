<?php

declare(strict_types=1);

final class CashEventsController
{
    public function index(): void
    {
        $title = 'Cash events';
        $schSel = schema_table_has_column('cash_events', 'scheduled_check_ym')
            ? 'ce.scheduled_check_ym'
            : 'CAST(NULL AS CHAR(7)) AS scheduled_check_ym';
        $rows = dbAll(
            'SELECT ce.id, ce.loan_id, ' . $schSel . ', ce.event_date, ce.amount, ce.category, ce.deposit_to, ce.notes, '
            . 'l.name AS loan_name, e.name AS entity_name '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'LEFT JOIN entities e ON e.id = l.entity_id '
            . 'ORDER BY ce.event_date DESC, ce.id DESC LIMIT 500',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        render('cash_events_index', [
            'title' => $title,
            'rows' => $rows,
        ]);
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

        $amountRaw = loan_normalize_decimal_input((string) ($_POST['amount'] ?? ''));
        $amountTrim = trim($amountRaw);
        if ($amountTrim === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountTrim)) {
            $redirectInvalid($validEventDateForRedirect);
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($amountTrim, '0', 2) !== 1) {
                $redirectInvalid($validEventDateForRedirect);
            }
        } elseif ((float) $amountTrim <= 0.0) {
            $redirectInvalid($validEventDateForRedirect);
        }
        $amountStr = checks_normalize_money_2($amountTrim);

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
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

        $amountRaw = loan_normalize_decimal_input((string) ($_POST['amount'] ?? ''));
        $amountTrim = trim($amountRaw);
        if ($amountTrim === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountTrim)) {
            $redirectInvalid($id);
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($amountTrim, '0', 2) !== 1) {
                $redirectInvalid($id);
            }
        } elseif ((float) $amountTrim <= 0.0) {
            $redirectInvalid($id);
        }
        $amountStr = checks_normalize_money_2($amountTrim);

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
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
