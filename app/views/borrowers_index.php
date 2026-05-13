<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?php echo e($title); ?></title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">
<div class="mx-auto max-w-3xl space-y-4">
<div class="flex items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/borrowers/new">New borrower</a>
</div>
<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($rows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="4">No borrowers yet.</td></tr>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php
    $id = (string) ($row['id'] ?? '');
    $name = (string) ($row['name'] ?? '');
    $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($id); ?></td>
<td class="px-3 py-2"><?php echo e($name); ?></td>
<td class="px-3 py-2"><?php echo e($notes); ?></td>
<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/borrowers/edit?id=<?php echo e($id); ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div></div></body></html>
