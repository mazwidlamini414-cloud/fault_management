<?php
// ═══════════════════════════════════════════════════════════════════════
//  FILE PATH: fault_management/modules/clients/client_documents.php
//  BUSIQUIP ESWATINI — Client Documents: Invoices, Receipts, Quotations
//  Generates full printable/downloadable documents with company letterhead
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors',1); error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['client_id'])) { header('Location: client_login.php'); exit; }

$client_id   = (int)$_SESSION['client_id'];
$client_name = $_SESSION['client_name'] ?? 'Client';
$client_type = $_SESSION['client_type'] ?? 'CORPORATE';

require_once '../../config/database.php';

// ── AJAX ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Get all documents (invoices + receipts)
    if ($action === 'get_documents') {
        $res = mysqli_query($conn,"
            SELECT i.*,
                   rf.REP_FAULT_ID, rf.DESCRIPTION AS FAULT_DESC, rf.STATUS AS FAULT_STATUS,
                   COALESCE(SUM(p.AMOUNT_PAID),0) AS PAID_AMOUNT_SUM,
                   COUNT(DISTINCT p.PAYMENT_ID) AS PAYMENT_COUNT,
                   r.RECEIPT_ID
            FROM invoice i
            LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID
            LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
            LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID
            LEFT JOIN receipt r ON r.PAYMENT_ID=(SELECT PAYMENT_ID FROM payment WHERE INVOICE_ID=i.INVOICE_ID AND STATUS='Verified' LIMIT 1)
            WHERE i.CLIENT_ID=$client_id
            GROUP BY i.INVOICE_ID
            ORDER BY i.INVOICE_DATE DESC
        ");
        $rows=[];
        while($r=mysqli_fetch_assoc($res)) $rows[]=$r;
        echo json_encode($rows); exit;
    }

    // Get full document data for printing
    if ($action === 'get_document_data') {
        $iid  = (int)($_GET['id']??0);
        $type = $_GET['type']??'invoice'; // invoice | quotation | receipt

        // Invoice
        $inv=mysqli_fetch_assoc(mysqli_query($conn,"SELECT i.*,a.ASSIGN_ID FROM invoice i LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID WHERE i.INVOICE_ID=$iid AND i.CLIENT_ID=$client_id"));
        if(!$inv){echo json_encode(['error'=>'Not found']);exit;}

        // Lines
        $lines=[];
        $lr=mysqli_query($conn,"SELECT * FROM invoice_line WHERE INVOICE_ID=$iid");
        while($l=mysqli_fetch_assoc($lr)) $lines[]=$l;

        // Client
        $client=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM client WHERE CLIENT_ID=$client_id"));

        // Fault
        $fault=null;
        if($inv['ASSIGN_ID']){
            $aid=(int)$inv['ASSIGN_ID'];
            $fault=mysqli_fetch_assoc(mysqli_query($conn,"SELECT rf.* FROM reported_fault rf JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID WHERE a.ASSIGN_ID=$aid LIMIT 1"));
        }

        // Technician
        $tech=null;
        if($inv['ASSIGN_ID']){
            $aid=(int)$inv['ASSIGN_ID'];
            $tech=mysqli_fetch_assoc(mysqli_query($conn,"SELECT e.FULL_NAME,e.EMAIL FROM employee e JOIN assignment_technician at2 ON at2.EMP_ID=e.EMP_ID WHERE at2.ASSIGN_ID=$aid LIMIT 1"));
        }

        // Payment
        $payment=null;
        $receipt=null;
        $pr=mysqli_query($conn,"SELECT * FROM payment WHERE INVOICE_ID=$iid ORDER BY PAYMENT_DATE DESC LIMIT 1");
        if($pr) $payment=mysqli_fetch_assoc($pr);
        if($payment){
            $pid=(int)$payment['PAYMENT_ID'];
            $rr=mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM receipt WHERE PAYMENT_ID=$pid LIMIT 1"));
            if($rr) $receipt=$rr;
        }

        // Work logs
        $logs=[];
        if($inv['ASSIGN_ID']){
            $aid=(int)$inv['ASSIGN_ID'];
            $wr=mysqli_query($conn,"SELECT wl.*,e.FULL_NAME FROM work_log wl LEFT JOIN employee e ON e.EMP_ID=wl.EMP_ID WHERE wl.ASSIGN_ID=$aid ORDER BY wl.LOG_DATE ASC");
            while($l=mysqli_fetch_assoc($wr)) $logs[]=$l;
        }

        echo json_encode(['invoice'=>$inv,'lines'=>$lines,'client'=>$client,'fault'=>$fault,'tech'=>$tech,'payment'=>$payment,'receipt'=>$receipt,'logs'=>$logs]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$notif_res   = mysqli_query($conn,"SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=$client_id AND user_type='Client' AND is_read=0");
$notif_count = mysqli_fetch_assoc($notif_res)['cnt'] ?? 0;

$current_page = 'client_documents';
$page_heading = 'Documents';
$page_subheading = 'Invoices · Quotations · Receipts';
// nav handled by shared sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Busiquip – Documents</title>
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
/* ── PORTAL HEADER ──────────────────────────────────────────────────── */
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
/* ── SIDEBAR ─────────────────────────────────────────────────────────── */
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
/* ── MAIN CONTENT AREA ───────────────────────────────────────────────── */
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
/* ═══ PAGE-SPECIFIC STYLES ═══ */
.divider{border:none;border-top:1px solid var(--bor);margin:1rem 0}
.filter-bar{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem}
.doc-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem;display:flex;gap:1rem;align-items:flex-start;margin-bottom:.75rem;transition:all .2s}
.doc-card:hover{border-color:var(--accent);box-shadow:0 4px 16px rgba(240,165,0,.08)}
.doc-icon-box{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.doc-icon-box.invoice{background:rgba(88,166,255,.15);color:var(--info)}
.doc-icon-box.quotation{background:rgba(240,165,0,.15);color:var(--accent)}
.doc-icon-box.receipt{background:rgba(63,185,80,.15);color:var(--success)}
.doc-main{flex:1;min-width:0}
.doc-ref{font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.doc-title{font-size:.95rem;font-weight:700;margin:.25rem 0 .5rem}
.doc-meta{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;font-size:.8rem;color:var(--text2)}
.doc-actions{display:flex;flex-direction:column;gap:.4rem;flex-shrink:0}
/* ── PRINT MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto}
.modal-overlay.show{display:flex}
.modal{background:#fff;border-radius:4px;width:100%;max-width:800px;margin:auto;color:#000;position:relative}
.modal-toolbar{background:var(--surface);border-radius:12px;padding:.75rem 1rem;display:flex;gap:.5rem;align-items:center;margin-bottom:.75rem;border:1px solid var(--border)}
/* ── DOCUMENT STYLES (print inside modal) ── */
.doc-paper{padding:40px 50px;font-family:'Segoe UI',Arial,sans-serif;color:#1a1a2e;background:#fff;min-height:900px}
.doc-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:24px;border-bottom:3px solid #f0a500;margin-bottom:24px}
.company-logo-box{display:flex;flex-direction:column}
.company-logo-mark{width:52px;height:52px;background:#f0a500;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.4rem;color:#000;margin-bottom:8px}
.company-name{font-size:1.3rem;font-weight:800;color:#1a1a2e;letter-spacing:-.02em}
.company-sub{font-size:.8rem;color:#6b7280;margin-top:2px}
.company-contact{font-size:.78rem;color:#6b7280;margin-top:8px;line-height:1.7}
.doc-type-badge{text-align:right}
.doc-type-badge h1{font-size:2rem;font-weight:900;color:#f0a500;letter-spacing:-.02em;text-transform:uppercase}
.doc-type-badge .ref{font-size:.8rem;color:#6b7280;margin-top:4px}
.doc-type-badge .doc-status{display:inline-block;padding:.3rem .85rem;border-radius:99px;font-size:.75rem;font-weight:700;margin-top:8px}
.doc-status.paid{background:#d1fae5;color:#065f46}
.doc-status.pending{background:#fef3c7;color:#92400e}
.doc-status.overdue{background:#fee2e2;color:#991b1b}
.doc-status.submitted{background:#dbeafe;color:#1e40af}
.billing-row{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-bottom:24px;padding:20px 24px;background:#f9fafb;border-radius:8px;border-left:4px solid #f0a500}
.billing-section h4{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:10px}
.billing-section p{font-size:.875rem;color:#374151;line-height:1.7;margin:0}
.billing-section strong{color:#111827;font-size:.95rem}
.doc-meta-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.meta-cell{padding:12px 16px;background:#f9fafb;border-radius:6px;text-align:center}
.meta-cell .label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:4px}
.meta-cell .value{font-size:.875rem;font-weight:700;color:#111827}
.items-table{width:100%;border-collapse:collapse;margin-bottom:0}
.items-table thead{background:#1a1a2e;color:#fff}
.items-table th{padding:10px 14px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;text-align:left}
.items-table td{padding:10px 14px;font-size:.875rem;border-bottom:1px solid #e5e7eb;color:#374151}
.items-table tbody tr:nth-child(even){background:#f9fafb}
.items-table tfoot{background:#fff}
.total-section{margin-top:0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
.total-row{display:flex;justify-content:space-between;padding:8px 16px;font-size:.875rem;color:#374151;border-bottom:1px solid #e5e7eb}
.total-row.grand{background:#1a1a2e;color:#fff;font-weight:800;font-size:1rem;padding:12px 16px;border-bottom:none}
.total-row.grand span:last-child{color:#f0a500;font-size:1.1rem}
.vat-note{font-size:.72rem;color:#9ca3af;padding:6px 16px;background:#f9fafb;border-bottom:1px solid #e5e7eb}
.payment-section{margin-top:24px;padding:16px 20px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb}
.payment-section h4{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px}
.payment-detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.pay-cell .label{font-size:.68rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.pay-cell .value{font-size:.875rem;font-weight:700;color:#111827;margin-top:2px}
.fault-section{margin-top:20px;padding:16px 20px;border:1px dashed #d1d5db;border-radius:8px}
.fault-section h4{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:10px}
.fault-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.fd-row{font-size:.82rem;color:#374151;display:flex;gap:8px}
.fd-label{color:#9ca3af;min-width:100px;font-weight:600}
.doc-footer{margin-top:40px;padding-top:16px;border-top:2px solid #f0a500;display:flex;justify-content:space-between;align-items:flex-end}
.doc-footer .company-sign{font-size:.8rem;color:#6b7280}
.doc-footer .company-sign strong{display:block;color:#111827;font-size:.875rem;margin-bottom:4px}
.stamp-box{width:90px;height:60px;border:2px dashed #d1d5db;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#d1d5db;text-align:center}
.receipt-stamp{background:#d1fae5;border:2px solid #059669;border-radius:8px;padding:10px 20px;text-align:center;color:#065f46;font-weight:800;font-size:1rem;letter-spacing:.05em}
.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:5rem;font-weight:900;color:rgba(240,165,0,.05);pointer-events:none;white-space:nowrap;text-transform:uppercase;letter-spacing:.1em}
@media print{
  body{background:#fff!important}
  .sidebar,.topbar,.modal-toolbar,.doc-actions,.filter-bar,.page-head,#toast-container{display:none!important}
  .modal-overlay{position:static!important;background:transparent!important;padding:0!important}
  .modal{box-shadow:none!important;max-width:100%!important}
  .main{margin-left:0!important}
  @page{margin:0;size:A4}
}
@media(max-width:600px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}.doc-meta-grid{grid-template-columns:repeat(2,1fr)}.payment-detail-grid{grid-template-columns:1fr 1fr}.billing-row{grid-template-columns:1fr}.doc-footer{flex-direction:column;gap:1rem}}
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
    <div class="h-page-title"><?php echo $page_heading ?? '&nbsp;'; ?><span><?php echo $page_subheading ?? ''; ?></span></div>
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
  <!-- page topbar replaced by portal-header above -->
    <div class="topbar-title">Documents <span>/ Invoices · Quotations · Receipts</span></div>
    <div class="topbar-actions">
      <a href="client_profile.php" class="icon-btn">
        <i class="ti ti-user-circle"></i>
        <?php if ($notif_count>0): ?><span class="badge"><?= $notif_count ?></span><?php endif; ?>
      </a>
    </div>
  </header>

  <div class="page-content">
    <div class="page-head">
      <div>
        <h1><i class="ti ti-file-description" style="color:var(--accent)"></i> My Documents</h1>
        <p>View, download and print all invoices, quotations, and payment receipts.</p>
      </div>
      <button class="btn btn-secondary btn-sm" onclick="loadDocuments()"><i class="ti ti-refresh"></i> Refresh</button>
    </div>

    <!-- Stats row -->
    <div style="display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap" id="docStats">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(88,166,255,.15);color:var(--info);display:flex;align-items:center;justify-content:center"><i class="ti ti-file-invoice"></i></div>
        <div><div id="statTotal" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Total Documents</div></div>
      </div>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(210,153,34,.15);color:var(--warning);display:flex;align-items:center;justify-content:center"><i class="ti ti-clock"></i></div>
        <div><div id="statPending" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Pending Payment</div></div>
      </div>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(63,185,80,.15);color:var(--success);display:flex;align-items:center;justify-content:center"><i class="ti ti-cash"></i></div>
        <div><div id="statPaid" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Paid</div></div>
      </div>
    </div>

    <!-- Filter -->
    <div class="filter-bar">
      <button class="btn btn-secondary btn-sm" onclick="setFilter('all',this)" style="background:var(--accent);color:#000">All</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Invoice',this)">Invoices</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Quotation',this)">Quotations</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('receipt',this)">Receipts</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Paid',this)">Paid</button>
      <button class="btn btn-secondary btn-sm" onclick="setFilter('Pending',this)">Pending</button>
    </div>

    <div id="docList"><div style="text-align:center;padding:3rem;color:var(--text2)"><i class="ti ti-loader ti-spin" style="font-size:2rem"></i><p style="margin-top:.75rem">Loading documents...</p></div></div>
  </div>
</div>

<!-- PRINT MODAL -->
<div class="modal-overlay" id="printModal">
  <div style="width:100%;max-width:840px;margin:auto">
    <div class="modal-toolbar">
      <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="ti ti-printer"></i> Print</button>
      <button class="btn btn-secondary btn-sm" onclick="downloadPDF()"><i class="ti ti-download"></i> Download PDF</button>
      <span style="flex:1"></span>
      <button class="btn btn-secondary btn-sm" onclick="closePrint()"><i class="ti ti-x"></i> Close</button>
    </div>
    <div class="modal" id="printContent"><!-- document goes here --></div>
  </div>
</div>

<script>
let allDocs=[], currentFilter='all', currentDocId=0;

function showToast(msg,type='info'){
  const c=document.getElementById('toast-container');const t=document.createElement('div');
  t.className='toast '+type;
  const icons={success:'circle-check',error:'circle-x',info:'info-circle'};
  t.innerHTML=`<i class="ti ti-${icons[type]}" style="font-size:1.1rem"></i><span>${msg}</span>`;
  c.appendChild(t);setTimeout(()=>{t.style.animation='slideOut .3s ease forwards';setTimeout(()=>t.remove(),300)},3500);
}
function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function fmt(n){return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtDate(d){if(!d)return'—';return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'});}

function setFilter(f,btn){
  currentFilter=f;
  document.querySelectorAll('.filter-bar .btn').forEach(b=>{b.style.background='';b.style.color='';});
  btn.style.background='var(--accent)';btn.style.color='#000';
  renderDocs();
}

async function loadDocuments(){
  const res=await fetch('client_documents.php?action=get_documents');
  allDocs=await res.json();
  updateStats();
  renderDocs();
}

function updateStats(){
  document.getElementById('statTotal').textContent=allDocs.length;
  document.getElementById('statPending').textContent=allDocs.filter(d=>d.STATUS==='Pending'||d.STATUS==='Submitted').length;
  document.getElementById('statPaid').textContent=allDocs.filter(d=>d.STATUS==='Paid').length;
}

function statusBadge(s){
  const map={Paid:'badge-paid',Pending:'badge-pending',Submitted:'badge-submitted',Overdue:'badge-overdue'};
  return `<span class="badge ${map[s]||'badge-pending'}">${s}</span>`;
}

function renderDocs(){
  const list=document.getElementById('docList');
  let data=allDocs;
  if(currentFilter==='Invoice') data=data.filter(d=>d.TYPE==='Invoice');
  else if(currentFilter==='Quotation') data=data.filter(d=>d.TYPE==='Quotation');
  else if(currentFilter==='receipt') data=data.filter(d=>d.STATUS==='Paid'&&d.RECEIPT_ID);
  else if(currentFilter==='Paid') data=data.filter(d=>d.STATUS==='Paid');
  else if(currentFilter==='Pending') data=data.filter(d=>d.STATUS==='Pending'||d.STATUS==='Submitted');

  if(!data.length){
    list.innerHTML=`<div style="text-align:center;padding:3rem;color:var(--text2)"><i class="ti ti-files" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4"></i><p>No documents found.</p></div>`;
    return;
  }

  list.innerHTML=data.map(d=>{
    const isQuot=d.TYPE==='Quotation';
    const hasPaid=d.STATUS==='Paid';
    const iconClass=isQuot?'quotation':hasPaid?'receipt':'invoice';
    const iconName=isQuot?'file-text':hasPaid?'receipt':'file-invoice';
    const ref=isQuot?`QUO-${String(d.INVOICE_ID).padStart(4,'0')}`:`INV-${String(d.INVOICE_ID).padStart(4,'0')}`;
    const faultSnippet=(d.FAULT_DESC||'').split('\n').find(l=>l.startsWith('FAULT TITLE:'))||'';
    return `<div class="doc-card">
      <div class="doc-icon-box ${iconClass}"><i class="ti ti-${iconName}"></i></div>
      <div class="doc-main">
        <div class="doc-ref">${d.TYPE} · ${ref}</div>
        <div class="doc-title">${escHtml(faultSnippet.replace('FAULT TITLE:','').trim()||'Service Invoice')} · Fault #${d.REP_FAULT_ID||'—'}</div>
        <div class="doc-meta">
          ${statusBadge(d.STATUS)}
          <span><i class="ti ti-calendar"></i>${fmtDate(d.INVOICE_DATE)}</span>
          <span><i class="ti ti-cash"></i>E ${fmt(d.TOTAL)}</span>
          ${d.DUE_DATE?`<span><i class="ti ti-clock"></i>Due: ${fmtDate(d.DUE_DATE)}</span>`:''}
        </div>
      </div>
      <div class="doc-actions">
        <button class="btn btn-primary btn-sm" onclick="viewDocument(${d.INVOICE_ID},'${d.TYPE}')"><i class="ti ti-eye"></i> View</button>
        <button class="btn btn-secondary btn-sm" onclick="viewDocument(${d.INVOICE_ID},'${d.TYPE}',true)"><i class="ti ti-printer"></i> Print</button>
        ${hasPaid?`<button class="btn btn-secondary btn-sm" onclick="viewDocument(${d.INVOICE_ID},'receipt')"><i class="ti ti-receipt"></i> Receipt</button>`:''}
      </div>
    </div>`;
  }).join('');
}

async function viewDocument(id,type='invoice',autoPrint=false){
  currentDocId=id;
  document.getElementById('printModal').classList.add('show');
  document.getElementById('printContent').innerHTML='<div style="padding:3rem;text-align:center;color:#666"><i class="ti ti-loader" style="font-size:2rem;display:block;margin-bottom:.75rem"></i>Generating document...</div>';

  const res=await fetch(`client_documents.php?action=get_document_data&id=${id}&type=${type}`);
  const data=await res.json();
  if(data.error){document.getElementById('printContent').innerHTML=`<div style="padding:2rem;color:red">${data.error}</div>`;return;}

  const doc=buildDocument(data,type);
  document.getElementById('printContent').innerHTML=doc;
  if(autoPrint) setTimeout(()=>window.print(),400);
}

function buildDocument(data,docType){
  const {invoice:inv,lines,client,fault,tech,payment,receipt,logs}=data;
  const isReceipt=docType==='receipt';
  const isQuot=inv.TYPE==='Quotation';

  const ref=isReceipt?`REC-${String(inv.INVOICE_ID).padStart(4,'0')}`
           :isQuot?`QUO-${String(inv.INVOICE_ID).padStart(4,'0')}`
           :`INV-${String(inv.INVOICE_ID).padStart(4,'0')}`;

  const title=isReceipt?'RECEIPT':isQuot?'QUOTATION':'INVOICE';

  // Status
  const rawStatus=isReceipt?'Paid':inv.STATUS||'Pending';
  const statusClass=rawStatus==='Paid'?'paid':rawStatus==='Pending'||rawStatus==='Submitted'?'pending':'overdue';

  // Subtotal / VAT
  const sub=parseFloat(inv.TOTAL||0);
  const vat=sub*0.15;
  const grand=sub+vat;

  // Parse fault description meta
  const faultLines=(fault?.DESCRIPTION||'').split('\n');
  const fmeta=faultLines.reduce((a,l)=>{const[k,...v]=l.split(':');if(v.length)a[k.trim()]=v.join(':').trim();return a;},{});

  // Line items HTML
  const lineRows=lines.map((l,i)=>`
    <tr>
      <td style="text-align:center">${i+1}</td>
      <td>${escHtml(l.DESCRIPTION||'Service')}</td>
      <td style="text-align:center">${l.QUANTITY||1}</td>
      <td style="text-align:right">E ${fmt(l.UNIT_PRICE)}</td>
      <td style="text-align:right;font-weight:600">E ${fmt(l.LINE_TOTAL)}</td>
    </tr>`).join('');

  // Payment details
  let payHtml='';
  if(payment){
    payHtml=`<div class="payment-section">
      <h4>Payment Details</h4>
      <div class="payment-detail-grid">
        <div class="pay-cell"><div class="label">Payment Date</div><div class="value">${fmtDate(payment.PAYMENT_DATE)}</div></div>
        <div class="pay-cell"><div class="label">Method</div><div class="value">${escHtml(payment.METHOD||'—')}</div></div>
        <div class="pay-cell"><div class="label">Amount Paid</div><div class="value">E ${fmt(payment.AMOUNT_PAID)}</div></div>
        ${payment.REFERENCE_NUMBER?`<div class="pay-cell"><div class="label">Reference</div><div class="value">${escHtml(payment.REFERENCE_NUMBER)}</div></div>`:''}
        <div class="pay-cell"><div class="label">Status</div><div class="value">${escHtml(payment.STATUS||'—')}</div></div>
      </div>
    </div>`;
  }

  // Fault reference section
  let faultHtml='';
  if(fault){
    faultHtml=`<div class="fault-section">
      <h4>Service Reference — Fault #${fault.REP_FAULT_ID}</h4>
      <div class="fault-detail-grid">
        ${fmeta['FAULT TITLE']?`<div class="fd-row"><span class="fd-label">Title</span><span>${escHtml(fmeta['FAULT TITLE'])}</span></div>`:''}
        ${fmeta['CATEGORY']?`<div class="fd-row"><span class="fd-label">Category</span><span>${escHtml(fmeta['CATEGORY'])}</span></div>`:''}
        ${fmeta['EQUIPMENT TYPE']?`<div class="fd-row"><span class="fd-label">Equipment</span><span>${escHtml(fmeta['EQUIPMENT TYPE'])}</span></div>`:''}
        ${fmeta['BRAND/MODEL']?`<div class="fd-row"><span class="fd-label">Brand/Model</span><span>${escHtml(fmeta['BRAND/MODEL'])}</span></div>`:''}
        ${fmeta['FAULT DATE/TIME']?`<div class="fd-row"><span class="fd-label">Fault Date</span><span>${escHtml(fmeta['FAULT DATE/TIME'])}</span></div>`:''}
        ${tech?`<div class="fd-row"><span class="fd-label">Technician</span><span>${escHtml(tech.FULL_NAME)}</span></div>`:''}
        ${fault.STATUS?`<div class="fd-row"><span class="fd-label">Status</span><span>${escHtml(fault.STATUS)}</span></div>`:''}
        ${fmeta['FAULT LOCATION']?`<div class="fd-row"><span class="fd-label">Location</span><span>${escHtml(fmeta['FAULT LOCATION'])}</span></div>`:''}
      </div>
    </div>`;
  }

  return `<div class="doc-paper" style="position:relative">
    <div class="watermark">${isReceipt?'PAID':title}</div>

    <!-- HEADER -->
    <div class="doc-header">
      <div class="company-logo-box">
        <div class="company-logo-mark">B</div>
        <div class="company-name">BUSIQUIP (PTY) LTD</div>
        <div class="company-sub">Eswatini · Business Equipment Solutions</div>
        <div class="company-contact">
          Inyatsi House, Somhlolo Road<br>
          P.O. Box A286, Mbabane, H100<br>
          Kingdom of Eswatini<br>
          Tel: +268 2404 5000<br>
          Email: accounts@busiquip.co.sz<br>
          VAT Reg: E124/0002/19
        </div>
      </div>
      <div class="doc-type-badge">
        <h1>${title}</h1>
        <div class="ref">${ref}</div>
        <div class="ref">Date: ${fmtDate(inv.INVOICE_DATE)}</div>
        ${inv.DUE_DATE?`<div class="ref">Due: ${fmtDate(inv.DUE_DATE)}</div>`:''}
        <div><span class="doc-status ${statusClass}">${rawStatus.toUpperCase()}</span></div>
        ${isReceipt?`<div style="margin-top:12px"><div class="receipt-stamp">✓ PAYMENT CONFIRMED</div></div>`:''}
      </div>
    </div>

    <!-- BILLING -->
    <div class="billing-row">
      <div class="billing-section">
        <h4>Bill To</h4>
        <p>
          <strong>${escHtml(client?.COMPANY_NAME||'—')}</strong><br>
          ${escHtml(client?.CONTACT_PERSON_NAME||'')}${client?.CONTACT_PERSON_NAME?'<br>':''
          }${escHtml(client?.COMPANY_ADDRESS||'')}<br>
          ${escHtml(client?.COMPANY_PHONE||'')}${client?.COMPANY_PHONE?'<br>':''
          }${escHtml(client?.COMPANY_EMAIL||'')}
        </p>
        ${client?.CLIENT_TYPE?`<p style="margin-top:8px;font-size:.78rem;color:#9ca3af">Account Type: <strong>${escHtml(client.CLIENT_TYPE)}</strong></p>`:''}
      </div>
      <div class="billing-section">
        <h4>Payment Details</h4>
        <p>
          <strong>Standard Bank Eswatini</strong><br>
          Account Name: Busiquip (Pty) Ltd<br>
          Account No: 9870034567<br>
          Branch Code: 05-06-07<br>
          Swift: SBSW SZ SX<br>
          <br>
          <span style="color:#6b7280;font-size:.8rem">Reference: ${ref}</span>
        </p>
      </div>
    </div>

    <!-- META GRID -->
    <div class="doc-meta-grid">
      <div class="meta-cell"><div class="label">Document No.</div><div class="value">${ref}</div></div>
      <div class="meta-cell"><div class="label">Issue Date</div><div class="value">${fmtDate(inv.INVOICE_DATE)}</div></div>
      <div class="meta-cell"><div class="label">Due Date</div><div class="value">${fmtDate(inv.DUE_DATE)}</div></div>
      <div class="meta-cell"><div class="label">Fault Ref.</div><div class="value">${fault?`#${fault.REP_FAULT_ID}`:'—'}</div></div>
    </div>

    <!-- LINE ITEMS -->
    <div style="border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;margin-bottom:16px">
      <table class="items-table">
        <thead><tr><th style="width:40px">#</th><th>Description of Services</th><th style="width:60px;text-align:center">Qty</th><th style="width:100px;text-align:right">Unit Price</th><th style="width:110px;text-align:right">Total</th></tr></thead>
        <tbody>${lineRows||'<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:1.5rem">No line items</td></tr>'}</tbody>
      </table>
    </div>

    <!-- TOTALS -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:20px">
      <div class="total-section" style="min-width:280px">
        <div class="total-row"><span>Subtotal</span><span>E ${fmt(sub)}</span></div>
        <div class="total-row"><span>VAT (15%)</span><span>E ${fmt(vat)}</span></div>
        <div class="vat-note">VAT Reg No: E124/0002/19 · All prices in Swazi Lilangeni (SZL/E)</div>
        <div class="total-row grand"><span>TOTAL DUE</span><span>E ${fmt(grand)}</span></div>
      </div>
    </div>

    <!-- PAYMENT & FAULT DETAILS -->
    ${payHtml}
    ${faultHtml}

    <!-- FOOTER -->
    <div class="doc-footer">
      <div class="company-sign">
        <strong>Busiquip (Pty) Ltd — Authorised Signatory</strong>
        <div style="width:160px;height:1px;background:#d1d5db;margin:28px 0 4px"></div>
        <div>Signature &amp; Company Stamp</div>
        <div style="margin-top:8px;color:#9ca3af">Generated: ${new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})}</div>
      </div>
      <div style="text-align:right">
        ${isReceipt?`<div class="receipt-stamp" style="margin-bottom:12px">RECEIVED WITH THANKS</div>`:''}
        <div class="stamp-box">Company<br>Stamp</div>
        <div style="font-size:.7rem;color:#9ca3af;margin-top:8px">Thank you for your business</div>
      </div>
    </div>

    <div style="margin-top:20px;padding:12px 16px;background:#fef9ec;border:1px solid #fde68a;border-radius:6px;font-size:.75rem;color:#92400e;text-align:center">
      This document was generated by the Busiquip Eswatini Fault Management System · For queries contact accounts@busiquip.co.sz or call +268 2404 5000
    </div>
  </div>`;
}

function closePrint(){document.getElementById('printModal').classList.remove('show');}

function downloadPDF(){
  showToast('Use Print → Save as PDF in the print dialog','info');
  setTimeout(()=>window.print(),500);
}

loadDocuments();
</script>
</body>
</html>


