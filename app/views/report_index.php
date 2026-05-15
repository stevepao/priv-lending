<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-4xl space-y-6">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<form class="flex flex-wrap items-end gap-3 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/report">
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
</form>
<?php if ($dateOrderError): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Start date must be on or before end date.</p>
<?php endif; ?>
<p class="text-sm text-slate-600">Monthly totals from <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>. Net income is interest in minus LOC interest out. Principal paid is shown for reference.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">Month</th>
<th class="px-3 py-2 text-right font-medium">Interest in</th>
<th class="px-3 py-2 text-right font-medium">LOC interest out</th>
<th class="px-3 py-2 text-right font-medium">Net income</th>
<th class="px-3 py-2 text-right font-medium">Principal paid</th>
</tr></thead><tbody>
<?php if ($dateOrderError): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="5">Fix the date range above to see the report.</td></tr>
<?php elseif ($monthlyRows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="5">No months in this date range.</td></tr>
<?php else: ?>
<?php foreach ($monthlyRows as $mr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2 font-medium text-slate-900"><?php echo e($mr['monthLabel']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e($mr['interestInDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e($mr['locInterestOutDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums font-medium"><?php echo e($mr['netIncomeDisp']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums text-slate-600"><?php echo e($mr['principalPaidDisp']); ?></td>
</tr>
<?php endforeach; ?>
<tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
<td class="px-3 py-2 text-slate-900">Total</td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e(checks_format_money_display_2($totals['interestIn'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e(checks_format_money_display_2($totals['locInterestOut'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e(checks_format_money_display_2($totals['netIncome'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums text-slate-700"><?php echo e(checks_format_money_display_2($totals['principalPaid'])); ?></td>
</tr>
<?php endif; ?>
</tbody></table></div>
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
