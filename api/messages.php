<?php
// ═══════════════════════════════════════════════════════════════
//  api/messages.php  —  Unified messaging (unified_messages table)
//
//  GET  /api/messages.php         Inbox for logged-in user
//  POST /api/messages.php         Send a message
//  PUT  /api/messages.php         Mark message(s) as read
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth();
$uid    = (int)$user['user_id'];
$role   = $user['user_type'];

// Map role to from_type / to_type enum used in unified_messages
// enum('Client','Employee','Admin')
function role_to_msg_type(string $role): string {
    return match($role) {
        'Client'     => 'Client',
        'Technician' => 'Employee',
        'Accountant' => 'Employee',
        'Admin'      => 'Admin',
        default      => 'Employee',
    };
}
$my_type = role_to_msg_type($role);

if ($method === 'GET') {
    $limit  = min((int)($_GET['limit']  ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    // Inbox: messages sent TO this user
    $res = $conn->query("
        SELECT id, from_id, from_type, from_name, subject, content,
               priority, is_read, read_at, sent_time
        FROM unified_messages
        WHERE to_id = $uid AND to_type = '$my_type'
        ORDER BY sent_time DESC
        LIMIT $limit OFFSET $offset
    ");
    $messages = [];
    while ($row = $res->fetch_assoc()) $messages[] = $row;

    $cnt = $conn->query("
        SELECT COUNT(*) as c FROM unified_messages
        WHERE to_id = $uid AND to_type = '$my_type'
    ");
    $total = (int)$cnt->fetch_assoc()['c'];

    $unread = $conn->query("
        SELECT COUNT(*) as c FROM unified_messages
        WHERE to_id = $uid AND to_type = '$my_type' AND is_read = 0
    ");

    api_ok([
        'messages'    => $messages,
        'total'       => $total,
        'unread'      => (int)$unread->fetch_assoc()['c'],
        'limit'       => $limit,
        'offset'      => $offset,
    ]);
}

if ($method === 'POST') {
    $body = get_body();

    $to_id   = (int)($body['to_id']   ?? 0);
    $to_type = clean($body['to_type'] ?? ''); // Client | Employee | Admin
    $subject = clean($body['subject'] ?? '');
    $content = clean($body['content'] ?? '');
    $priority = $body['priority'] ?? 'Normal';

    if (!$to_id)   api_error('to_id is required');
    if (!$to_type) api_error('to_type is required (Client, Employee, or Admin)');
    if (!$content) api_error('content is required');

    $valid_types = ['Client', 'Employee', 'Admin'];
    if (!in_array($to_type, $valid_types)) api_error('to_type must be: Client, Employee, or Admin');

    $valid_priorities = ['Low', 'Normal', 'High', 'Urgent'];
    if (!in_array($priority, $valid_priorities)) $priority = 'Normal';

    $from_name = clean($user['user_name']);
    $now       = date('Y-m-d H:i:s');

    // Get recipient name
    $to_name = '';
    if ($to_type === 'Client') {
        $r = $conn->query("SELECT COMPANY_NAME FROM client WHERE CLIENT_ID = $to_id LIMIT 1");
        if ($r && $r->num_rows) $to_name = clean($r->fetch_assoc()['COMPANY_NAME']);
    } elseif ($to_type === 'Employee') {
        $r = $conn->query("SELECT FULL_NAME FROM employee WHERE EMP_ID = $to_id LIMIT 1");
        if ($r && $r->num_rows) $to_name = clean($r->fetch_assoc()['FULL_NAME']);
    } elseif ($to_type === 'Admin') {
        $to_name = 'Admin';
    }

    $conn->query("
        INSERT INTO unified_messages
            (from_id, from_type, from_name, to_id, to_type, to_name, subject, content, priority, sent_time)
        VALUES
            ($uid, '$my_type', '$from_name', $to_id, '$to_type', '$to_name', '$subject', '$content', '$priority', '$now')
    ");
    $msg_id = (int)$conn->insert_id;
    if (!$msg_id) api_error('Failed to send message');

    // Notify recipient
    $notif_user_type = match($to_type) {
        'Client'   => 'Client',
        'Employee' => 'Employee',
        'Admin'    => 'Admin',
        default    => 'Employee',
    };
    $conn->query("
        INSERT INTO notifications (user_id, user_type, title, message)
        VALUES ($to_id, '$notif_user_type',
                'New Message from $from_name',
                '$subject')
    ");

    api_ok(['message_id' => $msg_id, 'message' => 'Message sent'], 201);
}

if ($method === 'PUT') {
    $body   = get_body();
    $msg_id = (int)($body['id'] ?? 0);
    $now    = date('Y-m-d H:i:s');

    if ($msg_id > 0) {
        $conn->query("
            UPDATE unified_messages SET is_read = 1, read_at = '$now'
            WHERE id = $msg_id AND to_id = $uid AND to_type = '$my_type'
        ");
    } else {
        $conn->query("
            UPDATE unified_messages SET is_read = 1, read_at = '$now'
            WHERE to_id = $uid AND to_type = '$my_type'
        ");
    }
    api_ok(['message' => 'Marked as read']);
}

api_error('Method not allowed', 405);



