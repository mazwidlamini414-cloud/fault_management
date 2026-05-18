<?php
$page_title    = 'Payment Verification';
$page_subtitle = 'Review & Verify Client Payments';
require_once __DIR__ . '/../../includes/acc_header.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status = mysqli_real_escape_string($conn, $_GET['status'] ?? 'Pending');
$filter_search = mysqli_real_escape_string($conn, $_GET['search'] ?? '');
$valid_statuses = ['Pending', 'Verified', 'Rejected', 'All'];
if (!in_array($filter_status, $valid_statuses)) $filter_status = 'Pending';

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where = "WHERE 1=1";
if ($filter_status !== 'All') {
    $where .= " AND p.STATUS = '$filter_status'";
}
if ($filter_search !== '') {
    $where .= " AND (c.COMPANY_NAME LIKE '%$filter_search%'
                 OR p.REFERENCE_NUMBER LIKE '%$filter_search%'
                 OR p.METHOD LIKE '%$filter_search%'
                 OR i.INVOICE_ID LIKE '%$filter_search%')";
}

// ── Fetch payments ─────────────────────────────────────────────────────────────
$payments = mysqli_query($conn, "
    SELECT p.PAYMENT_ID, p.AMOUNT_PAID, p.PAYMENT_DATE, p.METHOD,
           p.REFERENCE_NUMBER, p.STATUS,
           i.INVOICE_ID, i.TOTAL, i.STATUS as INV_STATUS, i.DUE_DATE,
           c.COMPANY_NAME, c.CONTACT_PERSON_NAME,
           rf.REP_FAULT_ID
    FROM payment p
    INNER JOIN invoice i         ON p.INVOICE_ID   = i.INVOICE_ID
    LEFT  JOIN client c          ON i.CLIENT_ID    = c.CLIENT_ID
    LEFT  JOIN assignment a      ON i.ASSIGN_ID    = a.ASSIGN_ID
    LEFT  JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    $where
    ORDER BY p.PAYMENT_DATE DESC
");

// ── Stats ──────────────────────────────────────────────────────────────────────
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(AMOUNT_PAID),0) t FROM payment WHERE STATUS='Pending'");
$s = mysqli_fetch_assoc($r);
$pending_count  = $s['c'];
$pending_amount = $s['t'];

$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(AMOUNT_PAID),0) t FROM payment WHERE STATUS='Verified'");
$s = mysqli_fetch_assoc($r);
$verified_count  = $s['c'];
$verified_amount = $s['t'];

$r = mysqli_query($conn, "SELECT COUNT(*) c FROM payment WHERE STATUS='Rejected'");
$rejected_count = mysqli_fetch_assoc($r)['c'];

$r = mysqli_query($conn, "SELECT COALESCE(SUM(AMOUNT_PAID),0) t FROM payment WHERE STATUS='Verified' AND MONTH(PAYMENT_DATE)=MONTH(NOW()) AND YEAR(PAYMENT_DATE)=YEAR(NOW())");
$verified_month = mysqli_fetch_assoc($r)['t'];

function payBadge($status) {
    $map = ['Pending'=>'badge-pending','Verified'=>'badge-paid','Rejected'=>'badge-overdue'];
    return $map[$status] ?? 'badge-cancelled';
}
?>

<style>
.filter-bar { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; margin-bottom:1.5rem; }
.filter-tab { padding:.4rem 1rem; border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; border:1px solid var(--border); background:var(--surface2); color:var(--text2); text-decoration:none; transition:all .2s; }
.filter-tab:hover { border-color:var(--accent); color:var(--accent); }
.filter-tab.active { background:rgba(240,165,0,.12); border-color:var(--accent); color:var(--accent); }
.filter-tab .cnt { background:var(--surface); border-radius:99px; padding:.05rem .4rem; font-size:.7rem; margin-left:.35rem; }
.payment-row:hover td { background:rgba(255,255,255,.025); cursor:pointer; }
.search-wrap { flex:1; min-width:200px; position:relative; }
.search-wrap input { padding-left:2.2rem; }
.search-wrap .ti { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:var(--text2); font-size:1rem; }
</style>

