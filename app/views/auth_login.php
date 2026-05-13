<?php require __DIR__ . '/partials/layout_head_login.php'; ?>
<div class="mx-auto max-w-sm space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
<h1 class="text-center text-xl font-semibold text-slate-900"><?php echo e($title); ?></h1>
<form class="space-y-4" method="post" action="/login"><?php echo csrf_field(); ?>
<button class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800" type="submit">Sign in</button>
</form>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
