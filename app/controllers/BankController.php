<?php

declare(strict_types=1);

final class BankController
{
    public function showForm(): void
    {
        $title = 'Bank statement';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please correct the fields and try again.</p>';
        }
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/bank">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="bank">Bank</label>';
        echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="bank" name="bank" required>';
        echo '<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="statement_date">Statement date</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="statement_date" name="statement_date" type="date" required></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="interest_amount">Interest amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="interest_amount" name="interest_amount" type="text" inputmode="decimal" required placeholder="0.00"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00" value="0.00"></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/">Cancel</a></div>';
        echo '</form></div></body></html>';
    }

    public function store(): void
    {
        csrf_verify_or_die();

        $bank = (string) ($_POST['bank'] ?? '');
        if (!in_array($bank, ['JPM', 'NTRS'], true)) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $stmtDateRaw = trim((string) ($_POST['statement_date'] ?? ''));
        $parsedStmt = DateTimeImmutable::createFromFormat('Y-m-d', $stmtDateRaw);
        if (!$parsedStmt instanceof DateTimeImmutable || $parsedStmt->format('Y-m-d') !== $stmtDateRaw) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $intRaw = loan_normalize_decimal_input((string) ($_POST['interest_amount'] ?? ''));
        $prinRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $intTrim = trim($intRaw) === '' ? '0' : trim($intRaw);
        $prinTrim = trim($prinRaw) === '' ? '0' : trim($prinRaw);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $intTrim) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $prinTrim)) {
            header('Location: /bank?invalid=1');
            exit;
        }
        $intPos = checks_normalize_money_2($intTrim);
        $prinPos = checks_normalize_money_2($prinTrim);
        if (extension_loaded('bcmath')) {
            if (bccomp($intPos, '0', 2) === -1 || bccomp($prinPos, '0', 2) === -1) {
                header('Location: /bank?invalid=1');
                exit;
            }
        } elseif ((float) $intPos < 0.0 || (float) $prinPos < 0.0) {
            header('Location: /bank?invalid=1');
            exit;
        }

        $negLoc = extension_loaded('bcmath') ? bcmul($intPos, '-1', 2) : number_format(-(float) $intPos, 2, '.', '');
        $negPrin = extension_loaded('bcmath') ? bcmul($prinPos, '-1', 2) : number_format(-(float) $prinPos, 2, '.', '');

        $notesLoc = 'Bank statement ' . $stmtDateRaw . ' (loc_interest)';
        $notesPrin = 'Bank statement ' . $stmtDateRaw . ' (principal_out)';

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (schema_table_has_column('cash_events', 'scheduled_check_ym')) {
                $ins = $pdo->prepare(
                    'INSERT INTO cash_events (loan_id, scheduled_check_ym, event_date, amount, category, deposit_to, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)'
                );
                $ins->execute([null, $stmtDateRaw, $negLoc, 'loc_interest', $bank, $notesLoc]);
                $ins->execute([null, $stmtDateRaw, $negPrin, 'principal_out', $bank, $notesPrin]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO cash_events (loan_id, event_date, amount, category, deposit_to, notes) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([null, $stmtDateRaw, $negLoc, 'loc_interest', $bank, $notesLoc]);
                $ins->execute([null, $stmtDateRaw, $negPrin, 'principal_out', $bank, $notesPrin]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /bank');
        exit;
    }
}
