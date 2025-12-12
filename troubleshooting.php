<?php
session_start();
require_once 'includes/config.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;

if ($isLoggedIn && $userRole === 'admin') { redirect('admin/dashboard.php'); }

$db = new Database();
$pdo = $db->getConnection();

// Get company settings
$settings = [];
try {
    $colsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $colKey = in_array('setting_key', $cols) ? 'setting_key' : 'setting_key';
    $colValue = in_array('setting_value', $cols) ? 'setting_value' : 'setting_value';
    $stmt = $pdo->query("SELECT `{$colKey}` AS setting_key, `{$colValue}` AS setting_value FROM settings");
    while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }
} catch (Exception $e) {}

function flatten_to_string($val) {
    if (is_array($val)) return implode(' ', array_map('flatten_to_string', $val));
    elseif (is_string($val)) { $d = json_decode($val, true); if (json_last_error() === JSON_ERROR_NONE && is_array($d)) return flatten_to_string($d); return $val; }
    return $val === null ? '' : (string)$val;
}
foreach ($settings as $key => $val) { $settings[$key] = flatten_to_string($val); }
$settings['favicon'] = is_array($settings['favicon'] ?? '') ? '' : ($settings['favicon'] ?? '');
$settings['site_logo'] = is_array($settings['site_logo'] ?? '') ? '' : ($settings['site_logo'] ?? '');

// Get troubleshooting categories
$stmt = $pdo->query("SELECT * FROM troubleshooting_guide WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order");
$categories = $stmt->fetchAll();

$guideId = $_GET['id'] ?? null;
$currentGuide = null; $children = []; $parentGuide = null; $guideFaqs = [];

