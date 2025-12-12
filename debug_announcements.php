<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

echo "=== Announcements Debug ===\n\n";

// Check if announcements table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'announcements'");
    $tableExists = $stmt->fetchColumn();
    echo "Announcements table exists: " . ($tableExists ? "YES" : "NO") . "\n";
} catch (Exception $e) {
    echo "Error checking table: " . $e->getMessage() . "\n";
}

// Check announcements in table
if ($tableExists) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM announcements");
        $count = $stmt->fetchColumn();
        echo "Announcements in table: $count\n";

        if ($count > 0) {
            $stmt = $pdo->query("SELECT id, title, date, created_at FROM announcements ORDER BY created_at DESC LIMIT 5");
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "Recent announcements:\n";
            foreach ($announcements as $ann) {
                echo "  - ID: {$ann['id']}, Title: {$ann['title']}, Date: {$ann['date']}, Created: {$ann['created_at']}\n";
            }
        }
    } catch (Exception $e) {
        echo "Error querying table: " . $e->getMessage() . "\n";
    }
}

// Check announcements in settings
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute(['announcements']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $annFromSettings = json_decode($result['setting_value'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($annFromSettings)) {
            echo "Announcements in settings: " . count($annFromSettings) . "\n";
            if (!empty($annFromSettings)) {
                echo "Settings announcements:\n";
                foreach ($annFromSettings as $index => $ann) {
                    echo "  - [$index] Title: {$ann['title']}, Date: {$ann['date']}\n";
                }
            }
        } else {
            echo "Invalid JSON in settings\n";
        }
    } else {
        echo "No announcements in settings\n";
    }
} catch (Exception $e) {
    echo "Error querying settings: " . $e->getMessage() . "\n";
}

echo "\n=== End Debug ===\n";
?>
