<?php
declare(strict_types=1);
/** @var string $title */
/** @var int $year */
/** @var string $group */
/** @var string $start */
/** @var string $end */
/** @var list<array{rowId: string, rowLabel: string, interestTotal: string}> $groupRows */
/** @var string $grandTotal */
/** @var list<array{entityId: int, entityName: string, interestTotal: string}> $entityRows */
$groupLabel = match ($group) {
    'borrower' => 'Borrower',
    'loan' => 'Loan',
    default => 'Entity',
};
require __DIR__ . '/partials/layout_head.php';
?>
<div class="mx-auto max-w-4xl space-y-8">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<p class="text-sm text-slate-600">Interest from <code class="text-xs">cash_events</code> where <code class="text-xs">category = interest</code> and <code class="text-xs">event_date</code> is within the calendar year (<?php echo e($start); ?> through <?php echo e($end); ?>).</p>
<form class="flex flex-wrap items-end gap-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="get" action="/reconcile">
<div>
<label class="mb-1 block text-xs font-medium text-slate-600" for="year">Year</label>
<input class="w-28 rounded border border-slate-300 px-3 py-2 text-sm" id="year" name="year" type="number" min="2000" max="2100" step="1" value="<?php echo e((string) $year); ?>">
</div>
<div>
<label class="mb-1 block text-xs font-medium text-slate-600" for="group">Group by</label>
<select class="rounded border border-slate-300 px-3 py-2 text-sm" id="group" name="group">
<option value="entity"<?php echo $group === 'entity' ? ' selected' : ''; ?>>Entity</option>
<option value="borrower"<?php echo $group === 'borrower' ? ' selected' : ''; ?>>Borrower</option>
<option value="loan"<?php echo $group === 'loan' ? ' selected' : ''; ?>>Loan</option>
</select>
</div>
<button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Show</button>
</form>
<div class="overflow-x-auto rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full divide-y divide-slate-200 text-sm">
<thead class="bg-slate-50"><tr>
<th class="px-4 py-3 text-left font-medium text-slate-700"><?php echo e($groupLabel); ?></th>
<th class="px-4 py-3 text-right font-medium text-slate-700">Computed interest</th>
</tr></thead>
<tbody class="divide-y divide-slate-100">
<?php if ($groupRows === []): ?>
<tr><td class="px-4 py-6 text-slate-500" colspan="2">No interest events in this year.</td></tr>
<?php else: ?>
<?php foreach ($groupRows as $gr): ?>
<tr>
<td class="px-4 py-2 text-slate-900"><?php echo e($gr['rowLabel']); ?></td>
<td class="px-4 py-2 text-right font-mono text-slate-900"><?php echo e($gr['interestTotal']); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
<tfoot class="border-t-2 border-slate-300 bg-slate-50">
<tr>
<th class="px-4 py-3 text-left font-semibold text-slate-900" scope="row">Grand total</th>
<td class="px-4 py-3 text-right font-mono font-semibold text-slate-900"><?php echo e($grandTotal); ?></td>
</tr>
</tfoot>
</table>
</div>
<div class="space-y-3 rounded border border-slate-200 bg-white p-4 shadow-sm">
<h2 class="text-lg font-semibold text-slate-800">1099 comparison (per entity)</h2>
<p class="text-sm text-slate-600">Optional: enter amounts reported on Form 1099-INT (or your records). Values are not saved. <strong class="font-medium">Difference</strong> = Reported − Computed (entity-level interest through linked loans only).</p>
<div class="overflow-x-auto">
<table class="min-w-full divide-y divide-slate-200 text-sm">
<thead class="bg-slate-50"><tr>
<th class="px-4 py-3 text-left font-medium text-slate-700">Entity</th>
<th class="px-4 py-3 text-right font-medium text-slate-700">Computed</th>
<th class="px-4 py-3 text-right font-medium text-slate-700">Reported</th>
<th class="px-4 py-3 text-right font-medium text-slate-700">Difference</th>
</tr></thead>
<tbody class="divide-y divide-slate-100">
<?php if ($entityRows === []): ?>
<tr><td class="px-4 py-6 text-slate-500" colspan="4">No entity-linked interest in this year.</td></tr>
<?php else: ?>
<?php foreach ($entityRows as $er): ?>
<?php
$eid = $er['entityId'];
$diffId = 'reconcile-diff-' . (string) $eid;
?>
<tr>
<td class="px-4 py-2 text-slate-900"><?php echo e($er['entityName']); ?></td>
<td class="px-4 py-2 text-right font-mono text-slate-900"><?php echo e($er['interestTotal']); ?></td>
<td class="px-4 py-2 text-right">
<label class="sr-only" for="reported-<?php echo e((string) $eid); ?>">1099 reported for <?php echo e($er['entityName']); ?></label>
<input class="reported-1099 w-32 rounded border border-slate-300 px-2 py-1 text-right font-mono text-sm" id="reported-<?php echo e((string) $eid); ?>" type="text" inputmode="decimal" autocomplete="off" placeholder="—" data-computed="<?php echo e($er['interestTotal']); ?>" data-diff-target="<?php echo e($diffId); ?>">
</td>
<td class="px-4 py-2 text-right font-mono text-slate-900" id="<?php echo e($diffId); ?>">—</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
(function () {
  function parseMoney(s) {
    if (s == null) return NaN;
    var t = String(s).trim().replace(/,/g, '');
    if (t === '' || t === '—') return NaN;
    var n = parseFloat(t);
    return n;
  }
  function fmtDiff(n) {
    if (!isFinite(n)) return '—';
    return n.toFixed(2);
  }
  function updateOne(el) {
    var id = el.getAttribute('data-diff-target');
    var out = id ? document.getElementById(id) : null;
    if (!out) return;
    var c = parseMoney(el.getAttribute('data-computed'));
    var r = parseMoney(el.value);
    if (!isFinite(c)) {
      out.textContent = '—';
      return;
    }
    if (!isFinite(r)) {
      out.textContent = '—';
      return;
    }
    out.textContent = fmtDiff(r - c);
  }
  document.querySelectorAll('.reported-1099').forEach(function (el) {
    el.addEventListener('input', function () { updateOne(el); });
    el.addEventListener('change', function () { updateOne(el); });
  });
})();
</script>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
