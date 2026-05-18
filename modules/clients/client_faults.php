<?php
session_start();
if (!isset($_SESSION['client_id'])) {
    header('Location: ../../index.php');
    exit();
}

$client_id   = $_SESSION['client_id'];
$client_name = $_SESSION['company_name'] ?? 'Client';

require_once __DIR__ . '/../../config/database.php';

// ─── Handle AJAX actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action   = $_POST['action'];
    $fault_id = intval($_POST['fault_id'] ?? 0);

    if ($action === 'approve_completion') {
        // 1. Update reported_fault status
        $stmt = $pdo->prepare("UPDATE reported_fault SET STATUS = 'Client Approved' WHERE REP_FAULT_ID = ? AND CLIENT_ID = ?");
        $stmt->execute([$fault_id, $client_id]);

        // 2. Upsert client_confirmations
        $stmt2 = $pdo->prepare("
            INSERT INTO client_confirmations (fault_id, client_id, confirmation_status, confirmed_at)
            VALUES (?, ?, 'Confirmed', NOW())
            ON DUPLICATE KEY UPDATE confirmation_status='Confirmed', confirmed_at=NOW()
        ");
        $stmt2->execute([$fault_id, $client_id]);

        // 3. Notify accountants
        $accountants = $pdo->query("SELECT EMP_ID FROM employee WHERE ROLE = 'Accountant'")->fetchAll(PDO::FETCH_COLUMN);
        $notifyStmt  = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message)
            VALUES (?, 'Employee', 'Fault Approved – Ready for Invoice', ?)
        ");
        foreach ($accountants as $accId) {
            $notifyStmt->execute([$accId, "Client approved fault #$fault_id. Please generate the invoice."]);
        }

        echo json_encode(['success' => true, 'message' => 'Fault approved. Accountant notified.']);
        exit();
    }

    if ($action === 'reject_completion') {
        $reason = trim($_POST['reason'] ?? '');
        if (!$reason) {
            echo json_encode(['success' => false, 'message' => 'Please provide a rejection reason.']);
            exit();
        }

        // 1. Update status
        $stmt = $pdo->prepare("UPDATE reported_fault SET STATUS = 'Rework Required' WHERE REP_FAULT_ID = ? AND CLIENT_ID = ?");
        $stmt->execute([$fault_id, $client_id]);

        // 2. Upsert client_confirmations
        $stmt2 = $pdo->prepare("
            INSERT INTO client_confirmations (fault_id, client_id, confirmation_status, confirmation_notes, confirmed_at)
            VALUES (?, ?, 'Rejected', ?, NOW())
            ON DUPLICATE KEY UPDATE confirmation_status='Rejected', confirmation_notes=?, confirmed_at=NOW()
        ");
        $stmt2->execute([$fault_id, $client_id, $reason, $reason]);

        // 3. Log rejection
        $stmt3 = $pdo->prepare("INSERT INTO fault_rejections (fault_id, client_id, rejection_reason) VALUES (?,?,?)");
        $stmt3->execute([$fault_id, $client_id, $reason]);

        // 4. Notify assigned technician(s)
        $techStmt = $pdo->prepare("
            SELECT at.EMP_ID FROM assignment_technician at
            JOIN assignment a ON a.ASSIGN_ID = at.ASSIGN_ID
            WHERE a.REP_FAULT_ID = ?
        ");
        $techStmt->execute([$fault_id]);
        $techs = $techStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifyStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message)
            VALUES (?, 'Employee', 'Rework Required', ?)
        ");
        foreach ($techs as $techId) {
            $notifyStmt->execute([$techId, "Client rejected fault #$fault_id. Reason: $reason"]);
        }

        // 5. Also notify admin
        $pdo->prepare("
            INSERT INTO notifications (user_id, user_type, title, message)
            VALUES (1, 'Admin', 'Fault Rejected by Client', ?)
        ")->execute(["Client rejected fault #$fault_id. Reason: $reason"]);

        echo json_encode(['success' => true, 'message' => 'Rejection submitted. Technician notified.']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit();
}

// ─── Fetch all faults for this client ─────────────────────────────────────────
$faultsSql = "
SELECT
    rf.REP_FAULT_ID,
    rf.REPORT_DATE,
    rf.STATUS,
    rf.PRIORITY,
    rf.REPORTED_BY,
    rf.DESCRIPTION,
    f.FAULT_TYPE,
    f.FAULT_DESCRIPTION AS FAULT_CATEGORY_DESC,
    a.ASSIGN_ID,
    a.ASSIGN_DATE,
    a.DUE_DATE,
    a.STATUS AS ASSIGN_STATUS,
    GROUP_CONCAT(DISTINCT e.FULL_NAME ORDER BY e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS,
    -- Latest work log
    (SELECT wl.ACTION_TAKEN FROM work_log wl WHERE wl.ASSIGN_ID = a.ASSIGN_ID ORDER BY wl.LOG_DATE DESC LIMIT 1) AS LATEST_ACTIVITY,
    (SELECT wl.LOG_DATE   FROM work_log wl WHERE wl.ASSIGN_ID = a.ASSIGN_ID ORDER BY wl.LOG_DATE DESC LIMIT 1) AS LATEST_ACTIVITY_DATE,
    (SELECT wl.LOG_TYPE   FROM work_log wl WHERE wl.ASSIGN_ID = a.ASSIGN_ID ORDER BY wl.LOG_DATE DESC LIMIT 1) AS LATEST_LOG_TYPE,
    -- Progress
    (SELECT COUNT(*) FROM work_log wl WHERE wl.ASSIGN_ID = a.ASSIGN_ID) AS LOG_COUNT,
    -- Client confirmation
    cc.confirmation_status AS CONFIRM_STATUS,
    cc.confirmation_notes  AS CONFIRM_NOTES
FROM reported_fault rf
LEFT JOIN fault f ON f.FAULT_ID = rf.FAULT_ID
LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
LEFT JOIN client_confirmations cc ON cc.fault_id = rf.REP_FAULT_ID AND cc.client_id = rf.CLIENT_ID
WHERE rf.CLIENT_ID = ?
GROUP BY rf.REP_FAULT_ID, a.ASSIGN_ID, cc.confirmation_status, cc.confirmation_notes
ORDER BY rf.REPORT_DATE DESC
";
$faultsStmt = $pdo->prepare($faultsSql);
$faultsStmt->execute([$client_id]);
$faults = $faultsStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Helper: compute progress % from status ───────────────────────────────────
function progressFromStatus(string $status): int {
    return match($status) {
        'Pending'        => 5,
        'Assigned'       => 20,
        'In Progress'    => 55,
        'Completed'      => 85,
        'Client Approved'=> 100,
        'Rework Required'=> 40,
        'Closed'         => 100,
        default          => 10,
    };
}

function statusColor(string $status): string {
    return match($status) {
        'Pending'        => '#f59e0b',
        'Assigned'       => '#3b82f6',
        'In Progress'    => '#8b5cf6',
        'Completed'      => '#10b981',
        'Client Approved'=> '#059669',
        'Rework Required'=> '#ef4444',
        'Closed'         => '#6b7280',
        default          => '#64748b',
    };
}

function priorityColor(string $priority): string {
    return match(strtolower($priority)) {
        'high'   => '#ef4444',
        'medium' => '#f59e0b',
        'low'    => '#10b981',
        'urgent' => '#dc2626',
        default  => '#64748b',
    };
}

// Fetch invoices/quotations per assignment
function getQuotations(PDO $pdo, ?int $assignId): array {
    if (!$assignId) return [];
    $stmt = $pdo->prepare("
        SELECT i.INVOICE_ID, i.INVOICE_DATE, i.DUE_DATE, i.STATUS, i.TYPE, i.TOTAL, i.PAID_AMOUNT,
               il.DESCRIPTION, il.QUANTITY, il.UNIT_PRICE, il.LINE_TOTAL
        FROM invoice i
        LEFT JOIN invoice_line il ON il.INVOICE_ID = i.INVOICE_ID
        WHERE i.ASSIGN_ID = ?
        ORDER BY i.INVOICE_ID DESC, il.LINE_ID
    ");
    $stmt->execute([$assignId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch payments (with receipt) for a given invoice
function getPaymentsForInvoice(PDO $pdo, int $invoiceId): array {
    $stmt = $pdo->prepare("
        SELECT p.PAYMENT_ID, p.PAYMENT_DATE, p.AMOUNT_PAID, p.METHOD,
               p.REFERENCE_NUMBER, p.STATUS,
               r.RECEIPT_ID, r.RECEIPT_DATE, r.RECEIPT_DATA
        FROM payment p
        LEFT JOIN receipt r ON r.PAYMENT_ID = p.PAYMENT_ID
        WHERE p.INVOICE_ID = ?
        ORDER BY p.PAYMENT_DATE DESC
    ");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch work log timeline per assignment
function getWorkLog(PDO $pdo, ?int $assignId): array {
    if (!$assignId) return [];
    $stmt = $pdo->prepare("
        SELECT wl.LOG_DATE, wl.LOG_TYPE, wl.ACTION_TAKEN, wl.HOURS_SPENT, e.FULL_NAME
        FROM work_log wl
        LEFT JOIN employee e ON e.EMP_ID = wl.EMP_ID
        WHERE wl.ASSIGN_ID = ?
        ORDER BY wl.LOG_DATE ASC
    ");
    $stmt->execute([$assignId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Unread notification count for this client
$notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND user_type = 'Client' AND is_read = 0");
$notifStmt->execute([$client_id]);
$unreadCount = $notifStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Faults — BUSIQUIP Eswatini</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══ PORTAL THEME TOKENS ════════════════════════════════════════════════ */
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
    --sh:0 8px 32px rgba(0,0,0,.5);
    --blur:blur(18px); --tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif; --fb:'DM Sans',sans-serif; --fm:'JetBrains Mono',monospace;
    --sw:260px; --hh:70px;
    /* Keep faults page vars intact */
    --surface:#161b22; --surface2:#1c2230; --border:#30363d; --border2:#21262d;
    --text:#EFF4FF; --muted:#8A9CC4; --accent:#1d6fa4; --accent2:#E8B84B;
    --green:#3fb950; --red:#f85149; --orange:#d29922; --purple:#bc8cff;
    --font-head:'Syne',sans-serif; --font-body:'DM Sans',sans-serif;
    --radius:12px; --radius-sm:6px; --shadow:0 4px 24px rgba(0,0,0,.4); --shadow-lg:0 8px 48px rgba(0,0,0,.6);
    --transition:.2s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;font-size:15px;line-height:1.6}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}
.portal-header{position:fixed;top:0;left:0;right:0;height:var(--hh);z-index:1500;
    background:rgba(7,12,20,.95);backdrop-filter:var(--blur);
    border-bottom:1px solid var(--bor);
    display:flex;align-items:center;padding:0 24px 0 calc(var(--sw)+24px);gap:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.4)}
.brand{display:flex;align-items:center;gap:10px;position:absolute;left:16px}
.brand-ic{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:18px}
.brand-nm{font-family:var(--fh);font-size:20px;font-weight:800;
    background:linear-gradient(135deg,var(--gold2),var(--burg2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.brand-sub{font-size:10px;color:var(--t2);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm)}
.h-page-title{flex:1;font-family:var(--fh);font-size:17px;font-weight:700;color:var(--t1)}
.h-page-title span{color:var(--t3);font-size:13px;font-weight:400;margin-left:8px;font-family:var(--fb)}
.h-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.hb{width:35px;height:35px;border-radius:50%;border:1px solid var(--bor);background:var(--gl);
    color:var(--t2);font-size:14px;display:flex;align-items:center;justify-content:center;transition:var(--tr);cursor:pointer;position:relative}
.hb:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}
.hb.lo{border-color:rgba(239,68,68,.3);color:var(--dan)}
.hb.lo:hover{background:rgba(239,68,68,.1);border-color:var(--dan)}
.h-av{width:35px;height:35px;border-radius:50%;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;
    font-family:var(--fh);font-size:14px;font-weight:800;color:#fff;
    border:2px solid var(--gold);cursor:pointer;transition:var(--tr)}
.h-av:hover{transform:scale(1.08)}
.h-nm{line-height:1.3}
.h-nm .n{font-weight:600;font-size:14px}
.h-nm .e{font-size:12px;color:var(--t2)}
.sidebar{position:fixed;top:var(--hh);left:0;width:var(--sw);
    height:calc(100vh - var(--hh));background:rgba(11,18,33,.97);
    backdrop-filter:var(--blur);border-right:1px solid var(--bor);
    padding:16px 0 80px;overflow-y:auto;z-index:1200;transition:transform .35s ease}
.slbl{padding:10px 20px 5px;font-size:11px;letter-spacing:.16em;text-transform:uppercase;
    color:var(--t3);font-family:var(--fm);font-weight:600}
.ni{display:flex;align-items:center;gap:11px;padding:11px 18px;margin:2px 8px;
    border-radius:10px;color:var(--t2);font-size:14px;font-weight:500;
    cursor:pointer;transition:var(--tr);border:1px solid transparent;text-decoration:none}
.ni:hover,.ni.act{background:var(--gold-p);color:var(--gold);border-color:var(--bor);transform:translateX(3px)}
.ni .ic{width:28px;height:28px;border-radius:8px;background:var(--gl);
    display:flex;align-items:center;justify-content:center;font-size:13px;transition:var(--tr);flex-shrink:0}
.ni:hover .ic,.ni.act .ic{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 10px var(--burg-g)}
.ni .nb{margin-left:auto;background:var(--dan);color:#fff;font-size:10px;
    padding:2px 6px;border-radius:99px;font-weight:700}
.ni .nb.tl{background:var(--teal)}
.nb-gold{background:var(--burg) !important}
.exp-btn{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;
    border-radius:10px;background:none;border:1px solid var(--borh);
    color:var(--gold);font-size:13px;font-weight:700;width:calc(100% - 16px);transition:var(--tr);cursor:pointer}
.exp-btn:hover{background:var(--gold-p)}
.exp-btn .ch{margin-left:auto;transition:transform .3s}
.exp-btn.open .ch{transform:rotate(180deg)}
.sub-menu{max-height:0;overflow:hidden;transition:max-height .4s ease}
.sub-menu.open{max-height:500px}
.si{display:flex;align-items:center;gap:9px;padding:8px 14px 8px 42px;margin:2px 8px;
    border-radius:8px;color:var(--t3);font-size:13px;cursor:pointer;transition:var(--tr);text-decoration:none}
.si:hover{color:var(--gold);background:var(--gold-p)}
.s-banner{margin:14px 10px;padding:12px;
    background:linear-gradient(135deg,rgba(139,0,0,.22),rgba(232,184,75,.1));
    border:1px solid var(--borh);border-radius:var(--r);text-align:center}
.s-banner i{font-size:22px;color:var(--gold);margin-bottom:6px;display:block}
.s-banner p{font-size:12px;color:var(--t2);line-height:1.5}
.s-banner a{display:inline-block;margin-top:8px;padding:5px 12px;border-radius:6px;
    background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-size:12px;font-weight:700}
.portal-main{margin-left:var(--sw);padding-top:calc(var(--hh) + 24px);
    padding-bottom:60px;padding-left:28px;padding-right:28px;
    position:relative;z-index:1;min-height:100vh}
@media(max-width:1024px){
    .portal-main{margin-left:0;padding-left:14px;padding-right:14px}
    .sidebar{transform:translateX(-100%)}
    .sidebar.mob-open{transform:translateX(0)}
    .portal-header{padding-left:60px}
    .mob-menu-btn{display:flex !important}
}
.mob-menu-btn{display:none;background:none;border:none;color:var(--t2);font-size:18px;cursor:pointer;margin-right:8px}
@media(max-width:768px){.h-nm{display:none}}
/* ═══ PAGE-SPECIFIC ═══ */
/* ── TOPBAR ────────────────────────────────────────── */
.topbar {
    height: 60px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    position: sticky;
    top: 0;
    z-index: 50;
}
.topbar-title {
    font-family: var(--font-head);
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
}
.topbar-right { display: flex; align-items: center; gap: 14px; }
.notif-btn {
    position: relative;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    font-size: 16px;
    transition: color var(--transition);
}
.notif-btn:hover { color: var(--text); }
.notif-badge {
    position: absolute;
    top: -4px; right: -6px;
    background: var(--red);
    color: #fff;
    font-size: 9px;
    width: 16px; height: 16px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700;
}

/* ── PAGE BODY ─────────────────────────────────────── */
.page-body { padding: 28px; flex: 1; }

/* ── HEADER SECTION ────────────────────────────────── */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.page-header h1 {
    font-family: var(--font-head);
    font-size: 26px;
    font-weight: 800;
    color: var(--text);
}
.page-header p { color: var(--muted); font-size: 13px; margin-top: 2px; }

/* ── STATS ROW ─────────────────────────────────────── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px,1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    transition: border-color var(--transition);
}
.stat-card:hover { border-color: var(--accent2); }
.stat-val {
    font-family: var(--font-head);
    font-size: 28px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; }

/* ── FILTER BAR ─────────────────────────────────────── */
.filter-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-bar input, .filter-bar select {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    padding: 8px 12px;
    font-family: var(--font-body);
    font-size: 13px;
    outline: none;
    transition: border-color var(--transition);
}
.filter-bar input:focus, .filter-bar select:focus { border-color: var(--accent2); }
.filter-bar input { flex: 1; min-width: 200px; }
.filter-bar select option { background: var(--surface2); }

/* ── FAULT CARDS ────────────────────────────────────── */
.faults-grid { display: flex; flex-direction: column; gap: 16px; }

.fault-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: border-color var(--transition), box-shadow var(--transition);
    animation: slideIn .35s ease both;
}
@keyframes slideIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fault-card:hover { border-color: #3d444d; box-shadow: var(--shadow); }

/* card header */
.fc-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    background: var(--surface2);
    border-bottom: 1px solid var(--border2);
    cursor: pointer;
    user-select: none;
}
.fc-ref {
    font-family: var(--font-head);
    font-weight: 700;
    font-size: 15px;
    color: var(--accent2);
    white-space: nowrap;
}
.fc-title { flex: 1; font-size: 14px; color: var(--text); font-weight: 500; }
.fc-title small { display: block; color: var(--muted); font-size: 12px; font-weight: 400; margin-top: 1px; }
.fc-badges { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .3px;
    white-space: nowrap;
}
.badge-outline {
    background: transparent;
    border: 1px solid currentColor;
}
.fc-chevron { color: var(--muted); transition: transform var(--transition); font-size: 13px; }
.fc-header.open .fc-chevron { transform: rotate(180deg); }

/* card body */
.fc-body { display: none; padding: 20px; }
.fc-body.open { display: block; animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* ── GRID INSIDE CARD ──────────────────────────────── */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.info-item label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--muted);
    margin-bottom: 4px;
}
.info-item span { color: var(--text); font-size: 13.5px; }

/* ── PROGRESS BAR ──────────────────────────────────── */
.progress-section { margin-bottom: 20px; }
.progress-header { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px; color: var(--muted); }
.progress-track {
    height: 6px;
    background: var(--border);
    border-radius: 99px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--accent2) 100%);
    transition: width 1s cubic-bezier(.4,0,.2,1);
}

/* ── TABS ──────────────────────────────────────────── */
.tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin-bottom: 20px; }
.tab-btn {
    padding: 8px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--muted);
    cursor: pointer;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 500;
    transition: all var(--transition);
    margin-bottom: -1px;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent2); border-bottom-color: var(--accent2); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── TIMELINE ──────────────────────────────────────── */
.timeline { display: flex; flex-direction: column; gap: 0; }
.tl-item {
    display: flex;
    gap: 14px;
    position: relative;
    padding-bottom: 18px;
}
.tl-item:last-child { padding-bottom: 0; }
.tl-line {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 32px;
    flex-shrink: 0;
}
.tl-dot {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--surface2);
    border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
    z-index: 1;
}
.tl-connector {
    flex: 1;
    width: 2px;
    background: var(--border2);
    min-height: 16px;
}
.tl-content { flex: 1; padding-top: 4px; }
.tl-action { font-size: 13px; color: var(--text); font-weight: 500; }
.tl-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ── QUOTATION TABLE ───────────────────────────────── */
.q-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.q-table th {
    background: var(--surface2);
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .7px;
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}
.q-table td { padding: 10px 12px; border-bottom: 1px solid var(--border2); color: var(--text); vertical-align: top; }
.q-table tr:last-child td { border-bottom: none; }
.q-total-row td { font-weight: 700; color: var(--accent2); background: rgba(88,166,255,.04); }

.invoice-block { margin-bottom: 20px; }
.invoice-block-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    padding: 10px 14px;
    font-size: 12px;
    color: var(--muted);
}
.invoice-block-header strong { color: var(--text); font-size: 13px; }
.invoice-block-header .invoice-total { font-family: var(--font-head); font-size: 18px; color: var(--accent2); }
.invoice-table-wrap { border: 1px solid var(--border); border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm); overflow: hidden; }

/* ── ACTION BUTTONS ────────────────────────────────── */
.action-bar {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border2);
    flex-wrap: wrap;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: var(--radius-sm);
    border: none;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
}
.btn-approve { background: var(--green); color: #fff; }
.btn-approve:hover { background: #2ea043; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(63,185,80,.3); }
.btn-reject  { background: rgba(248,81,73,.15); color: var(--red); border: 1px solid rgba(248,81,73,.3); }
.btn-reject:hover  { background: rgba(248,81,73,.25); transform: translateY(-1px); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #1a5f8a; transform: translateY(-1px); }
.btn-ghost { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--text); border-color: var(--muted); }

/* ── MODAL ─────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(4px);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    width: 100%;
    max-width: 480px;
    box-shadow: var(--shadow-lg);
    animation: modalIn .25s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(.95); }
    to   { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border2);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 { font-family: var(--font-head); font-size: 16px; font-weight: 700; color: var(--text); }
.modal-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 18px; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 20px; }
.modal-body label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: .7px; }
.modal-body textarea {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 13px;
    padding: 10px 12px;
    resize: vertical;
    min-height: 100px;
    outline: none;
    transition: border-color var(--transition);
}
.modal-body textarea:focus { border-color: var(--red); }
.modal-footer { padding: 14px 20px; border-top: 1px solid var(--border2); display: flex; justify-content: flex-end; gap: 10px; }

/* ── TOAST ─────────────────────────────────────────── */
.toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.toast {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 18px;
    font-size: 13px;
    color: var(--text);
    box-shadow: var(--shadow);
    display: flex;
    align-items: center;
    gap: 10px;
    animation: toastIn .3s ease;
    max-width: 320px;
}
@keyframes toastIn { from { opacity:0; transform: translateX(20px); } to { opacity:1; transform: translateX(0); } }
.toast.success { border-left: 3px solid var(--green); }
.toast.error   { border-left: 3px solid var(--red); }

/* ── EMPTY STATE ───────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.empty-state i { font-size: 48px; opacity: .3; margin-bottom: 16px; display: block; }
.empty-state h3 { font-family: var(--font-head); font-size: 18px; color: var(--text); margin-bottom: 8px; }

/* ── SECTION HEADING ───────────────────────────────── */
.section-heading {
    font-family: var(--font-head);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--muted);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-heading::after { content:''; flex:1; height:1px; background: var(--border2); }

/* ── RESPONSIVE ────────────────────────────────────── */
.hamburger { display: none; background: none; border: none; color: var(--text); font-size: 20px; cursor: pointer; }

@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .hamburger { display: block; }
    .info-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
    .info-grid { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: repeat(2,1fr); }
    .fc-header { flex-wrap: wrap; }
    .fc-badges { width: 100%; }
}

/* ── LOADING SPINNER ───────────────────────────────── */
.spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid var(--border);
    border-top-color: var(--accent2);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes pulse {
    0%,100% { opacity: 1; }
    50%      { opacity: .4; }
}

/* Approved / Rework banners */
.status-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    margin-bottom: 16px;
}
.status-banner.approved { background: rgba(63,185,80,.1); border: 1px solid rgba(63,185,80,.25); color: var(--green); }
.status-banner.rework   { background: rgba(248,81,73,.1);  border: 1px solid rgba(248,81,73,.25);  color: var(--red); }

</style>
</head>
<body>

<!-- PORTAL HEADER -->
<header class="portal-header">
    <a href="client_portal.php" class="brand">
        <div class="brand-ic">⚙️</div>
        <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
    </a>
    <button class="mob-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('mob-open')">
        <i class="fas fa-bars"></i>
    </button>
    <div class="h-page-title">My Faults<span>Real-time Status Tracking</span></div>
    <div class="h-right">
        <div class="h-nm"><div class="n"><?php echo htmlspecialchars($client_name); ?></div></div>
        <div class="h-av" onclick="window.location.href='client_profile.php'" title="My Profile">
            <?php echo strtoupper(substr($client_name,0,1)); ?>
        </div>
        <a href="client_portal.php" class="hb" title="Dashboard"><i class="fas fa-home"></i></a>
        <a href="client_login.php"  class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></a>
    </div>
    <img src="../../images/logo.png" alt="Busiquip Logo" style="height:52px;width:auto;max-width:150px;object-fit:contain;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 4px 14px rgba(232,184,75,.3);flex-shrink:0;margin-left:8px">
</header>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="slbl">Main Menu</div>
    <a href="client_portal.php"          class="ni"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</a>
    <a href="client_profile.php"         class="ni"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</a>
    <a href="report_fault.php"           class="ni"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></a>

    <div class="slbl" style="margin-top:8px">Equipment</div>
    <a href="client_faults.php"          class="ni act"><div class="ic"><i class="fas fa-tools"></i></div> My Faults</a>
    <a href="client_repair_progress.php" class="ni"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</a>
    <a href="client_products.php"        class="ni"><div class="ic"><i class="fas fa-box-open"></i></div> My Products</a>

    <div class="slbl" style="margin-top:8px">Finance</div>
    <a href="client_invoices.php"        class="ni"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices</a>
    <a href="client_invoices.php"        class="ni"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment</a>

    <div class="slbl" style="margin-top:8px">More</div>
    <button class="exp-btn" id="exp-btn" onclick="document.getElementById('sub-menu').classList.toggle('open');this.classList.toggle('open')">
        <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
    </button>
    <div class="sub-menu" id="sub-menu">
        <a class="si" href="client_notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a class="si" href="client_documents.php"><i class="fas fa-folder"></i> Documents</a>
        <a class="si" href="client_reports.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a class="si" href="client_feedback.php"><i class="fas fa-star"></i> Leave Feedback</a>
        <a class="si" href="client_help.php"><i class="fas fa-life-ring"></i> Help &amp; Support</a>
        <a class="si" href="client_settings.php"><i class="fas fa-cog"></i> Settings</a>
    </div>
    <div class="s-banner">
        <i class="fas fa-headset"></i>
        <p>Need help? Our support team is available Mon–Fri 8AM–5PM.</p>
        <a href="mailto:support@busiquip.co.sz">Contact Support</a>
    </div>
</aside>


<!-- ══ MAIN ═════════════════════════════════════════════ -->
<div class="portal-main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:14px;">
            <button class="hamburger" onclick="toggleSidebar()"><i class="fa fa-bars"></i></button>
            <span class="topbar-title">My Reported Faults</span>
        </div>
        <div class="topbar-right">
            <button class="notif-btn" onclick="window.location.href='client_notifications.php'">
                <i class="fa fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                <span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                <?php endif; ?>
            </button>
            <span style="font-size:12px;color:var(--muted);"><?= date('D, d M Y') ?></span>
        </div>
    </div>

    <!-- PAGE BODY -->
    <div class="page-body">

        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h1>Fault Tracker</h1>
                <p>All faults reported by <?= htmlspecialchars($client_name) ?> · Real-time status tracking</p>
            </div>
            <a href="report_fault.php" class="btn btn-primary">
                <i class="fa fa-plus"></i> Report New Fault
            </a>
        </div>

        <!-- STATS -->
        <?php
        $total    = count($faults);
        $pending  = count(array_filter($faults, fn($f) => $f['STATUS'] === 'Pending'));
        $open     = count(array_filter($faults, fn($f) => in_array($f['STATUS'], ['Assigned','In Progress'])));
        $done     = count(array_filter($faults, fn($f) => in_array($f['STATUS'], ['Completed','Client Approved','Closed'])));
        $rework   = count(array_filter($faults, fn($f) => $f['STATUS'] === 'Rework Required'));
        ?>
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-val"><?= $total ?></div>
                <div class="stat-label">Total Faults</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:var(--orange)"><?= $pending ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:var(--accent2)"><?= $open ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:var(--green)"><?= $done ?></div>
                <div class="stat-label">Resolved</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:var(--red)"><?= $rework ?></div>
                <div class="stat-label">Rework</div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <input type="text" id="searchInput" placeholder="Search by reference, description, technician…" style="font-family:inherit;">
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">All Statuses</option>
                <option>Pending</option>
                <option>Assigned</option>
                <option>In Progress</option>
                <option>Completed</option>
                <option>Client Approved</option>
                <option>Rework Required</option>
                <option>Closed</option>
            </select>
            <select id="filterPriority" onchange="applyFilters()">
                <option value="">All Priorities</option>
                <option>High</option>
                <option>Medium</option>
                <option>Low</option>
                <option>Urgent</option>
            </select>
            <select id="sortBy" onchange="applyFilters()">
                <option value="date_desc">Newest First</option>
                <option value="date_asc">Oldest First</option>
                <option value="priority">Priority</option>
                <option value="status">Status</option>
            </select>
        </div>

        <!-- FAULTS LIST -->
        <div class="faults-grid" id="faultsGrid">
        <?php if (empty($faults)): ?>
            <div class="empty-state">
                <i class="fa fa-clipboard-check"></i>
                <h3>No Faults Reported Yet</h3>
                <p>When you report a fault, it will appear here with full tracking details.</p>
                <br>
                <a href="report_fault.php" class="btn btn-primary" style="margin:0 auto;">
                    <i class="fa fa-plus"></i> Report Your First Fault
                </a>
            </div>
        <?php else: ?>
        <?php foreach ($faults as $idx => $f):
            $fid      = $f['REP_FAULT_ID'];
            $status   = $f['STATUS'] ?? 'Pending';
            $priority = $f['PRIORITY'] ?? 'Low';
            $progress = progressFromStatus($status);
            $sColor   = statusColor($status);
            $pColor   = priorityColor($priority);
            $techs    = $f['TECHNICIANS'] ?? '—';
            $assignId = $f['ASSIGN_ID'] ? intval($f['ASSIGN_ID']) : null;

            // Description excerpt
            $desc = $f['DESCRIPTION'] ?? '';
            // Try to parse structured fields
            $faultTitle = '';
            if (preg_match('/FAULT TITLE:\s*(.+)/i', $desc, $m)) $faultTitle = trim($m[1]);
            if (!$faultTitle) $faultTitle = mb_strimwidth($desc, 0, 80, '…');

            $category = '';
            if (preg_match('/CATEGORY:\s*(.+)/i', $desc, $m)) $category = trim($m[1]);
            elseif ($f['FAULT_TYPE']) $category = $f['FAULT_TYPE'];
            else $category = 'General';

            $equipType = '';
            if (preg_match('/EQUIPMENT TYPE:\s*(.+)/i', $desc, $m)) $equipType = trim($m[1]);

            $brand = '';
            if (preg_match('/BRAND\/MODEL:\s*(.+)/i', $desc, $m)) $brand = trim($m[1]);

            $serial = '';
            if (preg_match('/SERIAL\/ASSET NO:\s*(.+)/i', $desc, $m)) $serial = trim($m[1]);

            $location = '';
            if (preg_match('/FAULT LOCATION:\s*(.+)/i', $desc, $m)) $location = trim($m[1]);

            $faultRef = '';
            if (preg_match('/FAULT REFERENCE:\s*(.+)/i', $desc, $m)) $faultRef = trim($m[1]);
            if (!$faultRef) $faultRef = 'BQ-' . str_pad($fid, 5, '0', STR_PAD_LEFT);

            $isOperational = '';
            if (preg_match('/IS OPERATIONAL:\s*(.+)/i', $desc, $m)) $isOperational = trim($m[1]);

            $detailedDesc = '';
            if (preg_match('/DETAILED DESCRIPTION:\s*(.+)/si', $desc, $m)) $detailedDesc = trim($m[1]);
            if (!$detailedDesc) $detailedDesc = $desc;

            // Work timeline
            $timeline = getWorkLog($pdo, $assignId);

            // Quotations
            $quotations = getQuotations($pdo, $assignId);
            // Group by invoice
            $invoices = [];
            foreach ($quotations as $q) {
                $iid = $q['INVOICE_ID'];
                if (!isset($invoices[$iid])) {
                    $invoices[$iid] = [
                        'id'      => $iid,
                        'date'    => $q['INVOICE_DATE'],
                        'due'     => $q['DUE_DATE'],
                        'status'  => $q['STATUS'],
                        'type'    => $q['TYPE'],
                        'total'   => $q['TOTAL'],
                        'paid'    => $q['PAID_AMOUNT'],
                        'lines'   => [],
                    ];
                }
                if ($q['DESCRIPTION']) {
                    $invoices[$iid]['lines'][] = [
                        'desc'  => $q['DESCRIPTION'],
                        'qty'   => $q['QUANTITY'],
                        'price' => $q['UNIT_PRICE'],
                        'total' => $q['LINE_TOTAL'],
                    ];
                }
            }

            // Show approve/reject when technician has marked as Completed (including after rework re-completion)
            // Do not show if already confirmed (client approved)
            $showApprove = ($status === 'Completed') && ($f['CONFIRM_STATUS'] !== 'Confirmed');
            $showRework  = ($status === 'Completed') && ($f['CONFIRM_STATUS'] !== 'Confirmed');

            // Fetch payments for each invoice (for payment slip display)
            foreach ($invoices as $iid => &$inv_data) {
                $inv_data['payments'] = getPaymentsForInvoice($pdo, $iid);
            }
            unset($inv_data);
        ?>
        <div class="fault-card" 
             id="card-<?= $fid ?>"
             data-status="<?= htmlspecialchars($status) ?>"
             data-priority="<?= htmlspecialchars($priority) ?>"
             data-date="<?= $f['REPORT_DATE'] ?>"
             data-search="<?= htmlspecialchars(strtolower($faultRef . ' ' . $faultTitle . ' ' . $techs . ' ' . $desc)) ?>"
             style="animation-delay: <?= $idx * 0.05 ?>s">

            <!-- CARD HEADER -->
            <div class="fc-header" onclick="toggleCard(<?= $fid ?>)">
                <div class="fc-ref"><?= htmlspecialchars($faultRef) ?></div>
                <div class="fc-title">
                    <?= htmlspecialchars($faultTitle) ?>
                    <small><i class="fa fa-tag" style="font-size:10px;"></i> <?= htmlspecialchars($category) ?> <?= $equipType ? '· '.$equipType : '' ?></small>
                </div>
                <div class="fc-badges">
                    <span class="badge" style="background:<?= $sColor ?>22;color:<?= $sColor ?>;border:1px solid <?= $sColor ?>44;">
                        <span style="width:6px;height:6px;border-radius:50%;background:<?= $sColor ?>;flex-shrink:0;<?= in_array($status,['In Progress','Assigned']) ? 'animation:pulse 1.5s ease-in-out infinite;' : '' ?>"></span>
                        <?= htmlspecialchars($status) ?>
                    </span>
                    <span class="badge badge-outline" style="color:<?= $pColor ?>;border-color:<?= $pColor ?>44;">
                        <?= htmlspecialchars($priority) ?>
                    </span>
                    <span style="font-size:11px;color:var(--muted);">
                        <?= date('d M Y', strtotime($f['REPORT_DATE'])) ?>
                    </span>
                </div>
                <i class="fa fa-chevron-down fc-chevron" id="chev-<?= $fid ?>"></i>
            </div>

            <!-- CARD BODY -->
            <div class="fc-body" id="body-<?= $fid ?>">

                <!-- Confirmation banners -->
                <?php if ($f['CONFIRM_STATUS'] === 'Confirmed'): ?>
                <div class="status-banner approved">
                    <i class="fa fa-check-circle"></i>
                    <span>You approved this fault resolution. Invoice is being generated by the accountant.</span>
                </div>
                <?php elseif ($f['CONFIRM_STATUS'] === 'Rejected'): ?>
                <div class="status-banner rework">
                    <i class="fa fa-times-circle"></i>
                    <span>You requested rework. Reason: <em><?= htmlspecialchars($f['CONFIRM_NOTES'] ?? '') ?></em></span>
                </div>
                <?php endif; ?>

                <!-- PROGRESS -->
                <div class="progress-section">
                    <div class="progress-header">
                        <span>Overall Progress</span>
                        <span><?= $progress ?>%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?= $progress ?>%"></div>
                    </div>
                </div>

                <!-- INFO GRID -->
                <div class="section-heading">Fault Details</div>
                <div class="info-grid" style="margin-bottom:20px;">
                    <div class="info-item">
                        <label>Reference</label>
                        <span><?= htmlspecialchars($faultRef) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Reported By</label>
                        <span><?= htmlspecialchars($f['REPORTED_BY'] ?? '—') ?></span>
                    </div>
                    <div class="info-item">
                        <label>Date Reported</label>
                        <span><?= date('d M Y, H:i', strtotime($f['REPORT_DATE'])) ?></span>
                    </div>
                    <?php if ($f['DUE_DATE']): ?>
                    <div class="info-item">
                        <label>Due Date</label>
                        <span><?= date('d M Y', strtotime($f['DUE_DATE'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <label>Priority</label>
                        <span style="color:<?= $pColor ?>;font-weight:600;"><?= htmlspecialchars($priority) ?></span>
                    </div>
                    <div class="info-item">
                        <label>Category</label>
                        <span><?= htmlspecialchars($category) ?></span>
                    </div>
                    <?php if ($equipType): ?>
                    <div class="info-item">
                        <label>Equipment Type</label>
                        <span><?= htmlspecialchars($equipType) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($brand): ?>
                    <div class="info-item">
                        <label>Brand / Model</label>
                        <span><?= htmlspecialchars($brand) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($serial): ?>
                    <div class="info-item">
                        <label>Serial / Asset No.</label>
                        <span><?= htmlspecialchars($serial) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($location): ?>
                    <div class="info-item">
                        <label>Location</label>
                        <span><?= htmlspecialchars($location) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($isOperational): ?>
                    <div class="info-item">
                        <label>Operational?</label>
                        <span><?= htmlspecialchars($isOperational) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <label>Assigned Technician(s)</label>
                        <span><?= $techs !== '—' ? '<i class="fa fa-user-cog" style="color:var(--accent2);margin-right:4px;"></i>'.htmlspecialchars($techs) : '—' ?></span>
                    </div>
                    <?php if ($f['LATEST_ACTIVITY']): ?>
                    <div class="info-item" style="grid-column:1/-1;">
                        <label>Latest Update</label>
                        <span><?= htmlspecialchars($f['LATEST_ACTIVITY']) ?> 
                            <small style="color:var(--muted);">&nbsp;·&nbsp;<?= $f['LATEST_ACTIVITY_DATE'] ? date('d M, H:i', strtotime($f['LATEST_ACTIVITY_DATE'])) : '' ?></small>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($detailedDesc && $detailedDesc !== $desc): ?>
                    <div class="info-item" style="grid-column:1/-1;">
                        <label>Detailed Description</label>
                        <span style="white-space:pre-line;color:var(--muted);"><?= htmlspecialchars($detailedDesc) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TABS -->
                <div class="tabs" id="tabs-<?= $fid ?>">
                    <button class="tab-btn active" onclick="switchTab(<?= $fid ?>, 'timeline', this)">
                        <i class="fa fa-stream"></i> Work Timeline
                    </button>
                    <button class="tab-btn" onclick="switchTab(<?= $fid ?>, 'quotations', this)">
                        <i class="fa fa-file-invoice-dollar"></i> Quotations
                        <?php if (!empty($invoices)): ?>
                        <span style="background:var(--accent);color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= count($invoices) ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="tab-btn" onclick="switchTab(<?= $fid ?>, 'description', this)">
                        <i class="fa fa-align-left"></i> Full Report
                    </button>
                </div>

                <!-- TAB: TIMELINE -->
                <div class="tab-panel active" id="tab-<?= $fid ?>-timeline">
                    <?php if (empty($timeline)): ?>
                        <div style="color:var(--muted);font-size:13px;padding:12px 0;">
                            <i class="fa fa-clock" style="margin-right:6px;"></i>No work activity logged yet.
                        </div>
                    <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($timeline as $ti => $log):
                            $logIcon  = match($log['LOG_TYPE']) {
                                'Start'    => 'fa-play-circle',
                                'Complete' => 'fa-check-circle',
                                'Update'   => 'fa-edit',
                                default    => 'fa-circle',
                            };
                            $logColor = match($log['LOG_TYPE']) {
                                'Start'    => 'var(--accent2)',
                                'Complete' => 'var(--green)',
                                'Update'   => 'var(--orange)',
                                default    => 'var(--muted)',
                            };
                        ?>
                        <div class="tl-item">
                            <div class="tl-line">
                                <div class="tl-dot" style="border-color:<?= $logColor ?>;color:<?= $logColor ?>;">
                                    <i class="fa <?= $logIcon ?>" style="font-size:10px;"></i>
                                </div>
                                <?php if ($ti < count($timeline)-1): ?>
                                <div class="tl-connector"></div>
                                <?php endif; ?>
                            </div>
                            <div class="tl-content">
                                <div class="tl-action"><?= htmlspecialchars($log['ACTION_TAKEN'] ?? $log['LOG_TYPE']) ?></div>
                                <div class="tl-meta">
                                    <i class="fa fa-user" style="font-size:10px;"></i> <?= htmlspecialchars($log['FULL_NAME'] ?? 'Technician') ?>
                                    &nbsp;·&nbsp;
                                    <i class="fa fa-calendar" style="font-size:10px;"></i> <?= date('d M Y, H:i', strtotime($log['LOG_DATE'])) ?>
                                    <?php if ($log['HOURS_SPENT'] > 0): ?>
                                    &nbsp;·&nbsp;<i class="fa fa-clock" style="font-size:10px;"></i> <?= $log['HOURS_SPENT'] ?>h
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Assignment events -->
                        <?php if ($f['ASSIGN_DATE']): ?>
                        <div class="tl-item">
                            <div class="tl-line">
                                <div class="tl-dot" style="border-color:var(--purple);color:var(--purple);">
                                    <i class="fa fa-user-check" style="font-size:10px;"></i>
                                </div>
                            </div>
                            <div class="tl-content">
                                <div class="tl-action">Technician Assigned</div>
                                <div class="tl-meta">
                                    <?= htmlspecialchars($techs) ?> &nbsp;·&nbsp;
                                    <?= date('d M Y', strtotime($f['ASSIGN_DATE'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Reported event always last -->
                        <div class="tl-item">
                            <div class="tl-line">
                                <div class="tl-dot" style="border-color:var(--muted);color:var(--muted);">
                                    <i class="fa fa-flag" style="font-size:10px;"></i>
                                </div>
                            </div>
                            <div class="tl-content">
                                <div class="tl-action">Fault Reported</div>
                                <div class="tl-meta">By <?= htmlspecialchars($f['REPORTED_BY'] ?? 'Client') ?> · <?= date('d M Y, H:i', strtotime($f['REPORT_DATE'])) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TAB: QUOTATIONS -->
                <div class="tab-panel" id="tab-<?= $fid ?>-quotations">
                    <?php if (empty($invoices)): ?>
                        <div style="color:var(--muted);font-size:13px;padding:12px 0;">
                            <i class="fa fa-file-invoice" style="margin-right:6px;"></i>No quotations submitted yet.
                        </div>
                    <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                    <div class="invoice-block">
                        <div class="invoice-block-header">
                            <div>
                                <strong><?= $inv['type'] ?> #<?= $inv['id'] ?></strong>
                                <span style="margin-left:10px;">
                                    <span class="badge" style="background:<?= $inv['status']==='Paid' ? 'rgba(63,185,80,.2)' : ($inv['status']==='Pending Payment' ? 'rgba(210,153,34,.2)' : 'rgba(88,166,255,.15)') ?>;color:<?= $inv['status']==='Paid' ? 'var(--green)' : ($inv['status']==='Pending Payment' ? 'var(--orange)' : 'var(--accent2)') ?>;">
                                        <?= htmlspecialchars($inv['status']) ?>
                                    </span>
                                </span>
                                <span style="color:var(--muted);margin-left:8px;font-size:11px;">
                                    Issued: <?= $inv['date'] ? date('d M Y', strtotime($inv['date'])) : '—' ?>
                                    &nbsp;·&nbsp;
                                    Due: <?= $inv['due'] ? date('d M Y', strtotime($inv['due'])) : '—' ?>
                                </span>
                            </div>
                            <div>
                                <span style="color:var(--muted);font-size:11px;margin-right:8px;">Total</span>
                                <span class="invoice-total">E<?= number_format($inv['total'], 2) ?></span>
                            </div>
                        </div>
                        <div class="invoice-table-wrap">
                            <table class="q-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th>Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inv['lines'] as $line): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($line['desc']) ?></td>
                                        <td><?= $line['qty'] ?></td>
                                        <td>E<?= number_format($line['price'], 2) ?></td>
                                        <td>E<?= number_format($line['total'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="q-total-row">
                                        <td colspan="3"><strong>Total</strong></td>
                                        <td><strong>E<?= number_format($inv['total'], 2) ?></strong></td>
                                    </tr>
                                    <?php if ($inv['paid'] > 0): ?>
                                    <tr>
                                        <td colspan="3" style="color:var(--green);">Paid Amount</td>
                                        <td style="color:var(--green);">E<?= number_format($inv['paid'], 2) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php
                        // ── PAYMENT SLIP SECTION ─────────────────────────────────
                        $inv_payments = $inv['payments'] ?? [];
                        if (!empty($inv_payments)):
                        ?>
                        <div style="margin-top:14px;">
                            <div class="section-heading" style="font-size:11px;margin-bottom:10px;">
                                <i class="fa fa-receipt" style="color:var(--green);"></i> Payment Records
                            </div>
                            <?php foreach ($inv_payments as $pay):
                                $pay_bg    = $pay['STATUS'] === 'Verified' ? 'rgba(63,185,80,.08)' : ($pay['STATUS'] === 'Rejected' ? 'rgba(248,81,73,.08)' : 'rgba(210,153,34,.08)');
                                $pay_bdr   = $pay['STATUS'] === 'Verified' ? 'rgba(63,185,80,.25)' : ($pay['STATUS'] === 'Rejected' ? 'rgba(248,81,73,.25)' : 'rgba(210,153,34,.25)');
                                $pay_color = $pay['STATUS'] === 'Verified' ? 'var(--green)' : ($pay['STATUS'] === 'Rejected' ? 'var(--red)' : 'var(--orange)');
                                $pay_icon  = $pay['STATUS'] === 'Verified' ? 'fa-check-circle' : ($pay['STATUS'] === 'Rejected' ? 'fa-times-circle' : 'fa-clock');
                            ?>
                            <div style="background:<?= $pay_bg ?>;border:1px solid <?= $pay_bdr ?>;border-radius:8px;padding:12px 14px;margin-bottom:8px;">
                                <!-- Slip header -->
                                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <i class="fa <?= $pay_icon ?>" style="color:<?= $pay_color ?>;font-size:14px;"></i>
                                        <span style="font-size:13px;font-weight:600;color:var(--text);">
                                            Payment #<?= $pay['PAYMENT_ID'] ?>
                                        </span>
                                        <span style="font-size:11px;background:<?= $pay_bg ?>;border:1px solid <?= $pay_bdr ?>;color:<?= $pay_color ?>;border-radius:99px;padding:2px 8px;font-weight:600;">
                                            <?= htmlspecialchars($pay['STATUS']) ?>
                                        </span>
                                    </div>
                                    <span style="font-size:1rem;font-weight:700;color:<?= $pay_color ?>;">
                                        E<?= number_format($pay['AMOUNT_PAID'], 2) ?>
                                    </span>
                                </div>

                                <!-- Slip details grid -->
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:6px 16px;font-size:12px;">
                                    <div>
                                        <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px;">Method</div>
                                        <div style="color:var(--text);font-weight:500;"><?= htmlspecialchars($pay['METHOD'] ?? '—') ?></div>
                                    </div>
                                    <div>
                                        <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px;">Date</div>
                                        <div style="color:var(--text);font-weight:500;"><?= $pay['PAYMENT_DATE'] ? date('d M Y', strtotime($pay['PAYMENT_DATE'])) : '—' ?></div>
                                    </div>
                                    <?php if ($pay['REFERENCE_NUMBER']): ?>
                                    <div>
                                        <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px;">Reference</div>
                                        <div style="color:var(--text);font-weight:500;font-family:monospace;"><?= htmlspecialchars($pay['REFERENCE_NUMBER']) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($pay['RECEIPT_DATE']): ?>
                                    <div>
                                        <div style="color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px;">Receipt Date</div>
                                        <div style="color:var(--text);font-weight:500;"><?= date('d M Y', strtotime($pay['RECEIPT_DATE'])) ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($pay['STATUS'] === 'Verified'): ?>
                                <!-- Official receipt slip -->
                                <div style="margin-top:12px;padding-top:10px;border-top:1px dashed <?= $pay_bdr ?>;">
                                    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;font-weight:700;">
                                        <i class="fa fa-file-invoice-dollar" style="margin-right:4px;"></i> Official Receipt
                                    </div>
                                    <div style="background:rgba(0,0,0,.25);border-radius:6px;padding:12px;border:1px solid <?= $pay_bdr ?>;">
                                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                            <span style="font-size:12px;font-weight:700;color:var(--green);">BUSIQUIP ESWATINI</span>
                                            <span style="font-size:10px;color:var(--muted);">PAYMENT RECEIPT</span>
                                        </div>
                                        <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Receipt #<?= $pay['RECEIPT_ID'] ?? ('PMT-'.str_pad($pay['PAYMENT_ID'],4,'0',STR_PAD_LEFT)) ?></div>
                                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
                                            <span style="color:var(--muted);">Amount Paid</span>
                                            <span style="color:var(--green);font-weight:700;">E<?= number_format($pay['AMOUNT_PAID'], 2) ?></span>
                                        </div>
                                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
                                            <span style="color:var(--muted);">Payment Method</span>
                                            <span style="color:var(--text);"><?= htmlspecialchars($pay['METHOD'] ?? '—') ?></span>
                                        </div>
                                        <?php if ($pay['REFERENCE_NUMBER']): ?>
                                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
                                            <span style="color:var(--muted);">Transaction Ref</span>
                                            <span style="color:var(--text);font-family:monospace;"><?= htmlspecialchars($pay['REFERENCE_NUMBER']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div style="display:flex;justify-content:space-between;font-size:12px;">
                                            <span style="color:var(--muted);">Date</span>
                                            <span style="color:var(--text);"><?= $pay['PAYMENT_DATE'] ? date('d M Y', strtotime($pay['PAYMENT_DATE'])) : '—' ?></span>
                                        </div>
                                        <div style="margin-top:8px;padding-top:8px;border-top:1px solid <?= $pay_bdr ?>;text-align:center;font-size:10px;color:var(--muted);">
                                            Thank you for your payment — BUSIQUIP ESWATINI
                                        </div>
                                    </div>
                                    <button onclick="printSlip(<?= $pay['PAYMENT_ID'] ?>, <?= number_format($pay['AMOUNT_PAID'],2,'.','') ?>, '<?= addslashes($pay['METHOD'] ?? '') ?>', '<?= addslashes($pay['REFERENCE_NUMBER'] ?? '') ?>', '<?= $pay['PAYMENT_DATE'] ? date('d M Y', strtotime($pay['PAYMENT_DATE'])) : '' ?>', <?= $pay['RECEIPT_ID'] ?? $pay['PAYMENT_ID'] ?>)"
                                            style="margin-top:8px;background:none;border:1px solid <?= $pay_bdr ?>;color:var(--green);border-radius:6px;padding:5px 12px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                                        <i class="fa fa-print"></i> Print / Save Receipt
                                    </button>
                                </div>
                                <?php elseif ($pay['STATUS'] === 'Pending'): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--orange);display:flex;align-items:center;gap:6px;">
                                    <i class="fa fa-hourglass-half"></i>
                                    Payment submitted and awaiting verification by the accountant.
                                </div>
                                <?php elseif ($pay['STATUS'] === 'Rejected'): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--red);display:flex;align-items:center;gap:6px;">
                                    <i class="fa fa-exclamation-circle"></i>
                                    This payment was rejected. Please contact the accounts department.
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($inv['type'] === 'Invoice' && $inv['status'] === 'Pending Payment'): ?>
                        <div style="margin-top:12px;padding:10px 14px;background:rgba(210,153,34,.08);border:1px solid rgba(210,153,34,.2);border-radius:8px;font-size:12px;color:var(--orange);display:flex;align-items:center;gap:8px;">
                            <i class="fa fa-exclamation-triangle"></i>
                            Invoice issued and awaiting your payment. Please proceed to
                            <a href="client_invoices.php" style="color:var(--accent2);font-weight:600;margin-left:4px;">Invoices &amp; Payments</a>.
                        </div>
                        <?php elseif ($inv['type'] === 'Quotation' && in_array($inv['status'], ['Submitted','Approved'])): ?>
                        <div style="margin-top:12px;padding:10px 14px;background:rgba(88,166,255,.08);border:1px solid rgba(88,166,255,.2);border-radius:8px;font-size:12px;color:var(--accent2);display:flex;align-items:center;gap:8px;">
                            <i class="fa fa-info-circle"></i>
                            Quotation under review by the accountant. An invoice will be issued once approved.
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- TAB: FULL REPORT -->
                <div class="tab-panel" id="tab-<?= $fid ?>-description">
                    <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:var(--radius-sm);padding:16px;font-size:13px;white-space:pre-line;color:var(--muted);line-height:1.8;font-family:monospace;">
<?= htmlspecialchars($desc ?: 'No detailed report data available.') ?>
                    </div>
                </div>

                <!-- ACTION BUTTONS -->
                <?php if ($showApprove || $showRework): ?>
                <div class="action-bar">
                    <div style="flex:1;font-size:12px;color:var(--muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <i class="fa fa-info-circle" style="color:var(--green);"></i>
                        <?php if ($f['CONFIRM_STATUS'] === 'Rejected'): ?>
                            The technician has resubmitted after rework. Please review again and confirm or reject.
                        <?php else: ?>
                            The technician has marked this fault as <strong style="color:var(--green);">Completed</strong>. Please review the work and confirm.
                        <?php endif; ?>
                    </div>
                    <?php if ($showApprove): ?>
                    <button class="btn btn-approve" onclick="approveCompletion(<?= $fid ?>)">
                        <i class="fa fa-check"></i> Approve &amp; Accept Work
                    </button>
                    <?php endif; ?>
                    <?php if ($showRework): ?>
                    <button class="btn btn-reject" onclick="openRejectModal(<?= $fid ?>)">
                        <i class="fa fa-redo"></i> Reject &amp; Request Rework
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /.fc-body -->
        </div><!-- /.fault-card -->
        <?php endforeach; ?>
        <?php endif; ?>
        </div><!-- /#faultsGrid -->

    </div><!-- /.page-body -->
</div><!-- /.main -->

<!-- ══ REJECT MODAL ═════════════════════════════════════ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa fa-redo" style="color:var(--red);margin-right:8px;"></i>Request Rework</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fa fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p style="color:var(--muted);font-size:13px;margin-bottom:14px;">
                Please describe what was not resolved correctly. The technician will be notified immediately.
            </p>
            <label>Rejection Reason *</label>
            <textarea id="rejectReason" placeholder="e.g. The printer is still jamming after the repair. The paper feed mechanism was not fully fixed…"></textarea>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
            <button class="btn btn-reject" id="confirmRejectBtn" onclick="submitRejection()">
                <i class="fa fa-paper-plane"></i> Submit Rejection
            </button>
        </div>
    </div>
</div>

<!-- ══ TOASTS ════════════════════════════════════════════ -->
<div class="toast-container" id="toastContainer"></div>

<!-- ══ SCRIPT ════════════════════════════════════════════ -->
<script>
// ── Toggle sidebar on mobile ────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

// ── Card expand/collapse ────────────────────────────────
function toggleCard(fid) {
    const body  = document.getElementById('body-' + fid);
    const chev  = document.getElementById('chev-' + fid);
    const hdr   = chev.closest('.fc-header');
    const isOpen = body.classList.contains('open');

    body.classList.toggle('open', !isOpen);
    hdr.classList.toggle('open', !isOpen);

    // Animate progress bar on open
    if (!isOpen) {
        const fill = body.querySelector('.progress-fill');
        if (fill) {
            const w = fill.style.width;
            fill.style.width = '0%';
            requestAnimationFrame(() => fill.style.width = w);
        }
    }
}

// ── Tab switching ───────────────────────────────────────
function switchTab(fid, tabName, btn) {
    // Deactivate all tabs for this card
    document.querySelectorAll('#tabs-' + fid + ' .tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Hide all panels
    ['timeline','quotations','description'].forEach(t => {
        const p = document.getElementById('tab-' + fid + '-' + t);
        if (p) p.classList.remove('active');
    });
    const active = document.getElementById('tab-' + fid + '-' + tabName);
    if (active) active.classList.add('active');
}

// ── Filters & search ────────────────────────────────────
document.getElementById('searchInput').addEventListener('input', applyFilters);

function applyFilters() {
    const search   = document.getElementById('searchInput').value.toLowerCase();
    const status   = document.getElementById('filterStatus').value;
    const priority = document.getElementById('filterPriority').value;
    const sort     = document.getElementById('sortBy').value;

    const grid  = document.getElementById('faultsGrid');
    const cards = Array.from(grid.querySelectorAll('.fault-card'));

    cards.forEach(card => {
        const matchSearch   = !search   || card.dataset.search.includes(search);
        const matchStatus   = !status   || card.dataset.status   === status;
        const matchPriority = !priority || card.dataset.priority.toLowerCase() === priority.toLowerCase();
        card.style.display = (matchSearch && matchStatus && matchPriority) ? '' : 'none';
    });

    // Sort
    const priorityOrder = {urgent:0, high:1, medium:2, low:3};
    const statusOrder   = {'Rework Required':0,'Pending':1,'In Progress':2,'Assigned':3,'Completed':4,'Client Approved':5,'Closed':6};

    const visible = cards.filter(c => c.style.display !== 'none');
    visible.sort((a,b) => {
        if (sort === 'date_desc') return new Date(b.dataset.date) - new Date(a.dataset.date);
        if (sort === 'date_asc')  return new Date(a.dataset.date) - new Date(b.dataset.date);
        if (sort === 'priority')  return (priorityOrder[a.dataset.priority.toLowerCase()]??9) - (priorityOrder[b.dataset.priority.toLowerCase()]??9);
        if (sort === 'status')    return (statusOrder[a.dataset.status]??9) - (statusOrder[b.dataset.status]??9);
        return 0;
    });
    visible.forEach(c => grid.appendChild(c));
}

// ── Approve completion ──────────────────────────────────
async function approveCompletion(faultId) {
    if (!confirm('Are you sure you want to approve this fault resolution? This will notify the accountant to generate an invoice.')) return;

    const fd = new FormData();
    fd.append('action', 'approve_completion');
    fd.append('fault_id', faultId);

    try {
        const res  = await fetch('', { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1800);
        } else {
            showToast(data.message, 'error');
        }
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
    }
}

// ── Reject modal ────────────────────────────────────────
let _rejectFaultId = null;

function openRejectModal(faultId) {
    _rejectFaultId = faultId;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.add('open');
    setTimeout(() => document.getElementById('rejectReason').focus(), 100);
}

function closeModal() {
    document.getElementById('rejectModal').classList.remove('open');
    _rejectFaultId = null;
}

async function submitRejection() {
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        document.getElementById('rejectReason').focus();
        document.getElementById('rejectReason').style.borderColor = 'var(--red)';
        return;
    }

    const btn = document.getElementById('confirmRejectBtn');
    btn.innerHTML = '<span class="spinner"></span> Submitting…';
    btn.disabled  = true;

    const fd = new FormData();
    fd.append('action',   'reject_completion');
    fd.append('fault_id', _rejectFaultId);
    fd.append('reason',   reason);

    try {
        const res  = await fetch('', { method:'POST', body: fd });
        const data = await res.json();
        closeModal();
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1800);
        } else {
            showToast(data.message, 'error');
        }
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Rejection';
        btn.disabled  = false;
    }
}

// Close modal clicking outside
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Toast notifications ─────────────────────────────────
function showToast(msg, type='success') {
    const container = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = `<i class="fa ${type==='success' ? 'fa-check-circle' : 'fa-exclamation-circle'}" style="color:${type==='success'?'var(--green)':'var(--red)'}"></i>${msg}`;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(), 400); }, 4000);
}

// ── Print payment receipt slip ──────────────────────────
function printSlip(paymentId, amount, method, reference, date, receiptId) {
    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Payment Receipt</title>
<style>
  body { font-family: 'Segoe UI', sans-serif; background:#fff; color:#111; margin:0; padding:30px; }
  .slip { max-width:400px; margin:0 auto; border:2px solid #111; border-radius:8px; padding:24px; }
  .slip-header { text-align:center; border-bottom:1px dashed #999; padding-bottom:14px; margin-bottom:14px; }
  .slip-header h2 { font-size:20px; margin:0 0 4px; color:#000; }
  .slip-header small { font-size:11px; color:#555; }
  .slip-title { text-align:center; font-size:13px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:#444; margin-bottom:16px; }
  .slip-row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:7px; }
  .slip-row .label { color:#555; }
  .slip-row .value { font-weight:600; }
  .slip-total { border-top:1px dashed #999; margin-top:12px; padding-top:12px; }
  .slip-total .value { color:#059669; font-size:16px; }
  .slip-footer { text-align:center; margin-top:16px; font-size:10px; color:#777; border-top:1px dashed #ccc; padding-top:10px; }
  @media print { body { padding:10px; } }
</style>
</head>
<body>
<div class="slip">
  <div class="slip-header">
    <h2>BUSIQUIP ESWATINI</h2>
    <small>Fault Management &amp; Technical Services</small>
  </div>
  <div class="slip-title">Payment Receipt</div>
  <div class="slip-row"><span class="label">Receipt No.</span><span class="value">#${String(receiptId).padStart(4,'0')}</span></div>
  <div class="slip-row"><span class="label">Payment ID</span><span class="value">#${paymentId}</span></div>
  <div class="slip-row"><span class="label">Method</span><span class="value">${method}</span></div>
  ${reference ? `<div class="slip-row"><span class="label">Reference</span><span class="value" style="font-family:monospace;">${reference}</span></div>` : ''}
  <div class="slip-row"><span class="label">Date</span><span class="value">${date}</span></div>
  <div class="slip-row slip-total">
    <span class="label" style="font-weight:700;">Amount Paid</span>
    <span class="value">E${parseFloat(amount).toFixed(2)}</span>
  </div>
  <div class="slip-footer">
    This is an official payment receipt issued by BUSIQUIP ESWATINI.<br>
    All amounts are in Eswatini Lilangeni (E).
  </div>
</div>
<script>window.onload=function(){window.print();};<\/script>
</body>
</html>`;
    const win = window.open('', '_blank', 'width=480,height=680');
    if (win) { win.document.write(html); win.document.close(); }
}


// Auto-expand the first fault card on load
document.addEventListener('DOMContentLoaded', function() {
    const firstCard = document.querySelector('.fault-card[id^="card-"]');
    if (firstCard) {
        const fid = firstCard.id.replace('card-', '');
        setTimeout(() => toggleCard(fid), 200);
    }
});
</script>
</body>
</html>
