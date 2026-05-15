<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-xl space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<?php if ($showInvalid): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please correct the fields and try again.</p>
<?php endif; ?>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/bank">
<?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="bank">Bank</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="bank" name="bank" required>
<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="statement_date">Statement date</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="statement_date" name="statement_date" type="date" required></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="interest_amount">Interest amount</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="interest_amount" name="interest_amount" type="text" inputmode="decimal" required placeholder="0.00"></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/">Cancel</a></div>
</form></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
