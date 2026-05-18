<?php
$page_title    = 'Review Quotation';
$page_subtitle = 'Verify & Approve';
require_once __DIR__ . '/../../includes/acc_header.php';

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if (!$invoice_id) {
    header('Location: quotation_review.php');
    exit;
}

// Fetch quotation details
$quote = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT i.*, a.ASSIGN_ID, rf.REP_FAULT_ID, rf.DESCRIPTION, rf.PRIORITY,
           c.COMPANY_NAME, c.CLIENT_ID, c.COMPANY_EMAIL, c.CONTACT_PERSON_NAME,
           e.FULL_NAME as technician_name, e.EMAIL as tech_email
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN employee e ON at2.EMP_ID = e.EMP_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.INVOICE_ID = $invoice_id AND i.TYPE = 'Quotation'"));

if (!$quote) {
    header('Location: quotation_review.php?error=Quote not found');
    exit;
}

// Fetch line items
$lines = mysqli_query($conn, "
    SELECT * FROM invoice_line WHERE INVOICE_ID = $invoice_id ORDER BY LINE_ID");

// Handle actions
if ($_POST['action'] ?? null) {
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    if ($action === 'approve') {
        // Update quotation status
        mysqli_query($conn, "UPDATE invoice SET STATUS = 'Approved' WHERE INVOICE_ID = $invoice_id");
        
        // Log action
        mysqli_query($conn, "
            INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Approved', 'Quotation approved by accountant $acc_name', $acc_id, 'Accountant')");
        
        // Notify client
        $msg = "Quotation #$invoice_id for fault #{$quote['REP_FAULT_ID']} approved. Amount: \${$quote['TOTAL']}. Invoice will be generated soon.";
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, user_type, title, message) 
            VALUES ({$quote['CLIENT_ID']}, 'Client', 'Quotation Approved', '$msg')");
        
        // Notify technician
        $msg2 = "Your quotation #$invoice_id for fault #{$quote['REP_FAULT_ID']} has been approved by accountant.";
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, user_type, title, message) 
            VALUES ({$quote['EMP_ID']}, 'Employee', 'Quotation Approved', '$msg2')");
        
        header('Location: quotation_review.php?success=Quotation approved');
        exit;
        
    } elseif ($action === 'reject') {
        // Update quotation status
        mysqli_query($conn, "UPDATE invoice SET STATUS = 'Rejected' WHERE INVOICE_ID = $invoice_id");
        
        // Log action
        $notes_safe = mysqli_real_escape_string($conn, $notes);
        mysqli_query($conn, "
            INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
            VALUES ($invoice_id, 'Quotation Rejected', 'Rejection reason: $notes_safe', $acc_id, 'Accountant')");
        
        // Notify technician for rework
        $msg = "Your quotation #$invoice_id for fault #{$quote['REP_FAULT_ID']} has been rejected. Reason: $notes_safe";
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, user_type, title, message) 
            VALUES ({$quote['EMP_ID']}, 'Employee', 'Quotation Rejected - Rework Required', '$msg')");
        
        header('Location: quotation_review.php?success=Quotation rejected');
        exit;
    }
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <h2 style="margin:0"><?= $page_title ?></h2>
  <a href="quotation_review.php" class="btn btn-secondary btn-sm"><i class="ti ti-arrow-left"></i> Back</a>
</div>

<!-- Main Content Grid -->
<div class="grid-2" style="gap:1.5rem">

  <!-- Left: Details -->
  <div>
    <!-- Client Info Card -->
    <div class="card" style="margin-bottom:1.5rem">
      <div class="card-title"><i class="ti ti-user"></i> Client Information</div>
      <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Company Name</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem"><?= htmlspecialchars($quote['COMPANY_NAME'] ?? 'Unknown') ?></div>
        </div>
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Contact Person</div>
          <div style="font-size:.95rem;margin-top:.3rem"><?= htmlspecialchars($quote['CONTACT_PERSON_NAME'] ?? 'N/A') ?></div>
        </div>
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Email</div>
          <div style="font-size:.95rem;margin-top:.3rem"><a href="mailto:<?= htmlspecialchars($quote['COMPANY_EMAIL']) ?>" style="color:var(--primary);text-decoration:none"><?= htmlspecialchars($quote['COMPANY_EMAIL']) ?></a></div>
        </div>
      </div>
    </div>

    <!-- Fault Info Card -->
    <div class="card" style="margin-bottom:1.5rem">
      <div class="card-title"><i class="ti ti-alert-circle"></i> Fault Details</div>
      <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Fault ID</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem">Fault #<?= $quote['REP_FAULT_ID'] ?></div>
        </div>
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Priority</div>
          <div style="font-size:.95rem;margin-top:.3rem">
            <span class="badge badge-<?= strtolower($quote['PRIORITY']) ?>"><?= $quote['PRIORITY'] ?></span>
          </div>
        </div>
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Description</div>
          <div style="font-size:.95rem;margin-top:.3rem;line-height:1.5"><?= nl2br(htmlspecialchars($quote['DESCRIPTION'])) ?></div>
        </div>
      </div>
    </div>

    <!-- Technician Info -->
    <div class="card">
      <div class="card-title"><i class="ti ti-user-check"></i> Assigned Technician</div>
      <div style="display:flex;flex-direction:column;gap:1rem;margin-top:1rem">
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Name</div>
          <div style="font-size:.95rem;font-weight:600;margin-top:.3rem"><?= htmlspecialchars($quote['technician_name']) ?></div>
        </div>
        <div>
          <div style="font-size:.8rem;color:var(--text2);text-transform:uppercase;font-weight:600">Email</div>
          <div style="font-size:.95rem;margin-top:.3rem"><a href="mailto:<?= htmlspecialchars($quote['tech_email']) ?>" style="color:var(--primary);text-decoration:none"><?= htmlspecialchars($quote['tech_email']) ?></a></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Quotation Review -->
  <div>
    <!-- Quotation Summary Card -->
    <div class="card" style="margin-bottom:1.5rem;border:2px solid var(--primary)">
      <div class="card-title"><i class="ti ti-file-invoice"></i> Quotation #<?= $quote['INVOICE_ID'] ?></div>
      
      <!-- Line Items -->
      <div style="margin-top:1.5rem;display:flex;flex-direction:column;gap:1rem">
        <div style="border-bottom:1px solid var(--border);padding-bottom:1rem">
          <?php while ($line = mysqli_fetch_assoc($lines)): ?>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem">
            <div>
              <div style="font-size:.9rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($line['DESCRIPTION']) ?></div>
              <div style="font-size:.8rem;color:var(--text2);margin-top:.3rem"><?= $line['QUANTITY'] ?> x $<?= number_format($line['UNIT_PRICE'], 2) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:.9rem;font-weight:600;color:var(--primary)">$<?= number_format($line['LINE_TOTAL'], 2) ?></div>
            </div>
          </div>
          <?php endwhile; ?>
        </div>

        <!-- Total -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem;background:var(--surface2);border-radius:8px">
          <span style="font-size:.95rem;font-weight:600">Total Amount:</span>
          <span style="font-size:1.5rem;font-weight:700;color:var(--primary)">$<?= number_format($quote['TOTAL'], 2) ?></span>
        </div>
      </div>

      <!-- 5-Point Verification Checklist -->
      <div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:1rem">
        <div style="font-size:.85rem;font-weight:600;text-transform:uppercase;color:var(--text2);margin-bottom:1rem">Verification Checklist</div>
        <div style="display:flex;flex-direction:column;gap:.75rem">
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check1" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Labour costs are reasonable and properly documented</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check2" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Transport and material costs are justified</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check3" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">All line items match the work completed</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check4" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">Total is calculated correctly</span>
          </label>
          <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" id="check5" style="width:1.1rem;height:1.1rem;cursor:pointer">
            <span style="font-size:.9rem">No duplicate items or charges</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Action Form -->
    <form method="POST" class="card" style="background:var(--surface2)">
      <div style="margin-bottom:1.5rem">
        <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.5rem">Notes (Optional)</label>
        <textarea name="notes" style="width:100%;padding:.75rem;border:1px solid var(--border);border-radius:6px;font-size:.85rem;font-family:monospace;resize:vertical;height:100px;background:var(--surface)" placeholder="Add any notes or rejection reasons..."></textarea>
      </div>

      <div style="display:flex;gap:1rem">
        <button type="submit" name="action" value="approve" class="btn btn-success" style="flex:1">
          <i class="ti ti-circle-check"></i> Approve & Create Invoice
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger" style="flex:1" onclick="return confirm('Are you sure? Technician will be notified to rework.')">
          <i class="ti ti-circle-x"></i> Reject
        </button>
      </div>
    </form>
  </div>

</div>

<?php require_once '../../includes/acc_footer.php'; ?>

