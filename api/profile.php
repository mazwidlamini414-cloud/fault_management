<?php
// ═══════════════════════════════════════════════════════════════
//  api/profile.php  —  View & update profile for all roles
//
//  GET /api/profile.php         Get own profile
//  PUT /api/profile.php         Update own profile / change password
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth();
$uid    = (int)$user['user_id'];
$role   = $user['user_type'];

if ($method === 'GET') {
    if ($role === 'Client') {
        $res = $conn->query("
            SELECT CLIENT_ID, COMPANY_NAME, COMPANY_PHONE, COMPANY_EMAIL,
                   COMPANY_ADDRESS, CONTACT_PERSON_NAME, CLIENT_TYPE, WALLET_BALANCE
            FROM client WHERE CLIENT_ID = $uid LIMIT 1
        ");
        if (!$res || $res->num_rows === 0) api_error('Profile not found', 404);
        api_ok($res->fetch_assoc());
    }

    if (in_array($role, ['Technician', 'Accountant'])) {
        $res = $conn->query("
            SELECT EMP_ID, FULL_NAME, EMAIL, ROLE, HIRE_DATE, HOURLY_RATE, USERNAME
            FROM employee WHERE EMP_ID = $uid LIMIT 1
        ");
        if (!$res || $res->num_rows === 0) api_error('Profile not found', 404);
        api_ok($res->fetch_assoc());
    }

    if ($role === 'Admin') {
        $res = $conn->query("
            SELECT ADMIN_ID, USERNAME, EMAIL, CREATED_AT
            FROM admin WHERE ADMIN_ID = $uid LIMIT 1
        ");
        if (!$res || $res->num_rows === 0) api_error('Profile not found', 404);
        api_ok($res->fetch_assoc());
    }
}

if ($method === 'PUT') {
    $body = get_body();

    if ($role === 'Client') {
        $fields = [];
        if (isset($body['company_phone']))    $fields[] = "COMPANY_PHONE    = '" . clean($body['company_phone'])    . "'";
        if (isset($body['company_address']))  $fields[] = "COMPANY_ADDRESS  = '" . clean($body['company_address'])  . "'";
        if (isset($body['contact_person']))   $fields[] = "CONTACT_PERSON_NAME = '" . clean($body['contact_person']) . "'";

        // Password change — matches client_profile.php logic
        if (!empty($body['current_password']) && !empty($body['new_password'])) {
            $res = $conn->query("SELECT PASSWORD_HASH FROM client WHERE CLIENT_ID=$uid LIMIT 1");
            $hash = $res->fetch_assoc()['PASSWORD_HASH'];
            if (!password_verify($body['current_password'], $hash)) {
                api_error('Current password is incorrect');
            }
            $new_hash = password_hash($body['new_password'], PASSWORD_BCRYPT);
            $fields[] = "PASSWORD_HASH = '" . clean($new_hash) . "'";
        }

        if (empty($fields)) api_error('No fields to update');
        $conn->query("UPDATE client SET " . implode(', ', $fields) . " WHERE CLIENT_ID=$uid");
        api_ok(['message' => 'Profile updated']);
    }

    if (in_array($role, ['Technician', 'Accountant'])) {
        $fields = [];
        if (isset($body['email'])) $fields[] = "EMAIL = '" . clean($body['email']) . "'";

        if (!empty($body['current_password']) && !empty($body['new_password'])) {
            $res  = $conn->query("SELECT PASSWORD_HASH FROM employee WHERE EMP_ID=$uid LIMIT 1");
            $hash = $res->fetch_assoc()['PASSWORD_HASH'];
            if (!password_verify($body['current_password'], $hash)) {
                api_error('Current password is incorrect');
            }
            $new_hash = password_hash($body['new_password'], PASSWORD_BCRYPT);
            $fields[] = "PASSWORD_HASH = '" . clean($new_hash) . "'";
        }

        if (empty($fields)) api_error('No fields to update');
        $conn->query("UPDATE employee SET " . implode(', ', $fields) . " WHERE EMP_ID=$uid");
        api_ok(['message' => 'Profile updated']);
    }

    api_error('Profile update not supported for this role');
}

api_error('Method not allowed', 405);
