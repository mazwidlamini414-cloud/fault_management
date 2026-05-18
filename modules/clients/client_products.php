<?php
// ═══════════════════════════════════════════════════════════════════════
//  FILE PATH: fault_management/modules/clients/client_products.php
//  BUSIQUIP ESWATINI — Client Products (View Only — Admin Assigns)
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

    if ($action === 'get_products') {
        $res = mysqli_query($conn,"
            SELECT cp.*,
                   p.PROD_NAME, p.PROD_DESCRIPTION, p.PROD_TYPE,
                   (SELECT COUNT(*) FROM reported_fault rf WHERE rf.CLIENT_PROD_ID=cp.CLIENT_PROD_ID) AS FAULT_COUNT,
                   (SELECT COUNT(*) FROM reported_fault rf WHERE rf.CLIENT_PROD_ID=cp.CLIENT_PROD_ID AND rf.STATUS NOT IN('Closed','Rejected')) AS ACTIVE_FAULTS
            FROM client_product cp
            LEFT JOIN product p ON p.PROD_ID=cp.PROD_ID
            WHERE cp.CLIENT_ID=$client_id
            ORDER BY cp.PURCHASE_DATE DESC
        ");
        $rows=[];
        while($r=mysqli_fetch_assoc($res)) $rows[]=$r;
        echo json_encode($rows); exit;
    }

    if ($action === 'get_product_detail') {
        $cpid = (int)($_GET['id']??0);
        $row  = mysqli_fetch_assoc(mysqli_query($conn,"
            SELECT cp.*, p.PROD_NAME, p.PROD_DESCRIPTION, p.PROD_TYPE
            FROM client_product cp
            LEFT JOIN product p ON p.PROD_ID=cp.PROD_ID
            WHERE cp.CLIENT_PROD_ID=$cpid AND cp.CLIENT_ID=$client_id
        "));
        if (!$row) { echo json_encode(['error'=>'Not found']); exit; }

        // Fault history for this product
        $faults=[];
        $fr=mysqli_query($conn,"
            SELECT rf.REP_FAULT_ID, rf.REPORT_DATE, rf.STATUS, rf.PRIORITY, rf.DESCRIPTION
            FROM reported_fault rf
            WHERE rf.CLIENT_PROD_ID=$cpid
            ORDER BY rf.REPORT_DATE DESC
        ");
        while($f=mysqli_fetch_assoc($fr)) $faults[]=$f;

        echo json_encode(['product'=>$row,'faults'=>$faults]); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$notif_res   = mysqli_query($conn,"SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=$client_id AND user_type='Client' AND is_read=0");
$notif_count = mysqli_fetch_assoc($notif_res)['cnt'] ?? 0;

$current_page = 'client_products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Busiquip – My Products</title>
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
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}
.prod-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.25rem;cursor:pointer;transition:all .2s;position:relative}
.prod-card:hover{border-color:var(--accent);box-shadow:0 4px 20px rgba(240,165,0,.12);transform:translateY(-2px)}
.prod-icon{width:52px;height:52px;border-radius:12px;background:rgba(240,165,0,.12);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--accent);margin-bottom:1rem}
.prod-name{font-size:1rem;font-weight:700;margin-bottom:.3rem}
.prod-type{font-size:.78rem;color:var(--text2);margin-bottom:.75rem}
.prod-meta{display:flex;flex-direction:column;gap:.35rem;font-size:.8rem;color:var(--text2)}
.prod-meta span{display:flex;align-items:center;gap:.4rem}
.prod-footer{display:flex;gap:.5rem;align-items:center;margin-top:1rem;padding-top:.85rem;border-top:1px solid var(--border);flex-wrap:wrap}
.search-bar{display:flex;align-items:center;gap:.5rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.5rem 1rem;flex:1;max-width:320px}
.search-bar input{background:transparent;border:none;outline:none;color:var(--text);font-size:.875rem;width:100%}
.search-bar .ti{color:var(--text2)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:500;align-items:center;justify-content:center;padding:1rem}
.modal-overlay.show{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:640px;max-height:92vh;overflow-y:auto;animation:fadeIn .25s ease}
.modal-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-size:1.1rem;font-weight:700}
.modal-body{padding:1.5rem}
.close-btn{background:transparent;border:none;color:var(--text2);font-size:1.3rem;cursor:pointer;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center}
.close-btn:hover{background:var(--surface2);color:var(--text)}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--border);gap:1rem}
.info-row:last-child{border-bottom:none}
.info-label{font-size:.78rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;min-width:130px}
.info-value{font-size:.88rem;text-align:right;flex:1}
.table-wrap{overflow-x:auto;border-radius:8px;border:1px solid var(--border);margin-top:.75rem}
table{width:100%;border-collapse:collapse}
th{background:var(--surface2);color:var(--text2);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:.65rem .85rem;text-align:left}
td{padding:.75rem .85rem;border-top:1px solid var(--border);font-size:.85rem;color:var(--text)}
.badge-pending{background:rgba(210,153,34,.15);color:var(--warning);border:1px solid rgba(210,153,34,.3)}
.badge-progress{background:rgba(240,165,0,.15);color:var(--accent);border:1px solid rgba(240,165,0,.3)}
.badge-completed{background:rgba(63,185,80,.15);color:var(--success);border:1px solid rgba(63,185,80,.3)}
.badge-assigned{background:rgba(88,166,255,.15);color:var(--info);border:1px solid rgba(88,166,255,.3)}
.badge-closed{background:rgba(63,185,80,.15);color:var(--success);border:1px solid rgba(63,185,80,.3)}
.notice-box{background:rgba(88,166,255,.08);border:1px solid rgba(88,166,255,.25);border-radius:10px;padding:1rem 1.25rem;display:flex;align-items:flex-start;gap:.75rem;margin-bottom:1.25rem;color:var(--info);font-size:.875rem}
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
    <div class="h-page-title">My Products<span>Registered Equipment</span></div>
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
    <div class="topbar-title">My Products <span>/ Registered Equipment</span></div>
    <div class="topbar-actions">
      <a href="client_documents.php" class="icon-btn"><i class="ti ti-file-description"></i></a>
      <a href="client_profile.php" class="icon-btn">
        <i class="ti ti-user-circle"></i>
        <?php if ($notif_count>0): ?><span class="badge"><?= $notif_count ?></span><?php endif; ?>
      </a>
    </div>
  </header>

  <div class="page-content">
    <div class="page-head">
      <div>
        <h1><i class="ti ti-device-laptop" style="color:var(--accent)"></i> My Products</h1>
        <p>All equipment registered to your account by Busiquip Eswatini.</p>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center">
        <div class="search-bar">
          <i class="ti ti-search"></i>
          <input type="text" id="searchInput" placeholder="Search products..." oninput="filterProducts()">
        </div>
        <button class="btn btn-secondary btn-sm" onclick="loadProducts()"><i class="ti ti-refresh"></i></button>
      </div>
    </div>

    <div class="notice-box">
      <i class="ti ti-info-circle" style="font-size:1.2rem;flex-shrink:0;margin-top:.1rem"></i>
      <div>Products are registered to your account by <strong>Busiquip Eswatini administrators</strong> when you purchase equipment. If you believe a product is missing, contact your account manager.</div>
    </div>

    <div id="statsRow" style="display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(240,165,0,.15);color:var(--accent);display:flex;align-items:center;justify-content:center"><i class="ti ti-device-laptop"></i></div>
        <div><div id="statTotal" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Total Products</div></div>
      </div>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(63,185,80,.15);color:var(--success);display:flex;align-items:center;justify-content:center"><i class="ti ti-shield-check"></i></div>
        <div><div id="statWarranty" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Under Warranty</div></div>
      </div>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem">
        <div style="width:36px;height:36px;border-radius:8px;background:rgba(248,81,73,.15);color:var(--danger);display:flex;align-items:center;justify-content:center"><i class="ti ti-alert-triangle"></i></div>
        <div><div id="statActiveFaults" style="font-size:1.3rem;font-weight:700">—</div><div style="font-size:.75rem;color:var(--text2)">Active Repairs</div></div>
      </div>
    </div>

    <div id="prodGrid" class="prod-grid">
      <div style="text-align:center;padding:3rem;color:var(--text2);grid-column:1/-1"><i class="ti ti-loader ti-spin" style="font-size:2rem"></i><p style="margin-top:.75rem">Loading products...</p></div>
    </div>
  </div>
