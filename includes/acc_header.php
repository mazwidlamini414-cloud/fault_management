<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['emp_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Accountant') {
    header('Location: ' . BASE_URL . '/modules/staff/accountant_login.php');
    exit();
}

$acc_id   = $_SESSION['emp_id'];
$acc_name = $_SESSION['emp_name'] ?? 'Accountant';
$current_page = basename($_SERVER['PHP_SELF'], '.php');

require_once '../../config/database.php';

// ── Live KPIs for sidebar badges ──────────────────────────────────────────────
// Overdue invoices count
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM invoice WHERE STATUS NOT IN ('Paid','Cancelled') AND DUE_DATE < CURDATE()");
$overdue_count = mysqli_fetch_assoc($r)['c'] ?? 0;

// Pending invoices
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM invoice WHERE STATUS = 'Pending Payment' OR STATUS = 'Submitted'");
$pending_inv   = mysqli_fetch_assoc($r)['c'] ?? 0;

// Company balance = total payments received - total expenses
$r = mysqli_query($conn, "SELECT COALESCE(SUM(AMOUNT_PAID),0) income FROM payment WHERE STATUS='Verified'");
$total_income  = (float)(mysqli_fetch_assoc($r)['income'] ?? 0);
$r = mysqli_query($conn, "SELECT COALESCE(SUM(AMOUNT),0) exp FROM expenses");
$total_expense = (float)(mysqli_fetch_assoc($r)['exp'] ?? 0);
$company_balance = $total_income - $total_expense;

$nav_items = [
    ['page' => 'accountant_dashboard',    'icon' => 'layout-dashboard',   'label' => 'Dashboard'],
    ['page' => 'quotation_review',        'icon' => 'file-invoice',       'label' => 'Review Quotations'],
    ['page' => 'payment_verification',    'icon' => 'credit-card',        'label' => 'Verify Payments'],
    ['page' => 'acc_invoices',            'icon' => 'receipt',            'label' => 'Invoices',         'badge' => $pending_inv],
    ['page' => 'generate_invoice',        'icon' => 'file-text',          'label' => 'Generate Invoice'],
    ['page' => 'payment_tracking',        'icon' => 'trending-up',        'label' => 'Payment Tracking'],
    ['page' => 'financial_reports',       'icon' => 'chart-bar',          'label' => 'Reports'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Busiquip Finance – <?= htmlspecialchars($page_title ?? 'Accountant') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
  --bg:       #0d1117;
  --surface:  #161b22;
  --surface2: #21262d;
  --border:   #30363d;
  --accent:   #f0a500;
  --accent2:  #e08c00;
  --text:     #e6edf3;
  --text2:    #8b949e;
  --danger:   #f85149;
  --success:  #3fb950;
  --warning:  #d29922;
  --info:     #58a6ff;
  --sidebar-w: 248px;
  --topbar-h:  60px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);height:100vh;position:fixed;top:0;left:0;display:flex;flex-direction:column;z-index:100;transition:transform .3s;overflow-y:auto}
