<?php
// ═══════════════════════════════════════════════════════════════
//  api/dashboard.php  —  Role-specific dashboard stats
//
//  GET /api/dashboard.php   Returns stats relevant to logged-in role
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$user = require_auth();
$uid  = (int)$user['user_id'];
$role = $user['user_type'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error('Method not allowed', 405);

// ── CLIENT dashboard ──────────────────────────────────────────
if ($role === 'Client') {
    $total   = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE CLIENT_ID=$uid")->fetch_assoc()['c'];
    $pending = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE CLIENT_ID=$uid AND STATUS='Pending'")->fetch_assoc()['c'];
    $in_prog = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE CLIENT_ID=$uid AND STATUS='In Progress'")->fetch_assoc()['c'];
    $done    = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE CLIENT_ID=$uid AND STATUS IN ('Completed','Client Approved','Closed')")->fetch_assoc()['c'];
    $rework  = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE CLIENT_ID=$uid AND STATUS='Rework Required'")->fetch_assoc()['c'];

    // Invoices
    $inv_pending = (int)$conn->query("SELECT COUNT(*) c FROM invoice WHERE CLIENT_ID=$uid AND STATUS IN ('Pending Payment','Invoiced')")->fetch_assoc()['c'];
    $inv_total   = (float)$conn->query("SELECT COALESCE(SUM(TOTAL),0) s FROM invoice WHERE CLIENT_ID=$uid")->fetch_assoc()['s'];
    $inv_paid    = (float)$conn->query("SELECT COALESCE(SUM(PAID_AMOUNT),0) s FROM invoice WHERE CLIENT_ID=$uid")->fetch_assoc()['s'];

    // Wallet
    $wallet = (float)$conn->query("SELECT WALLET_BALANCE FROM client WHERE CLIENT_ID=$uid LIMIT 1")->fetch_assoc()['WALLET_BALANCE'];

    // Unread notifications
    $unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND user_type='Client' AND is_read=0")->fetch_assoc()['c'];

    // Recent faults
    $recent_res = $conn->query("
        SELECT rf.REP_FAULT_ID, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE,
               rf.DESCRIPTION, a.DUE_DATE
        FROM reported_fault rf
        LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
        WHERE rf.CLIENT_ID = $uid
        ORDER BY rf.REPORT_DATE DESC LIMIT 5
    ");
    $recent = [];
    while ($r = $recent_res->fetch_assoc()) $recent[] = $r;

    api_ok([
        'faults' => [
            'total'      => $total,
            'pending'    => $pending,
            'in_progress'=> $in_prog,
            'completed'  => $done,
            'rework'     => $rework,
        ],
        'invoices' => [
            'pending_count' => $inv_pending,
            'total_billed'  => $inv_total,
            'total_paid'    => $inv_paid,
            'outstanding'   => round($inv_total - $inv_paid, 2),
        ],
        'wallet_balance'     => $wallet,
        'unread_notifications' => $unread,
        'recent_faults'      => $recent,
    ]);
}

// ── TECHNICIAN dashboard ──────────────────────────────────────
if ($role === 'Technician') {
    $assigned  = (int)$conn->query("
        SELECT COUNT(*) c FROM assignment a
        INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
        INNER JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
        WHERE at2.EMP_ID=$uid AND rf.STATUS='Assigned'")->fetch_assoc()['c'];
    $in_prog   = (int)$conn->query("
        SELECT COUNT(*) c FROM assignment a
        INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
        INNER JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
        WHERE at2.EMP_ID=$uid AND rf.STATUS='In Progress'")->fetch_assoc()['c'];
    $completed = (int)$conn->query("
        SELECT COUNT(*) c FROM assignment a
        INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
        INNER JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
        WHERE at2.EMP_ID=$uid AND rf.STATUS='Completed'")->fetch_assoc()['c'];
    $total_hrs = (float)$conn->query("
        SELECT COALESCE(SUM(wl.HOURS_SPENT),0) h FROM work_log wl
        INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID=wl.ASSIGN_ID
        WHERE at2.EMP_ID=$uid")->fetch_assoc()['h'];

    $unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND user_type='Employee' AND is_read=0")->fetch_assoc()['c'];

    // Active faults
    $active_res = $conn->query("
        SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE,
               c.COMPANY_NAME, a.ASSIGN_ID, a.DUE_DATE
        FROM reported_fault rf
        INNER JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
        LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
        WHERE at2.EMP_ID=$uid AND rf.STATUS IN ('Assigned','In Progress')
        ORDER BY rf.REPORT_DATE DESC LIMIT 10
    ");
    $active = [];
    while ($r = $active_res->fetch_assoc()) $active[] = $r;

    api_ok([
        'faults' => [
            'assigned'   => $assigned,
            'in_progress'=> $in_prog,
            'completed'  => $completed,
        ],
        'total_hours_logged'   => $total_hrs,
        'unread_notifications' => $unread,
        'active_faults'        => $active,
    ]);
}

// ── ACCOUNTANT dashboard ──────────────────────────────────────
if ($role === 'Accountant') {
    $pending_invoices = (int)$conn->query("SELECT COUNT(*) c FROM invoice WHERE STATUS='Pending Payment'")->fetch_assoc()['c'];
    $pending_payments = (int)$conn->query("SELECT COUNT(*) c FROM payment WHERE STATUS='Pending'")->fetch_assoc()['c'];
    $total_revenue    = (float)$conn->query("SELECT COALESCE(SUM(PAID_AMOUNT),0) s FROM invoice")->fetch_assoc()['s'];
    $outstanding      = (float)$conn->query("SELECT COALESCE(SUM(TOTAL-PAID_AMOUNT),0) s FROM invoice WHERE STATUS NOT IN ('Paid','Closed')")->fetch_assoc()['s'];
    $company_bal      = (float)$conn->query("SELECT company_balance FROM company_settings LIMIT 1")->fetch_assoc()['company_balance'];

    $unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND user_type='Employee' AND is_read=0")->fetch_assoc()['c'];

    // Pending payments needing verification
    $pend_res = $conn->query("
        SELECT p.PAYMENT_ID, p.INVOICE_ID, p.PAYMENT_DATE, p.AMOUNT_PAID,
               p.METHOD, p.REFERENCE_NUMBER, c.COMPANY_NAME
        FROM payment p
        LEFT JOIN invoice i ON i.INVOICE_ID=p.INVOICE_ID
        LEFT JOIN client c ON c.CLIENT_ID=i.CLIENT_ID
        WHERE p.STATUS='Pending'
        ORDER BY p.PAYMENT_DATE DESC LIMIT 10
    ");
    $pending_pay_list = [];
    while ($r = $pend_res->fetch_assoc()) $pending_pay_list[] = $r;

    api_ok([
        'invoices' => [
            'pending_payment' => $pending_invoices,
            'total_revenue'   => $total_revenue,
            'outstanding'     => round($outstanding, 2),
        ],
        'pending_payments'     => $pending_pay_list,
        'company_balance'      => $company_bal,
        'unread_notifications' => $unread,
    ]);
}

// ── ADMIN dashboard ───────────────────────────────────────────
if ($role === 'Admin') {
    $total_faults     = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault")->fetch_assoc()['c'];
    $pending_faults   = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE STATUS='Pending'")->fetch_assoc()['c'];
    $in_prog_faults   = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE STATUS='In Progress'")->fetch_assoc()['c'];
    $completed_faults = (int)$conn->query("SELECT COUNT(*) c FROM reported_fault WHERE STATUS='Completed'")->fetch_assoc()['c'];
    $total_clients    = (int)$conn->query("SELECT COUNT(*) c FROM client")->fetch_assoc()['c'];
    $total_employees  = (int)$conn->query("SELECT COUNT(*) c FROM employee")->fetch_assoc()['c'];
    $total_techs      = (int)$conn->query("SELECT COUNT(*) c FROM employee WHERE ROLE='Technician'")->fetch_assoc()['c'];
    $company_bal      = (float)$conn->query("SELECT company_balance FROM company_settings LIMIT 1")->fetch_assoc()['company_balance'];

    $unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND user_type='Admin' AND is_read=0")->fetch_assoc()['c'];

    // Recent faults
    $recent_res = $conn->query("
        SELECT rf.REP_FAULT_ID, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE,
               c.COMPANY_NAME, f.FAULT_TYPE
        FROM reported_fault rf
        LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
        LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
        ORDER BY rf.REPORT_DATE DESC LIMIT 10
    ");
    $recent = [];
    while ($r = $recent_res->fetch_assoc()) $recent[] = $r;

    api_ok([
        'faults' => [
            'total'      => $total_faults,
            'pending'    => $pending_faults,
            'in_progress'=> $in_prog_faults,
            'completed'  => $completed_faults,
        ],
        'clients'              => $total_clients,
        'employees'            => $total_employees,
        'technicians'          => $total_techs,
        'company_balance'      => $company_bal,
        'unread_notifications' => $unread,
        'recent_faults'        => $recent,
    ]);
}

