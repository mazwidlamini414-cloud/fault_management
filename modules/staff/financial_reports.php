<?php
$page_title    = 'Financial Reports';
$page_subtitle = 'Accountant Analytics & Reporting';
require_once __DIR__ . '/../../includes/acc_header.php';

// ── Date Range Defaults ───────────────────────────────────────────────────────
$date_from  = $_GET['date_from']  ?? date('Y-m-01');
$date_to    = $_GET['date_to']    ?? date('Y-m-d');
$client_id  = (int)($_GET['client_id'] ?? 0);
$report_type = $_GET['report_type'] ?? 'overview';
$status_filter = $_GET['status'] ?? '';

// ── Clients for filter dropdown ───────────────────────────────────────────────
$all_clients = mysqli_query($conn, "SELECT CLIENT_ID, COMPANY_NAME FROM client ORDER BY COMPANY_NAME");

// ── Helper: date filter SQL ───────────────────────────────────────────────────
$date_sql  = "i.INVOICE_DATE BETWEEN '$date_from' AND '$date_to'";
$client_sql = $client_id ? "AND i.CLIENT_ID = $client_id" : "";

// ══════════════════════════════════════════════════════════════════════════════
// KPI METRICS
// ══════════════════════════════════════════════════════════════════════════════

// Total revenue collected (paid invoices in range)
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(i.PAID_AMOUNT),0) as revenue
    FROM invoice i WHERE i.TYPE='Invoice' AND $date_sql AND i.STATUS='Paid' $client_sql");
$total_revenue = mysqli_fetch_assoc($q)['revenue'];

// Total invoiced (all invoices in range)
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(i.TOTAL),0) as invoiced
    FROM invoice i WHERE i.TYPE='Invoice' AND $date_sql $client_sql");
$total_invoiced = mysqli_fetch_assoc($q)['invoiced'];

// Outstanding balance
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(i.TOTAL - COALESCE(i.PAID_AMOUNT,0)),0) as outstanding
    FROM invoice i
    WHERE i.TYPE='Invoice' AND $date_sql
    AND i.STATUS IN ('Pending Payment','Submitted') $client_sql");
$total_outstanding = mysqli_fetch_assoc($q)['outstanding'];

// Total payments verified in range
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(p.AMOUNT_PAID),0) as total_paid, COUNT(*) as count
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    WHERE p.STATUS='Verified' AND p.PAYMENT_DATE BETWEEN '$date_from' AND '$date_to' $client_sql");
$pay_row = mysqli_fetch_assoc($q);
$payments_verified = $pay_row['total_paid'];
$payments_count    = $pay_row['count'];

// Pending payments
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt, COALESCE(SUM(p.AMOUNT_PAID),0) as amt
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    WHERE p.STATUS='Pending' $client_sql");
$pend_row = mysqli_fetch_assoc($q);
$pending_payments_amt = $pend_row['amt'];
$pending_payments_cnt = $pend_row['cnt'];

