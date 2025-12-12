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

// Function to flatten settings
function flatten_to_string($val) {
    if (is_array($val)) {
        $flattened = [];
        foreach ($val as $v) {
            $flattened[] = flatten_to_string($v);
        }
        return implode(' ', $flattened);
    } elseif (is_string($val)) {
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return flatten_to_string($decoded);
        }
        $unserialized = @unserialize($val);
        if ($unserialized !== false && is_array($unserialized)) {
            return flatten_to_string($unserialized);
        }
        return $val;
    } else {
        return $val === null ? '' : (string)$val;
    }
}

foreach ($settings as $key => $val) {
    $settings[$key] = flatten_to_string($val);
}

$value = $settings['favicon'] ?? '';
$settings['favicon'] = is_array($value) ? (string)($value[0] ?? '') : (string)$value;

$value = $settings['site_logo'] ?? '';
$settings['site_logo'] = is_array($value) ? (string)($value[0] ?? '') : (string)$value;

// Logged-in state
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;

// Parse business hours
$businessHours = [];
try {
    $businessHoursRaw = $settings['business_hours'] ?? '{}';
    if (is_string($businessHoursRaw)) {
        $businessHours = json_decode($businessHoursRaw, true) ?: [];
    } elseif (is_array($businessHoursRaw)) {
        $businessHours = $businessHoursRaw;
    }
} catch (Exception $e) {
    $businessHours = [];
}

// Default business hours if not set
if (empty($businessHours)) {
    $businessHours = [
        'Monday' => '9:00 AM - 5:00 PM',
        'Tuesday' => '9:00 AM - 5:00 PM',
        'Wednesday' => '9:00 AM - 5:00 PM',
        'Thursday' => '9:00 AM - 5:00 PM',
        'Friday' => '9:00 AM - 5:00 PM',
        'Saturday' => '9:00 AM - 5:00 PM',
        'Sunday' => 'Closed'
    ];
}

// Contact form handling
$contactMessage = '';
$contactError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validate
    $errors = [];
    if (empty($name)) $errors[] = 'Name is required';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
    if (empty($subject)) $errors[] = 'Subject is required';
    if (empty($message)) $errors[] = 'Message is required';

    if (empty($errors)) {
// Insert contact inquiry into database
try {
    $stmt = $pdo->prepare("INSERT INTO contact_inquiries (name, email, phone, subject, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$name, $email, $phone, $subject, $message]);
    
    // Send email notification to admin
    require_once 'includes/Mailer.php';
    $mailer = new Mailer();
    
    $inquiryData = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'message' => $message,
        'created_at' => date('F j, Y \a\t g:i A')
    ];
    
    $emailSent = $mailer->sendContactInquiry($inquiryData);
    
    if ($emailSent) {
        $contactMessage = 'Thank you for your message! We will get back to you shortly.';
    } else {
        $contactMessage = 'Your message was saved but email notification failed. We will still respond soon.';
    }
    
} catch (Exception $e) {
    $contactError = 'There was an error sending your message. Please try again.';
    error_log("Contact form error: " . $e->getMessage());
}
    } else {
        $contactError = implode('<br>', $errors);
    }
}

