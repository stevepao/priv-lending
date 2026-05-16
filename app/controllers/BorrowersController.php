<?php

declare(strict_types=1);

final class BorrowersController
{
    public function index(): void
    {
        $title = 'Borrowers';
        $rows = dbAll('SELECT id, name, address, city, state, zip, notes FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        render('borrowers_index', [
            'title' => $title,
            'rows' => $rows,
        ]);
    }

    public function newForm(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        render('borrowers_new', [
            'title' => 'New borrower',
        ]);
    }

    public function create(): void
    {
        csrf_verify_or_die();
        $name = trim((string) ($_POST['name'] ?? ''));
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '') {
            header('Location: /borrowers/new');
            exit;
        }
        $notes = $notesRaw === '' ? null : $notesRaw;
        [$addr, $city, $state, $zip] = self::borrowerAddressFromPost();
        $stmt = db()->prepare('INSERT INTO borrowers (name, address, city, state, zip, notes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $addr, $city, $state, $zip, $notes]);
        header('Location: /borrowers');
        exit;
    }

    public function editForm(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /borrowers');
            exit;
        }
        $row = dbOne('SELECT id, name, address, city, state, zip, notes FROM borrowers WHERE id = ?', [$id]);
        if ($row === null) {
            header('Location: /borrowers');
            exit;
        }
        $title = 'Edit borrower';
        $bid = (string) ($row['id'] ?? '');
        $nameVal = (string) ($row['name'] ?? '');
        $notesVal = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
        $addressVal = $row['address'] !== null && $row['address'] !== '' ? (string) $row['address'] : '';
        $cityVal = $row['city'] !== null && $row['city'] !== '' ? (string) $row['city'] : '';
        $stateVal = $row['state'] !== null && $row['state'] !== '' ? (string) $row['state'] : '';
        $zipVal = $row['zip'] !== null && $row['zip'] !== '' ? (string) $row['zip'] : '';
        header('Content-Type: text/html; charset=utf-8');
        render('borrowers_edit', [
            'title' => $title,
            'bid' => $bid,
            'nameVal' => $nameVal,
            'addressVal' => $addressVal,
            'cityVal' => $cityVal,
            'stateVal' => $stateVal,
            'zipVal' => $zipVal,
            'notesVal' => $notesVal,
        ]);
    }

    public function update(): void
    {
        csrf_verify_or_die();
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        if ($id < 1) {
            header('Location: /borrowers');
            exit;
        }
        if ($name === '') {
            header('Location: /borrowers/edit?id=' . $id);
            exit;
        }
        $notes = $notesRaw === '' ? null : $notesRaw;
        [$addr, $city, $state, $zip] = self::borrowerAddressFromPost();
        $stmt = db()->prepare('UPDATE borrowers SET name = ?, address = ?, city = ?, state = ?, zip = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $addr, $city, $state, $zip, $notes, $id]);
        header('Location: /borrowers');
        exit;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private static function borrowerAddressFromPost(): array
    {
        $norm = static function (string $key): ?string {
            $t = trim((string) ($_POST[$key] ?? ''));

            return $t === '' ? null : $t;
        };

        return [$norm('address'), $norm('city'), $norm('state'), $norm('zip')];
    }
}
