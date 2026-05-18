<?php
// ═══════════════════════════════════════════════════════════════════════
//  client_payment_history.php  —  BUSIQUIP ESWATINI  —  Client Portal
//  Shows full payment history for the logged-in client
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['client_id'])) {
    header("Location: client_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';

// ── Logout ─────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: client_login.php");
    exit;
}

// ── Fetch payment history ──────────────────────────────────────────────
$payments = [];
$res = $conn->query("
    SELECT p.*,
           i.INVOICE_DATE, i.DUE_DATE, i.TOTAL AS INV_TOTAL,
           i.TYPE AS INV_TYPE, i.STATUS AS INV_STATUS,
           rf.DESCRIPTION AS FAULT_DESC,
           f.FAULT_TYPE
    FROM payment p
    JOIN invoice i ON i.INVOICE_ID = p.INVOICE_ID
    LEFT JOIN assignment a ON a.ASSIGN_ID = i.ASSIGN_ID
    LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    LEFT JOIN fault f ON f.FAULT_ID = rf.FAULT_ID
    WHERE i.CLIENT_ID = $client_id
    ORDER BY p.PAYMENT_DATE DESC, p.PAYMENT_ID DESC
");
if ($res) while ($r = $res->fetch_assoc()) $payments[] = $r;

// ── Summary stats ──────────────────────────────────────────────────────
$total_paid    = array_sum(array_column($payments, 'AMOUNT_PAID'));
$total_pending = 0;
$pending_res = $conn->query("
    SELECT COALESCE(SUM(p.AMOUNT_PAID),0) AS total
    FROM payment p
    JOIN invoice i ON i.INVOICE_ID = p.INVOICE_ID
    WHERE i.CLIENT_ID = $client_id AND p.STATUS = 'Pending'
");
if ($pending_res) $total_pending = $pending_res->fetch_assoc()['total'] ?? 0;
$total_count = count($payments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History – BUSIQUIP</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: #FFD700;
            --primary-burgundy: #8B0000;
            --accent-teal: #0D9488;
            --accent-emerald: #10B981;
            --color-dark-bg: #0F172A;
            --color-darker-bg: #0B1221;
            --color-card-dark: #1E293B;
            --color-border: #334155;
            --color-success: #10B981;
            --color-warning: #F59E0B;
            --color-danger: #EF4444;
            --color-info: #3B82F6;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--color-dark-bg), var(--color-darker-bg));
            color: #fff;
            min-height: 100vh;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: rgba(30,41,59,0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--color-border);
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 65px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .brand-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--primary-burgundy), var(--primary-gold));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }

        .brand-text { font-size: 1.1rem; font-weight: 700; color: #fff; }
        .brand-text span { color: var(--primary-gold); }

        .navbar-right { display: flex; align-items: center; gap: 1rem; }

        .nav-btn {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: var(--transition);
            border: none; cursor: pointer;
        }

        .nav-btn-ghost {
            background: transparent;
            color: #CBD5E1;
            border: 1px solid var(--color-border);
        }
        .nav-btn-ghost:hover { background: rgba(255,255,255,0.05); color: #fff; }

        .nav-btn-danger { background: rgba(239,68,68,0.15); color: #EF4444; border: 1px solid rgba(239,68,68,0.3); }
        .nav-btn-danger:hover { background: rgba(239,68,68,0.25); }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, rgba(139,0,0,0.3), rgba(13,148,136,0.2));
            border-bottom: 1px solid var(--color-border);
            padding: 2rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex; align-items: center; gap: 0.75rem;
        }

        .page-header h1 i { color: var(--primary-gold); }
        .page-header p { color: #94A3B8; margin-top: 0.3rem; }

        /* ── MAIN CONTENT ── */
        .main-content { padding: 2rem; max-width: 1200px; margin: 0 auto; }

        /* ── STATS CARDS ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--color-card-dark);
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-2px); border-color: var(--primary-gold); }

        .stat-icon {
            width: 50px; height: 50px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .stat-icon.gold { background: rgba(255,215,0,0.15); color: var(--primary-gold); }
        .stat-icon.green { background: rgba(16,185,129,0.15); color: var(--color-success); }
        .stat-icon.orange { background: rgba(245,158,11,0.15); color: var(--color-warning); }
        .stat-icon.blue { background: rgba(59,130,246,0.15); color: var(--color-info); }

        .stat-info { flex: 1; }
        .stat-value { font-size: 1.5rem; font-weight: 700; }
        .stat-label { font-size: 0.78rem; color: #94A3B8; margin-top: 0.2rem; }

        /* ── TABLE CARD ── */
        .table-card {
            background: var(--color-card-dark);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--color-border);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem;
        }

        .table-header h3 {
            font-size: 1rem; font-weight: 600;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .table-header h3 i { color: var(--primary-gold); }

        .search-box {
            display: flex; align-items: center; gap: 0.5rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 0.4rem 0.75rem;
        }
        .search-box i { color: #64748B; font-size: 0.85rem; }
        .search-box input {
            background: none; border: none; outline: none;
            color: #fff; font-size: 0.85rem; width: 200px;
        }
        .search-box input::placeholder { color: #64748B; }

        .table-wrapper { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead th {
            background: rgba(255,255,255,0.03);
            padding: 0.85rem 1.25rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--color-border);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid rgba(51,65,85,0.5);
            transition: var(--transition);
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(255,255,255,0.03); }

        td { padding: 1rem 1.25rem; color: #CBD5E1; vertical-align: middle; }

        .payment-id { font-weight: 600; color: #fff; }
        .invoice-ref { font-size: 0.8rem; color: #64748B; }

        .amount {
            font-weight: 700;
            font-size: 1rem;
            color: var(--color-success);
        }

        .badge {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.25rem 0.65rem;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-success { background: rgba(16,185,129,0.15); color: #10B981; }
        .badge-warning { background: rgba(245,158,11,0.15); color: #F59E0B; }
        .badge-danger  { background: rgba(239,68,68,0.15);  color: #EF4444; }
        .badge-info    { background: rgba(59,130,246,0.15); color: #3B82F6; }

        .method-icon { font-size: 0.9rem; margin-right: 0.3rem; }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748B;
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.4; }
        .empty-state p { font-size: 1rem; }

        /* ── FILTER TABS ── */
        .filter-tabs {
            display: flex; gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.35rem 0.85rem;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid var(--color-border);
            background: transparent;
            color: #94A3B8;
            transition: var(--transition);
        }
        .filter-tab.active, .filter-tab:hover {
            background: var(--primary-gold);
            color: #000;
            border-color: var(--primary-gold);
        }

        /* ── BACK LINK ── */
        .back-link {
            display: inline-flex; align-items: center; gap: 0.4rem;
            color: #94A3B8; text-decoration: none; font-size: 0.85rem;
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }
        .back-link:hover { color: var(--primary-gold); }

        @media (max-width: 768px) {
            .navbar { padding: 0 1rem; }
            .main-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .search-box input { width: 140px; }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="client_portal.php" class="navbar-brand">
        <div class="brand-icon"><i class="fas fa-tools"></i></div>
        <span class="brand-text">BUSI<span>QUIP</span></span>
    </a>
    <div class="navbar-right">
        <a href="client_portal.php" class="nav-btn nav-btn-ghost">
            <i class="fas fa-th-large"></i> Dashboard
        </a>
        <form method="POST" style="margin:0">
            <button type="submit" name="logout" class="nav-btn nav-btn-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>
</nav>

<!-- PAGE HEADER -->
<div class="page-header">
    <div style="max-width:1200px;margin:0 auto">
        <h1><i class="fas fa-history"></i> Payment History</h1>
        <p>All payments made by <strong><?= htmlspecialchars($client_name) ?></strong></p>
    </div>
</div>

<!-- MAIN -->
<div class="main-content">

    <a href="client_portal.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $total_count ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value">E <?= number_format($total_paid, 2) ?></div>
                <div class="stat-label">Total Amount Paid</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-value">E <?= number_format($total_pending, 2) ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $payments ? date('d M Y', strtotime($payments[0]['PAYMENT_DATE'])) : '—' ?></div>
                <div class="stat-label">Last Payment</div>
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="table-card">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> All Payments</h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterTable('all', this)">All</button>
                    <button class="filter-tab" onclick="filterTable('Verified', this)">Verified</button>
                    <button class="filter-tab" onclick="filterTable('Pending', this)">Pending</button>
                    <button class="filter-tab" onclick="filterTable('Rejected', this)">Rejected</button>
                </div>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search payments..." oninput="searchTable()">
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <?php if (empty($payments)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>No payment records found.</p>
            </div>
            <?php else: ?>
            <table id="paymentsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Invoice</th>
                        <th>Fault / Service</th>
                        <th>Payment Date</th>
                        <th>Amount Paid</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <?php
                        $status = $p['STATUS'] ?? 'Pending';
                        $badge_class = match($status) {
                            'Verified' => 'badge-success',
                            'Pending'  => 'badge-warning',
                            'Rejected' => 'badge-danger',
                            default    => 'badge-info',
                        };
                        $method = $p['METHOD'] ?? 'Cash';
                        $method_icon = match(strtolower($method)) {
                            'cash'         => '💵',
                            'bank transfer'=> '🏦',
                            'card'         => '💳',
                            'mobile money' => '📱',
                            default        => '💰',
                        };
                    ?>
                    <tr data-status="<?= htmlspecialchars($status) ?>">
                        <td class="payment-id">PMT-<?= str_pad($p['PAYMENT_ID'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <div>INV-<?= str_pad($p['INVOICE_ID'], 4, '0', STR_PAD_LEFT) ?></div>
                            <div class="invoice-ref"><?= htmlspecialchars($p['INV_TYPE'] ?? 'Invoice') ?> · E <?= number_format($p['INV_TOTAL'] ?? 0, 2) ?></div>
                        </td>
                        <td style="max-width:200px">
                            <?php if (!empty($p['FAULT_TYPE'])): ?>
                                <div style="font-size:0.82rem"><?= htmlspecialchars($p['FAULT_TYPE']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['FAULT_DESC'])): ?>
                                <div style="font-size:0.75rem;color:#64748B"><?= htmlspecialchars(substr($p['FAULT_DESC'], 0, 50)) ?>...</div>
                            <?php endif; ?>
                            <?php if (empty($p['FAULT_TYPE']) && empty($p['FAULT_DESC'])): ?>
                                <span style="color:#64748B">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime($p['PAYMENT_DATE'])) ?></td>
                        <td class="amount">E <?= number_format($p['AMOUNT_PAID'], 2) ?></td>
                        <td>
                            <span class="method-icon"><?= $method_icon ?></span>
                            <?= htmlspecialchars($method) ?>
                        </td>
                        <td style="font-family:monospace;font-size:0.82rem">
                            <?= htmlspecialchars($p['REFERENCE_NUMBER'] ?? '—') ?>
                        </td>
                        <td>
                            <span class="badge <?= $badge_class ?>">
                                <i class="fas fa-circle" style="font-size:0.5rem"></i>
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
function filterTable(status, btn) {
    document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const rows = document.querySelectorAll('#paymentsTable tbody tr');
    rows.forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function searchTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#paymentsTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

</body>
</html>
<?php $conn->close(); ?>
