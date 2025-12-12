<?php
require_once 'includes/config.php';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $sql = "
        CREATE TABLE IF NOT EXISTS troubleshooting_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            troubleshooting_guide_id INT NOT NULL,
            rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
            comment TEXT,
            anonymous TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_guide (user_id, troubleshooting_guide_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (troubleshooting_guide_id) REFERENCES troubleshooting_guide(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $pdo->exec($sql);

    echo "<h2>Success!</h2>";
    echo "<p>The troubleshooting_feedback table has been created successfully.</p>";
    echo "<p>You can now <a href='customer/troubleshooting.php'>return to the troubleshooting page</a> and try updating your feedback.</p>";

} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<p>Failed to create table: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database configuration and try again.</p>";
}
?>