</div>

<!-- PRODUCT DETAIL MODAL -->
<div class="modal-overlay" id="detailModal">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modalTitle">Product Details</h3>
      <button class="close-btn" onclick="closeModal()"><i class="ti ti-x"></i></button>
    </div>
    <div class="modal-body" id="modalBody">Loading...</div>
  </div>
</div>

<script>
let allProducts=[];

function showToast(msg,type='info'){
  const c=document.getElementById('toast-container');const t=document.createElement('div');
  t.className='toast '+type;
  const icons={success:'circle-check',error:'circle-x',info:'info-circle'};
  t.innerHTML=`<i class="ti ti-${icons[type]}" style="font-size:1.1rem"></i><span>${msg}</span>`;
  c.appendChild(t);setTimeout(()=>{t.style.animation='slideOut .3s ease forwards';setTimeout(()=>t.remove(),300)},3500);
}

function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

function warrantyBadge(end){
  if(!end) return `<span class="badge badge-warn">No Warranty</span>`;
  const today=new Date(),w=new Date(end),diff=Math.ceil((w-today)/(1000*60*60*24));
  if(diff<0) return `<span class="badge badge-expired">Expired</span>`;
  if(diff<30) return `<span class="badge badge-warn">Expires in ${diff}d</span>`;
  return `<span class="badge badge-ok">Valid until ${w.toLocaleDateString()}</span>`;
}

