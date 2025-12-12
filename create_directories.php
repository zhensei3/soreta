<?php
/**
 * Create required directories for Soreta Electronics
 */

$directories = [
    'assets/css',
    'assets/js',
    'assets/images',
    'uploads',
    'feedback',
    'notifications',
    'admin/layout',
    'customer',
    'auth'
];

foreach ($directories as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "Created directory: $path<br>";
    } else {
        echo "Directory already exists: $path<br>";
    }
}

echo "All required directories created successfully!";
?>