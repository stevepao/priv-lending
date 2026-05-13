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
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-6xl space-y-4">';
        echo '<div class="flex flex-wrap items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/cash-events/new">New cash event</a>';
        echo '</div>';
        echo '<p class="text-sm text-slate-600">Ledger of cash movements. Events from <strong>Checks</strong> include the scheduled month in <code class="text-xs">scheduled_check_ym</code> when set.</p>';
        echo '<p class="text-sm"><a class="text-slate-600 underline" href="/">Dashboard</a> · <a class="text-slate-600 underline" href="/checks">Checks</a> · <a class="text-slate-600 underline" href="/loans">Loans</a></p>';
        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Loan</th>';
        echo '<th class="px-3 py-2 font-medium">Amount</th><th class="px-3 py-2 font-medium">Category</th><th class="px-3 py-2 font-medium">Deposit to</th>';
        echo '<th class="px-3 py-2 font-medium">Check month</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="9">No cash events yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $ed = (string) ($row['event_date'] ?? '');
                $ent = (string) ($row['entity_name'] ?? '');
                $loan = (string) ($row['loan_name'] ?? '');
                if ($loan === '' && ($row['loan_id'] ?? null) === null) {
                    $loan = '—';
                } elseif ($loan === '') {
                    $loan = '#' . (string) ($row['loan_id'] ?? '');
                }
                $amt = $row['amount'] !== null && $row['amount'] !== '' ? (string) $row['amount'] : '';
                $cat = (string) ($row['category'] ?? '');
                $dep = $row['deposit_to'] !== null && $row['deposit_to'] !== '' ? (string) $row['deposit_to'] : '—';
                $scm = $row['scheduled_check_ym'] !== null && $row['scheduled_check_ym'] !== '' ? (string) $row['scheduled_check_ym'] : '—';
                $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($ed) . '</td>';
                echo '<td class="px-3 py-2">' . e($ent !== '' ? $ent : '—') . '</td>';
                echo '<td class="px-3 py-2">' . e($loan) . '</td>';
                echo '<td class="px-3 py-2 font-medium">' . e($amt) . '</td>';
                echo '<td class="px-3 py-2">' . e($cat) . '</td>';
                echo '<td class="px-3 py-2">' . e($dep) . '</td>';
                echo '<td class="px-3 py-2">' . e($scm) . '</td>';
                echo '<td class="px-3 py-2 text-slate-600">' . e($notes) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/cash-events/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    }

    public function newForm(): void
    {
        $title = 'New cash event';
        $loans = dbAll(
            'SELECT l.id, l.name, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<p class="text-sm text-slate-600">Record a payment or adjustment outside the monthly Checks flow. These events are not tied to a scheduled check month.</p>';
        echo '<a class="text-sm text-slate-600 underline" href="/cash-events">Back to cash events</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please fix the highlighted fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/cash-events/new">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan (optional)</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id">';
        echo '<option value="">— None —</option>';
        foreach ($loans as $lr) {
            $lid = (string) ($lr['id'] ?? '');
            $label = e((string) ($lr['entity_name'] ?? '')) . ' — ' . e((string) ($lr['name'] ?? ''));
            echo '<option value="' . e($lid) . '">' . $label . '</option>';
        }
        echo '</select></div>';
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="event_date">Event date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" required value="' . e($today) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="amount">Amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="category">Category</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="category" name="category" required>';
        foreach (['interest', 'principal_in', 'loc_interest', 'principal_out'] as $c) {
            echo '<option value="' . e($c) . '"' . ($c === 'interest' ? ' selected' : '') . '>' . e($c) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="deposit_to">Deposit to</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="deposit_to" name="deposit_to">';
        echo '<option value="">—</option><option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3"></textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/cash-events">Cancel</a></div>';
        echo '</form></div></body></html>';
    }

    public function create(): void
    {
        csrf_verify_or_die();

        $loanIdRaw = trim((string) ($_POST['loan_id'] ?? ''));
        $loanId = $loanIdRaw === '' ? null : (int) $loanIdRaw;
        if ($loanId !== null && $loanId < 1) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $eventDateRaw = trim((string) ($_POST['event_date'] ?? ''));
        $parsedEv = DateTimeImmutable::createFromFormat('Y-m-d', $eventDateRaw);
        if (!$parsedEv instanceof DateTimeImmutable || $parsedEv->format('Y-m-d') !== $eventDateRaw) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $amountRaw = loan_normalize_decimal_input((string) ($_POST['amount'] ?? ''));
        $amountTrim = trim($amountRaw);
        if ($amountTrim === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountTrim)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }
        if (extension_loaded('bcmath')) {
            if (bccomp($amountTrim, '0', 2) !== 1) {
                header('Location: /cash-events/new?invalid=1');
                exit;
            }
        } elseif ((float) $amountTrim <= 0.0) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }
        $amountStr = checks_normalize_money_2($amountTrim);

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!in_array($category, ['interest', 'principal_in', 'loc_interest', 'principal_out'], true)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $depRaw = trim((string) ($_POST['deposit_to'] ?? ''));
        $depositTo = $depRaw === '' ? null : $depRaw;
        if ($depositTo !== null && !in_array($depositTo, ['JPM', 'NTRS'], true)) {
            header('Location: /cash-events/new?invalid=1');
            exit;
        }

        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        $notes = $notesRaw === '' ? null : $notesRaw;

        if ($loanId !== null) {
            $exists = dbOne('SELECT id FROM loans WHERE id = ?', [$loanId]);
            if ($exists === null) {
                header('Location: /cash-events/new?invalid=1');
                exit;
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
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<p class="text-sm text-slate-600">Update this cash event. The scheduled check month (if any) stays linked to this row and is not changed here.</p>';
        echo '<a class="text-sm text-slate-600 underline" href="/cash-events">Back to cash events</a>';
        if ($schCol && $scmVal !== '') {
            echo '<p class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Linked check month: <code class="text-xs">' . e($scmVal) . '</code> (from Checks posting).</p>';
        }
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please fix the highlighted fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/cash-events/edit">';
        echo csrf_field();
        echo '<input type="hidden" name="id" value="' . e((string) $id) . '">';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan (optional)</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id">';
        echo '<option value=""' . ($curLoanId === '' ? ' selected' : '') . '>— None —</option>';
        foreach ($loans as $lr) {
            $lid = (string) ($lr['id'] ?? '');
            $label = e((string) ($lr['entity_name'] ?? '')) . ' — ' . e((string) ($lr['name'] ?? ''));
            $sel = $lid === $curLoanId ? ' selected' : '';
            echo '<option value="' . e($lid) . '"' . $sel . '>' . $label . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="event_date">Event date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" required value="' . e($eventDateVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="amount">Amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00" value="' . e($amountVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="category">Category</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="category" name="category" required>';
        foreach (['interest', 'principal_in', 'loc_interest', 'principal_out'] as $c) {
            $sel = $c === $catVal ? ' selected' : '';
            echo '<option value="' . e($c) . '"' . $sel . '>' . e($c) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="deposit_to">Deposit to</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="deposit_to" name="deposit_to">';
        echo '<option value=""' . ($depVal === '' ? ' selected' : '') . '>—</option>';
        echo '<option value="JPM"' . ($depVal === 'JPM' ? ' selected' : '') . '>JPM</option><option value="NTRS"' . ($depVal === 'NTRS' ? ' selected' : '') . '>NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3">' . e($notesVal) . '</textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/cash-events">Cancel</a></div>';
        echo '</form></div></body></html>';
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
}
