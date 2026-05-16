<?php require __DIR__ . '/partials/layout_head_login.php'; ?>
<?php
/** @var bool $otpStep */
/** @var string $maskedEmail */
/** @var string|null $flashType */
/** @var string|null $flashMessage */
?>
<div class="mx-auto max-w-sm space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
<h1 class="text-center text-xl font-semibold text-slate-900"><?php echo e($title); ?></h1>
<?php if ($flashMessage !== null && $flashMessage !== '' && $flashType !== null): ?>
<?php
$flashClass = match ($flashType) {
    'error' => 'border-red-200 bg-red-50 text-red-900',
    'success' => 'border-green-200 bg-green-50 text-green-900',
    default => 'border-slate-200 bg-slate-50 text-slate-800',
};
?>
<p class="rounded border px-3 py-2 text-sm <?php echo e($flashClass); ?>"><?php echo e($flashMessage); ?></p>
<?php endif; ?>
<?php if (!$otpStep): ?>
<form class="space-y-4" method="post" action="/login/request-otp"><?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="email">Email</label>
<input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="email" name="email" type="email" autocomplete="email" required maxlength="255" placeholder="you@example.com"></div>
<button class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800" type="submit">Email me a code</button>
</form>
<?php else: ?>
<p class="text-sm text-slate-600">Enter the 6-digit code we sent to <strong><?php echo e($maskedEmail); ?></strong>.</p>
<form class="space-y-4" method="post" action="/login/verify"><?php echo csrf_field(); ?>
<div><label class="mb-1 block text-sm font-medium text-slate-700" for="otp_code">Sign-in code</label>
<input class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono tracking-widest" id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="one-time-code" required placeholder="000000"></div>
<button class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800" type="submit">Sign in</button>
</form>
<p class="text-center"><a class="text-sm text-slate-600 underline hover:text-slate-900" href="/login/cancel">Use a different email</a></p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/partials/layout_foot.php'; ?>
