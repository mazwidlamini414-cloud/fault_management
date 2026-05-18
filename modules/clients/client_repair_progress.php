<?php
// ═══════════════════════════════════════════════════════════════════════
//  FILE PATH: fault_management/modules/clients/client_repair_progress.php
//  BUSIQUIP ESWATINI — Client Repair Progress Tracker
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors',1); error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['client_id'])) { header('Location: client_login.php'); exit; }

$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';
$client_type    = $_SESSION['client_type']    ?? 'CORPORATE';

require_once '../../config/database.php';

// ── AJAX ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_active_faults') {
        $res = mysqli_query($conn,"
            SELECT rf.*,
                   a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE, a.STATUS AS ASSIGN_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ')  AS TECHNICIANS,
                   i.INVOICE_ID, i.TOTAL AS INVOICE_TOTAL, i.STATUS AS INVOICE_STATUS,
                   (SELECT COUNT(*) FROM work_log wl WHERE wl.ASSIGN_ID=a.ASSIGN_ID) AS LOG_COUNT
            FROM reported_fault rf
            LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID=at2.EMP_ID
            LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID AND i.CLIENT_ID=$client_id
            WHERE rf.CLIENT_ID=$client_id AND rf.STATUS NOT IN('Closed','Rejected')
            GROUP BY rf.REP_FAULT_ID
            ORDER BY rf.REPORT_DATE DESC
        ");
        $rows=[];
        while($r=mysqli_fetch_assoc($res)) $rows[]=$r;
        echo json_encode($rows); exit;
    }

    if ($action === 'get_fault_detail') {
        $fid = (int)($_GET['id']??0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"
            SELECT rf.*,
                   a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE, a.STATUS AS ASSIGN_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ')  AS TECHNICIANS,
                   GROUP_CONCAT(DISTINCT e.EMAIL   SEPARATOR ', ')    AS TECH_EMAILS
            FROM reported_fault rf
            LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID=at2.EMP_ID
            WHERE rf.REP_FAULT_ID=$fid AND rf.CLIENT_ID=$client_id
            GROUP BY rf.REP_FAULT_ID
        "));
        if (!$row) { echo json_encode(['error'=>'Not found']); exit; }

        $logs=[];
        if ($row['ASSIGN_ID']) {
            $aid=(int)$row['ASSIGN_ID'];
            $lr=mysqli_query($conn,"SELECT wl.*,e.FULL_NAME AS EMP_NAME FROM work_log wl LEFT JOIN employee e ON e.EMP_ID=wl.EMP_ID WHERE wl.ASSIGN_ID=$aid ORDER BY wl.LOG_DATE ASC");
            while($l=mysqli_fetch_assoc($lr)) $logs[]=$l;
        }

        $invoice=null;
        if ($row['ASSIGN_ID']) {
            $aid=(int)$row['ASSIGN_ID'];
            $inv_r=mysqli_fetch_assoc(mysqli_query($conn,"SELECT i.*,GROUP_CONCAT(CONCAT(il.DESCRIPTION,'|',il.QUANTITY,'|',il.UNIT_PRICE,'|',il.LINE_TOTAL) SEPARATOR ';;') AS LINES FROM invoice i LEFT JOIN invoice_line il ON il.INVOICE_ID=i.INVOICE_ID WHERE i.ASSIGN_ID=$aid AND i.CLIENT_ID=$client_id GROUP BY i.INVOICE_ID ORDER BY i.INVOICE_ID DESC LIMIT 1"));
            if ($inv_r) $invoice=$inv_r;
        }

        $confirmation=null;
        $cc=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM client_confirmations WHERE fault_id=$fid AND client_id=$client_id ORDER BY id DESC LIMIT 1"));
        if ($cc) $confirmation=$cc;

        echo json_encode(['fault'=>$row,'logs'=>$logs,'invoice'=>$invoice,'confirmation'=>$confirmation]); exit;
    }

    if ($action === 'confirm_completion' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data   = json_decode(file_get_contents('php://input'),true);
        $fid    = (int)($data['fault_id']??0);
        $status = $data['status']==='Confirmed' ? 'Confirmed' : 'Rejected';
        $notes  = mysqli_real_escape_string($conn, trim($data['notes']??''));
        $now    = date('Y-m-d H:i:s');

        // Check fault belongs to client
        $chk=mysqli_fetch_assoc(mysqli_query($conn,"SELECT STATUS FROM reported_fault WHERE REP_FAULT_ID=$fid AND CLIENT_ID=$client_id"));
        if (!$chk) { echo json_encode(['success'=>false,'error'=>'Fault not found']); exit; }

        mysqli_query($conn,"INSERT INTO client_confirmations (fault_id,client_id,confirmation_status,confirmation_notes,confirmed_at) VALUES ($fid,$client_id,'$status','$notes','$now') ON DUPLICATE KEY UPDATE confirmation_status='$status',confirmation_notes='$notes',confirmed_at='$now'");

        if ($status==='Confirmed') {
            mysqli_query($conn,"UPDATE reported_fault SET STATUS='Client Approved' WHERE REP_FAULT_ID=$fid");
            // Notify accountants
            $acc_res=mysqli_query($conn,"SELECT EMP_ID FROM employee WHERE ROLE='Accountant'");
            while($acc=mysqli_fetch_assoc($acc_res)){
                $aid=$acc['EMP_ID'];
                mysqli_query($conn,"INSERT INTO notifications (user_id,user_type,title,message) VALUES ($aid,'Employee','Fault Approved – Ready for Invoice','Client approved fault #$fid. Please generate the invoice.')");
            }
            echo json_encode(['success'=>true,'message'=>'Work approved. Accountant has been notified to generate your invoice.']);
        } else {
            mysqli_query($conn,"UPDATE reported_fault SET STATUS='Rework Required' WHERE REP_FAULT_ID=$fid");
            // Notify technician
            $a_r=mysqli_fetch_assoc(mysqli_query($conn,"SELECT at2.EMP_ID FROM assignment a JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID WHERE a.REP_FAULT_ID=$fid LIMIT 1"));
            if ($a_r) {
                $tid=$a_r['EMP_ID'];
                mysqli_query($conn,"INSERT INTO notifications (user_id,user_type,title,message) VALUES ($tid,'Employee','Rework Required','Client rejected completion for fault #$fid. Reason: $notes')");
            }
            echo json_encode(['success'=>true,'message'=>'Rejection submitted. Technician will be notified to rework.']);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$notif_res   = mysqli_query($conn,"SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=$client_id AND user_type='Client' AND is_read=0");
$notif_count = mysqli_fetch_assoc($notif_res)['cnt'] ?? 0;
$msg_res     = mysqli_query($conn,"SELECT COUNT(*) AS cnt FROM unified_messages WHERE to_id=$client_id AND to_type='Client' AND is_read=0");
$msg_count   = mysqli_fetch_assoc($msg_res)['cnt'] ?? 0;

$current_page = 'client_repair_progress';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Busiquip – Repair Progress</title>
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
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;font-size:15px}
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
.fault-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem;margin-bottom:.85rem;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
.fault-card:hover{border-color:var(--accent);box-shadow:0 4px 20px rgba(240,165,0,.1);transform:translateY(-1px)}
.fault-card.completed-ready{border-left:4px solid var(--warning)}
.fault-ref{font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.fault-title{font-size:1rem;font-weight:700;margin:.3rem 0 .5rem}
.fault-meta{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center}
.meta-item{display:flex;align-items:center;gap:.3rem;font-size:.78rem;color:var(--text2)}
/* Progress stepper */
.stepper{display:flex;gap:0;margin:1.5rem 0;overflow-x:auto;padding-bottom:.5rem}
.step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:80px;position:relative}
.step:not(:last-child):after{content:'';position:absolute;top:16px;left:50%;width:100%;height:2px;background:var(--border);z-index:0}
.step.done:not(:last-child):after{background:var(--success)}
.step.active:not(:last-child):after{background:linear-gradient(90deg,var(--accent),var(--border))}
.step-circle{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;z-index:1;border:2px solid var(--border);background:var(--surface2);flex-shrink:0}
.step.done .step-circle{background:var(--success);border-color:var(--success);color:#fff}
.step.active .step-circle{background:var(--accent);border-color:var(--accent);color:#000}
.step-label{font-size:.68rem;color:var(--text2);text-align:center;margin-top:.4rem;max-width:70px;line-height:1.3}
.step.done .step-label{color:var(--success)}.step.active .step-label{color:var(--accent);font-weight:600}
/* Timeline */
.tl-item{display:flex;gap:1rem;padding:.75rem 0;position:relative}
.tl-item:not(:last-child):before{content:'';position:absolute;left:11px;top:2.5rem;bottom:-.5rem;width:2px;background:var(--border)}
.tl-dot{width:24px;height:24px;border-radius:50%;background:var(--surface2);border:2px solid var(--accent);display:flex;align-items:center;justify-content:center;font-size:.65rem;flex-shrink:0;margin-top:.1rem}
.tl-dot.done{background:var(--success);border-color:var(--success)}
.tl-content{flex:1}.tl-action{font-size:.875rem;font-weight:600}.tl-meta{font-size:.75rem;color:var(--text2);margin-top:.15rem}
.tl-note{font-size:.82rem;color:var(--text2);margin-top:.3rem;padding:.5rem .75rem;background:var(--surface2);border-radius:6px;border-left:3px solid var(--border)}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:500;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.show{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:720px;max-height:92vh;overflow-y:auto;animation:fadeIn .25s ease}
.modal-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-size:1.1rem;font-weight:700}
.modal-body{padding:1.5rem}
.modal-footer{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;gap:.75rem;justify-content:flex-end}
.close-btn{background:transparent;border:none;color:var(--text2);font-size:1.3rem;cursor:pointer;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center}
.close-btn:hover{background:var(--surface2);color:var(--text)}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--border);gap:1rem}
.info-row:last-child{border-bottom:none}
.info-label{font-size:.78rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;min-width:130px}
.info-value{font-size:.88rem;text-align:right;flex:1}
.alert-box{padding:.85rem 1rem;border-radius:8px;font-size:.875rem;display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem}
.alert-warning{background:rgba(210,153,34,.12);border:1px solid rgba(210,153,34,.3);color:var(--warning)}
.alert-success{background:rgba(63,185,80,.12);border:1px solid rgba(63,185,80,.3);color:var(--success)}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.65rem 1rem;color:var(--text);font-size:.9rem;outline:none;transition:border .2s;font-family:inherit}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(240,165,0,.1)}
.quote-table{width:100%;border-collapse:collapse;margin-top:.75rem}
.quote-table th{background:var(--surface2);color:var(--text2);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:.6rem .85rem;text-align:left}
.quote-table td{padding:.6rem .85rem;border-top:1px solid var(--border);font-size:.85rem}
.quote-total{font-size:1.1rem;font-weight:700;color:var(--accent);text-align:right;padding:.85rem;border-top:2px solid var(--border)}
@media(max-width:600px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}}

