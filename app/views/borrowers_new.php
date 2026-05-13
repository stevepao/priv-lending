<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-md space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/new">
<?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>
<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4"></textarea></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>
</form></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
