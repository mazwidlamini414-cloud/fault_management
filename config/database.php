<?php

// ═══════════════════════════════════════════════════════════════════════════════
// config/database.php  —  Works on XAMPP, phone IP (local), and Railway
// ═══════════════════════════════════════════════════════════════════════════════

// ─── BASE URL (auto-detected) ──────────────────────────────────────────────────
// On XAMPP:   http://localhost/fault_management   or http://192.168.x.x/fault_management
// On Railway: https://your-app.up.railway.app   (serves from /, no subfolder)
// Override by setting an APP_URL environment variable on Railway.
if (!defined('BASE_URL')) {
    if (getenv('APP_URL')) {
        // Railway / any host: set APP_URL env var in Railway dashboard
        define('BASE_URL', rtrim(getenv('APP_URL'), '/'));
    } else {
        // Local (XAMPP or phone IP): auto-detect from request
        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Derive subfolder path from this file's location
        $doc_root  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $file_dir  = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $sub_path  = $doc_root ? str_replace($doc_root, '', $file_dir) : '';
        define('BASE_URL', $protocol . '://' . $host_name . $sub_path);
    }
}

// ─── DATABASE CREDENTIALS ─────────────────────────────────────────────────────
// Railway injects MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE env vars.
// XAMPP uses root / blank password / localhost.
$db_host     = getenv('MYSQLHOST')     ?: (getenv('DB_HOST')     ?: 'localhost');
$db_user     = getenv('MYSQLUSER')     ?: (getenv('DB_USER')     ?: 'root');
$db_password = getenv('MYSQLPASSWORD') ?: (getenv('DB_PASSWORD') ?: '');
$db_name     = getenv('MYSQLDATABASE') ?: (getenv('DB_NAME')     ?: 'busiquip_final');
$db_port     = (int)(getenv('MYSQLPORT') ?: 3306);

// ─── MySQLi connection ($conn) ────────────────────────────────────────────────
$conn = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

// Legacy aliases (some pages use $servername / $username / $password / $database)
$servername = $db_host;
$username   = $db_user;
$password   = $db_password;
$database   = $db_name;

// ─── PDO connection ($pdo) ────────────────────────────────────────────────────
// Used by client_faults.php
try {
    $pdo = new PDO(
        "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}




