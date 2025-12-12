<?php
require_once 'includes/config.php';

$db = new Database();
$pdo = $db->getConnection();

// Get company settings (schema-agnostic: supports setting_key/setting_value or key/value)
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

// Function to recursively flatten values to strings
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

// Sanitize all settings
foreach ($settings as $key => $val) {
    $settings[$key] = flatten_to_string($val);
}

$value = $settings['favicon'] ?? '';
$settings['favicon'] = is_array($value) ? (string)($value[0] ?? '') : (string)$value;

$value = $settings['site_logo'] ?? '';
$settings['site_logo'] = is_array($value) ? (string)($value[0] ?? '') : (string)$value;

$value = $settings['hero_image'] ?? '';
$settings['hero_image'] = is_array($value) ? (string)($value[0] ?? '') : (string)$value;

// Parse JSON settings
$servicesRaw = $settings['services'] ?? '[]';
if (is_string($servicesRaw)) {
    $services = json_decode($servicesRaw, true) ?: [];
} elseif (is_array($servicesRaw)) {
    $services = $servicesRaw;
} else {
    $services = [];
}
$services = array_map(function($s) {
    return is_string($s) ? $s : (is_array($s) ? implode(', ', $s) : strval($s));
}, $services);
$business_hours = json_decode($settings['business_hours'] ?? '{}', true) ?: [];

// Parse announcements
$announcements = [];
try {
    $stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY display_order ASC, date DESC, created_at DESC LIMIT 6");
    $announcements = $stmt->fetchAll();
} catch (Exception $e) {
    $announcementsRaw = $settings['announcements'] ?? '[]';
    if (is_string($announcementsRaw)) {
        $announcements = json_decode($announcementsRaw, true) ?: [];
    } elseif (is_array($announcementsRaw)) {
        $announcements = $announcementsRaw;
    } else {
        $announcements = [];
    }
}

