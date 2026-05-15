<?php
declare(strict_types=1);

$rawPath = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($rawPath) ? $rawPath : '/', PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/') ?: '/';
}

$active = static function (string $path, string $prefix): bool {
    if ($prefix === '/') {
        return $path === '/';
    }

    return $path === $prefix || str_starts_with($path, $prefix . '/');
};

$linkClass = static function (bool $on): string {
    return $on
        ? 'rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white'
        : 'rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-200 hover:text-slate-900';
};
?>
<header class="mb-8 border-b border-slate-200 pb-4">
<div class="mx-auto flex max-w-6xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
<div class="flex shrink-0 items-center gap-3">
<a href="/" class="text-lg font-semibold tracking-tight text-slate-900 hover:text-slate-700">Private Lending</a>
</div>
<nav class="flex flex-wrap items-center gap-1" aria-label="Main navigation">
<a class="<?php echo e($linkClass($active($path, '/'))); ?>" href="/">Dashboard</a>
<a class="<?php echo e($linkClass($active($path, '/checks'))); ?>" href="/checks">Checks</a>
<a class="<?php echo e($linkClass($active($path, '/bank'))); ?>" href="/bank">Bank</a>
<a class="<?php echo e($linkClass($active($path, '/cash-events'))); ?>" href="/cash-events">Cash Events</a>
<a class="<?php echo e($linkClass($active($path, '/loans'))); ?>" href="/loans">Loans</a>
<a class="<?php echo e($linkClass($active($path, '/entities'))); ?>" href="/entities">Entities</a>
<a class="<?php echo e($linkClass($active($path, '/borrowers'))); ?>" href="/borrowers">Borrowers</a>
<a class="<?php echo e($linkClass($active($path, '/report'))); ?>" href="/report">Report</a>
</nav>
<form class="shrink-0" method="post" action="/logout"><?php echo csrf_field(); ?>
<button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50" type="submit">Sign out</button>
</form>
</div>
</header>