function typeIcon(t){
  const map={'Server':'server','Printer':'printer','Scanner':'scan','Network':'network','Laptop':'laptop','Desktop':'desktop','Other':'device-desktop'};
  return map[t]||'device-laptop';
}

function statusBadge(s){
  const map={'Pending':'badge-pending','Assigned':'badge-assigned','In Progress':'badge-progress','Completed':'badge-completed','Closed':'badge-closed','Client Approved':'badge-completed'};
  return `<span class="badge ${map[s]||'badge-pending'}">${s}</span>`;
}

async function loadProducts(){
  const res=await fetch('client_products.php?action=get_products');
  allProducts=await res.json();
  updateStats();
  renderProducts(allProducts);
}

function updateStats(){
  const today=new Date();
  document.getElementById('statTotal').textContent=allProducts.length;
  document.getElementById('statWarranty').textContent=allProducts.filter(p=>p.WARRANTY_END_DATE&&new Date(p.WARRANTY_END_DATE)>=today).length;
  document.getElementById('statActiveFaults').textContent=allProducts.reduce((s,p)=>s+(parseInt(p.ACTIVE_FAULTS)||0),0);
}

function filterProducts(){
  const q=document.getElementById('searchInput').value.toLowerCase();
  renderProducts(allProducts.filter(p=>(p.PROD_NAME||'').toLowerCase().includes(q)||(p.PROD_TYPE||'').toLowerCase().includes(q)||(p.SERIAL_NUM||'').toLowerCase().includes(q)));
}

function renderProducts(data){
  const grid=document.getElementById('prodGrid');
  if(!data.length){
    grid.innerHTML=`<div style="text-align:center;padding:3rem;color:var(--text2);grid-column:1/-1"><i class="ti ti-device-laptop" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4"></i><p>No products registered to your account yet.</p><p style="margin-top:.5rem;font-size:.82rem">Contact Busiquip Eswatini to register your equipment.</p></div>`;
    return;
  }
  grid.innerHTML=data.map(p=>`
    <div class="prod-card" onclick="openDetail(${p.CLIENT_PROD_ID})">
      <div class="prod-icon"><i class="ti ti-${typeIcon(p.PROD_TYPE)}"></i></div>
      <div class="prod-name">${escHtml(p.PROD_NAME||'Unknown Product')}</div>
      <div class="prod-type">${escHtml(p.PROD_TYPE||'General Equipment')}</div>
      <div class="prod-meta">
        ${p.SERIAL_NUM?`<span><i class="ti ti-barcode"></i>S/N: ${escHtml(p.SERIAL_NUM)}</span>`:''}
        ${p.PURCHASE_DATE?`<span><i class="ti ti-calendar"></i>Purchased: ${new Date(p.PURCHASE_DATE).toLocaleDateString()}</span>`:''}
        ${p.FAULT_COUNT>0?`<span><i class="ti ti-alert-triangle"></i>${p.FAULT_COUNT} fault${p.FAULT_COUNT>1?'s':''} total</span>`:'<span><i class="ti ti-circle-check" style="color:var(--success)"></i>No faults reported</span>'}
      </div>
      <div class="prod-footer">
        ${warrantyBadge(p.WARRANTY_END_DATE)}
        ${p.ACTIVE_FAULTS>0?`<span class="badge badge-warn"><i class="ti ti-loader"></i>${p.ACTIVE_FAULTS} active</span>`:''}
      </div>
    </div>`).join('');
}

