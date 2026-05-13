<?php

declare(strict_types=1);

final class EntitiesController
{
    public function index(): void
    {
        $title = 'Entities';
        $rows = dbAll(
            'SELECT e.id, e.name, e.borrower_id, b.name AS borrower_name FROM entities e INNER JOIN borrowers b ON b.id = e.borrower_id ORDER BY b.name ASC, e.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        render('entities_index', [
            'title' => $title,
            'rows' => $rows,
        ]);
    }

    public function newForm(): void
    {
        $title = 'New entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        render('entities_new', [
            'title' => $title,
            'borrowers' => $borrowers,
        ]);
    }

    public function create(): void
    {
        csrf_verify_or_die();
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($borrowerId < 1 || $name === '') {
            header('Location: /entities/new');
            exit;
        }
        $check = db()->prepare('SELECT id FROM borrowers WHERE id = ?');
        $check->execute([$borrowerId]);
        if ($check->fetch() === false) {
            header('Location: /entities/new');
            exit;
        }
        $stmt = db()->prepare('INSERT INTO entities (borrower_id, name) VALUES (?, ?)');
        $stmt->execute([$borrowerId, $name]);
        header('Location: /entities');
        exit;
    }

    public function editForm(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /entities');
            exit;
        }
        $row = dbOne('SELECT id, borrower_id, name FROM entities WHERE id = ?', [$id]);
        if ($row === null) {
            header('Location: /entities');
            exit;
        }
        $title = 'Edit entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        $eid = (string) ($row['id'] ?? '');
        $curBorrowerId = (string) ($row['borrower_id'] ?? '');
        $nameVal = (string) ($row['name'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        render('entities_edit', [
            'title' => $title,
            'borrowers' => $borrowers,
            'eid' => $eid,
            'curBorrowerId' => $curBorrowerId,
            'nameVal' => $nameVal,
        ]);
    }

    public function update(): void
    {
        csrf_verify_or_die();
        $id = (int) ($_POST['id'] ?? 0);
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($id < 1) {
            header('Location: /entities');
            exit;
        }
        if ($borrowerId < 1 || $name === '') {
            header('Location: /entities/edit?id=' . $id);
            exit;
        }
        $check = db()->prepare('SELECT id FROM borrowers WHERE id = ?');
        $check->execute([$borrowerId]);
        if ($check->fetch() === false) {
            header('Location: /entities/edit?id=' . $id);
            exit;
        }
        $stmt = db()->prepare('UPDATE entities SET borrower_id = ?, name = ? WHERE id = ?');
        $stmt->execute([$borrowerId, $name, $id]);
        header('Location: /entities');
        exit;
    }
}
