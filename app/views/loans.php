<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-6xl space-y-6">
<div class="flex items-center justify-between gap-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/loans/new">New loan</a>
</div>

<h2 class="text-lg font-semibold text-slate-800">Open loans</h2>
<?php
$loanRows = $openLoanRows;
$rowsEmpty = $openLoanRows === [];
$emptyMessage = 'No open loans.';
require __DIR__ . '/partials/loans_index_table.php';
?>

<h2 class="text-lg font-semibold text-slate-800">Closed loans</h2>
<?php
$loanRows = $closedLoanRows;
$rowsEmpty = $closedLoanRows === [];
$emptyMessage = 'No closed loans.';
require __DIR__ . '/partials/loans_index_table.php';
?>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
