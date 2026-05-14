<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-4">
<div class="flex items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/loans/new">New loan</a>
</div>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Name</th>
<th class="px-3 py-2 font-medium">Funding</th><th class="px-3 py-2 font-medium">Origin</th><th class="px-3 py-2 font-medium">Current balance</th>
<th class="px-3 py-2 font-medium">Principal</th><th class="px-3 py-2 font-medium">Rate %</th><th class="px-3 py-2 font-medium">Payment type</th>
<th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($rowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="10">No loans yet.</td></tr>
<?php else: ?>
<?php foreach ($loanRows as $lr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($lr['id']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['entityName']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['loanName']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['funding']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['origin']); ?></td>
<td class="px-3 py-2 font-mono italic text-slate-800" title="Sum of cash_events.amount for principal_in and principal_out on this loan (funding negative, repayments positive; zero when fully repaid)."><?php echo e($lr['currentBalance']); ?></td>
<td class="px-3 py-2"<?php echo $lr['principalTitle'] !== '' ? ' title="' . e($lr['principalTitle']) . '"' : ''; ?>><?php echo e($lr['principal']); ?></td>
<?php if ($lr['rateIsImplied']): ?>
<td class="px-3 py-2 italic text-slate-800" title="Implied annual %: monthly interest × 12 ÷ principal × 100 (stored annual rate is blank or zero)."><?php echo e($lr['impliedAnnual']); ?></td>
<?php else: ?>
<td class="px-3 py-2"><?php echo e($lr['rateDisplay']); ?></td>
<?php endif; ?>
<td class="px-3 py-2"><?php echo e($lr['ptype']); ?></td>
<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/loans/edit?id=<?php echo e($lr['id']); ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
