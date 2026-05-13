<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?php echo e($title); ?></title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">
<div class="mx-auto max-w-md space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>
<?php if ($borrowers === []): ?>
<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>
<?php else: ?>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/edit">
<?php echo csrf_field(); ?>
<input type="hidden" name="id" value="<?php echo e($eid); ?>">
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>
<?php foreach ($borrowers as $b): ?>
<?php
    $bid = (string) ($b['id'] ?? '');
    $bname = (string) ($b['name'] ?? '');
    $sel = $bid === $curBorrowerId ? ' selected' : '';
?>
<option value="<?php echo e($bid); ?>"<?php echo $sel; ?>><?php echo e($bname); ?></option>
<?php endforeach; ?>
</select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="<?php echo e($nameVal); ?>"></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>
</form>
<?php endif; ?>
</div></body></html>
