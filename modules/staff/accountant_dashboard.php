<?php
$page_title    = 'Dashboard';
$page_subtitle = 'Accountant Overview';
require_once __DIR__ . '/../../includes/acc_header.php';

// ── Stats ─────────────────────────────────────────────────────────────────────
// Quotations pending review
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM invoice i
    WHERE i.TYPE = 'Quotation' AND i.STATUS = 'Submitted'");
$quotations_pending = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Payments pending verification
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM payment p
    WHERE p.STATUS = 'Pending'");
$payments_pending = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Invoices generated (this month)
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM invoice i
    WHERE i.TYPE = 'Invoice' AND MONTH(i.INVOICE_DATE) = MONTH(NOW())
    AND YEAR(i.INVOICE_DATE) = YEAR(NOW())");
$invoices_month = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Total revenue this month
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(TOTAL), 0) as total FROM invoice i
    WHERE i.TYPE = 'Invoice' AND MONTH(i.INVOICE_DATE) = MONTH(NOW())
    AND YEAR(i.INVOICE_DATE) = YEAR(NOW())");
$revenue_month = mysqli_fetch_assoc($q)['total'] ?? 0;

// Outstanding balance
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(TOTAL - COALESCE(PAID_AMOUNT,0)), 0) as outstanding 
    FROM invoice i
    WHERE i.TYPE = 'Invoice' AND i.STATUS IN ('Pending Payment', 'Submitted')");
$outstanding = mysqli_fetch_assoc($q)['outstanding'] ?? 0;

