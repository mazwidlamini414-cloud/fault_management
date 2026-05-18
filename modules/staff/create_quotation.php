<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  create_quotation.php  –  Technician Quotation Builder
//  Busiquip Field Service Portal
// ═══════════════════════════════════════════════════════════════════════════════

require_once '../../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Support both session key conventions used across the portal
$tech_id   = (int)($_SESSION['emp_id'] ?? $_SESSION['user_id'] ?? $_SESSION['EMP_ID'] ?? 0);
$tech_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['name'] ?? 'Technician';
$hourly    = (float)($_SESSION['hourly_rate'] ?? 85.00);

// ── Dev fallback: if session is empty, try to identify from DB by username ──
// Remove this block in production if you have strict auth middleware
if ($tech_id === 0 && !empty($_SESSION['username'])) {
    $un  = mysqli_real_escape_string($conn, $_SESSION['username']);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT EMP_ID, FULL_NAME, HOURLY_RATE FROM employee WHERE USERNAME='$un' AND ROLE='Technician' LIMIT 1"));
    if ($row) {
        $tech_id   = (int)$row['EMP_ID'];
        $tech_name = $row['FULL_NAME'];
        $hourly    = (float)($row['HOURLY_RATE'] ?? 85.00);
        $_SESSION['emp_id']      = $tech_id;
        $_SESSION['full_name']   = $tech_name;
        $_SESSION['hourly_rate'] = $hourly;
    }
}

// ── Resolve fault/assign from URL ────────────────────────────────────────────
$fault_id  = intval($_GET['fault_id']  ?? $_POST['fault_id']  ?? 0);
$assign_id = intval($_GET['assign_id'] ?? $_POST['assign_id'] ?? 0);

