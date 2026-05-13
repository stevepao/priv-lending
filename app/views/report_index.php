<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-xl space-y-6">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<form class="flex flex-wrap items-end gap-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/report">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="start">Start date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="start" name="start" type="date" value="<?php echo e($start); ?>"></div>
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="end">End date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="end" name="end" type="date" value="<?php echo e($end); ?>"></div>
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Run report</button>
</form>
<?php if ($dateOrderError): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Start date must be on or before end date.</p>
<?php endif; ?>
<div class="space-y-3 rounded border border-slate-200 bg-white p-4 shadow-sm">
<h2 class="text-lg font-semibold text-slate-800">Totals</h2>
<dl class="grid grid-cols-1 gap-2 text-sm">
<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">Interest In</dt><dd class="font-mono font-medium text-slate-900"><?php echo e($interestInDisp); ?></dd></div>
<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">LOC Interest Out</dt><dd class="font-mono font-medium text-slate-900"><?php echo e($locInterestOutDisp); ?></dd></div>
<div class="flex justify-between gap-4 border-b border-slate-100 py-2"><dt class="text-slate-600">Net Income</dt><dd class="font-mono font-medium text-slate-900"><?php echo e($netIncomeDisp); ?></dd></div>
</dl>
<p class="text-xs font-medium uppercase tracking-wide text-slate-500">FYI</p>
<dl class="grid grid-cols-1 gap-2 text-sm">
<div class="flex justify-between gap-4 py-2"><dt class="text-slate-600">Principal Paid</dt><dd class="font-mono font-medium text-slate-900"><?php echo e($principalPaidDisp); ?></dd></div>
</dl>
<p class="text-xs text-slate-500">Interest In: sum of <code class="text-xs">cash_events.amount</code> where <code class="text-xs">category = interest</code> and <code class="text-xs">event_date</code> is in range (inclusive). LOC Interest Out uses <code class="text-xs">-SUM(amount)</code> for <code class="text-xs">loc_interest</code>. Principal Paid is <code class="text-xs">SUM(amount)</code> for <code class="text-xs">principal_in</code> plus <code class="text-xs">principal_out</code> (repayments positive, funding and bank principal draws negative). Net Income = Interest In − LOC Interest Out.</p>
</div></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
