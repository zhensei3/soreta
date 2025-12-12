<?php
require_once 'includes/config.php';
$db = new Database();
$pdo = $db->getConnection();
$stmt = $pdo->query('SELECT setting_key, setting_value FROM settings WHERE setting_key IN ("site_logo", "hero_image")');
while ($row = $stmt->fetch()) {
    echo $row['setting_key'] . ': ' . substr($row['setting_value'], 0, 50) . '...' . PHP_EOL;
}
