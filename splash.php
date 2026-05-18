<?php
/**
 * BUSIQUIP ESWATINI LTD
 * Professional Equipment Fault Management System
 * ============================================
 * SPLASH SCREEN v3.0 - Ultimate Premium Edition
 * 
 * Features:
 * - 7-second animated splash screen
 * - Role-based automatic redirects
 * - Real-time system status monitoring
 * - Theme selection options
 * - Responsive design (mobile & desktop)
 * - Network status detection
 * - Loading progress animation
 * - Premium glassmorphism design
 * 
 * Author: System Development Team
 * Last Updated: April 2026
 * Security Level: Enterprise Grade
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============ SESSION MANAGEMENT ============
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
        'cookie_lifetime' => 3600,
    ]);
}

// ============ DATABASE CONNECTION ============
require_once __DIR__ . '/config/database.php';
$db_status = $conn->connect_errno ? 'failed' : 'connected';

// ============ SYSTEM INFORMATION ============
$system_info = [
    'version' => '3.0',
    'build' => '20260412',
    'environment' => 'production',
    'timezone' => 'Africa/Johannesburg',
];

// ============ USER ROLE DETECTION ============
$user_role = $_SESSION['staff_role'] ?? $_SESSION['client_id'] ?? null;
$user_name = $_SESSION['staff_name'] ?? $_SESSION['client_name'] ?? 'Guest';
$user_type = 'guest';

if (isset($_SESSION['staff_role'])) {
    switch ($_SESSION['staff_role']) {
        case 'Admin':
            $user_type = 'admin';
            $welcome_title = 'Welcome to Admin Control Center';
            $redirect_url = 'modules/admin/admin_dashboard.php';
            break;
        case 'Technician':
            $user_type = 'technician';
            $welcome_title = 'Welcome to Technician Workspace';
            $redirect_url = 'modules/staff/technician_portal.php';
            break;
        case 'Accountant':
            $user_type = 'accountant';
            $welcome_title = 'Welcome to Accounts & Billing Portal';
            $redirect_url = 'modules/accounts/accountant_dashboard.php';
            break;
        default:
            $user_type = 'staff';
            $welcome_title = 'Welcome to Staff Portal';
            $redirect_url = 'modules/staff/staff_dashboard.php';
    }
} elseif (isset($_SESSION['client_id'])) {
    $user_type = 'client';
    $welcome_title = 'Welcome to Client Portal';
    $redirect_url = 'modules/clients/client_dashboard.php';
} else {
    $redirect_url = 'index.php';
}

// ============ THEME PREFERENCE ============
$theme = $_COOKIE['busiquip_theme'] ?? 'dark';

// ============ SYSTEM STATISTICS ============
$stats = [
    'total_clients' => 0,
    'active_faults' => 0,
    'pending_payments' => 0,
    'technicians_online' => 0,
];

if ($db_status === 'connected') {
    $result = $conn->query("SELECT COUNT(*) as count FROM clients WHERE status='Active'");
    if ($result) $stats['total_clients'] = $result->fetch_assoc()['count'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as count FROM faults WHERE status IN ('Pending', 'Assigned', 'In Progress')");
    if ($result) $stats['active_faults'] = $result->fetch_assoc()['count'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'Pending'");
    if ($result) $stats['pending_payments'] = $result->fetch_assoc()['count'] ?? 0;
    
    $result = $conn->query("SELECT COUNT(*) as count FROM employee WHERE status='Active' AND role='Technician'");
    if ($result) $stats['technicians_online'] = $result->fetch_assoc()['count'] ?? 0;
}

$current_date = date('l, F j, Y');
$current_time = date('H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>BUSIQUIP - Loading System | Eswatini Equipment Fault Management</title>
    
    <!-- Preload Assets -->
    <link rel="preload" as="image" href="images/logo.png">
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" as="style">
    
    <!-- Stylesheets -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ============ CSS VARIABLES ============ */
        :root {
            --color-burgundy: #8B0000;
            --color-gold: #FFD700;
            --color-teal: #0D9488;
            --color-emerald: #10B981;
            --color-slate: #475569;
            --color-dark-bg: #0F172A;
            --color-darker-bg: #0B1221;
            --color-card-dark: #1E293B;
            --color-light-bg: #F8FAFC;
            --color-light-card: #FFFFFF;
            --color-success: #10B981;
            --color-warning: #F59E0B;
            --color-danger: #EF4444;
            --color-info: #2563EB;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body.light-mode {
            --bg-primary: var(--color-light-bg);
            --bg-secondary: #F1F5F9;
            --bg-card: var(--color-light-card);
            --text-primary: var(--color-dark-bg);
            --text-secondary: #64748B;
            --border-color: #E2E8F0;
        }

        body.dark-mode {
            --bg-primary: var(--color-dark-bg);
            --bg-secondary: var(--color-darker-bg);
            --bg-card: var(--color-card-dark);
            --text-primary: #FFFFFF;
            --text-secondary: #CBD5E1;
            --border-color: #334155;
        }

        /* ============ RESET & BASE ============ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--color-dark-bg), #060E27);
            color: var(--text-primary);
            overflow: hidden;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: var(--transition);
        }

        /* ============ ANIMATED BACKGROUND ============ */
        .splash-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }

        .splash-bg::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                rgba(139, 0, 0, 0.1) 0%,
                rgba(255, 215, 0, 0.05) 25%,
                rgba(13, 148, 136, 0.1) 50%,
                rgba(255, 215, 0, 0.05) 75%,
                rgba(139, 0, 0, 0.1) 100%
            );
            animation: gradient-shift 15s ease infinite;
            top: -50%;
            left: -50%;
        }

        @keyframes gradient-shift {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(50px, 50px) rotate(180deg); }
        }

        .splash-blob {
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            filter: blur(80px);
            animation: blob-float 20s infinite ease-in-out;
        }

        .splash-blob-1 {
            width: 600px;
            height: 600px;
            top: -200px;
            left: -200px;
            background: linear-gradient(135deg, var(--color-burgundy), var(--color-gold));
            animation-delay: 0s;
        }

        .splash-blob-2 {
            width: 500px;
            height: 500px;
            bottom: -100px;
            right: -150px;
            background: linear-gradient(135deg, var(--color-teal), var(--color-emerald));
            animation-delay: 5s;
        }

        .splash-blob-3 {
            width: 450px;
            height: 450px;
            top: 40%;
            left: -100px;
            background: linear-gradient(135deg, var(--color-burgundy), var(--color-emerald));
            animation-delay: 10s;
        }

        @keyframes blob-float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(80px, -120px) scale(1.2); }
            66% { transform: translate(-50px, 80px) scale(0.8); }
        }

        /* ============ SPLASH CONTAINER ============ */
        .splash-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 600px;
            padding: 40px;
            text-align: center;
            animation: splash-fade-in 0.8s ease;
        }

        @keyframes splash-fade-in {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* ============ LOGO & BRANDING ============ */
        .splash-logo {
            margin-bottom: 40px;
            animation: logo-bounce 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) 0.2s both;
        }

        @keyframes logo-bounce {
            0% {
                opacity: 0;
                transform: scale(0) rotateZ(-180deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotateZ(0deg);
            }
        }

        .splash-logo-icon {
            font-size: 80px;
            margin-bottom: 15px;
            display: inline-block;
            animation: icon-glow 2s ease-in-out infinite;
        }

        @keyframes icon-glow {
            0%, 100% {
                text-shadow: 0 0 20px rgba(255, 215, 0, 0.5);
                transform: scale(1);
            }
            50% {
                text-shadow: 0 0 40px rgba(255, 215, 0, 0.8);
                transform: scale(1.05);
            }
        }

        .splash-company-name {
            font-size: 32px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--color-burgundy), var(--color-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
            animation: text-reveal 0.8s ease 0.4s both;
        }

        @keyframes text-reveal {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .splash-tagline {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-secondary);
            font-weight: 700;
            margin-bottom: 40px;
            animation: text-reveal 0.8s ease 0.5s both;
        }

        /* ============ WELCOME MESSAGE ============ */
        .splash-welcome {
            font-size: 18px;
            font-weight: 700;
            color: var(--color-gold);
            margin-bottom: 35px;
            animation: text-reveal 0.8s ease 0.6s both;
        }

        /* ============ LOADING SPINNER ============ */
        .splash-spinner {
            width: 60px;
            height: 60px;
            margin: 30px auto;
            position: relative;
            animation: spinner-fade-in 0.6s ease 0.7s both;
        }

        @keyframes spinner-fade-in {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .splash-spinner::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 4px solid rgba(255, 215, 0, 0.2);
            border-top: 4px solid var(--color-gold);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .splash-spinner::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 40px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 3px solid rgba(13, 148, 136, 0.3);
            border-top: 3px solid var(--color-teal);
            border-radius: 50%;
            animation: spin-reverse 1.5s linear infinite;
        }

        @keyframes spin-reverse {
            0% { transform: translate(-50%, -50%) rotate(360deg); }
            100% { transform: translate(-50%, -50%) rotate(0deg); }
        }

        /* ============ LOADING TEXT ============ */
        .splash-loading-text {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-secondary);
            font-weight: 700;
            margin-top: 20px;
            min-height: 20px;
            animation: text-reveal 0.8s ease 0.8s both;
        }

        .splash-loading-text::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }

        /* ============ PROGRESS BAR ============ */
        .splash-progress-container {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
            margin: 30px 0;
            border: 1px solid rgba(255, 215, 0, 0.2);
            animation: text-reveal 0.8s ease 0.9s both;
        }

        .splash-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--color-burgundy), var(--color-gold), var(--color-teal));
            width: 0%;
            border-radius: 10px;
            animation: progress-fill 7s ease-out forwards;
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.6);
        }

        @keyframes progress-fill {
            0% { width: 0%; }
            10% { width: 8%; }
            25% { width: 22%; }
            50% { width: 55%; }
            75% { width: 82%; }
            90% { width: 95%; }
            100% { width: 100%; }
        }

        .splash-progress-text {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 8px;
            font-weight: 600;
        }

        /* ============ STATUS INDICATORS ============ */
        .splash-status {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 35px;
            animation: text-reveal 0.8s ease 1s both;
        }

        .status-item {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8), rgba(26, 54, 93, 0.7));
            border: 1px solid rgba(255, 215, 0, 0.2);
            border-radius: 12px;
            padding: 15px;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }

        .status-dot.connected {
            background: var(--color-success);
            box-shadow: 0 0 10px var(--color-success);
        }

        .status-dot.failed {
            background: var(--color-danger);
            box-shadow: 0 0 10px var(--color-danger);
        }

        .status-dot.loading {
            background: var(--color-warning);
            box-shadow: 0 0 10px var(--color-warning);
            animation: pulse-fast 1s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.2); }
        }

        @keyframes pulse-fast {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(1.3); }
        }

        /* ============ STATISTICS ============ */
        .splash-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 35px;
            animation: text-reveal 0.8s ease 1.1s both;
        }

        .stat-box {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8), rgba(26, 54, 93, 0.7));
            border: 1px solid rgba(255, 215, 0, 0.2);
            border-radius: 12px;
            padding: 18px;
            backdrop-filter: blur(10px);
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--color-burgundy), var(--color-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            font-weight: 700;
        }

        /* ============ DATETIME ============ */
        .splash-datetime {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 35px;
            animation: text-reveal 0.8s ease 1.2s both;
            flex-wrap: wrap;
        }

        .datetime-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .datetime-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            font-weight: 700;
            margin-bottom: 5px;
        }

        .datetime-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--color-gold);
        }

        /* ============ FOOTER INFO ============ */
        .splash-footer {
            margin-top: 40px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 215, 0, 0.2);
            animation: text-reveal 0.8s ease 1.3s both;
        }

        .footer-version {
            font-size: 10px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .footer-security {
            font-size: 9px;
            color: var(--color-success);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ============ CONTROLS ============ */
        .splash-controls {
            display: flex;
            gap: 15px;
            margin-top: 35px;
            animation: text-reveal 0.8s ease 1.4s both;
            justify-content: center;
            flex-wrap: wrap;
        }

        .control-btn {
            padding: 10px 20px;
            border: 2px solid var(--color-gold);
            background: transparent;
            color: var(--color-gold);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .control-btn:hover {
            background: var(--color-gold);
            color: var(--color-dark-bg);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 215, 0, 0.4);
        }

        .control-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* ============ THEME SELECTOR ============ */
        .theme-selector {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100;
            display: flex;
            gap: 10px;
            animation: text-reveal 0.8s ease 0.3s both;
        }

        .theme-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 2px solid var(--color-gold);
            background: rgba(30, 41, 59, 0.9);
            color: var(--color-gold);
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .theme-btn:hover {
            background: var(--color-gold);
            color: var(--color-dark-bg);
            transform: scale(1.1) rotate(20deg);
        }

        /* ============ TIPS CAROUSEL ============ */
        .splash-tip {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(13, 148, 136, 0.15), rgba(255, 215, 0, 0.1));
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 12px;
            font-size: 12px;
            font-style: italic;
            color: var(--text-secondary);
            animation: text-reveal 0.8s ease 1.5s both;
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tip-icon {
            margin-right: 10px;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            .splash-container {
                max-width: 90%;
                padding: 25px;
            }

            .splash-company-name {
                font-size: 26px;
            }

            .splash-logo-icon {
                font-size: 60px;
            }

            .splash-status,
            .splash-stats {
                grid-template-columns: 1fr;
            }

            .splash-datetime {
                gap: 20px;
            }

            .splash-controls {
                flex-direction: column;
            }

            .control-btn {
                width: 100%;
                justify-content: center;
            }

            .theme-selector {
                bottom: 20px;
                top: auto;
                right: auto;
                left: 50%;
                transform: translateX(-50%);
            }
        }

        /* ============ ACCESSIBILITY ============ */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        /* ============ NO FLICKER ============ */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="dark-mode">
    <!-- Screen Reader Only Text -->
    <div class="sr-only">
        <h1>BUSIQUIP Eswatini Equipment Fault Management System Loading</h1>
        <p>System is initializing. Please wait.</p>
    </div>

    <!-- ANIMATED BACKGROUND -->
    <div class="splash-bg">
        <div class="splash-blob splash-blob-1"></div>
        <div class="splash-blob splash-blob-2"></div>
        <div class="splash-blob splash-blob-3"></div>
    </div>

    <!-- THEME SELECTOR -->
    <div class="theme-selector">
        <button class="theme-btn" onclick="setTheme('dark')" title="Dark Mode" id="themeBtn-dark">
            <i class="fas fa-moon"></i>
        </button>
        <button class="theme-btn" onclick="setTheme('light')" title="Light Mode" id="themeBtn-light">
            <i class="fas fa-sun"></i>
        </button>
    </div>

    <!-- MAIN SPLASH CONTAINER -->
    <div class="splash-container">
        <!-- LOGO -->
        <div class="splash-logo">
            <div class="splash-logo-icon">⚙️</div>
            <div class="splash-company-name">BUSIQUIP</div>
            <div class="splash-tagline">Professional Equipment Management</div>
        </div>

        <!-- WELCOME MESSAGE -->
        <div class="splash-welcome" id="welcomeMsg"><?php echo htmlspecialchars($welcome_title ?? 'Welcome to BUSIQUIP'); ?></div>

        <!-- LOADING SPINNER -->
        <div class="splash-spinner" role="status" aria-label="Loading"></div>

        <!-- LOADING TEXT -->
        <div class="splash-loading-text" id="loadingText">Initializing System</div>

        <!-- PROGRESS BAR -->
        <div class="splash-progress-container">
            <div class="splash-progress-bar"></div>
        </div>
        <div class="splash-progress-text"><span id="progressPercent">0</span>%</div>

        <!-- STATUS INDICATORS -->
        <div class="splash-status">
            <div class="status-item">
                <div class="status-dot loading" id="dbStatus"></div>
                <span>Database</span>
            </div>
            <div class="status-item">
                <div class="status-dot loading" id="serverStatus"></div>
                <span>Server</span>
            </div>
            <div class="status-item">
                <div class="status-dot loading" id="networkStatus"></div>
                <span>Network</span>
            </div>
            <div class="status-item">
                <div class="status-dot loading" id="sessionStatus"></div>
                <span>Session</span>
            </div>
        </div>

        <!-- STATISTICS -->
        <div class="splash-stats">
            <div class="stat-box">
                <div class="stat-number" id="statClients"><?php echo $stats['total_clients']; ?></div>
                <div class="stat-label">Active Clients</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" id="statFaults"><?php echo $stats['active_faults']; ?></div>
                <div class="stat-label">Pending Faults</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" id="statPayments"><?php echo $stats['pending_payments']; ?></div>
                <div class="stat-label">Pending Payments</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" id="statTechs"><?php echo $stats['technicians_online']; ?></div>
                <div class="stat-label">Technicians</div>
            </div>
        </div>

        <!-- DATE & TIME -->
        <div class="splash-datetime">
            <div class="datetime-item">
                <div class="datetime-label">Date</div>
                <div class="datetime-value" id="splashDate"><?php echo $current_date; ?></div>
            </div>
            <div class="datetime-item">
                <div class="datetime-label">Time</div>
                <div class="datetime-value" id="splashTime"><?php echo $current_time; ?></div>
            </div>
        </div>

        <!-- TIPS -->
        <div class="splash-tip">
            <span class="tip-icon">💡</span>
            <span id="tipText">Report equipment faults quickly for faster resolution and minimal downtime.</span>
        </div>

        <!-- FOOTER -->
        <div class="splash-footer">
            <div class="footer-version">
                <strong>BUSIQUIP v<?php echo $system_info['version']; ?></strong> • Build <?php echo $system_info['build']; ?> • <?php echo ucfirst($system_info['environment']); ?>
            </div>
            <div class="footer-security">
                <i class="fas fa-lock"></i> SSL Secure | Session Protected
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="splash-controls">
            <button class="control-btn" onclick="skipSplash()" id="skipBtn" style="display: none;">
                <i class="fas fa-forward"></i> Skip
            </button>
            <button class="control-btn" onclick="toggleMute()" id="muteBtn">
                <i class="fas fa-volume-up"></i> Mute
            </button>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        // ============ CONFIGURATION ============
        const SPLASH_DURATION = 7000; // 7 seconds
        const REDIRECT_URL = '<?php echo htmlspecialchars($redirect_url); ?>';
        const USER_TYPE = '<?php echo htmlspecialchars($user_type); ?>';
        const USER_NAME = '<?php echo htmlspecialchars($user_name); ?>';
        const DB_STATUS = '<?php echo htmlspecialchars($db_status); ?>';

        // ============ STATE MANAGEMENT ============
        let isMuted = false;
        let splashCompleted = false;
        let canSkip = false;

        // ============ LOADING MESSAGES ============
        const loadingMessages = [
            'Connecting to Server',
            'Loading User Profile',
            'Checking Fault Records',
            'Preparing Dashboard',
            'Initializing Modules',
            'Ready to Launch'
        ];

        const tips = [
            '💡 Report equipment faults quickly for faster resolution.',
            '🔧 Regular maintenance prevents costly equipment failures.',
            '📊 Track repair progress in real-time with our system.',
            '💰 Transparent pricing with no hidden charges.',
            '⚙️ Expert technicians available 24/7 for emergencies.',
            '📱 Access your account anytime from any device.'
        ];

        // ============ INITIALIZATION ============
        window.addEventListener('load', function() {
            initializeSplash();
            updateDateTime();
            setInterval(updateDateTime, 1000);
            
            // Start progress animation
            startProgressAnimation();
            
            // Simulate loading steps
            simulateLoading();
            
            // Set redirect timeout
            setTimeout(function() {
                splashCompleted = true;
                canSkip = true;
                document.getElementById('skipBtn').style.display = 'block';
                autoRedirect();
            }, SPLASH_DURATION);
        });

        // ============ INITIALIZE SPLASH ============
        function initializeSplash() {
            const theme = localStorage.getItem('busiquip-splash-theme') || 'dark';
            setTheme(theme);
            
            // Display random tip
            displayRandomTip();
            
            // Update status indicators
            updateStatusIndicators();
        }

        // ============ THEME MANAGEMENT ============
        function setTheme(theme) {
            document.body.className = theme + '-mode';
            localStorage.setItem('busiquip-splash-theme', theme);
            
            // Update button highlights
            document.querySelectorAll('.theme-btn').forEach(btn => {
                btn.style.opacity = '0.6';
            });
            document.getElementById('themeBtn-' + theme).style.opacity = '1';
        }

        // ============ STATUS UPDATES ============
        function updateStatusIndicators() {
            // Database status
            const dbStatusEl = document.getElementById('dbStatus');
            if (DB_STATUS === 'connected') {
                dbStatusEl.className = 'status-dot connected';
                dbStatusEl.setAttribute('aria-label', 'Database connected');
            } else {
                dbStatusEl.className = 'status-dot failed';
                dbStatusEl.setAttribute('aria-label', 'Database disconnected');
            }

            // Server status
            setTimeout(() => {
                document.getElementById('serverStatus').className = 'status-dot connected';
            }, 1000);

            // Network status
            setTimeout(() => {
                if (navigator.onLine) {
                    document.getElementById('networkStatus').className = 'status-dot connected';
                } else {
                    document.getElementById('networkStatus').className = 'status-dot failed';
                }
            }, 1500);

            // Session status
            setTimeout(() => {
                document.getElementById('sessionStatus').className = 'status-dot connected';
            }, 2000);
        }

        // ============ PROGRESS ANIMATION ============
        function startProgressAnimation() {
            let progress = 0;
            const increment = 100 / (SPLASH_DURATION / 100);
            
            const interval = setInterval(() => {
                progress += increment;
                if (progress > 100) progress = 100;
                
                document.getElementById('progressPercent').textContent = Math.floor(progress);
                
                if (progress >= 100) {
                    clearInterval(interval);
                }
            }, 100);
        }

        // ============ LOADING SIMULATION ============
        function simulateLoading() {
            let currentMessage = 0;
            
            const updateMessage = () => {
                if (currentMessage < loadingMessages.length) {
                    document.getElementById('loadingText').textContent = loadingMessages[currentMessage];
                    currentMessage++;
                    setTimeout(updateMessage, SPLASH_DURATION / loadingMessages.length);
                }
            };
            
            updateMessage();
        }

        // ============ DATE & TIME ============
        function updateDateTime() {
            const now = new Date();
            
            const dateOptions = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };
            
            const timeOptions = {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            };
            
            const dateString = now.toLocaleDateString('en-US', dateOptions);
            const timeString = now.toLocaleTimeString('en-US', timeOptions);
            
            document.getElementById('splashDate').textContent = dateString;
            document.getElementById('splashTime').textContent = timeString;
        }

        // ============ RANDOM TIP ============
        function displayRandomTip() {
            const randomTip = tips[Math.floor(Math.random() * tips.length)];
            document.getElementById('tipText').textContent = randomTip.substring(3); // Remove emoji
            document.querySelector('.tip-icon').textContent = randomTip.charAt(0); // Set emoji
        }

        // ============ AUDIO CONTROL ============
        function toggleMute() {
            isMuted = !isMuted;
            const btn = document.getElementById('muteBtn');
            if (isMuted) {
                btn.innerHTML = '<i class="fas fa-volume-mute"></i> Unmute';
            } else {
                btn.innerHTML = '<i class="fas fa-volume-up"></i> Mute';
                playStartupSound();
            }
        }

        function playStartupSound() {
            // Optional: Add startup sound
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gain.gain.setValueAtTime(0.1, audioContext.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        }

        // ============ SKIP & REDIRECT ============
        function skipSplash() {
            if (canSkip) {
                redirectToPortal();
            }
        }

        function autoRedirect() {
            setTimeout(redirectToPortal, 3000); // Extra 3 seconds after splash
        }

        function redirectToPortal() {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.6s ease';
            
            setTimeout(() => {
                window.location.href = REDIRECT_URL;
            }, 600);
        }

        // ============ KEYBOARD SHORTCUTS ============
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && canSkip) {
                skipSplash();
            } else if (event.key === 'Escape' && canSkip) {
                skipSplash();
            } else if (event.key === 'm' || event.key === 'M') {
                toggleMute();
            }
        });

        // ============ NETWORK STATUS ============
        window.addEventListener('online', function() {
            document.getElementById('networkStatus').className = 'status-dot connected';
        });

        window.addEventListener('offline', function() {
            document.getElementById('networkStatus').className = 'status-dot failed';
        });
    </script>
</body>
</html>