<?php
// ═══════════════════════════════════════════════════════════════════════
//  client_dashboard.php  —  BUSIQUIP ESWATINI  —  Client Portal
//  Database: busiquip_final
//  Session keys set by client_login.php:
//    $_SESSION['client_id']      → CLIENT.CLIENT_ID
//    $_SESSION['client_name']    → CLIENT.COMPANY_NAME
//    $_SESSION['client_contact'] → CLIENT.CONTACT_PERSON_NAME
//    $_SESSION['client_email']   → CLIENT.COMPANY_EMAIL
//    $_SESSION['client_type']    → CLIENT.CLIENT_TYPE
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ── Guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['client_id'])) {
    header("Location: client_login.php");
    exit;
}

// ── DB Connection ──────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/database.php';
if ($conn->connect_error) {
    die("<div style='font:16px sans-serif;padding:40px;color:red'>
         <strong>Database connection failed:</strong> " . htmlspecialchars($conn->connect_error) .
        "<br><br>Make sure the database <strong>busiquip_final</strong> exists and MySQL is running.</div>");
}
$conn->set_charset('utf8mb4');

// ── Session vars ───────────────────────────────────────────────────────
$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';
$client_email   = $_SESSION['client_email']   ?? '';
$client_type    = $_SESSION['client_type']    ?? 'CORPORATE';

