<?php
// ═══════════════════════════════════════════════════════════════
//  api/notifications.php  —  Notifications for all roles
//
//  GET  /api/notifications.php          List notifications for logged-in user
//  PUT  /api/notifications.php          Mark notifications as read
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth();
$uid    = (int)$user['user_id'];
$role   = $user['user_type'];

// Map role → user_type used in notifications table
// (matches values inserted in fault_details.php, client_faults.php, etc.)
$notif_type = match($role) {
    'Client'     => 'Client',
    'Technician' => 'Employee',
    'Accountant' => 'Employee',
    'Admin'      => 'Admin',
    default      => $role,
};

if ($method === 'GET') {
    $unread_only = isset($_GET['unread']) && $_GET['unread'] === '1';
    $limit  = min((int)($_GET['limit']  ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $where = "WHERE user_id = $uid AND user_type = '$notif_type'";
    if ($unread_only) $where .= " AND is_read = 0";

    $res = $conn->query("
        SELECT id, title, message, is_read, created_at
        FROM notifications
        $where
        ORDER BY created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $notifications = [];
    while ($row = $res->fetch_assoc()) $notifications[] = $row;

    $cnt = $conn->query("SELECT COUNT(*) as c FROM notifications $where");
    $total = (int)$cnt->fetch_assoc()['c'];

    $unread_cnt = $conn->query("
        SELECT COUNT(*) as c FROM notifications
        WHERE user_id = $uid AND user_type = '$notif_type' AND is_read = 0
    ");
    $unread = (int)$unread_cnt->fetch_assoc()['c'];

    api_ok([
        'notifications' => $notifications,
        'total'         => $total,
        'unread_count'  => $unread,
        'limit'         => $limit,
        'offset'        => $offset,
    ]);
}

if ($method === 'PUT') {
    $body = get_body();
    $notif_id = isset($body['id']) ? (int)$body['id'] : 0;

    if ($notif_id > 0) {
        // Mark single notification as read
        $conn->query("
            UPDATE notifications SET is_read = 1
            WHERE id = $notif_id AND user_id = $uid AND user_type = '$notif_type'
        ");
    } else {
        // Mark ALL as read
        $conn->query("
            UPDATE notifications SET is_read = 1
            WHERE user_id = $uid AND user_type = '$notif_type'
        ");
    }
    api_ok(['message' => 'Marked as read']);
}

api_error('Method not allowed', 405);

