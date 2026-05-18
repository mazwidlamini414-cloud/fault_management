<?php
// ═══════════════════════════════════════════════════════════════
//  api/middleware.php  —  Shared auth, DB, and response helpers
//  Used by every API endpoint.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/database.php';

// ── JSON output headers ──────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Token table (created on first API call) ───────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `api_tokens` (
        `token`      varchar(64)  NOT NULL,
        `user_id`    int(11)      NOT NULL,
        `user_type`  varchar(20)  NOT NULL COMMENT 'Client|Technician|Accountant|Admin',
        `user_name`  varchar(150) NOT NULL,
        `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
        `expires_at` timestamp    NOT NULL,
        PRIMARY KEY (`token`),
        KEY `idx_user` (`user_id`, `user_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Response helpers ─────────────────────────────────────────
function api_ok($data = [], $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
function get_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

// ── Require Bearer token — returns user info array ───────────
function require_auth(array $allowed_roles = []): array {
    global $conn;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        api_error('Missing or invalid Authorization header', 401);
    }
    $token = $conn->real_escape_string($m[1]);
    $res = $conn->query("
        SELECT user_id, user_type, user_name
        FROM api_tokens
        WHERE token = '$token'
          AND expires_at > NOW()
        LIMIT 1
    ");
    if (!$res || $res->num_rows === 0) {
        api_error('Invalid or expired token. Please log in again.', 401);
    }
    $user = $res->fetch_assoc();
    if (!empty($allowed_roles) && !in_array($user['user_type'], $allowed_roles)) {
        api_error('Access denied for role: ' . $user['user_type'], 403);
    }
    return $user;
}

// ── Sanitise scalar input ─────────────────────────────────────
function clean(string $val): string {
    global $conn;
    return $conn->real_escape_string(trim($val));
}
