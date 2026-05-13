<?php

declare(strict_types=1);

final class ReconcileController
{
    public function index(): void
    {
        $defaultYear = (int) (new DateTimeImmutable('now'))->format('Y');
        $yearRaw = isset($_GET['year']) ? (string) $_GET['year'] : '';
        $year = $defaultYear;
        if ($yearRaw !== '' && preg_match('/^\d{4}$/', $yearRaw) === 1) {
            $y = (int) $yearRaw;
            if ($y >= 2000 && $y <= 2100) {
                $year = $y;
            }
        }

        $groupRaw = isset($_GET['group']) ? (string) $_GET['group'] : 'entity';
        $group = in_array($groupRaw, ['borrower', 'entity', 'loan'], true) ? $groupRaw : 'entity';

        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-12-31', $year);

        $pdo = db();
        $cat = 'interest';

        $stmtGrand = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM cash_events WHERE category = ? AND event_date >= ? AND event_date <= ?'
        );
        $stmtGrand->execute([$cat, $start, $end]);
        $grandRow = $stmtGrand->fetch(PDO::FETCH_ASSOC);
        $grandRaw = is_array($grandRow) ? (string) ($grandRow['total'] ?? '0') : '0';
        $grandTotal = checks_normalize_money_2($grandRaw);

        $groupSql = match ($group) {
            'borrower' => [
                'idExpr' => 'COALESCE(b.id, 0)',
                'labelExpr' => "COALESCE(b.name, '(No borrower)')",
                'groupBy' => "COALESCE(b.id, 0), COALESCE(b.name, '(No borrower)')",
            ],
            'loan' => [
                'idExpr' => 'COALESCE(l.id, 0)',
                'labelExpr' => "COALESCE(l.name, '(No loan)')",
                'groupBy' => "COALESCE(l.id, 0), COALESCE(l.name, '(No loan)')",
            ],
            default => [
                'idExpr' => 'COALESCE(e.id, 0)',
                'labelExpr' => "COALESCE(e.name, '(No entity)')",
                'groupBy' => "COALESCE(e.id, 0), COALESCE(e.name, '(No entity)')",
            ],
        };

        $sqlGrouped = 'SELECT ' . $groupSql['idExpr'] . ' AS row_id, ' . $groupSql['labelExpr'] . ' AS row_label, '
            . 'COALESCE(SUM(ce.amount), 0) AS interest_total '
            . 'FROM cash_events ce '
            . 'LEFT JOIN loans l ON l.id = ce.loan_id '
            . 'LEFT JOIN entities e ON e.id = l.entity_id '
            . 'LEFT JOIN borrowers b ON b.id = e.borrower_id '
            . 'WHERE ce.category = ? AND ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY ' . $groupSql['groupBy'] . ' ORDER BY row_label ASC';

        $stmtGrouped = $pdo->prepare($sqlGrouped);
        $stmtGrouped->execute([$cat, $start, $end]);
        $groupRows = [];
        while ($r = $stmtGrouped->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($r)) {
                continue;
            }
            $tid = (string) ($r['row_id'] ?? '0');
            $label = (string) ($r['row_label'] ?? '');
            $sumRaw = $r['interest_total'] !== null && $r['interest_total'] !== '' ? (string) $r['interest_total'] : '0';
            $groupRows[] = [
                'rowId' => $tid,
                'rowLabel' => $label,
                'interestTotal' => checks_normalize_money_2($sumRaw),
            ];
        }

        $stmtEntity = $pdo->prepare(
            'SELECT e.id AS entity_id, e.name AS entity_name, COALESCE(SUM(ce.amount), 0) AS interest_total '
            . 'FROM cash_events ce '
            . 'INNER JOIN loans l ON l.id = ce.loan_id '
            . 'INNER JOIN entities e ON e.id = l.entity_id '
            . 'WHERE ce.category = ? AND ce.event_date >= ? AND ce.event_date <= ? '
            . 'GROUP BY e.id, e.name ORDER BY e.name ASC'
        );
        $stmtEntity->execute([$cat, $start, $end]);
        $entityRows = [];
        while ($r = $stmtEntity->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($r)) {
                continue;
            }
            $eid = (int) ($r['entity_id'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $ename = (string) ($r['entity_name'] ?? '');
            $sumRaw = $r['interest_total'] !== null && $r['interest_total'] !== '' ? (string) $r['interest_total'] : '0';
            $entityRows[] = [
                'entityId' => $eid,
                'entityName' => $ename,
                'interestTotal' => checks_normalize_money_2($sumRaw),
            ];
        }

        $title = 'Reconcile';
        header('Content-Type: text/html; charset=utf-8');
        render('reconcile', [
            'title' => $title,
            'year' => $year,
            'group' => $group,
            'start' => $start,
            'end' => $end,
            'groupRows' => $groupRows,
            'grandTotal' => $grandTotal,
            'entityRows' => $entityRows,
        ]);
    }
}
