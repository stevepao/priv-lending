<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-3xl space-y-6">
<div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
<h1 class="text-2xl font-semibold text-slate-900"><?php echo e($heading); ?></h1>
<p class="mt-2 text-sm text-slate-600">Use the navigation above to open a section. Below are quick links to the same places.</p>
</div>
<div class="grid gap-3 sm:grid-cols-2">
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/borrowers"><span class="block text-sm font-medium text-slate-900">Borrowers</span><span class="mt-1 block text-xs text-slate-500">People you lend to</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/entities"><span class="block text-sm font-medium text-slate-900">Entities</span><span class="mt-1 block text-xs text-slate-500">Borrowing entities</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/loans"><span class="block text-sm font-medium text-slate-900">Loans</span><span class="mt-1 block text-xs text-slate-500">Loan records and terms</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/checks"><span class="block text-sm font-medium text-slate-900">Checks</span><span class="mt-1 block text-xs text-slate-500">Monthly interest &amp; principal posting</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/cash-events"><span class="block text-sm font-medium text-slate-900">Cash events</span><span class="mt-1 block text-xs text-slate-500">Ledger of cash movements</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/bank"><span class="block text-sm font-medium text-slate-900">Bank</span><span class="mt-1 block text-xs text-slate-500">Statement LOC interest &amp; principal</span></a>
<a class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:bg-slate-50" href="/report"><span class="block text-sm font-medium text-slate-900">Report</span><span class="mt-1 block text-xs text-slate-500">Date-range totals</span></a>
</div>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
