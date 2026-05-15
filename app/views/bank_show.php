<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-2xl space-y-6">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<p class="text-sm text-slate-600">Use this page to record <strong>interest paid on your line of credit</strong> for a particular month. Choose the bank, enter the <strong>statement date</strong> from your bank statement, and enter the interest amount shown on that statement.</p>
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
</form>

<h2 class="text-lg font-semibold text-slate-800">Recent LOC interest</h2>
<p class="text-xs text-slate-600">The six most recent line-of-credit interest entries recorded from this page.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Bank</th><th class="px-3 py-2 font-medium">Statement date</th><th class="px-3 py-2 text-right font-medium">Interest amount</th>
</tr></thead><tbody>
<?php if ($recentLocInterestRows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="3">No LOC interest entries yet.</td></tr>
<?php else: ?>
<?php foreach ($recentLocInterestRows as $lr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($lr['bank']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['statementDate']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e($lr['interestDisp']); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
