<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

// Get company settings
$settings = [];
try {
    $colsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'");
    $colsStmt->execute();
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $colKey = in_array('setting_key', $cols) ? 'setting_key' : (in_array('key', $cols) ? 'key' : 'setting_key');
    $colValue = in_array('setting_value', $cols) ? 'setting_value' : (in_array('value', $cols) ? 'value' : 'setting_value');

    $colKeyEsc = "`" . str_replace('`', '', $colKey) . "`";
    $colValueEsc = "`" . str_replace('`', '', $colValue) . "`";

    $stmt = $pdo->query("SELECT " . $colKeyEsc . " AS setting_key, " . $colValueEsc . " AS setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // ignore
}

// Sanitize settings
foreach ($settings as $key => $val) {
    if (is_array($val)) {
        $settings[$key] = implode(' ', $val);
    } elseif (!is_string($val)) {
        $settings[$key] = (string)$val;
    }
}

// Pagination and search
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Build query
$where = ["is_active = 1"];
$params = [];

if (!empty($search)) {
    $where[] = "(title LIKE ? OR content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where[] = "category = ?";
    $params[] = $category;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE $whereClause");
$countStmt->execute($params);
$totalAnnouncements = $countStmt->fetchColumn();
$totalPages = ceil($totalAnnouncements / $perPage);

// Get announcements
$offset = ($page - 1) * $perPage;
$query = "SELECT * FROM announcements WHERE $whereClause ORDER BY display_order ASC, date DESC, created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

// Fallback: if announcements table is empty (or admin saved announcements into settings),
// attempt to load announcements from settings key 'announcements' (JSON) so admin changes via
// Settings UI are visible without requiring migration.
if (empty($announcements)) {
    $announcementsRaw = $settings['announcements'] ?? ($settings['announcements']['setting_value'] ?? '[]');
    $annFromSettings = [];
    if (is_string($announcementsRaw)) {
        $decoded = json_decode($announcementsRaw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $annFromSettings = $decoded;
        }
    } elseif (is_array($announcementsRaw)) {
        $annFromSettings = $announcementsRaw;
    }

    if (!empty($annFromSettings)) {
        // Normalize to shape expected by the template
        $normalized = [];
        foreach ($annFromSettings as $a) {
            $normalized[] = [
                'id' => $a['id'] ?? null,
                'title' => $a['title'] ?? ($a['heading'] ?? ''),
                'content' => $a['content'] ?? ($a['description'] ?? ''),
                'date' => $a['date'] ?? ($a['created_at'] ?? date('Y-m-d')),
                'image_path' => $a['image_path'] ?? ($a['image'] ?? ''),
                'category' => $a['category'] ?? '',
                'created_at' => $a['created_at'] ?? date('Y-m-d H:i:s'),
                'is_active' => isset($a['is_active']) ? (int)$a['is_active'] : 1,
                'display_order' => $a['display_order'] ?? 0,
            ];
        }

        // Apply simple pagination to the normalized array
        $totalAnnouncements = count($normalized);
        $totalPages = max(1, ceil($totalAnnouncements / $perPage));
        $announcements = array_slice($normalized, $offset, $perPage);

        // Build categories from settings fallback if DB categories are empty
        if (empty($categories)) {
            $cats = [];
            foreach ($normalized as $n) {
                if (!empty($n['category']) && !in_array($n['category'], $cats)) $cats[] = $n['category'];
            }
            $categories = $cats;
        }
    }
}

// Get categories for filter
$categoryStmt = $pdo->query("SELECT DISTINCT category FROM announcements WHERE category IS NOT NULL AND category != '' AND is_active = 1 ORDER BY category");
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

// Logged-in state
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - <?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php
    $favicon = $settings['favicon'] ?? '';
    if (!empty($favicon) && is_string($favicon)): ?>
        <link rel="icon" href="<?= htmlspecialchars($favicon[0] === '/' ? $favicon : ROOT_PATH . $favicon) ?>" />
    <?php endif; ?>
    <style>
        :root {
            --primary: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>;
            --secondary: <?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?>;
            --primary-light: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>20;
            font-family: <?= htmlspecialchars($settings['font_family'] ?? 'Inter') ?>, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        /* Navbar effects matching homepage */
        nav.navbar {
            transition: all 0.3s ease;
        }

        nav.navbar.scrolled {
            background: rgba(255, 255, 255, 0.85) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
        }

        @media (prefers-color-scheme: dark) {
            nav.navbar.scrolled {
                background: rgba(30, 30, 30, 0.85) !important;
            }
        }

        /* Hero Section - matching homepage style */
        .hero-section {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 70%, black) 100%);
            overflow: hidden;
            margin-top: 56px;
            padding: 4rem 0 3rem 0;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-heading {
            color: #ffffff;
            text-shadow: 0 6px 18px rgba(0,0,0,0.6);
        }

        .hero-lead {
            color: rgba(255,255,255,0.95);
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        /* Wave separator */
        .wave-separator {
            position: relative;
            height: 80px;
            background: white;
        }

        .wave-separator svg {
            position: absolute;
            top: -79px;
            left: 0;
            width: 100%;
            height: 80px;
        }

        /* Fade in animation */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Search section */
        .search-section {
            background: var(--primary-light);
            padding: 2.5rem 0;
            margin-bottom: 3rem;
        }

        .search-section .form-control,
        .search-section .form-select {
            border-radius: 12px;
            padding: 0.75rem 1.25rem;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            background: white;
        }

        .search-section .form-control:focus,
        .search-section .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem var(--primary-light);
        }

        .search-section .btn {
            border-radius: 12px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .search-section .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        /* Announcement cards - matching homepage style */
        .announcement-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: none;
            height: 100%;
        }

        .announcement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--primary-light), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 0;
        }

        .announcement-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15) !important;
        }

        .announcement-card:hover::before {
            opacity: 1;
        }

        .announcement-card .card-body {
            position: relative;
            z-index: 1;
        }

        .announcement-card img {
            transition: transform 0.3s ease;
        }

        .announcement-card:hover img {
            transform: scale(1.05);
        }

        .announcement-date {
            background: var(--primary);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .category-badge {
            background: var(--secondary);
            color: white;
            padding: 0.35rem 0.85rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Pagination styling */
        .pagination {
            gap: 0.5rem;
        }

        .pagination .page-link {
            color: var(--primary);
            border-radius: 8px;
            border: 2px solid var(--primary-light);
            transition: all 0.3s ease;
            font-weight: 500;
            padding: 0.5rem 1rem;
        }

        .pagination .page-link:hover {
            background-color: var(--primary-light);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .pagination .page-item.disabled .page-link {
            border-color: #e5e7eb;
        }

        /* Empty state styling */
        .empty-state {
            padding: 5rem 0;
            text-align: center;
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--primary);
            opacity: 0.3;
            margin-bottom: 2rem;
        }

        /* Filter badges */
        .active-filters {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            align-items: center;
        }

        .filter-badge {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .filter-badge .btn-close {
            width: 0.75rem;
            height: 0.75rem;
            opacity: 0.8;
            filter: invert(1) brightness(2);
            padding: 0;
        }

        .filter-badge .btn-close:hover {
            opacity: 1;
        }

        /* Stats bar */
        .stats-bar {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Dark navbar for small screens */
        @media (max-width: 991px) {
            nav.navbar {
                background-color: #343a40 !important;
                border-bottom: 1px solid #495057 !important;
            }
            .navbar-nav .nav-link {
                color: #ffffff !important;
            }
            .navbar-toggler {
                border-color: #ffffff;
            }
            .navbar-toggler-icon {
                background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.55%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
            }
            .navbar-brand {
                color: #ffffff !important;
            }
        }

        /* Mobile responsive */
        @media (max-width: 767px) {
            .hero-section {
                padding: 2rem 0 1.5rem 0;
            }

            .hero-heading {
                font-size: 2rem;
            }
            
            .search-section .col-md-5,
            .search-section .col-md-4,
            .search-section .col-md-3 {
                margin-bottom: 1rem;
            }

            .stats-bar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .active-filters {
                font-size: 0.85rem;
            }
        }

        <?= $settings['custom_css'] ?? '' ?>
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary d-flex align-items-center" href="index.php">
                <?php
                $logo = $settings['site_logo'] ?? '';
                if (!empty($logo) && is_string($logo)): ?>
                    <img src="<?= htmlspecialchars($logo[0] === '/' ? $logo : ROOT_PATH . $logo) ?>" alt="<?= htmlspecialchars($settings['company_name'] ?? 'Soreta') ?> logo" style="height:36px; margin-right:8px;">
                <?php else: ?>
                    <i class="bi bi-lightning-charge-fill me-2"></i>
                <?php endif; ?>
                <span><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="services.php">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
                    <li class="nav-item active"><a class="nav-link" href="announcements.php">Announcements</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
                    <?php if ($isLoggedIn): ?>
                        <?php if ($userRole === 'customer'): ?>
                            <li class="nav-item"><a class="nav-link" href="customer/dashboard.php">My Dashboard</a></li>
                        <?php elseif ($userRole === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="admin/dashboard.php">Admin</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="btn btn-outline-light ms-2" href="auth/logout.php">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="auth/login.php">Login</a></li>
                        <li class="nav-item"><a class="btn btn-primary ms-2" href="auth/register.php">Get Started</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section text-white">
        <div class="container hero-content">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3 hero-heading">
                        <i class="bi bi-megaphone me-3"></i>Announcements
                    </h1>
                    <p class="lead mb-0 hero-lead">
                        Stay updated with our latest news, updates, and important information
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Wave separator -->
    <div class="wave-separator">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320" preserveAspectRatio="none">
            <path fill="#ffffff" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
        </svg>
    </div>

    <!-- Search and Filter Section -->
    <section class="search-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-search me-2"></i>Search
                            </label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search announcements..." 
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-funnel me-2"></i>Category
                            </label>
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Announcements List -->
    <div class="container mb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Active Filters -->
                <?php if (!empty($search) || !empty($category)): ?>
                    <div class="active-filters fade-in">
                        <span class="me-2 fw-semibold text-muted">Active filters:</span>
                        <?php if (!empty($search)): ?>
                            <span class="filter-badge">
                                Search: "<?= htmlspecialchars($search) ?>"
                                <a href="?category=<?= urlencode($category) ?>" class="btn-close" aria-label="Remove search filter"></a>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($category)): ?>
                            <span class="filter-badge">
                                Category: <?= htmlspecialchars($category) ?>
                                <a href="?search=<?= urlencode($search) ?>" class="btn-close" aria-label="Remove category filter"></a>
                            </span>
                        <?php endif; ?>
                        <a href="announcements.php" class="btn btn-sm btn-outline-secondary">Clear all</a>
                    </div>
                <?php endif; ?>

                <!-- Stats Bar -->
                <div class="stats-bar fade-in">
                    <div>
                        <strong><?= $totalAnnouncements ?></strong> 
                        <span class="text-muted">total announcement<?= $totalAnnouncements !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="text-muted">
                            Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="text-muted">
                        Showing <strong><?= count($announcements) ?></strong> result<?= count($announcements) !== 1 ? 's' : '' ?>
                    </div>
                </div>

                <?php if (empty($announcements)): ?>
                    <!-- Empty State -->
                    <div class="empty-state fade-in">
                        <i class="bi bi-inbox"></i>
                        <h3 class="mb-3">No Announcements Found</h3>
                        <p class="text-muted mb-4">
                            <?php if (!empty($search) || !empty($category)): ?>
                                We couldn't find any announcements matching your criteria.
                            <?php else: ?>
                                There are no announcements available at this time.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search) || !empty($category)): ?>
                            <a href="announcements.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left me-2"></i>View All Announcements
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Announcements Grid -->
                    <div class="row g-4">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="col-md-6 col-lg-4 fade-in">
                                <div class="card announcement-card shadow-sm">
                                    <?php if (!empty($announcement['image_path']) && file_exists($announcement['image_path'])): ?>
                                        <div style="overflow: hidden; height: 200px; background: #f8f9fa;">
                                            <img src="<?= htmlspecialchars($announcement['image_path']) ?>" 
                                                 alt="<?= htmlspecialchars($announcement['title']) ?>" 
                                                 class="w-100" 
                                                 style="height: 200px; object-fit: cover;">
                                        </div>
                                    <?php else: ?>
                                        <!-- Default gradient background if no image -->
                                        <div style="height: 200px; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-megaphone" style="font-size: 3rem; color: white; opacity: 0.5;"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                                            <h5 class="card-title mb-0 flex-grow-1" style="font-size: 1.1rem; line-height: 1.4;">
                                                <?= htmlspecialchars($announcement['title']) ?>
                                            </h5>
                                            <?php if (!empty($announcement['category'])): ?>
                                                <span class="category-badge">
                                                    <?= htmlspecialchars($announcement['category']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($announcement['date'])): ?>
                                            <div class="mb-3">
                                                <span class="announcement-date">
                                                    <i class="bi bi-calendar-event me-1"></i>
                                                    <?= date('M j, Y', strtotime($announcement['date'])) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <p class="card-text text-muted flex-grow-1" style="font-size: 0.9rem;">
                                            <?php
                                            $content = $announcement['content'];
                                            $maxLength = 120;
                                            if (strlen($content) > $maxLength) {
                                                echo nl2br(htmlspecialchars(substr($content, 0, $maxLength))) . '...';
                                            } else {
                                                echo nl2br(htmlspecialchars($content));
                                            }
                                            ?>
                                        </p>

                                        <div class="mt-auto pt-3 border-top">
                                            <small class="text-muted">
                                                <i class="bi bi-clock me-1"></i>
                                                <?php
                                                $postedDate = strtotime($announcement['created_at']);
                                                $now = time();
                                                $diff = $now - $postedDate;

                                                if ($diff < 3600) {
                                                    $minutes = floor($diff / 60);
                                                    echo $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';
                                                } elseif ($diff < 86400) {
                                                    $hours = floor($diff / 3600);
                                                    echo $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
                                                } elseif ($diff < 604800) {
                                                    $days = floor($diff / 86400);
                                                    echo $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
                                                } else {
                                                    echo date('M j, Y', $postedDate);
                                                }
                                                ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Announcements pagination" class="mt-5">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>">
                                            <i class="bi bi-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);

                                if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>"><?= $totalPages ?></a>
                                    </li>
                                <?php endif; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>">
                                            Next <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <section id="contact" class="py-5 bg-dark text-white">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
                    <?php
                    $company_address = $settings['company_address'] ?? '';
                    $company_phone = $settings['company_phone'] ?? '';
                    $company_email = $settings['company_email'] ?? '';
                    ?>
                    <?php if (!empty($company_address)): ?>
                        <p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?= nl2br(htmlspecialchars($company_address)) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company_phone)): ?>
                        <p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($company_phone) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company_email)): ?>
                        <p class="mb-0"><i class="bi bi-envelope-fill me-2"></i><a href="mailto:<?= htmlspecialchars($company_email) ?>" class="text-white"><?= htmlspecialchars($company_email) ?></a></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-white"><?= htmlspecialchars($settings['footer_text'] ?? '&copy; ' . date('Y') . ' ' . ($settings['company_name'] ?? 'Soreta Electronics')) ?></p>
                    <?php if (!empty($settings['social_facebook']) || !empty($settings['social_messenger'])): ?>
                        <div class="mt-2">
                            <?php if (!empty($settings['social_facebook'])): ?>
                                <a href="<?= htmlspecialchars($settings['social_facebook']) ?>" target="_blank" class="text-white me-3"><i class="bi bi-facebook"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($settings['social_messenger'])): ?>
                                <a href="<?= htmlspecialchars($settings['social_messenger']) ?>" target="_blank" class="text-white"><i class="bi bi-messenger"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Fade in on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    const offset = 70;
                    const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>

    <?php if (!empty($settings['google_analytics'])): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($settings['google_analytics']) ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '<?= htmlspecialchars($settings['google_analytics']) ?>');
        </script>
    <?php endif; ?>

    <?php if (!empty($settings['facebook_pixel'])): ?>
        <script>
            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '<?= htmlspecialchars($settings['facebook_pixel']) ?>');
            fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id=<?= htmlspecialchars($settings['facebook_pixel']) ?>&ev=PageView&noscript=1"
        /></noscript>
    <?php endif; ?>
</body>
</html>