</style>
</head>
<body>
<div id="toast-container"></div>

<!-- PORTAL HEADER -->
<header class="portal-header">
    <a href="client_portal.php" class="brand">
        <div class="brand-ic">⚙️</div>
        <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
    </a>
    <button class="mob-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('mob-open')">
        <i class="fas fa-bars"></i>
    </button>
    <div class="h-page-title">Repair Progress<span>Live Updates &amp; Work Logs</span></div>
    <div class="h-right">
        <div class="h-nm"><div class="n"><?php echo htmlspecialchars($client_name); ?></div><div class="e"><?php echo htmlspecialchars($client_email ?? ''); ?></div></div>
        <div class="h-av" onclick="window.location.href='client_profile.php'" title="My Profile">
            <?php echo strtoupper(substr($client_name,0,1)); ?>
        </div>
        <a href="client_portal.php" class="hb" title="Dashboard"><i class="fas fa-home"></i></a>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
        </form>
    </div>
    <img src="../../images/logo.png" alt="Busiquip Logo" style="height:52px;width:auto;max-width:150px;object-fit:contain;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 4px 14px rgba(232,184,75,.3);flex-shrink:0;margin-left:8px">
</header>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="slbl">Main Menu</div>
    <a href="client_portal.php"          class="ni <?php echo ($current_page==='client_portal')?'act':''; ?>"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</a>
    <a href="client_profile.php"         class="ni <?php echo ($current_page==='client_profile')?'act':''; ?>"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</a>
    <a href="report_fault.php"           class="ni"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></a>

    <div class="slbl" style="margin-top:8px">Equipment</div>
    <a href="client_faults.php"          class="ni <?php echo ($current_page==='client_faults')?'act':''; ?>"><div class="ic"><i class="fas fa-tools"></i></div> My Faults</a>
    <a href="client_repair_progress.php" class="ni <?php echo ($current_page==='client_repair_progress')?'act':''; ?>"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</a>
    <a href="client_products.php"        class="ni <?php echo ($current_page==='client_products')?'act':''; ?>"><div class="ic"><i class="fas fa-box-open"></i></div> My Products</a>

    <div class="slbl" style="margin-top:8px">Finance</div>
    <a href="client_invoices.php"        class="ni <?php echo ($current_page==='client_invoices')?'act':''; ?>"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices</a>
    <a href="client_invoices.php"        class="ni"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment</a>

    <div class="slbl" style="margin-top:8px">More</div>
    <button class="exp-btn" id="exp-btn" onclick="document.getElementById('sub-menu').classList.toggle('open');this.classList.toggle('open')">
        <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
    </button>
    <div class="sub-menu" id="sub-menu">
        <a class="si" href="client_notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a class="si" href="client_documents.php" <?php echo ($current_page==='client_documents')?'style="color:var(--gold)"':''; ?>><i class="fas fa-folder"></i> Documents</a>
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