// Public-facing settings
$company_about = $settings['company_about'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';
$company_email = $settings['company_email'] ?? '';

// Logged-in state
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['user_role'] ?? null;

// Upcoming appointment summary for logged-in customers
$upcomingAppointmentsCount = 0;
$nextAppointment = null;
if ($isLoggedIn && $userRole === 'customer') {
    try {
        require_once __DIR__ . '/includes/AppointmentManager.php';
        $am = new AppointmentManager($pdo);
        $appts = $am->getCustomerAppointments($_SESSION['user_id']);
        $today = date('Y-m-d');
        foreach ($appts as $a) {
            if (!empty($a['appointment_date']) && $a['appointment_date'] >= $today && $a['status'] === 'scheduled') {
                $upcomingAppointmentsCount++;
                if (!$nextAppointment || $a['appointment_date'] < $nextAppointment['appointment_date'] || ($a['appointment_date'] == $nextAppointment['appointment_date'] && $a['appointment_time'] < $nextAppointment['appointment_time'])) {
                    $nextAppointment = $a;
                }
            }
        }
    } catch (Exception $e) {}
}

// Load troubleshooting categories
$troubleshootingCategories = [];
try {
    $stmt = $pdo->query("SELECT id, title, description, image_path FROM troubleshooting_guide WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order ASC LIMIT 3");
    $troubleshootingCategories = $stmt->fetchAll();
} catch (Exception $e) {}

// Load recent feedback
$recentFeedback = [];
try {
    $stmt = $pdo->query("SELECT f.rating, f.comment, f.created_at, f.anonymous, u.name as customer_name, tg.title as guide_title
        FROM troubleshooting_feedback f
        JOIN users u ON f.user_id = u.id
        LEFT JOIN troubleshooting_guide tg ON f.troubleshooting_guide_id = tg.id
        ORDER BY f.created_at DESC LIMIT 6");
    $recentFeedback = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['seo_meta_title'] ?? ($settings['company_name'] ?? 'Soreta Electronics')) ?></title>
    <?php if (!empty($settings['seo_meta_description'])): ?>
        <meta name="description" content="<?= htmlspecialchars($settings['seo_meta_description']) ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css" rel="stylesheet">
    <link href="assets/css/main.css" rel="stylesheet">
    <?php $favicon = $settings['favicon'];
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

        /* ========== ANNOUNCEMENT BAR ========== */
        .announcement-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1101;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .announcement-bar.dismissed { transform: translateY(-100%); opacity: 0; pointer-events: none; }
        .announcement-bar-inner { flex: 1; max-width: 1200px; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .announcement-marquee { display: flex; overflow: hidden; width: 100%; }
        .announcement-marquee-content {
            display: flex; align-items: center; gap: 2rem; padding-right: 2rem;
            animation: marquee 30s linear infinite; white-space: nowrap;
        }
        .announcement-marquee:hover .announcement-marquee-content { animation-play-state: paused; }
        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }
        .announcement-item {
            display: inline-flex; align-items: center; gap: 0.4rem;
            text-decoration: none; color: #93c5fd; font-size: 0.85rem; font-weight: 500;
            transition: color 0.2s ease;
        }
        .announcement-item:hover { color: #bfdbfe; text-decoration: underline; }
        .announcement-dot { width: 6px; height: 6px; background: var(--primary); border-radius: 50%; flex-shrink: 0; }
        .announcement-separator { color: #475569; font-size: 0.75rem; }
        .announcement-static {
            display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; color: #93c5fd; font-size: 0.875rem; font-weight: 500;
        }
        .announcement-static:hover { color: #bfdbfe; }
        .announcement-static:hover .announcement-text { text-decoration: underline; }
        .announcement-badge {
            background: var(--primary); color: white; padding: 0.15rem 0.5rem;
            border-radius: 10px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
        }
        .announcement-static i { font-size: 1.1rem; transition: transform 0.2s ease; }
        .announcement-static:hover i { transform: translateX(2px); }
        .announcement-close {
            position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%);
            background: transparent; border: none; color: #94a3b8; cursor: pointer;
            padding: 0.25rem; display: flex; align-items: center; justify-content: center;
            border-radius: 4px; font-size: 1.1rem; transition: all 0.2s ease;
        }
        .announcement-close:hover { background: rgba(255,255,255,0.1); color: #bfdbfe; }
        @media (max-width: 768px) {
            .announcement-bar { padding: 0.4rem 0.75rem; min-height: 36px; }
            .announcement-static, .announcement-item { font-size: 0.8rem; }
            .announcement-marquee-content { gap: 1.5rem; animation-duration: 25s; }
        }

        /* ========== FLOATING MEGA MENU NAVBAR ========== */
        .mega-navbar {
            position: fixed;
            top: 52px;
            left: 1rem;
            right: 1rem;
            z-index: 1100;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            transition: all 0.25s ease;
        }
        .mega-navbar.no-announcement {
            top: 12px;
        }
        .mega-navbar.scrolled {
            background: rgba(255,255,255,0.98);
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        }
        @media (min-width: 1440px) {
            .mega-navbar {
                left: 50%;
                right: auto;
                transform: translateX(-50%);
                width: calc(100% - 2rem);
                max-width: 1400px;
            }
        }
        .mega-navbar-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 72px;
        }
        .mega-navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }
        .mega-navbar-brand img { 
            height: 40px; 
            width: 40px; 
            border-radius: 50%; 
            object-fit: cover;
            border: 2px solid rgba(0,0,0,0.08);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mega-navbar-brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .mega-navbar-brand-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1a1a2e;
            letter-spacing: -0.02em;
        }
        .mega-navbar-nav {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .mega-navbar-nav > li { position: relative; }
        .mega-nav-link {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.625rem 1rem;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #1a1a2e;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .mega-nav-link:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .mega-nav-link.active { color: var(--primary); }
        .mega-nav-link .chevron { font-size: 0.75rem; transition: transform 0.15s ease; opacity: 0.6; }
        .mega-navbar-nav > li:hover .mega-nav-link .chevron { transform: rotate(180deg); }
        .mega-dropdown {
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(10px);
            min-width: 280px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
            padding: 1.25rem;
            opacity: 0;
            visibility: hidden;
            transition: all 0.25s ease;
            z-index: 1000;
        }
        .mega-navbar-nav > li:hover .mega-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }
        .mega-dropdown.mega-dropdown-wide {
            min-width: 580px;
            left: 0;
            transform: translateY(10px);
        }
        .mega-navbar-nav > li:hover .mega-dropdown.mega-dropdown-wide { transform: translateY(0); }
        .mega-menu-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
        .mega-menu-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .mega-menu-item:hover { background: rgba(37,99,235,0.06); }
        .mega-menu-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            transition: transform 0.15s ease;
        }
        .mega-menu-item:hover .mega-menu-icon { transform: scale(1.05); }
        .mega-menu-icon.icon-blue { background: rgba(37,99,235,0.1); color: #2563eb; }
        .mega-menu-icon.icon-green { background: rgba(16,185,129,0.1); color: #10b981; }
        .mega-menu-icon.icon-purple { background: rgba(139,92,246,0.1); color: #8b5cf6; }
        .mega-menu-icon.icon-orange { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .mega-menu-content { flex: 1; min-width: 0; }
        .mega-menu-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 0.25rem;
        }
        .mega-menu-desc { font-size: 0.8125rem; color: #64748b; line-height: 1.5; }
        .mega-menu-section { padding: 0.75rem 0; }
        .mega-menu-section:first-child { padding-top: 0; }
        .mega-menu-section:last-child { padding-bottom: 0; }
        .mega-menu-section + .mega-menu-section { border-top: 1px solid #e2e8f0; }
        .mega-navbar-actions { display: flex; align-items: center; gap: 0.75rem; }
        .mega-nav-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .mega-nav-btn-ghost { color: #1a1a2e; background: transparent; border: none; }
        .mega-nav-btn-ghost:hover { color: var(--primary); background: rgba(37,99,235,0.06); }
        .mega-nav-btn-primary {
            color: #ffffff;
            background: var(--primary);
            border: none;
            box-shadow: 0 2px 8px rgba(37,99,235,0.25);
        }
        .mega-nav-btn-primary:hover {
            background: color-mix(in srgb, var(--primary) 90%, #000);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.35);
            color: #ffffff;
        }
        .mega-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.15s ease;
            text-transform: uppercase;
        }
        .mega-user-avatar:hover { border-color: var(--primary); transform: scale(1.05); }
        .mega-user-wrapper { position: relative; }
        .mega-user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 240px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.25s ease;
            z-index: 1000;
            overflow: hidden;
        }
        .mega-user-wrapper:hover .mega-user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .mega-user-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(124,58,237,0.05));
            border-bottom: 1px solid #e2e8f0;
        }
        .mega-user-name { font-weight: 700; color: #1a1a2e; font-size: 1rem; margin-bottom: 0.25rem; }
        .mega-user-email { font-size: 0.8125rem; color: #64748b; }
        .mega-user-menu { padding: 0.5rem; }
        .mega-user-menu-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #1a1a2e;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
        }
        .mega-user-menu-item:hover { background: rgba(37,99,235,0.06); color: var(--primary); }
        .mega-user-menu-item i { font-size: 1.125rem; color: #64748b; width: 20px; text-align: center; }
        .mega-user-menu-item:hover i { color: var(--primary); }
        .mega-user-menu-divider { height: 1px; background: #e2e8f0; margin: 0.5rem 0; }
        .mega-mobile-toggle {
            display: none;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            color: #1a1a2e;
            transition: all 0.15s ease;
        }
        .mega-mobile-toggle:hover { background: rgba(37,99,235,0.06); }
        .mega-mobile-toggle i { font-size: 1.5rem; }
        .mega-mobile-nav {
            display: none;
            position: fixed;
            top: 72px;
            left: 0;
            right: 0;
            bottom: 0;
            background: #ffffff;
            z-index: 1099;
            overflow-y: auto;
            padding: 1rem;
            transform: translateX(100%);
            transition: transform 0.25s ease;
        }
        .mega-mobile-nav.open { transform: translateX(0); }
        .mega-mobile-section { padding: 1rem 0; border-bottom: 1px solid #e2e8f0; }
        .mega-mobile-section:last-child { border-bottom: none; }
        .mega-mobile-section-title {
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            padding: 0.5rem 0;
            margin-bottom: 0.5rem;
        }
        .mega-mobile-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 0.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: #1a1a2e;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
        }
        .mega-mobile-link:hover, .mega-mobile-link.active { color: var(--primary); background: rgba(37,99,235,0.06); }
        .mega-mobile-link i { font-size: 1.25rem; width: 24px; text-align: center; color: #64748b; }
        .mega-mobile-link:hover i, .mega-mobile-link.active i { color: var(--primary); }
        .mega-mobile-actions { display: flex; flex-direction: column; gap: 0.75rem; padding-top: 1rem; }
        .mega-mobile-actions .mega-nav-btn { justify-content: center; padding: 0.875rem 1.5rem; }
        .mega-nav-btn-outline { color: #1a1a2e; background: transparent; border: 1.5px solid #e2e8f0; }
        .mega-nav-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .mega-navbar-spacer { height: 136px; }
        .mega-navbar-spacer.no-announcement { height: 96px; }
        @media (max-width: 1024px) {
            .mega-navbar { left: 0.75rem; right: 0.75rem; }
            .mega-navbar-nav { display: none; }
            .mega-mobile-toggle { display: flex; }
            .mega-mobile-nav { display: block; top: 136px; }
            .mega-mobile-nav.no-announcement { top: 96px; }
            .mega-navbar-actions .mega-nav-btn-ghost { display: none; }
        }
        @media (max-width: 640px) {
            .mega-navbar { left: 0.5rem; right: 0.5rem; top: 48px; border-radius: 12px; }
            .mega-navbar.no-announcement { top: 8px; }
            .mega-navbar-inner { padding: 0 1rem; height: 56px; }
            .mega-navbar-brand-text { font-size: 1.125rem; }
            .mega-navbar-spacer { height: 116px; }
            .mega-navbar-spacer.no-announcement { height: 76px; }
            .mega-mobile-nav { top: 116px; }
            .mega-mobile-nav.no-announcement { top: 76px; }
        }
        @media (prefers-color-scheme: dark) {
            .mega-navbar { background: #0f172a; border-bottom-color: #334155; }
            .mega-navbar.scrolled { background: rgba(15,23,42,0.98); }
            .mega-navbar-brand-text, .mega-nav-link, .mega-mobile-link { color: #f1f5f9; }
            .mega-nav-link:hover, .mega-mobile-link:hover { color: var(--primary); }
            .mega-dropdown, .mega-user-dropdown, .mega-mobile-nav { background: #1e293b; border-color: #334155; }
            .mega-menu-title, .mega-user-name { color: #f1f5f9; }
            .mega-menu-desc, .mega-user-email { color: #94a3b8; }
            .mega-mobile-toggle { color: #f1f5f9; }
            .mega-nav-btn-ghost { color: #f1f5f9; }
            .mega-nav-btn-outline { color: #f1f5f9; border-color: #334155; }
        }
        /* ========== END MEGA MENU ========== */

        .fade-in { opacity: 0; transform: translateY(30px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .fade-in.visible { opacity: 1; transform: translateY(0); }

        .service-card { transition: all 0.3s ease; position: relative; overflow: hidden; }
        .service-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, var(--primary-light), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 0;
        }
        .service-card:hover { transform: translateY(-8px); box-shadow: 0 12px 40px rgba(0,0,0,0.15) !important; }
        .service-card:hover::before { opacity: 1; }
        .service-card .card-body { position: relative; z-index: 1; }
        .service-icon { font-size: 3rem; color: var(--primary); margin-bottom: 1rem; transition: transform 0.3s ease; }
        .service-card:hover .service-icon { transform: scale(1.1) rotate(5deg); }

        .testimonial-carousel { position: relative; overflow: hidden; }
        .testimonial-track { display: flex; transition: transform 0.5s ease; }
        .testimonial-item { min-width: 100%; padding: 0 15px; }
        @media (min-width: 768px) { .testimonial-item { min-width: 50%; } }
        @media (min-width: 992px) { .testimonial-item { min-width: 33.333%; } }
        .testimonial-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            min-height: 200px;
        }
        .testimonial-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }

        /* Added testimonial text contrast fixes */
        .testimonial-card p {
            color: #1f2937; /* Dark gray for the comment text */
            font-size: 1rem;
            line-height: 1.6;
        }

        .testimonial-card h6 {
            color: #111827; /* Nearly black for the customer name */
            font-weight: 600;
            font-size: 1rem;
        }

        .testimonial-card .text-muted {
            color: #6b7280 !important; /* Medium gray for the "On: Guide Title" text */
            font-size: 0.875rem;
        }

        /* Ensure star icons have proper color */
        .testimonial-card .bi-star-fill,
        .testimonial-card .bi-star {
            color: #fbbf24; /* Amber/gold for stars */
        }

        /* Dark mode support (optional) */
        @media (prefers-color-scheme: dark) {
            .testimonial-card {
                background: #1e293b;
                border: 1px solid #334155;
            }
            
            .testimonial-card p {
                color: #e5e7eb;
            }
            
            .testimonial-card h6 {
                color: #f9fafb;
            }
            
            .testimonial-card .text-muted {
                color: #9ca3af !important;
            }
        }
        .carousel-nav { text-align: center; margin-top: 2rem; }
        .carousel-dot {
            display: inline-block;
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #ddd;
            margin: 0 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .carousel-dot.active { background: var(--primary); width: 30px; border-radius: 5px; }

        .stats-section {
            background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 80%, var(--secondary)));
            color: white;
            padding: 3rem 0;
        }
        .stat-item { text-align: center; }
        .stat-number { font-size: 3rem; font-weight: bold; margin-bottom: 0.5rem; }
        .stat-label { font-size: 1rem; opacity: 0.9; }

        .floating-cta {
            position: fixed;
            bottom: 30px; right: 30px;
            z-index: 1000;
            opacity: 0;
            transform: translateY(100px);
            transition: all 0.3s ease;
        }
        .floating-cta.visible { opacity: 1; transform: translateY(0); }
        .floating-cta .btn {
            padding: 15px 25px;
            border-radius: 50px;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(37,99,235,0.4);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 8px 25px rgba(37,99,235,0.4); }
            50% { box-shadow: 0 8px 35px rgba(37,99,235,0.6); }
        }

        .hero-section {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, color-mix(in srgb, var(--primary) 70%, black) 100%);
            overflow: hidden;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            pointer-events: none;
        }
        .hero-heading, .hero-lead {
            color: #ffffff;
            text-shadow: 0 6px 18px rgba(0,0,0,0.6);
        }
        .hero-content { position: relative; z-index: 2; }
        .hero-cta { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        @media (max-width: 575.98px) {
            .hero-cta { flex-direction: column; gap: 0.75rem; }
            .hero-cta .btn { width: 100%; }
            .hero-heading { font-size: 1.6rem; }
            .hero-lead { font-size: 0.95rem; }
        }
        .hero-section .btn.btn-dark {
            background-color: #000 !important;
            color: #fff !important;
            box-shadow: 0 6px 18px rgba(0,0,0,0.25);
        }
        .hero-section .btn.btn-light {
            background-color: #fff !important;
            color: #000 !important;
            box-shadow: 0 6px 18px rgba(0,0,0,0.15);
        }

        .wave-separator { position: relative; height: 80px; background: white; }
        .wave-separator svg { position: absolute; top: -79px; left: 0; width: 100%; height: 80px; }

        .service-details { list-style: none; padding: 0; margin: 0; text-align: left; }
        .service-details li { padding: 0.35rem 0; font-size: 0.9rem; }
        @media (max-width: 767px) {
            .service-icon { font-size: 2.25rem; margin-bottom: 0.75rem; }
            .service-card .card-body { padding: 1.25rem; }
            .service-card .card-title { font-size: 1.1rem; margin-bottom: 0.5rem; }
            .service-card .card-text { font-size: 0.85rem; margin-bottom: 0.75rem; }
            .service-details { display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
            .service-details.show { display: block; }
            .service-toggle-btn {
                display: inline-flex; align-items: center; gap: 0.5rem;
                background: none; border: 1px solid var(--primary); color: var(--primary);
                padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.8rem;
                cursor: pointer; margin-top: 0.5rem;
            }
        }
        @media (min-width: 768px) { .service-toggle-btn { display: none; } }

        .featured-guide-card {
            display: grid; grid-template-columns: 1fr 1fr;
            background: white; border-radius: 16px; overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1); transition: all 0.3s ease;
        }
        .featured-guide-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(0,0,0,0.15); }
        .featured-image { position: relative; min-height: 300px; overflow: hidden; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; }
        .featured-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .featured-guide-card:hover .featured-image img { transform: scale(1.05); }
        .featured-placeholder {
            width: 100%; height: 100%; min-height: 300px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex; align-items: center; justify-content: center;
        }
        .featured-placeholder i { font-size: 4rem; color: white; opacity: 0.5; }
        .featured-content { padding: 2.5rem; display: flex; flex-direction: column; justify-content: center; }
        .featured-badge {
            display: inline-block; background: var(--primary); color: white;
            padding: 0.4rem 1rem; border-radius: 20px; font-size: 0.75rem;
            font-weight: 600; width: fit-content; margin-bottom: 1rem;
        }
        .featured-title { font-size: 1.75rem; font-weight: 700; color: #1f2937; margin-bottom: 1rem; }
        .featured-description { font-size: 1rem; color: #6b7280; margin-bottom: 1.5rem; }
        @media (max-width: 991px) {
            .featured-guide-card { grid-template-columns: 1fr; }
            .featured-image { min-height: 220px; }
            .featured-content { padding: 2rem; }
        }
        @media (max-width: 575px) {
            .featured-image, .featured-placeholder { min-height: 180px; }
            .featured-content { padding: 1.5rem; }
            .featured-title { font-size: 1.25rem; }
        }

        .guides-list-container { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 16px; padding: 2rem; }
        .guides-list-title { font-size: 1.1rem; font-weight: 600; color: #1f2937; }
        .guides-list { display: flex; flex-direction: column; gap: 0.75rem; }
        .guide-list-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 1.25rem; background: white; border: 1px solid #e5e7eb;
            border-radius: 12px; text-decoration: none; transition: all 0.2s ease;
        }
        .guide-list-item:hover { background: #f8fafc; border-color: var(--primary); transform: translateX(4px); }
        .guide-item-icon {
            flex-shrink: 0; width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.25rem;
        }
        .guide-item-content { flex: 1; min-width: 0; }
        .guide-item-title { font-size: 1rem; font-weight: 600; color: #1f2937; margin: 0 0 0.25rem 0; }
        .guide-item-desc { font-size: 0.875rem; color: #6b7280; margin: 0; }
        .guide-item-arrow { color: var(--primary); font-size: 1.2rem; transition: transform 0.2s ease; }
        .guide-list-item:hover .guide-item-arrow { transform: translateX(4px); }

        <?= $settings['custom_css'] ?? '' ?>
    </style>
</head>
<body>
    <!-- ANNOUNCEMENT BAR -->
    <?php if (!empty($announcements)):
        $latestAnnouncement = $announcements[0];
        $enableMarquee = count($announcements) > 1;
    ?>
    <div class="announcement-bar" id="announcementBar">
        <div class="announcement-bar-inner">
            <?php if ($enableMarquee): ?>
            <div class="announcement-marquee">
                <div class="announcement-marquee-content">
                    <?php foreach ($announcements as $idx => $ann): ?>
                        <a href="announcements.php?id=<?= $ann['id'] ?>" class="announcement-item">
                            <span class="announcement-dot"></span>
                            <span class="announcement-text"><?= htmlspecialchars($ann['title']) ?></span>
                        </a>
                        <?php if ($idx < count($announcements) - 1): ?>
                            <span class="announcement-separator">•</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="announcement-marquee-content" aria-hidden="true">
                    <?php foreach ($announcements as $idx => $ann): ?>
                        <a href="announcements.php?id=<?= $ann['id'] ?>" class="announcement-item">
                            <span class="announcement-dot"></span>
                            <span class="announcement-text"><?= htmlspecialchars($ann['title']) ?></span>
                        </a>
                        <?php if ($idx < count($announcements) - 1): ?>
                            <span class="announcement-separator">•</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <a href="announcements.php?id=<?= $latestAnnouncement['id'] ?>" class="announcement-static">
                <span class="announcement-badge">New</span>
                <span class="announcement-text"><?= htmlspecialchars($latestAnnouncement['title']) ?></span>
                <i class="bi bi-arrow-right-short"></i>
            </a>
            <?php endif; ?>
        </div>
        <button class="announcement-close" onclick="dismissAnnouncementBar()" aria-label="Dismiss">
            <i class="bi bi-x"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- MEGA MENU NAVBAR -->
    <nav class="mega-navbar" id="megaNavbar">
        <div class="mega-navbar-inner">
            <a href="index.php" class="mega-navbar-brand">
                <?php $logo = $settings['site_logo'];
                if (!empty($logo) && is_string($logo)): ?>
                    <img src="<?= htmlspecialchars($logo[0] === '/' ? $logo : ROOT_PATH . $logo) ?>" alt="<?= htmlspecialchars($settings['company_name'] ?? 'Soreta') ?>">
                <?php else: ?>
                    <div class="mega-navbar-brand-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                <?php endif; ?>
                <span class="mega-navbar-brand-text"><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></span>
            </a>

            <ul class="mega-navbar-nav">
                <li><a href="index.php" class="mega-nav-link active">Home</a></li>
                <li>
                    <a href="services.php" class="mega-nav-link">Services <i class="bi bi-chevron-down chevron"></i></a>
                    <div class="mega-dropdown mega-dropdown-wide">
                        <div class="mega-menu-grid">
                            <a href="services.php#installation" class="mega-menu-item">
                                <div class="mega-menu-icon icon-blue"><i class="bi bi-gear-wide-connected"></i></div>
                                <div class="mega-menu-content">
                                    <div class="mega-menu-title">Installation</div>
                                    <div class="mega-menu-desc">Professional setup for automation & security systems</div>
                                </div>
                            </a>
                            <a href="services.php#repair" class="mega-menu-item">
                                <div class="mega-menu-icon icon-orange"><i class="bi bi-wrench-adjustable-circle"></i></div>
                                <div class="mega-menu-content">
                                    <div class="mega-menu-title">Repair</div>
                                    <div class="mega-menu-desc">Fast diagnosis & component-level repair</div>
                                </div>
                            </a>
                            <a href="services.php#maintenance" class="mega-menu-item">
                                <div class="mega-menu-icon icon-green"><i class="bi bi-shield-check"></i></div>
                                <div class="mega-menu-content">
                                    <div class="mega-menu-title">Maintenance</div>
                                    <div class="mega-menu-desc">Preventive care & system optimization</div>
                                </div>
                            </a>
                            <a href="troubleshooting.php" class="mega-menu-item">
                                <div class="mega-menu-icon icon-purple"><i class="bi bi-tools"></i></div>
                                <div class="mega-menu-content">
                                    <div class="mega-menu-title">DIY Troubleshooting</div>
                                    <div class="mega-menu-desc">Step-by-step guides for common issues</div>
                                </div>
                            </a>
                        </div>
                        <div class="mega-menu-section">
                            <a href="<?= $isLoggedIn && $userRole === 'customer' ? 'customer/book-appointment.php' : 'auth/register.php' ?>" class="mega-menu-item" style="background: rgba(37,99,235,0.06);">
                                <div class="mega-menu-icon icon-blue"><i class="bi bi-calendar-plus"></i></div>
                                <div class="mega-menu-content">
                                    <div class="mega-menu-title">Book Appointment <i class="bi bi-arrow-right" style="font-size:0.75rem;"></i></div>
                                    <div class="mega-menu-desc">Schedule a service with our expert technicians</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </li>
                <li><a href="about.php" class="mega-nav-link">About</a></li>
                <li><a href="contact.php" class="mega-nav-link">Contact</a></li>
                <li><a href="troubleshooting.php" class="mega-nav-link">Troubleshooting</a></li>
                <?php if ($isLoggedIn && $userRole === 'customer'): ?>
                    <li><a href="customer/dashboard.php" class="mega-nav-link">Dashboard</a></li>
                <?php elseif ($isLoggedIn && $userRole === 'admin'): ?>
                    <li><a href="admin/dashboard.php" class="mega-nav-link">Admin</a></li>
                <?php endif; ?>
            </ul>

            <div class="mega-navbar-actions">
                <?php if ($isLoggedIn): ?>
                    <div class="mega-user-wrapper">
                        <div class="mega-user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 2)) ?></div>
                        <div class="mega-user-dropdown">
                            <div class="mega-user-header">
                                <div class="mega-user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></div>
                                <div class="mega-user-email"><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?></div>
                            </div>
                            <div class="mega-user-menu">
                                <?php if ($userRole === 'customer'): ?>
                                    <a href="customer/dashboard.php" class="mega-user-menu-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
                                    <a href="customer/profile.php" class="mega-user-menu-item"><i class="bi bi-person"></i><span>My Profile</span></a>
                                    <a href="customer/book-appointment.php" class="mega-user-menu-item"><i class="bi bi-calendar-plus"></i><span>Book Appointment</span></a>
                                <?php elseif ($userRole === 'admin'): ?>
                                    <a href="admin/dashboard.php" class="mega-user-menu-item"><i class="bi bi-speedometer2"></i><span>Admin Dashboard</span></a>
                                <?php endif; ?>
                                <div class="mega-user-menu-divider"></div>
                                <a href="auth/logout.php" class="mega-user-menu-item"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="auth/login.php" class="mega-nav-btn mega-nav-btn-ghost">Login</a>
                    <a href="auth/register.php" class="mega-nav-btn mega-nav-btn-primary">Get Started</a>
                <?php endif; ?>
                <button class="mega-mobile-toggle" type="button" onclick="toggleMobileNav()">
                    <i class="bi bi-list" id="mobileToggleIcon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Mobile Navigation -->
    <div class="mega-mobile-nav" id="megaMobileNav">
        <div class="mega-mobile-section">
            <div class="mega-mobile-section-title">Navigation</div>
            <a href="index.php" class="mega-mobile-link active"><i class="bi bi-house"></i>Home</a>
            <a href="services.php" class="mega-mobile-link"><i class="bi bi-grid"></i>Services</a>
            <a href="about.php" class="mega-mobile-link"><i class="bi bi-building"></i>About</a>
            <a href="contact.php" class="mega-mobile-link"><i class="bi bi-envelope"></i>Contact</a>
            <a href="troubleshooting.php" class="mega-mobile-link"><i class="bi bi-tools"></i>Troubleshooting</a>
        </div>
        <div class="mega-mobile-section">
            <div class="mega-mobile-section-title">Our Services</div>
            <a href="services.php#installation" class="mega-mobile-link"><i class="bi bi-gear-wide-connected"></i>Installation</a>
            <a href="services.php#repair" class="mega-mobile-link"><i class="bi bi-wrench-adjustable-circle"></i>Repair</a>
            <a href="services.php#maintenance" class="mega-mobile-link"><i class="bi bi-shield-check"></i>Maintenance</a>
        </div>
        <?php if ($isLoggedIn): ?>
            <div class="mega-mobile-section">
                <div class="mega-mobile-section-title">Account</div>
                <?php if ($userRole === 'customer'): ?>
                    <a href="customer/dashboard.php" class="mega-mobile-link"><i class="bi bi-speedometer2"></i>My Dashboard</a>
                    <a href="customer/book-appointment.php" class="mega-mobile-link"><i class="bi bi-calendar-plus"></i>Book Appointment</a>
                <?php elseif ($userRole === 'admin'): ?>
                    <a href="admin/dashboard.php" class="mega-mobile-link"><i class="bi bi-speedometer2"></i>Admin Dashboard</a>
                <?php endif; ?>
            </div>
            <div class="mega-mobile-actions">
                <a href="auth/logout.php" class="mega-nav-btn mega-nav-btn-outline"><i class="bi bi-box-arrow-right"></i>Logout</a>
            </div>
        <?php else: ?>
            <div class="mega-mobile-actions">
                <a href="auth/login.php" class="mega-nav-btn mega-nav-btn-outline">Login</a>
                <a href="auth/register.php" class="mega-nav-btn mega-nav-btn-primary">Get Started</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="mega-navbar-spacer <?= empty($announcements) ? 'no-announcement' : '' ?>" id="navbarSpacer"></div>

    <!-- HERO SECTION -->
    <?php
    $heroInlineStyle = '';
    if (!empty($settings['hero_image'])) {
        $heroPath = is_array($settings['hero_image']) ? $settings['hero_image'][0] : $settings['hero_image'];
        $heroInlineStyle = "style=\"background-image: url('" . htmlspecialchars($heroPath[0] === '/' ? $heroPath : ROOT_PATH . $heroPath) . "'); background-size: cover; background-position: center;\"";
    } else {
        $primaryColor = htmlspecialchars($settings['primary_color'] ?? '#2563eb');
        $heroInlineStyle = "style=\"background: linear-gradient(135deg, {$primaryColor} 0%, color-mix(in srgb, {$primaryColor} 70%, black) 100%);\"";
    }
    ?>
    <section class="hero-section text-white py-5" <?= $heroInlineStyle ?>>
        <div class="container hero-content" style="padding-top: 4rem; padding-bottom: 4rem;">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4 hero-heading"><?= htmlspecialchars($settings['hero_title'] ?? 'Professional Electronics Repair Services') ?></h1>
                    <p class="lead mb-4 hero-lead"><?= !empty($settings['hero_subtitle']) ? htmlspecialchars($settings['hero_subtitle']) : 'Fast, reliable, and affordable repair services for all your electronic devices.' ?></p>
                    <div class="d-flex gap-3 mt-4 hero-cta">
                        <?php if ($isLoggedIn && $userRole === 'customer'): ?>
                            <a href="customer/dashboard.php" class="btn btn-dark btn-lg">My Dashboard</a>
                            <a href="customer/book-appointment.php" class="btn btn-light btn-lg">Book Appointment</a>
                        <?php else: ?>
                            <a href="auth/register.php" class="btn btn-dark btn-lg">Book Service</a>
                            <a href="troubleshooting.php" class="btn btn-light btn-lg">DIY Guide</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="wave-separator">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320" preserveAspectRatio="none">
            <path fill="#ffffff" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,112C672,96,768,96,864,112C960,128,1056,160,1152,160C1248,160,1344,128,1392,112L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
        </svg>
    </div>

    <!-- STATS SECTION -->
    <?php $yearsExperience = !empty($settings['years_in_business']) ? intval($settings['years_in_business']) : 0; ?>
    <?php if ($yearsExperience > 0): ?>
    <section class="stats-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-4 col-12 fade-in">
                    <div class="stat-item">
                        <div class="stat-number" data-target="<?= $yearsExperience ?>">0</div>
                        <div class="stat-label">Years of Experience</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ABOUT SECTION -->
    <section id="about" class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center fade-in">
                    <h2 class="mb-4">About Us</h2>
                    <p class="lead mb-4">Committed to providing professional electronics repair services with expertise and reliability.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICES SECTION -->
    <section id="services" class="py-5 bg-light">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center fade-in">
                    <h2 class="mb-4">Our Services</h2>
                    <p class="lead mb-5">Comprehensive repair solutions for all your electronic needs</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-4 fade-in">
                    <div class="card h-100 border-0 shadow-sm service-card">
                        <div class="card-body text-center">
                            <div class="service-icon"><i class="bi bi-gear-wide-connected"></i></div>
                            <h5 class="card-title mb-3">Installation</h5>
                            <p class="card-text text-muted">Expert installation ensuring optimal setup from day one.</p>
                            <button class="service-toggle-btn" onclick="toggleServiceDetails(this)">View Details <i class="bi bi-chevron-down"></i></button>
                            <ul class="service-details">
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Home & Office Automation</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Security Systems</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>AV Systems</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 fade-in">
                    <div class="card h-100 border-0 shadow-sm service-card">
                        <div class="card-body text-center">
                            <div class="service-icon"><i class="bi bi-wrench-adjustable-circle"></i></div>
                            <h5 class="card-title mb-3">Repair</h5>
                            <p class="card-text text-muted">Fast diagnosis and repair to minimize downtime.</p>
                            <button class="service-toggle-btn" onclick="toggleServiceDetails(this)">View Details <i class="bi bi-chevron-down"></i></button>
                            <ul class="service-details">
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Diagnosis & Troubleshooting</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Component-Level Repair</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Emergency Services</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4 fade-in">
                    <div class="card h-100 border-0 shadow-sm service-card">
                        <div class="card-body text-center">
                            <div class="service-icon"><i class="bi bi-shield-check"></i></div>
                            <h5 class="card-title mb-3">Maintenance</h5>
                            <p class="card-text text-muted">Preventive care to extend equipment lifespan.</p>
                            <button class="service-toggle-btn" onclick="toggleServiceDetails(this)">View Details <i class="bi bi-chevron-down"></i></button>
                            <ul class="service-details">
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>Routine Check-ups</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>System Optimization</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i>24/7 Monitoring</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TROUBLESHOOTING PREVIEW -->
    <?php if (!empty($troubleshootingCategories)): ?>
    <section id="troubleshooting" class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center fade-in">
                    <h2 class="mb-4">Troubleshooting Preview</h2>
                    <p class="lead mb-5">Quick fixes for common electronic issues</p>
                </div>
            </div>
            <?php $featuredGuide = $troubleshootingCategories[0] ?? null; if ($featuredGuide): ?>
            <div class="row mb-4 fade-in">
                <div class="col-12 col-lg-10 mx-auto">
                    <a href="troubleshooting.php?id=<?= $featuredGuide['id'] ?>" class="text-decoration-none">
                        <div class="featured-guide-card">
                            <div class="featured-image">
                                <?php if (!empty($featuredGuide['image_path']) && file_exists($featuredGuide['image_path'])): ?>
                                    <img src="<?= htmlspecialchars($featuredGuide['image_path']) ?>" alt="<?= htmlspecialchars($featuredGuide['title']) ?>">
                                <?php else: ?>
                                    <div class="featured-placeholder"><i class="bi bi-tools"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="featured-content">
                                <div class="featured-badge">Featured Guide</div>
                                <h3 class="featured-title"><?= htmlspecialchars($featuredGuide['title']) ?></h3>
                                <p class="featured-description"><?= htmlspecialchars(substr($featuredGuide['description'], 0, 150)) ?><?= strlen($featuredGuide['description']) > 150 ? '...' : '' ?></p>
                                <span class="btn btn-primary btn-sm"><i class="bi bi-arrow-right me-2"></i>Read Guide</span>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <div class="row fade-in">
                <div class="col-12 col-lg-10 mx-auto">
                    <div class="guides-list-container">
                        <h5 class="guides-list-title mb-3"><i class="bi bi-list-ul me-2"></i>All Guides</h5>
                        <div class="guides-list">
                            <?php foreach ($troubleshootingCategories as $guide): ?>
                                <a href="troubleshooting.php?id=<?= $guide['id'] ?>" class="guide-list-item">
                                    <div class="guide-item-icon"><i class="bi bi-wrench"></i></div>
                                    <div class="guide-item-content">
                                        <h6 class="guide-item-title"><?= htmlspecialchars($guide['title']) ?></h6>
                                        <p class="guide-item-desc"><?= htmlspecialchars(substr($guide['description'], 0, 100)) ?><?= strlen($guide['description']) > 100 ? '...' : '' ?></p>
                                    </div>
                                    <div class="guide-item-arrow"><i class="bi bi-chevron-right"></i></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center mt-5 fade-in">
                <div class="col-lg-6 text-center">
                    <a href="troubleshooting.php" class="btn btn-outline-primary btn-lg"><i class="bi bi-book-half me-2"></i>View All Guides</a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- FEEDBACK/TESTIMONIALS -->
    <section id="feedback" class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center fade-in">
                    <h2 class="mb-4">What Our Customers Say</h2>
                    <p class="lead mb-5">Real feedback from customers who used our services</p>
                </div>
            </div>
            <?php if (empty($recentFeedback)): ?>
                <div class="row justify-content-center"><div class="col-lg-6 text-center text-muted"><p>No feedback yet</p></div></div>
            <?php else: ?>
                <div class="testimonial-carousel">
                    <div class="testimonial-track" id="testimonialTrack">
                        <?php foreach ($recentFeedback as $fb): ?>
                            <div class="testimonial-item">
                                <div class="testimonial-card">
                                    <div class="mb-3">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi bi-star<?= $i <= $fb['rating'] ? '-fill' : '' ?> text-warning"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="mb-4">"<?= nl2br(htmlspecialchars($fb['comment'])) ?>"</p>
                                    <hr>
                                    <div class="mt-3">
                                        <h6 class="mb-1"><?= $fb['anonymous'] ? 'Anonymous' : htmlspecialchars($fb['customer_name']) ?></h6>
                                        <p class="text-muted small mb-0">On: <?= htmlspecialchars($fb['guide_title'] ?? 'General') ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="carousel-nav" id="carouselNav"></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- FOOTER -->
    <section id="contact" class="py-5 bg-dark text-white">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?= htmlspecialchars($settings['company_name'] ?? 'Soreta Electronics') ?></h5>
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

    <!-- FLOATING CTA -->
    <?php if (!$isLoggedIn || $userRole !== 'customer'): ?>
        <div class="floating-cta" id="floatingCta">
            <a href="auth/register.php" class="btn btn-primary"><i class="bi bi-calendar-check me-2"></i>Book Now</a>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Announcement Bar Dismiss
        function dismissAnnouncementBar() {
            const bar = document.getElementById('announcementBar');
            const navbar = document.getElementById('megaNavbar');
            const spacer = document.getElementById('navbarSpacer');
            const mobileNav = document.getElementById('megaMobileNav');
            if (bar) {
                bar.classList.add('dismissed');
                navbar.classList.add('no-announcement');
                spacer.classList.add('no-announcement');
                if (mobileNav) mobileNav.classList.add('no-announcement');
                sessionStorage.setItem('announcementDismissed', 'true');
            }
        }

        // Check if announcement was previously dismissed
        document.addEventListener('DOMContentLoaded', function() {
            const bar = document.getElementById('announcementBar');
            if (bar && sessionStorage.getItem('announcementDismissed') === 'true') {
                dismissAnnouncementBar();
            }
        });

        // Mobile Nav Toggle
        let mobileNavOpen = false;
        function toggleMobileNav() {
            mobileNavOpen = !mobileNavOpen;
            const nav = document.getElementById('megaMobileNav');
            const icon = document.getElementById('mobileToggleIcon');
            if (mobileNavOpen) {
                nav.classList.add('open');
                icon.className = 'bi bi-x-lg';
                document.body.style.overflow = 'hidden';
            } else {
                nav.classList.remove('open');
                icon.className = 'bi bi-list';
                document.body.style.overflow = '';
            }
        }

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('megaNavbar');
            if (window.scrollY > 20) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Fade in on scroll
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) entry.target.classList.add('visible');
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

        // Animated counters
        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'));
            const duration = 2000;
            const step = target / (duration / 16);
            let current = 0;
            const update = () => {
                current += step;
                if (current < target) {
                    el.textContent = Math.floor(current);
                    requestAnimationFrame(update);
                } else {
                    el.textContent = target;
                }
            };
            update();
        }
        const statsObserver = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const counter = entry.target.querySelector('.stat-number');
                    if (counter && !counter.classList.contains('counted')) {
                        counter.classList.add('counted');
                        animateCounter(counter);
                    }
                }
            });
        }, { threshold: 0.5 });
        document.querySelectorAll('.stat-item').forEach(el => statsObserver.observe(el.parentElement));

        // Service details toggle (mobile)
        function toggleServiceDetails(btn) {
            const details = btn.nextElementSibling;
            const isShowing = details.classList.contains('show');
            document.querySelectorAll('.service-details.show').forEach(el => {
                el.classList.remove('show');
                el.previousElementSibling.innerHTML = 'View Details <i class="bi bi-chevron-down"></i>';
            });
            if (!isShowing) {
                details.classList.add('show');
                btn.innerHTML = 'Hide Details <i class="bi bi-chevron-up"></i>';
            }
        }

        // Testimonial Carousel
        <?php if (!empty($recentFeedback)): ?>
        const track = document.getElementById('testimonialTrack');
        const nav = document.getElementById('carouselNav');
        const items = document.querySelectorAll('.testimonial-item');
        let currentIndex = 0;
        let itemsPerView = window.innerWidth >= 992 ? 3 : (window.innerWidth >= 768 ? 2 : 1);

        function createDots() {
            nav.innerHTML = '';
            const totalSlides = Math.ceil(items.length / itemsPerView);
            for (let i = 0; i < totalSlides; i++) {
                const dot = document.createElement('span');
                dot.className = 'carousel-dot' + (i === 0 ? ' active' : '');
                dot.addEventListener('click', () => goToSlide(i));
                nav.appendChild(dot);
            }
        }

        function updateCarousel() {
            track.style.transform = `translateX(${-(currentIndex * (100 / itemsPerView))}%)`;
            nav.querySelectorAll('.carousel-dot').forEach((dot, i) => dot.classList.toggle('active', i === currentIndex));
        }

        function goToSlide(i) {
            currentIndex = Math.max(0, Math.min(i, Math.ceil(items.length / itemsPerView) - 1));
            updateCarousel();
        }

        function nextSlide() {
            currentIndex = (currentIndex + 1) % Math.ceil(items.length / itemsPerView);
            updateCarousel();
        }

        createDots();
        updateCarousel();
        window.addEventListener('resize', () => {
            itemsPerView = window.innerWidth >= 992 ? 3 : (window.innerWidth >= 768 ? 2 : 1);
            currentIndex = 0;
            createDots();
            updateCarousel();
        });
        setInterval(nextSlide, 5000);
        <?php endif; ?>

        // Floating CTA
        const floatingCta = document.getElementById('floatingCta');
        if (floatingCta) {
            window.addEventListener('scroll', function() {
                floatingCta.classList.toggle('visible', window.scrollY > 500);
            });
        }

        // Close mobile nav on link click
        document.querySelectorAll('.mega-mobile-link, .mega-mobile-nav .mega-nav-btn').forEach(link => {
            link.addEventListener('click', function() {
                if (mobileNavOpen) toggleMobileNav();
            });
        });

        // Close mobile nav on resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024 && mobileNavOpen) toggleMobileNav();
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
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '<?= htmlspecialchars($settings['facebook_pixel']) ?>');
            fbq('track', 'PageView');
        </script>
    <?php endif; ?>
</body>
</html>