// ── POST: Save quotation ──────────────────────────────────────────────────────
$toast = [];
if (!empty($_SESSION['toast'])) { $toast = $_SESSION['toast']; unset($_SESSION['toast']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_quotation'])) {

    $f_id      = intval($_POST['fault_id']);
    $a_id      = intval($_POST['assign_id']);
    $client_id = intval($_POST['client_id']);
    $action    = $_POST['submit_quotation'];   // 'draft' | 'submit'
    $status    = ($action === 'submit') ? 'Submitted' : 'Draft';

    $today    = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+14 days'));

    // Notes / labour breakdown
    $notes          = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $labour_hours   = floatval($_POST['labour_hours']  ?? 0);
    $labour_rate    = floatval($_POST['labour_rate']   ?? $hourly);
    $travel_km      = floatval($_POST['travel_km']     ?? 0);
    $travel_rate    = floatval($_POST['travel_rate']   ?? 3.50); // E per km
    $call_out_fee   = floatval($_POST['call_out_fee']  ?? 0);

    // Build line items array from POST
    $descs  = $_POST['line_desc']  ?? [];
    $qtys   = $_POST['line_qty']   ?? [];
    $prices = $_POST['line_price'] ?? [];
    $ltypes = $_POST['line_type']  ?? [];

    $lines = [];
    $total = 0;

    // Auto-inject labour line
    if ($labour_hours > 0) {
        $lt = round($labour_hours * $labour_rate, 2);
        $total += $lt;
        $lines[] = ['desc' => "Labour – Technician Time ({$labour_hours} hrs @ E{$labour_rate}/hr)", 'qty' => $labour_hours, 'price' => $labour_rate, 'total' => $lt, 'type' => 'Labour'];
    }
    // Auto-inject travel line
    if ($travel_km > 0) {
        $lt = round($travel_km * $travel_rate, 2);
        $total += $lt;
        $lines[] = ['desc' => "Transport – Travel & Fuel ({$travel_km} km @ E{$travel_rate}/km)", 'qty' => $travel_km, 'price' => $travel_rate, 'total' => $lt, 'type' => 'Transport'];
    }
    // Call-out fee
    if ($call_out_fee > 0) {
        $total += $call_out_fee;
        $lines[] = ['desc' => 'Call-Out / Site Visit Fee', 'qty' => 1, 'price' => $call_out_fee, 'total' => $call_out_fee, 'type' => 'Fee'];
    }

    // Manual line items
    foreach ($descs as $i => $d) {
        $d = trim($d);
        $q = floatval($qtys[$i] ?? 0);
        $p = floatval($prices[$i] ?? 0);
        if ($d && $q > 0 && $p >= 0) {
            $lt = round($q * $p, 2);
            $total += $lt;
            $lines[] = ['desc' => $d, 'qty' => $q, 'price' => $p, 'total' => $lt, 'type' => $ltypes[$i] ?? 'Parts'];
        }
    }

    if (empty($lines)) {
        $_SESSION['toast'] = ['msg' => 'Add at least one line item (labour, travel, parts, or fee).', 'type' => 'error'];
        header("Location: create_quotation.php?fault_id=$f_id&assign_id=$a_id");
        exit;
    }

    $total = round($total, 2);

    // Insert invoice record
    mysqli_query($conn, "
        INSERT INTO invoice (CLIENT_ID, ASSIGN_ID, INVOICE_DATE, DUE_DATE, STATUS, TYPE, TOTAL)
        VALUES ($client_id, $a_id, '$today', '$due_date', '$status', 'Quotation', $total)
    ");
    $inv_id = mysqli_insert_id($conn);

    // Insert line items
    foreach ($lines as $ln) {
        $de = mysqli_real_escape_string($conn, $ln['desc']);
        mysqli_query($conn, "
            INSERT INTO invoice_line (INVOICE_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, LINE_TOTAL)
            VALUES ($inv_id, '$de', {$ln['qty']}, {$ln['price']}, {$ln['total']})
        ");
    }

    // Audit trail
    $tech_escaped = mysqli_real_escape_string($conn, $tech_name);
    mysqli_query($conn, "
        INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
        VALUES ($inv_id, 'Quotation $status',
                'Quotation created by technician $tech_escaped for fault #$f_id (Total: E$total)',
                $tech_id, 'Technician')
    ");

    // Notify accountant(s) if submitted
    if ($action === 'submit') {
        $accs = mysqli_query($conn, "SELECT EMP_ID FROM employee WHERE ROLE='Accountant'");
        $quo_num = 'QUO-' . str_pad($inv_id, 4, '0', STR_PAD_LEFT);
        while ($acc = mysqli_fetch_assoc($accs)) {
            $msg = mysqli_real_escape_string($conn,
                "Technician $tech_name submitted quotation $quo_num (E" . number_format($total, 2) . ") for fault #$f_id. Please review and confirm.");
            mysqli_query($conn, "
                INSERT INTO notifications (user_id, user_type, title, message)
                VALUES ({$acc['EMP_ID']}, 'Employee', 'New Quotation Submitted', '$msg')
            ");
        }
        // Notify admin too
        $admins = mysqli_query($conn, "SELECT ADMIN_ID FROM admin LIMIT 5");
        while ($adm = mysqli_fetch_assoc($admins)) {
            $msg2 = mysqli_real_escape_string($conn,
                "Technician $tech_name submitted $quo_num (E" . number_format($total,2) . ") for fault #$f_id.");
            mysqli_query($conn, "
                INSERT INTO notifications (user_id, user_type, title, message)
                VALUES ({$adm['ADMIN_ID']}, 'Admin', 'New Quotation Submitted', '$msg2')
            ");
        }
        $_SESSION['toast'] = ['msg' => "Quotation $quo_num submitted successfully to the accountant!", 'type' => 'success'];
    } else {
        $quo_num = 'QUO-' . str_pad($inv_id, 4, '0', STR_PAD_LEFT);
        $_SESSION['toast'] = ['msg' => "Draft $quo_num saved. You can submit it later from Quotation History.", 'type' => 'info'];
    }

    header('Location: quotation_history.php');
    exit;
}

// ── Resolve assign_id from fault if not given ────────────────────────────────
if ($fault_id && !$assign_id) {
    $ar = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT a.ASSIGN_ID FROM assignment a
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        WHERE a.REP_FAULT_ID = $fault_id AND at2.EMP_ID = $tech_id LIMIT 1
    "));
    $assign_id = intval($ar['ASSIGN_ID'] ?? 0);
}

// ── Load selected fault detail ───────────────────────────────────────────────
$fault = null;
if ($fault_id && $assign_id) {
    $fault = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE,
               c.COMPANY_NAME, c.CONTACT_PERSON_NAME, c.COMPANY_PHONE, c.CLIENT_ID,
               a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE AS ASSIGN_DUE,
               GROUP_CONCAT(DISTINCT e.FULL_NAME ORDER BY e.FULL_NAME SEPARATOR ', ') AS TEAM
        FROM reported_fault rf
        INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
        LEFT JOIN assignment_technician at3 ON a.ASSIGN_ID = at3.ASSIGN_ID
        LEFT JOIN employee e ON at3.EMP_ID = e.EMP_ID
        WHERE rf.REP_FAULT_ID = $fault_id AND a.ASSIGN_ID = $assign_id AND at2.EMP_ID = $tech_id
        GROUP BY rf.REP_FAULT_ID
        LIMIT 1
    "));
}

// ── All faults assigned to this technician (for the selector) ────────────────
$all_faults = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY,
           c.COMPANY_NAME, a.ASSIGN_ID
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    WHERE at2.EMP_ID = $tech_id
      AND rf.STATUS IN ('In Progress','Assigned','Completed')
    ORDER BY rf.REPORT_DATE DESC
");

// ── Common parts/services catalogue (for quick-add buttons) ─────────────────
$catalogue = [
    ['name' => 'Diagnostic Fee',           'price' => 150.00, 'type' => 'Fee'],
    ['name' => 'Software Reinstallation',  'price' => 200.00, 'type' => 'Service'],
    ['name' => 'Hard Drive Replacement',   'price' => 850.00, 'type' => 'Parts'],
    ['name' => 'RAM Module (8GB)',          'price' => 450.00, 'type' => 'Parts'],
    ['name' => 'Thermal Paste Application','price' => 80.00,  'type' => 'Service'],
    ['name' => 'Network Cable (per metre)','price' => 12.00,  'type' => 'Parts'],
    ['name' => 'Power Supply Unit',        'price' => 700.00, 'type' => 'Parts'],
    ['name' => 'UPS Battery Replacement',  'price' => 950.00, 'type' => 'Parts'],
    ['name' => 'Printer Cartridge Set',    'price' => 380.00, 'type' => 'Parts'],
    ['name' => 'Antivirus Licence (1yr)',  'price' => 350.00, 'type' => 'Service'],
    ['name' => 'On-site Support (hourly)', 'price' => $hourly,'type' => 'Labour'],
    ['name' => 'Remote Support (hourly)',  'price' => 65.00,  'type' => 'Labour'],
];

// Parse fault reference from structured description
function parseFaultField(string $desc, string $field): string {
    $safe = preg_quote($field, '/');
    if (preg_match("/$safe:\s*([^\n]+)/i", $desc, $m)) return trim($m[1]);
    return '';
}

$page_title = 'Create Quotation';
require_once '../../includes/tech_header.php';
?>

<!-- ═══════════════════════ STYLES ═══════════════════════════════════════════ -->
<style>
  @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Sora:wght@300;400;500;600;700&display=swap');

  :root {
    --bg:        #0a0d12;
    --surface:   #0f1318;
    --surface2:  #141920;
    --border:    #1e2530;
    --border2:   #252d3a;
    --text:      #d8e4f0;
    --muted:     #5a6a80;
    --accent:    #00c8ff;
    --accent2:   #0088bb;
    --green:     #00e08a;
    --orange:    #ff9040;
    --red:       #ff4455;
    --purple:    #9060ff;
    --yellow:    #ffc840;
    --mono:      'JetBrains Mono', monospace;
    --sans:      'Sora', sans-serif;
    --radius:    10px;
    --shadow:    0 4px 24px rgba(0,0,0,.4);
  }

  * { box-sizing:border-box; margin:0; padding:0; }

  body { font-family: var(--sans); background: var(--bg); color: var(--text); }

  /* ── Toast ── */
  .qr-toast {
    position: fixed; top: 1.2rem; right: 1.2rem; z-index: 9999;
    background: var(--surface2); border: 1px solid var(--border2);
    border-radius: var(--radius); padding: .85rem 1.2rem;
    display: flex; align-items: center; gap: .75rem;
    font-size: .83rem; box-shadow: var(--shadow);
    animation: slideIn .3s ease;
  }
  .qr-toast.success { border-left: 3px solid var(--green); }
  .qr-toast.error   { border-left: 3px solid var(--red); }
  .qr-toast.info    { border-left: 3px solid var(--accent); }
  @keyframes slideIn { from { transform:translateX(60px); opacity:0; } to { transform:none; opacity:1; } }

  /* ── Page header ── */
  .qr-header {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 1.25rem 2rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    flex-wrap: wrap;
  }
  .qr-header h1 {
    font-family: var(--mono); font-size: 1.15rem; font-weight: 700;
    color: #fff; display: flex; align-items: center; gap: .6rem;
  }
  .qr-header h1 .doc-tag {
    font-size: .72rem; background: rgba(0,200,255,.12); color: var(--accent);
    border: 1px solid rgba(0,200,255,.3); border-radius: 4px;
    padding: .2em .55em; letter-spacing: .5px;
  }
  .qr-actions { display: flex; gap: .6rem; flex-wrap: wrap; }

  /* ── Layout ── */
  .qr-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.25rem;
    padding: 1.5rem 2rem;
    max-width: 1280px;
    margin: 0 auto;
  }
  @media (max-width: 900px) {
    .qr-layout { grid-template-columns: 1fr; padding: 1rem; }
  }

  /* ── Cards ── */
  .qr-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.25rem;
  }
  .qr-card:last-child { margin-bottom: 0; }
  .qr-card-head {
    padding: .75rem 1.1rem;
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .6rem;
    font-size: .75rem; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: .8px;
  }
  .qr-card-head .dot {
    width: 6px; height: 6px; border-radius: 50%; background: var(--accent); flex-shrink: 0;
  }
  .qr-card-body { padding: 1.1rem; }

  /* ── Fault selector ── */
  .fault-select-wrap { position: relative; }
  .fault-select-wrap select {
    width: 100%; background: var(--surface2); border: 1px solid var(--border2);
    color: var(--text); border-radius: var(--radius); padding: .65rem 1rem;
    font-family: var(--sans); font-size: .85rem; appearance: none;
    cursor: pointer; transition: border-color .2s;
  }
  .fault-select-wrap select:focus { outline: none; border-color: var(--accent); }
  .fault-select-wrap::after {
    content: '▾'; position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
    color: var(--muted); pointer-events: none; font-size: .8rem;
  }

  /* ── Fault info panel ── */
  .fault-info {
    background: var(--surface2); border: 1px solid var(--border2);
    border-radius: var(--radius); padding: 1rem 1.1rem; margin-top: .75rem;
  }
  .fault-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem; }
  .fi-row { display: flex; flex-direction: column; gap: .15rem; }
  .fi-label { font-size: .68rem; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
  .fi-val   { font-size: .82rem; color: var(--text); font-weight: 500; }
  .fi-val.mono { font-family: var(--mono); color: var(--accent); }
  .fi-val.full { grid-column: 1/-1; }

  .priority-badge {
    display: inline-block; padding: .2em .6em; border-radius: 4px;
    font-size: .7rem; font-weight: 700; font-family: var(--mono);
  }
  .priority-High   { background:rgba(255,68,85,.15); color:var(--red);    border:1px solid rgba(255,68,85,.3); }
  .priority-Medium { background:rgba(255,144,64,.15); color:var(--orange); border:1px solid rgba(255,144,64,.3); }
  .priority-Low    { background:rgba(0,224,138,.15); color:var(--green);  border:1px solid rgba(0,224,138,.3); }

  /* ── Labour quick-entry ── */
  .labour-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: .75rem;
  }
  @media (max-width:600px) { .labour-grid { grid-template-columns: 1fr; } }

  .field-group { display: flex; flex-direction: column; gap: .35rem; }
  .field-group label { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
  .field-group input, .field-group select, .field-group textarea {
    background: var(--surface2); border: 1px solid var(--border2);
    color: var(--text); border-radius: 8px; padding: .55rem .75rem;
    font-family: var(--sans); font-size: .85rem; transition: border-color .2s;
    width: 100%;
  }
  .field-group input:focus, .field-group select:focus, .field-group textarea:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,200,255,.08);
  }
  .field-group .prefix-wrap { position: relative; }
  .field-group .prefix-wrap span {
    position: absolute; left: .75rem; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: .82rem; pointer-events: none;
  }
  .field-group .prefix-wrap input { padding-left: 2rem; }

  /* ── Line items table ── */
  .lines-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  .lines-table th {
    background: var(--surface2); padding: .55rem .7rem;
    text-align: left; color: var(--muted); font-size: .68rem;
    text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid var(--border);
  }
  .lines-table td { padding: .45rem .5rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
  .lines-table td input, .lines-table td select {
    background: var(--surface2); border: 1px solid var(--border2);
    color: var(--text); border-radius: 6px; padding: .4rem .55rem;
    font-family: var(--sans); font-size: .82rem; width: 100%;
    transition: border-color .2s;
  }
  .lines-table td input:focus, .lines-table td select:focus {
    outline: none; border-color: var(--accent);
  }
  .lines-table .line-total { font-family: var(--mono); font-weight: 600; color: var(--green); white-space: nowrap; }
  .lines-table .del-btn {
    background: none; border: none; color: var(--red); cursor: pointer;
    font-size: 1rem; padding: .2rem .4rem; border-radius: 4px;
    transition: background .2s;
  }
  .lines-table .del-btn:hover { background: rgba(255,68,85,.12); }

  /* ── Catalogue pills ── */
  .catalogue-grid {
    display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem;
  }
  .cat-pill {
    display: flex; align-items: center; gap: .4rem;
    background: var(--surface2); border: 1px solid var(--border2);
    border-radius: 20px; padding: .3rem .8rem; cursor: pointer;
    font-size: .75rem; color: var(--text); transition: all .18s;
    user-select: none;
  }
  .cat-pill:hover { border-color: var(--accent); color: var(--accent); background: rgba(0,200,255,.06); }
  .cat-pill .cp-price { color: var(--muted); font-family: var(--mono); font-size: .7rem; }

  /* ── Summary sidebar ── */
  .summary-sticky { position: sticky; top: 1.25rem; }

  .totals-block { margin-top: 0; }
  .total-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .5rem 0; border-bottom: 1px solid var(--border); font-size: .83rem;
  }
  .total-row:last-child { border: none; }
  .total-row .tr-label { color: var(--muted); }
  .total-row .tr-val { font-family: var(--mono); font-weight: 600; }
  .grand-total {
    display: flex; justify-content: space-between; align-items: center;
    background: rgba(0,200,255,.06); border: 1px solid rgba(0,200,255,.2);
    border-radius: 8px; padding: .85rem 1rem; margin-top: .75rem;
  }
  .grand-total .gt-label { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
  .grand-total .gt-val { font-family: var(--mono); font-size: 1.35rem; font-weight: 700; color: var(--accent); }

  /* ── Vat toggle ── */
  .vat-row { display: flex; justify-content: space-between; align-items: center; margin-top: .6rem; font-size: .8rem; }
  .vat-row label { color: var(--muted); cursor: pointer; display: flex; align-items: center; gap: .4rem; }
  .toggle {
    position: relative; width: 34px; height: 18px; display: inline-block;
  }
  .toggle input { opacity: 0; width: 0; height: 0; }
  .toggle-slider {
    position: absolute; inset: 0; background: var(--border2);
    border-radius: 18px; transition: .2s; cursor: pointer;
  }
  .toggle-slider::before {
    content: ''; position: absolute; width: 12px; height: 12px; background: #fff;
    border-radius: 50%; left: 3px; top: 3px; transition: .2s;
  }
  .toggle input:checked + .toggle-slider { background: var(--accent); }
  .toggle input:checked + .toggle-slider::before { transform: translateX(16px); }

  /* ── Status badge ── */
  .status-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .72rem; font-weight: 600; padding: .25em .7em;
    border-radius: 20px; font-family: var(--mono);
  }
  .status-pill.in-progress { background: rgba(0,200,255,.12); color: var(--accent); }
  .status-pill.completed   { background: rgba(0,224,138,.12); color: var(--green); }
  .status-pill.assigned    { background: rgba(255,200,64,.12); color: var(--yellow); }
  .status-pill .sp-dot     { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

  /* ── Buttons ── */
  .btn {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .55rem 1.1rem; border-radius: 8px; font-size: .83rem;
    font-weight: 600; cursor: pointer; border: none; font-family: var(--sans);
    transition: all .18s; text-decoration: none; white-space: nowrap;
  }
  .btn-primary  { background: var(--accent); color: #000; }
  .btn-primary:hover  { background: #33d4ff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,200,255,.3); }
  .btn-success  { background: var(--green); color: #000; }
  .btn-success:hover  { background: #33e8a0; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,224,138,.3); }
  .btn-ghost    { background: var(--surface2); color: var(--text); border: 1px solid var(--border2); }
  .btn-ghost:hover    { border-color: var(--accent); color: var(--accent); }
  .btn-danger   { background: rgba(255,68,85,.12); color: var(--red); border: 1px solid rgba(255,68,85,.25); }
  .btn-danger:hover   { background: rgba(255,68,85,.22); }
  .btn-add-line { background: rgba(0,200,255,.08); color: var(--accent); border: 1px dashed rgba(0,200,255,.3); border-radius: 8px; width:100%; justify-content:center; padding:.6rem; margin-top:.5rem; font-size:.8rem; }
  .btn-add-line:hover { background: rgba(0,200,255,.14); }

  /* ── Separator ── */
  .section-sep { height: 1px; background: var(--border); margin: 1rem 0; }

  /* ── Empty state ── */
  .empty-state {
    text-align: center; padding: 2.5rem 1rem; color: var(--muted);
  }
  .empty-state svg { opacity: .35; margin-bottom: .75rem; }

  /* ── Notes textarea ── */
  textarea { resize: vertical; min-height: 80px; line-height: 1.5; }

  /* ── Confirmation overlay ── */
  .confirm-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,.75);
    z-index: 9000; align-items: center; justify-content: center;
  }
  .confirm-overlay.open { display: flex; }
  .confirm-box {
    background: var(--surface); border: 1px solid var(--border2);
    border-radius: 14px; padding: 2rem; width: 440px; max-width: 95vw;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
    animation: popIn .25s ease;
  }
  @keyframes popIn { from { transform:scale(.9); opacity:0; } to { transform:scale(1); opacity:1; } }
  .confirm-box h3 { font-size: 1rem; margin-bottom: .5rem; color: #fff; }
  .confirm-box p  { font-size: .83rem; color: var(--muted); margin-bottom: 1.25rem; line-height: 1.6; }
  .confirm-summary {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; padding: .85rem 1rem; margin-bottom: 1.25rem;
    font-size: .82rem;
  }
  .confirm-summary .cs-row { display: flex; justify-content: space-between; margin-bottom: .35rem; }
  .confirm-summary .cs-row:last-child { margin-bottom: 0; font-weight: 700; color: var(--accent); font-family: var(--mono); }
  .confirm-actions { display: flex; gap: .6rem; justify-content: flex-end; }

  /* ── Print ── */
  @media print {
    .qr-header .qr-actions, .catalogue-grid, .btn-add-line, .del-btn, .confirm-overlay { display: none !important; }
    .qr-layout { grid-template-columns: 1fr; }
    body { background: #fff; color: #000; }
  }
</style>

<!-- ═══════════════════════ TOAST ═══════════════════════════════════════════ -->
<?php if ($toast): ?>
<div class="qr-toast <?= htmlspecialchars($toast['type']) ?>" id="toastMsg">
  <?php if ($toast['type'] === 'success'): ?>✅<?php elseif ($toast['type'] === 'error'): ?>❌<?php else: ?>ℹ️<?php endif; ?>
  <?= htmlspecialchars($toast['msg']) ?>
</div>
<script>setTimeout(()=>{ const t=document.getElementById('toastMsg'); if(t){t.style.opacity='0';t.style.transition='opacity .4s';setTimeout(()=>t.remove(),400);} }, 4500);</script>
<?php endif; ?>

<!-- ═══════════════════════ PAGE HEADER ═════════════════════════════════════ -->
<div class="qr-header">
  <h1>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg>
    Create Quotation
    <span class="doc-tag">DRAFT</span>
  </h1>
  <div class="qr-actions">
    <a href="quotation_history.php" class="btn btn-ghost">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,18 9,12 15,6"/></svg>
      History
    </a>
    <button type="button" class="btn btn-ghost" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 6,2 18,2 18,9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Preview
    </button>
  </div>
</div>

<!-- ═══════════════════════ MAIN FORM ═══════════════════════════════════════ -->
<form method="POST" id="quotationForm" onsubmit="return validateForm()">
<div class="qr-layout">

  <!-- ── LEFT COLUMN ─────────────────────────────────────────────────────── -->
  <div class="left-col">

    <!-- 1. JOB SELECTION -->
    <div class="qr-card">
      <div class="qr-card-head"><div class="dot"></div> 1 · Select Job / Fault</div>
      <div class="qr-card-body">
        <div class="fault-select-wrap">
          <select id="faultSelector" onchange="location.href='create_quotation.php?fault_id='+this.value.split('|')[0]+'&assign_id='+this.value.split('|')[1]">
            <option value="0|0">— Choose an assigned fault to quote for —</option>
            <?php
            $all_faults_arr = [];
            while ($f = mysqli_fetch_assoc($all_faults)) $all_faults_arr[] = $f;
            foreach ($all_faults_arr as $f):
                $sel = ($f['REP_FAULT_ID'] == $fault_id) ? 'selected' : '';
                $first_line = trim(explode("\n", $f['DESCRIPTION'])[0] ?? 'No description');
                if (preg_match('/FAULT TITLE:\s*([^\n]+)/i', $f['DESCRIPTION'], $m)) $first_line = trim($m[1]);
                $first_line = mb_strimwidth($first_line, 0, 55, '…');
                $stat_icon  = match($f['STATUS']) { 'Completed' => '✓', 'In Progress' => '▶', default => '◉' };
            ?>
            <option value="<?= $f['REP_FAULT_ID'] ?>|<?= $f['ASSIGN_ID'] ?>" <?= $sel ?>>
              <?= $stat_icon ?> [<?= $f['PRIORITY'] ?? '?' ?>] <?= htmlspecialchars($f['COMPANY_NAME'] ?? 'Unknown') ?> — <?= htmlspecialchars($first_line) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($fault): ?>
        <?php
          $fref  = parseFaultField($fault['DESCRIPTION'], 'FAULT REFERENCE');
          $ftit  = parseFaultField($fault['DESCRIPTION'], 'FAULT TITLE');
          $fcat  = parseFaultField($fault['DESCRIPTION'], 'CATEGORY');
          $fequi = parseFaultField($fault['DESCRIPTION'], 'EQUIPMENT TYPE');
          $fbrand= parseFaultField($fault['DESCRIPTION'], 'BRAND/MODEL');
          $fser  = parseFaultField($fault['DESCRIPTION'], 'SERIAL/ASSET NO');
          $fdesc = parseFaultField($fault['DESCRIPTION'], 'DETAILED DESCRIPTION');
          if (!$fdesc) $fdesc = trim($fault['DESCRIPTION']);
          $status_class = match(strtolower($fault['STATUS'] ?? '')) {
              'completed'   => 'completed',
              'in progress' => 'in-progress',
              default       => 'assigned'
          };
        ?>
        <div class="fault-info">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
              <span style="font-size:.95rem;font-weight:600;color:#fff"><?= htmlspecialchars($fault['COMPANY_NAME'] ?? '—') ?></span>
              <span class="status-pill <?= $status_class ?>"><span class="sp-dot"></span><?= htmlspecialchars($fault['STATUS']) ?></span>
              <?php if ($fault['PRIORITY']): ?><span class="priority-badge priority-<?= $fault['PRIORITY'] ?>"><?= $fault['PRIORITY'] ?> Priority</span><?php endif; ?>
            </div>
            <div style="font-family:var(--mono);font-size:.75rem;color:var(--accent)"><?= $fref ?: 'REF-UNKNOWN' ?></div>
          </div>
          <div class="fault-info-grid">
            <?php if ($ftit): ?>
            <div class="fi-row" style="grid-column:1/-1"><div class="fi-label">Fault Title</div><div class="fi-val" style="font-weight:600"><?= htmlspecialchars($ftit) ?></div></div>
            <?php endif; ?>
            <div class="fi-row"><div class="fi-label">Category</div><div class="fi-val"><?= htmlspecialchars($fcat ?: '—') ?></div></div>
            <div class="fi-row"><div class="fi-label">Equipment Type</div><div class="fi-val"><?= htmlspecialchars($fequi ?: '—') ?></div></div>
            <div class="fi-row"><div class="fi-label">Brand / Model</div><div class="fi-val"><?= htmlspecialchars($fbrand ?: '—') ?></div></div>
            <div class="fi-row"><div class="fi-label">Serial / Asset No.</div><div class="fi-val mono"><?= htmlspecialchars($fser ?: '—') ?></div></div>
            <div class="fi-row"><div class="fi-label">Contact Person</div><div class="fi-val"><?= htmlspecialchars($fault['CONTACT_PERSON_NAME'] ?? '—') ?></div></div>
            <div class="fi-row"><div class="fi-label">Report Date</div><div class="fi-val"><?= $fault['REPORT_DATE'] ? date('d M Y', strtotime($fault['REPORT_DATE'])) : '—' ?></div></div>
            <div class="fi-row"><div class="fi-label">Team on Job</div><div class="fi-val"><?= htmlspecialchars($fault['TEAM'] ?? $tech_name) ?></div></div>
            <div class="fi-row"><div class="fi-label">Assign Due</div><div class="fi-val"><?= $fault['ASSIGN_DUE'] ? date('d M Y', strtotime($fault['ASSIGN_DUE'])) : '—' ?></div></div>
            <?php if ($fdesc): ?>
            <div class="fi-row" style="grid-column:1/-1">
              <div class="fi-label">Fault Description</div>
              <div class="fi-val" style="color:var(--muted);line-height:1.55;font-size:.8rem"><?= nl2br(htmlspecialchars(mb_strimwidth($fdesc,0,280,'…'))) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Hidden fields -->
        <input type="hidden" name="fault_id"   value="<?= $fault['REP_FAULT_ID'] ?>">
        <input type="hidden" name="assign_id"  value="<?= $fault['ASSIGN_ID'] ?>">
        <input type="hidden" name="client_id"  value="<?= $fault['CLIENT_ID'] ?>">
        <?php else: ?>
        <div class="empty-state" style="padding:1.5rem">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <p style="font-size:.83rem">Select a fault above to begin building your quotation.</p>
        </div>
        <input type="hidden" name="fault_id"  value="0">
        <input type="hidden" name="assign_id" value="0">
        <input type="hidden" name="client_id" value="0">
        <?php endif; ?>
      </div>
    </div>

    <!-- 2. LABOUR & TRAVEL (quick entry) -->
    <div class="qr-card">
      <div class="qr-card-head"><div class="dot" style="background:var(--green)"></div> 2 · Labour & Travel</div>
      <div class="qr-card-body">
        <div class="labour-grid">
          <div class="field-group">
            <label>Labour Hours</label>
            <div class="prefix-wrap">
              <input type="number" name="labour_hours" id="labourHours" step="0.5" min="0" value="0" placeholder="0" onchange="recalc()">
            </div>
          </div>
          <div class="field-group">
            <label>Hourly Rate (E)</label>
            <div class="prefix-wrap">
              <span>E</span>
              <input type="number" name="labour_rate" id="labourRate" step="0.01" min="0" value="<?= $hourly ?>" onchange="recalc()">
            </div>
          </div>
          <div class="field-group">
            <label>Labour Subtotal</label>
            <div class="prefix-wrap">
              <span>E</span>
              <input type="text" id="labourSubtotal" readonly value="0.00" style="color:var(--green);font-family:var(--mono)">
            </div>
          </div>
          <div class="field-group">
            <label>Travel Distance (km)</label>
            <input type="number" name="travel_km" id="travelKm" step="1" min="0" value="0" placeholder="0" onchange="recalc()">
          </div>
          <div class="field-group">
            <label>Rate per km (E)</label>
            <div class="prefix-wrap">
              <span>E</span>
              <input type="number" name="travel_rate" id="travelRate" step="0.01" min="0" value="3.50" onchange="recalc()">
            </div>
          </div>
          <div class="field-group">
            <label>Travel Subtotal</label>
            <div class="prefix-wrap">
              <span>E</span>
              <input type="text" id="travelSubtotal" readonly value="0.00" style="color:var(--green);font-family:var(--mono)">
            </div>
          </div>
          <div class="field-group">
            <label>Call-Out / Site Fee (E)</label>
            <div class="prefix-wrap">
              <span>E</span>
              <input type="number" name="call_out_fee" id="callOut" step="0.01" min="0" value="0" placeholder="0" onchange="recalc()">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. PARTS & SERVICES (manual lines) -->
    <div class="qr-card">
      <div class="qr-card-head"><div class="dot" style="background:var(--purple)"></div> 3 · Parts & Additional Services</div>
      <div class="qr-card-body">

        <!-- Catalogue quick-add -->
        <div style="margin-bottom:.9rem">
          <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem">Quick-Add from Catalogue</div>
          <div class="catalogue-grid">
            <?php foreach ($catalogue as $ci): ?>
            <div class="cat-pill" onclick="addCatalogueItem(<?= htmlspecialchars(json_encode($ci)) ?>)">
              <?= htmlspecialchars($ci['name']) ?> <span class="cp-price">E<?= number_format($ci['price'],2) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="section-sep"></div>

        <!-- Line items table -->
        <table class="lines-table">
          <thead>
            <tr>
              <th style="width:38%">Description</th>
              <th style="width:14%">Type</th>
              <th style="width:10%">Qty</th>
              <th style="width:15%">Unit Price</th>
              <th style="width:15%">Line Total</th>
              <th style="width:8%"></th>
            </tr>
          </thead>
          <tbody id="lineItems"></tbody>
        </table>
        <button type="button" class="btn btn-add-line" onclick="addLine()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Line Item
        </button>
      </div>
    </div>

    <!-- 4. NOTES -->
    <div class="qr-card">
      <div class="qr-card-head"><div class="dot" style="background:var(--yellow)"></div> 4 · Technician Notes & Recommendations</div>
      <div class="qr-card-body">
        <div class="field-group">
          <label>Internal Notes / Scope of Work</label>
          <textarea name="notes" rows="4" placeholder="Describe the work carried out, parts used, findings, and any follow-up recommendations for the accountant and client…"></textarea>
        </div>
      </div>
    </div>

  </div><!-- end left-col -->

  <!-- ── RIGHT COLUMN (SUMMARY) ──────────────────────────────────────────── -->
  <div class="right-col">
    <div class="summary-sticky">

      <!-- Quotation meta -->
      <div class="qr-card">
        <div class="qr-card-head"><div class="dot" style="background:var(--orange)"></div> Quotation Details</div>
        <div class="qr-card-body">
          <div class="field-group" style="margin-bottom:.75rem">
            <label>Prepared by</label>
            <input type="text" value="<?= htmlspecialchars($tech_name) ?>" readonly style="opacity:.6">
          </div>
          <div class="field-group" style="margin-bottom:.75rem">
            <label>Date Issued</label>
            <input type="text" value="<?= date('d M Y') ?>" readonly style="opacity:.6">
          </div>
          <div class="field-group">
            <label>Valid Until (14 days)</label>
            <input type="text" value="<?= date('d M Y', strtotime('+14 days')) ?>" readonly style="opacity:.6">
          </div>
        </div>
      </div>

      <!-- Totals -->
      <div class="qr-card">
        <div class="qr-card-head"><div class="dot" style="background:var(--accent)"></div> Cost Summary</div>
        <div class="qr-card-body">
          <div class="totals-block">
            <div class="total-row">
              <span class="tr-label">Labour</span>
              <span class="tr-val" id="sumLabour">E 0.00</span>
            </div>
            <div class="total-row">
              <span class="tr-label">Transport</span>
              <span class="tr-val" id="sumTravel">E 0.00</span>
            </div>
            <div class="total-row">
              <span class="tr-label">Call-Out Fee</span>
              <span class="tr-val" id="sumCallout">E 0.00</span>
            </div>
            <div class="total-row">
              <span class="tr-label">Parts & Services</span>
              <span class="tr-val" id="sumParts">E 0.00</span>
            </div>
            <div class="total-row">
              <span class="tr-label">Subtotal</span>
              <span class="tr-val" id="sumSubtotal">E 0.00</span>
            </div>
            <div class="vat-row">
              <label><span class="toggle"><input type="checkbox" id="vatToggle" onchange="recalc()"><span class="toggle-slider"></span></span> Include VAT (15%)</label>
              <span class="tr-val" id="sumVat" style="color:var(--muted)">E 0.00</span>
            </div>
          </div>
          <div class="grand-total">
            <div>
              <div class="gt-label">Grand Total</div>
              <div style="font-size:.7rem;color:var(--muted);margin-top:.15rem" id="vatNote">Excl. VAT</div>
            </div>
            <div class="gt-val" id="grandTotal">E 0.00</div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="qr-card">
        <div class="qr-card-body" style="display:flex;flex-direction:column;gap:.6rem">
          <button type="button" class="btn btn-success" style="width:100%;justify-content:center" onclick="openConfirm('submit')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22,2 11,13"/><polygon points="22,2 15,22 11,13 2,9 22,2"/></svg>
            Submit to Accountant
          </button>
          <button type="button" class="btn btn-ghost" style="width:100%;justify-content:center" onclick="openConfirm('draft')">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
            Save as Draft
          </button>
          <div style="font-size:.73rem;color:var(--muted);text-align:center;margin-top:.25rem">
            Submitting will notify the Accountant immediately.
          </div>
        </div>
      </div>

    </div><!-- end summary-sticky -->
  </div><!-- end right-col -->

</div><!-- end qr-layout -->

<!-- Hidden action field — set by JS before submit -->
<input type="hidden" name="submit_quotation" id="submitAction" value="">

</form>

<!-- ═══════════════════════ CONFIRM OVERLAY ══════════════════════════════════ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3 id="confTitle">Submit Quotation to Accountant?</h3>
    <p id="confDesc">Once submitted, the accountant will be notified and can review, confirm, and convert this into a formal invoice for the client.</p>
    <div class="confirm-summary">
      <div class="cs-row"><span style="color:var(--muted)">Client</span> <span id="confClient"><?= htmlspecialchars($fault['COMPANY_NAME'] ?? '—') ?></span></div>
      <div class="cs-row"><span style="color:var(--muted)">Prepared by</span> <span><?= htmlspecialchars($tech_name) ?></span></div>
      <div class="cs-row"><span style="color:var(--muted)">Date</span> <span><?= date('d M Y') ?></span></div>
      <div class="cs-row"><span>Total</span> <span id="confTotal">E 0.00</span></div>
    </div>
    <div class="confirm-actions">
      <button type="button" class="btn btn-ghost" onclick="closeConfirm()">Cancel</button>
      <button type="button" class="btn btn-success" id="confSubmitBtn" onclick="doSubmit()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg>
        Confirm & Submit
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════ JAVASCRIPT ══════════════════════════════════════ -->
<script>
// ── Line item management ──────────────────────────────────────────────────
let lineCount = 0;
const types   = ['Parts','Service','Labour','Transport','Fee','Other'];

function addLine(desc='', qty=1, price=0, type='Parts') {
  const tbody = document.getElementById('lineItems');
  const idx   = lineCount++;
  const lt    = (qty * price).toFixed(2);
  const typeOpts = types.map(t => `<option value="${t}" ${t===type?'selected':''}>${t}</option>`).join('');

  const tr = document.createElement('tr');
  tr.id = `line-${idx}`;
  tr.innerHTML = `
    <td><input type="text"   name="line_desc[]"  value="${esc(desc)}"   placeholder="Item description…" oninput="recalc()"></td>
    <td><select              name="line_type[]">${typeOpts}</select></td>
    <td><input type="number" name="line_qty[]"   value="${qty}"          step="0.01" min="0" style="width:70px" oninput="calcRowTotal(${idx})"></td>
    <td><input type="number" name="line_price[]" value="${price>0?price:''}" step="0.01" min="0" placeholder="0.00" oninput="calcRowTotal(${idx})"></td>
    <td class="line-total" id="lt-${idx}">E ${lt}</td>
    <td><button type="button" class="del-btn" onclick="delLine(${idx})" title="Remove">✕</button></td>
  `;
  tbody.appendChild(tr);
  recalc();
}

function addCatalogueItem(item) {
  addLine(item.name, 1, item.price, item.type);
}

function calcRowTotal(idx) {
  const row   = document.getElementById(`line-${idx}`);
  if (!row) return 0;
  const qty   = parseFloat(row.querySelector('[name="line_qty[]"]').value)   || 0;
  const price = parseFloat(row.querySelector('[name="line_price[]"]').value) || 0;
  const lt    = qty * price;
  row.querySelector(`#lt-${idx}`).textContent = 'E ' + lt.toFixed(2);
  recalc();
  return lt;
}

function delLine(idx) {
  const row = document.getElementById(`line-${idx}`);
  if (row) row.remove();
  recalc();
}

function esc(str) {
  return String(str).replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

// ── Recalculate totals ────────────────────────────────────────────────────
function recalc() {
  const lh = parseFloat(document.getElementById('labourHours').value) || 0;
  const lr = parseFloat(document.getElementById('labourRate').value)  || 0;
  const tk = parseFloat(document.getElementById('travelKm').value)    || 0;
  const tr = parseFloat(document.getElementById('travelRate').value)  || 0;
  const co = parseFloat(document.getElementById('callOut').value)     || 0;

  const labourAmt   = lh * lr;
  const travelAmt   = tk * tr;

  document.getElementById('labourSubtotal').value = labourAmt.toFixed(2);
  document.getElementById('travelSubtotal').value = travelAmt.toFixed(2);

  // Sum manual lines
  let partsAmt = 0;
  document.querySelectorAll('[name="line_qty[]"]').forEach((inp, i) => {
    const qty   = parseFloat(inp.value) || 0;
    const price = parseFloat(inp.closest('tr').querySelector('[name="line_price[]"]').value) || 0;
    partsAmt += qty * price;
  });

  const subtotal = labourAmt + travelAmt + co + partsAmt;
  const vat      = document.getElementById('vatToggle').checked ? subtotal * 0.15 : 0;
  const grand    = subtotal + vat;

  document.getElementById('sumLabour').textContent   = 'E ' + labourAmt.toFixed(2);
  document.getElementById('sumTravel').textContent   = 'E ' + travelAmt.toFixed(2);
  document.getElementById('sumCallout').textContent  = 'E ' + co.toFixed(2);
  document.getElementById('sumParts').textContent    = 'E ' + partsAmt.toFixed(2);
  document.getElementById('sumSubtotal').textContent = 'E ' + subtotal.toFixed(2);
  document.getElementById('sumVat').textContent      = 'E ' + vat.toFixed(2);
  document.getElementById('grandTotal').textContent  = 'E ' + grand.toFixed(2);
  document.getElementById('vatNote').textContent     = vat > 0 ? 'Incl. 15% VAT' : 'Excl. VAT';
  document.getElementById('confTotal').textContent   = 'E ' + grand.toFixed(2);
}

// ── Confirm overlay ───────────────────────────────────────────────────────
let pendingAction = 'submit';

function openConfirm(action) {
  pendingAction = action;
  const overlay = document.getElementById('confirmOverlay');
  const title   = document.getElementById('confTitle');
  const desc    = document.getElementById('confDesc');
  const btn     = document.getElementById('confSubmitBtn');

  if (action === 'submit') {
    title.textContent = 'Submit Quotation to Accountant?';
    desc.textContent  = 'Once submitted, the accountant will be notified immediately. This action cannot be undone.';
    btn.innerHTML     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg> Confirm & Submit';
    btn.className     = 'btn btn-success';
  } else {
    title.textContent = 'Save as Draft?';
    desc.textContent  = 'Your quotation will be saved as a draft. You can review, edit, and submit it later from your Quotation History.';
    btn.innerHTML     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg> Save Draft';
    btn.className     = 'btn btn-ghost';
  }
  overlay.classList.add('open');
}

function closeConfirm() {
  document.getElementById('confirmOverlay').classList.remove('open');
}

function doSubmit() {
  document.getElementById('submitAction').value = pendingAction;
  closeConfirm();
  document.getElementById('quotationForm').submit();
}

// ── Form validation ───────────────────────────────────────────────────────
function validateForm() {
  const fid = parseInt(document.querySelector('[name="fault_id"]').value) || 0;
  if (!fid) { alert('Please select a fault / job before submitting.'); return false; }

  const lh = parseFloat(document.getElementById('labourHours').value) || 0;
  const tk = parseFloat(document.getElementById('travelKm').value)    || 0;
  const co = parseFloat(document.getElementById('callOut').value)     || 0;
  const lineDescs = document.querySelectorAll('[name="line_desc[]"]');
  let hasLines = lh > 0 || tk > 0 || co > 0;
  lineDescs.forEach(inp => { if (inp.value.trim()) hasLines = true; });

  if (!hasLines) { alert('Add at least one line item (labour, travel, parts, or a fee).'); return false; }
  return true;
}

// Close overlay on backdrop click
document.getElementById('confirmOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeConfirm();
});

// Init
recalc();
</script>

<?php require_once '../../includes/tech_footer.php'; ?>