<?php
// ══════════════════════════════════════════════════════════════════════════════
// quotation_review.php
// ALL session/POST/redirect logic MUST come before acc_header.php is included,
// because acc_header.php outputs HTML starting at line 43 (<!DOCTYPE html>).
// Any header() call after that point causes "headers already sent".
// ══════════════════════════════════════════════════════════════════════════════

// 1. Start session & authenticate BEFORE any output
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['emp_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Accountant') {
    header('Location: ' . BASE_URL . '/modules/staff/accountant_login.php');
    exit();
}

// 2. DB connection needed for POST handling before acc_header runs
require_once __DIR__ . '/../../config/database.php';

$acc_id   = $_SESSION['emp_id'];
$acc_name = $_SESSION['emp_name'] ?? 'Accountant';

// ── Handle all POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action     = $_POST['action'];
    $invoice_id = intval($_POST['invoice_id'] ?? 0);

    // ── APPROVE ───────────────────────────────────────────────────────────────
    if ($action === 'approve' && $invoice_id) {
        mysqli_query($conn, "
            UPDATE invoice SET STATUS = 'Approved'
            WHERE  INVOICE_ID = $invoice_id AND TYPE = 'Quotation'");

        $acc_esc = mysqli_real_escape_string($conn, $acc_name);
        mysqli_query($conn, "
            INSERT INTO invoice_tracking
                   (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Approved',
                   'Approved by accountant $acc_esc', $acc_id, 'Accountant')");

        $_SESSION['toast'] = [
            'msg'  => "Quotation #$invoice_id approved. Click \"Generate Invoice\" to bill the client.",
            'type' => 'success'
        ];
        header('Location: quotation_review.php?status=Approved');
        exit();
    }

    // ── REJECT ────────────────────────────────────────────────────────────────
    if ($action === 'reject' && $invoice_id) {
        $reason     = trim($_POST['reason'] ?? 'No reason provided.');
        $reason_esc = mysqli_real_escape_string($conn, $reason);

        mysqli_query($conn, "
            UPDATE invoice SET STATUS = 'Rejected'
            WHERE  INVOICE_ID = $invoice_id AND TYPE = 'Quotation'");

        mysqli_query($conn, "
            INSERT INTO invoice_tracking
                   (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Rejected',
                   'Rejected by accountant. Reason: $reason_esc', $acc_id, 'Accountant')");

        // Notify the technician
        $tech_q = mysqli_query($conn, "
            SELECT at2.EMP_ID
            FROM   invoice i
            INNER  JOIN assignment a              ON i.ASSIGN_ID  = a.ASSIGN_ID
            INNER  JOIN assignment_technician at2 ON a.ASSIGN_ID  = at2.ASSIGN_ID
            WHERE  i.INVOICE_ID = $invoice_id LIMIT 1");
        if ($tech_row = mysqli_fetch_assoc($tech_q)) {
            $tid  = intval($tech_row['EMP_ID']);
            $note = mysqli_real_escape_string($conn,
                "Quotation #$invoice_id was rejected. Reason: $reason");
            mysqli_query($conn, "
                INSERT INTO notifications (user_id, user_type, title, message)
                VALUES ($tid, 'Employee', 'Quotation Rejected', '$note')");
        }

        $_SESSION['toast'] = [
            'msg'  => "Quotation #$invoice_id rejected. Technician has been notified.",
            'type' => 'error'
        ];
        header('Location: quotation_review.php?status=Rejected');
        exit();
    }

    // ── GENERATE INVOICE ──────────────────────────────────────────────────────
    if ($action === 'generate_invoice' && $invoice_id) {
        $q_row = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT i.*, a.ASSIGN_ID, c.CLIENT_ID
            FROM   invoice i
            INNER  JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
            LEFT   JOIN client c     ON i.CLIENT_ID = c.CLIENT_ID
            WHERE  i.INVOICE_ID = $invoice_id
              AND  i.TYPE       = 'Quotation'
              AND  i.STATUS     = 'Approved'"));

        if ($q_row) {
            $assign_id = intval($q_row['ASSIGN_ID']);
            $client_id = intval($q_row['CLIENT_ID']);
            $total     = floatval($q_row['TOTAL']);
            $due_date  = date('Y-m-d', strtotime('+14 days'));

            mysqli_query($conn, "
                INSERT INTO invoice
                       (CLIENT_ID, ASSIGN_ID, INVOICE_DATE, DUE_DATE,
                        STATUS, TYPE, TOTAL, PAID_AMOUNT)
                VALUES ($client_id, $assign_id, CURDATE(), '$due_date',
                        'Pending Payment', 'Invoice', $total, 0.00)");
            $new_inv_id = mysqli_insert_id($conn);

            // Copy line items
            $lines = mysqli_query($conn,
                "SELECT * FROM invoice_line WHERE INVOICE_ID = $invoice_id");
            while ($line = mysqli_fetch_assoc($lines)) {
                $d  = mysqli_real_escape_string($conn, $line['DESCRIPTION']);
                $qy = intval($line['QUANTITY']);
                $up = floatval($line['UNIT_PRICE']);
                $lt = floatval($line['LINE_TOTAL']);
                mysqli_query($conn, "
                    INSERT INTO invoice_line
                           (INVOICE_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, LINE_TOTAL)
                    VALUES ($new_inv_id, '$d', $qy, $up, $lt)");
            }

            mysqli_query($conn, "
                INSERT INTO invoice_tracking
                       (invoice_id, action, description, performed_by_id, performed_by_role)
                VALUES ($new_inv_id, 'Invoice Generated',
                       'Generated from quotation #$invoice_id by accountant', $acc_id, 'Accountant')");

            // Mark quotation so it cannot be double-invoiced
            mysqli_query($conn,
                "UPDATE invoice SET STATUS = 'Invoiced' WHERE INVOICE_ID = $invoice_id");

            // Notify client
            $due_fmt = date('d M Y', strtotime($due_date));
            $tot_fmt = number_format($total, 2);
            $note    = mysqli_real_escape_string($conn,
                "Invoice #$new_inv_id has been generated for E$tot_fmt. " .
                "Due: $due_fmt. Please log in to make payment.");
            mysqli_query($conn, "
                INSERT INTO notifications (user_id, user_type, title, message)
                VALUES ($client_id, 'Client', 'Invoice Ready for Payment', '$note')");

            $_SESSION['toast'] = [
                'msg'  => "Invoice #$new_inv_id generated! Client notified.",
                'type' => 'success'
            ];
        } else {
            $_SESSION['toast'] = [
                'msg'  => 'Cannot generate invoice — quotation must be Approved first.',
                'type' => 'error'
            ];
        }
        header('Location: quotation_review.php?status=Invoiced');
        exit();
    }
}

// ── Page meta (read by acc_header.php before it renders <title>) ──────────────
$page_title    = 'Quotation Review';
$page_subtitle = 'Pending Quotations';

// ── Include header — HTML output starts here, NO redirects allowed below ──────
require_once __DIR__ . '/../../includes/acc_header.php';

// ── Read quotations AFTER header (DB already open from acc_header) ────────────
$status_filter = $_GET['status'] ?? 'Submitted';
$allowed       = ['Submitted', 'Approved', 'Rejected', 'Invoiced', 'all'];
if (!in_array($status_filter, $allowed, true)) $status_filter = 'Submitted';

if ($status_filter === 'all') {
    $where = "WHERE i.TYPE = 'Quotation'";
} else {
    $sf_esc = mysqli_real_escape_string($conn, $status_filter);
    $where  = "WHERE i.TYPE = 'Quotation' AND i.STATUS = '$sf_esc'";
}

// Newest first (INVOICE_DATE DESC, then INVOICE_ID DESC as tiebreaker)
$quotations = mysqli_query($conn, "
    SELECT i.INVOICE_ID, i.TOTAL, i.INVOICE_DATE, i.DUE_DATE, i.STATUS,
           c.COMPANY_NAME, c.COMPANY_EMAIL,
           rf.REP_FAULT_ID, rf.DESCRIPTION, rf.PRIORITY,
           a.ASSIGN_ID,
           e.FULL_NAME AS TECHNICIAN_NAME
    FROM   invoice i
    INNER  JOIN assignment a              ON i.ASSIGN_ID    = a.ASSIGN_ID
    INNER  JOIN reported_fault rf         ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    LEFT   JOIN client c                  ON i.CLIENT_ID    = c.CLIENT_ID
    LEFT   JOIN assignment_technician at2 ON a.ASSIGN_ID    = at2.ASSIGN_ID
    LEFT   JOIN employee e                ON at2.EMP_ID     = e.EMP_ID
    $where
    ORDER  BY i.INVOICE_DATE DESC, i.INVOICE_ID DESC");

// Tab counts
$counts = [];
foreach (['Submitted', 'Approved', 'Rejected', 'Invoiced'] as $s) {
    $se         = mysqli_real_escape_string($conn, $s);
    $r          = mysqli_query($conn,
        "SELECT COUNT(*) c FROM invoice WHERE TYPE='Quotation' AND STATUS='$se'");
    $counts[$s] = mysqli_fetch_assoc($r)['c'] ?? 0;
}
$counts['all'] = array_sum($counts);
?>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="page-head">
  <div>
    <h1><i class="ti ti-file-invoice" style="color:var(--accent)"></i> Quotation Review</h1>
    <p>Review technician quotations, approve, then generate the client invoice.</p>
  </div>
  <a href="generate_invoice.php" class="btn btn-primary btn-sm">
    <i class="ti ti-plus"></i> Manual Invoice
  </a>
</div>

<!-- ── Workflow banner ─────────────────────────────────────────────────────── -->
<div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);
            border-radius:10px;padding:.8rem 1.1rem;margin-bottom:1.25rem;
            font-size:.82rem;color:var(--text2);display:flex;gap:.75rem;align-items:center;">
  <i class="ti ti-info-circle" style="color:var(--info);font-size:1.15rem;flex-shrink:0"></i>
  <span>
    <strong style="color:var(--text)">Workflow:</strong>
    Technician submits &rarr;
    <strong style="color:var(--warning)">Approve</strong> &rarr;
    <strong style="color:var(--accent)">Generate Invoice</strong> &rarr;
    Client notified &amp; pays &rarr; You verify payment &rarr; Fault closed
  </span>
</div>

<!-- ── Status tabs ─────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;">
<?php
$tabs = [
    'Submitted' => 'Pending Review',
    'Approved'  => 'Approved',
    'Rejected'  => 'Rejected',
    'Invoiced'  => 'Invoiced',
    'all'       => 'All',
];
foreach ($tabs as $val => $label):
    $active = ($status_filter === $val);
?>
<a href="?status=<?= $val ?>"
   style="padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:600;
          text-decoration:none;border:1px solid var(--border);
          background:<?= $active ? 'var(--surface2)' : 'var(--surface)' ?>;
          color:<?= $active ? 'var(--text)' : 'var(--text2)' ?>;">
  <?= $label ?>
  <span style="background:var(--surface2);border-radius:99px;
               padding:.1rem .45rem;font-size:.72rem;margin-left:4px;">
    <?= $counts[$val] ?? 0 ?>
  </span>
</a>
<?php endforeach; ?>
</div>

<!-- ── Quotation list ──────────────────────────────────────────────────────── -->
<?php if (!$quotations || mysqli_num_rows($quotations) === 0): ?>
<div class="empty-state">
  <i class="ti ti-inbox"></i>
  <p>No <?= $status_filter === 'all' ? '' : strtolower($status_filter) ?> quotations found.</p>
</div>

<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1rem;">

<?php while ($q = mysqli_fetch_assoc($quotations)):

    $prio_color = match($q['PRIORITY'] ?? '') {
        'Urgent' => '#ff6b6b', 'High' => 'var(--danger)',
        'Medium' => 'var(--warning)', 'Low' => 'var(--success)',
        default  => 'var(--text2)',
    };

    $desc       = $q['DESCRIPTION'] ?? '';
    $faultTitle = '';
    $faultRef   = '';
    if (preg_match('/FAULT TITLE:\s*(.+)/i',     $desc, $m)) $faultTitle = trim($m[1]);
    if (preg_match('/FAULT REFERENCE:\s*(.+)/i', $desc, $m)) $faultRef   = trim($m[1]);
    if (!$faultTitle) $faultTitle = mb_strimwidth(strip_tags($desc), 0, 60, '…');
    if (!$faultRef)   $faultRef   = 'BQ-' . str_pad($q['REP_FAULT_ID'], 5, '0', STR_PAD_LEFT);

    $border = match($q['STATUS']) {
        'Approved' => 'var(--success)', 'Invoiced' => 'var(--info)',
        'Rejected' => 'var(--danger)',  default     => 'var(--warning)',
    };
    $badge_class = match($q['STATUS']) {
        'Approved' => 'badge-paid',    'Invoiced' => 'badge-partial',
        'Rejected' => 'badge-overdue', default    => 'badge-submitted',
    };

    $lines = mysqli_query($conn,
        "SELECT * FROM invoice_line WHERE INVOICE_ID = {$q['INVOICE_ID']} ORDER BY LINE_ID");
?>

<div class="card" style="border-left:3px solid <?= $border ?>">

  <!-- Card header -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start;
              flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
    <div>
      <div style="font-size:1rem;font-weight:700;color:var(--text);">
        Quote #<?= $q['INVOICE_ID'] ?>
        &mdash; <?= htmlspecialchars($q['COMPANY_NAME'] ?? 'Unknown Client') ?>
      </div>
      <div style="font-size:.78rem;color:var(--text2);margin-top:.2rem;">
        <?= htmlspecialchars($faultRef) ?> &bull;
        <?= htmlspecialchars($faultTitle) ?> &bull;
        Fault #<?= $q['REP_FAULT_ID'] ?>
      </div>
      <div style="font-size:.75rem;color:var(--text2);margin-top:.2rem;">
        Technician: <strong style="color:var(--text);">
          <?= htmlspecialchars($q['TECHNICIAN_NAME'] ?? '—') ?>
        </strong>
        &bull; Submitted: <?= date('d M Y', strtotime($q['INVOICE_DATE'])) ?>
        <?= $q['DUE_DATE'] ? '&bull; Due: ' . date('d M Y', strtotime($q['DUE_DATE'])) : '' ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;flex-shrink:0;">
      <span class="badge <?= $badge_class ?>"><?= $q['STATUS'] ?></span>
      <?php if (!empty($q['PRIORITY'])): ?>
      <span style="color:<?= $prio_color ?>;font-size:.78rem;font-weight:700;">
        <?= htmlspecialchars($q['PRIORITY']) ?>
      </span>
      <?php endif; ?>
      <div style="font-size:1.4rem;font-weight:700;color:var(--accent);">
        E<?= number_format($q['TOTAL'], 2) ?>
      </div>
    </div>
  </div>

  <!-- Line items table -->
  <div class="table-wrap" style="margin-bottom:1rem;">
    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th style="text-align:center;">Qty</th>
          <th style="text-align:right;">Unit Price</th>
          <th style="text-align:right;">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $hasLines = false;
        while ($line = mysqli_fetch_assoc($lines)):
            $hasLines = true;
        ?>
        <tr>
          <td><?= htmlspecialchars($line['DESCRIPTION']) ?></td>
          <td style="text-align:center;"><?= intval($line['QUANTITY']) ?></td>
          <td style="text-align:right;">E<?= number_format($line['UNIT_PRICE'], 2) ?></td>
          <td style="text-align:right;">E<?= number_format($line['LINE_TOTAL'], 2) ?></td>
        </tr>
        <?php endwhile; ?>
        <?php if (!$hasLines): ?>
        <tr>
          <td colspan="4" style="color:var(--text2);text-align:center;padding:1rem;">
            No line items found.
          </td>
        </tr>
        <?php endif; ?>
        <tr style="background:var(--surface2);">
          <td colspan="3" style="font-weight:700;">Total</td>
          <td style="font-weight:700;color:var(--accent);text-align:right;">
            E<?= number_format($q['TOTAL'], 2) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Action buttons per status -->
  <?php if ($q['STATUS'] === 'Submitted'): ?>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action"     value="approve">
      <input type="hidden" name="invoice_id" value="<?= $q['INVOICE_ID'] ?>">
      <button type="submit" class="btn btn-success btn-sm"
              onclick="return confirm('Approve quotation #<?= $q['INVOICE_ID'] ?>?')">
        <i class="ti ti-circle-check"></i> Approve
      </button>
    </form>
    <button class="btn btn-danger btn-sm"
            onclick="openRejectModal(<?= $q['INVOICE_ID'] ?>)">
      <i class="ti ti-circle-x"></i> Reject
    </button>
    <a href="quotation_review_details.php?invoice_id=<?= $q['INVOICE_ID'] ?>"
       class="btn btn-secondary btn-sm">
      <i class="ti ti-eye"></i> Full Details
    </a>
  </div>

  <?php elseif ($q['STATUS'] === 'Approved'): ?>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
    <form method="POST" style="display:inline;">
      <input type="hidden" name="action"     value="generate_invoice">
      <input type="hidden" name="invoice_id" value="<?= $q['INVOICE_ID'] ?>">
      <button type="submit" class="btn btn-primary btn-sm"
              onclick="return confirm('Generate invoice for Quote #<?= $q['INVOICE_ID'] ?> — E<?= number_format($q['TOTAL'],2) ?>?\nClient will be notified to pay.')">
        <i class="ti ti-file-text"></i> Generate Invoice &amp; Notify Client
      </button>
    </form>
    <a href="quotation_review_details.php?invoice_id=<?= $q['INVOICE_ID'] ?>"
       class="btn btn-secondary btn-sm">
      <i class="ti ti-eye"></i> View Details
    </a>
  </div>

  <?php elseif ($q['STATUS'] === 'Invoiced'): ?>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
    <span style="font-size:.82rem;color:var(--success);font-weight:600;">
      <i class="ti ti-circle-check"></i> Invoice generated — awaiting client payment
    </span>
    <a href="acc_invoices.php" class="btn btn-secondary btn-sm">
      <i class="ti ti-receipt"></i> View Invoices
    </a>
    <a href="quotation_review_details.php?invoice_id=<?= $q['INVOICE_ID'] ?>"
       class="btn btn-secondary btn-sm"><i class="ti ti-eye"></i> Details</a>
  </div>

  <?php else: /* Rejected */ ?>
  <div>
    <a href="quotation_review_details.php?invoice_id=<?= $q['INVOICE_ID'] ?>"
       class="btn btn-secondary btn-sm">
      <i class="ti ti-eye"></i> View Details
    </a>
  </div>
  <?php endif; ?>

</div><!-- /.card -->
<?php endwhile; ?>
</div><!-- /.list -->
<?php endif; ?>

<!-- ── Reject reason modal ────────────────────────────────────────────────── -->
<div id="rejectModal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);
            z-index:999;align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--surface);border:1px solid var(--border);
              border-radius:12px;width:100%;max-width:440px;
              box-shadow:0 8px 48px rgba(0,0,0,.6);">
    <div style="padding:1.2rem 1.5rem;border-bottom:1px solid var(--border);
                display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:700;color:var(--text);">Reject Quotation</span>
      <button onclick="closeRejectModal()"
              style="background:none;border:none;color:var(--text2);
                     cursor:pointer;font-size:1.2rem;">&#x2715;</button>
    </div>
    <form method="POST" id="rejectForm">
      <input type="hidden" name="action"     value="reject">
      <input type="hidden" name="invoice_id" id="rejectInvoiceId" value="">
      <div style="padding:1.25rem 1.5rem;">
        <div class="form-group">
          <label>Rejection Reason *</label>
          <textarea name="reason" id="rejectReason" class="form-control" rows="4"
                    placeholder="e.g. Labour hours seem excessive. Please revise and resubmit."
                    required></textarea>
        </div>
      </div>
      <div style="padding:1rem 1.5rem;border-top:1px solid var(--border);
                  display:flex;justify-content:flex-end;gap:.75rem;">
        <button type="button" class="btn btn-secondary btn-sm"
                onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-danger btn-sm">
          <i class="ti ti-circle-x"></i> Confirm Rejection
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openRejectModal(invoiceId) {
    document.getElementById('rejectInvoiceId').value = invoiceId;
    document.getElementById('rejectReason').value    = '';
    document.getElementById('rejectModal').style.display = 'flex';
    setTimeout(() => document.getElementById('rejectReason').focus(), 100);
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

// Toast consumed here; showToast() is defined in acc_header.php
<?php if (!empty($_SESSION['toast'])): ?>
showToast(<?= json_encode($_SESSION['toast']['msg']) ?>,
          <?= json_encode($_SESSION['toast']['type']) ?>);
<?php unset($_SESSION['toast']); endif; ?>
</script>

<?php require_once __DIR__ . '/../../includes/acc_footer.php'; ?>


