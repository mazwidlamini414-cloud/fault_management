<?php
// ═══════════════════════════════════════════════════════════════
//  api/auth.php  —  Login & Logout for all roles
//
//  POST /api/auth.php   { "username":"...", "password":"...", "role":"Client|Technician|Accountant|Admin" }
//  DELETE /api/auth.php  (Bearer token required)
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── LOGIN ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $body     = get_body();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $role     = trim($body['role'] ?? '');

    if (!$username || !$password || !$role) {
        api_error('username, password and role are required');
    }

    $allowed = ['Client', 'Technician', 'Accountant', 'Admin'];
    if (!in_array($role, $allowed)) {
        api_error('role must be one of: ' . implode(', ', $allowed));
    }

    $uname_esc = clean($username);
    $user      = null;
    $user_id   = null;
    $user_name = null;

    if ($role === 'Client') {
        // Clients log in with USERNAME or COMPANY_EMAIL  (matches client_login.php logic)
        $res = $conn->query("
            SELECT CLIENT_ID, COMPANY_NAME, PASSWORD_HASH, COMPANY_EMAIL, CLIENT_TYPE, CONTACT_PERSON_NAME
            FROM client
            WHERE USERNAME = '$uname_esc' OR LOWER(COMPANY_EMAIL) = LOWER('$uname_esc')
            LIMIT 1
        ");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($password, $row['PASSWORD_HASH'])) {
                $user_id   = $row['CLIENT_ID'];
                $user_name = $row['COMPANY_NAME'];
                $user      = [
                    'id'             => $row['CLIENT_ID'],
                    'company_name'   => $row['COMPANY_NAME'],
                    'email'          => $row['COMPANY_EMAIL'],
                    'contact_person' => $row['CONTACT_PERSON_NAME'],
                    'client_type'    => $row['CLIENT_TYPE'],
                    'role'           => 'Client',
                ];
            }
        }

    } elseif ($role === 'Technician') {
        // Matches technician_login.php — USERNAME field, ROLE = 'Technician'
        $res = $conn->query("
            SELECT EMP_ID, FULL_NAME, EMAIL, ROLE, PASSWORD_HASH
            FROM employee
            WHERE (USERNAME = '$uname_esc' OR EMAIL = '$uname_esc')
              AND ROLE = 'Technician'
            LIMIT 1
        ");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($password, $row['PASSWORD_HASH'])) {
                $user_id   = $row['EMP_ID'];
                $user_name = $row['FULL_NAME'];
                $user      = [
                    'id'    => $row['EMP_ID'],
                    'name'  => $row['FULL_NAME'],
                    'email' => $row['EMAIL'],
                    'role'  => 'Technician',
                ];
            }
        }

    } elseif ($role === 'Accountant') {
        // Matches accountant_login.php — USERNAME field, ROLE = 'Accountant'
        $res = $conn->query("
            SELECT EMP_ID, FULL_NAME, EMAIL, ROLE, PASSWORD_HASH
            FROM employee
            WHERE (USERNAME = '$uname_esc' OR EMAIL = '$uname_esc')
              AND ROLE = 'Accountant'
            LIMIT 1
        ");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($password, $row['PASSWORD_HASH'])) {
                $user_id   = $row['EMP_ID'];
                $user_name = $row['FULL_NAME'];
                $user      = [
                    'id'    => $row['EMP_ID'],
                    'name'  => $row['FULL_NAME'],
                    'email' => $row['EMAIL'],
                    'role'  => 'Accountant',
                ];
            }
        }

    } elseif ($role === 'Admin') {
        // Matches admin_login.php — admin table
        $res = $conn->query("
            SELECT ADMIN_ID, USERNAME, EMAIL, PASSWORD_HASH
            FROM admin
            WHERE USERNAME = '$uname_esc'
            LIMIT 1
        ");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (password_verify($password, $row['PASSWORD_HASH'])) {
                $user_id   = $row['ADMIN_ID'];
                $user_name = $row['USERNAME'];
                $user      = [
                    'id'    => $row['ADMIN_ID'],
                    'name'  => $row['USERNAME'],
                    'email' => $row['EMAIL'],
                    'role'  => 'Admin',
                ];
            }
        }
    }

    if (!$user) {
        api_error('Invalid username or password', 401);
    }

    // Generate token — 64 hex chars, expires in 30 days
    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    $uid_esc    = (int)$user_id;
    $role_esc   = clean($role);
    $name_esc   = clean($user_name);

    $conn->query("
        INSERT INTO api_tokens (token, user_id, user_type, user_name, expires_at)
        VALUES ('$token', $uid_esc, '$role_esc', '$name_esc', '$expires_at')
    ");

    api_ok([
        'token'      => $token,
        'expires_at' => $expires_at,
        'user'       => $user,
    ], 201);
}

// ── LOGOUT ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $token = clean($m[1]);
        $conn->query("DELETE FROM api_tokens WHERE token = '$token'");
    }
    api_ok(['message' => 'Logged out successfully']);
}

api_error('Method not allowed', 405);



