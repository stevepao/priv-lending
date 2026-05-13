<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<title><?php echo e($title); ?></title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">
<div class="mx-auto max-w-xl space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>
<?php if ($showInvalid): ?>
<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The loan was not saved. For interest-only or amortizing: principal must be greater than zero. Annual rate must be greater than zero unless you use <strong>fixed</strong> with <strong>monthly interest</strong> filled in (then annual rate may be blank or zero). Use a dot or comma as the decimal separator (e.g. 100000.00 or 100000,00), optional US thousands, or a trailing % on the rate. Prepaid: amount and date required. Optional monthly amounts must be non-negative with at most two decimal places.</p>
<?php endif; ?>
<?php if ($entitiesEmpty): ?>
<p class="text-sm text-slate-600">No entities yet. <a class="underline" href="/entities/new">Create an entity</a> first.</p>
<?php else: ?>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/loans/new">
<?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="entity_id">Entity</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="entity_id" name="entity_id" required>
<?php foreach ($entities as $ent): ?>
<?php $eid = (string) ($ent['id'] ?? ''); $ename = (string) ($ent['name'] ?? ''); ?>
<option value="<?php echo e($eid); ?>"><?php echo e($ename); ?></option>
<?php endforeach; ?>
</select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="funding_source">Funding source</label>
<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="funding_source" name="funding_source" required>
<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="origin_date">Origin date</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="origin_date" name="origin_date" type="date" required></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="maturity_date">Maturity date (optional)</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="maturity_date" name="maturity_date" type="date"></div>
<fieldset class="space-y-2"><legend class="mb-1 text-sm font-medium text-slate-700">Payment type</legend>
<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="interest_only" required> Interest only</label>
<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="amortizing"> Amortizing</label>
<label class="block text-sm"><input class="mr-1" type="radio" name="payment_type" value="prepaid"> Prepaid</label></fieldset>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="annual_interest_rate">Annual interest rate (%)</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="annual_interest_rate" name="annual_interest_rate" type="text" inputmode="decimal" placeholder="e.g. 12.500"></div>
<fieldset class="space-y-3 rounded border border-slate-200 p-3"><legend class="text-sm font-medium text-slate-700">Checks &amp; amortization</legend>
<p class="text-xs text-slate-500">These fields apply on the Checks page after the prepaid-through month (and for interest-only / amortizing loans). <strong>Declining balance</strong> adds monthly principal below to expected payment.</p>
<div><span class="mb-1 block text-sm font-medium text-slate-700">Interest calculation method</span>
<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="fixed" required checked> Fixed</label>
<label class="block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="declining_balance"> Declining balance</label></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (optional)</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="Leave blank to derive from principal and rate on Checks"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_payment_monthly">Monthly principal payment (paydown)</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_payment_monthly" name="principal_payment_monthly" type="text" inputmode="decimal" placeholder="0.00 — typical for amortizing"></div>
</fieldset>
<p class="text-xs text-slate-500">Interest only and amortizing: principal required; annual rate required unless <strong>fixed</strong> with <strong>monthly interest</strong> set (then Checks uses that amount; leave monthly interest blank to derive from principal and rate on Checks). Prepaid: prepaid amount and date required; principal may be zero during prepaid, but annual rate, monthly interest, method, and paydown are saved for Checks after prepaid expires. Optional amounts: non-negative, up to two decimal places.</p>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date"></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>
</form>
<?php endif; ?>
</div></body></html>
