<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title><?php echo e($title); ?></title></head><body>
<p><?php echo e($heading); ?></p>
<p><a href="/borrowers">Borrowers</a> · <a href="/entities">Entities</a> · <a href="/loans">Loans</a> · <a href="/checks">Checks</a> · <a href="/cash-events">Cash events</a> · <a href="/bank">Bank</a> · <a href="/report">Report</a></p>
<form method="post" action="/logout"><?php echo csrf_field(); ?><button type="submit">Sign out</button></form>
</body></html>
