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
    $stmt = $pdo->query("SELECT `{$colKey}` AS setting_key, `{$colValue}` AS setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// Sanitize settings
foreach ($settings as $key => $val) {
    if (is_array($val)) $settings[$key] = implode(' ', $val);
    elseif (!is_string($val)) $settings[$key] = (string)$val;
}

// Logged-in state
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;

// Service details
$serviceDetails = [
    'installation' => [
        'icon' => 'bi-gear-wide-connected',
        'title' => 'Installation',
        'description' => 'Professional setup and installation of all your electronic systems',
        'features' => ['Home & Office Automation', 'Security & Surveillance Systems', 'Audio-Visual (AV) Systems', 'Structured Cabling', 'Lighting Solutions'],
        'benefits' => ['Expert technicians with years of experience', 'Proper setup ensuring optimal performance', 'Safety-compliant installations', 'Post-installation support and training']
    ],
    'repair' => [
        'icon' => 'bi-wrench-adjustable-circle',
        'title' => 'Repair',
        'description' => 'Fast and reliable repair services when your equipment fails',
        'features' => ['Diagnosis & Troubleshooting', 'Component-Level Repair', 'Unit Replacement & Swap-Out', 'Emergency Repair Services'],
        'benefits' => ['Rapid diagnosis and assessment', 'Minimal downtime on your systems', 'Quality replacement parts', '24/7 emergency support available']
    ],
    'maintenance' => [
        'icon' => 'bi-shield-check',
        'title' => 'Maintenance',
        'description' => 'Preventive maintenance to extend equipment lifespan',
        'features' => ['Routine Check-ups', 'System Optimization', 'Preventive Cleaning & Care', 'Health Reports', '24/7 Remote Monitoring'],
        'benefits' => ['Prevent costly unexpected breakdowns', 'Extended equipment lifespan', 'Improved system reliability', 'Detailed health reports and recommendations']
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - <?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php $favicon = $settings['favicon'] ?? '';
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
        html { scroll-behavior: smooth; }
    </style>
    <?php include 'includes/mega-navbar-css.php'; ?>
    <style>
        .fade-in { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .fade-in.visible { opacity: 1; transform: translateY(0); }
        
        .hero-section {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 70%, black) 100%);
            overflow: hidden; padding: 4rem 0 3rem 0;
        }
        .hero-section::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover; pointer-events: none;
        }
        .hero-content { position: relative; z-index: 2; }
        .hero-heading, .hero-lead { color: #ffffff; text-shadow: 0 6px 18px rgba(0,0,0,0.6); }
        .wave-separator { position: relative; height: 80px; background: white; }
        .wave-separator svg { position: absolute; top: -79px; left: 0; width: 100%; height: 80px; }

        .service-card { transition: all 0.3s ease; position: relative; overflow: hidden; border: none; height: 100%; }
        .service-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, var(--primary-light), transparent); opacity: 0; transition: opacity 0.3s ease; z-index: 0; }
        .service-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(0,0,0,0.15) !important; }
        .service-card:hover::before { opacity: 1; }
        .service-card .card-body { position: relative; z-index: 1; }
        .service-icon { font-size: 3.5rem; color: var(--primary); margin-bottom: 1.5rem; transition: transform 0.3s ease; }
        .service-card:hover .service-icon { transform: scale(1.15) rotate(5deg); }
        
        .feature-list { list-style: none; padding: 0; margin: 1.5rem 0; }
        .feature-list li { padding: 0.75rem 0; display: flex; align-items: center; gap: 0.75rem; color: #4b5563; font-size: 0.95rem; }
        .feature-list li i { color: var(--primary); font-size: 1.25rem; flex-shrink: 0; }
        
        .benefits-section { background: var(--primary-light); border-radius: 12px; padding: 2rem; margin-top: 2rem; }
        .benefits-title { font-size: 1.25rem; font-weight: 700; color: #1f2937; margin-bottom: 1.5rem; }
        .benefit-item { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .benefit-item:last-child { margin-bottom: 0; }
        .benefit-icon { flex-shrink: 0; width: 32px; height: 32px; background: var(--primary); color: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .benefit-text { color: #4b5563; font-size: 0.95rem; }
        
        .pricing-section { background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, black)); color: white; padding: 3rem 0; margin-top: 4rem; }
        .pricing-card { background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); border-radius: 16px; padding: 2rem; margin-bottom: 1.5rem; transition: all 0.3s ease; }
        .pricing-card:hover { transform: translateY(-8px); background: rgba(255,255,255,0.15); box-shadow: 0 12px 40px rgba(0,0,0,0.3); }
        .pricing-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; }
        .pricing-description { opacity: 0.9; margin-bottom: 1.5rem; }
        .cta-button { background: white; color: var(--primary); border: none; padding: 0.875rem 1.75rem; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; display: inline-block; text-decoration: none; }
        .cta-button:hover { background: #f8f9fa; color: var(--primary); transform: translateY(-2px); }
        
        <?= $settings['custom_css'] ?? '' ?>
    </style>
</head>
<body>
    <?php include 'includes/mega-navbar.php'; ?>

    <!-- Hero Section -->
    <section class="hero-section text-white">
        <div class="container hero-content">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3 hero-heading"></i>Our Services</h1>
                    <p class="lead mb-0 hero-lead">Comprehensive solutions for all your electronic repair, installation, and maintenance needs</p>
                </div>
            </div>
        </div>
    </section>

    <div class="wave-separator">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320" preserveAspectRatio="none">
            <path fill="#ffffff" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
        </svg>
    </div>

    <!-- Services Detail Section -->
    <section class="py-5">
        <div class="container">
            <div class="row g-4">
                <?php foreach ($serviceDetails as $serviceKey => $service): ?>
                    <div class="col-lg-4 fade-in">
                        <div class="card h-100 border-0 shadow-sm service-card">
                            <div class="card-body">
                                <div class="service-icon"><i class="bi <?= $service['icon'] ?>"></i></div>
                                <h3 class="card-title mb-2"><?= $service['title'] ?></h3>
                                <p class="card-text text-muted mb-4"><?= $service['description'] ?></p>
                                <h6 class="fw-bold mt-4 mb-3">What We Offer:</h6>
                                <ul class="feature-list">
                                    <?php foreach ($service['features'] as $feature): ?>
                                        <li><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($feature) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="benefits-section">
                                    <div class="benefits-title">Why Choose Us?</div>
                                    <?php foreach ($service['benefits'] as $benefit): ?>
                                        <div class="benefit-item">
                                            <div class="benefit-icon"><i class="bi bi-star-fill"></i></div>
                                            <div class="benefit-text"><?= htmlspecialchars($benefit) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <a href="<?= $isLoggedIn && $userRole === 'customer' ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="btn btn-primary w-100 mt-4">
                                    <i class="bi bi-calendar-check me-2"></i><?= $isLoggedIn && $userRole === 'customer' ? 'Book This Service' : 'Get Started' ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Call to Action Section -->
    <section class="pricing-section">
        <div class="container">
            <div class="row justify-content-center mb-5 text-center">
                <div class="col-lg-8">
                    <h2 class="display-5 fw-bold mb-3">Ready to Get Started?</h2>
                    <p class="lead" style="opacity: 0.95;">Book your service appointment today and experience professional electronics care</p>
                </div>
            </div>
            <div class="row justify-content-center g-4">
                <div class="col-md-6">
                    <div class="pricing-card">
                        <div class="pricing-title"><i class="bi bi-lightning-charge me-2"></i>Quick Service</div>
                        <div class="pricing-description">Need urgent repairs or maintenance? We offer same-day service for eligible requests.</div>
                        <a href="<?= $isLoggedIn && $userRole === 'customer' ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="cta-button">Book Now</a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="pricing-card">
                        <div class="pricing-title"><i class="bi bi-question-circle me-2"></i>Need Help?</div>
                        <div class="pricing-description">Have questions about our services? Contact us today for a free consultation.</div>
                        <a href="contact.php" class="cta-button">Get in Touch</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <section id="contact" class="py-5 bg-dark text-white">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
                    <?php if (!empty($settings['company_address'])): ?>
                        <p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?= nl2br(htmlspecialchars($settings['company_address'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($settings['company_phone'])): ?>
                        <p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($settings['company_phone']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($settings['company_email'])): ?>
                        <p class="mb-0"><i class="bi bi-envelope-fill me-2"></i><a href="mailto:<?= htmlspecialchars($settings['company_email']) ?>" class="text-white"><?= htmlspecialchars($settings['company_email']) ?></a></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-white"><?= htmlspecialchars($settings['footer_text'] ?? '&copy; ' . date('Y') . ' ' . ($settings['company_name'] ?? 'Soreta Electronics')) ?></p>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'includes/mega-navbar-js.php'; ?>
    <script>
        // Fade in on scroll
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => { if (entry.isIntersecting) entry.target.classList.add('visible'); });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
    </script>
</body>
</html>