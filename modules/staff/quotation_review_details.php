<?php
session_start();
if (!isset($_SESSION['emp_id'])) {
    die('Session expired. Please login again.');
}

$page_title = 'Review Quotation';
$page_subtitle = 'Verify & Approve';
require_once __DIR__ . '/../../includes/acc_header.php';

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) {
    echo '<div class="alert alert-danger"><i class="ti ti-alert-circle"></i> <span>Invalid quotation ID</span></div>';
    require_once __DIR__ . '/../../includes/acc_footer.php';
    exit;
}

// Fetch quotation details
$quote = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT i.*, a.ASSIGN_ID, rf.REP_FAULT_ID, rf.DESCRIPTION, rf.PRIORITY,
           c.COMPANY_NAME, c.CLIENT_ID, c.COMPANY_EMAIL, c.CONTACT_PERSON_NAME,
           e.FULL_NAME as technician_name, e.EMAIL as tech_email, e.EMP_ID
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN employee e ON at2.EMP_ID = e.EMP_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.INVOICE_ID = $invoice_id AND i.TYPE = 'Quotation'"));

if (!$quote) {
    echo '<div class="alert alert-danger"><i class="ti ti-alert-circle"></i> <span>Quotation not found</span></div>';
    require_once __DIR__ . '/../../includes/acc_footer.php';
    exit;
}

