<?php
ini_set("display_errors", 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/config/database.php';

// Redirect splash page logic
if (!isset($_GET['skip_splash']) && !isset($_SESSION['splash_shown'])) {
    $_SESSION['splash_shown'] = true;
    header('Location: splash.php');
    exit;
}

// Function to get system operational status
function getSystemStatus($conn) {
    $now = new DateTime();
    $dayOfWeek = $now->format('w');
    $hours = $now->format('H');
    $minutes = $now->format('i');
    $totalMinutes = ((int)$hours * 60) + (int)$minutes;

    $isOperating = $dayOfWeek >= 1 && $dayOfWeek <= 5 && $totalMinutes >= 480 && $totalMinutes < 1020;
    
    return array(
        'isOperating' => $isOperating,
        'dayOfWeek' => $dayOfWeek,
        'time' => $now->format('H:i:s'),
        'date' => $now->format('Y-m-d')
    );
}

$systemStatus = getSystemStatus($conn);

// Function to get operational statistics
function getOperationalStats($conn) {
    $stats = array();
    
    // Total Reported Faults
    $result = $conn->query("SELECT COUNT(*) as total FROM REPORTED_FAULT");
    $stats['total_faults'] = $result->fetch_assoc()['total'];
    
    // Pending Faults
    $result = $conn->query("SELECT COUNT(*) as total FROM REPORTED_FAULT WHERE STATUS = 'Pending'");
    $stats['pending_faults'] = $result->fetch_assoc()['total'];
    
    // In Progress
    $result = $conn->query("SELECT COUNT(*) as total FROM REPORTED_FAULT WHERE STATUS = 'In Progress'");
    $stats['in_progress'] = $result->fetch_assoc()['total'];
    
    // Completed
    $result = $conn->query("SELECT COUNT(*) as total FROM REPORTED_FAULT WHERE STATUS = 'Completed'");
    $stats['completed'] = $result->fetch_assoc()['total'];
    
    // Total Clients
    $result = $conn->query("SELECT COUNT(*) as total FROM CLIENT");
    $stats['total_clients'] = $result->fetch_assoc()['total'];
    
    // Active Technicians
    $result = $conn->query("SELECT COUNT(*) as total FROM EMPLOYEE WHERE ROLE = 'Technician'");
    $stats['active_technicians'] = $result->fetch_assoc()['total'];
    
    return $stats;
}

$stats = getOperationalStats($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BUSIQUIP - Professional Fault Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ===== COLOR PALETTE - ESWATINI BUSIQUIP ===== */
        :root {
            --primary-gold: #FFD700;
            --primary-burgundy: #8B0000;
            --primary-white: #FFFFFF;
            --accent-teal: #0D9488;
            --accent-emerald: #10B981;
            --accent-slate: #475569;
            --color-dark-bg: #0F172A;
            --color-darker-bg: #0B1221;
            --color-card-dark: #1E293B;
            --color-light-bg: #F8FAFC;
            --color-light-card: #FFFFFF;
            --color-text-dark: #FFFFFF;
            --color-text-light: #0F172A;
            --color-border: #334155;
            --color-success: #10B981;
            --color-warning: #F59E0B;
            --color-danger: #EF4444;
            --transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* THEME: DARK (DEFAULT) */
        :root {
            --bg-primary: var(--color-dark-bg);
            --bg-secondary: var(--color-darker-bg);
            --bg-card: var(--color-card-dark);
            --text-primary: var(--color-text-dark);
            --text-secondary: #CBD5E1;
            --border-color: var(--color-border);
            --overlay-opacity: 0.75;
        }

        /* THEME: LIGHT MODE */
        body.light-mode {
            --bg-primary: var(--color-light-bg);
            --bg-secondary: #F1F5F9;
            --bg-card: var(--color-light-card);
            --text-primary: var(--color-text-light);
            --text-secondary: #64748B;
            --border-color: #E2E8F0;
            --overlay-opacity: 0.85;
        }

        /* THEME: WARM MODE (SUNSET) */
        body.warm-mode {
            --bg-primary: #FDF5E6;
            --bg-secondary: #F5EBD9;
            --bg-card: #FFFFFF;
            --text-primary: #3D2817;
            --text-secondary: #8B6F47;
            --border-color: #E8D5C4;
            --overlay-opacity: 0.80;
        }

        /* ===== BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Sora', sans-serif;
            color: var(--text-primary);
            transition: var(--transition);
            position: relative;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ===== FULL PAGE BACKGROUND IMAGE ===== */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('fault_management/images/2.jpg');
            background-attachment: fixed;
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
            z-index: -2;
        }

        /* DARK THEME OVERLAY */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(15, 23, 42, var(--overlay-opacity)) 0%, rgba(11, 18, 33, var(--overlay-opacity)) 100%),
                        radial-gradient(circle at 20% 80%, rgba(139, 0, 0, 0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(13, 148, 136, 0.1) 0%, transparent 50%);
            z-index: -1;
        }

        /* LIGHT THEME OVERLAY */
        body.light-mode::after {
            background: linear-gradient(135deg, rgba(248, 250, 252, var(--overlay-opacity)) 0%, rgba(241, 245, 249, var(--overlay-opacity)) 100%),
                        radial-gradient(circle at 20% 80%, rgba(139, 0, 0, 0.08) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(13, 148, 136, 0.06) 0%, transparent 50%);
        }

        /* WARM THEME OVERLAY */
        body.warm-mode::after {
            background: linear-gradient(135deg, rgba(253, 245, 230, var(--overlay-opacity)) 0%, rgba(245, 235, 217, var(--overlay-opacity)) 100%),
                        radial-gradient(circle at 20% 80%, rgba(184, 134, 11, 0.12) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(218, 165, 32, 0.1) 0%, transparent 50%);
        }

        /* ===== ANIMATED BACKGROUND ELEMENTS ===== */
        .bg-decoration {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
            animation: blob-animation 25s infinite ease-in-out;
            filter: blur(60px);
            mix-blend-mode: screen;
        }

        .blob-1 {
            width: 600px;
            height: 600px;
            top: -200px;
            left: -200px;
            background: linear-gradient(135deg, var(--primary-gold), var(--primary-burgundy));
            animation-delay: 0s;
        }

        .blob-2 {
            width: 500px;
            height: 500px;
            bottom: -100px;
            right: -150px;
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-emerald));
            animation-delay: 3s;
        }

        .blob-3 {
            width: 450px;
            height: 450px;
            top: 40%;
            left: -100px;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--accent-emerald));
            animation-delay: 6s;
        }

        @keyframes blob-animation {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(80px, -120px) scale(1.2); }
            66% { transform: translate(-50px, 80px) scale(0.8); }
        }

        /* ===== CONTAINER ===== */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 40px;
            position: relative;
            z-index: 1;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 20px;
            }
        }

        /* ===== HEADER ===== */
        header {
            position: sticky;
            top: 0;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(30px);
            border-bottom: 3px solid var(--primary-gold);
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            transition: var(--transition);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        /* ===== COMPANY LOGO IMAGE (TOP RIGHT) ===== */
        .company-logo-img {
            height: 100px;
            width: auto;
            max-width: 220px;
            object-fit: contain;
            border-radius: 10px;
            background: #ffffff;
            padding: 6px 10px;
            transition: var(--transition);
            box-shadow: 0 4px 18px rgba(255, 215, 0, 0.35), 0 2px 8px rgba(0,0,0,0.3);
            flex-shrink: 0;
            display: block;
        }

        .company-logo-img:hover {
            transform: scale(1.06);
            box-shadow: 0 8px 30px rgba(255, 215, 0, 0.55), 0 4px 14px rgba(0,0,0,0.4);
        }

        @media (max-width: 768px) {
            .company-logo-img {
                height: 65px;
                max-width: 140px;
                padding: 4px 7px;
            }
        }

        header::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-gold), transparent);
            opacity: 0.6;
        }

        body.light-mode header {
            background: rgba(248, 250, 252, 0.92);
            border-bottom-color: var(--primary-burgundy);
        }

        body.warm-mode header {
            background: rgba(253, 245, 230, 0.92);
            border-bottom-color: var(--primary-burgundy);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 18px;
            cursor: pointer;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .logo-icon {
            font-size: 45px;
            animation: float 3.5s ease-in-out infinite;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 0 8px rgba(139, 0, 0, 0.3));
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }

        .logo h1 {
            font-size: 32px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            letter-spacing: -0.8px;
        }

        /* ===== NAVIGATION ===== */
        nav {
            display: flex;
            align-items: center;
            gap: 35px;
            flex: 1;
            justify-content: center;
        }

        nav a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            position: relative;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        nav a i {
            font-size: 16px;
            transition: var(--transition);
        }

        nav a::before {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-burgundy), var(--primary-gold));
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 0 10px rgba(255, 215, 0, 0.5);
        }

        nav a:hover {
            color: var(--primary-gold);
            transform: translateY(-2px);
        }

        nav a:hover i {
            transform: rotate(20deg) scale(1.15);
        }

        nav a:hover::before {
            width: 100%;
        }

        /* ===== OPERATIONAL STATUS ===== */
        .operational-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-left: 2px solid var(--border-color);
        }

        .status-indicator {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
            position: relative;
        }

        .status-indicator::after {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            animation: pulse-ring 2s ease-in-out infinite;
        }

        .status-indicator.open {
            background: var(--color-success);
            box-shadow: 0 0 12px var(--color-success), inset 0 0 8px rgba(255, 255, 255, 0.3);
        }

        .status-indicator.open::after {
            border: 1px solid var(--color-success);
        }

        .status-indicator.closed {
            background: var(--color-danger);
            box-shadow: 0 0 12px var(--color-danger), inset 0 0 8px rgba(255, 255, 255, 0.3);
        }

        .status-indicator.closed::after {
            border: 1px solid var(--color-danger);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.15); }
        }

        @keyframes pulse-ring {
            0%, 100% { opacity: 1; transform: scale(0.8); }
            50% { opacity: 0.3; transform: scale(1.3); }
        }

        .status-text {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* ===== SETTINGS BUTTON & MODAL ===== */
        .settings-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--primary-gold);
            background: transparent;
            color: var(--primary-gold);
            cursor: pointer;
            font-size: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.3);
        }

        .settings-btn::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            top: 100%;
            left: 0;
            transition: top 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: -1;
        }

        .settings-btn::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 2px solid var(--primary-gold);
            border-radius: 50%;
            top: 0;
            left: 0;
            animation: spin-border 4s linear infinite;
        }

        @keyframes spin-border {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .settings-btn:hover {
            color: white;
            border-color: white;
            box-shadow: 0 0 30px rgba(139, 0, 0, 0.5);
            transform: scale(1.12) rotate(90deg);
        }

        .settings-btn:hover::before {
            top: 0;
        }

        /* ===== SETTINGS MODAL ===== */
        .settings-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 20px;
        }

        .settings-modal.active {
            display: flex;
        }

        .settings-content {
            background: var(--bg-card);
            border-radius: 30px;
            padding: 55px;
            max-width: 600px;
            width: 100%;
            border: 2px solid var(--primary-gold);
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 35px 100px rgba(0, 0, 0, 0.6), 0 0 60px rgba(139, 0, 0, 0.2);
            position: relative;
        }

        .settings-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, transparent, var(--primary-gold), var(--primary-burgundy), transparent);
            border-radius: 30px 30px 0 0;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(60px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 20px;
        }

        .settings-header h2 {
            font-size: 28px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .settings-header i {
            font-size: 28px;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .close-settings {
            background: none;
            border: none;
            font-size: 32px;
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 2px solid var(--border-color);
        }

        .close-settings:hover {
            color: var(--primary-gold);
            transform: rotate(90deg);
            border-color: var(--primary-gold);
            background: rgba(255, 215, 0, 0.1);
        }

        .settings-section {
            margin-bottom: 40px;
        }

        .settings-section h3 {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            color: var(--primary-gold);
            margin-bottom: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-section h3 i {
            font-size: 18px;
        }

        .settings-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            background: var(--bg-primary);
            border-radius: 12px;
            margin-bottom: 12px;
            transition: var(--transition);
            cursor: pointer;
            border: 1px solid transparent;
        }

        .settings-item:hover {
            background: var(--bg-secondary);
            border-color: var(--primary-gold);
            transform: translateX(6px);
            box-shadow: 0 8px 20px rgba(139, 0, 0, 0.2);
        }

        .settings-item input[type="checkbox"],
        .settings-item input[type="radio"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-gold);
        }

        .settings-item label {
            flex: 1;
            cursor: pointer;
            margin: 0;
            font-weight: 600;
            font-size: 14px;
        }

        .theme-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .theme-option {
            padding: 20px 16px;
            border-radius: 14px;
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            font-weight: 700;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .theme-option i {
            font-size: 28px;
        }

        .theme-option:hover {
            border-color: var(--primary-gold);
            transform: translateY(-6px);
            box-shadow: 0 15px 35px rgba(139, 0, 0, 0.25);
        }

        .theme-option.active {
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            border-color: var(--primary-gold);
            color: white;
            box-shadow: 0 20px 45px rgba(139, 0, 0, 0.5);
        }

        /* ===== HERO SECTION ===== */
        .hero {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 100px;
            align-items: center;
            min-height: 85vh;
            padding: 100px 0;
            position: relative;
        }

        @media (max-width: 768px) {
            .hero {
                grid-template-columns: 1fr;
                gap: 60px;
                padding: 60px 0;
                min-height: auto;
            }
        }

        /* ===== HERO LEFT - IMAGE CAROUSEL ===== */
        .hero-left {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            order: 1;
        }

        .carousel-container {
            width: 100%;
            max-width: 600px;
            aspect-ratio: 1;
            border-radius: 35px;
            overflow: hidden;
            box-shadow: 0 50px 100px rgba(0, 0, 0, 0.5);
            border: 4px solid var(--primary-gold);
            position: relative;
            background: #F5F5F5;
        }

        .carousel-wrapper {
            width: 100%;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .carousel-slide {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            transform: translateX(100%) scale(0.95);
            transition: opacity 1s cubic-bezier(0.4, 0, 0.2, 1), transform 1s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .carousel-slide.active {
            opacity: 1;
            transform: translateX(0) scale(1);
        }

        .carousel-slide.prev {
            transform: translateX(-100%) scale(0.95);
        }

        .carousel-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #E0E0E0;
        }

        .carousel-loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F5F5F5;
            font-size: 16px;
            color: #999;
            font-weight: 600;
        }

        .carousel-controls {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 12px;
            z-index: 10;
            background: rgba(0, 0, 0, 0.6);
            padding: 14px 24px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
        }

        .carousel-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .carousel-dot:hover {
            background: rgba(255, 255, 255, 0.8);
            transform: scale(1.2);
        }

        .carousel-dot.active {
            background: var(--primary-gold);
            transform: scale(1.35);
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.8);
        }

        .carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 8;
            width: 55px;
            height: 55px;
            border: none;
            background: rgba(0, 0, 0, 0.7);
            color: var(--primary-gold);
            cursor: pointer;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            transition: var(--transition);
            backdrop-filter: blur(5px);
            border: 2px solid var(--primary-gold);
        }

        .carousel-nav:hover {
            background: rgba(139, 0, 0, 0.9);
            color: white;
            transform: scale(1.25);
            box-shadow: 0 0 30px rgba(139, 0, 0, 0.7);
        }

        .carousel-nav.prev {
            left: 20px;
        }

        .carousel-nav.next {
            right: 20px;
        }

        .image-decorations {
            position: absolute;
            width: 140px;
            height: 140px;
            background: linear-gradient(135deg, var(--primary-gold), var(--primary-burgundy));
            border-radius: 25px;
            opacity: 0.12;
            top: -50px;
            right: -50px;
            animation: rotate 25s linear infinite;
            z-index: 3;
            box-shadow: 0 0 30px rgba(255, 215, 0, 0.2);
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .status-badge {
            position: absolute;
            bottom: 30px;
            right: 30px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(15px);
            padding: 15px 25px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px solid rgba(255, 215, 0, 0.4);
            animation: slideUp 1s ease 0.5s both;
            z-index: 4;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .status-dot-large {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }

        .status-dot-large.operating {
            background: var(--color-success);
            box-shadow: 0 0 12px var(--color-success);
        }

        .status-dot-large.closed {
            background: var(--color-danger);
            box-shadow: 0 0 12px var(--color-danger);
        }

        /* ===== HERO CONTENT ===== */
        .hero-right {
            display: flex;
            flex-direction: column;
            gap: 35px;
            order: 2;
        }

        @media (max-width: 768px) {
            .hero-right {
                order: 1;
            }
            .hero-left {
                order: 2;
            }
        }

        .hero-right h1 {
            font-size: 62px;
            font-weight: 950;
            line-height: 1.15;
            letter-spacing: -1.5px;
            animation: slideInDown 0.8s ease;
        }

        .hero-right h1 .highlight {
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 2px 6px rgba(139, 0, 0, 0.3));
        }

        body.light-mode .hero-right h1 .highlight {
            background: linear-gradient(135deg, var(--primary-burgundy), var(--accent-teal));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-right p {
            font-size: 18px;
            line-height: 1.9;
            color: var(--text-secondary);
            animation: slideInUp 0.8s ease 0.1s both;
        }

        /* ===== INFO BOXES ===== */
        .info-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 18px;
            animation: slideInUp 0.8s ease 0.2s both;
        }

        .info-box {
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.15), rgba(255, 215, 0, 0.1));
            border: 2px solid var(--primary-gold);
            border-radius: 18px;
            padding: 22px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .info-box::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-burgundy), var(--primary-gold));
            top: 0;
            left: -100%;
            transition: left 0.3s ease;
        }

        .info-box::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.1), rgba(255, 215, 0, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 0;
        }

        .info-box:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(139, 0, 0, 0.3);
        }

        .info-box:hover::before {
            left: 0;
        }

        .info-box:hover::after {
            opacity: 1;
        }

        .info-icon {
            font-size: 28px;
            position: relative;
            z-index: 1;
        }

        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .info-value {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
        }

        /* ===== ACTION BUTTONS ===== */
        .button-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            animation: slideInUp 0.8s ease 0.3s both;
        }

        .btn {
            padding: 20px 50px;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 800;
            font-size: 14px;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
        }

        .btn::before {
            content: '';
            position: absolute;
            width: 0;
            height: 100%;
            background: rgba(255, 255, 255, 0.25);
            left: 50%;
            top: 0;
            transition: width 0.4s ease;
            transform: translateX(-50%);
            z-index: 0;
        }

        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 14px;
            opacity: 0;
            animation: buttonGlow 2s ease-in-out infinite paused;
        }

        .btn:hover::after {
            animation-play-state: running;
        }

        @keyframes buttonGlow {
            0%, 100% { box-shadow: inset 0 0 0 1px transparent; }
            50% { box-shadow: inset 0 0 20px rgba(255, 255, 255, 0.3); }
        }

        .btn:hover::before {
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-burgundy), #6B0000);
            color: white;
            border: 2px solid var(--primary-burgundy);
        }

        .btn-primary:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(139, 0, 0, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--accent-teal), var(--accent-emerald));
            color: white;
            border: 2px solid var(--accent-emerald);
        }

        .btn-secondary:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(16, 185, 129, 0.6);
        }

        /* ===== NAVIGATION SECTIONS ===== */
        .nav-sections {
            display: none;
            margin: 140px 0;
            animation: fadeIn 0.5s ease;
        }

        .nav-sections.active {
            display: block;
        }

        /* ===== SERVICES SECTION ===== */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 35px;
            margin: 50px 0;
        }

        .service-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.75));
            border: 2px solid var(--border-color);
            border-radius: 25px;
            padding: 45px 35px;
            text-align: left;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(15px);
        }

        body.light-mode .service-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.9));
        }

        body.warm-mode .service-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(253, 245, 230, 0.95));
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: -100%;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.15), rgba(255, 215, 0, 0.1));
            transition: top 0.3s ease;
        }

        .service-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-burgundy), var(--primary-gold));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }

        .service-card:hover {
            border-color: var(--primary-gold);
            transform: translateY(-18px);
            box-shadow: 0 40px 80px rgba(139, 0, 0, 0.3);
        }

        .service-card:hover::before {
            top: 0;
        }

        .service-card:hover::after {
            transform: scaleX(1);
        }

        .service-icon {
            font-size: 52px;
            margin-bottom: 20px;
            display: inline-block;
            animation: float 3.5s ease-in-out infinite;
        }

        .service-card h3 {
            font-size: 23px;
            margin: 18px 0;
            color: var(--text-primary);
            font-weight: 800;
        }

        .service-card p {
            color: var(--text-secondary);
            line-height: 1.8;
            margin: 0 0 15px 0;
            font-size: 14px;
        }

        .service-card ul {
            color: var(--text-secondary);
            margin: 0 0 0 25px;
            font-size: 13px;
            line-height: 2;
            list-style-type: none;
        }

        .service-card ul li:before {
            content: '✓ ';
            color: var(--primary-gold);
            font-weight: 800;
            margin-right: 8px;
        }

        /* ===== FEATURES SECTION ===== */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 35px;
            margin: 50px 0;
        }

        .feature-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.75));
            border: 2px solid var(--border-color);
            border-radius: 25px;
            padding: 45px 35px;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(15px);
        }

        body.light-mode .feature-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.9));
        }

        body.warm-mode .feature-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(253, 245, 230, 0.95));
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: -100%;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.15), rgba(255, 215, 0, 0.1));
            transition: top 0.3s ease;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            inset: 0;
            border: 2px solid transparent;
            border-radius: 25px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover {
            border-color: var(--primary-gold);
            transform: translateY(-18px);
            box-shadow: 0 40px 80px rgba(139, 0, 0, 0.3);
        }

        .feature-card:hover::before {
            top: 0;
        }

        .feature-card:hover::after {
            opacity: 1;
        }

        .feature-icon {
            font-size: 60px;
            margin-bottom: 20px;
            display: inline-block;
            animation: float 3.5s ease-in-out infinite;
        }

        .feature-card h3 {
            font-size: 23px;
            margin: 18px 0;
            color: var(--text-primary);
            font-weight: 800;
        }

        .feature-card p {
            color: var(--text-secondary);
            line-height: 1.8;
            margin: 0;
            font-size: 14px;
        }

        /* ===== CONTACT SECTION ===== */
        .contact-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            margin: 50px 0;
        }

        @media (max-width: 768px) {
            .contact-container {
                grid-template-columns: 1fr;
                gap: 50px;
            }
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 35px;
        }

        .contact-item {
            display: flex;
            gap: 25px;
            align-items: flex-start;
            padding: 28px;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.75));
            border-radius: 18px;
            border: 2px solid var(--border-color);
            transition: var(--transition);
            backdrop-filter: blur(15px);
        }

        body.light-mode .contact-item {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.9));
        }

        body.warm-mode .contact-item {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(253, 245, 230, 0.95));
        }

        .contact-item:hover {
            border-color: var(--primary-gold);
            transform: translateX(8px);
            box-shadow: 0 20px 50px rgba(139, 0, 0, 0.3);
        }

        .contact-icon {
            font-size: 36px;
            color: var(--primary-gold);
            min-width: 50px;
            text-align: center;
            margin-top: 5px;
            filter: drop-shadow(0 0 8px rgba(255, 215, 0, 0.3));
        }

        .contact-details h4 {
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .contact-details p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.8;
        }

        .contact-details a {
            color: var(--primary-gold);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 600;
        }

        .contact-details a:hover {
            color: var(--primary-burgundy);
            text-decoration: underline;
        }

        /* ===== GOOGLE MAPS ===== */
        .map-container {
            border-radius: 25px;
            overflow: hidden;
            border: 2px solid var(--border-color);
            height: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            transition: var(--transition);
            backdrop-filter: blur(5px);
        }

        .map-container:hover {
            border-color: var(--primary-gold);
            box-shadow: 0 30px 80px rgba(139, 0, 0, 0.4);
        }

        #busiquip-map {
            width: 100%;
            height: 100%;
        }

        /* ===== SOCIAL SECTION ===== */
        .social-container {
            display: flex;
            gap: 22px;
            margin-top: 35px;
            animation: slideInUp 0.8s ease 0.4s both;
            flex-wrap: wrap;
        }

        .social-btn {
            width: 75px;
            height: 75px;
            border-radius: 50%;
            border: 2px solid var(--primary-gold);
            background: transparent;
            color: var(--primary-gold);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            transition: var(--transition);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 0 25px rgba(255, 215, 0, 0.4);
            backdrop-filter: blur(10px);
        }

        .social-btn::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            top: 100%;
            left: 0;
            transition: top 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: -1;
        }

        .social-btn::after {
            content: '';
            position: absolute;
            inset: -4px;
            border: 2px solid var(--primary-gold);
            border-radius: 50%;
            opacity: 0;
            animation: socialPulse 2s ease-in-out infinite paused;
        }

        .social-btn:hover::after {
            animation-play-state: running;
        }

        @keyframes socialPulse {
            0%, 100% { opacity: 1; transform: scale(0.7); }
            50% { opacity: 0; transform: scale(1.5); }
        }

        .social-btn:hover {
            color: white;
            transform: scale(1.3) rotate(15deg);
            box-shadow: 0 0 50px rgba(139, 0, 0, 0.7);
        }

        .social-btn:hover::before {
            top: 0;
        }

        /* ===== FOOTER ===== */
        footer {
            text-align: center;
            padding: 70px 20px;
            border-top: 3px solid var(--primary-gold);
            margin-top: 140px;
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.15), rgba(255, 215, 0, 0.08));
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 700;
            position: relative;
            backdrop-filter: blur(10px);
        }

        footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-gold), transparent);
        }

        body.light-mode footer {
            background: linear-gradient(135deg, rgba(139, 0, 0, 0.1), rgba(13, 148, 136, 0.08));
            border-top-color: var(--primary-burgundy);
        }

        body.warm-mode footer {
            background: linear-gradient(135deg, rgba(184, 134, 11, 0.12), rgba(218, 165, 32, 0.08));
        }

        .footer-content {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .footer-content span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ===== UTILITY CLASSES ===== */
        .text-center { text-align: center; }
        .mt-large { margin-top: 80px; }
        .mb-large { margin-bottom: 80px; }

        .section-title {
            font-size: 48px;
            font-weight: 950;
            text-align: center;
            margin: 100px 0 30px;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
            position: relative;
        }

        .section-title::before,
        .section-title::after {
            content: '';
            position: absolute;
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-burgundy), var(--primary-gold));
            top: 50%;
            transform: translateY(-50%);
        }

        .section-title::before {
            left: 0;
        }

        .section-title::after {
            right: 0;
        }

        @media (max-width: 768px) {
            .section-title::before,
            .section-title::after {
                display: none;
            }

            .section-title {
                font-size: 36px;
            }
        }

        .section-subtitle {
            font-size: 17px;
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 60px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            header {
                padding: 18px 20px;
                flex-wrap: wrap;
                gap: 12px;
            }

            .logo h1 {
                font-size: 24px;
            }

            nav {
                gap: 18px;
                order: 3;
                width: 100%;
            }

            nav a {
                font-size: 11px;
            }

            .operational-status {
                border: none;
                padding: 0;
                order: 4;
                width: 100%;
            }

            .hero-right h1 {
                font-size: 40px;
            }

            .hero-right p {
                font-size: 16px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
                padding: 18px 35px;
            }

            .settings-content {
                padding: 40px;
            }

            .theme-grid {
                grid-template-columns: 1fr;
            }

            .services-grid,
            .features {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .contact-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .social-btn {
                width: 65px;
                height: 65px;
                font-size: 32px;
            }

            .carousel-nav {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }

            .carousel-nav.prev {
                left: 10px;
            }

            .carousel-nav.next {
                right: 10px;
            }
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <!-- BACKGROUND DECORATIONS -->
    <div class="bg-decoration">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <!-- HEADER -->
    <header>
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-cogs"></i></div>
            <h1>BUSIQUIP</h1>
        </div>
        <nav>
            <a onclick="showSection('home')"><i class="fas fa-home"></i> Home</a>
            <a onclick="showSection('services')"><i class="fas fa-wrench"></i> Services</a>
            <a onclick="showSection('features')"><i class="fas fa-star"></i> Features</a>
            <a onclick="showSection('contact')"><i class="fas fa-map-marker-alt"></i> Contact</a>
        </nav>
        <div class="operational-status">
            <div class="status-indicator" id="statusIndicator"></div>
            <span class="status-text" id="statusText">Checking...</span>
        </div>
        <button class="settings-btn" onclick="toggleSettings()" title="Settings">
            ⚙️
        </button>
        <img src="images/logo.png" alt="Busiquip Logo" class="company-logo-img">
    </header>

    <!-- SETTINGS MODAL -->
    <div class="settings-modal" id="settingsModal">
        <div class="settings-content">
            <div class="settings-header">
                <h2>⚙️ Settings</h2>
                <button class="close-settings" onclick="toggleSettings()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="settings-section">
                <h3><i class="fas fa-palette"></i> Theme Selection</h3>
                <div class="theme-grid">
                    <div class="theme-option active" onclick="setTheme('dark')" data-theme="dark">
                        <i class="fas fa-moon"></i> Dark
                    </div>
                    <div class="theme-option" onclick="setTheme('light')" data-theme="light">
                        <i class="fas fa-sun"></i> Light
                    </div>
                    <div class="theme-option" onclick="setTheme('warm')" data-theme="warm">
                        <i class="fas fa-fire"></i> Warm
                    </div>
                </div>
            </div>

            <div class="settings-section">
                <h3><i class="fas fa-bell"></i> Notifications</h3>
                <div class="settings-item">
                    <input type="checkbox" id="notifyToggle" checked>
                    <label for="notifyToggle">Enable Notifications</label>
                </div>
                <div class="settings-item">
                    <input type="checkbox" id="soundToggle" checked>
                    <label for="soundToggle">Sound Alerts</label>
                </div>
                <div class="settings-item">
                    <input type="checkbox" id="autoRefreshToggle" checked>
                    <label for="autoRefreshToggle">Auto-Refresh Status</label>
                </div>
            </div>

            <div class="settings-section">
                <h3><i class="fas fa-globe"></i> Language</h3>
                <div class="settings-item">
                    <input type="radio" name="language" id="langEn" value="en" checked onchange="setLanguage('en')">
                    <label for="langEn">English</label>
                </div>
                <div class="settings-item">
                    <input type="radio" name="language" id="langSw" value="sw" onchange="setLanguage('sw')">
                    <label for="langSw">Siswati</label>
                </div>
            </div>

            <div class="settings-section">
                <h3><i class="fas fa-info-circle"></i> System Info</h3>
                <div class="settings-item">
                    <label><i class="fas fa-cube"></i> Version: 2.4.0</label>
                </div>
                <div class="settings-item">
                    <label><i class="fas fa-server"></i> Status: Production</label>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="container">
        <!-- HOME SECTION (HERO) -->
        <section class="nav-sections active" id="home-section">
            <section class="hero">
                <div class="hero-left">
                    <div class="carousel-container">
                        <div class="carousel-wrapper" id="carouselWrapper">
                            <div class="carousel-slide active">
                                <img src="./images/1.jpg" alt="Equipment Management" onerror="handleImageError(this)">
                                <div class="carousel-loading" style="display: none;">Loading Image...</div>
                            </div>
                            <div class="carousel-slide">
                                <img src="./images/2.jpg" alt="Maintenance Service" onerror="handleImageError(this)">
                                <div class="carousel-loading" style="display: none;">Loading Image...</div>
                            </div>
                            <div class="carousel-slide">
                                <img src="./images/3.jpg" alt="Technical Support" onerror="handleImageError(this)">
                                <div class="carousel-loading" style="display: none;">Loading Image...</div>
                            </div>
                        </div>

                        <button class="carousel-nav prev" onclick="prevSlide()">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="carousel-nav next" onclick="nextSlide()">
                            <i class="fas fa-chevron-right"></i>
                        </button>

                        <div class="carousel-controls">
                            <div class="carousel-dot active" onclick="goToSlide(0)"></div>
                            <div class="carousel-dot" onclick="goToSlide(1)"></div>
                            <div class="carousel-dot" onclick="goToSlide(2)"></div>
                        </div>

                        <div class="image-decorations"></div>

                        <div class="status-badge">
                            <div class="status-dot-large operating" id="badgeStatus"></div>
                            <span id="badgeText">System Online</span>
                        </div>
                    </div>
                </div>

                <div class="hero-right">
                    <h1>Welcome to <span class="highlight">BUSIQUIP</span></h1>
                    <p>Professional Equipment Fault Management & Maintenance Service System. Report faults, track repairs in real-time, and manage maintenance services with our advanced, reliable platform trusted across Eswatini.</p>

                    <!-- INFO BOXES -->
                    <div class="info-container">
                        <div class="info-box">
                            <div class="info-icon">🕒</div>
                            <div class="info-label">Time</div>
                            <div class="info-value" id="current-time">--:--</div>
                        </div>
                        <div class="info-box">
                            <div class="info-icon">📅</div>
                            <div class="info-label">Date</div>
                            <div class="info-value" id="current-date">--/--</div>
                        </div>
                        <div class="info-box">
                            <div class="info-icon"><i class="fas fa-phone"></i></div>
                            <div class="info-label">Office</div>
                            <div class="info-value">+268 2404 6000</div>
                        </div>
                        <div class="info-box">
                            <div class="info-icon"><i class="fas fa-clock"></i></div>
                            <div class="info-label">Hours</div>
                            <div class="info-value">8AM-5PM</div>
                        </div>
                    </div>

                    <!-- ACTION BUTTONS -->
                    <div class="button-group">
                        <a href="modules/clients/client_login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Client Login
                        </a>
                        <a href="modules/staff/staff_login.php" class="btn btn-secondary">
                            <i class="fas fa-hard-hat"></i> Staff Login
                        </a>
                    </div>

                    <!-- SOCIAL MEDIA -->
                    <div>
                        <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 15px; font-weight: 700;">
                            <i class="fas fa-share-alt"></i> Connect With Us
                        </div>
                        <div class="social-container">
                            <a href="https://wa.me/26876171513" target="_blank" class="social-btn" title="WhatsApp - Busiquip Eswatini">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="https://www.facebook.com/busiquip" target="_blank" class="social-btn" title="Facebook - Busiquip Eswatini">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="https://x.com/busiquip" target="_blank" class="social-btn" title="X (Twitter) - Busiquip Eswatini">
                                <i class="fab fa-x-twitter"></i>
                            </a>
                            <a href="https://www.instagram.com/busiquip" target="_blank" class="social-btn" title="Instagram - Busiquip Eswatini">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="https://www.linkedin.com/company/busiquip" target="_blank" class="social-btn" title="LinkedIn - Busiquip Eswatini">
                                <i class="fab fa-linkedin-in"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </section>

        <!-- SERVICES SECTION -->
        <section class="nav-sections" id="services-section">
            <h2 class="section-title">Our Services</h2>
            <p class="section-subtitle">Comprehensive equipment maintenance and fault management solutions</p>
            
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-tools"></i></div>
                    <h3>Equipment Repair</h3>
                    <p>Professional repair services for all types of business equipment. Our certified technicians diagnose and fix issues quickly to minimize downtime.</p>
                    <ul>
                        <li>24/7 Emergency Support</li>
                        <li>On-site Repairs</li>
                        <li>Warranty Coverage</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-calendar-check"></i></div>
                    <h3>Preventive Maintenance</h3>
                    <p>Regular maintenance programs to prevent equipment failures before they occur, extending equipment lifespan and reducing repair costs.</p>
                    <ul>
                        <li>Monthly Inspections</li>
                        <li>Scheduled Services</li>
                        <li>Performance Reports</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-stethoscope"></i></div>
                    <h3>Fault Diagnostics</h3>
                    <p>Advanced diagnostic services to identify equipment faults accurately. We use cutting-edge technology for quick troubleshooting.</p>
                    <ul>
                        <li>Computer Diagnostics</li>
                        <li>Root Cause Analysis</li>
                        <li>Solution Planning</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-shipping-fast"></i></div>
                    <h3>Equipment Logistics</h3>
                    <p>Secure pickup and delivery services for equipment requiring off-site repairs. We handle transportation with care and accountability.</p>
                    <ul>
                        <li>Free Pickup Service</li>
                        <li>Insured Transport</li>
                        <li>Tracking System</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-chart-line"></i></div>
                    <h3>Performance Analytics</h3>
                    <p>Detailed reports and analytics on equipment performance, maintenance history, and cost optimization recommendations.</p>
                    <ul>
                        <li>Real-time Tracking</li>
                        <li>Monthly Reports</li>
                        <li>Cost Analysis</li>
                    </ul>
                </div>

                <div class="service-card">
                    <div class="service-icon"><i class="fas fa-chalkboard-user"></i></div>
                    <h3>Training & Support</h3>
                    <p>Comprehensive training programs for your team on equipment operation, maintenance best practices, and troubleshooting techniques.</p>
                    <ul>
                        <li>Online Courses</li>
                        <li>On-site Training</li>
                        <li>Technical Support</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- FEATURES SECTION -->
        <section class="nav-sections" id="features-section">
            <h2 class="section-title">Why Choose BUSIQUIP</h2>
            <p class="section-subtitle">Industry-leading solutions with proven reliability and customer satisfaction</p>
            
            <div class="features">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-bullseye"></i></div>
                    <h3>Easy Fault Reporting</h3>
                    <p>Report equipment faults quickly and easily through our intuitive mobile and web interface. One-click fault submission with detailed tracking.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-chart-pie"></i></div>
                    <h3>Real-Time Tracking</h3>
                    <p>Monitor repair progress in real-time with live updates, notifications, and status changes. Know exactly where your equipment is at all times.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-user-tie"></i></div>
                    <h3>Expert Technicians</h3>
                    <p>Our certified and experienced technicians ensure quality repairs for all equipment types with industry-standard practices and certifications.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                    <h3>Mobile Accessible</h3>
                    <p>Access the system anywhere, anytime with full mobile support. Responsive design works perfectly on phones, tablets, and desktops.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-coins"></i></div>
                    <h3>Transparent Pricing</h3>
                    <p>Clear quotations and invoicing with no hidden charges. All costs are clearly itemized and agreed upon before work begins.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                    <h3>Secure & Reliable</h3>
                    <p>Enterprise-grade security with encrypted data transmission, 99.9% uptime guarantee, and regular backups for peace of mind.</p>
                </div>
            </div>
        </section>

        <!-- CONTACT SECTION -->
        <section class="nav-sections" id="contact-section">
            <h2 class="section-title">Get In Touch</h2>
            <p class="section-subtitle">Contact BUSIQUIP for equipment repair and maintenance services</p>
            
            <div class="contact-container">
                <div class="contact-info">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-map-pin"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Physical Location</h4>
                            <p>
                                Busiquip (Pty) Ltd<br>
                                Mhlambanyatsi Road, Mbabane<br>
                                Hhohho Region, Eswatini<br>
                                P.O. Box 4234, Mbabane H100
                            </p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Phone Support</h4>
                            <p>
                                Main Office: <a href="tel:+26824046000">+268 2404 6000</a><br>
                                WhatsApp: <a href="https://wa.me/26876171513">+268 7617 1513</a><br>
                                Support Hours: 8:00 AM – 5:00 PM (Mon–Fri)
                            </p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Email Support</h4>
                            <p>
                                <a href="mailto:info@busiquip.co.sz">info@busiquip.co.sz</a><br>
                                <a href="mailto:support@busiquip.co.sz">support@busiquip.co.sz</a><br>
                                <a href="mailto:repairs@busiquip.co.sz">repairs@busiquip.co.sz</a>
                            </p>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="contact-details">
                            <h4>Business Hours</h4>
                            <p>
                                Monday – Friday: 8:00 AM – 5:00 PM<br>
                                Saturday: 9:00 AM – 1:00 PM<br>
                                Sunday: Closed
                            </p>
                        </div>
                    </div>
                </div>

                <div class="map-container">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3574.123456789!2d31.1333!3d-26.3167!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x1ee8c3b4e2f3a5c7%3A0x1234567890abcdef!2sBusiquip!5e0!3m2!1sen!2ssz!4v1716000000000!5m2!1sen!2ssz&q=Busiquip+Mbabane+Eswatini"
                        width="100%"
                        height="100%"
                        style="border:0; border-radius: 25px;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Busiquip Location - Mbabane, Eswatini">
                    </iframe>
                </div>
            </div>
        </section>
    </div>

    <!-- FOOTER -->
    <footer>
        <div class="footer-content">
            <span><i class="fas fa-copyright"></i> <?php
ini_set("display_errors", 1);
error_reporting(E_ALL);
echo date("Y"); ?> BUSIQUIP</span>
            <span>•</span>
            <span><i class="fas fa-cog"></i> Professional Equipment Fault Management System</span>
            <span>•</span>
            <span><i class="fas fa-map-marker-alt"></i> Eswatini</span>
            <span>•</span>
            <span><i class="fas fa-cube"></i> Version 2.4.0</span>
        </div>
    </footer>

    <script>
        // ===== CONFIGURATION =====
        let currentLanguage = localStorage.getItem('busiquip-language') || 'en';
        let currentTheme = localStorage.getItem('busiquip-theme') || 'dark';
        let currentSlide = 0;
        let slideInterval;

        // ===== IMAGE ERROR HANDLER =====
        function handleImageError(img) {
            img.style.display = 'none';
            const loadingDiv = img.nextElementSibling;
            if (loadingDiv) {
                loadingDiv.style.display = 'flex';
            }
        }

        // ===== CAROUSEL FUNCTIONS =====
        function startCarousel() {
            clearInterval(slideInterval);
            slideInterval = setInterval(() => {
                currentSlide = (currentSlide + 1) % 3;
                updateCarousel();
            }, 4000);
        }

        function updateCarousel() {
            const slides = document.querySelectorAll('.carousel-slide');
            const dots = document.querySelectorAll('.carousel-dot');

            slides.forEach((slide, index) => {
                slide.classList.remove('active', 'prev');
                dots[index].classList.remove('active');
                if (index !== currentSlide) {
                    slide.classList.add('prev');
                }
            });

            slides[currentSlide].classList.add('active');
            dots[currentSlide].classList.add('active');
        }

        function nextSlide() {
            currentSlide = (currentSlide + 1) % 3;
            updateCarousel();
            startCarousel();
        }

        function prevSlide() {
            currentSlide = (currentSlide - 1 + 3) % 3;
            updateCarousel();
            startCarousel();
        }

        function goToSlide(index) {
            currentSlide = index;
            updateCarousel();
            startCarousel();
        }

        // ===== OPERATIONAL STATUS =====
        function checkOperationalStatus() {
            const now = new Date();
            const dayOfWeek = now.getDay();
            const hours = now.getHours();
            const minutes = now.getMinutes();
            const totalMinutes = hours * 60 + minutes;

            const isOperating = dayOfWeek >= 1 && dayOfWeek <= 5 && totalMinutes >= 480 && totalMinutes < 1020;
            
            const indicator = document.getElementById('statusIndicator');
            const statusText = document.getElementById('statusText');
            const badgeStatus = document.getElementById('badgeStatus');
            const badgeText = document.getElementById('badgeText');

            if (isOperating) {
                indicator.classList.remove('closed');
                indicator.classList.add('open');
                statusText.textContent = 'OPERATING';
                
                badgeStatus.classList.remove('closed');
                badgeStatus.classList.add('operating');
                badgeText.textContent = 'System Online';
            } else {
                indicator.classList.remove('open');
                indicator.classList.add('closed');
                statusText.textContent = 'CLOSED';
                
                badgeStatus.classList.remove('operating');
                badgeStatus.classList.add('closed');
                badgeText.textContent = 'System Offline';
            }
        }

        // ===== TIME & DATE UPDATE =====
        function updateDateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true
            });
            const dateString = now.toLocaleDateString('en-US', { 
                weekday: 'short', 
                month: 'short', 
                day: 'numeric'
            });
            
            document.getElementById('current-time').textContent = timeString;
            document.getElementById('current-date').textContent = dateString;
            
            checkOperationalStatus();
        }

        // ===== THEME MANAGEMENT =====
        function setTheme(theme) {
            document.body.className = '';
            if (theme !== 'dark') {
                document.body.classList.add(theme + '-mode');
            }
            
            document.querySelectorAll('.theme-option').forEach(opt => {
                opt.classList.remove('active');
            });
            document.querySelector(`[data-theme="${theme}"]`).classList.add('active');
            
            currentTheme = theme;
            localStorage.setItem('busiquip-theme', theme);
        }

        // ===== SETTINGS MODAL =====
        function toggleSettings() {
            const modal = document.getElementById('settingsModal');
            modal.classList.toggle('active');
        }

        document.getElementById('settingsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });

        // ===== NAVIGATION SECTIONS =====
        function showSection(sectionName) {
            document.querySelectorAll('.nav-sections').forEach(section => {
                section.classList.remove('active');
            });
            document.getElementById(sectionName + '-section').classList.add('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ===== LANGUAGE TOGGLE =====
        function setLanguage(lang) {
            currentLanguage = lang;
            localStorage.setItem('busiquip-language', lang);
        }

        // ===== INITIALIZATION =====
        document.addEventListener('DOMContentLoaded', function() {
            setTheme(currentTheme);
            updateDateTime();
            setInterval(updateDateTime, 1000);
            setInterval(checkOperationalStatus, 60000);
            startCarousel();
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>