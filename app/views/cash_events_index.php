<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-4">
<div class="flex flex-wrap items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/cash-events/new">New cash event</a>
</div>
<form class="flex flex-wrap items-end gap-3 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/cash-events" id="cash-events-filter-form">
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
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show</button>
</form>
<?php if ($dateOrderError): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Start date must be on or before end date.</p>
<?php endif; ?>
<p class="text-sm text-slate-600">Showing events from <strong><?php echo e($start); ?></strong> through <strong><?php echo e($end); ?></strong>. Events from <strong>Checks</strong> may include a check month when applicable.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="whitespace-nowrap px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Loan</th>
<th class="px-3 py-2 text-right font-medium">Amount</th><th class="px-3 py-2 font-medium">Category</th><th class="px-3 py-2 font-medium">Deposit to</th>
<th class="px-3 py-2 font-medium">Check month</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($dateOrderError): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="8">Fix the date range above to see events.</td></tr>
<?php elseif ($rows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="8">No cash events in this date range.</td></tr>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php
    $id = (string) ($row['id'] ?? '');
    $ed = (string) ($row['event_date'] ?? '');
    $loan = (string) ($row['loan_name'] ?? '');
    if ($loan === '' && ($row['loan_id'] ?? null) === null) {
        $loan = '—';
    } elseif ($loan === '') {
        $loan = '#' . (string) ($row['loan_id'] ?? '');
    }
    $amtRaw = $row['amount'] !== null && $row['amount'] !== '' ? (string) $row['amount'] : '';
    $amtDisp = $amtRaw !== '' ? checks_format_money_display_2($amtRaw) : '—';
    $cat = (string) ($row['category'] ?? '');
    $dep = $row['deposit_to'] !== null && $row['deposit_to'] !== '' ? (string) $row['deposit_to'] : '—';
    $scm = $row['scheduled_check_ym'] !== null && $row['scheduled_check_ym'] !== '' ? (string) $row['scheduled_check_ym'] : '—';
    $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
?>
<tr class="border-t border-slate-100">
<td class="whitespace-nowrap px-3 py-2"><?php echo e($ed); ?></td>
<td class="px-3 py-2"><?php echo e($loan); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"><?php echo e($amtDisp); ?></td>
<td class="px-3 py-2"><?php echo e($cat); ?></td>
<td class="px-3 py-2"><?php echo e($dep); ?></td>
<td class="px-3 py-2"><?php echo e($scm); ?></td>
<td class="px-3 py-2 text-slate-600"><?php echo e($notes); ?></td>
<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/cash-events/edit?id=<?php echo e($id); ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
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