if ($guideId) {
    $stmt = $pdo->prepare("SELECT * FROM troubleshooting_guide WHERE id = ? AND is_active = 1");
    $stmt->execute([$guideId]);
    $currentGuide = $stmt->fetch();
    if ($currentGuide) {
        $stmt = $pdo->prepare("SELECT * FROM troubleshooting_guide WHERE parent_id = ? AND is_active = 1 ORDER BY display_order");
        $stmt->execute([$guideId]);
        $children = $stmt->fetchAll();
        if ($currentGuide['parent_id']) {
            $stmt = $pdo->prepare("SELECT * FROM troubleshooting_guide WHERE id = ?");
            $stmt->execute([$currentGuide['parent_id']]);
            $parentGuide = $stmt->fetch();
        }
        
        // Get FAQs for this guide
        $stmt = $pdo->prepare("
            SELECT * FROM troubleshooting_guide_faqs 
            WHERE guide_id = ? AND is_active = 1 
            ORDER BY display_order ASC, created_at DESC
        ");
        $stmt->execute([$guideId]);
        $guideFaqs = $stmt->fetchAll();
    }
}

$existingFeedback = null; $hasFeedback = false;
if (isset($_SESSION['user_id']) && $guideId) {
    $stmt = $pdo->prepare("SELECT id, rating, comment, anonymous FROM troubleshooting_feedback WHERE user_id = ? AND troubleshooting_guide_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $guideId]);
    $existingFeedback = $stmt->fetch();
    $hasFeedback = $existingFeedback !== false;
}

function getCategoryIcon($title) {
    $icons = ['Laptop'=>'laptop','Phone'=>'phone','Desktop'=>'pc-display','Tablet'=>'tablet','Audio'=>'speaker','Video'=>'tv','Network'=>'wifi','Software'=>'code-slash','Hardware'=>'motherboard','Refrigerator'=>'snow','Air Conditioner'=>'fan','Washing Machine'=>'water','TV'=>'tv','Printer'=>'printer'];
    foreach ($icons as $k => $v) { if (stripos($title, $k) !== false) return $v; }
    return 'tools';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Troubleshooting Guide - <?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php if (!empty($settings['favicon'])): ?><link rel="icon" href="<?= htmlspecialchars($settings['favicon'][0] === '/' ? $settings['favicon'] : ROOT_PATH . $settings['favicon']) ?>" /><?php endif; ?>
    <style>
        :root { --primary: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>; --secondary: <?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?>; --primary-light: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>20; font-family: <?= htmlspecialchars($settings['font_family'] ?? 'Inter') ?>, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); min-height: 100vh; color: #f1f5f9; }
    </style>
    <?php include 'includes/mega-navbar-css.php'; ?>
    <style>
        /* Override navbar for dark page */
        .mega-navbar { background: rgba(255,255,255,0.08) !important; border-color: rgba(255,255,255,0.1) !important; }
        .mega-navbar.scrolled { background: rgba(255,255,255,0.12) !important; }
        .mega-navbar-brand-text, .mega-nav-link { color: #f1f5f9 !important; }
        .mega-nav-link:hover, .mega-nav-link.active { color: var(--primary) !important; background: rgba(255,255,255,0.1) !important; }
        .mega-mobile-toggle { color: #f1f5f9 !important; }
        .mega-nav-btn-ghost { color: #f1f5f9 !important; }
        .mega-user-avatar { border-color: rgba(255,255,255,0.3) !important; }
        
        .category-filter-wrapper { margin-bottom: 2rem; }
        .category-filter-toggle { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; cursor: pointer; transition: all 0.3s ease; }
        .category-filter-toggle:hover { background: rgba(255,255,255,0.12); }
        .category-filter-toggle.active { border-radius: 12px 12px 0 0; border-bottom-color: transparent; }
        .filter-toggle-left { display: flex; align-items: center; gap: 0.75rem; }
        .filter-toggle-left i { font-size: 1.25rem; color: var(--primary); }
        .filter-toggle-left span { font-weight: 600; color: #f1f5f9; }
        .selected-category-badge { background: var(--primary); color: white; padding: 0.375rem 0.875rem; border-radius: 20px; font-size: 0.8125rem; font-weight: 600; }
        /* Replace the existing filter-chevron CSS with this */

        .filter-chevron {
            color: #94a3b8;
            font-size: 1.25rem;
            transition: transform 0.3s ease;
            transform: rotate(0deg); /* Add explicit initial state */
            display: inline-block; /* Ensure transform works */
        }

        .filter-chevron.rotated {
            transform: rotate(180deg);
        }

        /* Make sure the toggle right section has proper display */
        .filter-toggle-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .category-filter-dropdown { display: none; background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-top: none; border-radius: 0 0 12px 12px; padding: 1rem; }
        .category-filter-dropdown.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .category-pills { display: flex; gap: 0.625rem; flex-wrap: wrap; }
        .category-pill { padding: 0.625rem 1.25rem; background: rgba(255,255,255,0.1); color: #f1f5f9; border-radius: 50px; font-weight: 600; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.5rem; border: 2px solid transparent; cursor: pointer; }
        .category-pill:hover { background: rgba(255,255,255,0.15); color: #ffffff; transform: translateY(-2px); }
        .category-pill.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        .category-card { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; overflow: hidden; transition: all 0.3s ease; height: 100%; }
        .category-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(0,0,0,0.2); border-color: rgba(255,255,255,0.2); }
        .category-thumbnail { width: 100%; height: 220px; overflow: hidden; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; }
        .category-thumbnail img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .category-card:hover .category-thumbnail img { transform: scale(1.1); }
        .category-thumbnail-icon { width: 90px; height: 90px; background: var(--primary); color: white; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 3rem; }
        .category-card .card-body { padding: 1.75rem; }
        .category-card .card-title { font-size: 1.375rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.75rem; }
        .category-card .card-text { color: #cbd5e0; margin-bottom: 1.25rem; line-height: 1.6; }
        
        .guide-detail-card { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 2.5rem; margin-bottom: 2rem; }
        .guide-detail-card .lead { color: #cbd5e0 !important; }
        .breadcrumb { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 2rem; }
        .breadcrumb-item a { color: var(--primary); text-decoration: none; font-weight: 500; }
        .breadcrumb-item.active { color: #cbd5e0; }
        .breadcrumb-item + .breadcrumb-item::before { color: #64748b; }
        
        .decision-card { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 1.5rem; transition: all 0.3s ease; text-decoration: none; display: block; height: 100%; }
        .decision-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.2); background: rgba(255,255,255,0.12); }
        .decision-thumbnail { width: 100%; height: 140px; overflow: hidden; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; border-radius: 10px; margin-bottom: 1rem; }
        .decision-thumbnail img { width: 100%; height: 100%; object-fit: cover; }
        .decision-thumbnail-icon { width: 60px; height: 60px; background: var(--primary); color: white; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; }
        .decision-card h6 { font-size: 1.125rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.75rem; }
        .decision-card .text-muted { color: #94a3b8 !important; margin-bottom: 1rem; }
        .decision-card .solution-link { color: var(--primary); font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        
        .step-item { display: flex; gap: 1.25rem; margin-bottom: 2rem; }
        .step-number { width: 44px; height: 44px; background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 80%, #000)); color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
        .step-content { flex: 1; padding-top: 8px; color: #f1f5f9; font-size: 1.0625rem; line-height: 1.7; }
        
        .alert-info { background: rgba(37,99,235,0.15); backdrop-filter: blur(10px); border: 1px solid rgba(37,99,235,0.3); border-radius: 12px; padding: 1.5rem; color: #f1f5f9; }
        .alert-info h6 { color: #60a5fa; font-weight: 700; margin-bottom: 0.75rem; }
        
        .sidebar-card { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 1.75rem; margin-bottom: 1.5rem; }
        .sidebar-card h6 { font-size: 1.125rem; font-weight: 700; color: #f1f5f9; margin-bottom: 1.25rem; }
        .sidebar-card p, .sidebar-card ul li { color: #cbd5e0; }
        
        .section-title { font-size: 1.75rem; font-weight: 700; color: #f1f5f9; margin-bottom: 1.5rem; }
        .rating-star { background: transparent; border: none; cursor: pointer; font-size: 2rem; color: #475569; padding: 0.25rem; transition: all 0.2s; }
        .rating-star:hover, .rating-star.active { color: #fbbf24; transform: scale(1.15); }
        
        .form-control, .form-select { border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; padding: 0.75rem 1rem; background: rgba(255,255,255,0.1); color: #f1f5f9; }
        .form-control::placeholder { color: #94a3b8; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(37,99,235,0.25); background: rgba(255,255,255,0.15); color: #f1f5f9; }
        .form-label { font-weight: 600; color: #f1f5f9; margin-bottom: 0.5rem; }
        .form-check-label { color: #cbd5e0; }
        .form-check-input { background-color: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.3); }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        
        .btn-primary { background: var(--primary); border: none; padding: 0.875rem 1.75rem; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .btn-primary:hover { background: color-mix(in srgb, var(--primary) 85%, #000); transform: translateY(-2px); }
        .btn-outline-primary { border: 2px solid var(--primary); color: var(--primary); background: transparent; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; }
        .btn-outline-primary:hover { background: var(--primary); color: white; }
        
        hr { border-color: rgba(255,255,255,0.1); }
        .feedback-login-prompt { text-align: center; padding: 2rem; }
        .feedback-login-prompt i { font-size: 3rem; color: #475569; margin-bottom: 1rem; display: block; }
        .feedback-login-prompt h5 { font-weight: 700; color: #f1f5f9; margin-bottom: 1rem; }
        .feedback-login-prompt p { color: #94a3b8; margin-bottom: 1.5rem; }
        .feedback-login-prompt a { color: var(--primary); font-weight: 600; }
        .alert-success { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #86efac; }
        .alert-danger { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        .alert .btn-close { filter: invert(1); }
        
        .references-info { background: rgba(37,99,235,0.1); border-left: 3px solid var(--primary); padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; color: #cbd5e0; font-size: 0.9375rem; }
        .references-info i { color: var(--primary); margin-right: 0.5rem; }
        
        .guide-faqs-section {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 2rem;
            margin-top: 2rem;
        }
        
        .guide-faqs-section h5 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .guide-faqs-section h5 i {
            color: var(--primary);
        }
        
        .faq-accordion-item {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px !important;
            margin-bottom: 0.75rem;
            overflow: hidden;
        }
        
        .faq-accordion-button {
            background: transparent;
            color: #f1f5f9;
            font-weight: 600;
            font-size: 1.0625rem;
            padding: 1.25rem 1.5rem;
            border: none;
        }
        
        .faq-accordion-button:not(.collapsed) {
            background: rgba(37,99,235,0.15);
            color: #60a5fa;
            box-shadow: none;
        }
        
        .faq-accordion-button:focus { box-shadow: none; }
        
        .faq-accordion-button::after {
            filter: brightness(0) invert(1);
            background-size: 1.25rem;
        }
        
        .faq-accordion-button:not(.collapsed)::after {
            filter: brightness(0) saturate(100%) invert(62%) sepia(76%) saturate(2878%) hue-rotate(201deg) brightness(101%) contrast(98%);
        }
        
        .faq-accordion-body {
            background: rgba(255,255,255,0.03);
            color: #cbd5e0;
            padding: 1.5rem;
            line-height: 1.8;
            font-size: 1rem;
        }
        
        .faq-accordion-collapse { border-top: 1px solid rgba(255,255,255,0.1); }
        
        .guide-faq-feedback {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .guide-faq-feedback span { color: #94a3b8; font-size: 0.9375rem; }
        
        .guide-faq-feedback-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9375rem;
        }
        
        .guide-faq-feedback-btn:hover {
            background: rgba(34,197,94,0.2);
            border-color: #22c55e;
            color: #86efac;
        }
        
        .guide-faq-feedback-btn.active {
            background: #22c55e;
            border-color: #22c55e;
            color: white;
        }
        
        .references-section {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 2rem;
            margin-top: 2rem;
        }

        .references-section h5 {
            font-size: 1.375rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .references-section h5 i { color: var(--primary); }

        .reference-item {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .reference-item:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.2);
            transform: translateX(5px);
        }

        .reference-item i {
            color: var(--primary);
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .reference-item a {
            color: #60a5fa;
            text-decoration: none;
            word-break: break-word;
            font-size: 0.9375rem;
            line-height: 1.6;
            flex: 1;
        }

        .reference-item a:hover {
            color: #93c5fd;
            text-decoration: underline;
        }

        .reference-number {
            background: var(--primary);
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8125rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .category-item {
            transition: all 0.3s ease;
        }

        .category-item.hidden {
            display: none;
        }

        .no-results-message {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
        }

        .no-results-message i {
            font-size: 4rem;
            color: #475569;
            margin-bottom: 1rem;
            display: block;
        }

        .no-results-message h4 {
            color: #cbd5e0;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .troubleshooting-container { padding: 1rem 0.75rem; }
            .troubleshooting-hero { padding: 2rem 1.5rem; }
            .troubleshooting-hero h1 { font-size: 1.75rem; }
        }
        <?= $settings['custom_css'] ?? '' ?>
    </style>
</head>
<body>
    <?php include 'includes/mega-navbar.php'; ?>

    <div class="troubleshooting-container">
        <?php if (!$currentGuide): ?>
            <div class="troubleshooting-hero fade-in">
                <h1>Troubleshooting Guide</h1>
                <p>Find step-by-step solutions for common electronic problems. Select a category below to get started.</p>
            </div>

<div class="category-filter-wrapper fade-in">
    <div class="category-filter-toggle" id="categoryFilterToggle">
        <div class="filter-toggle-left">
            <span>Filter by Category</span>
        </div>
        <div class="filter-toggle-right">
            <span class="selected-category-badge" id="selectedCategoryBadge">All Categories</span>
            <i class="bi bi-chevron-down filter-chevron"></i>
        </div>
    </div>
    <div class="category-filter-dropdown" id="categoryFilterDropdown">
        <div class="category-pills">
            <button type="button" class="category-pill active" data-category="all">
                All Categories
            </button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="category-pill" data-category="<?= $cat['id'] ?>">
                    <?= htmlspecialchars($cat['title']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4 fade-in" id="categoriesGrid">
    <?php foreach ($categories as $cat): ?>
    <div class="col-md-6 col-lg-4 category-item" data-category-id="<?= $cat['id'] ?>">
        <a href="troubleshooting.php?id=<?= $cat['id'] ?>" class="text-decoration-none">
            <div class="category-card">
                <div class="category-thumbnail">
                    <?php if (!empty($cat['image_path']) && file_exists($cat['image_path'])): ?>
                        <img src="<?= htmlspecialchars($cat['image_path']) ?>" alt="<?= htmlspecialchars($cat['title']) ?>">
                    <?php else: ?>
                        <div class="category-thumbnail-icon">
                            
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars($cat['title']) ?></h5>
                    <p class="card-text"><?= htmlspecialchars(substr($cat['description'], 0, 120)) ?><?= strlen($cat['description']) > 120 ? '...' : '' ?></p>
                    <span class="btn btn-primary w-100">
                        <i class="bi bi-arrow-right-circle me-2"></i>Explore Solutions
                    </span>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="no-results-message" id="noResultsMessage" style="display: none;">
    <i class="bi bi-search"></i>
    <h4>No categories found</h4>
    <p>Try adjusting your filter criteria.</p>
</div>
        <?php else: ?>
            <nav aria-label="breadcrumb" class="fade-in">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="troubleshooting.php">Troubleshooting</a></li>
                    <?php if ($parentGuide): ?><li class="breadcrumb-item"><a href="troubleshooting.php?id=<?= $parentGuide['id'] ?>"><?= htmlspecialchars($parentGuide['title']) ?></a></li><?php endif; ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($currentGuide['title']) ?></li>
                </ol>
            </nav>

            <div class="row">
<div class="col-lg-8">
    <div class="guide-detail-card fade-in">
        <h3 class="section-title"><?= htmlspecialchars($currentGuide['title']) ?></h3>
        <p class="lead"><?= nl2br(htmlspecialchars($currentGuide['description'])) ?></p>

        <?php if (!empty($children)): ?>
            <div class="mt-4">
                <h5 class="mb-4" style="font-size:1.375rem;font-weight:700;color:#f1f5f9;"><i class="bi bi-list-check me-2" style="color:var(--primary);"></i>What best describes your specific issue?</h5>
                <div class="row g-3">
                    <?php foreach ($children as $child): ?>
                    <div class="col-md-6">
                        <a href="troubleshooting.php?id=<?= $child['id'] ?>" class="decision-card">
                            <div class="decision-thumbnail">
                                <?php if (!empty($child['image_path']) && file_exists($child['image_path'])): ?>
                                    <img src="<?= htmlspecialchars($child['image_path']) ?>" alt="<?= htmlspecialchars($child['title']) ?>">
                                <?php else: ?>
                                    <div class="decision-thumbnail-icon"><i class="bi bi-<?= getCategoryIcon($child['title']) ?>"></i></div>
                                <?php endif; ?>
                            </div>
                            <h6><?= htmlspecialchars($child['title']) ?></h6>
                            <p class="text-muted small"><?= htmlspecialchars(substr($child['description'], 0, 100)) ?><?= strlen($child['description']) > 100 ? '...' : '' ?></p>
                            <span class="solution-link">View solution <i class="bi bi-arrow-right"></i></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif (!empty(trim($currentGuide['fix_steps']))): ?>
            <div class="mt-5">
                <h5 class="mb-4" style="font-size:1.5rem;font-weight:700;color:#f1f5f9;"><i class="bi bi-wrench-adjustable me-2" style="color:var(--primary);"></i>Fix Steps:</h5>
                <?php $steps = explode("\n", $currentGuide['fix_steps']); foreach ($steps as $i => $step): if (trim($step)): ?>
                    <div class="step-item"><div class="step-number"><?= $i + 1 ?></div><div class="step-content"><?= nl2br(htmlspecialchars(trim($step))) ?></div></div>
                <?php endif; endforeach; ?>
            </div>
            
            <?php if (!empty($currentGuide['preventive_tip'])): ?>
                <div class="mt-4">
                    <div class="alert alert-info">
                        <h6><i class="bi bi-lightbulb-fill"></i> Preventive Tip</h6>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($currentGuide['preventive_tip'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            // References Section - Only show when there are fix steps
            function parseReferences($referencesText) {
                if (empty(trim($referencesText))) {
                    return [];
                }

                $lines = explode("\n", $referencesText);
                $references = [];

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    // Check if line contains a URL
                    if (preg_match('/(https?:\/\/[^\s]+)/i', $line, $matches)) {
                        $url = $matches[1];
                        // Get description (text before URL, or use URL as description)
                        $description = trim(str_replace($url, '', $line));
                        if (empty($description)) {
                            $description = $url;
                        }

                        $references[] = [
                            'url' => $url,
                            'description' => $description
                        ];
                    }
                }

                return $references;
            }

            if (!empty($currentGuide['references'])):
                $references = parseReferences($currentGuide['references']);
                if (!empty($references)):
            ?>
                <div class="mt-4">
                    <div class="references-section">
                        <h5><i class="bi bi-link-45deg"></i>References & Further Reading</h5>

                        <div class="references-info">
                            <i class="bi bi-info-circle"></i>
                            These resources provide additional technical information and official documentation.
                        </div>

                        <div class="references-list">
                            <?php foreach ($references as $index => $ref): ?>
                                <div class="reference-item">
                                    <div class="reference-number"><?= $index + 1 ?></div>
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    <a href="<?= htmlspecialchars($ref['url']) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       title="<?= htmlspecialchars($ref['description']) ?>">
                                        <?= htmlspecialchars($ref['description']) ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php
                endif;
            endif;
            ?>

            <!-- FAQs Section for this Guide -->
            <?php if (!empty($guideFaqs)): ?>
                <div class="mt-4">
                    <div class="guide-faqs-section">
                        <h5><i class="bi bi-patch-question"></i>Related FAQs</h5>
                        
                        <div class="accordion" id="guideFaqAccordion">
                            <?php foreach ($guideFaqs as $index => $faq): ?>
                                <div class="accordion-item faq-accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button faq-accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" 
                                                type="button" 
                                                data-bs-toggle="collapse" 
                                                data-bs-target="#guideFaq<?= $faq['id'] ?>">
                                            <?= htmlspecialchars($faq['question']) ?>
                                        </button>
                                    </h2>
                                    <div id="guideFaq<?= $faq['id'] ?>" 
                                         class="accordion-collapse collapse faq-accordion-collapse <?= $index == 0 ? 'show' : '' ?>" 
                                         data-bs-parent="#guideFaqAccordion">
                                        <div class="accordion-body faq-accordion-body">
                                            <?= nl2br(htmlspecialchars($faq['answer'])) ?>

                                            <div class="guide-faq-feedback">
                                                <span>Was this helpful?</span>
                                                <button class="guide-faq-feedback-btn" onclick="submitGuideFaqFeedback(event, <?= $faq['id'] ?>, 1)">
                                                    <i class="bi bi-hand-thumbs-up me-1"></i>Yes
                                                </button>
                                                <button class="guide-faq-feedback-btn" onclick="submitGuideFaqFeedback(event, <?= $faq['id'] ?>, 0)">
                                                    <i class="bi bi-hand-thumbs-down me-1"></i>No
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($currentGuide && $isLoggedIn): ?>
        <hr class="my-5">
        <div>
            <h5 class="mb-4" style="font-size:1.375rem;font-weight:700;color:#f1f5f9;"><i class="bi bi-chat-heart me-2" style="color:var(--primary);"></i>Was this guide helpful?</h5>
            <form id="feedbackForm" class="mt-3">
                <input type="hidden" name="troubleshooting_guide_id" value="<?= $currentGuide['id'] ?>">
                <?php if ($hasFeedback): ?><input type="hidden" name="edit_id" value="<?= $existingFeedback['id'] ?>"><?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= CSRFProtection::generateToken() ?>">
                <div class="mb-4">
                    <label class="form-label">Your Rating</label>
                    <div>
                        <?php $cr = $hasFeedback ? $existingFeedback['rating'] : 0; for ($i = 1; $i <= 5; $i++): ?>
                            <span class="rating-star<?= $i <= $cr ? ' active' : '' ?>" data-rating="<?= $i ?>"><i class="bi bi-star<?= $i <= $cr ? '-fill' : '' ?>"></i></span>
                        <?php endfor; ?>
                        <input type="hidden" name="rating" id="rating" value="<?= $cr ?>" required>
                    </div>
                </div>
                <div class="mb-3"><label for="comment" class="form-label">Your Feedback (Optional)</label><textarea class="form-control" id="comment" name="comment" rows="4" placeholder="Share your experience..."><?= $hasFeedback ? htmlspecialchars($existingFeedback['comment']) : '' ?></textarea></div>
                <div class="form-check mb-4"><input class="form-check-input" type="checkbox" name="anonymous" id="anonymous" value="1"<?= $hasFeedback && $existingFeedback['anonymous'] ? ' checked' : '' ?>><label class="form-check-label" for="anonymous">Post anonymously</label></div>
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send me-2"></i><?= $hasFeedback ? 'Update Feedback' : 'Submit Feedback' ?></button>
            </form>
        </div>
        <?php elseif ($currentGuide && !$isLoggedIn): ?>
        <hr class="my-5">
        <div class="feedback-login-prompt"><i class="bi bi-heart"></i><h5>Was this guide helpful?</h5><p>Please <a href="auth/login.php">login</a> to leave feedback.</p><a href="auth/login.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-2"></i>Login to Leave Feedback</a></div>
        <?php endif; ?>
    </div>
</div>
                <div class="col-lg-4">
                    <div class="sidebar-card fade-in"><h6><i class="bi bi-headset me-2" style="color:var(--primary);"></i>Need More Help?</h6><p>If our guide doesn't solve your issue, book a professional service appointment.</p><a href="<?= $isLoggedIn ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="btn btn-primary w-100"><i class="bi bi-calendar-plus me-2"></i>Book Appointment</a></div>
                    <div class="sidebar-card fade-in"><h6>Browse Categories</h6><div class="d-flex flex-column gap-2"><?php foreach ($categories as $cat): ?><a href="troubleshooting.php?id=<?= $cat['id'] ?>" class="btn <?= $currentGuide && $currentGuide['id'] == $cat['id'] ? 'btn-primary' : 'btn-outline-primary' ?> text-start"><?= htmlspecialchars($cat['title']) ?></a><?php endforeach; ?></div></div>
                    <div class="sidebar-card fade-in"><h6><i class="bi bi-lightbulb me-2" style="color:var(--primary);"></i>Quick Tips</h6><ul style="font-size:0.9375rem;line-height:1.8;padding-left:1.25rem;"><li>Always unplug devices before troubleshooting</li><li>Take photos before disassembly</li><li>Keep screws organized</li><li>Consult manual for error codes</li><li>Back up data before repairs</li></ul></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <section class="py-5 bg-dark text-white mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
                    <?php if (!empty($settings['company_address'])): ?><p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?= nl2br(htmlspecialchars($settings['company_address'])) ?></p><?php endif; ?>
                    <?php if (!empty($settings['company_phone'])): ?><p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($settings['company_phone']) ?></p><?php endif; ?>
                    <?php if (!empty($settings['company_email'])): ?><p class="mb-0"><i class="bi bi-envelope-fill me-2"></i><a href="mailto:<?= htmlspecialchars($settings['company_email']) ?>" class="text-white"><?= htmlspecialchars($settings['company_email']) ?></a></p><?php endif; ?>
                </div>
                <div class="col-md-6 text-md-end"><p class="text-white"><?= htmlspecialchars($settings['footer_text'] ?? '&copy; ' . date('Y') . ' ' . ($settings['company_name'] ?? 'Soreta Electronics')) ?></p></div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'includes/mega-navbar-js.php'; ?>
    <script>
        const filterToggle = document.getElementById('categoryFilterToggle');
        const filterDropdown = document.getElementById('categoryFilterDropdown');
        const filterChevron = document.querySelector('.filter-chevron');
        if (filterToggle && filterDropdown && filterChevron) {
            console.log('Elements found:', filterToggle, filterDropdown, filterChevron);
            filterToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                console.log('Toggle clicked');
                this.classList.toggle('active');
                filterDropdown.classList.toggle('show');
                // Toggle rotation using data attribute and direct style
                const isRotated = filterChevron.dataset.rotated === 'true';
                filterChevron.dataset.rotated = !isRotated;
                filterChevron.style.transform = !isRotated ? 'rotate(180deg)' : 'rotate(0deg)';
                console.log('Chevron rotated:', !isRotated);
            });
            document.addEventListener('click', function(e) {
                if (!filterToggle.contains(e.target) && !filterDropdown.contains(e.target)) {
                    filterToggle.classList.remove('active');
                    filterDropdown.classList.remove('show');
                    filterChevron.dataset.rotated = 'false';
                    filterChevron.style.transform = 'rotate(0deg)';
                }
            });
        } else {
            console.log('Elements not found');
        }

        // Category filter functionality
        const categoryPills = document.querySelectorAll('.category-pill');
        const categoryItems = document.querySelectorAll('.category-item');
        const selectedCategoryBadge = document.getElementById('selectedCategoryBadge');
        const noResultsMessage = document.getElementById('noResultsMessage');
        const categoriesGrid = document.getElementById('categoriesGrid');

        categoryPills.forEach(pill => {
            pill.addEventListener('click', function() {
                const category = this.dataset.category;

                // Update active state
                categoryPills.forEach(p => p.classList.remove('active'));
                this.classList.add('active');

                // Update badge
                if (selectedCategoryBadge) {
                    selectedCategoryBadge.textContent = category === 'all' ? 'All Categories' : this.textContent.trim();
                }

                // Filter items
                let visibleCount = 0;
                categoryItems.forEach(item => {
                    if (category === 'all') {
                        item.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        const isHidden = item.dataset.categoryId !== category;
                        item.classList.toggle('hidden', isHidden);
                        if (!isHidden) visibleCount++;
                    }
                });

                // Show/hide no results message and grid
                if (visibleCount === 0) {
                    if (categoriesGrid) categoriesGrid.style.display = 'none';
                    if (noResultsMessage) noResultsMessage.style.display = 'block';
                } else {
                    if (categoriesGrid) categoriesGrid.style.display = '';
                    if (noResultsMessage) noResultsMessage.style.display = 'none';
                }

                // Close dropdown
                filterToggle.classList.remove('active');
                filterDropdown.classList.remove('show');
                filterChevron.dataset.rotated = 'false';
                filterChevron.style.transform = 'rotate(0deg)';
            });
        });
        
        const observer = new IntersectionObserver(entries => { 
            entries.forEach(e => { if(e.isIntersecting) e.target.classList.add('visible'); }); 
        }, {threshold:0.1,rootMargin:'0px 0px -50px 0px'});
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

        // Rating stars
        const ratingStars = document.querySelectorAll('.rating-star');
        const ratingInput = document.getElementById('rating');
        let currentRating = parseInt(ratingInput?.value) || 0;
        function updateStars(r) { ratingStars.forEach(s => { const sr = parseInt(s.dataset.rating); const i = s.querySelector('i'); if (sr <= r) { s.classList.add('active'); i.className = 'bi bi-star-fill'; } else { s.classList.remove('active'); i.className = 'bi bi-star'; } }); }
        updateStars(currentRating);
        ratingStars.forEach(s => {
            s.addEventListener('click', function() { currentRating = parseInt(this.dataset.rating); if (ratingInput) ratingInput.value = currentRating; updateStars(currentRating); });
            s.addEventListener('mouseenter', function() { updateStars(parseInt(this.dataset.rating)); });
            s.addEventListener('mouseleave', function() { updateStars(currentRating); });
        });

        // Guide feedback form
        const feedbackForm = document.getElementById('feedbackForm');
        if (feedbackForm) {
            feedbackForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]'); const orig = btn.innerHTML;
                btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
                try {
                    const fd = new FormData(this);
                    const res = await fetch('feedback/submit_feedback.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    const alert = document.createElement('div');
                    alert.className = data.success ? 'alert alert-success alert-dismissible fade show' : 'alert alert-danger alert-dismissible fade show';
                    alert.innerHTML = `<i class="bi bi-${data.success ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-2"></i>${data.success ? 'Thank you! Your feedback has been submitted.' : (data.message || 'An error occurred')}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
                    this.parentNode.insertBefore(alert, this);
                    alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } catch (err) { console.error(err); } finally { btn.disabled = false; btn.innerHTML = orig; }
            });
        }

        // Guide FAQ Feedback
        async function submitGuideFaqFeedback(event, faqId, isHelpful) {
            try {
                const response = await fetch('guide_faq_feedback.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guide_faq_id: faqId, is_helpful: isHelpful })
                });

                const result = await response.json();

                if (result.success) {
                    const accordion = document.getElementById('guideFaq' + faqId);
                    const buttons = accordion.querySelectorAll('.guide-faq-feedback-btn');

                    buttons.forEach(btn => btn.classList.remove('active'));
                    event.target.closest('.guide-faq-feedback-btn').classList.add('active');
                    buttons.forEach(btn => btn.disabled = true);

                    const feedbackDiv = accordion.querySelector('.guide-faq-feedback');
                    feedbackDiv.querySelector('span').textContent = 'Thank you for your feedback!';
                }
            } catch (error) {
                console.error('Feedback submission error:', error);
            }
        }
    </script>
</body>
</html>