<!-- MAIN -->
<div class="portal-main">
  <header class="topbar">
    <div class="topbar-title">Repair Progress <span>/ Live Tracking</span></div>
    <div class="topbar-actions">
      <a href="client_documents.php" class="icon-btn" title="Documents"><i class="ti ti-file-description"></i></a>
      <a href="client_profile.php" class="icon-btn" title="Profile">
        <i class="ti ti-user-circle"></i>
        <?php if ($notif_count>0): ?><span class="badge"><?= $notif_count ?></span><?php endif; ?>
      </a>
    </div>
  </header>

  <div class="page-content">
    <div class="page-head">
      <div>
        <h1><i class="ti ti-loader" style="color:var(--accent)"></i> Repair Progress</h1>
        <p>Track all active repair requests in real time.</p>
      </div>
      <a href="report_fault.php" class="btn btn-primary"><i class="ti ti-plus"></i> Report New Fault</a>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
      <button class="btn btn-secondary btn-sm active-filter" onclick="setFilter('all',this)">All Active</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Pending',this)">Pending</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Assigned',this)">Assigned</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('In Progress',this)">In Progress</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Completed',this)">Completed</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Rework Required',this)">Rework</button>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" onclick="loadFaults()"><i class="ti ti-refresh"></i> Refresh</button>
    </div>

    <div id="faultList"><div style="text-align:center;padding:3rem;color:var(--text2)"><i class="ti ti-loader ti-spin" style="font-size:2rem"></i><p style="margin-top:.75rem">Loading repairs...</p></div></div>
  </div>
