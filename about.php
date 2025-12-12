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
    while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }
} catch (Exception $e) {}

foreach ($settings as $key => $val) {
    if (is_array($val)) $settings[$key] = implode(' ', $val);
    elseif (!is_string($val)) $settings[$key] = (string)$val;
}

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;

$values = [
    ['icon' => 'bi-heart-fill', 'title' => 'Customer First', 'description' => 'Your satisfaction is our top priority. We listen, adapt, and deliver excellence in every interaction.'],
    ['icon' => 'bi-gear-fill', 'title' => 'Technical Excellence', 'description' => 'Our certified technicians stay current with latest technology and repair methodologies.'],
    ['icon' => 'bi-shield-check', 'title' => 'Reliability', 'description' => 'Dependable service you can count on, every time. We stand behind our work with confidence.'],
    ['icon' => 'bi-lightning-charge-fill', 'title' => 'Innovation', 'description' => 'Constantly improving our services using cutting-edge tools and techniques.']
];

$teamRoles = [
    ['icon' => 'bi-person-badge', 'role' => 'Expert Technicians', 'description' => 'Certified professionals with years of field experience'],
    ['icon' => 'bi-telephone-fill', 'role' => 'Support Team', 'description' => 'Available to assist you with scheduling and inquiries'],
    ['icon' => 'bi-tools', 'role' => 'Maintenance Specialists', 'description' => 'Keeping your systems running optimally']
];