// Faults in range
$q = mysqli_query($conn, "
    SELECT COUNT(*) as total,
           SUM(STATUS='Closed') as closed,
           SUM(STATUS='In Progress') as in_progress,
           SUM(STATUS='Client Approved') as approved
    FROM reported_fault
    WHERE REPORT_DATE BETWEEN '$date_from' AND '$date_to'");
$fault_stats = mysqli_fetch_assoc($q);

// Collection rate
$collection_rate = $total_invoiced > 0 ? round(($total_revenue / $total_invoiced) * 100, 1) : 0;

// ══════════════════════════════════════════════════════════════════════════════
// REPORT DATA SETS
// ══════════════════════════════════════════════════════════════════════════════

// 1) Revenue by Client
$revenue_by_client = mysqli_query($conn, "
    SELECT c.COMPANY_NAME, c.CLIENT_ID,
           COUNT(i.INVOICE_ID) as invoice_count,
           COALESCE(SUM(i.TOTAL),0) as total_invoiced,
           COALESCE(SUM(i.PAID_AMOUNT),0) as total_paid,
           COALESCE(SUM(i.TOTAL - COALESCE(i.PAID_AMOUNT,0)),0) as outstanding
    FROM invoice i
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.TYPE='Invoice' AND $date_sql
    GROUP BY i.CLIENT_ID
    ORDER BY total_invoiced DESC");

// 2) Invoice Aging (outstanding grouped by age)
$invoice_aging = mysqli_query($conn, "
    SELECT i.INVOICE_ID, c.COMPANY_NAME, i.INVOICE_DATE, i.DUE_DATE,
           i.TOTAL, COALESCE(i.PAID_AMOUNT,0) as paid,
           (i.TOTAL - COALESCE(i.PAID_AMOUNT,0)) as balance,
           i.STATUS,
           DATEDIFF(NOW(), i.DUE_DATE) as days_overdue
    FROM invoice i
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.TYPE='Invoice' AND i.STATUS IN ('Pending Payment','Submitted')
    AND (i.TOTAL - COALESCE(i.PAID_AMOUNT,0)) > 0
    $client_sql
    ORDER BY days_overdue DESC");

// 3) Payment Method Breakdown
$payment_methods = mysqli_query($conn, "
    SELECT p.METHOD,
           COUNT(*) as count,
           COALESCE(SUM(p.AMOUNT_PAID),0) as total
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    WHERE p.PAYMENT_DATE BETWEEN '$date_from' AND '$date_to'
    GROUP BY p.METHOD ORDER BY total DESC");

// 4) Monthly Revenue Trend (last 12 months)
$monthly_trend = mysqli_query($conn, "
    SELECT DATE_FORMAT(i.INVOICE_DATE,'%Y-%m') as month,
           DATE_FORMAT(i.INVOICE_DATE,'%b %Y') as month_label,
           COALESCE(SUM(i.TOTAL),0) as invoiced,
           COALESCE(SUM(i.PAID_AMOUNT),0) as collected
    FROM invoice i
    WHERE i.TYPE='Invoice'
    AND i.INVOICE_DATE >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(i.INVOICE_DATE,'%Y-%m')
    ORDER BY month ASC");

$trend_data = [];
while ($row = mysqli_fetch_assoc($monthly_trend)) $trend_data[] = $row;

// 5) All Invoices (for detailed invoice report)
$status_where = $status_filter ? "AND i.STATUS = '$status_filter'" : "";
$all_invoices = mysqli_query($conn, "
    SELECT i.INVOICE_ID, i.INVOICE_DATE, i.DUE_DATE, i.STATUS, i.TYPE,
           i.TOTAL, COALESCE(i.PAID_AMOUNT,0) as paid,
           c.COMPANY_NAME, c.COMPANY_EMAIL,
           rf.REP_FAULT_ID, rf.PRIORITY, rf.DESCRIPTION as fault_desc
    FROM invoice i
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    LEFT JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    LEFT JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE $date_sql $client_sql $status_where
    ORDER BY i.INVOICE_DATE DESC");

// 6) All Payments
$all_payments = mysqli_query($conn, "
    SELECT p.PAYMENT_ID, p.PAYMENT_DATE, p.AMOUNT_PAID, p.METHOD,
           p.STATUS, p.REFERENCE_NUMBER,
           i.INVOICE_ID, i.TOTAL,
           c.COMPANY_NAME
    FROM payment p
    INNER JOIN invoice i ON p.INVOICE_ID = i.INVOICE_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE p.PAYMENT_DATE BETWEEN '$date_from' AND '$date_to' $client_sql
    ORDER BY p.PAYMENT_DATE DESC");

// 7) Technician Performance (hours worked & jobs)
$tech_performance = mysqli_query($conn, "
    SELECT e.FULL_NAME,
           COUNT(DISTINCT wl.ASSIGN_ID) as jobs,
           COALESCE(SUM(wl.HOURS_SPENT),0) as total_hours,
           COALESCE(SUM(il.LINE_TOTAL),0) as labour_billed
    FROM employee e
    LEFT JOIN work_log wl ON e.EMP_ID = wl.EMP_ID
    LEFT JOIN invoice_line il ON (il.INVOICE_ID IN (
        SELECT inv.INVOICE_ID FROM invoice inv
        INNER JOIN assignment a ON inv.ASSIGN_ID = a.ASSIGN_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        WHERE at2.EMP_ID = e.EMP_ID
    ) AND il.DESCRIPTION LIKE '%Labour%')
    WHERE e.ROLE = 'Technician'
    GROUP BY e.EMP_ID ORDER BY jobs DESC");

// 8) Fault Status Financial Summary
$fault_financial = mysqli_query($conn, "
    SELECT rf.STATUS,
           COUNT(DISTINCT rf.REP_FAULT_ID) as fault_count,
           COALESCE(SUM(i.TOTAL),0) as total_value,
           COALESCE(SUM(i.PAID_AMOUNT),0) as paid_value
    FROM reported_fault rf
    LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    LEFT JOIN invoice i ON i.ASSIGN_ID = a.ASSIGN_ID AND i.TYPE='Invoice'
    WHERE rf.REPORT_DATE BETWEEN '$date_from' AND '$date_to'
    GROUP BY rf.STATUS ORDER BY fault_count DESC");

// 9) VAT Report (14% VAT breakdown FROM invoice lines)
$vat_report = mysqli_query($conn, "
    SELECT DATE_FORMAT(i.INVOICE_DATE,'%Y-%m') as month,
           DATE_FORMAT(i.INVOICE_DATE,'%b %Y') as month_label,
           COALESCE(SUM(i.TOTAL),0) as gross,
           COALESCE(SUM(i.TOTAL / 1.14),0) as net,
           COALESCE(SUM(i.TOTAL - i.TOTAL/1.14),0) as vat
    FROM invoice i
    WHERE i.TYPE='Invoice' AND $date_sql $client_sql
    GROUP BY DATE_FORMAT(i.INVOICE_DATE,'%Y-%m')
    ORDER BY month ASC");

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
/* ── Print Styles ────────────────────────────────────────────────────────── */
@media print {
  .no-print, .quick-nav-section, .filter-bar, .tabs-wrapper,
  .sidebar, nav, header, .acc-header, footer { display: none !important; }
  .print-only { display: block !important; }
  body { background: #fff !important; color: #000 !important; font-size: 11pt; }
  .report-section { display: block !important; page-break-inside: avoid; }
  .card { border: 1px solid #ccc !important; box-shadow: none !important; break-inside: avoid; }
  .report-tab-panel { display: block !important; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #bbb; padding: 6px 8px; }
  th { background: #f0f0f0 !important; color: #000 !important; }
  .badge { border: 1px solid #aaa; padding: 2px 6px; border-radius: 4px; }
  .kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
  .kpi-card { border: 1px solid #ccc; padding: 12px; border-radius: 6px; }
  .print-header { display: flex !important; justify-content: space-between; align-items: center;
    border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px; }
  .bar-chart { display: none; }
  h1 { font-size: 18pt; margin: 0; }
  .section-title { font-size: 13pt; font-weight: bold; margin: 16px 0 8px; }
}

/* ── Report Page Styles ──────────────────────────────────────────────────── */
.report-tabs { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: 1.5rem;
  border-bottom: 2px solid var(--border); padding-bottom: .5rem; }
.report-tab { padding: .5rem 1.1rem; border-radius: 8px 8px 0 0; cursor: pointer;
  font-size: .82rem; font-weight: 600; color: var(--text2);
  background: transparent; border: none; transition: all .2s;
  letter-spacing: .02em; }
.report-tab:hover { color: var(--text); background: var(--surface2); }
.report-tab.active { background: var(--accent); color: #fff; }

.report-tab-panel { display: none; }
.report-tab-panel.active { display: block; }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.kpi-card { background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; padding: 1.2rem 1.4rem; position: relative; overflow: hidden; }
.kpi-card::before { content:''; position: absolute; top:0; left:0; right:0; height: 3px; }
.kpi-card.blue::before  { background: #3b82f6; }
.kpi-card.green::before { background: #22c55e; }
.kpi-card.orange::before{ background: #f59e0b; }
.kpi-card.red::before   { background: #ef4444; }
.kpi-card.purple::before{ background: #a855f7; }
.kpi-card.teal::before  { background: #14b8a6; }
.kpi-label { font-size: .72rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--text2); margin-bottom: .4rem; }
.kpi-value { font-size: 1.7rem; font-weight: 800; color: var(--text); line-height: 1; }
.kpi-sub { font-size: .75rem; color: var(--text2); margin-top: .3rem; }

.filter-bar { background: var(--surface); border: 1px solid var(--border);
  border-radius: 12px; padding: 1rem 1.2rem; margin-bottom: 1.5rem;
  display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: .3rem; }
.filter-group label { font-size: .72rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; color: var(--text2); }
.filter-group select, .filter-group input {
  background: var(--surface2); border: 1px solid var(--border); color: var(--text);
  border-radius: 7px; padding: .45rem .75rem; font-size: .82rem; min-width: 150px; }

.data-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.data-table th { padding: .7rem 1rem; text-align: left; font-size: .72rem;
  font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--text2); border-bottom: 2px solid var(--border);
  background: var(--surface2); white-space: nowrap; }
.data-table td { padding: .65rem 1rem; border-bottom: 1px solid var(--border);
  vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: var(--surface2); }
.data-table tfoot td { font-weight: 700; background: var(--surface2);
  border-top: 2px solid var(--border); }

.badge { display: inline-flex; align-items: center; gap: .25rem;
  padding: .2rem .6rem; border-radius: 20px; font-size: .7rem; font-weight: 700; }
.badge-paid     { background: rgba(34,197,94,.15);  color: #22c55e; }
.badge-pending  { background: rgba(245,158,11,.15); color: #f59e0b; }
.badge-overdue  { background: rgba(239,68,68,.15);  color: #ef4444; }
.badge-info     { background: rgba(59,130,246,.15); color: #3b82f6; }
.badge-verified { background: rgba(20,184,166,.15); color: #14b8a6; }

.bar { height: 10px; border-radius: 5px; background: var(--border); overflow: hidden; min-width: 80px; }
.bar-fill { height: 100%; border-radius: 5px; background: var(--accent); }
.bar-fill.green { background: #22c55e; }
.bar-fill.orange{ background: #f59e0b; }
.bar-fill.red   { background: #ef4444; }

.chart-wrap { padding: 1rem 0; }
.trend-bars { display: flex; align-items: flex-end; gap: .3rem; height: 140px; padding: 0 .5rem; }
.trend-bar-group { display: flex; align-items: flex-end; gap: 2px; flex: 1; flex-direction: column; justify-content: flex-end; }
.trend-bar { border-radius: 3px 3px 0 0; width: 100%; min-width: 16px; transition: opacity .2s; }
.trend-bar:hover { opacity: .8; }
.trend-labels { display: flex; gap: .3rem; margin-top: .4rem; padding: 0 .5rem; }
.trend-label { flex: 1; text-align: center; font-size: .62rem; color: var(--text2); }
.trend-legend { display: flex; gap: 1.2rem; font-size: .75rem; color: var(--text2); margin-bottom: .5rem; }
.legend-dot { width: 10px; height: 10px; border-radius: 2px; display: inline-block; margin-right: 4px; }

.print-only { display: none; }
.print-actions { display: flex; gap: .75rem; flex-wrap: wrap; }

.section-divider { border: none; border-top: 1px solid var(--border); margin: 1.5rem 0; }

.aging-chip { display: inline-block; padding: .15rem .55rem; border-radius: 12px;
  font-size: .7rem; font-weight: 700; }
.aging-current  { background: rgba(34,197,94,.12);  color: #22c55e; }
.aging-30       { background: rgba(245,158,11,.12); color: #f59e0b; }
.aging-60       { background: rgba(249,115,22,.12); color: #f97316; }
.aging-90plus   { background: rgba(239,68,68,.12);  color: #ef4444; }

.summary-box { background: var(--surface2); border: 1px solid var(--border);
  border-radius: 10px; padding: 1rem 1.4rem; display: flex;
  justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
.summary-item { text-align: center; }
.summary-item .val { font-size: 1.2rem; font-weight: 700; color: var(--text); }
.summary-item .lbl { font-size: .7rem; color: var(--text2); margin-top: .1rem; }

/* Collection rate ring */
.ring-wrap { position: relative; width: 80px; height: 80px; }
.ring-wrap svg { transform: rotate(-90deg); }
.ring-pct { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  font-size: .9rem; font-weight: 800; color: var(--text); }
</style>

<!-- ══ Print Header (only shows when printing) ══ -->
<div class="print-only print-header">
  <div>
    <h1>BUSIQUIP ESWATINI — Financial Report</h1>
    <div style="font-size:10pt;color:#555;margin-top:4px">
      Period: <?= date('d M Y', strtotime($date_from)) ?> to <?= date('d M Y', strtotime($date_to)) ?>
      <?= $client_id ? " | Client Filter Applied" : "" ?>
    </div>
  </div>
  <div style="text-align:right;font-size:9pt;color:#888">
    Generated: <?= date('d M Y H:i') ?><br>
    Prepared by Accountant Module
  </div>
</div>

<!-- ══ Page Header ══ -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem" class="no-print">
  <div>
    <div style="font-size:1.3rem;font-weight:800;color:var(--text)">Financial Reports</div>
    <div style="font-size:.82rem;color:var(--text2);margin-top:.2rem">Comprehensive financial analytics & reporting</div>
  </div>
  <div class="print-actions">
    <button onclick="window.print()" class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:.4rem">
      <i class="ti ti-printer"></i> Print Report
    </button>
    <button onclick="exportCSV()" class="btn btn-secondary btn-sm" style="display:flex;align-items:center;gap:.4rem">
      <i class="ti ti-download"></i> Export CSV
    </button>
  </div>
</div>

<!-- ══ Filter Bar ══ -->
<form method="GET" class="filter-bar no-print" id="filter-form">
  <input type="hidden" name="report_type" id="active-tab-input" value="<?= htmlspecialchars($report_type) ?>">
  <div class="filter-group">
    <label>From Date</label>
    <input type="date" name="date_from" value="<?= $date_from ?>">
  </div>
  <div class="filter-group">
    <label>To Date</label>
    <input type="date" name="date_to" value="<?= $date_to ?>">
  </div>
  <div class="filter-group">
    <label>Client</label>
    <select name="client_id">
      <option value="0">All Clients</option>
      <?php mysqli_data_seek($all_clients, 0); while ($cl = mysqli_fetch_assoc($all_clients)): ?>
      <option value="<?= $cl['CLIENT_ID'] ?>" <?= $client_id == $cl['CLIENT_ID'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($cl['COMPANY_NAME']) ?>
      </option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Invoice Status</label>
    <select name="status">
      <option value="">All Statuses</option>
      <option value="Paid" <?= $status_filter=='Paid' ? 'selected':'' ?>>Paid</option>
      <option value="Pending Payment" <?= $status_filter=='Pending Payment' ? 'selected':'' ?>>Pending Payment</option>
      <option value="Submitted" <?= $status_filter=='Submitted' ? 'selected':'' ?>>Submitted</option>
    </select>
  </div>
  <div class="filter-group">
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
  </div>
  <div class="filter-group">
    <label>&nbsp;</label>
    <a href="financial_reports.php" class="btn btn-secondary btn-sm">Reset</a>
  </div>
</form>

<!-- ══ KPI Summary ══ -->
<div class="kpi-grid">
  <div class="kpi-card blue">
    <div class="kpi-label">Total Invoiced</div>
    <div class="kpi-value">E<?= number_format($total_invoiced, 0) ?></div>
    <div class="kpi-sub">Period: <?= date('d M', strtotime($date_from)) ?> – <?= date('d M Y', strtotime($date_to)) ?></div>
  </div>
  <div class="kpi-card green">
    <div class="kpi-label">Revenue Collected</div>
    <div class="kpi-value">E<?= number_format($total_revenue, 0) ?></div>
    <div class="kpi-sub">Collection rate: <?= $collection_rate ?>%</div>
  </div>
  <div class="kpi-card orange">
    <div class="kpi-label">Outstanding</div>
    <div class="kpi-value">E<?= number_format($total_outstanding, 0) ?></div>
    <div class="kpi-sub">Unpaid invoices</div>
  </div>
  <div class="kpi-card red">
    <div class="kpi-label">Pending Payments</div>
    <div class="kpi-value"><?= $pending_payments_cnt ?></div>
    <div class="kpi-sub">E<?= number_format($pending_payments_amt, 2) ?> to verify</div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-label">Faults (Period)</div>
    <div class="kpi-value"><?= $fault_stats['total'] ?? 0 ?></div>
    <div class="kpi-sub"><?= $fault_stats['closed'] ?? 0 ?> closed / <?= $fault_stats['in_progress'] ?? 0 ?> in progress</div>
  </div>
  <div class="kpi-card purple">
    <div class="kpi-label">Avg Invoice Value</div>
    <?php
      $inv_cnt_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM invoice i WHERE i.TYPE='Invoice' AND $date_sql $client_sql");
      $inv_cnt = mysqli_fetch_assoc($inv_cnt_q)['c'];
      $avg_inv = $inv_cnt > 0 ? $total_invoiced / $inv_cnt : 0;
    ?>
    <div class="kpi-value">E<?= number_format($avg_inv, 0) ?></div>
    <div class="kpi-sub"><?= $inv_cnt ?> invoices issued</div>
  </div>
</div>

<!-- ══ Tabs ══ -->
<div class="tabs-wrapper no-print">
  <div class="report-tabs">
    <button class="report-tab <?= $report_type=='overview'?'active':'' ?>" onclick="switchTab('overview',this)"><i class="ti ti-dashboard"></i> Overview</button>
    <button class="report-tab <?= $report_type=='invoices'?'active':'' ?>" onclick="switchTab('invoices',this)"><i class="ti ti-receipt"></i> Invoices</button>
    <button class="report-tab <?= $report_type=='payments'?'active':'' ?>" onclick="switchTab('payments',this)"><i class="ti ti-credit-card"></i> Payments</button>
    <button class="report-tab <?= $report_type=='aging'?'active':'' ?>" onclick="switchTab('aging',this)"><i class="ti ti-clock"></i> Aging</button>
    <button class="report-tab <?= $report_type=='clients'?'active':'' ?>" onclick="switchTab('clients',this)"><i class="ti ti-building"></i> By Client</button>
    <button class="report-tab <?= $report_type=='technicians'?'active':'' ?>" onclick="switchTab('technicians',this)"><i class="ti ti-tools"></i> Technicians</button>
    <button class="report-tab <?= $report_type=='vat'?'active':'' ?>" onclick="switchTab('vat',this)"><i class="ti ti-report"></i> VAT Report</button>
    <button class="report-tab <?= $report_type=='faults'?'active':'' ?>" onclick="switchTab('faults',this)"><i class="ti ti-alert-triangle"></i> Fault Financial</button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 1 — OVERVIEW
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='overview'?'active':'' ?>" id="tab-overview">

  <!-- Revenue Trend Chart -->
  <div class="card" style="margin-bottom:1.5rem">
    <div class="card-title"><i class="ti ti-chart-line" style="color:var(--accent)"></i> Monthly Revenue Trend (Last 12 Months)</div>
    <?php if (empty($trend_data)): ?>
      <div class="empty-state"><i class="ti ti-chart-bar"></i><p>No data available</p></div>
    <?php else:
      $max_val = max(array_map(fn($r)=> max((float)$r['invoiced'],(float)$r['collected']), $trend_data));
      $max_val = max($max_val, 1);
    ?>
    <div class="trend-legend">
      <span><span class="legend-dot" style="background:var(--accent)"></span>Invoiced</span>
      <span><span class="legend-dot" style="background:#22c55e"></span>Collected</span>
    </div>
    <div class="chart-wrap bar-chart">
      <div class="trend-bars">
        <?php foreach ($trend_data as $td):
          $h_inv = round(($td['invoiced']/$max_val)*130);
          $h_col = round(($td['collected']/$max_val)*130);
        ?>
        <div class="trend-bar-group" style="position:relative;flex:1;display:flex;gap:2px;align-items:flex-end;justify-content:center;height:140px"
             title="<?= $td['month_label'] ?>: Invoiced E<?= number_format($td['invoiced'],2) ?> | Collected E<?= number_format($td['collected'],2) ?>">
          <div class="trend-bar" style="height:<?= $h_inv ?>px;background:var(--accent);opacity:.8;flex:1"></div>
          <div class="trend-bar" style="height:<?= $h_col ?>px;background:#22c55e;flex:1"></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="trend-labels">
        <?php foreach ($trend_data as $td): ?>
        <div class="trend-label"><?= substr($td['month_label'],0,3) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- Data table for print -->
    <div style="margin-top:1.2rem;overflow-x:auto">
    <table class="data-table">
      <thead><tr>
        <th>Month</th><th>Invoiced</th><th>Collected</th><th>Outstanding</th><th>Collection Rate</th>
      </tr></thead>
      <tbody>
      <?php foreach ($trend_data as $td):
        $out = $td['invoiced'] - $td['collected'];
        $rate = $td['invoiced'] > 0 ? round(($td['collected']/$td['invoiced'])*100,1) : 0;
      ?>
      <tr>
        <td><?= $td['month_label'] ?></td>
        <td>E<?= number_format($td['invoiced'],2) ?></td>
        <td style="color:#22c55e;font-weight:600">E<?= number_format($td['collected'],2) ?></td>
        <td style="color:#f59e0b">E<?= number_format($out,2) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:.5rem">
            <div class="bar" style="flex:1"><div class="bar-fill <?= $rate>=80?'green':($rate>=50?'orange':'red') ?>" style="width:<?= $rate ?>%"></div></div>
            <span style="font-size:.75rem;font-weight:600;min-width:35px"><?= $rate ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php
          $t_inv = array_sum(array_column($trend_data,'invoiced'));
          $t_col = array_sum(array_column($trend_data,'collected'));
          $t_rate = $t_inv > 0 ? round(($t_col/$t_inv)*100,1) : 0;
        ?>
        <tr>
          <td>TOTAL</td>
          <td>E<?= number_format($t_inv,2) ?></td>
          <td>E<?= number_format($t_col,2) ?></td>
          <td>E<?= number_format($t_inv-$t_col,2) ?></td>
          <td><?= $t_rate ?>%</td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Payment Methods + Fault Summary side by side -->
  <div class="grid-2">
    <div class="card">
      <div class="card-title"><i class="ti ti-credit-card" style="color:var(--accent)"></i> Payment Methods Breakdown</div>
      <?php mysqli_data_seek($payment_methods, 0); $pm_rows = []; while ($r = mysqli_fetch_assoc($payment_methods)) $pm_rows[] = $r;
      $pm_total = array_sum(array_column($pm_rows,'total')); ?>
      <?php if (empty($pm_rows)): ?>
        <div class="empty-state"><i class="ti ti-inbox"></i><p>No payments in period</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Method</th><th>Count</th><th>Total</th><th>Share</th></tr></thead>
        <tbody>
        <?php foreach ($pm_rows as $pm):
          $pct = $pm_total > 0 ? round(($pm['total']/$pm_total)*100,1) : 0; ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($pm['METHOD'] ?: 'Other') ?></td>
          <td><?= $pm['count'] ?></td>
          <td>E<?= number_format($pm['total'],2) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem">
              <div class="bar" style="flex:1"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
              <span style="font-size:.75rem;min-width:35px"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>TOTAL</td><td><?= array_sum(array_column($pm_rows,'count')) ?></td><td>E<?= number_format($pm_total,2) ?></td><td>100%</td></tr></tfoot>
      </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title"><i class="ti ti-alert-triangle" style="color:var(--accent)"></i> Fault Financial Status</div>
      <?php $ff_rows = []; while ($r = mysqli_fetch_assoc($fault_financial)) $ff_rows[] = $r; ?>
      <?php if (empty($ff_rows)): ?>
        <div class="empty-state"><i class="ti ti-inbox"></i><p>No data</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Status</th><th>Faults</th><th>Value</th><th>Collected</th></tr></thead>
        <tbody>
        <?php foreach ($ff_rows as $ff): ?>
        <tr>
          <td><span class="badge badge-info"><?= htmlspecialchars($ff['STATUS']) ?></span></td>
          <td><?= $ff['fault_count'] ?></td>
          <td>E<?= number_format($ff['total_value'],2) ?></td>
          <td style="color:#22c55e;font-weight:600">E<?= number_format($ff['paid_value'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 2 — INVOICES
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='invoices'?'active':'' ?>" id="tab-invoices">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
      <div class="card-title" style="margin:0"><i class="ti ti-receipt" style="color:var(--accent)"></i> Invoice Register</div>
      <div style="font-size:.78rem;color:var(--text2)" id="invoice-count"></div>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table" id="invoices-table">
      <thead><tr>
        <th onclick="sortTable('invoices-table',0)" style="cursor:pointer">INV # ↕</th>
        <th onclick="sortTable('invoices-table',1)" style="cursor:pointer">Date ↕</th>
        <th>Due Date</th>
        <th>Client</th>
        <th>Fault #</th>
        <th>Priority</th>
        <th onclick="sortTable('invoices-table',6,true)" style="cursor:pointer">Total ↕</th>
        <th>Paid</th>
        <th>Balance</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php
        $grand_total = 0; $grand_paid = 0; $row_count = 0;
        while ($inv = mysqli_fetch_assoc($all_invoices)):
          $balance = $inv['TOTAL'] - $inv['paid'];
          $grand_total += $inv['TOTAL']; $grand_paid += $inv['paid']; $row_count++;
          $sc = ['Paid'=>'badge-paid','Pending Payment'=>'badge-pending','Submitted'=>'badge-info'];
          $s_badge = $sc[$inv['STATUS']] ?? 'badge-info';
          $is_overdue = ($balance > 0 && strtotime($inv['DUE_DATE']) < time() && $inv['STATUS'] != 'Paid');
      ?>
      <tr>
        <td style="font-weight:700;color:var(--accent)">
          <a href="acc_invoice_details.php?invoice_id=<?= $inv['INVOICE_ID'] ?>" style="color:var(--accent);text-decoration:none">#<?= $inv['INVOICE_ID'] ?></a>
        </td>
        <td><?= date('d M Y', strtotime($inv['INVOICE_DATE'])) ?></td>
        <td style="<?= $is_overdue ? 'color:#ef4444;font-weight:600' : '' ?>">
          <?= date('d M Y', strtotime($inv['DUE_DATE'])) ?>
          <?= $is_overdue ? '<br><span style="font-size:.65rem">OVERDUE</span>' : '' ?>
        </td>
        <td><?= htmlspecialchars($inv['COMPANY_NAME'] ?? 'Unknown') ?></td>
        <td><?= $inv['REP_FAULT_ID'] ? '#'.$inv['REP_FAULT_ID'] : '—' ?></td>
        <td><?php if ($inv['PRIORITY']): ?>
          <span class="badge <?= $inv['PRIORITY']=='High'?'badge-overdue':($inv['PRIORITY']=='Medium'?'badge-pending':'badge-info') ?>"><?= $inv['PRIORITY'] ?></span>
        <?php else: ?>—<?php endif; ?></td>
        <td style="font-weight:700">E<?= number_format($inv['TOTAL'],2) ?></td>
        <td style="color:#22c55e;font-weight:600">E<?= number_format($inv['paid'],2) ?></td>
        <td style="<?= $balance>0?'color:#f59e0b;font-weight:600':'' ?>">E<?= number_format($balance,2) ?></td>
        <td><span class="badge <?= $s_badge ?>"><?= $inv['STATUS'] ?></span></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right">TOTALS (<?= $row_count ?> invoices)</td>
          <td>E<?= number_format($grand_total,2) ?></td>
          <td>E<?= number_format($grand_paid,2) ?></td>
          <td>E<?= number_format($grand_total-$grand_paid,2) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 3 — PAYMENTS
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='payments'?'active':'' ?>" id="tab-payments">
  <div class="card">
    <div class="card-title"><i class="ti ti-credit-card" style="color:var(--accent)"></i> Payment Register</div>
    <div style="overflow-x:auto">
    <table class="data-table" id="payments-table">
      <thead><tr>
        <th>PAY #</th>
        <th onclick="sortTable('payments-table',1)" style="cursor:pointer">Date ↕</th>
        <th>Client</th>
        <th>Invoice #</th>
        <th>Invoice Total</th>
        <th>Amount Paid</th>
        <th>Method</th>
        <th>Reference</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php
        $ptotal = 0; $pcount = 0;
        while ($p = mysqli_fetch_assoc($all_payments)):
          $ptotal += $p['AMOUNT_PAID']; $pcount++;
          $ps = $p['STATUS']=='Verified' ? 'badge-verified' : ($p['STATUS']=='Pending' ? 'badge-pending' : 'badge-info');
      ?>
      <tr>
        <td style="font-weight:700;color:var(--accent)">#<?= $p['PAYMENT_ID'] ?></td>
        <td><?= date('d M Y', strtotime($p['PAYMENT_DATE'])) ?></td>
        <td><?= htmlspecialchars($p['COMPANY_NAME'] ?? 'Unknown') ?></td>
        <td><a href="acc_invoice_details.php?invoice_id=<?= $p['INVOICE_ID'] ?>" style="color:var(--accent);text-decoration:none">#<?= $p['INVOICE_ID'] ?></a></td>
        <td>E<?= number_format($p['TOTAL'],2) ?></td>
        <td style="font-weight:700;color:#22c55e">E<?= number_format($p['AMOUNT_PAID'],2) ?></td>
        <td><?= htmlspecialchars($p['METHOD']) ?></td>
        <td style="font-size:.75rem;color:var(--text2)"><?= $p['REFERENCE_NUMBER'] ?: '—' ?></td>
        <td><span class="badge <?= $ps ?>"><?= $p['STATUS'] ?></span></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right">TOTAL (<?= $pcount ?> payments)</td>
          <td style="color:#22c55e">E<?= number_format($ptotal,2) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 4 — AGING REPORT
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='aging'?'active':'' ?>" id="tab-aging">

  <?php
    // Collect aging data
    $aging_rows = [];
    while ($ar = mysqli_fetch_assoc($invoice_aging)) $aging_rows[] = $ar;
    $age_current=0; $age_30=0; $age_60=0; $age_90=0;
    $age_current_amt=0; $age_30_amt=0; $age_60_amt=0; $age_90_amt=0;
    foreach ($aging_rows as $ar) {
      $d = (int)$ar['days_overdue'];
      if ($d <= 0) { $age_current++; $age_current_amt += $ar['balance']; }
      elseif ($d <= 30) { $age_30++; $age_30_amt += $ar['balance']; }
      elseif ($d <= 60) { $age_60++; $age_60_amt += $ar['balance']; }
      else { $age_90++; $age_90_amt += $ar['balance']; }
    }
    $age_total_amt = $age_current_amt + $age_30_amt + $age_60_amt + $age_90_amt;
  ?>

  <!-- Aging Summary Boxes -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="kpi-card green">
      <div class="kpi-label">Current (Not Due)</div>
      <div class="kpi-value">E<?= number_format($age_current_amt,0) ?></div>
      <div class="kpi-sub"><?= $age_current ?> invoices</div>
    </div>
    <div class="kpi-card orange">
      <div class="kpi-label">1–30 Days Overdue</div>
      <div class="kpi-value">E<?= number_format($age_30_amt,0) ?></div>
      <div class="kpi-sub"><?= $age_30 ?> invoices</div>
    </div>
    <div class="kpi-card" style="border-top-color:#f97316">
      <div class="kpi-label">31–60 Days Overdue</div>
      <div class="kpi-value" style="color:#f97316">E<?= number_format($age_60_amt,0) ?></div>
      <div class="kpi-sub"><?= $age_60 ?> invoices</div>
    </div>
    <div class="kpi-card red">
      <div class="kpi-label">61+ Days Overdue</div>
      <div class="kpi-value">E<?= number_format($age_90_amt,0) ?></div>
      <div class="kpi-sub"><?= $age_90 ?> invoices — CRITICAL</div>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><i class="ti ti-clock" style="color:var(--accent)"></i> Aging Analysis — Outstanding Invoices</div>
    <?php if (empty($aging_rows)): ?>
      <div class="empty-state"><i class="ti ti-check"></i><p>No outstanding invoices — all clear!</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="aging-table">
      <thead><tr>
        <th>INV #</th>
        <th>Client</th>
        <th>Invoice Date</th>
        <th>Due Date</th>
        <th>Total</th>
        <th>Paid</th>
        <th>Balance Due</th>
        <th>Days Overdue</th>
        <th>Aging Bracket</th>
        <th>Status</th>
        <th class="no-print">Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($aging_rows as $ar):
        $d = (int)$ar['days_overdue'];
        if ($d <= 0)       { $bracket = 'Current';       $bc = 'aging-current'; }
        elseif ($d <= 30)  { $bracket = '1–30 Days';     $bc = 'aging-30'; }
        elseif ($d <= 60)  { $bracket = '31–60 Days';    $bc = 'aging-60'; }
        else               { $bracket = '60+ Days';      $bc = 'aging-90plus'; }
        $sc = ['Paid'=>'badge-paid','Pending Payment'=>'badge-pending','Submitted'=>'badge-info'];
        $s_badge = $sc[$ar['STATUS']] ?? 'badge-info';
      ?>
      <tr>
        <td style="font-weight:700;color:var(--accent)">
          <a href="acc_invoice_details.php?invoice_id=<?= $ar['INVOICE_ID'] ?>" style="color:var(--accent);text-decoration:none">#<?= $ar['INVOICE_ID'] ?></a>
        </td>
        <td style="font-weight:600"><?= htmlspecialchars($ar['COMPANY_NAME'] ?? 'Unknown') ?></td>
        <td><?= date('d M Y', strtotime($ar['INVOICE_DATE'])) ?></td>
        <td><?= date('d M Y', strtotime($ar['DUE_DATE'])) ?></td>
        <td>E<?= number_format($ar['balance']+$ar['paid'],2) ?></td>
        <td style="color:#22c55e">E<?= number_format($ar['paid'],2) ?></td>
        <td style="font-weight:700;color:<?= $d>60?'#ef4444':($d>30?'#f97316':'#f59e0b') ?>">E<?= number_format($ar['balance'],2) ?></td>
        <td style="font-weight:700;color:<?= $d>60?'#ef4444':($d>30?'#f97316':($d>0?'#f59e0b':'#22c55e')) ?>">
          <?= $d <= 0 ? 'Not due' : $d.' days' ?>
        </td>
        <td><span class="aging-chip <?= $bc ?>"><?= $bracket ?></span></td>
        <td><span class="badge <?= $s_badge ?>"><?= $ar['STATUS'] ?></span></td>
        <td class="no-print">
          <a href="acc_invoice_details.php?invoice_id=<?= $ar['INVOICE_ID'] ?>" class="btn btn-secondary btn-sm" style="padding:.2rem .6rem;font-size:.72rem">View</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right">TOTAL OUTSTANDING</td>
          <td style="font-weight:800;color:#ef4444">E<?= number_format($age_total_amt,2) ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 5 — BY CLIENT
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='clients'?'active':'' ?>" id="tab-clients">
  <div class="card">
    <div class="card-title"><i class="ti ti-building" style="color:var(--accent)"></i> Revenue by Client</div>
    <?php $rbc_rows = []; while ($r = mysqli_fetch_assoc($revenue_by_client)) $rbc_rows[] = $r;
    $max_rbc = max(array_column($rbc_rows,'total_invoiced') ?: [1]); ?>
    <?php if (empty($rbc_rows)): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No data for period</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="clients-table">
      <thead><tr>
        <th>Client</th>
        <th>Invoices</th>
        <th onclick="sortTable('clients-table',2,true)" style="cursor:pointer">Total Invoiced ↕</th>
        <th>Total Collected</th>
        <th>Outstanding</th>
        <th>Collection Rate</th>
        <th class="no-print">Profile</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rbc_rows as $rc):
        $rate_c = $rc['total_invoiced'] > 0 ? round(($rc['total_paid']/$rc['total_invoiced'])*100,1) : 0;
        $barw = $max_rbc > 0 ? round(($rc['total_invoiced']/$max_rbc)*100) : 0;
      ?>
      <tr>
        <td>
          <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars($rc['COMPANY_NAME'] ?? 'Unknown') ?></div>
          <div class="bar" style="margin-top:.3rem;width:<?= min($barw,100) ?>%"><div class="bar-fill" style="width:100%"></div></div>
        </td>
        <td><?= $rc['invoice_count'] ?></td>
        <td style="font-weight:700">E<?= number_format($rc['total_invoiced'],2) ?></td>
        <td style="color:#22c55e;font-weight:600">E<?= number_format($rc['total_paid'],2) ?></td>
        <td style="color:<?= $rc['outstanding']>0?'#f59e0b':'#22c55e' ?>;font-weight:600">E<?= number_format($rc['outstanding'],2) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:.5rem">
            <div class="bar"><div class="bar-fill <?= $rate_c>=80?'green':($rate_c>=50?'orange':'red') ?>" style="width:<?= $rate_c ?>%"></div></div>
            <span style="font-size:.75rem;font-weight:700;min-width:38px"><?= $rate_c ?>%</span>
          </div>
        </td>
        <td class="no-print">
          <a href="?client_id=<?= $rc['CLIENT_ID'] ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&report_type=invoices" class="btn btn-secondary btn-sm" style="padding:.2rem .6rem;font-size:.72rem">Filter</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php
          $t_inv_c = array_sum(array_column($rbc_rows,'total_invoiced'));
          $t_paid_c = array_sum(array_column($rbc_rows,'total_paid'));
          $t_out_c = array_sum(array_column($rbc_rows,'outstanding'));
          $t_rate_c = $t_inv_c > 0 ? round(($t_paid_c/$t_inv_c)*100,1) : 0;
        ?>
        <tr>
          <td>TOTAL</td>
          <td><?= array_sum(array_column($rbc_rows,'invoice_count')) ?></td>
          <td>E<?= number_format($t_inv_c,2) ?></td>
          <td>E<?= number_format($t_paid_c,2) ?></td>
          <td>E<?= number_format($t_out_c,2) ?></td>
          <td><?= $t_rate_c ?>%</td>
          <td class="no-print"></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 6 — TECHNICIAN PERFORMANCE
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='technicians'?'active':'' ?>" id="tab-technicians">
  <div class="card">
    <div class="card-title"><i class="ti ti-tools" style="color:var(--accent)"></i> Technician Performance & Labour Billing</div>
    <?php $tp_rows = []; while ($r = mysqli_fetch_assoc($tech_performance)) $tp_rows[] = $r;
    $max_jobs = max(array_column($tp_rows,'jobs') ?: [1]); ?>
    <?php if (empty($tp_rows)): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No technician data</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="tech-table">
      <thead><tr>
        <th>Technician</th>
        <th onclick="sortTable('tech-table',1,true)" style="cursor:pointer">Jobs ↕</th>
        <th>Total Hours</th>
        <th>Avg Hours/Job</th>
        <th>Labour Billed</th>
        <th>Job Volume</th>
      </tr></thead>
      <tbody>
      <?php foreach ($tp_rows as $tp):
        $avg_h = $tp['jobs'] > 0 ? round($tp['total_hours']/$tp['jobs'],1) : 0;
        $jobbar = $max_jobs > 0 ? round(($tp['jobs']/$max_jobs)*100) : 0;
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:.6rem">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;color:#fff;flex-shrink:0">
              <?= strtoupper(substr($tp['FULL_NAME'],0,1)) ?>
            </div>
            <span style="font-weight:600"><?= htmlspecialchars($tp['FULL_NAME']) ?></span>
          </div>
        </td>
        <td style="font-weight:800;font-size:1.1rem;color:var(--accent)"><?= $tp['jobs'] ?></td>
        <td><?= number_format($tp['total_hours'],1) ?> hrs</td>
        <td><?= $avg_h ?> hrs</td>
        <td style="font-weight:600;color:#22c55e">E<?= number_format($tp['labour_billed'],2) ?></td>
        <td>
          <div class="bar"><div class="bar-fill" style="width:<?= $jobbar ?>%"></div></div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>TOTAL</td>
          <td><?= array_sum(array_column($tp_rows,'jobs')) ?></td>
          <td><?= number_format(array_sum(array_column($tp_rows,'total_hours')),1) ?> hrs</td>
          <td>—</td>
          <td>E<?= number_format(array_sum(array_column($tp_rows,'labour_billed')),2) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Work Logs detail -->
  <div class="card" style="margin-top:1.5rem">
    <div class="card-title"><i class="ti ti-clipboard-list" style="color:var(--accent)"></i> Recent Work Logs</div>
    <?php $wlogs = mysqli_query($conn, "
      SELECT wl.LOG_DATE, wl.LOG_TYPE, wl.ACTION_TAKEN, wl.HOURS_SPENT,
             e.FULL_NAME, rf.REP_FAULT_ID, c.COMPANY_NAME
      FROM work_log wl
      INNER JOIN employee e ON wl.EMP_ID = e.EMP_ID
      LEFT JOIN assignment a ON wl.ASSIGN_ID = a.ASSIGN_ID
      LEFT JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
      LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
      WHERE wl.LOG_DATE BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'
      ORDER BY wl.LOG_DATE DESC LIMIT 30"); ?>
    <div style="overflow-x:auto">
    <table class="data-table">
      <thead><tr>
        <th>Date/Time</th><th>Technician</th><th>Client</th><th>Fault #</th><th>Type</th><th>Hours</th><th>Notes</th>
      </tr></thead>
      <tbody>
      <?php while ($wl = mysqli_fetch_assoc($wlogs)): ?>
      <tr>
        <td style="white-space:nowrap"><?= date('d M Y H:i', strtotime($wl['LOG_DATE'])) ?></td>
        <td style="font-weight:600"><?= htmlspecialchars($wl['FULL_NAME']) ?></td>
        <td><?= htmlspecialchars($wl['COMPANY_NAME'] ?? '—') ?></td>
        <td><?= $wl['REP_FAULT_ID'] ? '#'.$wl['REP_FAULT_ID'] : '—' ?></td>
        <td><span class="badge <?= $wl['LOG_TYPE']=='Complete'?'badge-paid':'badge-info' ?>"><?= $wl['LOG_TYPE'] ?></span></td>
        <td><?= number_format($wl['HOURS_SPENT'],1) ?></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.77rem;color:var(--text2)"><?= htmlspecialchars($wl['ACTION_TAKEN']) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 7 — VAT REPORT
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='vat'?'active':'' ?>" id="tab-vat">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
      <div class="card-title" style="margin:0"><i class="ti ti-report" style="color:var(--accent)"></i> VAT Report (14% VAT)</div>
      <div style="font-size:.75rem;color:var(--text2);background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:.3rem .7rem">
        VAT Rate: <strong>14%</strong> | Net = Gross ÷ 1.14
      </div>
    </div>
    <?php $vat_rows = []; while ($r = mysqli_fetch_assoc($vat_report)) $vat_rows[] = $r; ?>
    <?php if (empty($vat_rows)): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No invoice data for period</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="vat-table">
      <thead><tr>
        <th>Month</th>
        <th>No. Invoices</th>
        <th>Gross Revenue (incl. VAT)</th>
        <th>Net Revenue (excl. VAT)</th>
        <th>VAT Amount (14%)</th>
        <th>VAT as % of Gross</th>
      </tr></thead>
      <tbody>
      <?php foreach ($vat_rows as $vr):
        $vat_pct = $vr['gross'] > 0 ? round(($vr['vat']/$vr['gross'])*100,2) : 0;
        $inv_cnt_v = mysqli_fetch_assoc(mysqli_query($conn, "
          SELECT COUNT(*) as c FROM invoice i
          WHERE i.TYPE='Invoice' AND DATE_FORMAT(i.INVOICE_DATE,'%Y-%m')='{$vr['month']}' $client_sql"))['c'];
      ?>
      <tr>
        <td style="font-weight:600"><?= $vr['month_label'] ?></td>
        <td><?= $inv_cnt_v ?></td>
        <td style="font-weight:700">E<?= number_format($vr['gross'],2) ?></td>
        <td>E<?= number_format($vr['net'],2) ?></td>
        <td style="font-weight:700;color:var(--accent)">E<?= number_format($vr['vat'],2) ?></td>
        <td><?= $vat_pct ?>%</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php
          $vt_gross = array_sum(array_column($vat_rows,'gross'));
          $vt_net   = array_sum(array_column($vat_rows,'net'));
          $vt_vat   = array_sum(array_column($vat_rows,'vat'));
        ?>
        <tr>
          <td colspan="2">PERIOD TOTAL</td>
          <td>E<?= number_format($vt_gross,2) ?></td>
          <td>E<?= number_format($vt_net,2) ?></td>
          <td style="color:var(--accent)">E<?= number_format($vt_vat,2) ?></td>
          <td><?= $vt_gross > 0 ? round(($vt_vat/$vt_gross)*100,2) : 0 ?>%</td>
        </tr>
      </tfoot>
    </table>
    </div>

    <!-- VAT Summary Box -->
    <div class="summary-box" style="margin-top:1.2rem">
      <div class="summary-item">
        <div class="val">E<?= number_format($vt_gross,2) ?></div>
        <div class="lbl">Gross Revenue</div>
      </div>
      <div style="font-size:1.5rem;color:var(--text2)">−</div>
      <div class="summary-item">
        <div class="val" style="color:var(--accent)">E<?= number_format($vt_vat,2) ?></div>
        <div class="lbl">Total VAT (14%)</div>
      </div>
      <div style="font-size:1.5rem;color:var(--text2)">=</div>
      <div class="summary-item">
        <div class="val" style="color:#22c55e">E<?= number_format($vt_net,2) ?></div>
        <div class="lbl">Net Revenue (ex. VAT)</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB 8 — FAULT FINANCIAL
═════════════════════════════════════════════════════════════════════════════ -->
<div class="report-tab-panel <?= $report_type=='faults'?'active':'' ?>" id="tab-faults">
  <div class="card">
    <div class="card-title"><i class="ti ti-alert-triangle" style="color:var(--accent)"></i> Fault-Based Financial Report</div>
    <?php $fault_detail = mysqli_query($conn, "
      SELECT rf.REP_FAULT_ID, rf.REPORT_DATE, rf.STATUS as fault_status,
             rf.PRIORITY, rf.REPORTED_BY,
             c.COMPANY_NAME,
             a.ASSIGN_DATE,
             i.INVOICE_ID, i.STATUS as inv_status, i.TOTAL, i.PAID_AMOUNT,
             i.TYPE as inv_type,
             p.PAYMENT_ID, p.STATUS as pay_status, p.AMOUNT_PAID, p.METHOD
      FROM reported_fault rf
      LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
      LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
      LEFT JOIN invoice i ON i.ASSIGN_ID = a.ASSIGN_ID
      LEFT JOIN payment p ON p.INVOICE_ID = i.INVOICE_ID
      WHERE rf.REPORT_DATE BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'
      " . ($client_id ? "AND rf.CLIENT_ID=$client_id" : "") . "
      ORDER BY rf.REPORT_DATE DESC"); ?>
    <div style="overflow-x:auto">
    <table class="data-table">
      <thead><tr>
        <th>Fault #</th>
        <th>Reported</th>
        <th>Client</th>
        <th>Priority</th>
        <th>Fault Status</th>
        <th>Invoice #</th>
        <th>Invoice Type</th>
        <th>Invoice Total</th>
        <th>Paid</th>
        <th>Inv. Status</th>
        <th>Pay. Status</th>
      </tr></thead>
      <tbody>
      <?php while ($fd = mysqli_fetch_assoc($fault_detail)):
        $sc = ['Paid'=>'badge-paid','Pending Payment'=>'badge-pending','Submitted'=>'badge-info'];
        $sb = $sc[$fd['inv_status']] ?? 'badge-info';
        $pb = $fd['pay_status']=='Verified' ? 'badge-verified' : ($fd['pay_status']=='Pending' ? 'badge-pending' : 'badge-info');
        $fs_col = [
          'Closed'=>'badge-paid','Client Approved'=>'badge-verified','Completed'=>'badge-info',
          'In Progress'=>'badge-pending','Assigned'=>'badge-info','Pending'=>'badge-pending','Rework Required'=>'badge-overdue'
        ];
        $fsb = $fs_col[$fd['fault_status']] ?? 'badge-info';
      ?>
      <tr>
        <td style="font-weight:700;color:var(--accent)">#<?= $fd['REP_FAULT_ID'] ?></td>
        <td style="white-space:nowrap"><?= date('d M Y', strtotime($fd['REPORT_DATE'])) ?></td>
        <td><?= htmlspecialchars($fd['COMPANY_NAME'] ?? 'Unknown') ?></td>
        <td><?php if ($fd['PRIORITY']): ?>
          <span class="badge <?= $fd['PRIORITY']=='High'?'badge-overdue':($fd['PRIORITY']=='Medium'?'badge-pending':'badge-info') ?>"><?= $fd['PRIORITY'] ?></span>
        <?php else: ?>—<?php endif; ?></td>
        <td><span class="badge <?= $fsb ?>"><?= $fd['fault_status'] ?></span></td>
        <td><?= $fd['INVOICE_ID'] ? '<a href="acc_invoice_details.php?invoice_id='.$fd['INVOICE_ID'].'" style="color:var(--accent);text-decoration:none">#'.$fd['INVOICE_ID'].'</a>' : '—' ?></td>
        <td><?= $fd['inv_type'] ?? '—' ?></td>
        <td><?= $fd['TOTAL'] !== null ? 'E'.number_format($fd['TOTAL'],2) : '—' ?></td>
        <td style="color:#22c55e"><?= $fd['PAID_AMOUNT'] !== null ? 'E'.number_format($fd['PAID_AMOUNT'],2) : '—' ?></td>
        <td><?= $fd['inv_status'] ? '<span class="badge '.$sb.'">'.$fd['inv_status'].'</span>' : '—' ?></td>
        <td><?= $fd['pay_status'] ? '<span class="badge '.$pb.'">'.$fd['pay_status'].'</span>' : '—' ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══ Footer Info ══ -->
<div style="margin-top:2rem;padding:1rem;background:var(--surface);border:1px solid var(--border);border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;font-size:.75rem;color:var(--text2)" class="no-print">
  <span><i class="ti ti-info-circle"></i> All amounts in Eswatini Lilangeni (E/SZL) · VAT rate 14%</span>
  <span>Report generated: <?= date('d M Y H:i:s') ?></span>
</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab, el) {
  document.querySelectorAll('.report-tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.report-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  el.classList.add('active');
  document.getElementById('active-tab-input').value = tab;
}

// ── Table sort ────────────────────────────────────────────────────────────────
function sortTable(tableId, colIdx, isNumeric = false) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  const asc   = table.dataset.sortCol == colIdx && table.dataset.sortDir == 'asc' ? false : true;
  table.dataset.sortCol = colIdx;
  table.dataset.sortDir = asc ? 'asc' : 'desc';
  rows.sort((a, b) => {
    const av = a.cells[colIdx]?.innerText.replace(/[^0-9.\-]/g,'') || '';
    const bv = b.cells[colIdx]?.innerText.replace(/[^0-9.\-]/g,'') || '';
    return isNumeric
      ? (asc ? parseFloat(av||0) - parseFloat(bv||0) : parseFloat(bv||0) - parseFloat(av||0))
      : (asc ? av.localeCompare(bv) : bv.localeCompare(av));
  });
  rows.forEach(r => tbody.appendChild(r));
}

// ── CSV Export ────────────────────────────────────────────────────────────────
function exportCSV() {
  const activePanel = document.querySelector('.report-tab-panel.active');
  if (!activePanel) return;
  const table = activePanel.querySelector('table.data-table');
  if (!table) { alert('No table data to export in current tab.'); return; }
  let csv = [];
  table.querySelectorAll('tr').forEach(row => {
    let cols = [];
    row.querySelectorAll('th, td').forEach(cell => {
      // Skip "no-print" cells
      if (!cell.classList.contains('no-print')) {
        cols.push('"' + cell.innerText.replace(/"/g,'""').replace(/\n/g,' ') + '"');
      }
    });
    csv.push(cols.join(','));
  });
  const blob = new Blob([csv.join('\n')], {type:'text/csv'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'busiquip_report_<?= date('Ymd') ?>.csv';
  a.click();
}

// ── Quick date presets ────────────────────────────────────────────────────────
function setDatePreset(preset) {
  const today = new Date();
  let from, to = today.toISOString().slice(0,10);
  if (preset === 'today') { from = to; }
  else if (preset === 'week') {
    const d = new Date(today); d.setDate(d.getDate()-6); from = d.toISOString().slice(0,10);
  } else if (preset === 'month') {
    from = today.getFullYear()+'-'+ String(today.getMonth()+1).padStart(2,'0') +'-01';
  } else if (preset === 'quarter') {
    const q = Math.floor(today.getMonth()/3);
    from = today.getFullYear()+'-'+ String(q*3+1).padStart(2,'0') +'-01';
  } else if (preset === 'year') {
    from = today.getFullYear()+'-01-01';
  }
  document.querySelector('[name=date_from]').value = from;
  document.querySelector('[name=date_to]').value   = to;
}
</script>

<!-- Date preset buttons -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:-1rem;margin-bottom:1rem" class="no-print">
  <span style="font-size:.72rem;color:var(--text2);align-self:center;font-weight:600">QUICK:</span>
  <?php foreach ([['today','Today'],['week','Last 7 Days'],['month','This Month'],['quarter','This Quarter'],['year','This Year']] as [$v,$l]): ?>
  <button onclick="setDatePreset('<?= $v ?>')" class="btn btn-secondary btn-sm" style="padding:.25rem .65rem;font-size:.72rem"><?= $l ?></button>
  <?php endforeach; ?>
</div>

<?php require_once '../../includes/acc_footer.php'; ?>



