<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-3xl space-y-4">
<div class="flex items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/entities/new">New entity</a>
</div>
<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Borrower</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($rows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="4">No entities yet.</td></tr>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php
    $id = (string) ($row['id'] ?? '');
    $borrowerName = (string) ($row['borrower_name'] ?? '');
    $entityName = (string) ($row['name'] ?? '');
?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($id); ?></td>
<td class="px-3 py-2"><?php echo e($borrowerName); ?></td>
<td class="px-3 py-2"><?php echo e($entityName); ?></td>
<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/entities/edit?id=<?php echo e($id); ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
