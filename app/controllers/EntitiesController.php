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
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/entities/new">New entity</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Borrower</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="4">No entities yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $borrowerName = (string) ($row['borrower_name'] ?? '');
                $entityName = (string) ($row['name'] ?? '');
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($borrowerName) . '</td>';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/entities/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    }

    public function newForm(): void
    {
        $title = 'New entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>';
        if ($borrowers === []) {
            echo '<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/new">';
            echo csrf_field();
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>';
            foreach ($borrowers as $b) {
                $bid = (string) ($b['id'] ?? '');
                $bname = (string) ($b['name'] ?? '');
                echo '<option value="' . e($bid) . '">' . e($bname) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
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
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>';
        if ($borrowers === []) {
            echo '<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/edit">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . e($eid) . '">';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>';
            foreach ($borrowers as $b) {
                $bid = (string) ($b['id'] ?? '');
                $bname = (string) ($b['name'] ?? '');
                $sel = $bid === $curBorrowerId ? ' selected' : '';
                echo '<option value="' . e($bid) . '"' . $sel . '>' . e($bname) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
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