.sidebar-logo{padding:1.2rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-shrink:0}
.logo-mark{width:36px;height:36px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;color:#000}
.logo-text{font-weight:700;font-size:1rem;color:var(--text)}
.logo-text span{color:var(--accent)}
.logo-sub{font-size:.65rem;color:var(--text2);margin-top:.1rem}
.sidebar-nav{flex:1;overflow-y:auto;padding:.75rem 0}
.sidebar-nav::-webkit-scrollbar{width:4px}
.sidebar-nav::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.nav-section{padding:.25rem .75rem .25rem 1rem;font-size:.65rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.08em;margin-top:.75rem}
.nav-item{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;margin:.1rem .5rem;border-radius:8px;text-decoration:none;color:var(--text2);font-size:.875rem;font-weight:500;transition:all .2s;position:relative}
.nav-item:hover{background:var(--surface2);color:var(--text)}
.nav-item.active{background:linear-gradient(90deg,rgba(240,165,0,.18),rgba(240,165,0,.06));color:var(--accent);border-left:3px solid var(--accent);padding-left:calc(1rem - 3px)}
.nav-item .ti{font-size:1.1rem}
.nav-badge{margin-left:auto;background:var(--danger);color:#fff;border-radius:99px;font-size:.65rem;font-weight:700;padding:.15rem .45rem;min-width:18px;text-align:center;flex-shrink:0}
.balance-chip{margin:.75rem;background:rgba(63,185,80,.1);border:1px solid rgba(63,185,80,.25);border-radius:10px;padding:.75rem 1rem;flex-shrink:0}
.balance-chip .bc-label{font-size:.65rem;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.balance-chip .bc-val{font-size:1.1rem;font-weight:700;color:var(--success);margin-top:.15rem}
.balance-chip .bc-val.negative{color:var(--danger)}
.sidebar-footer{padding:1rem;border-top:1px solid var(--border);flex-shrink:0}
.acc-card{display:flex;align-items:center;gap:.75rem;padding:.75rem;background:var(--surface2);border-radius:10px;border:1px solid var(--border)}
.acc-avatar{width:36px;height:36px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:#000;flex-shrink:0}
.acc-info .name{font-size:.8rem;font-weight:600;color:var(--text)}
.acc-info .role{font-size:.7rem;color:var(--text2)}
.status-dot{width:8px;height:8px;border-radius:50%;background:var(--success);margin-left:auto;box-shadow:0 0 6px var(--success);flex-shrink:0}

/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{height:var(--topbar-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.5rem;gap:1rem;position:sticky;top:0;z-index:50}
.topbar-title{font-size:1.1rem;font-weight:600;color:var(--text);flex:1}
.topbar-title span{color:var(--text2);font-weight:400;font-size:.9rem}
.topbar-actions{display:flex;align-items:center;gap:.5rem}
.icon-btn{width:38px;height:38px;border-radius:8px;background:transparent;border:1px solid var(--border);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .2s;position:relative;text-decoration:none}
.icon-btn:hover{background:var(--surface2);color:var(--text)}
.icon-btn .badge{position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;border-radius:99px;font-size:.6rem;font-weight:700;padding:.1rem .3rem;min-width:16px;text-align:center}
.page-content{padding:1.5rem;flex:1;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* COMPONENTS */
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem}
.page-head h1{font-size:1.4rem;font-weight:700}
.page-head p{font-size:.85rem;color:var(--text2);margin-top:.2rem}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--accent);color:#000}.btn-primary:hover{background:var(--accent2);transform:translateY(-2px)}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}.btn-secondary:hover{background:var(--border)}
.btn-danger{background:rgba(248,81,73,.15);color:var(--danger);border:1px solid rgba(248,81,73,.3)}.btn-danger:hover{background:rgba(248,81,73,.25)}
.btn-success{background:rgba(63,185,80,.15);color:var(--success);border:1px solid rgba(63,185,80,.3)}.btn-success:hover{background:rgba(63,185,80,.25)}
.btn-info{background:rgba(88,166,255,.15);color:var(--info);border:1px solid rgba(88,166,255,.3)}.btn-info:hover{background:rgba(88,166,255,.25)}
.btn-sm{padding:.35rem .75rem;font-size:.8rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem}
.card-title{font-size:.95rem;font-weight:600;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
@media(max-width:900px){.grid-4,.grid-3{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:600px){.grid-4,.grid-3,.grid-2{grid-template-columns:1fr}.sidebar{transform:translateX(-100%)}.main{margin-left:0}}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem;display:flex;align-items:flex-start;gap:1rem;transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.stat-icon.orange{background:rgba(240,165,0,.15);color:var(--accent)}
.stat-icon.blue{background:rgba(88,166,255,.15);color:var(--info)}
.stat-icon.green{background:rgba(63,185,80,.15);color:var(--success)}
.stat-icon.red{background:rgba(248,81,73,.15);color:var(--danger)}
.stat-icon.purple{background:rgba(188,140,255,.15);color:#bc8cff}
.stat-num{font-size:1.75rem;font-weight:700;color:var(--text);line-height:1}
.stat-label{font-size:.8rem;color:var(--text2);margin-top:.25rem}
.stat-trend{font-size:.72rem;margin-top:.3rem}
.stat-trend.up{color:var(--success)}.stat-trend.down{color:var(--danger)}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:99px;font-size:.72rem;font-weight:600;white-space:nowrap}
.badge-paid{background:rgba(63,185,80,.15);color:var(--success);border:1px solid rgba(63,185,80,.3)}
.badge-pending{background:rgba(210,153,34,.15);color:var(--warning);border:1px solid rgba(210,153,34,.3)}
.badge-overdue{background:rgba(248,81,73,.15);color:var(--danger);border:1px solid rgba(248,81,73,.3)}
.badge-partial{background:rgba(88,166,255,.15);color:var(--info);border:1px solid rgba(88,166,255,.3)}
.badge-cancelled{background:rgba(139,148,158,.15);color:var(--text2);border:1px solid var(--border)}
.badge-submitted{background:rgba(240,165,0,.15);color:var(--accent);border:1px solid rgba(240,165,0,.3)}
.table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border)}
table{width:100%;border-collapse:collapse}
th{background:var(--surface2);color:var(--text2);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:.75rem 1rem;text-align:left;white-space:nowrap}
td{padding:.85rem 1rem;border-top:1px solid var(--border);font-size:.875rem;color:var(--text);vertical-align:middle}
tr:hover td{background:rgba(255,255,255,.02)}
.form-group{margin-bottom:1.1rem}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:var(--text2);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:.65rem 1rem;color:var(--text);font-size:.9rem;outline:none;transition:border .2s;font-family:inherit}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(240,165,0,.1)}
.form-control::placeholder{color:var(--text2)}
select.form-control option{background:var(--surface2)}
textarea.form-control{resize:vertical;min-height:90px}
#toast-container{position:fixed;top:1.2rem;right:1.2rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem}
.toast{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.85rem 1.1rem;display:flex;align-items:center;gap:.75rem;font-size:.875rem;color:var(--text);box-shadow:0 8px 24px rgba(0,0,0,.4);animation:slideIn .3s ease;min-width:260px;max-width:360px}
.toast.success{border-left:4px solid var(--success)}.toast.error{border-left:4px solid var(--danger)}.toast.info{border-left:4px solid var(--info)}.toast.warning{border-left:4px solid var(--warning)}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.divider{border:none;border-top:1px solid var(--border);margin:1rem 0}
.empty-state{text-align:center;padding:3rem 1rem;color:var(--text2)}
.empty-state .ti{font-size:3rem;display:block;margin-bottom:.75rem;opacity:.4}
.alert{padding:.85rem 1rem;border-radius:8px;font-size:.875rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.6rem}
.alert-success{background:rgba(63,185,80,.1);border:1px solid rgba(63,185,80,.3);color:var(--success)}
.alert-danger{background:rgba(248,81,73,.1);border:1px solid rgba(248,81,73,.3);color:var(--danger)}
.alert-warning{background:rgba(210,153,34,.1);border:1px solid rgba(210,153,34,.3);color:var(--warning)}
.alert-info{background:rgba(88,166,255,.1);border:1px solid rgba(88,166,255,.3);color:var(--info)}
</style>
</head>
<body>
<div id="toast-container"></div>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="<?= BASE_URL ?>/images/logo.png"
         alt="Busiquip"
         style="height:48px;width:auto;max-width:180px;object-fit:contain;display:block;"
         onerror="this.style.display='none';document.getElementById('acc-logo-fallback').style.display='flex'">
    <div id="acc-logo-fallback" style="display:none;align-items:center;gap:.75rem">
      <div class="logo-mark">B</div>
      <div class="logo-text">Busi<span>quip</span>
        <div class="logo-sub">Finance Module</div>
      </div>
    </div>
  </div>
  <!-- Live balance chip -->
  <div class="balance-chip">
    <div class="bc-label">Company Balance</div>
    <div class="bc-val <?= $company_balance < 0 ? 'negative' : '' ?>">
      E <?= number_format($company_balance, 2) ?>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Finance</div>
    <?php foreach ($nav_items as $item): ?>
    <a href="<?= $item['page'] ?>.php" class="nav-item <?= $current_page === $item['page'] ? 'active' : '' ?>">
      <i class="ti ti-<?= $item['icon'] ?>"></i>
      <?= $item['label'] ?>
      <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
        <span class="nav-badge"><?= $item['badge'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <hr class="divider" style="margin:.75rem .5rem">
    <a href="../../logout.php" class="nav-item" style="color:var(--danger)">
      <i class="ti ti-logout"></i> Sign Out
    </a>
  </nav>
  <div class="sidebar-footer">
    <div class="acc-card">
      <div class="acc-avatar"><?= strtoupper(substr($acc_name, 0, 1)) ?></div>
      <div class="acc-info">
        <div class="name"><?= htmlspecialchars($acc_name) ?></div>
        <div class="role">Accountant</div>
      </div>
      <div class="status-dot" title="Online"></div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <?= htmlspecialchars($page_title ?? 'Finance') ?>
      <?php if (!empty($page_subtitle)): ?><span>/ <?= htmlspecialchars($page_subtitle) ?></span><?php endif; ?>
    </div>
    <div class="topbar-actions">
      <a href="../../logout.php" class="icon-btn" title="Sign Out" style="color:var(--danger)"><i class="ti ti-logout"></i></a>
    </div>
  </header>
  <div class="page-content">
<?php
// Toast helper function for JS
?>
<script>
function showToast(msg, type='info'){
  const c=document.getElementById('toast-container');
  const t=document.createElement('div');
  t.className='toast '+type;
  const icons={success:'circle-check',error:'alert-circle',info:'info-circle',warning:'alert-triangle'};
  t.innerHTML=`<i class="ti ti-${icons[type]||'info-circle'}" style="font-size:1.1rem;flex-shrink:0"></i><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(()=>{t.style.animation='slideIn .3s ease reverse';setTimeout(()=>t.remove(),300)},4000);
}
</script>


