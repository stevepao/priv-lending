<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-6">
<div class="flex flex-wrap items-end justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<form class="flex flex-wrap items-end gap-2" method="get" action="/checks">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="month">Calendar month</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="month" name="month" type="month" value="<?php echo e($selectedYm); ?>"></div>
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show</button>
</form></div>
<div class="space-y-2 text-sm text-slate-600">
<p>Choose the month you are working on, then review the payments due below. When you are ready, check the loans you paid, pick the <strong>payment date</strong>, and click <strong>Save selected payments</strong>.</p>
<ul class="list-disc space-y-1 pl-5">
<li>The <strong>monthly payments</strong> table is for regular interest-only and amortizing loans. A loan’s first payment is due the <strong>month after</strong> it starts—not in the starting month.</li>
<li>If a payment is already recorded, <strong>Posted</strong> appears and there is nothing to check.</li>
<li>For <strong>declining balance</strong> loans, the amount shown is that month’s interest plus the scheduled principal payment.</li>
<li>The <strong>prepaid loans</strong> section is for loans where interest was paid up front. Record that one-time amount once; it will stay <strong>Posted</strong> for every month in the prepaid period.</li>
</ul>
</div>
<?php if ($showScheduledCheckMigrationBanner): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Recording monthly checks is not available on this database yet. An administrator needs to run the pending database update (migration <code class="text-xs">0005_cash_events_scheduled_check.sql</code>).</p>
<?php endif; ?>
<?php if ($showChecksCategoryUniqueMigrationBanner): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Declining-balance loans cannot be saved as separate interest and principal payments until the database is updated (migration <code class="text-xs">0007_cash_events_scheduled_check_category_unique.sql</code>).</p>
<?php endif; ?>
<?php if ($postedSuccess): ?>
<p class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Your selected payments were saved.</p>
<?php endif; ?>
<?php if ($postedFailure): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Nothing was saved. Select at least one loan, use a valid payment date, and avoid recording the same payment twice.</p>
<?php endif; ?>

<form method="post" action="/checks" class="space-y-4">
<?php echo csrf_field(); ?>
<input type="hidden" name="month" value="<?php echo e($selectedYm); ?>">
<h2 class="text-lg font-semibold text-slate-800">Monthly payments</h2>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Funding source</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Calculation</th>
<th class="px-3 py-2 text-right font-medium">Expected payment</th><th class="px-3 py-2 font-medium">Record</th><th class="px-3 py-2 font-medium">Status</th>
</tr></thead><tbody>
<?php if ($monthlyRowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="6">No monthly payments are due for this month.</td></tr>
<?php else: ?>
<?php foreach ($monthlyDisplayRows as $mr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($mr['fundingSource']); ?></td>
<td class="px-3 py-2"><?php echo e($mr['loanName']); ?></td>
<td class="px-3 py-2"><?php echo e($mr['calcMethod']); ?></td>
<td class="px-3 py-2 text-right"><?php echo $mr['expectedCellHtml']; ?></td>
<td class="px-3 py-2"><?php echo $mr['postCell']; ?></td>
<td class="px-3 py-2"><?php echo $mr['statusCell']; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>

<h2 class="text-lg font-semibold text-slate-800">Prepaid loans</h2>
<?php if ($showPrepaidMigrationBanner): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Recording prepaid interest is not available on this database yet. An administrator needs to run the pending database update (migration <code class="text-xs">0006_loans_prepaid_interest_received.sql</code>).</p>
<?php endif; ?>
<p class="text-sm text-slate-600">These loans had interest paid in advance. Each one appears from its start date through its prepaid-through date. Check the loan, choose the date you actually paid, and save once—after that it will show <strong>Posted</strong> for every month in that range.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Funding source</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Origin</th><th class="px-3 py-2 text-right font-medium">Prepaid amount</th>
<th class="px-3 py-2 font-medium">Record</th><th class="px-3 py-2 font-medium">Status</th>
</tr></thead><tbody>
<?php if ($prepaidRowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="6">No prepaid loans apply for this month.</td></tr>
<?php else: ?>
<?php foreach ($prepaidDisplayRows as $pr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($pr['fundingSource']); ?></td>
<td class="px-3 py-2"><?php echo e($pr['loanName']); ?></td>
<td class="px-3 py-2"><?php echo e($pr['origin']); ?></td>
<td class="px-3 py-2 text-right"><?php echo $pr['pAmtCellHtml']; ?></td>
<td class="px-3 py-2"><?php echo $pr['postCell']; ?></td>
<td class="px-3 py-2"><?php echo $pr['statusCell']; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>

<div class="flex flex-wrap items-end gap-4 rounded border border-slate-200 bg-white p-4 shadow-sm">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="event_date">Payment date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" value="<?php echo e($today); ?>" required></div>
<button class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Save selected payments</button>
</div>
<p class="text-xs text-slate-500">Monthly payments are saved for the month shown above. Prepaid loans save the full upfront interest as one payment—use the date on your records (often the closing or funding date).</p>
</form>

</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
