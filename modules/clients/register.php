<?php      
error_reporting(E_ALL);      
ini_set('display_errors', 1);      
    
session_start();      

require_once __DIR__ . '/../../config/database.php';

if(empty($_SESSION['csrf_token'])){
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = "";

if(isset($_POST['register'])){
    if(!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])){
        $errors[] = "Invalid request!";
    } else {

        $company_name = trim($_POST['company_name']);
        $company_phone = trim($_POST['company_phone']);
        $company_email = trim($_POST['company_email']);
        $company_address = trim($_POST['company_address']);
        $contact_person_name = trim($_POST['contact_person_name']);
        $client_type = $_POST['client_type'];
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if(strlen($company_name)<2) $errors[]="Invalid company name";
        if(strlen($contact_person_name)<2) $errors[]="Invalid contact person name";
        if(!filter_var($company_email,FILTER_VALIDATE_EMAIL)) $errors[]="Invalid email";
        if(!preg_match('/^\+268[0-9]{8}$/', $company_phone)) $errors[]="Invalid phone number";
        if(strlen($username)<3) $errors[]="Username must be at least 3 characters";
        if(strlen($password)<6) $errors[]="Password too short";
        if($password !== $confirm_password) $errors[]="Passwords do not match";

        if(empty($errors)){
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO CLIENT 
            (COMPANY_NAME, COMPANY_PHONE, COMPANY_EMAIL, COMPANY_ADDRESS, CONTACT_PERSON_NAME, CLIENT_TYPE, USERNAME, PASSWORD_HASH) 
            VALUES (?,?,?,?,?,?,?,?)");

            $stmt->bind_param("ssssssss",
                $company_name, $company_phone, $company_email, $company_address,
                $contact_person_name, $client_type, $username, $hashed
            );

            if($stmt->execute()){
                $success = "✓ Registration successful! Redirecting to login...";
                header("refresh:2;url=client_login.php");
            } else {
                if(strpos($stmt->error, 'Duplicate entry') !== false) {
                    if(strpos($stmt->error, 'COMPANY_EMAIL') !== false) {
                        $errors[] = "Email already registered!";
                    } elseif(strpos($stmt->error, 'USERNAME') !== false) {
                        $errors[] = "Username already taken!";
                    } else {
                        $errors[] = "Registration error!";
                    }
                } else {
                    $errors[] = "Database error: " . $stmt->error;
                }
            }

            $stmt->close();
        }
    }
}

$conn->close();
?>    

<!DOCTYPE html>    
<html lang="en">    
<head>    
<meta charset="UTF-8">    
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Registration - BUSIQUIP ESWATINI</title>    
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>    
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    width: 100%;
    height: auto;
    font-family: 'Inter', sans-serif;
    overflow-x: hidden;
}

:root {
    --primary: #10b981;
    --secondary: #059669;
    --accent: #34d399;
    --dark-bg: #0f172a;
    --card-bg: #1e293b;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --border: #334155;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
}

body {
    background: linear-gradient(135deg, var(--dark-bg) 0%, #1a4d3e 100%);
    display: flex;
    min-height: 100vh;
}

body.light-mode {
    --dark-bg: #f8fafc;
    --card-bg: #ffffff;
    --text-primary: #0f172a;
    --text-secondary: #64748b;
    --border: #e2e8f0;
    background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);
}

.sidebar {
    position: relative;
    width: 35%;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.05) 100%);
    padding: 60px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    z-index: 1;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

body.light-mode .sidebar {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(5, 150, 105, 0.02) 100%);
}

.sidebar-content {
    animation: slideInLeft 0.8s ease;
}

@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.sidebar-logo {
    width: 120px;
    height: 120px;
    margin: 0 auto 30px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    box-shadow: 0 15px 50px rgba(16, 185, 129, 0.3);
    border: 3px solid rgba(255, 255, 255, 0.1);
    position: relative;
    animation: bounce 3s ease-in-out infinite;
}

@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-20px); }
}

