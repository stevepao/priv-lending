<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?php echo e($title); ?></title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">
<div class="mx-auto max-w-xl space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<p class="text-sm text-slate-600">Update this cash event. The scheduled check month (if any) stays linked to this row and is not changed here.</p>
<a class="text-sm text-slate-600 underline" href="/cash-events">Back to cash events</a>
<?php if ($schCol && $scmVal !== ''): ?>
<p class="rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Linked check month: <code class="text-xs"><?php echo e($scmVal); ?></code> (from Checks posting).</p>
<?php endif; ?>
<?php if ($showInvalid): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Please fix the highlighted fields and try again.</p>
<?php endif; ?>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/cash-events/edit">
<?php echo csrf_field(); ?>
<input type="hidden" name="id" value="<?php echo e((string) $eventId); ?>">
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="loan_id">Loan (optional)</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="loan_id" name="loan_id">
<option value=""<?php echo $curLoanId === '' ? ' selected' : ''; ?>>— None —</option>
<?php foreach ($loans as $lr): ?>
<?php
    $lid = (string) ($lr['id'] ?? '');
    $label = e((string) ($lr['entity_name'] ?? '')) . ' — ' . e((string) ($lr['name'] ?? ''));
    $sel = $lid === $curLoanId ? ' selected' : '';
?>
<option value="<?php echo e($lid); ?>"<?php echo $sel; ?>><?php echo $label; ?></option>
<?php endforeach; ?>
</select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="event_date">Event date</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="event_date" name="event_date" type="date" required value="<?php echo e($eventDateVal); ?>"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="amount">Amount</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00" value="<?php echo e($amountVal); ?>"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="category">Category</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="category" name="category" required>
<?php foreach (['interest', 'principal_in', 'loc_interest', 'principal_out'] as $c): ?>
<?php $sel = $c === $catVal ? ' selected' : ''; ?>
<option value="<?php echo e($c); ?>"<?php echo $sel; ?>><?php echo e($c); ?></option>
<?php endforeach; ?>
</select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="deposit_to">Deposit to</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="deposit_to" name="deposit_to">
<option value=""<?php echo $depVal === '' ? ' selected' : ''; ?>>—</option>
<option value="JPM"<?php echo $depVal === 'JPM' ? ' selected' : ''; ?>>JPM</option><option value="NTRS"<?php echo $depVal === 'NTRS' ? ' selected' : ''; ?>>NTRS</option></select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>
<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="3"><?php echo e($notesVal); ?></textarea></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/cash-events">Cancel</a></div>
</form></div></body></html>
