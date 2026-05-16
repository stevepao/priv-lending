<?php
/** @var string $bankLabel */
/** @var list<array{entityName: string, loanName: string, balanceDisp: string}> $loans */
/** @var string $loansTotalBalanceDisp */
/** @var list<array{monthLabel: string, interestDisp: string, locDisp: string, principalInDisp: string}> $recentMonths */
?>
<section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
<h2 class="text-lg font-semibold text-slate-900"><?php echo e($bankLabel); ?></h2>
<h3 class="mt-4 text-xs font-medium uppercase tracking-wide text-slate-500">Loans</h3>
<div class="mt-2 overflow-x-auto">
<table class="min-w-full text-left text-sm">
<thead class="border-b border-slate-200 text-slate-600"><tr>
<th class="py-2 pr-3 font-medium">Entity</th>
<th class="py-2 pr-3 font-medium">Loan</th>
<th class="py-2 text-right font-medium">Balance</th>
</tr></thead>
<tbody>
<?php if ($loans === []): ?>
<tr><td class="py-3 text-slate-500" colspan="3">No open loans with funding <?php echo e($bankLabel); ?>.</td></tr>
<?php else: ?>
<?php foreach ($loans as $lr): ?>
<tr class="border-t border-slate-100">
<td class="py-2 pr-3 text-slate-800"><?php echo e($lr['entityName']); ?></td>
<td class="py-2 pr-3 font-medium text-slate-900"><?php echo e($lr['loanName']); ?></td>
<td class="py-2 text-right font-mono tabular-nums italic text-slate-800"><?php echo e($lr['balanceDisp']); ?></td>
</tr>
<?php endforeach; ?>
<tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
<td class="py-2 pr-3 text-slate-900" colspan="2">Total balance</td>
<td class="py-2 text-right font-mono tabular-nums italic text-slate-900"><?php echo e($loansTotalBalanceDisp); ?></td>
</tr>
<?php endif; ?>
</tbody></table></div>
<h3 class="mt-6 text-xs font-medium uppercase tracking-wide text-slate-500">Recent months</h3>
<p class="mt-1 text-xs text-slate-500">Interest and principal in are sums of recorded cash amounts; LOC interest is shown as a positive expense.</p>
<div class="mt-2 overflow-x-auto">
<table class="min-w-full text-left text-sm">
<thead class="border-b border-slate-200 text-slate-600"><tr>
<th class="py-2 pr-3 font-medium">Month</th>
<th class="py-2 pr-2 text-right font-medium">Interest</th>
<th class="py-2 pr-2 text-right font-medium">LOC interest</th>
<th class="py-2 text-right font-medium">Principal in</th>
</tr></thead>
<tbody>
<?php foreach ($recentMonths as $mr): ?>
<tr class="border-t border-slate-100">
<td class="py-2 pr-3 font-medium text-slate-900"><?php echo e($mr['monthLabel']); ?></td>
<td class="py-2 pr-2 text-right font-mono tabular-nums text-slate-800"><?php echo e($mr['interestDisp']); ?></td>
<td class="py-2 pr-2 text-right font-mono tabular-nums text-slate-800"><?php echo e($mr['locDisp']); ?></td>
<td class="py-2 text-right font-mono tabular-nums text-slate-800"><?php echo e($mr['principalInDisp']); ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