<!-- Page Header -->
<div class="page-head">
    <div>
        <h1><i class="ti ti-credit-card" style="color:var(--accent)"></i> Payment Verification</h1>
        <p>Review and approve client payment submissions</p>
    </div>
    <a href="payment_tracking.php" class="btn btn-secondary"><i class="ti ti-trending-up"></i> Payment Tracking</a>
</div>

<!-- Stats Row -->
<div class="grid-4" style="margin-bottom:1.5rem">
    <div class="stat-card" onclick="window.location='?status=Pending'" style="cursor:pointer">
        <div class="stat-icon orange"><i class="ti ti-clock"></i></div>
        <div>
            <div class="stat-num"><?= $pending_count ?></div>
            <div class="stat-label">Pending Review</div>
            <div class="stat-trend <?= $pending_count > 0 ? 'down' : 'up' ?>">
                E<?= number_format($pending_amount, 2) ?> awaiting
            </div>
        </div>
    </div>
    <div class="stat-card" onclick="window.location='?status=Verified'" style="cursor:pointer">
        <div class="stat-icon green"><i class="ti ti-circle-check"></i></div>
        <div>
            <div class="stat-num"><?= $verified_count ?></div>
            <div class="stat-label">Verified Total</div>
            <div class="stat-trend up">E<?= number_format($verified_amount, 2) ?></div>
        </div>
    </div>
    <div class="stat-card" onclick="window.location='?status=Rejected'" style="cursor:pointer">
        <div class="stat-icon red"><i class="ti ti-x"></i></div>
        <div>
            <div class="stat-num"><?= $rejected_count ?></div>
            <div class="stat-label">Rejected</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="ti ti-calendar-stats"></i></div>
        <div>
            <div class="stat-num">E<?= number_format($verified_month, 2) ?></div>
            <div class="stat-label">Verified This Month</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <?php
    $tabs = [
        ['status' => 'Pending',  'label' => 'Pending',  'cnt' => $pending_count],
        ['status' => 'Verified', 'label' => 'Verified', 'cnt' => $verified_count],
        ['status' => 'Rejected', 'label' => 'Rejected', 'cnt' => $rejected_count],
        ['status' => 'All',      'label' => 'All',      'cnt' => null],
    ];
    foreach ($tabs as $tab):
        $active = ($filter_status === $tab['status']) ? 'active' : '';
        $qs = http_build_query(['status' => $tab['status'], 'search' => $filter_search]);
    ?>
    <a href="?<?= $qs ?>" class="filter-tab <?= $active ?>">
        <?= $tab['label'] ?>
        <?php if ($tab['cnt'] !== null && $tab['cnt'] > 0): ?>
            <span class="cnt"><?= $tab['cnt'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <form method="GET" style="flex:1;min-width:220px;display:flex;gap:.5rem">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
        <div class="search-wrap" style="flex:1">
            <i class="ti ti-search"></i>
            <input type="text" name="search" value="<?= htmlspecialchars($filter_search) ?>"
                   class="form-control" placeholder="Search client, ref, method...">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-search"></i></button>
        <?php if ($filter_search): ?>
        <a href="?status=<?= $filter_status ?>" class="btn btn-secondary btn-sm"><i class="ti ti-x"></i></a>
        <?php endif; ?>
    </form>
</div>

<!-- Payments Table -->
<div class="card" style="padding:0">
    <?php if (mysqli_num_rows($payments) === 0): ?>
        <div class="empty-state" style="padding:3rem">
            <i class="ti ti-inbox"></i>
            <p>No <?= strtolower($filter_status !== 'All' ? $filter_status : '') ?> payments found<?= $filter_search ? ' matching "' . htmlspecialchars($filter_search) . '"' : '' ?>.</p>
            <?php if ($filter_status === 'Pending'): ?>
            <p style="font-size:.8rem;margin-top:.5rem;opacity:.7">Payments appear here when clients submit proof of payment.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:12px">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Client</th>
                    <th>Invoice</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th style="text-align:right">Amount</th>
                    <th style="text-align:right">Invoice Total</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($p = mysqli_fetch_assoc($payments)):
                $inv_num = 'INV-' . str_pad($p['INVOICE_ID'], 4, '0', STR_PAD_LEFT);
                $overdue = ($p['INV_STATUS'] !== 'Paid' && $p['DUE_DATE'] < date('Y-m-d'));
            ?>
            <tr class="payment-row" onclick="window.location='payment_verify_details.php?payment_id=<?= $p['PAYMENT_ID'] ?>'">
                <td style="font-weight:600;color:var(--text2)">#<?= $p['PAYMENT_ID'] ?></td>
                <td>
                    <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($p['COMPANY_NAME'] ?? 'Unknown') ?></div>
                    <?php if ($p['CONTACT_PERSON_NAME']): ?>
                    <div style="font-size:.72rem;color:var(--text2)"><?= htmlspecialchars($p['CONTACT_PERSON_NAME']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-weight:600;color:var(--info)"><?= $inv_num ?></div>
                    <?php if ($p['REP_FAULT_ID']): ?>
                    <div style="font-size:.72rem;color:var(--text2)">Fault #<?= $p['REP_FAULT_ID'] ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.82rem">
                        <?php
                        $method_icons = [
                            'Card Transfer'   => 'ti-credit-card',
                            'Bank Transfer'   => 'ti-building-bank',
                            'Mobile Money'    => 'ti-device-mobile',
                            'Cash'            => 'ti-cash',
                            'Wallet'          => 'ti-wallet',
                        ];
                        $micon = $method_icons[$p['METHOD']] ?? 'ti-cash';
                        ?>
                        <i class="ti <?= $micon ?>" style="color:var(--text2)"></i>
                        <?= htmlspecialchars($p['METHOD']) ?>
                    </span>
                </td>
                <td style="font-size:.8rem;color:var(--text2)"><?= $p['REFERENCE_NUMBER'] ? htmlspecialchars($p['REFERENCE_NUMBER']) : '<em style="opacity:.4">—</em>' ?></td>
                <td style="text-align:right;font-weight:700;color:var(--success);font-size:.95rem">E<?= number_format($p['AMOUNT_PAID'], 2) ?></td>
                <td style="text-align:right;color:var(--text2);font-size:.85rem">
                    E<?= number_format($p['TOTAL'], 2) ?>
                    <?php if ($overdue): ?>
                    <div style="font-size:.68rem;color:var(--danger)">Overdue</div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem;white-space:nowrap"><?= date('d M Y', strtotime($p['PAYMENT_DATE'])) ?></td>
                <td><span class="badge <?= payBadge($p['STATUS']) ?>"><?= $p['STATUS'] ?></span></td>
                <td onclick="event.stopPropagation()">
                    <a href="payment_verify_details.php?payment_id=<?= $p['PAYMENT_ID'] ?>"
                       class="btn btn-<?= $p['STATUS'] === 'Pending' ? 'primary' : 'secondary' ?> btn-sm">
                        <?= $p['STATUS'] === 'Pending' ? '<i class="ti ti-shield-check"></i> Verify' : '<i class="ti ti-eye"></i> View' ?>
                    </a>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Quick tip if pending -->
<?php if ($filter_status === 'Pending' && $pending_count > 0): ?>
<div class="alert alert-info" style="margin-top:1rem">
    <i class="ti ti-info-circle"></i>
    <span>You have <strong><?= $pending_count ?></strong> payment<?= $pending_count > 1 ? 's' : '' ?> totalling <strong>E<?= number_format($pending_amount, 2) ?></strong> awaiting verification. Click a row or the <em>Verify</em> button to review each one.</span>
</div>
<?php endif; ?>

<?php require_once '../../includes/acc_footer.php'; ?>