// ── Logout ─────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: client_login.php");
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  AJAX / ACTION HANDLERS  (return JSON, then exit)
// ══════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── helper: safe string ──────────────────────────────────────────
    $esc = fn(string $v): string => $conn->real_escape_string(trim($v));

    // ── get_client_info ──────────────────────────────────────────────
    if ($action === 'get_client_info') {
        $row = $conn->query("SELECT * FROM client WHERE CLIENT_ID = $client_id")->fetch_assoc();
        echo json_encode($row ?: []);
        exit;
    }

    // ── get_faults ───────────────────────────────────────────────────
    if ($action === 'get_faults') {
        $filter = $_GET['filter'] ?? 'all';
        $where  = "rf.CLIENT_ID = $client_id";
        if ($filter === 'Pending')    $where .= " AND rf.STATUS = 'Pending'";
        if ($filter === 'Assigned')   $where .= " AND rf.STATUS = 'Assigned'";
        if ($filter === 'In Progress') $where .= " AND rf.STATUS = 'In Progress'";
        if ($filter === 'Resolved')   $where .= " AND rf.STATUS = 'Resolved'";

        $res = $conn->query("
            SELECT rf.*,
                   f.FAULT_TYPE,
                   f.DEFAULT_PRIORITY,
                   cp.SERIAL_NUM,
                   p.PROD_NAME,
                   a.ASSIGN_ID,
                   a.ASSIGN_DATE,
                   a.DUE_DATE,
                   a.STATUS AS ASSIGN_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME ORDER BY e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS
            FROM reported_fault rf
            LEFT JOIN fault          f  ON f.FAULT_ID       = rf.FAULT_ID
            LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID = rf.CLIENT_PROD_ID
            LEFT JOIN product        p  ON p.PROD_ID         = cp.PROD_ID
            LEFT JOIN assignment     a  ON a.REP_FAULT_ID    = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee       e  ON e.EMP_ID          = at2.EMP_ID
            WHERE $where
            GROUP BY rf.REP_FAULT_ID
            ORDER BY rf.REPORT_DATE DESC
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    // ── get_fault_detail ─────────────────────────────────────────────
    if ($action === 'get_fault_detail') {
        $fid = (int)($_GET['id'] ?? 0);
        $row = $conn->query("
            SELECT rf.*,
                   f.FAULT_TYPE, f.FAULT_DESCRIPTION AS FAULT_DEFAULT_DESC, f.DEFAULT_PRIORITY, f.DEFAULT_SLA_DAYS,
                   cp.SERIAL_NUM, cp.PURCHASE_DATE, cp.WARRANTY_END_DATE,
                   p.PROD_NAME, p.PROD_TYPE,
                   a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE, a.STATUS AS ASSIGN_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS,
                   GROUP_CONCAT(DISTINCT e.EMAIL SEPARATOR ', ') AS TECH_EMAILS
            FROM reported_fault rf
            LEFT JOIN fault          f  ON f.FAULT_ID       = rf.FAULT_ID
            LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID = rf.CLIENT_PROD_ID
            LEFT JOIN product        p  ON p.PROD_ID         = cp.PROD_ID
            LEFT JOIN assignment     a  ON a.REP_FAULT_ID    = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee       e  ON e.EMP_ID          = at2.EMP_ID
            WHERE rf.REP_FAULT_ID = $fid AND rf.CLIENT_ID = $client_id
            GROUP BY rf.REP_FAULT_ID
        ")->fetch_assoc();

        // work logs
        $logs = [];
        if ($row && $row['ASSIGN_ID']) {
            $aid = (int)$row['ASSIGN_ID'];
            $lr  = $conn->query("
                SELECT wl.*, e.FULL_NAME AS EMP_NAME
                FROM work_log wl
                LEFT JOIN employee e ON e.EMP_ID = wl.EMP_ID
                WHERE wl.ASSIGN_ID = $aid
                ORDER BY wl.LOG_DATE DESC
            ");
            if ($lr) while ($l = $lr->fetch_assoc()) $logs[] = $l;
        }

        echo json_encode(['fault' => $row, 'logs' => $logs]);
        exit;
    }

    // ── report_fault ─────────────────────────────────────────────────
    if ($action === 'report_fault') {
        $desc     = $esc($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['Low','Medium','High','Critical']) ? $_POST['priority'] : 'Medium';
        $rep_by   = $esc($_POST['reported_by'] ?? $client_contact);
        $date_raw = $_POST['report_date'] ?? date('Y-m-d H:i:s');
        $rep_date = $esc($date_raw);

        if (!$desc) { echo json_encode(['success'=>false,'error'=>'Description is required.']); exit; }

        $conn->query("
            INSERT INTO reported_fault (CLIENT_ID, REPORT_DATE, STATUS, PRIORITY, REPORTED_BY, DESCRIPTION)
            VALUES ($client_id, '$rep_date', 'Pending', '$priority', '$rep_by', '$desc')
        ");
        $new_id = $conn->insert_id;

        if ($conn->error) {
            echo json_encode(['success'=>false,'error'=>$conn->error]);
        } else {
            echo json_encode(['success'=>true,'message'=>"Fault #$new_id reported successfully. Our team will review it shortly.",'id'=>$new_id]);
        }
        exit;
    }

    // ── get_invoices ─────────────────────────────────────────────────
    if ($action === 'get_invoices') {
        $res = $conn->query("
            SELECT i.*,
                   COALESCE(SUM(p.AMOUNT_PAID),0) AS TOTAL_PAID,
                   COUNT(il.LINE_ID) AS LINE_COUNT
            FROM invoice i
            LEFT JOIN payment      p  ON p.INVOICE_ID = i.INVOICE_ID
            LEFT JOIN invoice_line il ON il.INVOICE_ID = i.INVOICE_ID
            WHERE i.CLIENT_ID = $client_id
            GROUP BY i.INVOICE_ID
            ORDER BY i.INVOICE_DATE DESC
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    // ── get_invoice_detail ───────────────────────────────────────────
    if ($action === 'get_invoice_detail') {
        $iid = (int)($_GET['id'] ?? 0);
        $inv = $conn->query("SELECT * FROM invoice WHERE INVOICE_ID=$iid AND CLIENT_ID=$client_id")->fetch_assoc();
        if (!$inv) { echo json_encode(['error'=>'Not found']); exit; }

        $lines = [];
        $lr = $conn->query("SELECT * FROM invoice_line WHERE INVOICE_ID=$iid");
        if ($lr) while ($l = $lr->fetch_assoc()) $lines[] = $l;

        $payments = [];
        $pr = $conn->query("SELECT * FROM payment WHERE INVOICE_ID=$iid ORDER BY PAYMENT_DATE DESC");
        if ($pr) while ($p = $pr->fetch_assoc()) $payments[] = $p;

        echo json_encode(['invoice'=>$inv,'lines'=>$lines,'payments'=>$payments]);
        exit;
    }

    // ── submit_payment ───────────────────────────────────────────────
    if ($action === 'submit_payment') {
        $iid    = (int)($_POST['invoice_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $method = $esc($_POST['method'] ?? 'Cash');
        $ref    = $esc($_POST['reference'] ?? '');

        if (!$iid || $amount <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid invoice or amount.']); exit; }

        // verify invoice belongs to client
        $inv = $conn->query("SELECT * FROM invoice WHERE INVOICE_ID=$iid AND CLIENT_ID=$client_id")->fetch_assoc();
        if (!$inv) { echo json_encode(['success'=>false,'error'=>'Invoice not found.']); exit; }

        $today = date('Y-m-d');
        $conn->query("
            INSERT INTO payment (INVOICE_ID, PAYMENT_DATE, AMOUNT_PAID, METHOD, REFERENCE_NUMBER, STATUS)
            VALUES ($iid, '$today', $amount, '$method', '$ref', 'Pending')
        ");

        if ($conn->error) {
            echo json_encode(['success'=>false,'error'=>$conn->error]);
        } else {
            // check if fully paid
            $paid = (float)$conn->query("SELECT COALESCE(SUM(AMOUNT_PAID),0) t FROM payment WHERE INVOICE_ID=$iid")->fetch_assoc()['t'];
            $total = (float)($inv['TOTAL'] ?? 0);
            if ($paid >= $total && $total > 0) {
                $conn->query("UPDATE invoice SET STATUS='Paid' WHERE INVOICE_ID=$iid");
            } elseif ($paid > 0) {
                $conn->query("UPDATE invoice SET STATUS='Partial' WHERE INVOICE_ID=$iid");
            }
            echo json_encode(['success'=>true,'message'=>'Payment submitted! Reference: '.($ref ?: 'N/A').'. Status: Pending confirmation.']);
        }
        exit;
    }

    // ── get_payments ─────────────────────────────────────────────────
    if ($action === 'get_payments') {
        $res = $conn->query("
            SELECT p.*, i.INVOICE_DATE, i.TOTAL AS INV_TOTAL, i.TYPE AS INV_TYPE
            FROM payment p
            JOIN invoice i ON i.INVOICE_ID = p.INVOICE_ID
            WHERE i.CLIENT_ID = $client_id
            ORDER BY p.PAYMENT_DATE DESC, p.PAYMENT_ID DESC
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    // ── get_products ─────────────────────────────────────────────────
    if ($action === 'get_products') {
        $res = $conn->query("
            SELECT cp.*, p.PROD_NAME, p.PROD_TYPE, p.PROD_DESCRIPTION
            FROM client_product cp
            JOIN product p ON p.PROD_ID = cp.PROD_ID
            WHERE cp.CLIENT_ID = $client_id
            ORDER BY cp.CLIENT_PROD_ID
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    // ── get_work_logs ────────────────────────────────────────────────
    if ($action === 'get_work_logs') {
        $res = $conn->query("
            SELECT wl.*, e.FULL_NAME AS EMP_NAME, e.ROLE,
                   rf.DESCRIPTION AS FAULT_DESC, rf.STATUS AS FAULT_STATUS
            FROM work_log wl
            JOIN assignment     a  ON a.ASSIGN_ID    = wl.ASSIGN_ID
            JOIN reported_fault rf ON rf.REP_FAULT_ID = a.REP_FAULT_ID
            LEFT JOIN employee  e  ON e.EMP_ID        = wl.EMP_ID
            WHERE rf.CLIENT_ID = $client_id
            ORDER BY wl.LOG_DATE DESC
            LIMIT 30
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    // ── get_technicians ──────────────────────────────────────────────
    if ($action === 'get_technicians') {
        $res = $conn->query("
            SELECT DISTINCT e.EMP_ID, e.FULL_NAME, e.EMAIL, e.ROLE, e.HIRE_DATE,
                   COUNT(DISTINCT at2.ASSIGN_ID) AS JOBS_COUNT
            FROM assignment_technician at2
            JOIN employee   e  ON e.EMP_ID     = at2.EMP_ID
            JOIN assignment a  ON a.ASSIGN_ID  = at2.ASSIGN_ID
            JOIN reported_fault rf ON rf.REP_FAULT_ID = a.REP_FAULT_ID
            WHERE rf.CLIENT_ID = $client_id
            GROUP BY e.EMP_ID
            ORDER BY e.FULL_NAME
        ");
        $rows = [];
        if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Unknown action: '.$action]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  PAGE-LOAD  — server-side stats (no errors if tables are empty)
// ══════════════════════════════════════════════════════════════════════
function dbCount(mysqli $c, string $sql): int {
    $r = $c->query($sql);
    return $r ? (int)$r->fetch_assoc()['n'] : 0;
}

$f_total    = dbCount($conn, "SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id");
$f_pending  = dbCount($conn, "SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS='Pending'");
$f_assigned = dbCount($conn, "SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS='Assigned'");
$f_progress = dbCount($conn, "SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS='In Progress'");
$f_resolved = dbCount($conn, "SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS='Resolved'");

$i_total   = dbCount($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id");
$i_unpaid  = dbCount($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS IN ('Unpaid','Partial','Overdue')");
$i_paid    = dbCount($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Paid'");

$prod_count = dbCount($conn, "SELECT COUNT(*) n FROM client_product WHERE CLIENT_ID=$client_id");
$tech_count = dbCount($conn, "
    SELECT COUNT(DISTINCT at2.EMP_ID) n
    FROM assignment_technician at2
    JOIN assignment a ON a.ASSIGN_ID=at2.ASSIGN_ID
    JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    WHERE rf.CLIENT_ID=$client_id
");

// Total outstanding balance
$balance_r = $conn->query("
    SELECT COALESCE(SUM(i.TOTAL) - COALESCE(SUM(p.AMOUNT_PAID),0), 0) AS bal
    FROM invoice i
    LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID
    WHERE i.CLIENT_ID=$client_id AND i.STATUS != 'Paid'
");
$outstanding = $balance_r ? (float)$balance_r->fetch_assoc()['bal'] : 0;

// Recent 5 faults
$recent_faults_r = $conn->query("
    SELECT rf.REP_FAULT_ID, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE, rf.DESCRIPTION,
           f.FAULT_TYPE,
           p.PROD_NAME,
           a.ASSIGN_DATE,
           GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS
    FROM reported_fault rf
    LEFT JOIN fault          f  ON f.FAULT_ID       = rf.FAULT_ID
    LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID = rf.CLIENT_PROD_ID
    LEFT JOIN product        p  ON p.PROD_ID         = cp.PROD_ID
    LEFT JOIN assignment     a  ON a.REP_FAULT_ID    = rf.REP_FAULT_ID
    LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
    LEFT JOIN employee       e  ON e.EMP_ID          = at2.EMP_ID
    WHERE rf.CLIENT_ID = $client_id
    GROUP BY rf.REP_FAULT_ID
    ORDER BY rf.REPORT_DATE DESC
    LIMIT 5
");

// Recent 5 invoices
$recent_invoices_r = $conn->query("
    SELECT i.INVOICE_ID, i.INVOICE_DATE, i.DUE_DATE, i.STATUS, i.TOTAL, i.TYPE,
           COALESCE(SUM(p.AMOUNT_PAID),0) AS PAID_AMT
    FROM invoice i
    LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID
    WHERE i.CLIENT_ID = $client_id
    GROUP BY i.INVOICE_ID
    ORDER BY i.INVOICE_DATE DESC
    LIMIT 5
");

// Client full record
$client_r = $conn->query("SELECT * FROM client WHERE CLIENT_ID=$client_id");
$client   = $client_r ? $client_r->fetch_assoc() : [];
$c_initial = strtoupper(substr($client['COMPANY_NAME'] ?? $client_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Dashboard — BUSIQUIP ESWATINI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══ DESIGN TOKENS ═══════════════════════════════════════════════ */
:root{
    --burg:#8B0000; --burg2:#C0392B; --burg-g:rgba(139,0,0,.3);
    --gold:#E8B84B; --gold2:#FFD700; --gold-p:rgba(232,184,75,.12);
    --teal:#0D9488; --sky:#0EA5E9; --em:#10B981;
    --warn:#F59E0B; --dan:#EF4444; --ind:#6366F1;
    --bg0:#070C14; --bg1:#0D1421; --bg2:#111B2E; --bg3:#1A2640; --bg4:#243055;
    --sur:rgba(17,27,46,.95); --gl:rgba(255,255,255,.04); --glb:rgba(255,255,255,.07);
    --bor:rgba(232,184,75,.16); --borh:rgba(232,184,75,.4);
    --t1:#EFF4FF; --t2:#8A9CC4; --t3:#445570;
    --r:14px; --rl:22px; --rx:32px;
    --sh:0 8px 32px rgba(0,0,0,.5); --shl:0 20px 60px rgba(0,0,0,.55);
    --blur:blur(18px); --tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif; --fb:'DM Sans',sans-serif; --fm:'JetBrains Mono',monospace;
    --sw:260px; --hh:68px;
}
body.lm{
    --bg0:#F0F4FA;--bg1:#E6EEF8;--bg2:#DDE5F5;--bg3:#fff;
    --sur:rgba(255,255,255,.96);--gl:rgba(0,0,0,.02);
    --bor:rgba(139,0,0,.14);--t1:#0D1421;--t2:#4A5A7A;--t3:#9AAAC4;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;transition:background .4s,color .4s}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}

/* ═══ GRID BG ══════════════════════════════════════════════════════ */
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);
    background-size:48px 48px}
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;opacity:.15;animation:orb 18s ease-in-out infinite}
.o1{width:480px;height:480px;top:-150px;left:-150px;background:radial-gradient(circle,var(--burg),transparent);animation-delay:0s}
.o2{width:380px;height:380px;bottom:-80px;right:-80px;background:radial-gradient(circle,var(--gold),transparent);animation-delay:-6s}
.o3{width:280px;height:280px;top:45%;left:42%;background:radial-gradient(circle,var(--teal),transparent);animation-delay:-12s}
@keyframes orb{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(35px,-55px) scale(1.1)}66%{transform:translate(-25px,35px) scale(.9)}}

/* ═══ TICKER ════════════════════════════════════════════════════════ */
.ticker{position:fixed;top:0;left:0;right:0;height:26px;z-index:2000;
    background:linear-gradient(90deg,var(--burg),#6B0000,var(--burg));overflow:hidden;display:flex;align-items:center}
.ticker-inner{display:flex;gap:70px;white-space:nowrap;animation:tick 28s linear infinite;
    font-family:var(--fm);font-size:10px;letter-spacing:.06em;color:var(--gold2)}
@keyframes tick{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* ═══ HEADER ════════════════════════════════════════════════════════ */
header{position:fixed;top:26px;left:0;right:0;height:var(--hh);z-index:1500;
    background:rgba(7,12,20,.9);backdrop-filter:var(--blur);
    border-bottom:1px solid var(--bor);
    display:flex;align-items:center;padding:0 24px 0 calc(var(--sw)+24px);gap:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.4);transition:var(--tr)}
body.lm header{background:rgba(240,244,250,.93)}
.brand{position:absolute;left:16px;display:flex;align-items:center;gap:10px;text-decoration:none}
.brand-ic{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:20px;
    animation:spin-s 14s linear infinite;box-shadow:0 0 18px var(--burg-g)}
@keyframes spin-s{to{transform:rotate(360deg)}}
.brand-nm{font-family:var(--fh);font-size:20px;font-weight:800;
    background:linear-gradient(135deg,var(--gold2),var(--burg2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.brand-sub{font-size:9px;color:var(--t2);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm)}
.h-search{flex:1;max-width:400px;display:flex;align-items:center;gap:8px;
    background:var(--glb);border:1px solid var(--bor);border-radius:var(--r);padding:0 12px;transition:var(--tr)}
.h-search:focus-within{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.h-search i{color:var(--t3);font-size:13px}
.h-search input{flex:1;background:none;border:none;outline:none;color:var(--t1);
    font-family:var(--fb);font-size:13px;padding:9px 0}
.h-search input::placeholder{color:var(--t3)}
.h-search button{background:linear-gradient(135deg,var(--burg),var(--gold));border:none;color:#fff;
    width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;transition:var(--tr)}
.h-search button:hover{transform:scale(1.1)}
.h-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.h-bal{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);
    padding:6px 14px;border-radius:var(--r);font-family:var(--fm);font-size:12px;color:var(--em);
    display:flex;align-items:center;gap:8px}
.h-bal small{font-size:9px;color:var(--t2);display:block;font-family:var(--fb)}
.h-av{width:40px;height:40px;border-radius:50%;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;
    font-family:var(--fh);font-size:17px;font-weight:800;color:#fff;
    border:2px solid var(--gold);cursor:pointer;transition:var(--tr);position:relative}
.h-av:hover{transform:scale(1.1);box-shadow:0 0 18px var(--burg-g)}
.h-av .dot{position:absolute;bottom:1px;right:1px;width:10px;height:10px;
    background:var(--em);border-radius:50%;border:2px solid var(--bg0)}
.h-nm{line-height:1.3}
.h-nm .n{font-weight:600;font-size:13px}
.h-nm .e{font-size:11px;color:var(--t2)}
.hb{width:38px;height:38px;border-radius:50%;border:1px solid var(--bor);background:var(--gl);
    color:var(--t2);font-size:15px;display:flex;align-items:center;justify-content:center;transition:var(--tr);position:relative}
.hb:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p);box-shadow:0 0 12px var(--gold-p)}
.hb .bdg{position:absolute;top:-4px;right:-4px;background:var(--dan);color:#fff;
    width:17px;height:17px;border-radius:50%;font-size:9px;font-weight:700;
    display:flex;align-items:center;justify-content:center;border:2px solid var(--bg0)}
.hb.lo{border-color:rgba(239,68,68,.3);color:var(--dan)}
.hb.lo:hover{background:rgba(239,68,68,.1);border-color:var(--dan)}

/* ═══ SIDEBAR ═══════════════════════════════════════════════════════ */
.sidebar{position:fixed;top:calc(26px + var(--hh));left:0;width:var(--sw);
    height:calc(100vh - 26px - var(--hh));background:rgba(11,18,33,.97);
    backdrop-filter:var(--blur);border-right:1px solid var(--bor);
    padding:20px 0 80px;overflow-y:auto;z-index:1200;transition:transform .35s ease}
body.lm .sidebar{background:rgba(255,255,255,.97)}
.slbl{padding:10px 20px 5px;font-size:9px;letter-spacing:.16em;text-transform:uppercase;
    color:var(--t3);font-family:var(--fm);font-weight:600}
.ni{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;
    border-radius:10px;color:var(--t2);font-size:13px;font-weight:500;
    cursor:pointer;transition:var(--tr);border:1px solid transparent;position:relative}
.ni:hover,.ni.act{background:var(--gold-p);color:var(--gold);border-color:var(--bor);transform:translateX(3px)}
.ni .ic{width:30px;height:30px;border-radius:8px;background:var(--gl);
    display:flex;align-items:center;justify-content:center;font-size:14px;transition:var(--tr)}
.ni:hover .ic,.ni.act .ic{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 10px var(--burg-g)}
.ni .nb{margin-left:auto;background:var(--dan);color:#fff;font-size:9px;
    padding:2px 6px;border-radius:99px;font-weight:700}
.ni .nb.tl{background:var(--teal)}
.nb-gold{background:var(--burg) !important}
.exp-btn{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;
    border-radius:10px;background:none;border:1px solid var(--borh);
    color:var(--gold);font-size:12px;font-weight:700;width:calc(100% - 16px);transition:var(--tr)}
.exp-btn:hover{background:var(--gold-p)}
.exp-btn .ch{margin-left:auto;transition:transform .3s}
.exp-btn.open .ch{transform:rotate(180deg)}
.sub-menu{max-height:0;overflow:hidden;transition:max-height .4s ease}
.sub-menu.open{max-height:500px}
.si{display:flex;align-items:center;gap:9px;padding:8px 14px 8px 42px;margin:2px 8px;
    border-radius:8px;color:var(--t3);font-size:12px;cursor:pointer;transition:var(--tr)}
.si:hover{color:var(--gold);background:var(--gold-p)}
.s-banner{margin:14px 10px;padding:14px;
    background:linear-gradient(135deg,rgba(139,0,0,.22),rgba(232,184,75,.1));
    border:1px solid var(--borh);border-radius:var(--r);text-align:center}
.s-banner i{font-size:26px;color:var(--gold);margin-bottom:8px;display:block}
.s-banner p{font-size:11px;color:var(--t2);line-height:1.5}
.s-banner a{display:inline-block;margin-top:10px;padding:6px 14px;border-radius:6px;
    background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-size:11px;font-weight:700;transition:var(--tr)}
.s-banner a:hover{transform:scale(1.05)}

/* ═══ MAIN ══════════════════════════════════════════════════════════ */
main{margin-left:var(--sw);padding-top:calc(26px + var(--hh) + 28px);
    padding-bottom:60px;padding-left:28px;padding-right:28px;
    position:relative;z-index:1;min-height:100vh}
@media(max-width:1024px){main{margin-left:0;padding-left:14px;padding-right:14px}
    .sidebar{transform:translateX(-100%)} .sidebar.mo{transform:translateX(0)} header{padding-left:24px} .brand{position:static}}

/* ═══ ALERTS ════════════════════════════════════════════════════════ */
#alerts{position:fixed;top:calc(26px + var(--hh) + 14px);right:18px;z-index:9999;
    display:flex;flex-direction:column;gap:9px;pointer-events:none}
.al{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:var(--r);
    font-size:13px;font-weight:500;pointer-events:all;backdrop-filter:var(--blur);
    box-shadow:var(--sh);min-width:260px;max-width:360px;
    animation:alin .3s ease,alout .4s ease 4.6s forwards}
.al-s{background:rgba(16,185,129,.14);border:1px solid var(--em);color:var(--em)}
.al-e{background:rgba(239,68,68,.14);border:1px solid var(--dan);color:var(--dan)}
.al-i{background:rgba(59,130,246,.14);border:1px solid #3B82F6;color:#3B82F6}
@keyframes alin{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes alout{to{transform:translateX(120%);opacity:0;pointer-events:none}}

/* ═══ HERO ══════════════════════════════════════════════════════════ */
.hero{position:relative;overflow:hidden;
    background:linear-gradient(135deg,rgba(139,0,0,.16),rgba(232,184,75,.07),rgba(13,148,136,.07));
    border:1px solid var(--borh);border-radius:var(--rx);
    padding:40px 44px;margin-bottom:32px;
    display:grid;grid-template-columns:1fr auto;gap:36px;align-items:center;
    animation:sd .65s ease}
@keyframes sd{from{opacity:0;transform:translateY(-22px)}to{opacity:1;transform:translateY(0)}}
.hero::before{content:'';position:absolute;inset:0;
    background:radial-gradient(ellipse at 80% 50%,rgba(232,184,75,.06),transparent 60%);pointer-events:none}
.hero-d1{position:absolute;top:-28px;right:190px;width:110px;height:110px;border-radius:28px;
    background:linear-gradient(135deg,var(--burg),var(--gold));opacity:.08;transform:rotate(18deg);
    animation:fd 8s ease-in-out infinite}
@keyframes fd{0%,100%{transform:rotate(18deg) translateY(0)}50%{transform:rotate(18deg) translateY(-10px)}}
.hero-d2{position:absolute;bottom:-18px;right:100px;width:65px;height:65px;border-radius:16px;
    background:var(--teal);opacity:.1;transform:rotate(-14deg);animation:fd 6s ease-in-out infinite reverse}
.hero-greet{font-family:var(--fh);font-size:12px;font-weight:600;letter-spacing:.1em;
    text-transform:uppercase;color:var(--gold);margin-bottom:9px;display:flex;align-items:center;gap:8px}
.hero-greet::before{content:'';width:22px;height:2px;background:linear-gradient(90deg,var(--burg),var(--gold))}
.hero h1{font-family:var(--fh);font-size:clamp(24px,3.5vw,36px);font-weight:800;line-height:1.15;
    margin-bottom:12px;background:linear-gradient(135deg,#fff,var(--gold2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent}
body.lm .hero h1{background:linear-gradient(135deg,var(--bg0),var(--burg));-webkit-background-clip:text}
.hero p{font-size:14px;color:var(--t2);line-height:1.7;margin-bottom:20px;max-width:500px}
.chips{display:flex;flex-wrap:wrap;gap:9px}
.chip{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border-radius:99px;
    font-size:11px;font-weight:600;border:1px solid;backdrop-filter:var(--blur)}
.cg{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}
.ct{border-color:var(--teal);color:var(--teal);background:rgba(13,148,136,.1)}
.cr{border-color:var(--dan);color:var(--dan);background:rgba(239,68,68,.08)}
.ce{border-color:var(--em);color:var(--em);background:rgba(16,185,129,.08)}
.hero-g{width:180px;height:180px;flex-shrink:0;position:relative;display:flex;align-items:center;justify-content:center}
.h-ring{width:148px;height:148px;border-radius:50%;border:2.5px solid transparent;
    background:conic-gradient(var(--burg),var(--gold),var(--teal),var(--burg)) border-box;
    -webkit-mask:linear-gradient(#fff 0 0) padding-box,linear-gradient(#fff 0 0);
    -webkit-mask-composite:destination-out;mask-composite:exclude;
    animation:rs 9s linear infinite;position:absolute}
@keyframes rs{to{transform:rotate(360deg)}}
.h-ic{width:100px;height:100px;border-radius:50%;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:44px;
    box-shadow:0 0 38px var(--burg-g),0 0 70px rgba(232,184,75,.12);
    animation:pr 3s ease-in-out infinite}
@keyframes pr{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
.h-orb{position:absolute;width:14px;height:14px;border-radius:50%;animation:orb2 4s linear infinite}
.ho1{background:var(--gold);animation-duration:3.2s}
.ho2{background:var(--teal);animation-duration:5.1s;animation-delay:-2s}
@keyframes orb2{0%{transform:rotate(0deg) translateX(82px) rotate(0deg)}100%{transform:rotate(360deg) translateX(82px) rotate(-360deg)}}

/* ═══ STATS ═════════════════════════════════════════════════════════ */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:32px}
.sc{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);
    padding:20px 18px;backdrop-filter:var(--blur);cursor:pointer;position:relative;overflow:hidden;
    transition:var(--tr);animation:pi .45s ease both}
.sc:nth-child(1){animation-delay:.05s}.sc:nth-child(2){animation-delay:.1s}
.sc:nth-child(3){animation-delay:.15s}.sc:nth-child(4){animation-delay:.2s}
.sc:nth-child(5){animation-delay:.25s}.sc:nth-child(6){animation-delay:.3s}
@keyframes pi{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
.sc::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--burg-g),transparent);opacity:0;transition:opacity .3s}
.sc:hover{transform:translateY(-5px);border-color:var(--borh);box-shadow:var(--sh)}
.sc:hover::after{opacity:1}
.si-w{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:12px}
.si-b{background:rgba(139,0,0,.2);color:var(--burg2)}
.si-g{background:rgba(232,184,75,.14);color:var(--gold)}
.si-t{background:rgba(13,148,136,.14);color:var(--teal)}
.si-e{background:rgba(16,185,129,.14);color:var(--em)}
.si-r{background:rgba(244,63,94,.14);color:#F43F5E}
.si-s{background:rgba(14,165,233,.14);color:var(--sky)}
.sn{font-family:var(--fh);font-size:32px;font-weight:800;
    background:linear-gradient(135deg,var(--t1),var(--gold));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:3px}
.sl{font-size:11px;color:var(--t2);letter-spacing:.03em}
.ss{font-size:10px;color:var(--t3);margin-top:5px;font-family:var(--fm)}

/* ═══ FEATURES ══════════════════════════════════════════════════════ */
.feats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px;margin-bottom:32px}
.fc{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);
    padding:26px 22px;backdrop-filter:var(--blur);cursor:pointer;text-align:center;
    position:relative;overflow:hidden;transition:var(--tr);animation:pi .45s ease both}
.fc::before{content:'';position:absolute;top:0;left:-100%;right:100%;height:3px;
    background:linear-gradient(90deg,var(--burg),var(--gold),var(--teal));
    transition:left .4s ease,right .4s ease}
.fc:hover{transform:translateY(-9px);border-color:var(--borh);box-shadow:var(--shl)}
.fc:hover::before{left:0;right:0}
.fe{font-size:40px;display:block;margin-bottom:12px;animation:fl 3s ease-in-out infinite}
@keyframes fl{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
.fc:nth-child(2) .fe{animation-delay:.5s}.fc:nth-child(3) .fe{animation-delay:1s}
.fc:nth-child(4) .fe{animation-delay:1.5s}.fc:nth-child(5) .fe{animation-delay:2s}
.fc:nth-child(6) .fe{animation-delay:2.5s}
.ft{font-family:var(--fh);font-size:16px;font-weight:700;margin-bottom:7px}
.fd{font-size:12px;color:var(--t2);line-height:1.6}
.fb2{display:inline-flex;align-items:center;gap:6px;margin-top:16px;padding:8px 18px;
    background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;border:none;
    border-radius:8px;font-size:12px;font-weight:600;transition:var(--tr)}
.fb2:hover{transform:scale(1.07);box-shadow:0 8px 22px var(--burg-g)}

/* ═══ SECTION ═══════════════════════════════════════════════════════ */
.sh{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.st{font-family:var(--fh);font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px}
.st .dot{width:9px;height:9px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));box-shadow:0 0 7px var(--burg-g)}
.sl2{font-size:12px;color:var(--gold);font-weight:600;display:flex;align-items:center;gap:4px;cursor:pointer;transition:var(--tr)}
.sl2:hover{gap:8px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:32px}
@media(max-width:900px){.two{grid-template-columns:1fr}}

/* ═══ ACTIVITY ══════════════════════════════════════════════════════ */
.acts{display:flex;flex-direction:column;gap:10px}
.ai{display:flex;align-items:center;gap:12px;padding:14px 16px;
    background:var(--sur);border:1px solid var(--bor);border-radius:var(--r);
    cursor:pointer;transition:var(--tr);animation:pi .4s ease both}
.ai:hover{border-color:var(--borh);transform:translateX(5px);box-shadow:4px 0 18px rgba(232,184,75,.07)}
.ai-ic{width:44px;height:44px;border-radius:11px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:17px;
    background:linear-gradient(135deg,rgba(139,0,0,.18),rgba(232,184,75,.13))}
.ai-b{flex:1;min-width:0}
.ai-t{font-weight:600;font-size:13px;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ai-m{font-size:10px;color:var(--t2);display:flex;gap:10px;flex-wrap:wrap}
.ai-m span{display:flex;align-items:center;gap:3px}

/* ═══ BADGES ════════════════════════════════════════════════════════ */
.b{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;
    font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.b-p{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.b-a{background:rgba(99,102,241,.14);color:var(--ind);border:1px solid rgba(99,102,241,.3)}
.b-i{background:rgba(14,165,233,.14);color:var(--sky);border:1px solid rgba(14,165,233,.3)}
.b-r{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-u{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.b-pd{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-ov{background:rgba(244,63,94,.18);color:#F43F5E;border:1px solid rgba(244,63,94,.4)}
.b-pt{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}

/* ═══ PANEL ═════════════════════════════════════════════════════════ */
.pnl{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);backdrop-filter:var(--blur);overflow:hidden}
.pnl-b{padding:16px 20px}
.pnl-b.sc2{max-height:360px;overflow-y:auto}
.pr-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--bor)}
.pr-row:last-child{border:none}
.pr-l{font-size:12px;color:var(--t2)}
.pr-sub{font-size:10px;color:var(--t3);margin-top:2px}
.pr-amt{font-family:var(--fm);font-size:13px;font-weight:600;color:var(--gold)}
.prog-w{height:5px;background:var(--bg3);border-radius:99px;overflow:hidden}
.prog-b{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--burg),var(--gold));transition:width 1.1s ease}

/* ═══ DIVIDER ═══════════════════════════════════════════════════════ */
.div{display:flex;align-items:center;gap:12px;margin:28px 0}
.div::before,.div::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--bor),transparent)}
.div span{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);white-space:nowrap}

/* ═══ MODAL ═════════════════════════════════════════════════════════ */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:3000;
    align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(5px)}
.mo.show{display:flex;animation:fi .2s ease}
@keyframes fi{from{opacity:0}to{opacity:1}}
.mb{background:var(--bg2);border:1px solid var(--borh);border-radius:var(--rx);
    width:100%;max-width:660px;max-height:92vh;overflow-y:auto;
    box-shadow:var(--shl);animation:mu .32s cubic-bezier(.34,1.56,.64,1)}
@keyframes mu{from{transform:translateY(38px) scale(.96);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.mh{padding:22px 26px 18px;border-bottom:1px solid var(--bor);
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;background:var(--bg2);z-index:1}
.mh h2{font-family:var(--fh);font-size:19px;font-weight:800;display:flex;align-items:center;gap:9px}
.mc{width:34px;height:34px;border-radius:50%;border:1px solid var(--bor);background:none;
    color:var(--t2);font-size:17px;display:flex;align-items:center;justify-content:center;transition:var(--tr)}
.mc:hover{border-color:var(--gold);color:var(--gold);transform:rotate(90deg)}
.mbody{padding:22px 26px;display:grid;gap:16px}
.mfoot{padding:16px 26px 22px;border-top:1px solid var(--bor);display:flex;gap:11px;flex-wrap:wrap}
.mtabs{display:flex;gap:3px;padding:14px 26px 0;border-bottom:1px solid var(--bor)}
.mt{padding:9px 16px;background:none;border:none;color:var(--t2);font-size:12px;font-weight:600;
    border-bottom:2px solid transparent;cursor:pointer;transition:var(--tr);margin-bottom:-1px}
.mt.act{color:var(--gold);border-bottom-color:var(--gold)}
.tp{display:none}
.tp.act{display:block;padding-top:18px}

/* ═══ FORM ══════════════════════════════════════════════════════════ */
.fr{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:11px;font-weight:600;color:var(--t2);letter-spacing:.04em;text-transform:uppercase}
.fg input,.fg textarea,.fg select{
    background:var(--bg3);border:1px solid var(--bor);color:var(--t1);
    padding:10px 13px;border-radius:8px;font-family:var(--fb);font-size:13px;transition:var(--tr);outline:none}
.fg input::placeholder,.fg textarea::placeholder{color:var(--t3)}
.fg input:focus,.fg textarea:focus,.fg select:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.fg textarea{min-height:85px;resize:vertical}
.fg select option{background:var(--bg2);color:var(--t1)}

/* ═══ BUTTONS ═══════════════════════════════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:8px;
    font-size:13px;font-weight:600;border:none;cursor:pointer;transition:var(--tr);flex-shrink:0}
.btn-p{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-p:hover{transform:translateY(-2px);box-shadow:0 8px 22px var(--burg-g)}
.btn-s{background:none;border:1px solid var(--borh);color:var(--gold)}
.btn-s:hover{background:var(--gold-p)}
.btn-d{background:var(--dan);color:#fff}
.btn-d:hover{background:#B91C1C;transform:translateY(-2px)}
.btn-e{background:var(--em);color:#fff}
.btn-e:hover{background:#059669;transform:translateY(-2px)}
.btn-sm{padding:6px 13px;font-size:11px}
.btn-full{width:100%;justify-content:center}

/* ═══ TABLE ═════════════════════════════════════════════════════════ */
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:9px 13px;text-align:left;font-size:10px;text-transform:uppercase;
    letter-spacing:.07em;color:var(--t3);border-bottom:1px solid var(--bor);font-weight:600}
.tbl td{padding:11px 13px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tbl tr:hover td{background:var(--gl)}
.tbl tr:last-child td{border-bottom:none}

/* ═══ DETAIL GRID ═══════════════════════════════════════════════════ */
.dg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.di{padding:11px 13px;background:var(--bg3);border:1px solid var(--bor);border-radius:8px}
.di .k{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.di .v{font-size:13px;font-weight:600}
.di.full{grid-column:1/-1}

/* ═══ INFO BOX ══════════════════════════════════════════════════════ */
.ib{padding:12px 14px;border-radius:8px;display:flex;gap:10px;align-items:flex-start;font-size:12px}
.ib i{font-size:16px;flex-shrink:0;margin-top:1px}
.ib-g{background:rgba(232,184,75,.09);border-left:3px solid var(--gold);color:var(--t1)}
.ib-t{background:rgba(13,148,136,.09);border-left:3px solid var(--teal);color:var(--t1)}
.ib-e{background:rgba(16,185,129,.09);border-left:3px solid var(--em);color:var(--t1)}

/* ═══ SPINNER ═══════════════════════════════════════════════════════ */
.spin{width:34px;height:34px;border-radius:50%;border:3px solid var(--bor);
    border-top-color:var(--gold);animation:sp .8s linear infinite;margin:18px auto}
@keyframes sp{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:36px 18px}
.empty i{font-size:36px;color:var(--t3);margin-bottom:10px;display:block}
.empty p{color:var(--t2);font-size:13px}

/* ═══ FOOTER ════════════════════════════════════════════════════════ */
footer{background:var(--bg1);border-top:1px solid var(--bor);padding:22px 28px;
    text-align:center;margin-left:var(--sw);position:relative;z-index:1}
footer p{font-size:11px;color:var(--t3)}
footer strong{color:var(--gold);font-family:var(--fh)}
@media(max-width:768px){
    .hero{grid-template-columns:1fr;padding:24px 20px}.hero-g{display:none}
    .two{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}
    .fr{grid-template-columns:1fr}.dg{grid-template-columns:1fr}
    footer{margin-left:0}.h-bal,.h-nm{display:none}
}
</style>
</head>
<body>

<!-- BG -->
<div class="bg-grid"></div>
<div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>

<!-- TICKER -->
<div class="ticker">
    <div class="ticker-inner">
        <span>⚙️ BUSIQUIP ESWATINI — FAULT MANAGEMENT SYSTEM &nbsp;✦&nbsp; Report · Track · Resolve</span>
        <span>🛡️ Secure Client Portal &nbsp;✦&nbsp; <?php echo htmlspecialchars($client_name); ?> &nbsp;✦&nbsp; <?php echo $client_type; ?></span>
        <span>📊 Total Faults: <?php echo $f_total; ?> &nbsp;✦&nbsp; Invoices Outstanding: <?php echo $i_unpaid; ?> &nbsp;✦&nbsp; Products Registered: <?php echo $prod_count; ?></span>
        <span>⚙️ BUSIQUIP ESWATINI — FAULT MANAGEMENT SYSTEM &nbsp;✦&nbsp; Report · Track · Resolve</span>
        <span>🛡️ Secure Client Portal &nbsp;✦&nbsp; <?php echo htmlspecialchars($client_name); ?> &nbsp;✦&nbsp; <?php echo $client_type; ?></span>
        <span>📊 Total Faults: <?php echo $f_total; ?> &nbsp;✦&nbsp; Invoices Outstanding: <?php echo $i_unpaid; ?> &nbsp;✦&nbsp; Products Registered: <?php echo $prod_count; ?></span>
    </div>
</div>

<!-- HEADER -->
<header>
    <a href="client_dashboard.php" class="brand">
        <div class="brand-ic">⚙️</div>
        <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
    </a>
    <div class="h-search">
        <i class="fas fa-search"></i>
        <input type="text" id="gsearch" placeholder="Search faults, invoices, products…">
        <button onclick="doSearch()"><i class="fas fa-arrow-right"></i></button>
    </div>
    <div class="h-right">
        <?php if($outstanding > 0): ?>
        <div class="h-bal"><small>Outstanding</small><strong>E <?php echo number_format($outstanding,2); ?></strong></div>
        <?php endif; ?>
        <div class="h-nm"><div class="n"><?php echo htmlspecialchars($client_name); ?></div><div class="e"><?php echo htmlspecialchars($client_email); ?></div></div>
        <div class="h-av" onclick="om('m-profile')" title="My Profile">
            <?php echo $c_initial; ?><span class="dot"></span>
        </div>
        <button class="hb" onclick="togTheme()" title="Toggle Theme"><i class="fas fa-palette"></i></button>
        <button class="hb" onclick="om('m-notif')" title="Notifications"><i class="fas fa-bell"></i></button>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
        </form>
    </div>
</header>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="slbl">Main Menu</div>
    <div class="ni act" onclick="scrollTo2('hero')"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</div>
    <div class="ni" onclick="om('m-profile')"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</div>
    <div class="ni" onclick="window.open('report_fault.php','_blank')"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></div>

    <div class="slbl" style="margin-top:8px">Equipment</div>
    <div class="ni" onclick="loadFaults();om('m-faults')"><div class="ic"><i class="fas fa-tools"></i></div> My Faults <span class="nb"><?php echo $f_total; ?></span></div>
    <div class="ni" onclick="loadWorklogs();om('m-repair')"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</div>
    <div class="ni" onclick="loadProducts();om('m-products')"><div class="ic"><i class="fas fa-box-open"></i></div> My Products <span class="nb tl"><?php echo $prod_count; ?></span></div>
    <div class="ni" onclick="loadTechs();om('m-techs')"><div class="ic"><i class="fas fa-user-cog"></i></div> Technicians <span class="nb tl"><?php echo $tech_count; ?></span></div>

    <div class="slbl" style="margin-top:8px">Finance</div>
    <div class="ni" onclick="loadInvoices();om('m-invoices')"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices <?php if($i_unpaid>0): ?><span class="nb"><?php echo $i_unpaid; ?></span><?php endif; ?></div>
    <div class="ni" onclick="loadPaymentInvList();om('m-pay')"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment</div>
    <div class="ni" onclick="loadPayments();om('m-payments')"><div class="ic"><i class="fas fa-history"></i></div> Payment History</div>

    <div class="slbl" style="margin-top:8px">More</div>
    <button class="exp-btn" id="exp-btn" onclick="togMore()">
        <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
    </button>
    <div class="sub-menu" id="sub-menu">
        <div class="si" onclick="om('m-notif')"><i class="fas fa-bell"></i> Notifications</div>
        <div class="si"><i class="fas fa-folder"></i> Documents</div>
        <div class="si"><i class="fas fa-chart-line"></i> Reports</div>
        <div class="si"><i class="fas fa-star"></i> Leave Feedback</div>
        <div class="si"><i class="fas fa-life-ring"></i> Help & Support</div>
        <div class="si" onclick="om('m-settings')"><i class="fas fa-cog"></i> Settings</div>
    </div>

    <div class="s-banner">
        <i class="fas fa-headset"></i>
        <p>Need help? Our support team is available Mon–Fri 8AM–5PM.</p>
        <a href="mailto:support@busiquip.co.sz">Contact Support</a>
    </div>
</aside>

<!-- ALERTS -->
<div id="alerts"></div>

<!-- MAIN -->
<main>

<!-- HERO -->
<section class="hero" id="hero">
    <div class="hero-d1"></div><div class="hero-d2"></div>
    <div>
        <div class="hero-greet"><i class="fas fa-star"></i>Welcome Back</div>
        <h1><?php echo htmlspecialchars($client['CONTACT_PERSON_NAME'] ?: $client_name); ?> 👋</h1>
        <p>Your BUSIQUIP client portal. Report equipment faults, track repair progress, manage invoices and payments — all in one place.</p>
        <div class="chips">
            <span class="chip cg"><i class="fas fa-list"></i> <?php echo $f_total; ?> Total Faults</span>
            <span class="chip ct"><i class="fas fa-spinner"></i> <?php echo $f_assigned + $f_progress; ?> Active</span>
            <span class="chip cr"><i class="fas fa-file-invoice-dollar"></i> <?php echo $i_unpaid; ?> Unpaid</span>
            <span class="chip ce"><i class="fas fa-check-circle"></i> <?php echo $f_resolved; ?> Resolved</span>
        </div>
    </div>
    <div class="hero-g">
        <div class="h-ring"></div>
        <div class="h-ic">⚙️</div>
        <div class="h-orb ho1"></div><div class="h-orb ho2"></div>
    </div>
</section>

<!-- STATS -->
<div class="stats">
    <div class="sc" onclick="loadFaults();om('m-faults')">
        <div class="si-w si-b"><i class="fas fa-list-alt"></i></div>
        <div class="sn"><?php echo $f_total; ?></div>
        <div class="sl">Total Faults</div><div class="ss">All time · click to view</div>
    </div>
    <div class="sc" onclick="loadFaults('Pending');om('m-faults')">
        <div class="si-w si-g"><i class="fas fa-hourglass-half"></i></div>
        <div class="sn"><?php echo $f_pending; ?></div>
        <div class="sl">Pending</div><div class="ss">Awaiting assignment</div>
    </div>
    <div class="sc" onclick="loadFaults('Assigned');om('m-faults')">
        <div class="si-w si-s"><i class="fas fa-user-check"></i></div>
        <div class="sn"><?php echo $f_assigned; ?></div>
        <div class="sl">Assigned</div><div class="ss">Technician allocated</div>
    </div>
    <div class="sc" onclick="loadFaults('Resolved');om('m-faults')">
        <div class="si-w si-e"><i class="fas fa-check-double"></i></div>
        <div class="sn"><?php echo $f_resolved; ?></div>
        <div class="sl">Resolved</div><div class="ss">Completed repairs</div>
    </div>
    <div class="sc" onclick="loadInvoices();om('m-invoices')">
        <div class="si-w si-r"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="sn"><?php echo $i_unpaid; ?></div>
        <div class="sl">Unpaid Invoices</div><div class="ss">Action required</div>
    </div>
    <div class="sc" onclick="loadProducts();om('m-products')">
        <div class="si-w si-t"><i class="fas fa-box-open"></i></div>
        <div class="sn"><?php echo $prod_count; ?></div>
        <div class="sl">Registered Products</div><div class="ss">Under your account</div>
    </div>
</div>

<!-- FEATURE CARDS -->
<div class="feats">
    <div class="fc" onclick="window.open('report_fault.php','_blank')">
        <span class="fe">📋</span><div class="ft">Report Fault</div>
        <div class="fd">Submit equipment faults with priority and description.</div>
        <button class="fb2"><i class="fas fa-plus"></i> Report Now</button>
    </div>
    <div class="fc" onclick="loadFaults();om('m-faults')">
        <span class="fe">🔧</span><div class="ft">My Faults</div>
        <div class="fd">View all faults, statuses and assigned technicians.</div>
        <button class="fb2"><i class="fas fa-eye"></i> View All</button>
    </div>
    <div class="fc" onclick="loadWorklogs();om('m-repair')">
        <span class="fe">🛠️</span><div class="ft">Repair Progress</div>
        <div class="fd">Live work logs and technician updates on your repairs.</div>
        <button class="fb2"><i class="fas fa-chart-line"></i> Track</button>
    </div>
    <div class="fc" onclick="loadInvoices();om('m-invoices')">
        <span class="fe">💰</span><div class="ft">Invoices</div>
        <div class="fd">Access all invoices, due dates and payment status.</div>
        <button class="fb2"><i class="fas fa-receipt"></i> View</button>
    </div>
    <div class="fc" onclick="loadPaymentInvList();om('m-pay')">
        <span class="fe">💳</span><div class="ft">Make Payment</div>
        <div class="fd">Pay outstanding invoices via multiple payment methods.</div>
        <button class="fb2"><i class="fas fa-credit-card"></i> Pay Now</button>
    </div>
    <div class="fc" onclick="loadProducts();om('m-products')">
        <span class="fe">📦</span><div class="ft">My Products</div>
        <div class="fd">View registered equipment, serials and warranty dates.</div>
        <button class="fb2"><i class="fas fa-box-open"></i> View</button>
    </div>
</div>

<div class="div"><span>✦ Recent Activity ✦</span></div>

<!-- TWO COL: FAULTS + INVOICES -->
<div class="two">
    <div>
        <div class="sh">
            <div class="st"><span class="dot"></span> Recent Faults</div>
            <span class="sl2" onclick="loadFaults();om('m-faults')">View all <i class="fas fa-arrow-right"></i></span>
        </div>
        <div class="acts">
        <?php
        $cnt=0;
        if ($recent_faults_r && $recent_faults_r->num_rows > 0):
            while($rf = $recent_faults_r->fetch_assoc()):
                $cnt++;
                $sc_cls = match($rf['STATUS']){
                    'Pending'    =>'b-p','Assigned'=>'b-a',
                    'In Progress'=>'b-i','Resolved'=>'b-r',default=>'b-p'};
                $ic_cls = match($rf['STATUS']){
                    'Pending'=>'fa-hourglass-half','Assigned'=>'fa-user-check',
                    'In Progress'=>'fa-spinner','Resolved'=>'fa-check-circle',default=>'fa-wrench'};
        ?>
        <div class="ai" onclick="loadFaultDetail(<?php echo $rf['REP_FAULT_ID']; ?>)" style="animation-delay:<?php echo $cnt*.07; ?>s">
            <div class="ai-ic"><i class="fas <?php echo $ic_cls; ?>"></i></div>
            <div class="ai-b">
                <div class="ai-t"><?php echo htmlspecialchars(substr($rf['DESCRIPTION']??'No description',0,60)); ?></div>
                <div class="ai-m">
                    <?php if($rf['FAULT_TYPE']): ?><span><i class="fas fa-tag"></i><?php echo htmlspecialchars($rf['FAULT_TYPE']); ?></span><?php endif; ?>
                    <?php if($rf['PROD_NAME']): ?><span><i class="fas fa-box"></i><?php echo htmlspecialchars($rf['PROD_NAME']); ?></span><?php endif; ?>
                    <span><i class="fas fa-calendar"></i><?php echo $rf['REPORT_DATE'] ? date('d M Y',strtotime($rf['REPORT_DATE'])) : 'N/A'; ?></span>
                    <?php if($rf['TECHNICIANS']): ?><span><i class="fas fa-user-cog"></i><?php echo htmlspecialchars($rf['TECHNICIANS']); ?></span><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <span class="b <?php echo $sc_cls; ?>"><?php echo $rf['STATUS']; ?></span>
                <?php if($rf['PRIORITY']): ?>
                <div style="font-size:10px;color:var(--t3);margin-top:3px"><?php echo strtoupper($rf['PRIORITY']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><i class="fas fa-inbox"></i><p>No faults yet.</p>
            <button class="btn btn-p btn-sm" style="margin-top:12px" onclick="window.open('report_fault.php','_blank')"><i class="fas fa-plus"></i> Report First Fault</button>
        </div>
        <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="sh">
            <div class="st"><span class="dot"></span> Recent Invoices</div>
            <span class="sl2" onclick="loadInvoices();om('m-invoices')">View all <i class="fas fa-arrow-right"></i></span>
        </div>
        <div class="pnl">
        <div class="pnl-b">
        <?php
        if ($recent_invoices_r && $recent_invoices_r->num_rows > 0):
            while($iv = $recent_invoices_r->fetch_assoc()):
                $is_cls = match($iv['STATUS']??'Unpaid'){
                    'Paid'=>'b-pd','Partial'=>'b-pt','Overdue'=>'b-ov',default=>'b-u'};
        ?>
        <div class="pr-row">
            <div>
                <div class="pr-l">Invoice #<?php echo $iv['INVOICE_ID']; ?> <small style="color:var(--t3)"><?php echo $iv['TYPE']??'Invoice'; ?></small></div>
                <div class="pr-sub">Due: <?php echo $iv['DUE_DATE']??'N/A'; ?> · Paid: E<?php echo number_format($iv['PAID_AMT'],2); ?></div>
            </div>
            <div style="text-align:right">
                <div class="pr-amt">E <?php echo number_format($iv['TOTAL']??0,2); ?></div>
                <span class="b <?php echo $is_cls; ?>"><?php echo $iv['STATUS']??'Unpaid'; ?></span>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty"><i class="fas fa-receipt"></i><p>No invoices yet.</p></div>
        <?php endif; ?>
        </div></div>

        <!-- Progress bars -->
        <div style="margin-top:22px">
            <div class="sh"><div class="st"><span class="dot"></span> Fault Status</div></div>
            <div class="pnl"><div class="pnl-b">
            <?php
            $bars=[['Pending',$f_pending,'var(--warn)'],['Assigned',$f_assigned,'var(--ind)'],
                   ['In Progress',$f_progress,'var(--sky)'],['Resolved',$f_resolved,'var(--em)']];
            foreach($bars as[$lbl,$val,$clr]):
                $pct=$f_total>0?round($val/$f_total*100):0;
            ?>
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px">
                    <span style="color:var(--t2)"><?php echo $lbl; ?></span>
                    <span style="font-family:var(--fm);color:var(--t1)"><?php echo $val; ?> (<?php echo $pct; ?>%)</span>
                </div>
                <div class="prog-w"><div class="prog-b" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>"></div></div>
            </div>
            <?php endforeach; ?>
            </div></div>
        </div>
    </div>
</div>

<!-- TECHNICIANS STRIP -->
<?php
$techs_strip = $conn->query("
    SELECT DISTINCT e.EMP_ID, e.FULL_NAME, e.EMAIL, e.ROLE,
           COUNT(DISTINCT at2.ASSIGN_ID) AS JOBS
    FROM assignment_technician at2
    JOIN employee e ON e.EMP_ID=at2.EMP_ID
    JOIN assignment a ON a.ASSIGN_ID=at2.ASSIGN_ID
    JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    WHERE rf.CLIENT_ID=$client_id
    GROUP BY e.EMP_ID ORDER BY e.FULL_NAME
");
if ($techs_strip && $techs_strip->num_rows > 0):
?>
<div class="div"><span>✦ Assigned Technicians ✦</span></div>
<div class="feats" style="margin-bottom:32px">
<?php while($t=$techs_strip->fetch_assoc()): ?>
<div class="pnl" style="padding:22px;text-align:center">
    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:22px;font-weight:800;color:#fff;margin:0 auto 12px">
        <?php echo strtoupper(substr($t['FULL_NAME'],0,1)); ?>
    </div>
    <div style="font-family:var(--fh);font-weight:700;font-size:15px;margin-bottom:3px"><?php echo htmlspecialchars($t['FULL_NAME']); ?></div>
    <div style="font-size:11px;color:var(--teal);margin-bottom:6px"><i class="fas fa-user-cog"></i> <?php echo $t['ROLE']; ?></div>
    <div style="font-size:11px;color:var(--t2);margin-bottom:10px"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($t['EMAIL']); ?></div>
    <span class="b b-a"><?php echo $t['JOBS']; ?> job<?php echo $t['JOBS']!=1?'s':''; ?></span>
</div>
<?php endwhile; ?>
</div>
<?php endif; ?>

</main>

<footer>
    <p>&copy; <?php echo date("Y"); ?> <strong>BUSIQUIP</strong> Fault Management System &nbsp;|&nbsp; 🇸🇿 Eswatini &nbsp;|&nbsp; All Rights Reserved</p>
</footer>

<!-- ═══════════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════════════ -->

<!-- REPORT FAULT -->
<div class="mo" id="m-report">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-exclamation-triangle" style="color:var(--warn)"></i>Report New Fault</h2><button class="mc" onclick="cm('m-report')">×</button></div>
    <div class="mbody">
        <div class="ib ib-g"><i class="fas fa-info-circle" style="color:var(--gold)"></i>
            <div>Provide as much detail as possible. Our team will review and assign a technician promptly.</div>
        </div>
        <div class="fg"><label>Fault Description *</label>
            <textarea id="rf-desc" placeholder="Describe the issue in detail — error messages, symptoms, when it started…"></textarea></div>
        <div class="fr">
            <div class="fg"><label>Priority</label>
                <select id="rf-priority"><option value="Medium">Medium</option><option value="Low">Low</option><option value="High">High</option><option value="Critical">Critical</option></select>
            </div>
            <div class="fg"><label>Reported By</label>
                <input type="text" id="rf-by" value="<?php echo htmlspecialchars($client_contact); ?>" placeholder="Contact person name"></div>
        </div>
        <div class="fg"><label>Date & Time of Fault</label>
            <input type="datetime-local" id="rf-date" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
    </div>
    <div class="mfoot">
        <button class="btn btn-p" onclick="submitFault()"><i class="fas fa-paper-plane"></i> Submit Report</button>
        <button class="btn btn-s" onclick="cm('m-report')">Cancel</button>
    </div>
</div>
</div>

<!-- MY FAULTS -->
<div class="mo" id="m-faults">
<div class="mb" style="max-width:780px">
    <div class="mh"><h2><i class="fas fa-tools" style="color:var(--gold)"></i>My Faults</h2><button class="mc" onclick="cm('m-faults')">×</button></div>
    <div class="mtabs">
        <button class="mt act" onclick="stab(this,'ft-all')">All</button>
        <button class="mt" onclick="stab(this,'ft-pending')">Pending</button>
        <button class="mt" onclick="stab(this,'ft-assigned')">Assigned</button>
        <button class="mt" onclick="stab(this,'ft-prog')">In Progress</button>
        <button class="mt" onclick="stab(this,'ft-res')">Resolved</button>
    </div>
    <div style="padding:0 26px 22px">
        <div id="ft-all" class="tp act"></div>
        <div id="ft-pending" class="tp"></div>
        <div id="ft-assigned" class="tp"></div>
        <div id="ft-prog" class="tp"></div>
        <div id="ft-res" class="tp"></div>
    </div>
    <div class="mfoot">
        <button class="btn btn-p" onclick="cm('m-faults');window.open('report_fault.php','_blank')"><i class="fas fa-plus"></i> Report New Fault</button>
    </div>
</div>
</div>

<!-- FAULT DETAIL -->
<div class="mo" id="m-fdetail">
<div class="mb" style="max-width:720px">
    <div class="mh"><h2 id="fd-title"><i class="fas fa-wrench" style="color:var(--gold)"></i>Fault Details</h2><button class="mc" onclick="cm('m-fdetail')">×</button></div>
    <div id="fd-body" class="mbody"></div>
    <div id="fd-foot" class="mfoot"></div>
</div>
</div>

<!-- REPAIR PROGRESS -->
<div class="mo" id="m-repair">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-wrench" style="color:var(--teal)"></i>Repair Progress</h2><button class="mc" onclick="cm('m-repair')">×</button></div>
    <div id="m-repair-body" class="mbody"><div class="spin"></div></div>
</div>
</div>

<!-- MY PRODUCTS -->
<div class="mo" id="m-products">
<div class="mb" style="max-width:720px">
    <div class="mh"><h2><i class="fas fa-box-open" style="color:var(--sky)"></i>My Products</h2><button class="mc" onclick="cm('m-products')">×</button></div>
    <div id="m-prod-body" class="mbody"><div class="spin"></div></div>
</div>
</div>

<!-- TECHNICIANS -->
<div class="mo" id="m-techs">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-user-cog" style="color:var(--teal)"></i>Assigned Technicians</h2><button class="mc" onclick="cm('m-techs')">×</button></div>
    <div id="m-techs-body" class="mbody"><div class="spin"></div></div>
</div>
</div>

<!-- INVOICES -->
<div class="mo" id="m-invoices">
<div class="mb" style="max-width:760px">
    <div class="mh"><h2><i class="fas fa-receipt" style="color:var(--gold)"></i>Invoices</h2><button class="mc" onclick="cm('m-invoices')">×</button></div>
    <div class="mtabs">
        <button class="mt act" onclick="stab(this,'iv-all')">All</button>
        <button class="mt" onclick="stab(this,'iv-unpaid')">Unpaid</button>
        <button class="mt" onclick="stab(this,'iv-paid')">Paid</button>
    </div>
    <div style="padding:0 26px 22px">
        <div id="iv-all" class="tp act"></div>
        <div id="iv-unpaid" class="tp"></div>
        <div id="iv-paid" class="tp"></div>
    </div>
    <div class="mfoot">
        <button class="btn btn-p" onclick="cm('m-invoices');loadPaymentInvList();om('m-pay')"><i class="fas fa-credit-card"></i> Make Payment</button>
    </div>
</div>
</div>

<!-- INVOICE DETAIL -->
<div class="mo" id="m-invdetail">
<div class="mb" style="max-width:680px">
    <div class="mh"><h2><i class="fas fa-receipt" style="color:var(--gold)"></i>Invoice Detail</h2><button class="mc" onclick="cm('m-invdetail')">×</button></div>
    <div id="id-body" class="mbody"><div class="spin"></div></div>
    <div id="id-foot" class="mfoot"></div>
</div>
</div>

<!-- MAKE PAYMENT -->
<div class="mo" id="m-pay">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-credit-card" style="color:var(--em)"></i>Make Payment</h2><button class="mc" onclick="cm('m-pay')">×</button></div>
    <div class="mbody">
        <div class="ib ib-e"><i class="fas fa-shield-alt" style="color:var(--em)"></i>
            <div>Select an invoice below to pay. Payments are submitted for confirmation by our accounts team.</div>
        </div>
        <div class="fg"><label>Select Invoice to Pay</label>
            <div id="pay-inv-list"><div class="spin"></div></div>
        </div>
        <div id="pay-fields" style="display:none">
            <div class="fr" style="margin-top:4px">
                <div class="fg"><label>Amount (E)</label>
                    <input type="number" id="pay-amt" placeholder="0.00" min="1" step="0.01"></div>
                <div class="fg"><label>Payment Method</label>
                    <select id="pay-meth">
                        <option value="Card Transfer">Card Transfer</option>
                        <option value="Mobile Money">Mobile Money (MTN / Eswatini Mobile)</option>
                        <option value="Bank Transfer">Bank Transfer (EFT)</option>
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
            </div>
            <div class="fg"><label>Reference / Receipt Number (optional)</label>
                <input type="text" id="pay-ref" placeholder="Bank reference or receipt number"></div>
        </div>
    </div>
    <div class="mfoot">
        <button class="btn btn-e" onclick="submitPayment()"><i class="fas fa-paper-plane"></i> Submit Payment</button>
        <button class="btn btn-s" onclick="cm('m-pay')">Cancel</button>
    </div>
</div>
</div>

<!-- PAYMENT HISTORY -->
<div class="mo" id="m-payments">
<div class="mb" style="max-width:720px">
    <div class="mh"><h2><i class="fas fa-history" style="color:var(--sky)"></i>Payment History</h2><button class="mc" onclick="cm('m-payments')">×</button></div>
    <div id="m-pay-body" class="mbody"><div class="spin"></div></div>
</div>
</div>

<!-- PROFILE -->
<div class="mo" id="m-profile">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-user-circle" style="color:var(--gold)"></i>My Profile</h2><button class="mc" onclick="cm('m-profile')">×</button></div>
    <div class="mbody">
        <div style="text-align:center;margin-bottom:16px">
            <div style="width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:30px;font-weight:800;color:#fff;margin:0 auto 10px;box-shadow:0 0 28px var(--burg-g)"><?php echo $c_initial; ?></div>
            <div style="font-family:var(--fh);font-size:17px;font-weight:700"><?php echo htmlspecialchars($client_name); ?></div>
            <div style="font-size:12px;color:var(--teal);margin-top:3px"><?php echo htmlspecialchars($client_type); ?></div>
        </div>
        <div class="dg">
            <div class="di"><div class="k">Company Name</div><div class="v"><?php echo htmlspecialchars($client['COMPANY_NAME']??''); ?></div></div>
            <div class="di"><div class="k">Client Type</div><div class="v"><?php echo htmlspecialchars($client['CLIENT_TYPE']??''); ?></div></div>
            <div class="di"><div class="k">Contact Person</div><div class="v"><?php echo htmlspecialchars($client['CONTACT_PERSON_NAME']??''); ?></div></div>
            <div class="di"><div class="k">Phone</div><div class="v"><?php echo htmlspecialchars($client['COMPANY_PHONE']??'N/A'); ?></div></div>
            <div class="di full"><div class="k">Email</div><div class="v"><?php echo htmlspecialchars($client['COMPANY_EMAIL']??''); ?></div></div>
            <div class="di full"><div class="k">Address</div><div class="v"><?php echo htmlspecialchars($client['COMPANY_ADDRESS']??'N/A'); ?></div></div>
            <div class="di"><div class="k">Client ID</div><div class="v" style="font-family:var(--fm)">#<?php echo $client_id; ?></div></div>
            <div class="di"><div class="k">Username</div><div class="v" style="font-family:var(--fm)"><?php echo htmlspecialchars($client['USERNAME']??''); ?></div></div>
        </div>
    </div>
    <div class="mfoot">
        <button class="btn btn-s btn-full" onclick="cm('m-profile')">Close</button>
    </div>
</div>
</div>

<!-- NOTIFICATIONS -->
<div class="mo" id="m-notif">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-bell" style="color:var(--gold)"></i>Notifications</h2><button class="mc" onclick="cm('m-notif')">×</button></div>
    <div class="mbody">
        <div class="ib ib-t"><i class="fas fa-info-circle" style="color:var(--teal)"></i>
            <div>System notifications about your faults, invoices and assignments will appear here.</div>
        </div>
        <div class="empty"><i class="fas fa-bell-slash"></i><p>No notifications at this time.</p></div>
    </div>
</div>
</div>

<!-- SETTINGS -->
<div class="mo" id="m-settings">
<div class="mb">
    <div class="mh"><h2><i class="fas fa-cog" style="color:var(--t2)"></i>Settings</h2><button class="mc" onclick="cm('m-settings')">×</button></div>
    <div class="mbody">
        <div class="fg"><label>Color Theme</label>
            <select id="theme-sel" onchange="setTheme(this.value)">
                <option value="dark">Dark (Default)</option>
                <option value="light">Light</option>
            </select>
        </div>
        <div class="ib ib-g"><i class="fas fa-info-circle" style="color:var(--gold)"></i>
            <div>Theme preference is saved locally in your browser.</div>
        </div>
    </div>
    <div class="mfoot"><button class="btn btn-s btn-full" onclick="cm('m-settings')">Close</button></div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════════════ -->
<script>
/* ── THEME ─────────────────────────────────────────────────────────── */
let curTheme = localStorage.getItem('bq-theme')||'dark';
function setTheme(t){document.body.className=t==='light'?'lm':'';localStorage.setItem('bq-theme',t);curTheme=t;const s=document.getElementById('theme-sel');if(s)s.value=t;}
function togTheme(){setTheme(curTheme==='dark'?'light':'dark');}
setTheme(curTheme);

/* ── MODALS ─────────────────────────────────────────────────────────── */
function om(id){document.getElementById(id).classList.add('show');}
function cm(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.mo').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('show');}));

/* ── ALERTS ─────────────────────────────────────────────────────────── */
function alert2(type,msg){
    const b=document.getElementById('alerts');
    const el=document.createElement('div');
    el.className='al al-'+type;
    const ic={s:'check-circle',e:'exclamation-circle',i:'info-circle'}[type]||'info-circle';
    el.innerHTML=`<i class="fas fa-${ic}"></i><span>${msg}</span>`;
    b.appendChild(el);
    setTimeout(()=>el.remove(),5200);
}

/* ── TABS ───────────────────────────────────────────────────────────── */
function stab(btn,pid){
    const box=btn.closest('.mb');
    box.querySelectorAll('.mt').forEach(t=>t.classList.remove('act'));
    box.querySelectorAll('.tp').forEach(p=>p.classList.remove('act'));
    btn.classList.add('act');
    document.getElementById(pid)?.classList.add('act');
}

/* ── SIDEBAR ────────────────────────────────────────────────────────── */
function togMore(){
    const b=document.getElementById('exp-btn');
    const m=document.getElementById('sub-menu');
    b.classList.toggle('open');m.classList.toggle('open');
}
function scrollTo2(id){document.getElementById(id)?.scrollIntoView({behavior:'smooth'});}

/* ── SEARCH ─────────────────────────────────────────────────────────── */
function doSearch(){const q=document.getElementById('gsearch').value.trim();if(q)window.location.href='search.php?q='+encodeURIComponent(q);}
document.getElementById('gsearch').addEventListener('keydown',e=>{if(e.key==='Enter')doSearch();});

/* ── HELPERS ────────────────────────────────────────────────────────── */
function h(s){const d=document.createElement('div');d.textContent=s??'';return d.innerHTML;}
function fd(s){return s?new Date(s).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'N/A';}
function cur(v){return 'E '+parseFloat(v||0).toFixed(2);}
function statBadge(s){
    const m={Pending:'b-p',Assigned:'b-a','In Progress':'b-i',Resolved:'b-r',Completed:'b-r'};
    return `<span class="b ${m[s]||'b-p'}">${h(s)}</span>`;
}
function invBadge(s){
    const m={Paid:'b-pd',Partial:'b-pt',Overdue:'b-ov'};
    return `<span class="b ${m[s]||'b-u'}">${h(s||'Unpaid')}</span>`;
}
function payBadge(s){
    const m={Confirmed:'b-r',Pending:'b-p',Rejected:'b-u'};
    return `<span class="b ${m[s]||'b-p'}">${h(s||'Pending')}</span>`;
}

/* ── FAULTS ─────────────────────────────────────────────────────────── */
let allFaults=[];
async function loadFaults(filter=''){
    ['ft-all','ft-pending','ft-assigned','ft-prog','ft-res'].forEach(id=>{
        const el=document.getElementById(id);if(el)el.innerHTML='<div class="spin"></div>';
    });
    const res=await fetch('?action=get_faults&filter='+encodeURIComponent(filter));
    allFaults=await res.json();
    renderFaultPane('ft-all',allFaults);
    renderFaultPane('ft-pending',allFaults.filter(f=>f.STATUS==='Pending'));
    renderFaultPane('ft-assigned',allFaults.filter(f=>f.STATUS==='Assigned'));
    renderFaultPane('ft-prog',allFaults.filter(f=>f.STATUS==='In Progress'));
    renderFaultPane('ft-res',allFaults.filter(f=>f.STATUS==='Resolved'||f.STATUS==='Completed'));
}
function renderFaultPane(pid,faults){
    const el=document.getElementById(pid);if(!el)return;
    if(!faults.length){el.innerHTML='<div class="empty"><i class="fas fa-inbox"></i><p>No faults here.</p></div>';return;}
    el.innerHTML=faults.map(f=>`
        <div class="ai" onclick="loadFaultDetail(${f.REP_FAULT_ID})" style="margin-bottom:9px">
            <div class="ai-ic"><i class="fas fa-wrench"></i></div>
            <div class="ai-b">
                <div class="ai-t">${h(f.DESCRIPTION?.substring(0,65)||'No description')}</div>
                <div class="ai-m">
                    ${f.FAULT_TYPE?`<span><i class="fas fa-tag"></i>${h(f.FAULT_TYPE)}</span>`:''}
                    ${f.PROD_NAME?`<span><i class="fas fa-box"></i>${h(f.PROD_NAME)}</span>`:''}
                    <span><i class="fas fa-calendar"></i>${fd(f.REPORT_DATE)}</span>
                    ${f.TECHNICIANS?`<span><i class="fas fa-user-cog"></i>${h(f.TECHNICIANS)}</span>`:''}
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                ${statBadge(f.STATUS)}
                ${f.PRIORITY?`<div style="font-size:10px;color:var(--t3);margin-top:3px">${h(f.PRIORITY)}</div>`:''}
            </div>
        </div>
    `).join('');
}

async function loadFaultDetail(id){
    document.getElementById('fd-title').innerHTML='<i class="fas fa-wrench" style="color:var(--gold)"></i>Loading…';
    document.getElementById('fd-body').innerHTML='<div class="spin"></div>';
    document.getElementById('fd-foot').innerHTML='';
    om('m-fdetail');
    const res=await fetch(`?action=get_fault_detail&id=${id}`);
    const data=await res.json();
    if(data.error){document.getElementById('fd-body').innerHTML=`<p style="color:var(--dan)">${h(data.error)}</p>`;return;}
    const f=data.fault;
    const logs=data.logs||[];
    document.getElementById('fd-title').innerHTML=`<i class="fas fa-wrench" style="color:var(--gold)"></i>Fault #${f.REP_FAULT_ID}`;
    document.getElementById('fd-body').innerHTML=`
        <div class="dg">
            <div class="di"><div class="k">Status</div><div class="v">${statBadge(f.STATUS)}</div></div>
            <div class="di"><div class="k">Priority</div><div class="v">${h(f.PRIORITY||'N/A')}</div></div>
            <div class="di"><div class="k">Report Date</div><div class="v">${fd(f.REPORT_DATE)}</div></div>
            <div class="di"><div class="k">Reported By</div><div class="v">${h(f.REPORTED_BY||'N/A')}</div></div>
            ${f.FAULT_TYPE?`<div class="di"><div class="k">Fault Type</div><div class="v">${h(f.FAULT_TYPE)}</div></div>`:''}
            ${f.PROD_NAME?`<div class="di"><div class="k">Product</div><div class="v">${h(f.PROD_NAME)}</div></div>`:''}
            ${f.SERIAL_NUM?`<div class="di"><div class="k">Serial No.</div><div class="v" style="font-family:var(--fm)">${h(f.SERIAL_NUM)}</div></div>`:''}
            ${f.TECHNICIANS?`<div class="di full"><div class="k">Assigned Technician(s)</div><div class="v">${h(f.TECHNICIANS)}</div></div>`:''}
            ${f.ASSIGN_DATE?`<div class="di"><div class="k">Assigned Date</div><div class="v">${fd(f.ASSIGN_DATE)}</div></div>`:''}
            ${f.DUE_DATE?`<div class="di"><div class="k">Due Date</div><div class="v">${fd(f.DUE_DATE)}</div></div>`:''}
            <div class="di full"><div class="k">Description</div><div class="v" style="font-weight:400;font-size:12px;line-height:1.6">${h(f.DESCRIPTION||'N/A')}</div></div>
        </div>
        ${logs.length?`
        <div style="margin-top:16px">
            <div style="font-family:var(--fh);font-size:13px;font-weight:700;margin-bottom:10px;color:var(--gold)"><i class="fas fa-clipboard-list"></i> Work Log</div>
            ${logs.map(l=>`
            <div style="padding:10px 13px;background:var(--bg3);border:1px solid var(--bor);border-radius:8px;margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-weight:600;font-size:12px">${h(l.EMP_NAME||'Technician')} <span style="color:var(--t3)">(${h(l.LOG_TYPE||'')})</span></span>
                    <span style="font-size:10px;color:var(--t3);font-family:var(--fm)">${fd(l.LOG_DATE)}</span>
                </div>
                <div style="font-size:12px;color:var(--t2)">${h(l.ACTION_TAKEN||'')}</div>
                ${l.HOURS_SPENT?`<div style="font-size:10px;color:var(--t3);margin-top:3px">${l.HOURS_SPENT}h logged</div>`:''}
            </div>`).join('')}
        </div>`:''}
    `;
    document.getElementById('fd-foot').innerHTML=`
        <button class="btn btn-s" onclick="cm('m-fdetail');loadFaults();om('m-faults')"><i class="fas fa-arrow-left"></i> Back to Faults</button>
    `;
}

/* ── REPAIR / WORKLOGS ──────────────────────────────────────────────── */
async function loadWorklogs(){
    document.getElementById('m-repair-body').innerHTML='<div class="spin"></div>';
    const res=await fetch('?action=get_work_logs');
    const logs=await res.json();
    const el=document.getElementById('m-repair-body');
    if(!logs.length){el.innerHTML='<div class="empty"><i class="fas fa-tools"></i><p>No repair activity yet.</p></div>';return;}
    el.innerHTML=`<div style="display:grid;gap:10px">${logs.map(l=>`
        <div style="padding:13px 15px;background:var(--bg3);border:1px solid var(--bor);border-radius:10px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <span style="font-weight:600;font-size:13px">${h(l.EMP_NAME||'Technician')} <span style="color:var(--teal);font-size:11px">(${h(l.ROLE||'')})</span></span>
                <span style="font-family:var(--fm);font-size:10px;color:var(--t3)">${fd(l.LOG_DATE)}</span>
            </div>
            <div style="font-size:12px;color:var(--t2);margin-bottom:4px">${h(l.ACTION_TAKEN||'No notes')}</div>
            <div style="display:flex;gap:10px">
                <span class="b b-a">${h(l.LOG_TYPE||'Update')}</span>
                ${l.HOURS_SPENT?`<span class="b b-i">${l.HOURS_SPENT}h</span>`:''}
                ${statBadge(l.FAULT_STATUS||'Unknown')}
            </div>
        </div>`).join('')}</div>`;
}

/* ── PRODUCTS ───────────────────────────────────────────────────────── */
async function loadProducts(){
    document.getElementById('m-prod-body').innerHTML='<div class="spin"></div>';
    const res=await fetch('?action=get_products');
    const prods=await res.json();
    const el=document.getElementById('m-prod-body');
    if(!prods.length){el.innerHTML='<div class="empty"><i class="fas fa-box-open"></i><p>No registered products. Contact support to register your equipment.</p></div>';return;}
    el.innerHTML=`<table class="tbl">
        <thead><tr><th>Product</th><th>Type</th><th>Serial #</th><th>Purchase Date</th><th>Warranty End</th></tr></thead>
        <tbody>${prods.map(p=>`<tr>
            <td style="font-weight:600">${h(p.PROD_NAME||'N/A')}</td>
            <td style="color:var(--t2)">${h(p.PROD_TYPE||'N/A')}</td>
            <td style="font-family:var(--fm);color:var(--gold)">${h(p.SERIAL_NUM||'N/A')}</td>
            <td>${fd(p.PURCHASE_DATE)}</td>
            <td>${p.WARRANTY_END_DATE&&new Date(p.WARRANTY_END_DATE)>new Date()?
                `<span style="color:var(--em)">${fd(p.WARRANTY_END_DATE)}</span>`:
                `<span style="color:var(--dan)">${fd(p.WARRANTY_END_DATE)||'Expired'}</span>`}
            </td>
        </tr>`).join('')}</tbody></table>`;
}

/* ── TECHNICIANS ────────────────────────────────────────────────────── */
async function loadTechs(){
    document.getElementById('m-techs-body').innerHTML='<div class="spin"></div>';
    const res=await fetch('?action=get_technicians');
    const techs=await res.json();
    const el=document.getElementById('m-techs-body');
    if(!techs.length){el.innerHTML='<div class="empty"><i class="fas fa-user-cog"></i><p>No technicians assigned yet.</p></div>';return;}
    el.innerHTML=`<div style="display:grid;gap:12px">${techs.map(t=>`
        <div style="display:flex;align-items:center;gap:14px;padding:14px;background:var(--bg3);border:1px solid var(--bor);border-radius:10px">
            <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:20px;font-weight:800;color:#fff;flex-shrink:0">
                ${h(t.FULL_NAME.charAt(0).toUpperCase())}
            </div>
            <div style="flex:1">
                <div style="font-weight:700;font-size:14px">${h(t.FULL_NAME)}</div>
                <div style="font-size:12px;color:var(--teal)">${h(t.ROLE)}</div>
                <div style="font-size:11px;color:var(--t2)">${h(t.EMAIL||'')}</div>
            </div>
            <span class="b b-a">${t.JOBS_COUNT} job${t.JOBS_COUNT!=1?'s':''}</span>
        </div>`).join('')}</div>`;
}

/* ── INVOICES ───────────────────────────────────────────────────────── */
let allInvoices=[];
async function loadInvoices(){
    ['iv-all','iv-unpaid','iv-paid'].forEach(id=>{const el=document.getElementById(id);if(el)el.innerHTML='<div class="spin"></div>';});
    const res=await fetch('?action=get_invoices');
    allInvoices=await res.json();
    renderInvPane('iv-all',allInvoices);
    renderInvPane('iv-unpaid',allInvoices.filter(i=>i.STATUS!=='Paid'));
    renderInvPane('iv-paid',allInvoices.filter(i=>i.STATUS==='Paid'));
}
function renderInvPane(pid,invs){
    const el=document.getElementById(pid);if(!el)return;
    if(!invs.length){el.innerHTML='<div class="empty"><i class="fas fa-receipt"></i><p>No invoices here.</p></div>';return;}
    el.innerHTML=`<table class="tbl"><thead><tr><th>Invoice #</th><th>Type</th><th>Total</th><th>Paid</th><th>Status</th><th>Due</th><th></th></tr></thead>
    <tbody>${invs.map(i=>`<tr>
        <td style="font-family:var(--fm);color:var(--gold)">#${i.INVOICE_ID}</td>
        <td style="color:var(--t2)">${h(i.TYPE||'Invoice')}</td>
        <td style="font-weight:700">${cur(i.TOTAL)}</td>
        <td style="color:var(--em)">${cur(i.TOTAL_PAID)}</td>
        <td>${invBadge(i.STATUS)}</td>
        <td style="color:var(--t2)">${fd(i.DUE_DATE)}</td>
        <td><button class="btn btn-p btn-sm" onclick="loadInvDetail(${i.INVOICE_ID})"><i class="fas fa-eye"></i></button></td>
    </tr>`).join('')}</tbody></table>`;
}

async function loadInvDetail(id){
    document.getElementById('id-body').innerHTML='<div class="spin"></div>';
    document.getElementById('id-foot').innerHTML='';
    om('m-invdetail');
    const res=await fetch(`?action=get_invoice_detail&id=${id}`);
    const data=await res.json();
    if(data.error){document.getElementById('id-body').innerHTML=`<p style="color:var(--dan)">${h(data.error)}</p>`;return;}
    const {invoice:iv,lines,payments}=data;
    document.getElementById('id-body').innerHTML=`
        <div class="dg">
            <div class="di"><div class="k">Invoice #</div><div class="v" style="font-family:var(--fm)">${iv.INVOICE_ID}</div></div>
            <div class="di"><div class="k">Type</div><div class="v">${h(iv.TYPE||'Invoice')}</div></div>
            <div class="di"><div class="k">Status</div><div class="v">${invBadge(iv.STATUS)}</div></div>
            <div class="di"><div class="k">Total</div><div class="v" style="color:var(--gold)">${cur(iv.TOTAL)}</div></div>
            <div class="di"><div class="k">Invoice Date</div><div class="v">${fd(iv.INVOICE_DATE)}</div></div>
            <div class="di"><div class="k">Due Date</div><div class="v">${fd(iv.DUE_DATE)}</div></div>
        </div>
        ${lines.length?`
        <div style="margin-top:14px">
            <div style="font-family:var(--fh);font-size:13px;font-weight:700;margin-bottom:8px;color:var(--gold)"><i class="fas fa-list"></i> Line Items</div>
            <table class="tbl"><thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>${lines.map(l=>`<tr>
                <td>${h(l.DESCRIPTION||'')}</td>
                <td>${l.QUANTITY||0}</td>
                <td>${cur(l.UNIT_PRICE)}</td>
                <td style="color:var(--gold)">${cur(l.LINE_TOTAL)}</td>
            </tr>`).join('')}</tbody></table>
        </div>`:''}
        ${payments.length?`
        <div style="margin-top:14px">
            <div style="font-family:var(--fh);font-size:13px;font-weight:700;margin-bottom:8px;color:var(--em)"><i class="fas fa-money-bill-wave"></i> Payments</div>
            <table class="tbl"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Ref.</th><th>Status</th></tr></thead>
            <tbody>${payments.map(p=>`<tr>
                <td>${fd(p.PAYMENT_DATE)}</td>
                <td style="color:var(--em);font-weight:700">${cur(p.AMOUNT_PAID)}</td>
                <td>${h(p.METHOD||'N/A')}</td>
                <td style="font-family:var(--fm);font-size:11px">${h(p.REFERENCE_NUMBER||'—')}</td>
                <td>${payBadge(p.STATUS)}</td>
            </tr>`).join('')}</tbody></table>
        </div>`:''}
    `;
    document.getElementById('id-foot').innerHTML=`
        <button class="btn btn-s" onclick="cm('m-invdetail')"><i class="fas fa-arrow-left"></i> Back</button>
        ${iv.STATUS!=='Paid'?`<button class="btn btn-e" onclick="cm('m-invdetail');preselectInvoice(${iv.INVOICE_ID},${iv.TOTAL});loadPaymentInvList();om('m-pay')"><i class="fas fa-credit-card"></i> Pay This Invoice</button>`:''}
    `;
}

/* ── PAYMENTS ───────────────────────────────────────────────────────── */
let selInvId=null;
async function loadPaymentInvList(){
    selInvId=null;
    document.getElementById('pay-fields').style.display='none';
    document.getElementById('pay-inv-list').innerHTML='<div class="spin"></div>';
    const res=await fetch('?action=get_invoices');
    const invs=await res.json();
    const unpaid=invs.filter(i=>i.STATUS!=='Paid');
    const el=document.getElementById('pay-inv-list');
    if(!unpaid.length){
        el.innerHTML='<div class="empty"><i class="fas fa-check-circle" style="color:var(--em)"></i><p>All invoices are paid! 🎉</p></div>';
        return;
    }
    el.innerHTML=unpaid.map(i=>`
        <div class="ai" id="pinv-${i.INVOICE_ID}" onclick="selInv(${i.INVOICE_ID},${parseFloat(i.TOTAL||0)})" style="margin-bottom:8px;border:2px solid var(--bor)">
            <div class="ai-ic"><i class="fas fa-receipt"></i></div>
            <div class="ai-b">
                <div class="ai-t">Invoice #${i.INVOICE_ID} <span style="font-family:var(--fm);color:var(--gold)">${cur(i.TOTAL)}</span></div>
                <div class="ai-m"><span>Due: ${fd(i.DUE_DATE)}</span><span>${invBadge(i.STATUS)}</span></div>
            </div>
            <i class="fas fa-circle" style="color:var(--bor);margin-left:8px"></i>
        </div>`).join('');
}

function selInv(id,amt){
    selInvId=id;
    document.querySelectorAll('[id^="pinv-"]').forEach(c=>{c.style.borderColor='var(--bor)';c.querySelector('.fa-circle').style.color='var(--bor)';});
    const card=document.getElementById('pinv-'+id);
    if(card){card.style.borderColor='var(--gold)';card.querySelector('.fa-circle').style.color='var(--gold)';}
    document.getElementById('pay-amt').value=amt.toFixed(2);
    document.getElementById('pay-fields').style.display='block';
}

function preselectInvoice(id,total){selInvId=id;document.getElementById('pay-amt').value=parseFloat(total||0).toFixed(2);}

async function submitPayment(){
    if(!selInvId){alert2('e','Please select an invoice first.');return;}
    const amt=parseFloat(document.getElementById('pay-amt').value);
    const meth=document.getElementById('pay-meth').value;
    const ref=document.getElementById('pay-ref').value;
    if(!amt||amt<=0){alert2('e','Please enter a valid amount.');return;}
    const fd2=new FormData();
    fd2.append('invoice_id',selInvId);fd2.append('amount',amt);fd2.append('method',meth);fd2.append('reference',ref);
    const res=await fetch('?action=submit_payment',{method:'POST',body:fd2});
    const result=await res.json();
    if(result.success){alert2('s',result.message);cm('m-pay');setTimeout(()=>location.reload(),2400);}
    else alert2('e',result.error||'Payment failed.');
}

async function loadPayments(){
    document.getElementById('m-pay-body').innerHTML='<div class="spin"></div>';
    const res=await fetch('?action=get_payments');
    const pays=await res.json();
    const el=document.getElementById('m-pay-body');
    if(!pays.length){el.innerHTML='<div class="empty"><i class="fas fa-history"></i><p>No payments yet.</p></div>';return;}
    el.innerHTML=`<table class="tbl"><thead><tr><th>Invoice</th><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr></thead>
    <tbody>${pays.map(p=>`<tr>
        <td style="font-family:var(--fm);color:var(--gold)">#${p.INVOICE_ID}</td>
        <td>${fd(p.PAYMENT_DATE)}</td>
        <td style="font-weight:700;color:var(--em)">${cur(p.AMOUNT_PAID)}</td>
        <td>${h(p.METHOD||'N/A')}</td>
        <td style="font-family:var(--fm);font-size:11px">${h(p.REFERENCE_NUMBER||'—')}</td>
        <td>${payBadge(p.STATUS)}</td>
    </tr>`).join('')}</tbody></table>`;
}

/* ── REPORT FAULT ───────────────────────────────────────────────────── */
async function submitFault(){
    const desc=document.getElementById('rf-desc').value.trim();
    if(!desc){alert2('e','Please enter a fault description.');return;}
    const fd3=new FormData();
    fd3.append('description',desc);
    fd3.append('priority',document.getElementById('rf-priority').value);
    fd3.append('reported_by',document.getElementById('rf-by').value);
    fd3.append('report_date',document.getElementById('rf-date').value.replace('T',' ')+':00');
    const res=await fetch('?action=report_fault',{method:'POST',body:fd3});
    const result=await res.json();
    if(result.success){
        alert2('s',result.message);cm('m-report');
        document.getElementById('rf-desc').value='';
        setTimeout(()=>location.reload(),2500);
    }else alert2('e',result.error||'Failed to submit.');
}

/* ── ANIMATE PROGRESS BARS ON LOAD ─────────────────────────────────── */
document.addEventListener('DOMContentLoaded',()=>{
    setTimeout(()=>{
        document.querySelectorAll('.prog-b').forEach(b=>{
            const w=b.style.width;b.style.width='0';
            setTimeout(()=>{b.style.width=w;},80);
        });
    },400);
});
</script>
</body>
</html>
