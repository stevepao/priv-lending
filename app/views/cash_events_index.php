<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-4">
<div class="flex flex-wrap items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/cash-events/new">New cash event</a>
</div>
<p class="text-sm text-slate-600">Ledger of cash movements. Events from <strong>Checks</strong> include the scheduled month in <code class="text-xs">scheduled_check_ym</code> when set.</p>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="whitespace-nowrap px-3 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Loan</th>
<th class="px-3 py-2 text-right font-medium">Amount</th><th class="px-3 py-2 font-medium">Category</th><th class="px-3 py-2 font-medium">Deposit to</th>
<th class="px-3 py-2 font-medium">Check month</th><th class="px-3 py-2 font-medium">Notes</th><th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($rows === []): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="8">No cash events yet.</td></tr>
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
</tbody></table></div></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
