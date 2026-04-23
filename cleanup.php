<?php
require __DIR__ . '/config.php';

$deleted = cleanup_old_locations($pdo);

if (PHP_SAPI === 'cli') {
    echo 'Deleted ' . $deleted . " old location record(s).\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo 'Deleted ' . $deleted . ' old location record(s).';
?>