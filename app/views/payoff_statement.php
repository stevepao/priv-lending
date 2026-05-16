<?php require __DIR__ . '/partials/layout_head.php'; ?>
<?php
/** @var string $loanLabel */
/** @var int $loanId */
/** @var string $dateQuoted */
/** @var string $payoffGoodThru */
/** @var string $dateQuotedDisp */
/** @var string $payoffGoodThruDisp */
/** @var string $fullStartDisp */
/** @var string $fullEndDisp */
/** @var string $perdiemStartDisp */
/** @var string $perdiemEndDisp */
/** @var string $principalDisp */
/** @var string $fullInterestDisp */
/** @var string $perdiemInterestDisp */
/** @var string $totalDueDisp */
/** @var string $dailyRateDisp */
/** @var int $daysInclusive */
?>
<div class="mx-auto max-w-2xl space-y-6">
<h1 class="text-2xl font-semibold tracking-tight text-slate-900">LOAN PAYOFF STATEMENT</h1>
<p class="text-sm text-slate-600"><?php echo e($loanLabel); ?> <span class="text-slate-400">(id <?php echo e((string) $loanId); ?>)</span></p>
<div class="space-y-4 rounded border border-slate-200 bg-white p-5 text-sm shadow-sm text-slate-800">
<div class="grid gap-1 sm:grid-cols-2 sm:gap-x-4">
<div class="text-slate-600">Date quoted</div>
<div class="font-medium"><?php echo e($dateQuotedDisp); ?></div>
<div class="text-slate-600">Payoff good through</div>
<div class="font-medium"><?php echo e($payoffGoodThruDisp); ?></div>
</div>
<hr class="border-slate-200">
<div class="flex flex-wrap justify-between gap-2 border-b border-slate-100 py-2">
<span class="text-slate-700">Principal</span>
<span class="font-mono tabular-nums font-semibold text-slate-900"><?php echo e($principalDisp); ?></span>
</div>
<div class="flex flex-wrap justify-between gap-2 border-b border-slate-100 py-2">
<span class="text-slate-700">Interest — <?php echo e($fullStartDisp); ?> thru <?php echo e($fullEndDisp); ?></span>
<span class="font-mono tabular-nums text-slate-900"><?php echo e($fullInterestDisp); ?></span>
</div>
<div class="flex flex-wrap justify-between gap-2 border-b border-slate-100 py-2">
<span class="text-slate-700">Interest — <?php echo e($perdiemStartDisp); ?> thru <?php echo e($perdiemEndDisp); ?> <span class="whitespace-nowrap text-xs font-normal text-slate-500">(<?php echo e((string) $daysInclusive); ?> day<?php echo $daysInclusive === 1 ? '' : 's'; ?>)</span></span>
<span class="font-mono tabular-nums text-slate-900"><?php echo e($perdiemInterestDisp); ?></span>
</div>
<div class="flex flex-wrap justify-between gap-2 border-b border-slate-200 py-3">
<span class="font-semibold text-slate-900">Total amount due</span>
<span class="font-mono tabular-nums text-lg font-semibold text-slate-900"><?php echo e($totalDueDisp); ?></span>
</div>
<div class="flex flex-wrap justify-between gap-2 pt-1">
<span class="text-slate-600">Daily interest rate</span>
<span class="font-mono tabular-nums text-slate-800"><?php echo e($dailyRateDisp); ?> <span class="text-xs font-normal text-slate-500">per day</span></span>
</div>
</div>
<p><a class="text-sm font-medium text-slate-700 underline hover:text-slate-900" href="/payoff">← Back to payoff form</a></p>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
