<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-6">
<div class="flex flex-wrap items-end justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<form class="flex flex-wrap items-end gap-2" method="get" action="/checks">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="month">Calendar month</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="month" name="month" type="month" value="<?php echo e($selectedYm); ?>"></div>
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show</button>
</form></div>
<p class="text-sm text-slate-600">Expected payment for <strong>interest-only</strong>, <strong>amortizing</strong>, and <strong>post-prepaid</strong> loans for this calendar month (loans appear in the monthly table starting the <strong>month after</strong> the loan’s origin month; the origin month itself has no monthly check). Posted monthly checks show <strong>Posted</strong> in the status column (no checkbox). For <strong>declining balance</strong>, the total is interest on the remaining balance plus the scheduled monthly principal (<code class="text-xs">principal_payment_monthly</code>). Paydown count excludes the loan’s origin month. <strong>Prepaid</strong> loans appear from the origin month through the prepaid-through month so you can post the lump prepaid interest once (then they show as Posted here until that window ends). Use <strong>Post cash events</strong> below for both tables.</p>
<?php if ($showScheduledCheckMigrationBanner): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Monthly posting and Posted status require migration <code class="text-xs">0005_cash_events_scheduled_check.sql</code>. Run <code class="text-xs">php bin/migrate.php</code> on the server.</p>
<?php endif; ?>
<?php if ($showChecksCategoryUniqueMigrationBanner): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Posting declining-balance checks as separate interest and principal cash events requires migration <code class="text-xs">0007_cash_events_scheduled_check_category_unique.sql</code>. Run <code class="text-xs">php bin/migrate.php</code>.</p>
<?php endif; ?>
<?php if ($postedSuccess): ?>
<p class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">Cash events were posted for the selected checkboxes.</p>
<?php endif; ?>
<?php if ($postedFailure): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Nothing was posted (no qualifying loans selected, invalid date, or duplicate posting).</p>
<?php endif; ?>

<form method="post" action="/checks" class="space-y-4">
<?php echo csrf_field(); ?>
<input type="hidden" name="month" value="<?php echo e($selectedYm); ?>">
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Funding source</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Method</th>
<th class="px-3 py-2 text-right font-medium">Expected payment</th><th class="px-3 py-2 font-medium">Post</th><th class="px-3 py-2 font-medium">Status</th>
</tr></thead><tbody>
<?php if ($monthlyRowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="6">No interest-only, amortizing, or post-prepaid loans for this calendar month.</td></tr>
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
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Posting prepaid interest requires migration <code class="text-xs">0006_loans_prepaid_interest_received.sql</code>. Run <code class="text-xs">php bin/migrate.php</code>.</p>
<?php endif; ?>
<p class="text-xs text-slate-600">Shown for each calendar month from the loan’s <strong>origin</strong> through the <strong>prepaid-through</strong> month (<code class="text-xs">prepaid_interest_date</code>). Post the lump prepaid interest once using the cash event date above; after posting, the row shows <strong>Posted</strong> (no checkbox) for every month in that range.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Funding source</th><th class="px-3 py-2 font-medium">Loan</th><th class="px-3 py-2 font-medium">Prepaid amount</th>
<th class="px-3 py-2 font-medium">Post</th><th class="px-3 py-2 font-medium">Status</th>
</tr></thead><tbody>
<?php if ($prepaidRowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="5">No prepaid loans in the prepaid-through window for this month.</td></tr>
<?php else: ?>
<?php foreach ($prepaidDisplayRows as $pr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($pr['fundingSource']); ?></td>
<td class="px-3 py-2"><?php echo e($pr['loanName']); ?></td>
<td class="px-3 py-2 font-medium"><?php echo e($pr['pAmtDisp']); ?></td>
<td class="px-3 py-2"><?php echo $pr['postCell']; ?></td>
<td class="px-3 py-2"><?php echo $pr['statusCell']; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>

<div class="flex flex-wrap items-end gap-4 rounded border border-slate-200 bg-white p-4 shadow-sm">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="event_date">Cash event date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" value="<?php echo e($today); ?>" required></div>
<button class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Post cash events</button>
</div>
<p class="text-xs text-slate-500"><strong>Monthly table:</strong> posts cash event(s) per checked loan with <code class="text-xs">scheduled_check_ym</code> set to the month shown (declining balance: interest plus <code class="text-xs">principal_in</code> when the database unique index supports it). Status becomes <strong>Posted</strong>. <strong>Prepaid table:</strong> one <strong>interest</strong> event for the lump <code class="text-xs">prepaid_interest_amount</code> (<code class="text-xs">scheduled_check_ym</code> left blank); use the event date you need (often close or funding date).</p>
</form>

</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