</div>

<!-- DETAIL MODAL -->
<div class="modal-overlay" id="detailModal">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modalTitle">Fault Details</h3>
      <button class="close-btn" onclick="closeModal()"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body" id="modalBody">Loading...</div>
    <div class="modal-footer" id="modalFooter"></div>
  </div>
</div>

<!-- CONFIRM MODAL -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3 id="confirmTitle">Review Completed Work</h3>
      <button class="close-btn" onclick="document.getElementById('confirmModal').classList.remove('show')"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.875rem;color:var(--text2);margin-bottom:1rem">Has the technician completed the work to your satisfaction?</p>
      <div class="form-group">
        <label style="display:block;font-size:.8rem;font-weight:600;color:var(--text2);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em">Notes / Feedback (optional)</label>
        <textarea id="confirmNotes" class="form-control" rows="3" placeholder="Describe any issues or comments..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" onclick="submitConfirm('Rejected')"><i class="ti ti-x"></i> Reject & Request Rework</button>
      <button class="btn btn-success" onclick="submitConfirm('Confirmed')"><i class="ti ti-check"></i> Approve Completion</button>
    </div>
  </div>
</div>

<script>
let currentFilter='all', currentFaultId=0, allFaults=[];

function showToast(msg,type='info'){
  const c=document.getElementById('toast-container');
  const t=document.createElement('div');t.className='toast '+type;
  const icons={success:'circle-check',error:'circle-x',info:'info-circle'};
  t.innerHTML=`<i class="ti ti-${icons[type]}" style="font-size:1.1rem"></i><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(()=>{t.style.animation='slideOut .3s ease forwards';setTimeout(()=>t.remove(),300)},3500);
}

function statusBadge(s){
  const map={'Pending':'badge-pending','Assigned':'badge-assigned','In Progress':'badge-progress','Completed':'badge-completed','Client Approved':'badge-approved','Rework Required':'badge-rework','Rejected':'badge-rejected'};
  return `<span class="badge ${map[s]||'badge-pending'}">${s}</span>`;
}

function priorityBadge(p){
  const map={High:'badge-high',Medium:'badge-medium',Low:'badge-low',Critical:'badge-rejected'};
  return `<span class="badge ${map[p]||'badge-medium'}">${p||'N/A'}</span>`;
}

function stepperSteps(status){
  const steps=['Pending','Assigned','In Progress','Completed','Client Approved'];
  const idx=steps.indexOf(status);
  return steps.map((s,i)=>{
    const cls=i<idx?'done':i===idx?'active':'';
    const icon=i<idx?'check':i===idx?'loader':'clock';
    return `<div class="step ${cls}">
      <div class="step-circle"><i class="ti ti-${icon}"></i></div>
      <div class="step-label">${s}</div>
    </div>`;
  }).join('');
}

function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

function setFilter(f,btn){
  currentFilter=f;
  document.querySelectorAll('.filter-bar .btn').forEach(b=>b.classList.remove('active-filter'));
  btn.classList.add('active-filter');
  renderFaults();
}

async function loadFaults(){
  const res=await fetch('client_repair_progress.php?action=get_active_faults');
  allFaults=await res.json();
  renderFaults();
}

function renderFaults(){
  const list=document.getElementById('faultList');
  let data=allFaults;
  if(currentFilter!=='all') data=data.filter(f=>f.STATUS===currentFilter);
  if(!data.length){
    list.innerHTML=`<div style="text-align:center;padding:3rem;color:var(--text2)"><i class="ti ti-tools" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4"></i><p>No repairs matching this filter.</p></div>`;
    return;
  }
  list.innerHTML=data.map(f=>{
    const needsReview=f.STATUS==='Completed';
    const desc=(f.DESCRIPTION||'').split('\n')[0].substring(0,80);
    return `<div class="fault-card ${needsReview?'completed-ready':''}" onclick="openDetail(${f.REP_FAULT_ID})">
      ${needsReview?`<div style="position:absolute;top:0;right:0;background:var(--warning);color:#000;font-size:.7rem;font-weight:700;padding:.2rem .75rem;border-radius:0 12px 0 8px">⚠ REVIEW REQUIRED</div>`:''}
      <div class="fault-ref">Fault #${f.REP_FAULT_ID} · ${new Date(f.REPORT_DATE).toLocaleDateString()}</div>
      <div class="fault-title">${escHtml(desc||'No description')}</div>
      <div class="fault-meta">
        ${statusBadge(f.STATUS)}
        ${priorityBadge(f.PRIORITY)}
        ${f.TECHNICIANS?`<span class="meta-item"><i class="ti ti-user"></i>${escHtml(f.TECHNICIANS)}</span>`:''}
        ${f.DUE_DATE?`<span class="meta-item"><i class="ti ti-calendar"></i>Due: ${new Date(f.DUE_DATE).toLocaleDateString()}</span>`:''}
        ${f.LOG_COUNT>0?`<span class="meta-item"><i class="ti ti-list"></i>${f.LOG_COUNT} work log${f.LOG_COUNT>1?'s':''}</span>`:''}
      </div>
    </div>`;
  }).join('');
}

