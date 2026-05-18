<?php
$page_title    = 'Payment Tracking';
$page_subtitle = 'All Payments';
require_once __DIR__ . '/../../includes/acc_header.php';

// ── Stats ─────────────────────────────────────────────────────────────────────
$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(AMOUNT_PAID),0) total FROM payment WHERE STATUS='Verified'");
$verified_row   = mysqli_fetch_assoc($r);

$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(AMOUNT_PAID),0) total FROM payment WHERE STATUS='Pending'");
$pending_row    = mysqli_fetch_assoc($r);

$r = mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(AMOUNT_PAID),0) total FROM payment WHERE STATUS='Rejected'");
$rejected_row   = mysqli_fetch_assoc($r);

// ── Filters ───────────────────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? 'all';
$allowed_statuses = ['all','Pending','Verified','Rejected'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = 'all';

$where = $status_filter === 'all' ? '' : "WHERE p.STATUS = '" . mysqli_real_escape_string($conn, $status_filter) . "'";

// ── Fetch payments ────────────────────────────────────────────────────────────
$payments = mysqli_query($conn, "
    SELECT p.PAYMENT_ID, p.PAYMENT_DATE, p.AMOUNT_PAID, p.METHOD, p.REFERENCE_NUMBER, p.STATUS,
           i.INVOICE_ID, i.TOTAL AS INVOICE_TOTAL, i.STATUS AS INVOICE_STATUS,
           c.COMPANY_NAME, c.COMPANY_EMAIL,
           rf.REP_FAULT_ID
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    LEFT JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    LEFT JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    $where
    ORDER BY p.PAYMENT_DATE DESC, p.PAYMENT_ID DESC");
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-trending-up" style="color:var(--accent)"></i> Payment Tracking</h1>
    <p>Track all payment activity and verify incoming payments</p>
  </div>
  <a href="payment_verification.php" class="btn btn-primary btn-sm">
    <i class="ti ti-circle-check"></i> Verify Payments
  </a>
</div>

<!-- Stats -->
<div class="grid-4" style="margin-bottom:1.5rem;">
  <div class="stat-card">
    <div class="stat-icon green"><i class="ti ti-circle-check"></i></div>
    <div>
      <div class="stat-num"><?= $verified_row['c'] ?></div>
      <div class="stat-label">Verified</div>
      <div style="font-size:.75rem;color:var(--success);margin-top:.2rem;">E<?= number_format($verified_row['total'], 2) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="ti ti-clock"></i></div>
    <div>
      <div class="stat-num"><?= $pending_row['c'] ?></div>
      <div class="stat-label">Pending Verification</div>
      <div style="font-size:.75rem;color:var(--warning);margin-top:.2rem;">E<?= number_format($pending_row['total'], 2) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><i class="ti ti-circle-x"></i></div>
    <div>
      <div class="stat-num"><?= $rejected_row['c'] ?></div>
      <div class="stat-label">Rejected</div>
      <div style="font-size:.75rem;color:var(--danger);margin-top:.2rem;">E<?= number_format($rejected_row['total'], 2) ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="ti ti-currency"></i></div>
    <div>
      <div class="stat-num">E<?= number_format($verified_row['total'], 2) ?></div>
      <div class="stat-label">Total Collected</div>
    </div>
  </div>
</div>

<!-- Status filter tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;">
<?php
$tab_data = [
    'all'      => 'All Payments',
    'Pending'  => 'Pending',
    'Verified' => 'Verified',
    'Rejected' => 'Rejected',
];
foreach ($tab_data as $val => $label):
    $active = $status_filter === $val;
?>
<a href="?status=<?= $val ?>"
   style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;border:1px solid var(--border);
          background:<?= $active ? 'var(--surface2)' : 'var(--surface)' ?>;color:<?= $active ? 'var(--text)' : 'var(--text2)' ?>;">
    <?= $label ?>
</a>
<?php endforeach; ?>
</div>

<!-- Payments table -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Client</th>
        <th>Invoice</th>
        <th>Fault</th>
        <th>Method</th>
        <th>Reference</th>
        <th>Amount</th>
        <th>Date</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $count = 0;
    while ($p = mysqli_fetch_assoc($payments)):
        $count++;
        $status_map = [
            'Pending'  => ['class'=>'badge-pending',   'label'=>'Pending'],
            'Verified' => ['class'=>'badge-paid',      'label'=>'Verified'],
            'Rejected' => ['class'=>'badge-overdue',   'label'=>'Rejected'],
        ];
        $sb = $status_map[$p['STATUS']] ?? ['class'=>'badge-cancelled','label'=>$p['STATUS']];
    ?>
    <tr>
      <td style="color:var(--text2);font-size:.8rem;">#<?= $p['PAYMENT_ID'] ?></td>
      <td>
        <div style="font-weight:600;font-size:.875rem;"><?= htmlspecialchars($p['COMPANY_NAME'] ?? '—') ?></div>
        <div style="font-size:.72rem;color:var(--text2);"><?= htmlspecialchars($p['COMPANY_EMAIL'] ?? '') ?></div>
      </td>
      <td>
        <a href="acc_invoice_details.php?invoice_id=<?= $p['INVOICE_ID'] ?>"
           style="color:var(--info);text-decoration:none;font-size:.875rem;">
          INV-<?= str_pad($p['INVOICE_ID'], 4, '0', STR_PAD_LEFT) ?>
        </a>
        <div style="font-size:.72rem;color:var(--text2);">Total: E<?= number_format($p['INVOICE_TOTAL'], 2) ?></div>
      </td>
      <td>
        <?php if ($p['REP_FAULT_ID']): ?>
        <span style="font-size:.8rem;color:var(--text2);">Fault #<?= $p['REP_FAULT_ID'] ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="font-size:.85rem;"><?= htmlspecialchars($p['METHOD'] ?? '—') ?></td>
      <td style="font-size:.8rem;color:var(--text2);"><?= $p['REFERENCE_NUMBER'] ? htmlspecialchars($p['REFERENCE_NUMBER']) : '<span style="opacity:.5;">—</span>' ?></td>
      <td style="font-weight:700;color:var(--success);">E<?= number_format($p['AMOUNT_PAID'], 2) ?></td>
      <td style="font-size:.82rem;color:var(--text2);"><?= $p['PAYMENT_DATE'] ? date('d M Y', strtotime($p['PAYMENT_DATE'])) : '—' ?></td>
      <td><span class="badge <?= $sb['class'] ?>"><?= $sb['label'] ?></span></td>
      <td>
        <div style="display:flex;gap:.4rem;">
          <a href="payment_verify_details.php?payment_id=<?= $p['PAYMENT_ID'] ?>"
             class="btn btn-secondary btn-sm" title="View Details">
            <i class="ti ti-eye"></i>
          </a>
          <?php if ($p['STATUS'] === 'Pending'): ?>
          <a href="payment_verification.php?payment_id=<?= $p['PAYMENT_ID'] ?>"
             class="btn btn-success btn-sm" title="Verify">
            <i class="ti ti-circle-check"></i>
          </a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    <?php if ($count === 0): ?>
    <tr>
      <td colspan="10">
        <div class="empty-state"><i class="ti ti-inbox"></i><p>No payments found for the selected filter.</p></div>
      </td>
    </tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Payment trend chart -->
<?php
$monthly = mysqli_query($conn, "
    SELECT DATE_FORMAT(PAYMENT_DATE, '%b %Y') AS month,
           MONTH(PAYMENT_DATE) AS m, YEAR(PAYMENT_DATE) AS y,
           SUM(AMOUNT_PAID) AS total
    FROM payment
    WHERE STATUS='Verified'
    AND PAYMENT_DATE >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(PAYMENT_DATE), MONTH(PAYMENT_DATE)
    ORDER BY y, m");
$chart_labels = [];
$chart_data   = [];
while ($row = mysqli_fetch_assoc($monthly)) {
    $chart_labels[] = $row['month'];
    $chart_data[]   = (float)$row['total'];
}
?>
<div class="card" style="margin-top:1.5rem;">
  <div class="card-title"><i class="ti ti-chart-bar" style="color:var(--accent)"></i> Monthly Revenue (Last 6 Months)</div>
  <?php if (empty($chart_labels)): ?>
    <div style="color:var(--text2);font-size:.875rem;padding:1rem 0;">No verified payment data yet.</div>
  <?php else: ?>
  <canvas id="paymentChart" height="100"></canvas>
  <script>
  (function(){
    const ctx = document.getElementById('paymentChart').getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
          label: 'Revenue (E)',
          data: <?= json_encode($chart_data) ?>,
          backgroundColor: 'rgba(240,165,0,.25)',
          borderColor: 'rgba(240,165,0,.8)',
          borderWidth: 2,
          borderRadius: 6,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { labels: { color: '#8b949e' } } },
        scales: {
          x: { ticks: { color:'#8b949e' }, grid: { color:'#30363d' } },
          y: { ticks: { color:'#8b949e', callback: v => 'E'+v.toLocaleString() }, grid: { color:'#30363d' } }
        }
      }
    });
  })();
  </script>
  <?php endif; ?>
</div>

<?php require_once '../../includes/acc_footer.php'; ?>