// Company contact info
$company_name = $settings['company_name'] ?? 'Soreta Electronics';
$company_address = $settings['company_address'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_email = $settings['company_email'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - <?= htmlspecialchars($company_name) ?></title>
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

        html { scroll-behavior: smooth; }

        /* Dark background for entire page */
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            color: #f1f5f9;
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

        /* Hero Section */
        .hero-section {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 70%, black) 100%);
            overflow: hidden;
            padding: 4rem 0 3rem 0;
            margin-bottom: 3rem;
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

        .hero-heading, .hero-lead {
            color: #ffffff;
            text-shadow: 0 6px 18px rgba(0,0,0,0.6);
        }

        /* Contact Info Card - Glassmorphism */
        .contact-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .contact-card::before {
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

        .contact-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        }

        .contact-card:hover::before {
            opacity: 1;
        }

        .contact-card > * {
            position: relative;
            z-index: 1;
        }

        .contact-icon {
            width: 56px;
            height: 56px;
            background: rgba(37, 99, 235, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: var(--primary);
            margin: 0 auto 1.5rem;
            transition: transform 0.3s ease;
        }

        .contact-card:hover .contact-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .contact-label {
            font-size: 0.875rem;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .contact-value {
            font-size: 1.125rem;
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 0;
        }

        .contact-value a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .contact-value a:hover {
            color: color-mix(in srgb, var(--primary) 80%, #fff);
        }

        /* Contact Form Section - Glassmorphism */
        .contact-form-section {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        .section-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.75rem;
        }

        .form-label {
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 0.75rem;
            display: block;
        }

        .form-control, .form-textarea {
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 0.875rem 1.25rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        .form-control::placeholder,
        .form-textarea::placeholder {
            color: #94a3b8;
        }

        .form-control:focus, .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
            outline: none;
            background: rgba(255, 255, 255, 0.15);
            color: #f1f5f9;
        }

        .form-textarea {
            resize: vertical;
            min-height: 150px;
            font-family: inherit;
        }

        .required {
            color: #ef4444;
        }

        /* Alert messages */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert .btn-close {
            filter: invert(1);
        }

        /* Business Hours Card - Glassmorphism */
        .hours-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        .hours-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .hours-title i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .hour-item {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hour-item:last-child {
            border-bottom: none;
        }

        .hour-day {
            font-weight: 600;
            color: #f1f5f9;
        }

        .hour-time {
            color: #94a3b8;
            font-weight: 500;
        }

        /* Submit Button */
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.875rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-submit:hover {
            background: color-mix(in srgb, var(--primary) 90%, #000);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            background: color-mix(in srgb, var(--primary) 90%, #000);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-weight: 600;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
        }

        @media (max-width: 768px) {
            .hero-heading {
                font-size: 2rem;
            }

            .contact-form-section {
                padding: 2rem;
            }

            .contact-card {
                padding: 1.5rem;
            }
        }

        <?= $settings['custom_css'] ?? '' ?>
    </style>
    <?php include 'includes/mega-navbar-css.php'; ?>
</head>
<body>
    <?php include 'includes/mega-navbar.php'; ?>

    <!-- Hero Section -->
    <section class="hero-section text-white fade-in">
        <div class="container hero-content">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3 hero-heading">
                        <i class="bi bi-chat-left-quote me-3"></i>Get in Touch
                    </h1>
                    <p class="lead mb-0 hero-lead">
                        Have questions? We'd love to hear from you. Reach out to us anytime!
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Information Cards -->
    <section class="py-5">
        <div class="container">
            <div class="row g-4 mb-5">
                <?php if (!empty($company_address)): ?>
                    <div class="col-md-6 col-lg-3 fade-in">
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <div class="contact-label">Location</div>
                            <div class="contact-value"><?= nl2br(htmlspecialchars($company_address)) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($company_phone)): ?>
                    <div class="col-md-6 col-lg-3 fade-in">
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="bi bi-telephone-fill"></i>
                            </div>
                            <div class="contact-label">Phone</div>
                            <div class="contact-value">
                                <a href="tel:<?= preg_replace('/[^0-9]/', '', $company_phone) ?>">
                                    <?= htmlspecialchars($company_phone) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($company_email)): ?>
                    <div class="col-md-6 col-lg-3 fade-in">
                        <div class="contact-card">
                            <div class="contact-icon">
                                <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div class="contact-label">Email</div>
                            <div class="contact-value">
                                <a href="mailto:<?= htmlspecialchars($company_email) ?>">
                                    <?= htmlspecialchars($company_email) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-md-6 col-lg-3 fade-in">
                    <div class="contact-card">
                        <div class="contact-icon">
                            <i class="bi bi-clock-fill"></i>
                        </div>
                        <div class="contact-label">Response Time</div>
                        <div class="contact-value">Within Opening Hours</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Contact Section -->
    <section class="py-5">
        <div class="container">
            <div class="row g-4">
                <!-- Contact Form -->
                <div class="col-lg-8 fade-in">
                    <div class="contact-form-section">
                        <h3 class="section-title mb-4"><i class="bi bi-send me-2" style="color: var(--primary);"></i>Send us a Message</h3>

                        <?php if (!empty($contactMessage)): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle me-2"></i>
                                <?= htmlspecialchars($contactMessage) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($contactError)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-circle me-2"></i>
                                <?= $contactError ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            Your Name
                                            <span class="required">*</span>
                                        </label>
                                        <input type="text" name="name" class="form-control" placeholder="John Doe" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            Email Address
                                            <span class="required">*</span>
                                        </label>
                                        <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Phone Number</label>
                                        <input type="tel" name="phone" class="form-control" placeholder="(555) 123-4567">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            Subject
                                            <span class="required">*</span>
                                        </label>
                                        <input type="text" name="subject" class="form-control" placeholder="How can we help?" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    Message
                                    <span class="required">*</span>
                                </label>
                                <textarea name="message" class="form-textarea form-control" placeholder="Tell us more about your inquiry..." required></textarea>
                            </div>

                            <button type="submit" class="btn-submit">
                                <i class="bi bi-send me-2"></i>Send Message
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Business Hours -->
                <div class="col-lg-4 fade-in">
                    <div class="hours-card">
                        <div class="hours-title">
                            <i class="bi bi-calendar-event"></i>
                            Opening Hours
                        </div>
                        <div class="hours-list">
                            <?php foreach ($businessHours as $day => $hours): ?>
                                <div class="hour-item">
                                    <span class="hour-day"><?= htmlspecialchars($day) ?></span>
                                    <span class="hour-time"><?= htmlspecialchars($hours) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="hours-card mt-4">
                        <div class="hours-title">
                            <i class="bi bi-lightning-fill"></i>
                            Quick Actions
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <a href="<?= $isLoggedIn && $userRole === 'customer' ? 'customer/book-appointment.php' : 'auth/register.php' ?>" 
                               class="btn btn-primary btn-sm">
                                <i class="bi bi-calendar-check me-2"></i>Book Service
                            </a>
                            <a href="services.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-tools me-2"></i>View Services
                            </a>
                            <a href="about.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-building me-2"></i>About Us
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <section id="contact-footer" class="py-5 bg-dark text-white mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($company_name) ?></h5>
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
                    <p class="text-white"><?= htmlspecialchars($settings['footer_text'] ?? '&copy; ' . date('Y') . ' ' . htmlspecialchars($company_name)) ?></p>
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
    <?php include 'includes/mega-navbar-js.php'; ?>

    <script>
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
                    const offset = 96;
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