$yearsExperience = !empty($settings['years_in_business']) ? intval($settings['years_in_business']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - <?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php $favicon = $settings['favicon'] ?? ''; if (!empty($favicon)): ?>
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
        
        .hero-section { position: relative; background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 70%, black) 100%); overflow: hidden; padding: 4rem 0 3rem 0; }
        .hero-section::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom; background-size: cover; pointer-events: none; }
        .hero-content { position: relative; z-index: 2; }
        .hero-heading, .hero-lead { color: #ffffff; text-shadow: 0 6px 18px rgba(0,0,0,0.6); }
        .wave-separator { position: relative; height: 80px; background: white; }
        .wave-separator svg { position: absolute; top: -79px; left: 0; width: 100%; height: 80px; }

        .value-card { transition: all 0.3s ease; position: relative; overflow: hidden; border: none; height: 100%; text-align: center; }
        .value-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, var(--primary-light), transparent); opacity: 0; transition: opacity 0.3s ease; z-index: 0; }
        .value-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(0,0,0,0.15) !important; }
        .value-card:hover::before { opacity: 1; }
        .value-card .card-body { position: relative; z-index: 1; }
        .value-icon { font-size: 3rem; color: var(--primary); margin-bottom: 1rem; transition: transform 0.3s ease; }
        .value-card:hover .value-icon { transform: scale(1.15) rotate(5deg); }

        .stats-section { background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, black)); color: white; padding: 3rem 0; }
        .stat-item { text-align: center; padding: 2rem; }
        .stat-number { font-size: 3rem; font-weight: bold; margin-bottom: 0.5rem; }
        .stat-label { font-size: 1.125rem; opacity: 0.9; }

        .about-intro { background: var(--primary-light); padding: 3rem; border-radius: 16px; margin-bottom: 3rem; }
        .about-intro h3 { color: var(--primary); margin-bottom: 1rem; font-weight: 700; }
        .about-intro p { color: #4b5563; font-size: 1.0625rem; line-height: 1.8; margin-bottom: 1rem; }

        .team-card { transition: all 0.3s ease; text-align: center; padding: 2rem; border: 1px solid #e5e7eb; border-radius: 16px; background: white; position: relative; overflow: hidden; }
        .team-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, var(--primary-light), transparent); opacity: 0; transition: opacity 0.3s ease; z-index: 0; }
        .team-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); border-color: var(--primary); }
        .team-card:hover::before { opacity: 1; }
        .team-card > * { position: relative; z-index: 1; }
        .team-icon { font-size: 3rem; color: var(--primary); margin-bottom: 1rem; }
        .team-title { font-size: 1.25rem; font-weight: 700; color: #1f2937; margin-bottom: 0.5rem; }
        .team-description { color: #6b7280; font-size: 0.95rem; }

        .cta-section { background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 70%, black)); color: white; padding: 4rem 0; border-radius: 16px; text-align: center; }
        .cta-button { background: white; color: var(--primary); border: none; padding: 0.875rem 1.75rem; border-radius: 12px; font-weight: 600; transition: all 0.3s ease; display: inline-block; text-decoration: none; margin-top: 1.5rem; }
        .cta-button:hover { background: #f8f9fa; color: var(--primary); transform: translateY(-2px); }
        
        <?= $settings['custom_css'] ?? '' ?>
    </style>
</head>
<body>
    <?php include 'includes/mega-navbar.php'; ?>

    <section class="hero-section text-white">
        <div class="container hero-content">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3 hero-heading"><i class="bi bi-building me-3"></i>About Us</h1>
                    <p class="lead mb-0 hero-lead">Your trusted partner for professional electronics repair, installation, and maintenance</p>
                </div>
            </div>
        </div>
    </section>

    <div class="wave-separator">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320" preserveAspectRatio="none">
            <path fill="#ffffff" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
        </svg>
    </div>

    <section class="py-5">
        <div class="container">
            <div class="about-intro fade-in">
                <h3>Who We Are</h3>
                <p><?= htmlspecialchars($settings['company_about'] ?? 'Committed to providing professional electronics repair services with expertise and reliability.') ?></p>
            </div>
        </div>
    </section>

    <?php if ($yearsExperience > 0): ?>
    <section class="stats-section">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-md-4 fade-in">
                    <div class="stat-item">
                        <div class="stat-number" data-target="<?= $yearsExperience ?>">0</div>
                        <div class="stat-label">Years of Experience</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="py-5 bg-light">
        <div class="container">
            <div class="row justify-content-center mb-5 text-center">
                <div class="col-lg-8 fade-in"><h2 class="mb-3 fw-bold">Our Core Values</h2><p class="lead text-muted">These principles guide everything we do</p></div>
            </div>
            <div class="row g-4">
                <?php foreach ($values as $value): ?>
                <div class="col-md-6 col-lg-3 fade-in">
                    <div class="card h-100 border-0 shadow-sm value-card">
                        <div class="card-body">
                            <div class="value-icon"><i class="bi <?= $value['icon'] ?>"></i></div>
                            <h5 class="card-title mb-3"><?= htmlspecialchars($value['title']) ?></h5>
                            <p class="card-text text-muted"><?= htmlspecialchars($value['description']) ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center mb-5 text-center">
                <div class="col-lg-8 fade-in"><h2 class="mb-3 fw-bold">Our Team</h2><p class="lead text-muted">Dedicated professionals committed to your satisfaction</p></div>
            </div>
            <div class="row g-4">
                <?php foreach ($teamRoles as $role): ?>
                <div class="col-md-4 fade-in">
                    <div class="team-card">
                        <div class="team-icon"><i class="bi <?= $role['icon'] ?>"></i></div>
                        <div class="team-title"><?= htmlspecialchars($role['role']) ?></div>
                        <p class="team-description"><?= htmlspecialchars($role['description']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="cta-section fade-in">
                <h2 class="display-5 fw-bold mb-3">Ready to Get Started?</h2>
                <p class="lead mb-4" style="opacity:0.95;">Let us take care of your electronics. Book a service or contact us today!</p>
                <a href="<?= $isLoggedIn && $userRole === 'customer' ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="cta-button"><i class="bi bi-calendar-check me-2"></i>Book Service</a>
                <a href="contact.php" class="cta-button" style="background:transparent;color:white;border:2px solid white;"><i class="bi bi-chat-left-text me-2"></i>Get in Touch</a>
            </div>
        </div>
    </section>

    <section class="py-5 bg-dark text-white">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
                    <?php if (!empty($settings['company_address'])): ?><p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?= nl2br(htmlspecialchars($settings['company_address'])) ?></p><?php endif; ?>
                    <?php if (!empty($settings['company_phone'])): ?><p class="mb-1"><i class="bi bi-telephone-fill me-2"></i><?= htmlspecialchars($settings['company_phone']) ?></p><?php endif; ?>
                    <?php if (!empty($settings['company_email'])): ?><p class="mb-0"><i class="bi bi-envelope-fill me-2"></i><a href="mailto:<?= htmlspecialchars($settings['company_email']) ?>" class="text-white"><?= htmlspecialchars($settings['company_email']) ?></a></p><?php endif; ?>
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
        const observer = new IntersectionObserver(entries => { entries.forEach(e => { if(e.isIntersecting) e.target.classList.add('visible'); }); }, {threshold:0.1,rootMargin:'0px 0px -50px 0px'});
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
        
        function animateCounter(el) { const target = parseInt(el.dataset.target), step = target / 125; let current = 0; const update = () => { current += step; if(current < target) { el.textContent = Math.floor(current); requestAnimationFrame(update); } else el.textContent = target; }; update(); }
        const statsObserver = new IntersectionObserver(entries => { entries.forEach(e => { if(e.isIntersecting) { const c = e.target.querySelector('.stat-number'); if(c && !c.classList.contains('counted')) { c.classList.add('counted'); animateCounter(c); } } }); }, {threshold:0.5});
        document.querySelectorAll('.stat-item').forEach(el => statsObserver.observe(el));
    </script>
</body>
</html>