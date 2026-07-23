<?php require __DIR__ . '/partials/layout_head.php'; ?>
<?php
/** @var list<array{id: int, loanName: string, entityName: string}> $loanOptions */
/** @var string $dateQuotedDefault */
/** @var string $payoffGoodThruDefault */
/** @var bool $showInvalid */
?>
<div class="mx-auto max-w-2xl space-y-6">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<p class="text-sm text-slate-600">Outstanding principal and interest use the loan’s billing cycle day from <strong>origin date</strong> (day-of-month <em>D</em>). Payoff good through must be on or after date quoted.</p>
<?php if ($showInvalid): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please select a valid loan, dates (YYYY-MM-DD), and ensure payoff good through is not before date quoted.</p>
<?php endif; ?>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/payoff">
<?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id" required>
<?php if ($loanOptions === []): ?>
<option value="" disabled selected>No loans available — create a loan first.</option>
<?php else: ?>
<option value="" disabled selected>— Select a loan —</option>
<?php foreach ($loanOptions as $opt): ?>
<option value="<?php echo e((string) $opt['id']); ?>"><?php echo e($opt['entityName'] . ' — ' . $opt['loanName']); ?></option>
<?php endforeach; ?>
<?php endif; ?>
</select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="date_quoted">Date quoted</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="date_quoted" name="date_quoted" type="date" required value="<?php echo e($dateQuotedDefault); ?>"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="payoff_good_thru">Payoff good through</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="payoff_good_thru" name="payoff_good_thru" type="date" required value="<?php echo e($payoffGoodThruDefault); ?>"></div>
<div class="rounded border border-slate-200 bg-slate-50 px-3 py-3">
<label class="flex items-start gap-2 text-sm text-slate-800" for="last_month_interest_paid">
<input class="mt-0.5" id="last_month_interest_paid" name="last_month_interest_paid" type="checkbox" value="1">
<span><span class="font-medium">Last month’s interest already paid</span><span class="mt-0.5 block text-xs font-normal text-slate-600">Omit the prior full billing-cycle interest line (e.g. when that month’s check was just posted). Per-diem for the current cycle still applies.</span></span>
</label>
</div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit"<?php echo $loanOptions === [] ? ' disabled' : ''; ?>>Generate statement</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/">Cancel</a></div>
</form>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
