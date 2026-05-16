<?php require __DIR__ . '/partials/layout_head.php'; ?>
<?php
/** @var string $title */
/** @var string $entityName */
/** @var string $borrowerName */
/** @var string $borrowerAddress */
/** @var string $borrowerCityStateZip */
/** @var string $propertyLine */
/** @var string $dateQuotedDisp */
/** @var string $payoffGoodThruDisp */
/** @var string $interestFullRange */
/** @var string $interestPerdiemRange */
/** @var string $principalDisp */
/** @var string $fullInterestDisp */
/** @var string $perdiemInterestDisp */
/** @var string $totalDueDisp */
/** @var string $dailyRateDisp */
/** @var int $loanId */
/** @var string $dateQuotedYmd */
/** @var string $payoffGoodThruYmd */
?>
<div class="mx-auto max-w-3xl space-y-8 px-4 py-6 text-slate-900 print:max-w-none print:px-0">
<div class="flex flex-col justify-between gap-10 sm:flex-row sm:gap-16">
<div class="space-y-0.5 text-sm leading-snug">
<?php if (LENDER_NAME !== ''): ?>
<div class="font-semibold"><?php echo e(LENDER_NAME); ?></div>
<?php endif; ?>
<?php if (LENDER_ADDRESS !== ''): ?>
<div><?php echo e(LENDER_ADDRESS); ?></div>
<?php endif; ?>
<?php
if (LENDER_CITY !== '' || LENDER_STATE !== '' || LENDER_ZIP !== '') {
    $lenderParts = [];
    if (LENDER_CITY !== '') {
        $lenderParts[] = LENDER_CITY;
    }
    $stZip = trim(LENDER_STATE . ' ' . LENDER_ZIP);
    if ($stZip !== '') {
        $lenderParts[] = $stZip;
    }
    $lenderCityStateZipLine = implode(', ', $lenderParts);
    ?>
<div><?php echo e($lenderCityStateZipLine); ?></div>
<?php
}
?>
<?php if (LENDER_EMAIL !== ''): ?>
<div><?php echo e(LENDER_EMAIL); ?></div>
<?php endif; ?>
<?php if (LENDER_PHONE !== ''): ?>
<div><?php echo e(LENDER_PHONE); ?></div>
<?php endif; ?>
</div>
<div class="space-y-0.5 text-sm leading-snug sm:text-right">
<?php if ($entityName !== ''): ?>
<div class="font-semibold"><?php echo e($entityName); ?></div>
<?php endif; ?>
<?php if ($borrowerAddress !== ''): ?>
<div><?php echo e($borrowerAddress); ?></div>
<?php endif; ?>
<?php if ($borrowerCityStateZip !== ''): ?>
<div><?php echo e($borrowerCityStateZip); ?></div>
<?php endif; ?>
<?php if ($borrowerName !== ''): ?>
<div>Attn: <?php echo e($borrowerName); ?></div>
<?php endif; ?>
</div>
</div>

<h1 class="text-center text-xl font-bold tracking-wide">LOAN PAYOFF STATEMENT</h1>

<div class="mx-auto max-w-xl space-y-3 text-sm">
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Date quoted:</span><span class="text-right tabular-nums"><?php echo e($dateQuotedDisp); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Payoff good to:</span><span class="text-right tabular-nums"><?php echo e($payoffGoodThruDisp); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Property:</span><span class="text-right"><?php echo e($propertyLine); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Principal</span><span class="font-medium text-right tabular-nums"><?php echo e($principalDisp); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Interest - <?php echo e($interestFullRange); ?></span><span class="text-right tabular-nums"><?php echo e($fullInterestDisp); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-2"><span>Interest - <?php echo e($interestPerdiemRange); ?></span><span class="text-right tabular-nums"><?php echo e($perdiemInterestDisp); ?></span></div>
<div class="flex justify-between gap-6 border-b border-slate-200 py-3"><span class="font-semibold">Total amount due:</span><span class="font-semibold text-right tabular-nums"><?php echo e($totalDueDisp); ?></span></div>
<div class="flex justify-between gap-6 py-2"><span>Daily interest rate</span><span class="text-right tabular-nums"><?php echo e($dailyRateDisp); ?></span></div>
</div>

<?php
$pdfQs = http_build_query([
    'loan_id' => $loanId,
    'date_quoted' => $dateQuotedYmd,
    'payoff_good_thru' => $payoffGoodThruYmd,
]);
?>
<div class="flex justify-center pt-6 print:hidden">
<a class="inline-flex rounded bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800" href="/payoff/pdf?<?php echo e($pdfQs); ?>">Download PDF</a>
</div>

<p class="pt-6 text-center print:hidden"><a class="text-sm font-medium text-slate-700 underline hover:text-slate-900" href="/payoff">← Back to payoff form</a></p>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