async function openDetail(cpid){
  document.getElementById('detailModal').classList.add('show');
  document.getElementById('modalBody').innerHTML='<div style="text-align:center;padding:2rem;color:var(--text2)"><i class="ti ti-loader ti-spin" style="font-size:2rem"></i></div>';

  const res=await fetch(`client_products.php?action=get_product_detail&id=${cpid}`);
  const {product:p,faults}=await res.json();
  if(p.error){document.getElementById('modalBody').innerHTML='<p style="color:var(--danger)">Error loading product.</p>';return;}

  document.getElementById('modalTitle').textContent=escHtml(p.PROD_NAME||'Product Details');

  let html=`
    <div class="info-row"><span class="info-label">Product Name</span><span class="info-value">${escHtml(p.PROD_NAME||'—')}</span></div>
    <div class="info-row"><span class="info-label">Product Type</span><span class="info-value">${escHtml(p.PROD_TYPE||'—')}</span></div>
    ${p.PROD_DESCRIPTION?`<div class="info-row"><span class="info-label">Description</span><span class="info-value">${escHtml(p.PROD_DESCRIPTION)}</span></div>`:''}
    <div class="info-row"><span class="info-label">Serial Number</span><span class="info-value"><code style="background:var(--surface2);padding:.15rem .4rem;border-radius:4px;font-size:.85rem">${escHtml(p.SERIAL_NUM||'—')}</code></span></div>
    <div class="info-row"><span class="info-label">Purchase Date</span><span class="info-value">${p.PURCHASE_DATE?new Date(p.PURCHASE_DATE).toLocaleDateString():'—'}</span></div>
    <div class="info-row"><span class="info-label">Warranty</span><span class="info-value">${warrantyBadge(p.WARRANTY_END_DATE)}</span></div>
  `;

  if(faults.length){
    html+=`<div style="margin-top:1.25rem">
      <div style="font-size:.9rem;font-weight:600;margin-bottom:.75rem"><i class="ti ti-alert-triangle" style="color:var(--accent)"></i> Fault History (${faults.length})</div>
      <div class="table-wrap">
        <table><thead><tr><th>#</th><th>Date</th><th>Status</th><th>Priority</th></tr></thead>
        <tbody>${faults.map(f=>`<tr>
          <td><a href="client_repair_progress.php" style="color:var(--info);text-decoration:none">#${f.REP_FAULT_ID}</a></td>
          <td>${new Date(f.REPORT_DATE).toLocaleDateString()}</td>
          <td>${statusBadge(f.STATUS)}</td>
          <td><span class="badge ${f.PRIORITY==='High'?'badge-expired':f.PRIORITY==='Medium'?'badge-warn':'badge-ok'}">${f.PRIORITY||'N/A'}</span></td>
        </tr>`).join('')}</tbody></table>
      </div>
    </div>`;
  } else {
    html+=`<div style="margin-top:1.25rem;text-align:center;padding:1.5rem;color:var(--text2);background:var(--surface2);border-radius:8px"><i class="ti ti-circle-check" style="color:var(--success);font-size:1.5rem;display:block;margin-bottom:.5rem"></i>No faults reported for this product.</div>`;
  }

  html+=`<div style="margin-top:1.25rem;display:flex;gap:.5rem;justify-content:flex-end">
    <button class="btn btn-secondary btn-sm" onclick="closeModal()"><i class="ti ti-x"></i> Close</button>
    <a href="report_fault.php" class="btn btn-primary btn-sm"><i class="ti ti-alert-triangle"></i> Report Fault for this Product</a>
  </div>`;

  document.getElementById('modalBody').innerHTML=html;
}

function closeModal(){document.getElementById('detailModal').classList.remove('show');}

loadProducts();
</script>
</body>
</html>