.sidebar-logo svg {
    width: 70px;
    height: 70px;
}

.sidebar-title {
    font-size: 32px;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 15px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.sidebar-subtitle {
    font-size: 14px;
    color: var(--text-secondary);
    margin-bottom: 40px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.sidebar-features {
    text-align: left;
    margin-top: 40px;
}

.feature {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    align-items: flex-start;
    animation: slideInLeft 0.8s ease backwards;
}

.feature:nth-child(1) { animation-delay: 0.1s; }
.feature:nth-child(2) { animation-delay: 0.2s; }
.feature:nth-child(3) { animation-delay: 0.3s; }

.feature-icon {
    width: 40px;
    height: 40px;
    background: rgba(16, 185, 129, 0.2);
    border-radius: 10px;
    display: flex;
    justify-content: center;
    align-items: center;
    color: var(--accent);
    font-size: 18px;
    flex-shrink: 0;
}

.feature-text {
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.6;
}

.container {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 40px 20px;
    overflow-y: auto;
    min-height: 100vh;
    background: transparent;
}

form {
    background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
    backdrop-filter: blur(10px);
    border: 1px solid rgba(16, 185, 129, 0.2);
    padding: 50px;
    border-radius: 20px;
    width: 100%;
    max-width: 600px;
    color: var(--text-primary);
    animation: slideInRight 0.8s ease;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(16, 185, 129, 0.1);
    margin: auto 0;
    transition: all 0.3s ease;
}

body.light-mode form {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.95));
    border: 1px solid rgba(16, 185, 129, 0.15);
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(16, 185, 129, 0.05);
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.form-header {
    text-align: center;
    margin-bottom: 40px;
    border-bottom: 2px solid rgba(16, 185, 129, 0.2);
    padding-bottom: 25px;
}

.form-header h2 {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 8px;
}

.form-header p {
    font-size: 13px;
    color: var(--text-secondary);
    letter-spacing: 1px;
    text-transform: uppercase;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(16, 185, 129, 0.1);
}

.theme-toggle {
    background: transparent;
    border: 2px solid rgba(16, 185, 129, 0.3);
    color: var(--text-primary);
    width: 45px;
    height: 45px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 20px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.theme-toggle:hover {
    border-color: var(--primary);
    background: rgba(16, 185, 129, 0.1);
    transform: scale(1.05);
}

.theme-toggle:active {
    transform: scale(0.95);
}

.language-select {
    background: rgba(15, 23, 42, 0.8);
    border: 2px solid rgba(16, 185, 129, 0.2);
    color: var(--text-primary);
    padding: 8px 15px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
}

body.light-mode .language-select {
    background: rgba(248, 250, 252, 0.8);
}

.language-select:focus {
    outline: none;
    border-color: var(--primary);
}

.form-group {
    margin-bottom: 20px;
    position: relative;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.form-row.full {
    grid-template-columns: 1fr;
}

label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

input, select {
    width: 100%;
    padding: 14px 16px;
    margin: 0;
    border-radius: 10px;
    border: 2px solid rgba(16, 185, 129, 0.2);
    background: rgba(15, 23, 42, 0.8);
    color: var(--text-primary);
    font-size: 14px;
    font-family: inherit;
    transition: all 0.3s ease;
}

body.light-mode input,
body.light-mode select {
    background: rgba(248, 250, 252, 0.9);
    color: var(--text-primary);
}

input::placeholder, select::placeholder {
    color: var(--text-secondary);
}

input:focus, select:focus {
    outline: none;
    border-color: var(--primary);
    background: rgba(15, 23, 42, 0.95);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1), inset 0 0 10px rgba(16, 185, 129, 0.05);
}

body.light-mode input:focus,
body.light-mode select:focus {
    background: rgba(248, 250, 252, 0.95);
}

input.valid {
    border-color: var(--success);
    background: rgba(16, 185, 129, 0.08);
}

body.light-mode input.valid {
    background: rgba(16, 185, 129, 0.05);
}

input.invalid {
    border-color: var(--danger);
    background: rgba(239, 68, 68, 0.08);
}

body.light-mode input.invalid {
    background: rgba(239, 68, 68, 0.05);
}

input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: rgba(15, 23, 42, 0.4);
}

body.light-mode input:disabled {
    background: rgba(248, 250, 252, 0.4);
}

.error-popup {
    position: absolute;
    top: -35px;
    left: 0;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    z-index: 100;
    opacity: 0;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    pointer-events: none;
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
    display: flex;
    align-items: center;
    gap: 6px;
}

.error-popup.show {
    opacity: 1;
    transform: translateY(0);
}

.error-popup::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 15px;
    width: 10px;
    height: 10px;
    background: #dc2626;
    transform: rotate(45deg);
}