async function openDetail(fid){
  currentFaultId=fid;
  document.getElementById('detailModal').classList.add('show');
  document.getElementById('modalBody').innerHTML='<div style="text-align:center;padding:2rem;color:var(--text2)"><i class="ti ti-loader ti-spin" style="font-size:2rem"></i></div>';
  document.getElementById('modalFooter').innerHTML='';

  const res=await fetch(`client_repair_progress.php?action=get_fault_detail&id=${fid}`);
  const {fault:f,logs,invoice,confirmation}=await res.json();
  if(f.error){document.getElementById('modalBody').innerHTML='<p style="color:var(--danger)">Error loading details.</p>';return;}

  document.getElementById('modalTitle').textContent=`Fault #${f.REP_FAULT_ID} — Progress`;

  // Parse description lines
  const lines=(f.DESCRIPTION||'').split('\n');
  const desc=lines.filter(l=>!l.includes(':')&&!l.startsWith('FAULT REFERENCE')).join(' ').trim();
  const meta=lines.filter(l=>l.includes(':')).reduce((acc,l)=>{const[k,...v]=l.split(':');acc[k.trim()]=v.join(':').trim();return acc;},{});

  let html=`
    <!-- Stepper -->
    <div class="stepper">${stepperSteps(f.STATUS)}</div>

    <!-- Confirmation alert -->
    ${f.STATUS==='Completed'&&!confirmation?`<div class="alert-box alert-warning"><i class="ti ti-alert-triangle" style="font-size:1.2rem;flex-shrink:0"></i><div><strong>Action Required</strong><br>The technician has completed the work. Please review and approve or request rework.</div></div>`:''}
    ${confirmation&&confirmation.confirmation_status==='Confirmed'?`<div class="alert-box alert-success"><i class="ti ti-circle-check" style="font-size:1.2rem;flex-shrink:0"></i><div><strong>You approved this work.</strong> Invoice is being prepared.</div></div>`:''}

    <!-- Fault Info -->
    <div style="margin-bottom:1.25rem">
      <div class="card-title" style="font-size:.95rem;font-weight:600;margin-bottom:.75rem"><i class="ti ti-info-circle" style="color:var(--accent)"></i> Fault Details</div>
      <div class="info-row"><span class="info-label">Status</span><span class="info-value">${statusBadge(f.STATUS)}</span></div>
      <div class="info-row"><span class="info-label">Priority</span><span class="info-value">${priorityBadge(f.PRIORITY)}</span></div>
      <div class="info-row"><span class="info-label">Reported</span><span class="info-value">${new Date(f.REPORT_DATE).toLocaleString()}</span></div>
      ${f.REPORTED_BY?`<div class="info-row"><span class="info-label">Reported By</span><span class="info-value">${escHtml(f.REPORTED_BY)}</span></div>`:''}
      ${meta['FAULT TITLE']?`<div class="info-row"><span class="info-label">Fault Title</span><span class="info-value">${escHtml(meta['FAULT TITLE'])}</span></div>`:''}
      ${meta['CATEGORY']?`<div class="info-row"><span class="info-label">Category</span><span class="info-value">${escHtml(meta['CATEGORY'])}</span></div>`:''}
      ${meta['EQUIPMENT TYPE']?`<div class="info-row"><span class="info-label">Equipment</span><span class="info-value">${escHtml(meta['EQUIPMENT TYPE'])}</span></div>`:''}
      ${meta['BRAND/MODEL']?`<div class="info-row"><span class="info-label">Brand/Model</span><span class="info-value">${escHtml(meta['BRAND/MODEL'])}</span></div>`:''}
      ${f.TECHNICIANS?`<div class="info-row"><span class="info-label">Technician(s)</span><span class="info-value">${escHtml(f.TECHNICIANS)}</span></div>`:''}
      ${f.DUE_DATE?`<div class="info-row"><span class="info-label">Due Date</span><span class="info-value">${new Date(f.DUE_DATE).toLocaleDateString()}</span></div>`:''}
    </div>`;

  // Work Logs
  if(logs.length){
    html+=`<div style="margin-bottom:1.25rem">
      <div class="card-title" style="font-size:.95rem;font-weight:600;margin-bottom:.75rem"><i class="ti ti-list-check" style="color:var(--accent)"></i> Work Logs (${logs.length})</div>
      ${logs.map(l=>`<div class="tl-item">
        <div class="tl-dot done"><i class="ti ti-check"></i></div>
        <div class="tl-content">
          <div class="tl-action">${escHtml(l.LOG_TYPE||'Update')} ${l.HOURS_SPENT>0?`— ${l.HOURS_SPENT}h`:''}</div>
          <div class="tl-meta">${escHtml(l.EMP_NAME||'Technician')} · ${new Date(l.LOG_DATE).toLocaleString()}</div>
          ${l.ACTION_TAKEN?`<div class="tl-note">${escHtml(l.ACTION_TAKEN)}</div>`:''}
        </div>
      </div>`).join('')}
    </div>`;
  }

  // Quotation
  if(invoice){
    const lines_raw=(invoice.LINES||'').split(';;').filter(Boolean);
    const lineRows=lines_raw.map(l=>{const[desc,qty,unit,total]=l.split('|');return `<tr><td>${escHtml(desc)}</td><td style="text-align:center">${qty}</td><td style="text-align:right">E${parseFloat(unit).toFixed(2)}</td><td style="text-align:right">E${parseFloat(total).toFixed(2)}</td></tr>`;}).join('');
    html+=`<div style="margin-bottom:1.25rem">
      <div class="card-title" style="font-size:.95rem;font-weight:600;margin-bottom:.75rem"><i class="ti ti-file-invoice" style="color:var(--accent)"></i> Quotation Summary</div>
      <table class="quote-table">
        <thead><tr><th>Description</th><th style="text-align:center">Qty</th><th style="text-align:right">Unit</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>${lineRows}</tbody>
      </table>
      <div class="quote-total">Total: E${parseFloat(invoice.TOTAL||0).toLocaleString('en',{minimumFractionDigits:2})}</div>
      <div style="font-size:.78rem;color:var(--text2);margin-top:.3rem">Invoice Status: <strong>${invoice.INVOICE_STATUS||invoice.STATUS||'—'}</strong></div>
    </div>`;
  }

  document.getElementById('modalBody').innerHTML=html;

  // Footer buttons
  let footer='<button class="btn btn-secondary" onclick="closeModal()"><i class="ti ti-x"></i> Close</button>';
  if(f.STATUS==='Completed'&&!confirmation){
    footer+=`<button class="btn btn-primary" onclick="openConfirmDialog()"><i class="ti ti-clipboard-check"></i> Review Work</button>`;
  }
  document.getElementById('modalFooter').innerHTML=footer;
}

function closeModal(){document.getElementById('detailModal').classList.remove('show');}

function openConfirmDialog(){
  closeModal();
  document.getElementById('confirmModal').classList.add('show');
  document.getElementById('confirmNotes').value='';
}

async function submitConfirm(status){
  const notes=document.getElementById('confirmNotes').value;
  const res=await fetch('client_repair_progress.php?action=confirm_completion',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({fault_id:currentFaultId,status,notes})
  });
  const d=await res.json();
  document.getElementById('confirmModal').classList.remove('show');
  if(d.success){showToast(d.message,'success');loadFaults();}
  else showToast(d.error||'Error','error');
}

loadFaults();
setInterval(loadFaults, 30000); // Auto-refresh every 30s
</script>
</body>
</html>