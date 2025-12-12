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
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) { 
        $settings[$row['setting_key']] = is_string($row['setting_value']) ? $row['setting_value'] : '';
    }
} catch (Exception $e) {}

// Get FAQs grouped by category
$searchQuery = $_GET['search'] ?? '';
$selectedCategory = $_GET['category'] ?? 'all';

$sql = "SELECT * FROM faqs WHERE is_active = 1";
$params = [];

if ($searchQuery) {
    $sql .= " AND (question LIKE ? OR answer LIKE ?)";
    $searchTerm = '%' . $searchQuery . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($selectedCategory && $selectedCategory != 'all') {
    $sql .= " AND category = ?";
    $params[] = $selectedCategory;
}

$sql .= " ORDER BY display_order ASC, created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faqs = $stmt->fetchAll();

// Get categories for filter
$stmt = $pdo->query("SELECT DISTINCT category FROM faqs WHERE is_active = 1 AND category IS NOT NULL AND category != '' ORDER BY category");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Track view if viewing specific FAQ
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    $stmt = $pdo->prepare("UPDATE faqs SET views = views + 1 WHERE id = ?");
    $stmt->execute([$viewId]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQs - <?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php if (!empty($settings['favicon'])): ?><link rel="icon" href="<?= htmlspecialchars($settings['favicon']) ?>" /><?php endif; ?>
    <style>
        :root { 
            --primary: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>; 
            --secondary: <?= htmlspecialchars($settings['secondary_color'] ?? '#64748b') ?>; 
            --primary-light: <?= htmlspecialchars($settings['primary_color'] ?? '#2563eb') ?>20; 
            font-family: <?= htmlspecialchars($settings['font_family'] ?? 'Inter') ?>, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        }
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
        
        .fade-in { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .fade-in.visible { opacity: 1; transform: translateY(0); }
        
        .faq-container { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem; }
        
        .faq-hero { 
            background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, #000)); 
            color: white; 
            padding: 3rem 2rem; 
            border-radius: 20px; 
            margin-bottom: 2rem; 
            position: relative; 
            overflow: hidden; 
            text-align: center;
        }
        .faq-hero::before { 
            content: ''; 
            position: absolute; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.05" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom; 
            background-size: cover; 
        }
        .faq-hero > * { position: relative; z-index: 1; }
        .faq-hero h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 0.75rem; }
        .faq-hero p { font-size: 1.125rem; opacity: 0.95; }
        
        .search-box { 
            background: rgba(255,255,255,0.08); 
            backdrop-filter: blur(10px); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 16px; 
            padding: 1.5rem; 
            margin-bottom: 2rem;
        }
        
        .search-input-wrapper { position: relative; }
        .search-input-wrapper i { 
            position: absolute; 
            left: 1.25rem; 
            top: 50%; 
            transform: translateY(-50%); 
            color: #94a3b8; 
            font-size: 1.25rem;
        }
        .search-input { 
            width: 100%; 
            padding: 1rem 1rem 1rem 3.5rem; 
            border: 2px solid rgba(255,255,255,0.2); 
            border-radius: 12px; 
            background: rgba(255,255,255,0.1); 
            color: #f1f5f9; 
            font-size: 1.0625rem;
            transition: all 0.3s;
        }
        .search-input:focus { 
            outline: none; 
            border-color: var(--primary); 
            background: rgba(255,255,255,0.15); 
            box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
        }
        .search-input::placeholder { color: #94a3b8; }
        
        .category-filters { 
            display: flex; 
            gap: 0.75rem; 
            flex-wrap: wrap; 
            margin-bottom: 2rem;
        }
        .category-filter { 
            padding: 0.75rem 1.5rem; 
            background: rgba(255,255,255,0.08); 
            border: 2px solid rgba(255,255,255,0.1); 
            border-radius: 50px; 
            color: #f1f5f9; 
            text-decoration: none; 
            font-weight: 600;
            transition: all 0.3s;
        }
        .category-filter:hover { 
            background: rgba(255,255,255,0.15); 
            color: #ffffff; 
            transform: translateY(-2px);
        }
        .category-filter.active { 
            background: var(--primary); 
            color: white; 
            border-color: var(--primary);
        }
        
        .faq-section { 
            background: rgba(255,255,255,0.08); 
            backdrop-filter: blur(10px); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 16px; 
            padding: 2rem; 
            margin-bottom: 2rem;
        }
        .faq-section-title { 
            font-size: 1.5rem; 
            font-weight: 700; 
            color: #f1f5f9; 
            margin-bottom: 1.5rem; 
            padding-bottom: 0.75rem; 
            border-bottom: 2px solid rgba(255,255,255,0.1);
        }
        
        .accordion-item { 
            background: rgba(255,255,255,0.05); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 12px !important; 
            margin-bottom: 0.75rem; 
            overflow: hidden;
        }
        .accordion-button { 
            background: transparent; 
            color: #f1f5f9; 
            font-weight: 600; 
            font-size: 1.0625rem; 
            padding: 1.25rem 1.5rem;
            border: none;
        }
        .accordion-button:not(.collapsed) { 
            background: rgba(37,99,235,0.15); 
            color: #60a5fa;
            box-shadow: none;
        }
        .accordion-button:focus { box-shadow: none; }
        .accordion-button::after { 
            filter: brightness(0) invert(1); 
            background-size: 1.25rem;
        }
        .accordion-button:not(.collapsed)::after { filter: brightness(0) saturate(100%) invert(62%) sepia(76%) saturate(2878%) hue-rotate(201deg) brightness(101%) contrast(98%); }
        .accordion-body { 
            background: rgba(255,255,255,0.03); 
            color: #cbd5e0; 
            padding: 1.5rem; 
            line-height: 1.8;
            font-size: 1rem;
        }
        .accordion-collapse { border-top: 1px solid rgba(255,255,255,0.1); }
        
        .faq-feedback { 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            margin-top: 1.5rem; 
            padding-top: 1.5rem; 
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .faq-feedback span { color: #94a3b8; font-size: 0.9375rem; }
        .feedback-btn { 
            background: rgba(255,255,255,0.1); 
            border: 1px solid rgba(255,255,255,0.2); 
            color: #f1f5f9; 
            padding: 0.5rem 1rem; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: all 0.2s;
            font-size: 0.9375rem;
        }
        .feedback-btn:hover { 
            background: rgba(34,197,94,0.2); 
            border-color: #22c55e; 
            color: #86efac;
        }
        .feedback-btn.active { 
            background: #22c55e; 
            border-color: #22c55e; 
            color: white;
        }
        
        .empty-state { 
            text-align: center; 
            padding: 3rem 1rem; 
            color: #94a3b8;
        }
        .empty-state i { 
            font-size: 4rem; 
            color: #475569; 
            margin-bottom: 1rem;
        }
        .empty-state h5 { color: #f1f5f9; margin-bottom: 0.5rem; }
        
        .quick-links { 
            background: rgba(255,255,255,0.08); 
            backdrop-filter: blur(10px); 
            border: 1px solid rgba(255,255,255,0.1); 
            border-radius: 16px; 
            padding: 1.75rem; 
            margin-top: 2rem;
        }
        .quick-links h6 { 
            font-size: 1.125rem; 
            font-weight: 700; 
            color: #f1f5f9; 
            margin-bottom: 1rem;
        }
        .quick-link { 
            display: flex; 
            align-items: center; 
            gap: 0.75rem; 
            color: #cbd5e0; 
            text-decoration: none; 
            padding: 0.75rem; 
            border-radius: 8px; 
            transition: all 0.2s;
            margin-bottom: 0.5rem;
        }
        .quick-link:hover { 
            background: rgba(255,255,255,0.1); 
            color: var(--primary); 
            transform: translateX(4px);
        }
        .quick-link i { 
            font-size: 1.25rem; 
            color: var(--primary);
        }
        
        @media (max-width: 768px) {
            .faq-hero h1 { font-size: 1.75rem; }
            .faq-section { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <?php include 'includes/mega-navbar.php'; ?>

    <div class="faq-container">
        <div class="faq-hero fade-in">
            <h1><i class="bi bi-question-circle me-3"></i>Frequently Asked Questions</h1>
            <p>Quick answers to common questions about our products and services</p>
        </div>

        <div class="search-box fade-in">
            <form method="get" action="">
                <div class="search-input-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Search FAQs..." 
                           value="<?= htmlspecialchars($searchQuery) ?>">
                </div>
            </form>
        </div>

        <?php if (!empty($categories)): ?>
            <div class="category-filters fade-in">
                <a href="faqs.php?category=all" class="category-filter <?= $selectedCategory == 'all' ? 'active' : '' ?>">
                    <i class="bi bi-grid me-2"></i>All
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="faqs.php?category=<?= urlencode($cat) ?>" 
                       class="category-filter <?= $selectedCategory == $cat ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($faqs)): ?>
            <div class="faq-section fade-in">
                <div class="empty-state">
                    <i class="bi bi-search"></i>
                    <h5>No FAQs found</h5>
                    <p>Try adjusting your search or browse by category</p>
                    <?php if ($searchQuery || $selectedCategory != 'all'): ?>
                        <a href="faqs.php" class="btn btn-primary mt-3">
                            <i class="bi bi-arrow-clockwise me-2"></i>View All FAQs
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php
            // Group FAQs by category
            $groupedFaqs = [];
            foreach ($faqs as $faq) {
                $cat = $faq['category'] ?: 'General';
                if (!isset($groupedFaqs[$cat])) {
                    $groupedFaqs[$cat] = [];
                }
                $groupedFaqs[$cat][] = $faq;
            }
            ?>

            <?php foreach ($groupedFaqs as $category => $categoryFaqs): ?>
                <div class="faq-section fade-in">
                    <h3 class="faq-section-title">
                        <i class="bi bi-bookmark me-2"></i><?= htmlspecialchars($category) ?>
                    </h3>

                    <div class="accordion" id="accordion<?= preg_replace('/[^a-zA-Z0-9]/', '', $category) ?>">
                        <?php foreach ($categoryFaqs as $index => $faq): ?>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" 
                                            type="button" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#collapse<?= $faq['id'] ?>">
                                        <?= htmlspecialchars($faq['question']) ?>
                                    </button>
                                </h2>
                                <div id="collapse<?= $faq['id'] ?>" 
                                     class="accordion-collapse collapse <?= $index == 0 ? 'show' : '' ?>" 
                                     data-bs-parent="#accordion<?= preg_replace('/[^a-zA-Z0-9]/', '', $category) ?>">
                                    <div class="accordion-body">
                                        <?= nl2br(htmlspecialchars($faq['answer'])) ?>

                                        <div class="faq-feedback">
                                            <span>Was this helpful?</span>
                                            <button class="feedback-btn" onclick="submitFeedback(<?= $faq['id'] ?>, 1)">
                                                <i class="bi bi-hand-thumbs-up me-1"></i>Yes
                                            </button>
                                            <button class="feedback-btn" onclick="submitFeedback(<?= $faq['id'] ?>, 0)">
                                                <i class="bi bi-hand-thumbs-down me-1"></i>No
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="quick-links fade-in">
            <h6><i class="bi bi-lightning me-2"></i>Quick Links</h6>
            <a href="troubleshooting.php" class="quick-link">
                <i class="bi bi-tools"></i>
                <span>Troubleshooting Guides - Step-by-step solutions</span>
            </a>
            <a href="<?= $isLoggedIn ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="quick-link">
                <i class="bi bi-calendar-plus"></i>
                <span>Book Service Appointment</span>
            </a>
            <a href="contact.php" class="quick-link">
                <i class="bi bi-envelope"></i>
                <span>Contact Support</span>
            </a>
        </div>
    </div>

    <section class="py-5 bg-dark text-white mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
                    <?php if (!empty($settings['company_address'])): ?><p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?= nl2br(htmlspecialchars($settings['company_address'])) ?></p><?php endif; ?>
                    <?php if (!empty($settings['company_phone'])): ?><p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($settings['company_phone']) ?></p><?php endif; ?>
                </div>
                <div class="col-md-6 text-md-end"><p class="text-white"><?= htmlspecialchars($settings['footer_text'] ?? '© ' . date('Y') . ' ' . ($settings['company_name'] ?? 'Soreta Electronics')) ?></p></div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'includes/mega-navbar-js.php'; ?>
    <script>
        // Fade-in animation observer
        const observer = new IntersectionObserver(entries => { 
            entries.forEach(e => { 
                if(e.isIntersecting) e.target.classList.add('visible'); 
            }); 
        }, {threshold:0.1,rootMargin:'0px 0px -50px 0px'});
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

        // FAQ Feedback
        async function submitFeedback(faqId, isHelpful) {
            try {
                const response = await fetch('faq_feedback.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ faq_id: faqId, is_helpful: isHelpful })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Find the feedback buttons for this FAQ
                    const accordion = document.getElementById('collapse' + faqId);
                    const buttons = accordion.querySelectorAll('.feedback-btn');
                    
                    // Mark the clicked button as active
                    buttons.forEach(btn => btn.classList.remove('active'));
                    event.target.closest('.feedback-btn').classList.add('active');
                    
                    // Disable both buttons
                    buttons.forEach(btn => btn.disabled = true);
                    
                    // Show thank you message
                    const feedbackDiv = accordion.querySelector('.faq-feedback');
                    feedbackDiv.querySelector('span').textContent = 'Thank you for your feedback!';
                }
            } catch (error) {
                console.error('Feedback submission error:', error);
            }
        }
    </script>
</body>
</html>