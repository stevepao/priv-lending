<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title><?php echo e($title); ?></title></head><body>
<form method="post" action="/login"><?php echo csrf_field(); ?><button type="submit">Sign in</button></form>
</body></html>