// Fetch line items
$lines = mysqli_query($conn, "
    SELECT * FROM invoice_line WHERE INVOICE_ID = $invoice_id ORDER BY LINE_ID");

// Handle actions - NO HEADER REDIRECT
if ($_POST['action'] ?? null) {
    $action = $_POST['action'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if ($action === 'approve') {
        mysqli_query($conn, "UPDATE invoice SET STATUS = 'Approved' WHERE INVOICE_ID = $invoice_id");
        mysqli_query($conn, "
            INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Approved', 'Approved by accountant', $acc_id, 'Accountant')");
        
        $msg = "Quotation #$invoice_id approved. Amount: E{$quote['TOTAL']}";
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, user_type, title, message) 
            VALUES ({$quote['CLIENT_ID']}, 'Client', 'Quotation Approved', '$msg')");
        
        echo '<div class="alert alert-success"><i class="ti ti-circle-check"></i> <span>Quotation approved successfully!</span></div>';
        
    } elseif ($action === 'reject') {
        mysqli_query($conn, "UPDATE invoice SET STATUS = 'Rejected' WHERE INVOICE_ID = $invoice_id");
        mysqli_query($conn, "
            INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Rejected', 'Reason: $notes', $acc_id, 'Accountant')");
        
        $msg = "Quotation #$invoice_id rejected. Reason: $notes";
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, user_type, title, message) 
            VALUES ({$quote['EMP_ID']}, 'Employee', 'Quotation Rejected', '$msg')");
        
        echo '<div class="alert alert-warning"><i class="ti ti-alert-triangle"></i> <span>Quotation rejected. Technician notified.</span></div>';
    }
    
    // Refresh page data
    $quote = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT i.*, a.ASSIGN_ID, rf.REP_FAULT_ID, rf.DESCRIPTION, rf.PRIORITY,
               c.COMPANY_NAME, c.CLIENT_ID, c.COMPANY_EMAIL, c.CONTACT_PERSON_NAME,
               e.FULL_NAME as technician_name, e.EMAIL as tech_email, e.EMP_ID
        FROM invoice i
        INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
        INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        INNER JOIN employee e ON at2.EMP_ID = e.EMP_ID
        LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
        WHERE i.INVOICE_ID = $invoice_id AND i.TYPE = 'Quotation'"));
}
?>

<div class="page-head">
  <div>
    <h1><?= $page_title ?></h1>
    <p><?= $page_subtitle ?></p>
  </div>
  <a href="quotation_review.php" class="btn btn-secondary"><i class="ti ti-arrow-left"></i> Back</a>
</div>

<div class="grid-2">
  <!-- LEFT: Details -->
  <div>
    <!-- Client Info -->
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-title"><i class="ti ti-user"></i> Client Information</div>
      <div style="display:flex;flex-direction:column;gap:1rem">
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Company</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem"><?= htmlspecialchars($quote['COMPANY_NAME'] ?? 'Unknown') ?></div>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Contact Person</div>
          <div style="font-size:.95rem;margin-top:.3rem"><?= htmlspecialchars($quote['CONTACT_PERSON_NAME'] ?? 'N/A') ?></div>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Email</div>
          <div style="font-size:.95rem;margin-top:.3rem">
            <a href="mailto:<?= htmlspecialchars($quote['COMPANY_EMAIL']) ?>" style="color:var(--info);text-decoration:none">
              <?= htmlspecialchars($quote['COMPANY_EMAIL']) ?>
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Fault Info -->
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-title"><i class="ti ti-alert-circle"></i> Fault Details</div>
      <div style="display:flex;flex-direction:column;gap:1rem">
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Fault ID</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem">Fault #<?= $quote['REP_FAULT_ID'] ?></div>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Priority</div>
          <div style="margin-top:.3rem">
            <span class="badge badge-<?= strtolower($quote['PRIORITY'] ?? 'info') ?>">
              <?= htmlspecialchars($quote['PRIORITY'] ?? 'Normal') ?>
            </span>
          </div>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Description</div>
          <div style="font-size:.9rem;margin-top:.3rem;line-height:1.5;color:var(--text2)">
            <?= nl2br(htmlspecialchars(mb_strimwidth($quote['DESCRIPTION'], 0, 200, '...'))) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Technician Info -->
    <div class="card">
      <div class="card-title"><i class="ti ti-user-check"></i> Assigned Technician</div>
      <div style="display:flex;flex-direction:column;gap:1rem">
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Name</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem"><?= htmlspecialchars($quote['technician_name']) ?></div>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);text-transform:uppercase;font-weight:600">Email</div>
          <div style="font-size:.95rem;margin-top:.3rem">
            <a href="mailto:<?= htmlspecialchars($quote['tech_email']) ?>" style="color:var(--info);text-decoration:none">
              <?= htmlspecialchars($quote['tech_email']) ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Quotation Review -->
  <div>
    <!-- Quotation Card -->
    <div class="card" style="margin-bottom:1.25rem;border:2px solid var(--accent)">
      <div class="card-title"><i class="ti ti-file-invoice"></i> Quotation #<?= $quote['INVOICE_ID'] ?></div>
      
      <div style="margin-top:1.25rem">
        <?php while ($line = mysqli_fetch_assoc($lines)): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:.9rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($line['DESCRIPTION']) ?></div>
            <div style="font-size:.8rem;color:var(--text2);margin-top:.3rem"><?= $line['QUANTITY'] ?> x E<?= number_format($line['UNIT_PRICE'], 2) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.9rem;font-weight:600;color:var(--info)">E<?= number_format($line['LINE_TOTAL'], 2) ?></div>
          </div>
        </div>
        <?php endwhile; ?>

        <!-- Total -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem;background:rgba(240,165,0,.1);border:1px solid var(--accent);border-radius:8px;margin-top:1rem">
          <span style="font-size:.95rem;font-weight:600">Total Amount:</span>
          <span style="font-size:1.5rem;font-weight:700;color:var(--accent)">E<?= number_format($quote['TOTAL'], 2) ?></span>
        </div>
      </div>

      <!-- Verification Checklist -->
      <div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:1rem">
        <div style="font-size:.8rem;text-transform:uppercase;color:var(--text2);font-weight:600;margin-bottom:1rem">5-Point Verification</div>
        <div style="display:flex;flex-direction:column;gap:.75rem">
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check1" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Labour costs are reasonable</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check2" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Transport & materials justified</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check3" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Line items match work completed</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check4" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Total calculated correctly</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check5" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">No duplicate charges</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Action Form -->
    <form method="POST" class="card" style="background:var(--surface2)">
      <div style="margin-bottom:1.25rem">
        <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.5rem;text-transform:uppercase;color:var(--text2)">Notes</label>
        <textarea name="notes" style="width:100%;padding:.65rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:.9rem;font-family:monospace;resize:vertical;min-height:80px" placeholder="Add notes or rejection reasons..."></textarea>
      </div>

      <div style="display:flex;gap:1rem">
        <button type="submit" name="action" value="approve" class="btn btn-success" style="flex:1" onclick="return validateChecks()">
          <i class="ti ti-circle-check"></i> Approve
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger" style="flex:1" onclick="return confirm('Reject this quotation?')">
          <i class="ti ti-circle-x"></i> Reject
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function validateChecks() {
  const checks = [
    document.getElementById('check1').checked,
    document.getElementById('check2').checked,
    document.getElementById('check3').checked,
    document.getElementById('check4').checked,
    document.getElementById('check5').checked
  ];
  
  if (!checks.every(c => c)) {
    alert('Please verify all checklist items');
    return false;
  }
  
  return confirm('Approve this quotation?');
}
</script>

<?php require_once __DIR__ . '/../../includes/acc_footer.php'; ?>

