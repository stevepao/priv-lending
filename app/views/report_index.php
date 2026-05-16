<?php require __DIR__ . '/partials/layout_head.php'; ?>
<?php
$colspanMetric = $reportType === 'month_bank' ? 6 : 5;
$leadCols = $reportType === 'month_bank' ? 2 : 1;
$reportAllocLocItalics = ($reportType === 'loan' || $reportType === 'entity');
?>
<div class="mx-auto max-w-4xl space-y-6">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/report">
<div class="flex flex-wrap items-end gap-3">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="report_type">Report type</label>
<select class="rounded border border-slate-300 px-3 py-2 text-sm" id="report_type" name="report_type">
<option value="month"<?php echo $reportType === 'month' ? ' selected' : ''; ?>>By month</option>
<option value="bank"<?php echo $reportType === 'bank' ? ' selected' : ''; ?>>By bank</option>
<option value="month_bank"<?php echo $reportType === 'month_bank' ? ' selected' : ''; ?>>By month, by bank</option>
<option value="loan"<?php echo $reportType === 'loan' ? ' selected' : ''; ?>>By loan</option>
<option value="entity"<?php echo $reportType === 'entity' ? ' selected' : ''; ?>>By entity</option>
</select></div>
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="range">Date range</label>
<select class="rounded border border-slate-300 px-3 py-2 text-sm" id="range" name="range">
<option value="last_3_months"<?php echo $range === 'last_3_months' ? ' selected' : ''; ?>>Last 3 months</option>
<option value="last_full_year"<?php echo $range === 'last_full_year' ? ' selected' : ''; ?>>Last full year</option>
<option value="ytd"<?php echo $range === 'ytd' ? ' selected' : ''; ?>>Current year to date</option>
<option value="quarter"<?php echo $range === 'quarter' ? ' selected' : ''; ?>>Calendar quarter to date</option>
<option value="custom"<?php echo $range === 'custom' ? ' selected' : ''; ?>>Custom range</option>
</select></div>
<div id="custom-range-fields" class="flex flex-wrap items-end gap-3<?php echo $range === 'custom' ? '' : ' hidden'; ?>">
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="start">Start date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="start" name="start" type="date" value="<?php echo e($start); ?>"></div>
<div><label class="mb-1 block text-xs font-medium text-slate-600" for="end">End date</label>
<input class="rounded border border-slate-300 px-3 py-2 text-sm" id="end" name="end" type="date" value="<?php echo e($end); ?>"></div>
</div>
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show report</button>
</div>
</form>
<?php if ($dateOrderError): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Start date must be on or before end date.</p>
<?php endif; ?>
<p class="text-sm text-slate-600">
<?php if ($reportType === 'month'): ?>
Monthly totals from <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>; every calendar month in range is shown (zeros when there is no activity).
<?php elseif ($reportType === 'bank'): ?>
Totals by bank from <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>, grouped by <strong>Deposit to</strong> (e.g. JPM, NTRS)—the same field used for line-of-credit interest and loan postings.
<?php elseif ($reportType === 'month_bank'): ?>
Each row is one calendar month and bank. Only month–bank combinations with activity in range are listed. Same bank field as <strong>Deposit to</strong> on cash events.
<?php elseif ($reportType === 'loan'): ?>
Totals by loan for <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>. Rows with no loan aggregate bank or other events not tied to a loan.
<strong>LOC interest out</strong> is a rough allocation: for each bank, period LOC expense is split across loans in proportion to <strong>current balance</strong> (principal in + out on the loan, same as the Loans list) for loans funded from that bank (<strong>Funding source</strong>, same values as <strong>Deposit to</strong>) vs total such balance for that bank.
<?php else: ?>
Totals by borrowing entity for <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>, using each loan’s entity. Events not on a loan are grouped separately.
<strong>LOC interest out</strong> uses the same allocation rule by entity: each bank’s share uses sums of loan current balances for that entity and funding source.
<?php endif; ?>
Net income is interest in minus LOC interest out (minus allocated LOC for by-loan and by-entity views). Principal paid is the sum of principal in and principal out (for reference).</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<?php if ($reportType === 'month'): ?>
<th class="px-3 py-2 font-medium">Month</th>
<?php elseif ($reportType === 'bank'): ?>
<th class="px-3 py-2 font-medium">Bank</th>
<?php elseif ($reportType === 'month_bank'): ?>
<th class="px-3 py-2 font-medium">Month</th><th class="px-3 py-2 font-medium">Bank</th>
<?php elseif ($reportType === 'loan'): ?>
<th class="px-3 py-2 font-medium">Loan</th>
<?php else: ?>
<th class="px-3 py-2 font-medium">Entity</th>
<?php endif; ?>
<th class="px-3 py-2 text-right font-medium">Interest in</th>
<th class="px-3 py-2 text-right font-medium">LOC interest out</th>
<th class="px-3 py-2 text-right font-medium">Net income</th>
<th class="px-3 py-2 text-right font-medium">Principal paid</th>
</tr></thead><tbody>
<?php if ($dateOrderError): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="<?php echo (int) $colspanMetric; ?>">Fix the date range above to see the report.</td></tr>
<?php elseif ($detailRows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="<?php echo (int) $colspanMetric; ?>"><?php echo $reportType === 'month' ? 'No months in this date range.' : 'No cash activity in this date range.'; ?></td></tr>
<?php else: ?>
<?php foreach ($detailRows as $dr): ?>
<tr class="border-t border-slate-100">
<?php if ($reportType === 'month'): ?>
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($dr['monthLabel']); ?></td>
<?php elseif ($reportType === 'bank'): ?>
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($dr['bankLabel']); ?></td>
<?php elseif ($reportType === 'month_bank'): ?>
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($dr['monthLabel']); ?></td>
<td class="px-3 py-2 text-slate-800"><?php echo e($dr['bankLabel']); ?></td>
<?php elseif ($reportType === 'loan'): ?>
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($dr['loanLabel']); ?></td>
<?php else: ?>
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($dr['entityLabel']); ?></td>
<?php endif; ?>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e($dr['interestInDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums<?php echo !empty($dr['allocLocComputed']) ? ' italic text-slate-800' : ''; ?>"><?php echo e($dr['locInterestOutDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums font-medium<?php echo !empty($dr['allocLocComputed']) ? ' italic text-slate-800' : ''; ?>"><?php echo e($dr['netIncomeDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums text-slate-600"><?php echo e($dr['principalPaidDisp']); ?></td>
</tr>
<?php endforeach; ?>
<tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
<td class="px-3 py-2 text-slate-900" colspan="<?php echo (int) $leadCols; ?>">Total</td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e(checks_format_money_display_2($totals['interestIn'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums<?php echo $reportAllocLocItalics ? ' italic text-slate-800' : ''; ?>"><?php echo e(checks_format_money_display_2($totals['locInterestOut'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums<?php echo $reportAllocLocItalics ? ' italic text-slate-800' : ''; ?>"><?php echo e(checks_format_money_display_2($totals['netIncome'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums text-slate-700"><?php echo e(checks_format_money_display_2($totals['principalPaid'])); ?></td>
</tr>
<?php endif; ?>
</tbody></table></div>
<?php if ($reportAllocLocItalics && ($locAllocUnallocatedNote ?? '') !== ''): ?>
<p class="text-sm text-slate-600"><?php echo e($locAllocUnallocatedNote); ?></p>
<?php endif; ?>
<script>
(function () {
  var sel = document.getElementById('range');
  var custom = document.getElementById('custom-range-fields');
  if (!sel || !custom) {
    return;
  }
  function sync() {
    var isCustom = sel.value === 'custom';
    custom.classList.toggle('hidden', !isCustom);
    custom.querySelectorAll('input').forEach(function (inp) {
      inp.disabled = !isCustom;
    });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