.success-check {
    position: absolute;
    right: 14px;
    top: 42px;
    color: var(--success);
    font-size: 18px;
    opacity: 0;
    transform: scale(0);
    transition: all 0.3s ease;
}

.success-check.show {
    opacity: 1;
    transform: scale(1);
}

.password-container {
    position: relative;
}

.password-toggle {
    position: absolute;
    right: 14px;
    top: 14px;
    cursor: pointer;
    color: var(--text-secondary);
    font-size: 18px;
    transition: all 0.3s ease;
    user-select: none;
}

.password-toggle:hover {
    color: var(--primary);
}

.strength {
    height: 6px;
    background: rgba(239, 68, 68, 0.3);
    margin-top: 6px;
    border-radius: 10px;
    overflow: hidden;
}

.strength-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #ef4444, #f59e0b, #10b981);
    transition: width 0.3s ease;
    border-radius: 10px;
}

.strength-text {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 4px;
}

button[type="submit"], button[type="button"] {
    width: 100%;
    padding: 14px;
    margin-top: 20px;
    border: none;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.3);
    color: var(--text-primary);
    cursor: not-allowed;
    font-size: 16px;
    font-weight: 700;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
}

button.active {
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
    cursor: pointer;
}

button.active:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4);
}

button.active:active {
    transform: translateY(0);
}

.success-message {
    background: rgba(16, 185, 129, 0.15);
    border: 2px solid rgba(16, 185, 129, 0.4);
    color: var(--success);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 13px;
    animation: slideDown 0.5s ease;
}

.error-messages {
    background: rgba(239, 68, 68, 0.15);
    border: 2px solid rgba(239, 68, 68, 0.4);
    color: #fca5a5;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: 13px;
    animation: slideDown 0.5s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.form-footer {
    text-align: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid rgba(16, 185, 129, 0.1);
}

.form-footer p {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 10px;
}

.form-footer a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 700;
    transition: all 0.3s ease;
}

.form-footer a:hover {
    color: var(--accent);
}

@media (max-width: 1200px) {
    .sidebar {
        width: 40%;
    }
}

@media (max-width: 900px) {
    .sidebar {
        width: 50%;
    }
    
    form {
        padding: 40px 25px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    body {
        flex-direction: column;
    }
    
    .sidebar {
        position: relative;
        width: 100%;
        padding: 40px 20px;
        border-bottom: 2px solid rgba(16, 185, 129, 0.2);
        flex-shrink: 0;
    }
    
    .container {
        width: 100%;
        padding: 20px;
        min-height: auto;
    }
    
    form {
        padding: 30px;
        width: 100%;
    }
    
    .sidebar-logo {
        width: 100px;
        height: 100px;
    }
    
    .sidebar-title {
        font-size: 24px;
    }

    .error-popup {
        white-space: normal;
        width: 100%;
        left: 0;
        top: -40px;
    }
}

::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.5);
}

