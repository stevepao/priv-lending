<?php

echo "LOGIN FILE LOADED<br>\n";

require __DIR__ . '/../lib/session.php';
echo "SESSION OK<br>\n";

require __DIR__ . '/../lib/csrf.php';
echo "CSRF OK<br>\n";
