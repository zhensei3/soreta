<?php
session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$guideFaqId = (int)($input['guide_faq_id'] ?? 0);
$isHelpful = (int)($input['is_helpful'] ?? 0);

if ($guideFaqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid FAQ ID']);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

try {
    $userId = $_SESSION['user_id'] ?? null;
    $sessionId = session_id();
    
    // Check if user already submitted feedback for this guide FAQ
    if ($userId) {
        $stmt = $pdo->prepare("SELECT id FROM troubleshooting_guide_faq_feedback WHERE guide_faq_id = ? AND user_id = ?");
        $stmt->execute([$guideFaqId, $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM troubleshooting_guide_faq_feedback WHERE guide_faq_id = ? AND session_id = ?");
        $stmt->execute([$guideFaqId, $sessionId]);
    }
    
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'You already submitted feedback for this FAQ']);
        exit;
    }
    
    // Insert feedback
    if ($userId) {
        $stmt = $pdo->prepare("INSERT INTO troubleshooting_guide_faq_feedback (guide_faq_id, user_id, is_helpful) VALUES (?, ?, ?)");
        $stmt->execute([$guideFaqId, $userId, $isHelpful]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO troubleshooting_guide_faq_feedback (guide_faq_id, session_id, is_helpful) VALUES (?, ?, ?)");
        $stmt->execute([$guideFaqId, $sessionId, $isHelpful]);
    }
    
    // Update FAQ counters
    if ($isHelpful) {
        $stmt = $pdo->prepare("UPDATE troubleshooting_guide_faqs SET helpful_count = helpful_count + 1 WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE troubleshooting_guide_faqs SET not_helpful_count = not_helpful_count + 1 WHERE id = ?");
    }
    $stmt->execute([$guideFaqId]);
    
    echo json_encode(['success' => true, 'message' => 'Thank you for your feedback!']);
    
} catch (Exception $e) {
    error_log('Guide FAQ feedback error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error submitting feedback']);
}