::-webkit-scrollbar-thumb {
    background: rgba(16, 185, 129, 0.5);
    border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
    background: rgba(16, 185, 129, 0.7);
}
</style>    
</head>    
<body>    

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-content">
        <div class="sidebar-logo">
            <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <linearGradient id="logoGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" style="stop-color:white;stop-opacity:1" />
                        <stop offset="100%" style="stop-color:#e0e7ff;stop-opacity:1" />
                    </linearGradient>
                </defs>
                <path d="M35 50L47 62L75 35" stroke="url(#logoGrad)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                <circle cx="50" cy="50" r="35" stroke="url(#logoGrad)" stroke-width="2" fill="none"/>
            </svg>
        </div>
        
        <h1 class="sidebar-title">BUSIQUIP</h1>
        <p class="sidebar-subtitle">Eswatini Fault Management</p>
        
        <div class="sidebar-features">
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="feature-text">Secure registration with bcrypt hashing</div>
            </div>
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-bolt"></i></div>
                <div class="feature-text">Fast fault reporting and tracking</div>
            </div>
            <div class="feature">
                <div class="feature-icon"><i class="fas fa-headset"></i></div>
                <div class="feature-text">24/7 customer support available</div>
            </div>
        </div>
    </div>
</div>

<!-- FORM -->
<div class="container">
    <form method="POST" id="form">    

        <div class="top">    
            <button type="button" class="theme-toggle" id="themeToggle" title="Toggle Theme">🌙</button>    
            <select class="language-select" onchange="changeLang(this.value)">    
                <option value="en">🇬🇧 English</option>    
                <option value="ss">🇸🇿 SiSwati</option>    
            </select>    
        </div>    

        <div class="form-header">
            <h2 id="title">Register Company</h2>    
            <p id="subtitle">Join the BUSIQUIP community today</p>
        </div>

        <?php if($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if(count($errors) > 0): ?>
            <div class="error-messages">
                <i class="fas fa-exclamation-circle"></i> <?php echo implode(", ", $errors); ?>
            </div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">    

        <!-- COMPANY NAME -->
        <div class="form-group">
            <label for="company_name">Company Name *</label>
            <input id="company_name" name="company_name" type="text" placeholder="Your Company Ltd" required>    
            <div class="error-popup" id="popup_company_name"></div>
            <span class="success-check" id="check_company_name">✓</span>
        </div>

        <!-- CONTACT PERSON -->
        <div class="form-group">
            <label for="contact_person_name">Contact Person Name *</label>
            <input id="contact_person_name" name="contact_person_name" type="text" placeholder="John Doe" disabled>    
            <div class="error-popup" id="popup_contact_person_name"></div>
            <span class="success-check" id="check_contact_person_name">✓</span>
        </div>

        <!-- PHONE & EMAIL -->
        <div class="form-row">
            <div class="form-group">
                <label for="company_phone">Phone *</label>
                <input id="company_phone" name="company_phone" type="tel" placeholder="+268XXXXXXXX" disabled>    
                <div class="error-popup" id="popup_company_phone"></div>
                <span class="success-check" id="check_company_phone">✓</span>
            </div>

            <div class="form-group">
                <label for="company_email">Email Address *</label>
                <input id="company_email" type="email" name="company_email" placeholder="you@example.com" disabled>    
                <div class="error-popup" id="popup_company_email"></div>
                <span class="success-check" id="check_company_email">✓</span>
            </div>
        </div>

        <!-- ADDRESS -->
        <div class="form-group">
            <label for="company_address">Physical Address *</label>
            <input id="company_address" name="company_address" type="text" placeholder="123 Main Street, Mbabane" disabled>    
            <div class="error-popup" id="popup_company_address"></div>
        </div>

        <!-- CLIENT TYPE -->
        <div class="form-group">
            <label for="client_type">Client Type *</label>
            <select id="client_type" name="client_type" disabled>    
                <option value="">Select Type</option>    
                <option value="CORPORATE">Corporate</option>    
                <option value="INDIVIDUAL">Individual</option>    
                <option value="NGO">NGO</option>    
                <option value="GOVERNMENT">Government</option>    
            </select>    
        </div>

        <!-- USERNAME -->
        <div class="form-group">
            <label for="username">Username *</label>
            <input id="username" name="username" type="text" placeholder="Choose a username" disabled>    
            <div class="error-popup" id="popup_username"></div>
            <span class="success-check" id="check_username">✓</span>
        </div>

        <!-- PASSWORD -->
        <div class="form-group">
            <label for="password">Password *</label>
            <div class="password-container">
                <input id="password" type="password" name="password" placeholder="Min 6 characters" disabled>    
                <span class="password-toggle" onclick="togglePass()" title="Show/Hide">👁️</span>
                <span class="success-check" id="check_password">✓</span>
            </div>
            <div class="error-popup" id="popup_password"></div>
            <div class="strength">
                <div class="strength-fill" id="strength-fill"></div>
            </div>
            <div class="strength-text" id="strength-text"></div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password *</label>
            <div class="password-container">
                <input id="confirm_password" type="password" name="confirm_password" placeholder="Repeat password" disabled>    
                <span class="password-toggle" onclick="toggleConfirm()" title="Show/Hide">👁️</span>
                <span class="success-check" id="check_confirm_password">✓</span>
            </div>
            <div class="error-popup" id="popup_confirm_password"></div>
        </div>

        <!-- BUTTON -->
        <button id="btn" name="register" type="submit" disabled>Register Company</button>    

        <!-- FOOTER -->
        <div class="form-footer">
            <p id="footer_text">Already have an account?</p>
            <p><a href="client_login.php" id="login_link">← Back to Login</a></p>
        </div>

    </form>    
</div>

<script>
const btn = document.getElementById("btn");
const passwordFill = document.getElementById("strength-fill");
const passwordText = document.getElementById("strength-text");
const themeToggle = document.getElementById("themeToggle");

// Track validation state
let validationState = {
    companyName: false,
    contactPersonName: false,
    companyPhone: false,
    companyEmail: false,
    username: false,
    password: false,
    confirmPassword: false
};

/* ===== THEME TOGGLE ===== */
function toggleTheme() {
    const isLight = document.body.classList.toggle("light-mode");
    localStorage.setItem("theme-mode", isLight ? "light" : "dark");
    updateThemeIcon();
}

function updateThemeIcon() {
    const isLight = document.body.classList.contains("light-mode");
    themeToggle.textContent = isLight ? "🌙" : "☀️";
    themeToggle.title = isLight ? "Switch to Dark Mode" : "Switch to Light Mode";
}

const savedTheme = localStorage.getItem("theme-mode");
if (savedTheme === "light") {
    document.body.classList.add("light-mode");
}
updateThemeIcon();

themeToggle.addEventListener("click", function(e) {
    e.preventDefault();
    toggleTheme();
});

/* ===== SHOW/HIDE POPUPS ===== */
function showPopup(fieldId, message) {
    const popup = document.getElementById(`popup_${fieldId}`);
    if(popup) {
        popup.textContent = message;
        popup.classList.add("show");
    }
}

function hidePopup(fieldId) {
    const popup = document.getElementById(`popup_${fieldId}`);
    if(popup) {
        popup.classList.remove("show");
    }
}

function showCheckmark(fieldId) {
    const check = document.getElementById(`check_${fieldId}`);
    if(check) {
        check.classList.add("show");
    }
}

function hideCheckmark(fieldId) {
    const check = document.getElementById(`check_${fieldId}`);
    if(check) {
        check.classList.remove("show");
    }
}

/* ===== SHOW/HIDE PASSWORDS ===== */
function togglePass(){
    const pwd = document.getElementById("password");
    pwd.type = pwd.type === "password" ? "text" : "password";
}

function toggleConfirm(){
    const confirm = document.getElementById("confirm_password");
    confirm.type = confirm.type === "password" ? "text" : "password";
}

/* ===== PREVENT NON-LETTER INPUT FOR NAMES ===== */
function allowOnlyLetters(e) {
    const input = e.target;
    const value = input.value;
    const cleaned = value.replace(/[^a-zA-Z\s]/g, '');
    
    if(value !== cleaned) {
        input.value = cleaned;
        showPopup(input.id, "❌ Only letters allowed");
        setTimeout(() => hidePopup(input.id), 2000);
    }
}

/* ===== FORMAT PHONE NUMBER ===== */
function formatPhoneNumber(e) {
    const input = e.target;
    let value = input.value;
    
    let numbersOnly = value.replace(/\D/g, '');
    
    if (numbersOnly.startsWith('268')) {
        numbersOnly = numbersOnly.substring(3);
    }
    
    numbersOnly = numbersOnly.substring(0, 8);
    
    if (numbersOnly) {
        input.value = '+268' + numbersOnly;
    } else {
        input.value = '+268';
    }
}

/* ===== UPDATE BUTTON STATE ===== */
function updateButtonState() {
    if(validationState.companyName && validationState.contactPersonName && validationState.companyPhone && 
       validationState.companyEmail && validationState.username && validationState.password && validationState.confirmPassword) {
        btn.classList.add("active");
        btn.disabled = false;
    } else {
        btn.classList.remove("active");
        btn.disabled = true;
    }
}

/* ===== VALIDATION FUNCTIONS ===== */
function validateCompanyName() {
    const input = document.getElementById("company_name");
    const ok = input.value.length >= 2;
    
    if(input.value === "") {
        validationState.companyName = false;
        hideCheckmark("company_name");
        hidePopup("company_name");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.companyName = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("company_name");
        hidePopup("company_name");
        document.getElementById("contact_person_name").disabled = false;
    } else {
        validationState.companyName = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("company_name");
        showPopup("company_name", "❌ Min 2 characters");
    }
    updateButtonState();
}

function validateContactPersonName() {
    const input = document.getElementById("contact_person_name");
    const ok = input.value.length >= 2;
    
    if(input.value === "") {
        validationState.contactPersonName = false;
        hideCheckmark("contact_person_name");
        hidePopup("contact_person_name");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.contactPersonName = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("contact_person_name");
        hidePopup("contact_person_name");
        document.getElementById("company_phone").disabled = false;
    } else {
        validationState.contactPersonName = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("contact_person_name");
        showPopup("contact_person_name", "❌ Min 2 characters");
    }
    updateButtonState();
}

function validateCompanyPhone() {
    const input = document.getElementById("company_phone");
    const ok = /^\+268[0-9]{8}$/.test(input.value);
    
    if(input.value === "" || input.value === "+268") {
        validationState.companyPhone = false;
        hideCheckmark("company_phone");
        hidePopup("company_phone");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.companyPhone = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("company_phone");
        hidePopup("company_phone");
        document.getElementById("company_email").disabled = false;
    } else {
        validationState.companyPhone = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("company_phone");
        showPopup("company_phone", "❌ Need 8 digits: +268XXXXXXXX");
    }
    updateButtonState();
}

function validateCompanyEmail() {
    const input = document.getElementById("company_email");
    const ok = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/.test(input.value);
    
    if(input.value === "") {
        validationState.companyEmail = false;
        hideCheckmark("company_email");
        hidePopup("company_email");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.companyEmail = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("company_email");
        hidePopup("company_email");
        document.getElementById("company_address").disabled = false;
    } else {
        validationState.companyEmail = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("company_email");
        showPopup("company_email", "❌ Invalid email format");
    }
    updateButtonState();
}

function validateUsername() {
    const input = document.getElementById("username");
    const ok = input.value.length >= 3 && /^[a-zA-Z0-9_]+$/.test(input.value);
    
    if(input.value === "") {
        validationState.username = false;
        hideCheckmark("username");
        hidePopup("username");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.username = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("username");
        hidePopup("username");
        document.getElementById("password").disabled = false;
    } else {
        validationState.username = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("username");
        showPopup("username", "❌ Min 3 chars, letters/numbers only");
    }
    updateButtonState();
}

function validatePassword() {
    const input = document.getElementById("password");
    const ok = input.value.length >= 6;
    
    if(input.value === "") {
        validationState.password = false;
        hideCheckmark("password");
        hidePopup("password");
        input.classList.remove("valid", "invalid");
        passwordFill.style.width = "0%";
        passwordText.innerHTML = "";
    } else if(ok) {
        validationState.password = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("password");
        hidePopup("password");
        document.getElementById("confirm_password").disabled = false;
    } else {
        validationState.password = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("password");
        showPopup("password", "❌ Min 6 characters");
    }
    
    let strength = 0;
    if(input.value.length >= 6) strength = 25;
    if(input.value.length >= 8) strength = 50;
    if(/[0-9]/.test(input.value) && /[a-z]/.test(input.value)) strength = 75;
    if(/[A-Z]/.test(input.value)) strength = 100;
    
    passwordFill.style.width = strength + "%";
    
    if(strength < 50) passwordText.innerHTML = "🔴 Weak password";
    else if(strength < 75) passwordText.innerHTML = "🟡 Medium strength";
    else passwordText.innerHTML = "🟢 Strong password ✓";
    
    updateButtonState();
}

function validateConfirm() {
    const pwd = document.getElementById("password");
    const input = document.getElementById("confirm_password");
    const ok = pwd.value === input.value && pwd.value.length >= 6;
    
    if(input.value === "") {
        validationState.confirmPassword = false;
        hideCheckmark("confirm_password");
        hidePopup("confirm_password");
        input.classList.remove("valid", "invalid");
    } else if(ok) {
        validationState.confirmPassword = true;
        input.classList.add("valid");
        input.classList.remove("invalid");
        showCheckmark("confirm_password");
        hidePopup("confirm_password");
    } else {
        validationState.confirmPassword = false;
        input.classList.add("invalid");
        input.classList.remove("valid");
        hideCheckmark("confirm_password");
        if(pwd.value !== input.value) {
            showPopup("confirm_password", "❌ Passwords don't match");
        } else {
            showPopup("confirm_password", "❌ Enter password first");
        }
    }
    updateButtonState();
}

/* ===== EVENT LISTENERS ===== */
document.getElementById("company_name").addEventListener("input", function(e) {
    allowOnlyLetters(e);
    validateCompanyName();
});

document.getElementById("contact_person_name").addEventListener("input", function(e) {
    allowOnlyLetters(e);
    validateContactPersonName();
});

document.getElementById("company_phone").addEventListener("input", function(e) {
    formatPhoneNumber(e);
    validateCompanyPhone();
});

document.getElementById("company_email").addEventListener("input", validateCompanyEmail);

document.getElementById("company_address").addEventListener("input", function() {
    if(this.value.length >= 5) {
        document.getElementById("client_type").disabled = false;
    }
});

document.getElementById("client_type").addEventListener("change", function() {
    if(this.value !== "") {
        document.getElementById("username").disabled = false;
    }
});

document.getElementById("username").addEventListener("input", validateUsername);

document.getElementById("password").addEventListener("input", validatePassword);
document.getElementById("confirm_password").addEventListener("input", validateConfirm);

/* ===== LANGUAGE ===== */
function changeLang(l){
    if(l === "ss"){
        document.getElementById("title").innerText = "Lumela Inkhosi";
        document.getElementById("subtitle").innerText = "Kuhlanganisa Neminatfo Yentlulueko";
        document.getElementById("footer_text").innerText = "Unesithupha?";
        document.getElementById("login_link").innerText = "← Buyela Ekhaya";
        document.getElementById("btn").innerText = "Memeza Inkhosi";
    } else {
        document.getElementById("title").innerText = "Register Company";
        document.getElementById("subtitle").innerText = "Join the BUSIQUIP community today";
        document.getElementById("footer_text").innerText = "Already have an account?";
        document.getElementById("login_link").innerText = "← Back to Login";
        document.getElementById("btn").innerText = "Register Company";
    }
}
</script>    

</body>    
</html>


