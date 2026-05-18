<?php
// ═══════════════════════════════════════════════════════════════
//  api/invoices.php  —  Invoices & Payments
//
//  GET  /api/invoices.php            List invoices for logged-in user
//  GET  /api/invoices.php?id=X       Single invoice with lines + payments
//  POST /api/invoices.php            Client submits a payment
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth(['Client', 'Accountant', 'Admin', 'Technician']);
$uid    = (int)$user['user_id'];
$role   = $user['user_type'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($invoice_id > 0) {
        // ── Single invoice detail ──
        $access_clause = match($role) {
            'Client' => "AND i.CLIENT_ID = $uid",
            default  => '',
        };

        $res = $conn->query("
            SELECT i.INVOICE_ID, i.CLIENT_ID, i.ASSIGN_ID, i.INVOICE_DATE, i.DUE_DATE,
                   i.STATUS, i.TYPE, i.TOTAL, i.PAID_AMOUNT,
                   c.COMPANY_NAME, c.COMPANY_EMAIL, c.COMPANY_PHONE,
                   rf.REP_FAULT_ID, rf.DESCRIPTION AS FAULT_DESC, rf.STATUS AS FAULT_STATUS,
                   rf.PRIORITY, rf.REPORT_DATE
            FROM invoice i
            LEFT JOIN client c ON c.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN assignment a ON a.ASSIGN_ID = i.ASSIGN_ID
            LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID = a.REP_FAULT_ID
            WHERE i.INVOICE_ID = $invoice_id $access_clause
            LIMIT 1
        ");
        if (!$res || $res->num_rows === 0) api_error('Invoice not found or access denied', 404);
        $invoice = $res->fetch_assoc();

        // Line items
        $lines_res = $conn->query("
            SELECT LINE_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, LINE_TOTAL
            FROM invoice_line WHERE INVOICE_ID = $invoice_id ORDER BY LINE_ID ASC
        ");
        $invoice['lines'] = [];
        while ($l = $lines_res->fetch_assoc()) $invoice['lines'][] = $l;

        // Payments
        $pay_res = $conn->query("
            SELECT p.PAYMENT_ID, p.PAYMENT_DATE, p.AMOUNT_PAID, p.METHOD,
                   p.REFERENCE_NUMBER, p.STATUS,
                   r.RECEIPT_ID, r.RECEIPT_DATE
            FROM payment p
            LEFT JOIN receipt r ON r.PAYMENT_ID = p.PAYMENT_ID
            WHERE p.INVOICE_ID = $invoice_id
            ORDER BY p.PAYMENT_DATE DESC
        ");
        $invoice['payments'] = [];
        while ($p = $pay_res->fetch_assoc()) $invoice['payments'][] = $p;

        api_ok($invoice);
    }

    // ── List invoices ──
    $status_filter = isset($_GET['status']) ? clean($_GET['status']) : '';
    $limit  = min((int)($_GET['limit']  ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $where_parts = [];
    if ($role === 'Client')      $where_parts[] = "i.CLIENT_ID = $uid";
    if ($role === 'Technician') {
        // Technicians see invoices linked to their assignments
        $where_parts[] = "at2.EMP_ID = $uid";
    }
    // Status filter — matches values used in client_invoices.php
    $valid_statuses = ['Paid','Partial','Overdue','Unpaid','Pending Payment','Approved','Invoiced'];
    if ($status_filter && in_array($status_filter, $valid_statuses)) {
        $where_parts[] = "i.STATUS = '$status_filter'";
    }

    $join  = ($role === 'Technician')
        ? "LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = i.ASSIGN_ID"
        : '';
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $res = $conn->query("
        SELECT i.INVOICE_ID, i.CLIENT_ID, i.ASSIGN_ID, i.INVOICE_DATE, i.DUE_DATE,
               i.STATUS, i.TYPE, i.TOTAL, i.PAID_AMOUNT,
               c.COMPANY_NAME
        FROM invoice i
        LEFT JOIN client c ON c.CLIENT_ID = i.CLIENT_ID
        $join
        $where
        ORDER BY i.INVOICE_DATE DESC
        LIMIT $limit OFFSET $offset
    ");
    $invoices = [];
    while ($row = $res->fetch_assoc()) $invoices[] = $row;

    $cnt_res = $conn->query("SELECT COUNT(DISTINCT i.INVOICE_ID) AS total FROM invoice i $join $where");
    $total   = (int)$cnt_res->fetch_assoc()['total'];

    api_ok(['invoices' => $invoices, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

// ── POST — Client submits payment ─────────────────────────────
if ($method === 'POST') {
    if ($role !== 'Client') api_error('Only clients can submit payments', 403);

    $body       = get_body();
    $invoice_id = (int)($body['invoice_id'] ?? 0);
    $amount     = round((float)($body['amount'] ?? 0), 2);
    $method_pay = clean($body['method']         ?? '');   // e.g. Card Transfer, Mobile Money, Bank Transfer
    $reference  = clean($body['reference']      ?? '');
    $mobile     = clean($body['mobile_number']  ?? '');
    $network    = clean($body['network']        ?? '');
    $bank       = clean($body['bank']           ?? '');
    $acc_num    = clean($body['account_number'] ?? '');

    if (!$invoice_id) api_error('invoice_id is required');
    if ($amount <= 0)  api_error('amount must be greater than 0');
    if (!$method_pay)  api_error('payment method is required');

    // Validate invoice belongs to this client
    $inv_res = $conn->query("
        SELECT INVOICE_ID, TOTAL, STATUS, PAID_AMOUNT
        FROM invoice
        WHERE INVOICE_ID = $invoice_id AND CLIENT_ID = $uid
        LIMIT 1
    ");
    if (!$inv_res || $inv_res->num_rows === 0) api_error('Invoice not found', 404);
    $inv = $inv_res->fetch_assoc();

    if (in_array($inv['STATUS'], ['Paid', 'Closed'])) {
        api_error("Invoice is already {$inv['STATUS']}");
    }

    $today = date('Y-m-d');
    $conn->query("
        INSERT INTO payment (INVOICE_ID, PAYMENT_DATE, AMOUNT_PAID, METHOD, REFERENCE_NUMBER, STATUS)
        VALUES ($invoice_id, '$today', $amount, '$method_pay', '$reference', 'Pending')
    ");
    $payment_id = (int)$conn->insert_id;
    if (!$payment_id) api_error('Failed to record payment');

    api_ok([
        'payment_id' => $payment_id,
        'invoice_id' => $invoice_id,
        'amount'     => $amount,
        'method'     => $method_pay,
        'status'     => 'Pending',
        'message'    => 'Payment submitted. Awaiting accountant verification.',
    ], 201);
}

api_error('Method not allowed', 405);

