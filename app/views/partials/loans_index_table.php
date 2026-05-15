<?php
/** @var array<int, array<string, string|bool>> $loanRows */
/** @var bool $rowsEmpty */
/** @var string $emptyMessage */
?>
<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">
<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>
<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Name</th>
<th class="px-3 py-2 font-medium">Funding</th><th class="px-3 py-2 font-medium">Origin</th><th class="px-3 py-2 text-right font-medium">Current balance</th>
<th class="px-3 py-2 text-right font-medium">Principal</th><th class="px-3 py-2 font-medium">Rate %</th><th class="px-3 py-2 font-medium">Payment type</th>
<th class="px-3 py-2 font-medium">Actions</th>
</tr></thead><tbody>
<?php if ($rowsEmpty): ?>
<tr><td class="px-3 py-4 text-slate-500" colspan="10"><?php echo e($emptyMessage); ?></td></tr>
<?php else: ?>
<?php foreach ($loanRows as $lr): ?>
<tr class="border-t border-slate-100">
<td class="px-3 py-2"><?php echo e($lr['id']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['entityName']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['loanName']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['funding']); ?></td>
<td class="px-3 py-2"><?php echo e($lr['origin']); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums italic text-slate-800" title="Sum of cash_events.amount for principal_in and principal_out on this loan (funding negative, repayments positive; zero when fully repaid)."><?php echo e(checks_format_money_display_2($lr['currentBalance'])); ?></td>
<td class="px-3 py-2 text-right font-mono tabular-nums"<?php echo $lr['principalTitle'] !== '' ? ' title="' . e($lr['principalTitle']) . '"' : ''; ?>><?php echo e(checks_format_money_display_2($lr['principal'])); ?></td>
<?php if ($lr['rateIsImplied']): ?>
<td class="px-3 py-2 italic text-slate-800" title="Implied annual %: monthly interest × 12 ÷ principal × 100 (stored annual rate is blank or zero)."><?php echo e($lr['impliedAnnual']); ?></td>
<?php else: ?>
<td class="px-3 py-2"><?php echo e($lr['rateDisplay']); ?></td>
<?php endif; ?>
<td class="px-3 py-2"><?php echo e($lr['ptype']); ?></td>
<td class="px-3 py-2"><a class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-800 hover:bg-slate-50" href="/loans/edit?id=<?php echo e($lr['id']); ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody></table></div>
