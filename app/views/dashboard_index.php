<?php require __DIR__ . '/partials/layout_head.php'; ?>
<?php
/** @var list<array{entityName: string, loanName: string, balanceDisp: string}> $jpmLoans */
/** @var string $jpmLoansTotalBalanceDisp */
/** @var list<array{entityName: string, loanName: string, balanceDisp: string}> $ntrsLoans */
/** @var string $ntrsLoansTotalBalanceDisp */
/** @var list<array{monthLabel: string, interestDisp: string, locDisp: string, principalInDisp: string}> $jpmMonths */
/** @var list<array{monthLabel: string, interestDisp: string, locDisp: string, principalInDisp: string}> $ntrsMonths */
?>
<div class="mx-auto max-w-6xl space-y-8">
<section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
<h1 class="text-2xl font-semibold text-slate-900"><?php echo e($heading); ?></h1>
<div class="mt-4 space-y-3 text-sm text-slate-700">
<p><strong>Private Lending</strong> is a simple workspace for informal private loans: you track <strong>borrowing entities</strong>, the <strong>loans</strong> made to them (terms, funding line, and origin dates), and the <strong>cash ledger</strong>—interest received, principal funded and repaid, and line-of-credit interest paid to your banks (<strong>JPM</strong> and <strong>NTRS</strong>). Use <strong>Checks</strong> for scheduled monthly postings, <strong>Bank</strong> for LOC interest from statements, and <strong>Report</strong> for period rollups.</p>
<p class="text-slate-600">Below is a quick bank-level snapshot: <strong>open</strong> loans by funding source (sorted by origin date), then the last three calendar months of cash activity attributed to each bank via <strong>Deposit to</strong> on cash events (current month is through today). Balances match the Loans list (principal in + principal out).</p>
</div>
</section>

<div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:gap-10">
<?php
[$jpmBankLabel, $ntrsBankLabel] = lending_funding_source_values();
foreach (
    [
        [$jpmBankLabel, $jpmLoans, $jpmLoansTotalBalanceDisp, $jpmMonths],
        [$ntrsBankLabel, $ntrsLoans, $ntrsLoansTotalBalanceDisp, $ntrsMonths],
    ] as [$bankLabel, $loans, $loansTotalBalanceDisp, $recentMonths]
) {
    require __DIR__ . '/partials/dashboard_bank_column.php';
}
?>
</div>

<section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
<h2 class="text-sm font-semibold text-slate-900">Quick links</h2>
<p class="mt-1 text-xs text-slate-500">Jump to a section.</p>
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/checks"><span class="block font-medium text-slate-900">Checks</span><span class="mt-0.5 block text-xs text-slate-500">Monthly interest &amp; principal</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/bank"><span class="block font-medium text-slate-900">Bank</span><span class="mt-0.5 block text-xs text-slate-500">LOC interest &amp; principal</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/cash-events"><span class="block font-medium text-slate-900">Cash events</span><span class="mt-0.5 block text-xs text-slate-500">Ledger of cash movements</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/loans"><span class="block font-medium text-slate-900">Loans</span><span class="mt-0.5 block text-xs text-slate-500">Loan records and terms</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/entities"><span class="block font-medium text-slate-900">Entities</span><span class="mt-0.5 block text-xs text-slate-500">Borrowing entities</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/borrowers"><span class="block font-medium text-slate-900">Borrowers</span><span class="mt-0.5 block text-xs text-slate-500">People you lend to</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/report"><span class="block font-medium text-slate-900">Report</span><span class="mt-0.5 block text-xs text-slate-500">Date-range totals</span></a>
<a class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm shadow-sm transition hover:border-slate-300 hover:bg-white" href="/payoff"><span class="block font-medium text-slate-900">Payoff</span><span class="mt-0.5 block text-xs text-slate-500">Loan payoff statements</span></a>
</div>
</section>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
