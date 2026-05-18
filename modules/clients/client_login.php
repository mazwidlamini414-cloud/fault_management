<?php
/**
 * BUSIQUIP ESWATINI LTD – Client Login
 * Includes: Login · Forgot Password · Reset Password · Splash
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
include(__DIR__ . "/../../config/database.php");

/* ─────────────────────────────────────────────
   MAILER HELPER  (PHPMailer if available, else mail())
   ───────────────────────────────────────────── */
function sendResetEmail(string $to, string $name, string $link): bool {
    $subject = 'BUSIQUIP – Password Reset Request';
    $body    = "
Dear {$name},

You requested a password reset for your BUSIQUIP Client Portal account.

Click the link below to set a new password (valid for 30 minutes):

{$link}

If you did not request this, please ignore this email – your password will not change.

— BUSIQUIP Eswatini Security Team
";
    // Try PHPMailer first (autoloaded via Composer)
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST')     ? SMTP_HOST     : 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('SMTP_USER')     ? SMTP_USER     : '';
            $mail->Password   = defined('SMTP_PASS')     ? SMTP_PASS     : '';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = defined('SMTP_PORT')     ? SMTP_PORT     : 587;
            $mail->setFrom(
                defined('SMTP_FROM') ? SMTP_FROM : 'noreply@busiquip.co.sz',
                'BUSIQUIP Eswatini'
            );
            $mail->addAddress($to, $name);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('PHPMailer: ' . $e->getMessage());
        }
    }
    // Fallback: PHP mail()
    $headers  = "From: noreply@busiquip.co.sz\r\n";
    $headers .= "Reply-To: noreply@busiquip.co.sz\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    return mail($to, $subject, $body, $headers);
}

/* ─────────────────────────────────────────────
   ENSURE password_resets TABLE EXISTS
   ───────────────────────────────────────────── */
$conn->query("
    CREATE TABLE IF NOT EXISTS password_resets (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        email       VARCHAR(255) NOT NULL,
        token       VARCHAR(64)  NOT NULL UNIQUE,
        expires_at  DATETIME     NOT NULL,
        used        TINYINT(1)   DEFAULT 0,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ─────────────────────────────────────────────
   STATE VARIABLES
   ───────────────────────────────────────────── */
$showSplash   = false;
$view         = 'login';      // login | forgot | reset | reset_done | email_sent
$error        = '';
$success      = '';
$resetToken   = '';

/* Show forgot form when JS submits the dummy forgot_show action */
if (isset($_POST['action']) && $_POST['action'] === 'forgot_show') {
    $view = 'forgot';
}

/* ─────────────────────────────────────────────
   1. LOGIN
   ───────────────────────────────────────────── */
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
        $view  = 'login';
    } else {
        $stmt = $conn->prepare("SELECT * FROM client WHERE COMPANY_EMAIL = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($client && password_verify($password, $client['PASSWORD_HASH'])) {
            $_SESSION['client_id']      = $client['CLIENT_ID'];
            $_SESSION['client_name']    = $client['COMPANY_NAME'];
            $_SESSION['client_contact'] = $client['CONTACT_PERSON_NAME'];
            $_SESSION['client_email']   = $client['COMPANY_EMAIL'];
            $_SESSION['client_type']    = $client['CLIENT_TYPE'];
            $showSplash = true;
        } else {
            $error = 'Invalid email or password. Please try again.';
            $view  = 'login';
        }
    }
}

/* ─────────────────────────────────────────────
   2. FORGOT PASSWORD – send reset email
   ───────────────────────────────────────────── */
if (isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $view  = 'forgot';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT CLIENT_ID, COMPANY_NAME, COMPANY_EMAIL FROM client WHERE LOWER(COMPANY_EMAIL)=? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /* Always show the "email sent" view to prevent user enumeration */
        if ($client) {
            // Remove old unused tokens for this email
            $del = $conn->prepare("DELETE FROM password_resets WHERE email=? AND used=0");
            $del->bind_param('s', $email);
            $del->execute();
            $del->close();

            $token   = bin2hex(random_bytes(32));   // 64-char hex
            $expires = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

            $ins = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)");
            $ins->bind_param('sss', $email, $token, $expires);
            $ins->execute();
            $ins->close();

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'];
            $script   = $_SERVER['SCRIPT_NAME'];
            $link     = "{$protocol}://{$host}{$script}?action=reset&token={$token}";

            sendResetEmail($email, $client['COMPANY_NAME'], $link);
        }
        $view = 'email_sent';
    }
}

