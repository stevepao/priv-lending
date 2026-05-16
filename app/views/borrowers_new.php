<?php require __DIR__ . '/partials/layout_head.php'; ?>
<div class="mx-auto max-w-lg space-y-4">
<h1 class="text-2xl font-semibold"><?php echo e($title); ?></h1>
<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>
<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/new">
<?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="address">Address</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="address" name="address" type="text" maxlength="255" autocomplete="street-address"></div>
<div class="grid gap-4 sm:grid-cols-2">
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="city">City</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="city" name="city" type="text" maxlength="100" autocomplete="address-level2"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="state">State</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="state" name="state" type="text" maxlength="100" autocomplete="address-level1"></div>
</div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="zip">ZIP</label>
<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="zip" name="zip" type="text" maxlength="20" autocomplete="postal-code"></div>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>
<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4"></textarea></div>
<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>
<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>
</form></div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
