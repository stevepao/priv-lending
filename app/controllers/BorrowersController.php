<?php

declare(strict_types=1);

final class BorrowersController
{
    public function index(): void
    {
        $title = 'Borrowers';
        $rows = dbAll('SELECT id, name, notes FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/borrowers/new">New borrower</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="4">No borrowers yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($name) . '</td>';
                echo '<td class="px-3 py-2">' . e($notes) . '</td>';
                echo '<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/borrowers/edit?id=' . e($id) . '">Edit</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    }

    public function newForm(): void
    {
        $title = 'New borrower';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>';
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/new">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4"></textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>';
        echo '</form></div></body></html>';
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
        $stmt = db()->prepare('INSERT INTO borrowers (name, notes) VALUES (?, ?)');
        $stmt->execute([$name, $notes]);
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
        $row = dbOne('SELECT id, name, notes FROM borrowers WHERE id = ?', [$id]);
        if ($row === null) {
            header('Location: /borrowers');
            exit;
        }
        $title = 'Edit borrower';
        $bid = (string) ($row['id'] ?? '');
        $nameVal = (string) ($row['name'] ?? '');
        $notesVal = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>';
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/edit">';
        echo csrf_field();
        echo '<input type="hidden" name="id" value="' . e($bid) . '">';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4">' . e($notesVal) . '</textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>';
        echo '</form></div></body></html>';
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
        $stmt = db()->prepare('UPDATE borrowers SET name = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $notes, $id]);
        header('Location: /borrowers');
        exit;
    }
}
