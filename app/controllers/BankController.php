<?php

declare(strict_types=1);

final class BankController
{
    public function showForm(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        render('bank_show', [
            'title' => 'Bank statement',
            'showInvalid' => isset($_GET['invalid']),
        ]);
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
