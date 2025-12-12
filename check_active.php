<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

$stmt = $pdo->query('SELECT id, title, parent_id, is_active, fix_steps FROM troubleshooting_guide WHERE parent_id IS NOT NULL ORDER BY id');
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo 'Leaf node troubleshooting entries:\n';
foreach ($entries as $entry) {
    echo 'ID: ' . $entry['id'] . ' - Title: ' . $entry['title'] . ' - Parent: ' . $entry['parent_id'] . ' - Active: ' . ($entry['is_active'] ? 'YES' : 'NO') . '\n';
    echo 'Fix Steps length: ' . strlen($entry['fix_steps']) . '\n\n';
}
?>
