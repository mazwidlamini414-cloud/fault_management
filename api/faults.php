<?php
// ═══════════════════════════════════════════════════════════════
//  api/faults.php  —  Reported faults CRUD
//
//  GET    /api/faults.php              List faults for logged-in user
//  GET    /api/faults.php?id=X         Single fault detail
//  POST   /api/faults.php              Client reports a new fault
//  PUT    /api/faults.php              Technician updates status (start/complete)
//                                      Client confirms/rejects completed fault
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth(['Client', 'Technician', 'Accountant', 'Admin']);
$uid    = (int)$user['user_id'];
$role   = $user['user_type'];

// ── GET — list or single ──────────────────────────────────────
if ($method === 'GET') {
    $fault_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($fault_id > 0) {
        // ── Single fault — all roles can view if they're linked ──
        $where = match($role) {
            'Client'     => "rf.REP_FAULT_ID = $fault_id AND rf.CLIENT_ID = $uid",
            'Technician' => "rf.REP_FAULT_ID = $fault_id AND at2.EMP_ID = $uid",
            default      => "rf.REP_FAULT_ID = $fault_id",
        };

        $join = ($role === 'Technician')
            ? "INNER JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
               INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID"
            : "LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID";

        $res = $conn->query("
            SELECT rf.REP_FAULT_ID, rf.CLIENT_ID, rf.FAULT_ID, rf.REPORT_DATE,
                   rf.STATUS, rf.PRIORITY, rf.REPORTED_BY, rf.DESCRIPTION,
                   c.COMPANY_NAME, c.COMPANY_PHONE, c.COMPANY_EMAIL,
                   f.FAULT_TYPE,
                   a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE, a.STATUS AS ASSIGN_STATUS,
                   cc.confirmation_status AS CONFIRM_STATUS,
                   cc.confirmation_notes  AS CONFIRM_NOTES
            FROM reported_fault rf
            LEFT JOIN client c ON c.CLIENT_ID = rf.CLIENT_ID
            LEFT JOIN fault f  ON f.FAULT_ID  = rf.FAULT_ID
            $join
            LEFT JOIN client_confirmations cc
                   ON cc.fault_id = rf.REP_FAULT_ID AND cc.client_id = rf.CLIENT_ID
            WHERE $where
            GROUP BY rf.REP_FAULT_ID
            LIMIT 1
        ");
        if (!$res || $res->num_rows === 0) api_error('Fault not found or access denied', 404);
        $fault = $res->fetch_assoc();

        // Work log for this fault
        if ($fault['ASSIGN_ID']) {
            $aid = (int)$fault['ASSIGN_ID'];
            $wl  = $conn->query("
                SELECT wl.LOG_DATE, wl.LOG_TYPE, wl.ACTION_TAKEN, wl.HOURS_SPENT,
                       e.FULL_NAME AS technician
                FROM work_log wl
                LEFT JOIN employee e ON e.EMP_ID = wl.EMP_ID
                WHERE wl.ASSIGN_ID = $aid
                ORDER BY wl.LOG_DATE ASC
            ");
            $fault['work_log'] = [];
            while ($row = $wl->fetch_assoc()) $fault['work_log'][] = $row;
        }
        api_ok($fault);
    }

    // ── List ──
    $status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
    $limit  = min((int)($_GET['limit']  ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $where_parts = [];
    $join        = '';

    if ($role === 'Client') {
        $where_parts[] = "rf.CLIENT_ID = $uid";
        $join = "LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID";
    } elseif ($role === 'Technician') {
        $where_parts[] = "at2.EMP_ID = $uid";
        $join = "INNER JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
                 INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID";
    } else {
        // Admin / Accountant — see all
        $join = "LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID";
    }

    if ($status_filter) $where_parts[] = "rf.STATUS = '$status_filter'";
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $res = $conn->query("
        SELECT rf.REP_FAULT_ID, rf.CLIENT_ID, rf.REPORT_DATE, rf.STATUS,
               rf.PRIORITY, rf.REPORTED_BY, rf.DESCRIPTION,
               c.COMPANY_NAME,
               f.FAULT_TYPE,
               a.ASSIGN_ID, a.DUE_DATE
        FROM reported_fault rf
        LEFT JOIN client c ON c.CLIENT_ID = rf.CLIENT_ID
        LEFT JOIN fault f  ON f.FAULT_ID  = rf.FAULT_ID
        $join
        $where
        GROUP BY rf.REP_FAULT_ID
        ORDER BY rf.REPORT_DATE DESC
        LIMIT $limit OFFSET $offset
    ");

    $faults = [];
    while ($row = $res->fetch_assoc()) $faults[] = $row;

    // Total count
    $cnt_res = $conn->query("
        SELECT COUNT(DISTINCT rf.REP_FAULT_ID) AS total
        FROM reported_fault rf
        $join
        $where
    ");
    $total = (int)$cnt_res->fetch_assoc()['total'];

    api_ok(['faults' => $faults, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

// ── POST — Client reports a new fault ────────────────────────
if ($method === 'POST') {
    if ($role !== 'Client') api_error('Only clients can report faults', 403);

    $body = get_body();

    // Required
    $description = trim($body['description'] ?? '');
    if (!$description) api_error('description is required');

    // Optional but matched to report_fault.php fields
    $fault_ref       = clean($body['fault_ref']       ?? ('BQ-' . date('Y') . '-' . rand(10000,99999)));
    $fault_id_raw    = (int)($body['fault_id']         ?? 0);
    $fault_type_lbl  = clean($body['fault_type_label'] ?? '');
    $equipment_type  = clean($body['equipment_type']   ?? '');
    $brand_model     = clean($body['brand_model']      ?? '');
    $serial_number   = clean($body['serial_number']    ?? '');
    $priority_raw    = $body['priority'] ?? 'Medium';
    $priority        = in_array($priority_raw, ['Low','Medium','High','Critical']) ? $priority_raw : 'Medium';
    $fault_date      = clean($body['fault_date']       ?? date('Y-m-d'));
    $fault_time      = clean($body['fault_time']       ?? date('H:i'));
    $is_operational  = clean($body['is_operational']   ?? 'No');
    $occurred_before = clean($body['occurred_before']  ?? 'No');
    $users_affected  = max(1, (int)($body['users_affected'] ?? 1));
    $fault_location  = clean($body['fault_location']   ?? '');
    $dept_branch     = clean($body['dept_branch']      ?? '');
    $contact_method  = clean($body['contact_method']   ?? 'Email');
    $service_visit   = clean($body['service_visit']    ?? 'Unsure');

    // Get client's contact name — matches report_fault.php
    $cli = $conn->query("SELECT CONTACT_PERSON_NAME FROM client WHERE CLIENT_ID = $uid LIMIT 1");
    $reported_by = $cli ? clean($cli->fetch_assoc()['CONTACT_PERSON_NAME'] ?? '') : '';

    // Build full DESCRIPTION block (same format as web form)
    $fault_type_line = $fault_id_raw > 0
        ? "FAULT TYPE: " . clean(
            ($conn->query("SELECT FAULT_TYPE FROM fault WHERE FAULT_ID=$fault_id_raw LIMIT 1")->fetch_assoc()['FAULT_TYPE'] ?? $fault_type_lbl)
          )
        : "FAULT TITLE: $fault_type_lbl";

    $full_desc = "FAULT REFERENCE: $fault_ref\n"
               . "$fault_type_line\n"
               . ($equipment_type ? "EQUIPMENT TYPE: $equipment_type\n" : '')
               . ($brand_model    ? "BRAND/MODEL: $brand_model\n"      : '')
               . ($serial_number  ? "SERIAL/ASSET NO: $serial_number\n" : '')
               . "FAULT DATE/TIME: $fault_date $fault_time\n"
               . "IS OPERATIONAL: $is_operational\n"
               . "OCCURRED BEFORE: $occurred_before\n"
               . "USERS AFFECTED: $users_affected\n"
               . ($fault_location ? "FAULT LOCATION: $fault_location\n"    : '')
               . ($dept_branch    ? "DEPARTMENT/BRANCH: $dept_branch\n"    : '')
               . "PREFERRED CONTACT: $contact_method\n"
               . "SERVICE VISIT REQUIRED: $service_visit\n"
               . "\nDETAILED DESCRIPTION:\n$description";

    $desc_esc    = clean($full_desc);
    $rep_esc     = clean($reported_by);
    $fault_id_val = $fault_id_raw > 0 ? $fault_id_raw : 'NULL';
    $now         = date('Y-m-d H:i:s');

    $conn->query("
        INSERT INTO reported_fault
            (CLIENT_ID, FAULT_ID, REPORT_DATE, STATUS, PRIORITY, REPORTED_BY, DESCRIPTION)
        VALUES
            ($uid, $fault_id_val, '$now', 'Pending', '$priority', '$rep_esc', '$desc_esc')
    ");
    $new_id = (int)$conn->insert_id;
    if (!$new_id) api_error('Failed to submit fault. Please try again.');

    api_ok(['fault_id' => $new_id, 'reference' => $fault_ref, 'status' => 'Pending'], 201);
}

// ── PUT — Status update ───────────────────────────────────────
if ($method === 'PUT') {
    $body     = get_body();
    $fault_id = (int)($body['fault_id'] ?? 0);
    $action   = trim($body['action'] ?? '');

    if (!$fault_id) api_error('fault_id is required');

    // Load fault
    $res = $conn->query("
        SELECT rf.*, a.ASSIGN_ID
        FROM reported_fault rf
        LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
        WHERE rf.REP_FAULT_ID = $fault_id
        LIMIT 1
    ");
    if (!$res || $res->num_rows === 0) api_error('Fault not found', 404);
    $fault    = $res->fetch_assoc();
    $assign_id = (int)($fault['ASSIGN_ID'] ?? 0);

    // ── Technician: start ──────────────────────────────────────
    if ($role === 'Technician' && $action === 'start') {
        if ($fault['STATUS'] !== 'Assigned') {
            api_error("Cannot start: fault status is '{$fault['STATUS']}', expected 'Assigned'");
        }
        // Verify technician owns this assignment
        $chk = $conn->query("
            SELECT 1 FROM assignment_technician
            WHERE ASSIGN_ID = $assign_id AND EMP_ID = $uid LIMIT 1
        ");
        if (!$chk || $chk->num_rows === 0) api_error('Access denied to this fault', 403);

        $conn->query("UPDATE reported_fault SET STATUS='In Progress' WHERE REP_FAULT_ID=$fault_id");
        $conn->query("INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT)
                      VALUES ($assign_id, $uid, 'Start', 'Work started by technician', 0)");
        $conn->query("INSERT INTO notifications (user_id, user_type, title, message)
                      VALUES (1, 'Admin', 'Work Started',
                      'Technician {$user['user_name']} started work on fault #$fault_id')");
        api_ok(['fault_id' => $fault_id, 'status' => 'In Progress']);
    }

    // ── Technician: complete ───────────────────────────────────
    if ($role === 'Technician' && $action === 'complete') {
        if ($fault['STATUS'] !== 'In Progress') {
            api_error("Cannot complete: fault status is '{$fault['STATUS']}', expected 'In Progress'");
        }
        $chk = $conn->query("
            SELECT 1 FROM assignment_technician
            WHERE ASSIGN_ID = $assign_id AND EMP_ID = $uid LIMIT 1
        ");
        if (!$chk || $chk->num_rows === 0) api_error('Access denied to this fault', 403);

        $note     = clean($body['note'] ?? 'Work completed');
        $client_id = (int)$fault['CLIENT_ID'];

        $conn->query("UPDATE reported_fault SET STATUS='Completed' WHERE REP_FAULT_ID=$fault_id");
        $conn->query("INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT)
                      VALUES ($assign_id, $uid, 'Complete', '$note', 0)");
        $conn->query("INSERT INTO notifications (user_id, user_type, title, message)
                      VALUES (1, 'Admin', 'Fault Completed',
                      'Fault #$fault_id marked completed by {$user['user_name']}')");
        $conn->query("INSERT INTO notifications (user_id, user_type, title, message)
                      VALUES ($client_id, 'Client', 'Fault Resolved',
                      'Your fault #$fault_id has been resolved. Please verify and confirm.')");
        api_ok(['fault_id' => $fault_id, 'status' => 'Completed']);
    }

    // ── Technician: add note ───────────────────────────────────
    if ($role === 'Technician' && $action === 'note') {
        $note = clean($body['note'] ?? '');
        if (!$note) api_error('note is required');
        $conn->query("INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT)
                      VALUES ($assign_id, $uid, 'Note', '$note', 0)");
        api_ok(['fault_id' => $fault_id, 'message' => 'Note added']);
    }

    // ── Client: confirm ───────────────────────────────────────
    if ($role === 'Client' && $action === 'confirm') {
        if ($fault['STATUS'] !== 'Completed') {
            api_error("Cannot confirm: fault status is '{$fault['STATUS']}', expected 'Completed'");
        }
        if ((int)$fault['CLIENT_ID'] !== $uid) api_error('Access denied', 403);

        $conn->query("UPDATE reported_fault SET STATUS='Client Approved' WHERE REP_FAULT_ID=$fault_id AND CLIENT_ID=$uid");

        // Upsert into client_confirmations (matches client_faults.php logic)
        $conn->query("
            INSERT INTO client_confirmations (fault_id, client_id, confirmation_status, confirmed_at)
            VALUES ($fault_id, $uid, 'Confirmed', NOW())
            ON DUPLICATE KEY UPDATE confirmation_status='Confirmed', confirmed_at=NOW()
        ");

        // Notify accountants
        $acc_res = $conn->query("SELECT EMP_ID FROM employee WHERE ROLE='Accountant'");
        while ($acc = $acc_res->fetch_assoc()) {
            $eid = (int)$acc['EMP_ID'];
            $conn->query("INSERT INTO notifications (user_id, user_type, title, message)
                          VALUES ($eid, 'Employee', 'Fault Approved – Ready for Invoice',
                          'Client approved fault #$fault_id. Please generate the invoice.')");
        }
        api_ok(['fault_id' => $fault_id, 'status' => 'Client Approved']);
    }

    // ── Client: reject / rework ───────────────────────────────
    if ($role === 'Client' && $action === 'reject') {
        if ($fault['STATUS'] !== 'Completed') {
            api_error("Cannot reject: fault status is '{$fault['STATUS']}', expected 'Completed'");
        }
        if ((int)$fault['CLIENT_ID'] !== $uid) api_error('Access denied', 403);

        $reason = clean($body['reason'] ?? '');
        $conn->query("UPDATE reported_fault SET STATUS='Rework Required' WHERE REP_FAULT_ID=$fault_id AND CLIENT_ID=$uid");
        $conn->query("
            INSERT INTO client_confirmations (fault_id, client_id, confirmation_status, confirmation_notes, confirmed_at)
            VALUES ($fault_id, $uid, 'Needs Rework', '$reason', NOW())
            ON DUPLICATE KEY UPDATE confirmation_status='Needs Rework', confirmation_notes='$reason', confirmed_at=NOW()
        ");
        if ($reason) {
            $conn->query("INSERT INTO fault_rejections (fault_id, client_id, rejection_reason)
                          VALUES ($fault_id, $uid, '$reason')");
        }
        api_ok(['fault_id' => $fault_id, 'status' => 'Rework Required']);
    }

    api_error("Unknown action '$action' for role '$role'");
}

api_error('Method not allowed', 405);
