<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

try {
    $pdo->exec('ALTER TABLE notifications MODIFY COLUMN related_type ENUM("appointment", "feedback", "system") DEFAULT "system"');
    echo 'Database updated successfully';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