/* ─────────────────────────────────────────────
   3. RESET FORM – show form (GET)
   ───────────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action'] === 'reset' && isset($_GET['token'])) {
    $resetToken = trim($_GET['token']);
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('s', $resetToken);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $view = 'reset';
    } else {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
        $view  = 'forgot';
    }
}

/* ─────────────────────────────────────────────
   4. RESET PASSWORD – process new password (POST)
   ───────────────────────────────────────────── */
if (isset($_POST['action']) && $_POST['action'] === 'do_reset') {
    $resetToken    = trim($_POST['token']        ?? '');
    $newPass       = trim($_POST['new_password'] ?? '');
    $confirmPass   = trim($_POST['confirm_password'] ?? '');
    $view          = 'reset';

    if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param('s', $resetToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $error = 'Reset link expired or already used. Please request a new one.';
            $view  = 'forgot';
        } else {
            $hash  = password_hash($newPass, PASSWORD_BCRYPT);
            $email = $row['email'];

            // Update client password
            $upd = $conn->prepare("UPDATE CLIENT SET PASSWORD_HASH=? WHERE LOWER(COMPANY_EMAIL)=?");
            $upd->bind_param('ss', $hash, $email);
            $upd->execute();
            $upd->close();

            // Mark token used
            $mark = $conn->prepare("UPDATE password_resets SET used=1 WHERE token=?");
            $mark->bind_param('s', $resetToken);
            $mark->execute();
            $mark->close();

            $view    = 'reset_done';
            $success = 'Your password has been changed. You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $showSplash ? 'Client Portal – Loading' : 'Client Login | BUSIQUIP ESWATINI'; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ──────────────────────────────────────────────
   VARIABLES
────────────────────────────────────────────── */
:root {
    --emerald:   #10B981;
    --teal:      #0D9488;
    --emerald2:  #34d399;
    --gold:      #FFD700;
    --dark:      #0F172A;
    --darker:    #0B1221;
    --card:      rgba(20,30,55,0.96);
    --border:    rgba(16,185,129,0.25);
    --danger:    #EF4444;
    --warning:   #F59E0B;
    --muted:     #94A3B8;
    --tr:        all 0.35s cubic-bezier(.4,0,.2,1);
}

/* ──────────────────────────────────────────────
   RESET & BASE
────────────────────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html, body { width:100%; height:100%; }
body {
    font-family: 'Sora', sans-serif;
    background: linear-gradient(135deg, var(--dark), var(--darker));
    color: #fff;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    position: relative;
    overflow-x: hidden;
    overflow-y: auto;
}

/* ── Background image + overlay ── */
body::before {
    content:'';
    position:fixed; inset:0;
    background: url('<?= BASE_URL ?>/images/2.jpg') center/cover no-repeat;
    z-index:-2;
}
body::after {
    content:'';
    position:fixed; inset:0;
    background:
        linear-gradient(135deg, rgba(15,23,42,.97) 0%, rgba(11,18,33,.97) 100%),
        radial-gradient(circle at 20% 80%, rgba(13,148,136,.2) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(16,185,129,.12) 0%, transparent 50%);
    z-index:-1;
}

/* ── Animated grid ── */
.grid-bg {
    position:fixed; inset:0; z-index:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(16,185,129,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(16,185,129,.04) 1px, transparent 1px);
    background-size:50px 50px;
    animation: grid-move 25s linear infinite;
}
@keyframes grid-move {
    to { background-position:50px 50px; }
}

/* ── Blobs ── */
.blob { position:fixed; border-radius:50%; filter:blur(90px); opacity:.08; z-index:0; pointer-events:none; }
.b1 { width:500px; height:500px; top:-150px; left:-150px; background:radial-gradient(circle,var(--teal),transparent); animation:blob 18s ease-in-out infinite; }
.b2 { width:400px; height:400px; bottom:-100px; right:-100px; background:radial-gradient(circle,var(--emerald),transparent); animation:blob 22s ease-in-out infinite reverse; }
@keyframes blob {
    0%,100%{transform:translate(0,0) scale(1);}
    50%{transform:translate(60px,-80px) scale(1.2);}
}

/* ──────────────────────────────────────────────
   FLOATING LOGO  (top of page, above card)
────────────────────────────────────────────── */
.floating-logo {
    position: fixed;
    top: 22px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 200;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    pointer-events: none;
}
.floating-logo-img-wrap {
    position: relative;
    width: 68px; height: 68px;
}
.floating-logo-ring {
    position: absolute; inset: -7px;
    border: 2px solid rgba(16,185,129,.4);
    border-radius: 50%;
    animation: logo-spin 8s linear infinite;
}
.floating-logo-ring2 {
    position: absolute; inset: -14px;
    border: 1px dashed rgba(255,215,0,.22);
    border-radius: 50%;
    animation: logo-spin 14s linear infinite reverse;
}
@keyframes logo-spin { to { transform:rotate(360deg); } }
.floating-logo-img {
    width: 68px; height: 68px;
    object-fit: contain;
    border-radius: 50%;
    background: rgba(16,185,129,.12);
    border: 2px solid rgba(16,185,129,.35);
    padding: 4px;
    animation: logo-float 3.5s ease-in-out infinite;
    filter: drop-shadow(0 0 14px rgba(16,185,129,.5));
}
@keyframes logo-float {
    0%,100%{ transform:translateY(0px); filter:drop-shadow(0 0 12px rgba(16,185,129,.5)); }
    50%    { transform:translateY(-10px); filter:drop-shadow(0 8px 20px rgba(16,185,129,.7)); }
}
.floating-logo-label {
    font-family: 'Rajdhani', sans-serif;
    font-size: 11px; font-weight: 700;
    letter-spacing: 2.5px;
    color: var(--emerald2);
    text-transform: uppercase;
    margin-top: 5px;
    text-shadow: 0 0 10px rgba(16,185,129,.5);
}

/* ──────────────────────────────────────────────
   TOP-RIGHT CONTROLS
────────────────────────────────────────────── */
.top-controls {
    position: fixed; top:22px; right:22px;
    display:flex; gap:12px; z-index:200;
}
.ctrl-btn {
    width:44px; height:44px; border-radius:50%;
    border:1.5px solid var(--border);
    background:rgba(20,30,55,.85);
    color:var(--emerald); font-size:18px;
    cursor:pointer; display:flex;
    align-items:center; justify-content:center;
    transition:var(--tr); backdrop-filter:blur(12px);
}
.ctrl-btn:hover {
    border-color:var(--emerald);
    background:rgba(16,185,129,.15);
    transform:scale(1.1) rotate(10deg);
}

/* ──────────────────────────────────────────────
   CARD WRAPPER
────────────────────────────────────────────── */
.login-wrapper {
    position:relative; z-index:10;
    width:100%; display:flex;
    justify-content:center; align-items:center;
    padding: 110px 20px 40px; /* space for floating logo */
    min-height:100vh;
}

.login-container {
    background: var(--card);
    backdrop-filter: blur(32px);
    padding: 44px 40px 36px;
    border-radius: 22px;
    width: 100%; max-width: 430px;
    border: 1.5px solid var(--border);
    box-shadow: 0 30px 80px rgba(0,0,0,.55), 0 0 40px rgba(16,185,129,.06);
    position: relative; overflow: hidden;
    animation: card-in .7s cubic-bezier(.3,1.2,.5,1) both;
}
@keyframes card-in {
    from { opacity:0; transform:translateY(36px) scale(.97); }
    to   { opacity:1; transform:none; }
}
.login-container::before {
    content:'';
    position:absolute; top:-60%; right:-60%;
    width:200%; height:200%;
    background:radial-gradient(circle, rgba(16,185,129,.07) 0%, transparent 65%);
    animation: shimmer 9s ease-in-out infinite;
    pointer-events:none;
}
@keyframes shimmer {
    0%,100%{transform:translate(0,0);}
    50%{transform:translate(40px,40px);}
}

/* ──────────────────────────────────────────────
   CARD HEADER
────────────────────────────────────────────── */
.card-header {
    text-align:center;
    margin-bottom:30px;
    padding-bottom:22px;
    border-bottom:1.5px solid rgba(16,185,129,.2);
}
.card-header h1 {
    font-family:'Rajdhani',sans-serif;
    font-size:28px; font-weight:700;
    background:linear-gradient(135deg,var(--emerald),var(--emerald2));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    margin-bottom:4px;
}
.card-header p {
    font-size:11px; color:var(--muted);
    text-transform:uppercase; letter-spacing:2px; font-weight:600;
}

/* ──────────────────────────────────────────────
   FORM ELEMENTS
────────────────────────────────────────────── */
.form-group { margin-bottom:18px; }
.form-group label {
    display:block; font-size:11px; font-weight:700;
    color:var(--emerald); margin-bottom:7px;
    text-transform:uppercase; letter-spacing:.8px;
}
.input-wrap { position:relative; }
.input-wrap i.prefix {
    position:absolute; left:14px; top:50%; transform:translateY(-50%);
    color:var(--teal); font-size:15px; pointer-events:none;
}
.input-wrap input {
    width:100%;
    padding:13px 14px 13px 40px;
    border-radius:11px;
    border:1.5px solid rgba(16,185,129,.2);
    background:rgba(10,18,40,.8);
    color:#fff; font-size:14px;
    font-family:'Sora',sans-serif;
    transition:var(--tr);
}
.input-wrap input::placeholder { color:#4B5A74; }
.input-wrap input:focus {
    outline:none;
    border-color:var(--emerald);
    background:rgba(10,18,40,.95);
    box-shadow:0 0 0 3px rgba(16,185,129,.15);
}
.eye-toggle {
    position:absolute; right:13px; top:50%;
    transform:translateY(-50%);
    background:none; border:none; color:var(--muted);
    cursor:pointer; font-size:15px;
    transition:var(--tr);
}
.eye-toggle:hover { color:var(--emerald); }

/* strength bar */
.strength-bar { display:flex; gap:4px; margin-top:6px; }
.strength-seg {
    flex:1; height:4px; border-radius:4px;
    background:rgba(255,255,255,.1);
    transition:background .3s;
}
.strength-label { font-size:10px; color:var(--muted); margin-top:4px; text-align:right; }

/* ──────────────────────────────────────────────
   BUTTONS
────────────────────────────────────────────── */
.btn-primary {
    width:100%;
    padding:14px;
    background:linear-gradient(135deg,var(--emerald),var(--teal));
    color:#fff;
    border:none; border-radius:11px;
    cursor:pointer; font-size:15px; font-weight:800;
    text-transform:uppercase; letter-spacing:1px;
    margin-top:8px;
    transition:var(--tr);
    position:relative; overflow:hidden;
    font-family:'Sora',sans-serif;
    box-shadow:0 12px 35px rgba(16,185,129,.3);
    display:flex; align-items:center; justify-content:center; gap:8px;
}
.btn-primary::before {
    content:'';
    position:absolute; top:0; left:-100%;
    width:100%; height:100%;
    background:rgba(255,255,255,.18);
    transition:left .4s ease;
}
.btn-primary:hover::before { left:100%; }
.btn-primary:hover {
    transform:translateY(-3px);
    box-shadow:0 20px 50px rgba(16,185,129,.45);
}
.btn-primary:active { transform:translateY(0); }
.btn-primary:disabled { opacity:.6; cursor:not-allowed; transform:none!important; }

.btn-text {
    background:none; border:none; cursor:pointer;
    color:var(--emerald); font-size:13px; font-weight:700;
    font-family:'Sora',sans-serif;
    transition:var(--tr); padding:0;
    text-decoration:underline; text-underline-offset:3px;
}
.btn-text:hover { color:var(--emerald2); }

/* ──────────────────────────────────────────────
   ALERTS
────────────────────────────────────────────── */
.alert {
    padding:12px 14px; border-radius:10px;
    font-size:13px; font-weight:600;
    margin-bottom:16px; display:flex; gap:10px; align-items:flex-start;
    animation:slide-down .4s ease;
}
@keyframes slide-down {
    from{opacity:0;transform:translateY(-10px);}
    to{opacity:1;transform:none;}
}
.alert-error   { background:rgba(239,68,68,.13); border:1.5px solid rgba(239,68,68,.4); color:#FCA5A5; }
.alert-success { background:rgba(16,185,129,.13); border:1.5px solid rgba(16,185,129,.4); color:#6EE7B7; }
.alert-info    { background:rgba(59,130,246,.13); border:1.5px solid rgba(59,130,246,.4); color:#93C5FD; }

/* ──────────────────────────────────────────────
   CARD FOOTER LINKS
────────────────────────────────────────────── */
.card-footer {
    text-align:center; margin-top:22px;
    padding-top:18px;
    border-top:1px solid rgba(16,185,129,.15);
    display:flex; flex-direction:column; gap:8px;
}
.card-footer a, .card-footer button.btn-text {
    color:var(--emerald); font-size:13px; font-weight:700;
    text-decoration:none; transition:var(--tr);
    display:inline-flex; align-items:center; gap:5px;
}
.card-footer a:hover { color:var(--emerald2); }

/* ──────────────────────────────────────────────
   STEP INDICATOR (for password reset flow)
────────────────────────────────────────────── */
.steps {
    display:flex; justify-content:center; gap:0;
    margin-bottom:24px; position:relative;
}
.steps::before {
    content:''; position:absolute;
    top:16px; left:20%; right:20%; height:2px;
    background:rgba(16,185,129,.2);
}
.step {
    display:flex; flex-direction:column; align-items:center;
    gap:6px; flex:1; position:relative; z-index:1;
}
.step-circle {
    width:32px; height:32px; border-radius:50%;
    border:2px solid rgba(16,185,129,.3);
    background:rgba(10,18,40,.9);
    display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:700; color:var(--muted);
    transition:var(--tr);
}
.step.done .step-circle  { background:var(--emerald); border-color:var(--emerald); color:#fff; }
.step.active .step-circle{ border-color:var(--emerald); color:var(--emerald); box-shadow:0 0 0 4px rgba(16,185,129,.18); }
.step-label { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; font-weight:600; }
.step.active .step-label { color:var(--emerald); }
.step.done  .step-label  { color:var(--emerald2); }

/* ──────────────────────────────────────────────
   SUCCESS ICON
────────────────────────────────────────────── */
.success-icon {
    width:80px; height:80px; border-radius:50%;
    background:rgba(16,185,129,.15);
    border:2px solid var(--emerald);
    display:flex; align-items:center; justify-content:center;
    margin:0 auto 20px;
    font-size:38px;
    animation:pop .6s cubic-bezier(.3,1.5,.5,1) both;
}
@keyframes pop {
    from{transform:scale(0);opacity:0;}
    to{transform:scale(1);opacity:1;}
}

/* ──────────────────────────────────────────────
   SPLASH SCREEN
────────────────────────────────────────────── */
.splash-overlay {
    position:fixed; inset:0; z-index:9999;
    background:linear-gradient(135deg,var(--dark),var(--darker));
    display:flex; justify-content:center; align-items:center;
    animation:fadeIn .6s ease;
}
@keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
.splash-content { text-align:center; position:relative; z-index:1; }
.splash-logo-wrap {
    width:130px; height:130px; margin:0 auto 28px;
    position:relative;
}
.splash-logo-img {
    width:130px; height:130px; border-radius:50%;
    object-fit:contain; padding:8px;
    background:rgba(16,185,129,.12);
    border:2px solid rgba(16,185,129,.3);
    animation:logo-float 2.5s ease-in-out infinite;
    filter:drop-shadow(0 0 18px rgba(16,185,129,.6));
}
.splash-ring {
    position:absolute; inset:-10px;
    border:2px solid rgba(16,185,129,.3);
    border-radius:50%;
    animation:logo-spin 6s linear infinite;
}
.splash-ring2 {
    position:absolute; inset:-20px;
    border:1px dashed rgba(255,215,0,.2);
    border-radius:50%;
    animation:logo-spin 10s linear infinite reverse;
}
.splash-company {
    font-family:'Rajdhani',sans-serif;
    font-size:40px; font-weight:700;
    background:linear-gradient(135deg,var(--emerald),var(--emerald2));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    margin-bottom:6px;
}
.splash-sub { font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:2px; margin-bottom:20px; }
.splash-badge {
    display:inline-block; padding:10px 24px;
    background:rgba(16,185,129,.15);
    border:1.5px solid var(--emerald);
    border-radius:50px; font-size:14px; font-weight:700;
    color:var(--emerald2); margin-bottom:30px;
}
.dots { display:flex; justify-content:center; gap:10px; margin:20px 0; }
.dot {
    width:11px; height:11px; border-radius:50%;
    background:linear-gradient(135deg,var(--emerald),var(--teal));
    animation:bounce 1.4s infinite;
}
.dot:nth-child(2){animation-delay:.2s;}
.dot:nth-child(3){animation-delay:.4s;}
@keyframes bounce {
    0%,60%,100%{transform:translateY(0);opacity:.4;}
    30%{transform:translateY(-14px);opacity:1;}
}
.splash-progress-track {
    width:300px; height:6px; margin:0 auto 20px;
    background:rgba(16,185,129,.15);
    border-radius:6px; overflow:hidden;
    border:1px solid rgba(16,185,129,.25);
}
.splash-progress-fill {
    height:100%;
    background:linear-gradient(90deg,var(--teal),var(--emerald),var(--emerald2));
    width:0%; border-radius:6px;
    animation:fill-progress 5s ease-in-out forwards;
}
@keyframes fill-progress { 0%{width:0%} 70%{width:100%} 100%{width:100%} }
.splash-welcome { font-size:17px; font-weight:700; color:var(--emerald2); margin-bottom:6px; }
.splash-message { font-size:13px; color:var(--muted); }

/* ──────────────────────────────────────────────
   RESPONSIVE
────────────────────────────────────────────── */
@media(max-width:480px){
    .login-container{padding:36px 22px 28px;}
    .login-wrapper{padding:100px 12px 30px;}
    .floating-logo-img{width:56px;height:56px;}
    .floating-logo-img-wrap{width:56px;height:56px;}
}
</style>
</head>
<body>

<!-- BACKGROUNDS -->
<div class="grid-bg"></div>
<div class="blob b1"></div>
<div class="blob b2"></div>

<!-- FLOATING LOGO (always visible, top centre) -->
<div class="floating-logo">
    <div class="floating-logo-img-wrap">
        <div class="floating-logo-ring"></div>
        <div class="floating-logo-ring2"></div>
        <img src="fault_management/images/logo.png"
             alt="BUSIQUIP Logo"
             class="floating-logo-img"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div style="display:none;width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--emerald));align-items:center;justify-content:center;font-size:30px;border:2px solid rgba(16,185,129,.35);">⚙️</div>
    </div>
    <div class="floating-logo-label">BUSIQUIP</div>
</div>

<!-- TOP-RIGHT CONTROLS -->
<div class="top-controls">
    <button class="ctrl-btn" title="Language" onclick="toggleLanguage()">🌍</button>
    <button class="ctrl-btn" id="themeToggle" title="Theme" onclick="toggleTheme()">🌙</button>
</div>

<?php if ($showSplash): ?>
<!-- ══════════ SPLASH ══════════ -->
<div class="splash-overlay">
    <div class="splash-content">
        <div class="splash-logo-wrap">
            <div class="splash-ring"></div>
            <div class="splash-ring2"></div>
            <img src="fault_management/images/logo.png" alt="Logo"
                 class="splash-logo-img"
                 onerror="this.src=''; this.style.display='none'; this.parentNode.querySelector('.logo-fb').style.display='flex';">
            <div class="logo-fb" style="display:none;position:absolute;inset:0;background:linear-gradient(135deg,var(--teal),var(--emerald));border-radius:50%;align-items:center;justify-content:center;font-size:55px;">⚙️</div>
        </div>
        <div class="splash-company">BUSIQUIP ESWATINI</div>
        <div class="splash-sub">Fault Management System</div>
        <div class="splash-badge">👤 CLIENT PORTAL</div>
        <div class="dots"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>
        <div class="splash-progress-track"><div class="splash-progress-fill"></div></div>
        <div class="splash-welcome">Welcome, <?php echo htmlspecialchars($_SESSION['client_name']); ?>!</div>
        <div class="splash-message">Preparing your portal…</div>
    </div>
</div>
<script>setTimeout(()=>{ window.location.href='client_portal.php'; }, 5200);</script>

<?php else: ?>
<!-- ══════════ VIEWS ══════════ -->
<div class="login-wrapper">
<div class="login-container">

<?php /* ─── LOGIN VIEW ─── */ if ($view === 'login'): ?>
    <div class="card-header">
        <h1 id="title">Client Login</h1>
        <p id="subtitle">BUSIQUIP ESWATINI</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" id="loginForm" novalidate>
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label id="emailLabel">Company Email</label>
            <div class="input-wrap">
                <i class="fas fa-envelope prefix"></i>
                <input type="email" name="email" id="emailInput"
                       placeholder="company@example.com" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label id="passLabel">Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock prefix"></i>
                <input type="password" name="password" id="passwordInput"
                       placeholder="Enter your password" required>
                <button type="button" class="eye-toggle" onclick="toggleEye('passwordInput',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
        <button type="submit" name="login" class="btn-primary" id="loginBtn">
            <i class="fas fa-sign-in-alt"></i> <span>Login to Portal</span>
        </button>
    </form>
    <div class="card-footer">
        <button class="btn-text" onclick="showView('forgot')">
            <i class="fas fa-key"></i> Forgot password?
        </button>
        <a href="register.php" id="registerLink"><i class="fas fa-user-plus"></i> Register here</a>
        <a href="../../index.php" id="homeLink"><i class="fas fa-arrow-left"></i> Back to Home</a>
    </div>

<?php /* ─── FORGOT VIEW ─── */ elseif ($view === 'forgot'): ?>
    <div class="card-header">
        <h1>Forgot Password</h1>
        <p>Enter your registered email</p>
    </div>
    <!-- step indicator -->
    <div class="steps">
        <div class="step active"><div class="step-circle">1</div><div class="step-label">Email</div></div>
        <div class="step"><div class="step-circle">2</div><div class="step-label">Check inbox</div></div>
        <div class="step"><div class="step-circle">3</div><div class="step-label">New password</div></div>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <p style="font-size:13px;color:var(--muted);margin-bottom:20px;text-align:center;">
        We'll send a secure reset link to your registered email address.
    </p>
    <form method="POST" novalidate>
        <input type="hidden" name="action" value="forgot">
        <div class="form-group">
            <label>Company Email</label>
            <div class="input-wrap">
                <i class="fas fa-envelope prefix"></i>
                <input type="email" name="email" placeholder="company@example.com" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
        </div>
        <button type="submit" class="btn-primary">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>
    <div class="card-footer">
        <button class="btn-text" onclick="showView('login')"><i class="fas fa-arrow-left"></i> Back to Login</button>
    </div>

<?php /* ─── EMAIL SENT VIEW ─── */ elseif ($view === 'email_sent'): ?>
    <div class="card-header">
        <h1>Check Your Email</h1>
        <p>Reset link sent</p>
    </div>
    <!-- step indicator -->
    <div class="steps">
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">Email</div></div>
        <div class="step active"><div class="step-circle">2</div><div class="step-label">Check inbox</div></div>
        <div class="step"><div class="step-circle">3</div><div class="step-label">New password</div></div>
    </div>
    <div style="text-align:center;">
        <div class="success-icon" style="font-size:32px;">📬</div>
        <p style="font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:18px;">
            If an account exists for that email, you'll receive a password reset link within a few minutes.<br><br>
            The link expires in <strong style="color:var(--emerald);">30 minutes</strong>.<br>
            Check your <em>spam</em> folder if you don't see it.
        </p>
        <div class="alert alert-info" style="text-align:left;">
            <i class="fas fa-info-circle"></i>
            <span>Didn't receive it? Wait a minute then try again, or contact <strong>support@busiquip.co.sz</strong>.</span>
        </div>
    </div>
    <div class="card-footer">
        <button class="btn-text" onclick="showView('forgot')"><i class="fas fa-redo"></i> Try again</button>
        <button class="btn-text" onclick="showView('login')"><i class="fas fa-arrow-left"></i> Back to Login</button>
    </div>

<?php /* ─── RESET FORM VIEW ─── */ elseif ($view === 'reset'): ?>
    <div class="card-header">
        <h1>New Password</h1>
        <p>Choose a strong password</p>
    </div>
    <!-- step indicator -->
    <div class="steps">
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">Email</div></div>
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">Check inbox</div></div>
        <div class="step active"><div class="step-circle">3</div><div class="step-label">New password</div></div>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" id="resetForm" novalidate>
        <input type="hidden" name="action" value="do_reset">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($resetToken) ?>">
        <div class="form-group">
            <label>New Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock prefix"></i>
                <input type="password" name="new_password" id="newPass"
                       placeholder="At least 8 characters" required
                       oninput="checkStrength(this.value)">
                <button type="button" class="eye-toggle" onclick="toggleEye('newPass',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="strength-bar">
                <div class="strength-seg" id="seg1"></div>
                <div class="strength-seg" id="seg2"></div>
                <div class="strength-seg" id="seg3"></div>
                <div class="strength-seg" id="seg4"></div>
            </div>
            <div class="strength-label" id="strengthLabel"></div>
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock prefix"></i>
                <input type="password" name="confirm_password" id="confirmPass"
                       placeholder="Repeat your password" required
                       oninput="checkMatch()">
                <button type="button" class="eye-toggle" onclick="toggleEye('confirmPass',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div id="matchMsg" style="font-size:11px;margin-top:5px;"></div>
        </div>
        <button type="submit" class="btn-primary" id="resetBtn">
            <i class="fas fa-key"></i> Set New Password
        </button>
    </form>
    <div class="card-footer">
        <button class="btn-text" onclick="showView('login')"><i class="fas fa-arrow-left"></i> Back to Login</button>
    </div>

<?php /* ─── RESET DONE VIEW ─── */ elseif ($view === 'reset_done'): ?>
    <div class="card-header">
        <h1>Password Updated!</h1>
        <p>All done</p>
    </div>
    <!-- step indicator – all done -->
    <div class="steps">
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">Email</div></div>
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">Check inbox</div></div>
        <div class="step done"><div class="step-circle"><i class="fas fa-check"></i></div><div class="step-label">New password</div></div>
    </div>
    <div style="text-align:center;">
        <div class="success-icon">✅</div>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:20px;">
            You can now log in with your new password.<br>
            Redirecting to login in <strong id="cd" style="color:var(--emerald);">5</strong>s…
        </p>
    </div>
    <button class="btn-primary" onclick="showView('login')">
        <i class="fas fa-sign-in-alt"></i> Go to Login
    </button>
    <script>
        let secs = 5;
        const iv = setInterval(()=>{
            secs--;
            document.getElementById('cd').textContent = secs;
            if(secs<=0){ clearInterval(iv); showView('login'); }
        }, 1000);
    </script>

<?php endif; ?>

</div><!-- /login-container -->
</div><!-- /login-wrapper -->
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
    applyLanguage();
    applyTheme();
});

/* ─── VIEW SWITCHER ─── */
function showView(v){
    if(v==='login'){
        window.location.href = window.location.pathname;
    } else if(v==='forgot'){
        // Create a hidden form that triggers the forgot view
        const f = document.createElement('form');
        f.method='POST'; f.action=window.location.pathname;
        const a=document.createElement('input'); a.type='hidden'; a.name='action'; a.value='forgot_show';
        const e=document.createElement('input'); e.type='hidden'; e.name='email'; e.value='';
        f.appendChild(a); f.appendChild(e);
        document.body.appendChild(f);
        f.submit();
    }
}

/* ─── EYE TOGGLE ─── */
function toggleEye(id, btn){
    const inp = document.getElementById(id);
    const ico = btn.querySelector('i');
    if(inp.type==='password'){
        inp.type='text';
        ico.className='fas fa-eye-slash';
    } else {
        inp.type='password';
        ico.className='fas fa-eye';
    }
}

/* ─── PASSWORD STRENGTH ─── */
function checkStrength(val){
    let score=0;
    if(val.length>=8)  score++;
    if(/[A-Z]/.test(val)) score++;
    if(/[0-9]/.test(val)) score++;
    if(/[^A-Za-z0-9]/.test(val)) score++;
    const cols=['','#EF4444','#F59E0B','#10B981','#34d399'];
    const labels=['','Weak','Fair','Good','Strong'];
    for(let i=1;i<=4;i++){
        const s=document.getElementById('seg'+i);
        if(s) s.style.background = i<=score ? cols[score] : 'rgba(255,255,255,.1)';
    }
    const lbl=document.getElementById('strengthLabel');
    if(lbl){ lbl.textContent = val ? labels[score] : ''; lbl.style.color=cols[score]||''; }
}

/* ─── PASSWORD MATCH ─── */
function checkMatch(){
    const a = document.getElementById('newPass')?.value || '';
    const b = document.getElementById('confirmPass')?.value || '';
    const m = document.getElementById('matchMsg');
    if(!m) return;
    if(!b){ m.textContent=''; return; }
    if(a===b){
        m.textContent='✓ Passwords match';
        m.style.color='var(--emerald)';
    } else {
        m.textContent='✗ Passwords do not match';
        m.style.color='var(--danger)';
    }
}

/* ─── FORM SUBMIT LOADER ─── */
document.addEventListener('submit', e=>{
    const btn = e.target.querySelector('.btn-primary');
    if(btn){ btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Please wait…'; }
});

/* ─── LANGUAGE ─── */
let currentLang = localStorage.getItem('clientLang') || 'en';
const t = {
    en:{ title:'Client Login', subtitle:'BUSIQUIP ESWATINI', emailLabel:'Company Email', passLabel:'Password', loginBtn:'Login to Portal', registerLink:'Register here', homeLink:'← Back to Home' },
    ss:{ title:'Kugena Kwabahlanganyeli', subtitle:'BUSIQUIP ESWATINI', emailLabel:'I-imeyili Yenkampani', passLabel:'Iphasiwedi', loginBtn:'Kugena Kwapoketali', registerLink:'Ngena Lapha', homeLink:'← Buyela Ekhaya' }
};
function toggleLanguage(){ currentLang=currentLang==='en'?'ss':'en'; localStorage.setItem('clientLang',currentLang); applyLanguage(); }
function applyLanguage(){
    const lang=t[currentLang];
    if(!lang) return;
    const set=(id,val)=>{ const el=document.getElementById(id); if(el) el.textContent=val; };
    set('title',lang.title); set('subtitle',lang.subtitle);
    set('emailLabel',lang.emailLabel); set('passLabel',lang.passLabel);
    set('registerLink',lang.registerLink); set('homeLink',lang.homeLink);
    const lb=document.getElementById('loginBtn');
    if(lb) lb.innerHTML='<i class="fas fa-sign-in-alt"></i> '+lang.loginBtn;
}

/* ─── THEME ─── */
function toggleTheme(){
    const isDark=localStorage.getItem('clientTheme')==='dark';
    localStorage.setItem('clientTheme', isDark?'light':'dark');
    applyTheme();
}
function applyTheme(){
    const theme=localStorage.getItem('clientTheme')||'dark';
    document.getElementById('themeToggle').textContent = theme==='light'?'☀️':'🌙';
    document.body.style.filter = theme==='light'?'invert(1)':'none';
}
</script>

<?php
/* Handle the dummy "forgot_show" action to render the forgot form without an email */
// (Add to top of PHP block if needed; handled cleanly by adding `forgot_show` case)
?>
</body>
</html>