// Recent quotations pending review
$recent_quotations = mysqli_query($conn, "
    SELECT i.INVOICE_ID, i.TOTAL, i.INVOICE_DATE, c.COMPANY_NAME,
           a.ASSIGN_ID, rf.REP_FAULT_ID
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.TYPE = 'Quotation' AND i.STATUS = 'Submitted'
    ORDER BY i.INVOICE_DATE DESC LIMIT 5");

// Recent payments pending verification
$recent_payments = mysqli_query($conn, "
    SELECT p.PAYMENT_ID, p.AMOUNT_PAID, p.PAYMENT_DATE, p.METHOD,
           i.INVOICE_ID, i.TOTAL, c.COMPANY_NAME
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE p.STATUS = 'Pending'
    ORDER BY p.PAYMENT_DATE DESC LIMIT 5");

// Recent invoices generated
$recent_invoices = mysqli_query($conn, "
    SELECT i.INVOICE_ID, i.TOTAL, i.STATUS, i.INVOICE_DATE,
           c.COMPANY_NAME
    FROM invoice i
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.TYPE = 'Invoice'
    ORDER BY i.INVOICE_DATE DESC LIMIT 5");

?>

<style>
    .company-balance {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    .company-balance-label {
        font-size: .9rem;
        opacity: 0.9;
        margin-bottom: .5rem;
    }
    .company-balance-amount {
        font-size: 2.5rem;
        font-weight: 700;
    }
</style>

<!-- Company Balance Card -->
<div class="company-balance">
    <div class="company-balance-label">COMPANY BALANCE</div>
    <div class="company-balance-amount">E<?= number_format($company_balance, 2) ?></div>
    <div style="font-size: .85rem; margin-top: 1rem; opacity: 0.8;">Last updated: <?= date('d M Y H:i') ?></div>
</div>

<!-- Quick-nav cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1.5rem">
<?php
$quick = [
    ['quotation_review',       'file-invoice',  'Review Quotations', 'blue',   $quotations_pending],
    ['payment_verification',   'circle-check',  'Verify Payments',   'green',  $payments_pending],
    ['generate_invoice',       'file-text',     'Generate Invoice',  'purple', null],
    ['acc_invoices',           'receipt',       'All Invoices',      'orange', $invoices_month],
    ['payment_tracking',       'trending-up',   'Payment Tracking',  'blue',   null],
    ['financial_reports',      'chart-line',    'Financial Reports', 'purple', null],
];
foreach ($quick as [$page,$icon,$label,$color,$badge]):
?>
<a href="<?= $page ?>.php" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1rem;display:flex;flex-direction:column;align-items:flex-start;gap:.5rem;text-decoration:none;transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.3)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
  <div class="stat-icon <?= $color ?>"><i class="ti ti-<?= $icon ?>"></i></div>
  <div style="font-size:.8rem;color:var(--text2);font-weight:500;line-height:1.2"><?= $label ?></div>
  <?php if ($badge !== null && $badge > 0): ?>
  <div style="font-size:1.3rem;font-weight:700;color:var(--text)"><?= $badge ?></div>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- Stats Row -->
<div class="grid-4" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-icon blue"><i class="ti ti-file-invoice"></i></div>
    <div>
      <div class="stat-num"><?= $quotations_pending ?></div>
      <div class="stat-label">Quotations Pending</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="ti ti-circle-check"></i></div>
    <div>
      <div class="stat-num"><?= $payments_pending ?></div>
      <div class="stat-label">Payments to Verify</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="ti ti-currency"></i></div>
    <div>
      <div class="stat-num">E<?= number_format($revenue_month, 2) ?></div>
      <div class="stat-label">This Month Revenue</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="ti ti-alert-circle"></i></div>
    <div>
      <div class="stat-num">E<?= number_format($outstanding, 2) ?></div>
      <div class="stat-label">Outstanding Balance</div>
    </div>
  </div>
</div>

<!-- Main Content Grid -->
<div class="grid-2">

  <!-- Quotations Pending Review -->
  <div class="card">
    <div class="card-title"><i class="ti ti-file-invoice" style="color:var(--accent)"></i> Quotations Pending Review</div>
    <?php if (mysqli_num_rows($recent_quotations) === 0): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No quotations pending</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php while ($q_item = mysqli_fetch_assoc($recent_quotations)): ?>
    <a href="quotation_review_details.php?invoice_id=<?= $q_item['INVOICE_ID'] ?>" style="display:flex;align-items:center;gap:.75rem;padding:.75rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);text-decoration:none;transition:background .2s" onmouseover="this.style.background='#2d333b'" onmouseout="this.style.background='var(--surface2)'">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($q_item['COMPANY_NAME'] ?? 'Unknown') ?></div>
        <div style="font-size:.75rem;color:var(--text2);margin-top:.15rem">Quote #<?= $q_item['INVOICE_ID'] ?> - Fault #<?= $q_item['REP_FAULT_ID'] ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">
        <span class="badge badge-info">Pending</span>
        <div style="font-size:.85rem;font-weight:600;color:var(--primary)">E<?= number_format($q_item['TOTAL'], 2) ?></div>
      </div>
    </a>
    <?php endwhile; ?>
    </div>
    <a href="quotation_review.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;width:100%;justify-content:center">View All Quotations</a>
    <?php endif; ?>
  </div>

  <!-- Payments Pending Verification -->
  <div class="card">
    <div class="card-title"><i class="ti ti-circle-check" style="color:var(--accent)"></i> Payments Pending Verification</div>
    <?php if (mysqli_num_rows($recent_payments) === 0): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No payments to verify</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php while ($p = mysqli_fetch_assoc($recent_payments)): ?>
    <a href="payment_verify_details.php?payment_id=<?= $p['PAYMENT_ID'] ?>" style="display:flex;align-items:center;gap:.75rem;padding:.75rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);text-decoration:none;transition:background .2s" onmouseover="this.style.background='#2d333b'" onmouseout="this.style.background='var(--surface2)'">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($p['COMPANY_NAME'] ?? 'Unknown') ?></div>
        <div style="font-size:.75rem;color:var(--text2);margin-top:.15rem"><?= $p['METHOD'] ?> - <?= date('d M Y', strtotime($p['PAYMENT_DATE'])) ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">
        <span class="badge badge-warning">Verify</span>
        <div style="font-size:.85rem;font-weight:600;color:var(--success)">E<?= number_format($p['AMOUNT_PAID'], 2) ?></div>
      </div>
    </a>
    <?php endwhile; ?>
    </div>
    <a href="payment_verification.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;width:100%;justify-content:center">View All Payments</a>
    <?php endif; ?>
  </div>

</div>

<!-- Recent Invoices -->
<div class="card" style="margin-top:1.5rem">
    <div class="card-title"><i class="ti ti-receipt" style="color:var(--accent)"></i> Recent Invoices</div>
    <?php if (mysqli_num_rows($recent_invoices) === 0): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No invoices generated</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php while ($inv = mysqli_fetch_assoc($recent_invoices)): 
        $status_colors = ['Paid'=>'success', 'Pending Payment'=>'warning', 'Submitted'=>'info'];
        $status_color = $status_colors[$inv['STATUS']] ?? 'secondary';
    ?>
    <a href="acc_invoice_details.php?invoice_id=<?= $inv['INVOICE_ID'] ?>" style="display:flex;align-items:center;gap:.75rem;padding:.75rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);text-decoration:none;transition:background .2s" onmouseover="this.style.background='#2d333b'" onmouseout="this.style.background='var(--surface2)'">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;color:var(--text)">Invoice #<?= $inv['INVOICE_ID'] ?></div>
        <div style="font-size:.75rem;color:var(--text2);margin-top:.15rem"><?= htmlspecialchars($inv['COMPANY_NAME'] ?? 'Unknown') ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">
        <span class="badge badge-<?= $status_color ?>"><?= $inv['STATUS'] ?></span>
        <div style="font-size:.85rem;font-weight:600;color:var(--primary)">E<?= number_format($inv['TOTAL'], 2) ?></div>
      </div>
    </a>
    <?php endwhile; ?>
    </div>
    <a href="acc_invoices.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;width:100%;justify-content:center">View All Invoices</a>
    <?php endif; ?>
</div>

<!-- Live clock -->
<div style="text-align:right;margin-top:1rem;font-size:.75rem;color:var(--text2)">
  Last updated: <span id="live-time"></span>
</div>
<script>
function tick(){ document.getElementById('live-time').textContent = new Date().toLocaleString('en-SZ'); }
tick(); setInterval(tick,1000);
</script>

<?php require_once '../../includes/acc_footer.php'; ?>
