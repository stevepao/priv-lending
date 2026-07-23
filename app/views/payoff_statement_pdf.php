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
/** @var bool $showFullMonthInterest */
/** @var string $principalDisp */
/** @var string $fullInterestDisp */
/** @var string $perdiemInterestDisp */
/** @var string $totalDueDisp */
/** @var string $dailyRateDisp */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo e($title); ?></title>
<style>
@page { margin: 36pt 42pt; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #0f172a; margin: 0; }
table.meta { width: 100%; margin-bottom: 16pt; border-collapse: collapse; }
td.meta-left, td.meta-right { width: 50%; vertical-align: top; padding: 0; font-size: 9pt; line-height: 1.35; }
td.meta-right { text-align: right; }
.lender-name, .entity-name { font-weight: bold; }
h1 { text-align: center; font-size: 13pt; margin: 14pt 0 12pt 0; font-weight: bold; letter-spacing: 0.02em; }
.amounts-table { width: 100%; max-width: 400px; margin: 0 auto; font-size: 9pt; border-collapse: collapse; }
.amounts-table td { padding: 5pt 0; vertical-align: top; border-bottom: 1px solid #cbd5e1; }
.amounts-table td.num { text-align: right; white-space: nowrap; }
.amounts-table tr.total td { font-weight: bold; border-bottom: 1px solid #94a3b8; padding-top: 7pt; padding-bottom: 7pt; }
.amounts-table tr.last td { border-bottom: none; }
</style>
</head>
<body>
<table class="meta" cellspacing="0"><tr>
<td class="meta-left">
<?php if (LENDER_NAME !== ''): ?><div class="lender-name"><?php echo e(LENDER_NAME); ?></div><?php endif; ?>
<?php if (LENDER_ADDRESS !== ''): ?><div><?php echo e(LENDER_ADDRESS); ?></div><?php endif; ?>
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
    echo '<div>' . e($lenderCityStateZipLine) . '</div>';
}
?>
<?php if (LENDER_EMAIL !== ''): ?><div><?php echo e(LENDER_EMAIL); ?></div><?php endif; ?>
<?php if (LENDER_PHONE !== ''): ?><div><?php echo e(LENDER_PHONE); ?></div><?php endif; ?>
</td>
<td class="meta-right">
<?php if ($entityName !== ''): ?><div class="entity-name"><?php echo e($entityName); ?></div><?php endif; ?>
<?php if ($borrowerAddress !== ''): ?><div><?php echo e($borrowerAddress); ?></div><?php endif; ?>
<?php if ($borrowerCityStateZip !== ''): ?><div><?php echo e($borrowerCityStateZip); ?></div><?php endif; ?>
<?php if ($borrowerName !== ''): ?><div>Attn: <?php echo e($borrowerName); ?></div><?php endif; ?>
</td>
</tr></table>

<h1>LOAN PAYOFF STATEMENT</h1>

<table class="amounts-table">
<tr><td>Date quoted:</td><td class="num"><?php echo e($dateQuotedDisp); ?></td></tr>
<tr><td>Payoff good to:</td><td class="num"><?php echo e($payoffGoodThruDisp); ?></td></tr>
<tr><td>Property:</td><td class="num"><?php echo e($propertyLine); ?></td></tr>
<tr><td>Principal</td><td class="num"><?php echo e($principalDisp); ?></td></tr>
<?php if ($showFullMonthInterest): ?>
<tr><td>Interest - <?php echo e($interestFullRange); ?></td><td class="num"><?php echo e($fullInterestDisp); ?></td></tr>
<?php endif; ?>
<tr><td>Interest - <?php echo e($interestPerdiemRange); ?></td><td class="num"><?php echo e($perdiemInterestDisp); ?></td></tr>
<tr class="total"><td>Total amount due:</td><td class="num"><?php echo e($totalDueDisp); ?></td></tr>
<tr class="last"><td>Daily interest rate</td><td class="num"><?php echo e($dailyRateDisp); ?></td></tr>
</table>
</body>
</html>
