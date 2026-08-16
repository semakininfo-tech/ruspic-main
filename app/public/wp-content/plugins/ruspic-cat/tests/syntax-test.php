<?php
// Run with: php tests/syntax-test.php
$root = dirname(__DIR__);
$files = array(
    $root . '/ruspic-cat.php',
    $root . '/includes/class-db.php',
    $root . '/includes/class-parser-import.php',
    $root . '/includes/class-admin.php',
);
foreach ($files as $file) {
    exec('php -l ' . escapeshellarg($file), $out, $code);
    echo basename($file) . ': ' . ($code === 0 ? 'OK' : 'FAIL') . PHP_EOL;
    if ($code !== 0) exit($code);
}
