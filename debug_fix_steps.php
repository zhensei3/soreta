<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

$stmt = $pdo->query('SELECT id, title, parent_id, fix_steps FROM troubleshooting_guide WHERE parent_id IS NOT NULL ORDER BY id');
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo 'Leaf node troubleshooting entries:\n';
foreach ($entries as $entry) {
    echo 'ID: ' . $entry['id'] . ' - Title: ' . $entry['title'] . ' - Parent: ' . $entry['parent_id'] . '\n';
    echo 'Fix Steps: "' . $entry['fix_steps'] . '"\n';
    echo 'Trimmed length: ' . strlen(trim($entry['fix_steps'])) . '\n';
    echo 'Has children: ';

    // Check if this entry has children
    $childStmt = $pdo->prepare('SELECT COUNT(*) FROM troubleshooting_guide WHERE parent_id = ? AND is_active = 1');
    $childStmt->execute([$entry['id']]);
    $childCount = $childStmt->fetchColumn();

    echo ($childCount > 0 ? 'YES' : 'NO') . '\n\n';
}
?>
