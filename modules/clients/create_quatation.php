<?php
$page_title = 'Create Quotation';
require_once 'includes/tech_header.php';

$fault_id  = intval($_GET['fault_id']  ?? $_POST['fault_id']  ?? 0);
$assign_id = intval($_GET['assign_id'] ?? $_POST['assign_id'] ?? 0);

// If no assign_id via GET, try to find from fault_id
if ($fault_id && !$assign_id) {
    $ar = mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.ASSIGN_ID FROM assignment a INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID WHERE a.REP_FAULT_ID=$fault_id AND at2.EMP_ID=$tech_id LIMIT 1"));
    $assign_id = intval($ar['ASSIGN_ID'] ?? 0);
}

// Verify ownership
$fault = null;
if ($fault_id && $assign_id) {
    $fault = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY,
               c.COMPANY_NAME, c.CLIENT_ID, a.ASSIGN_ID
        FROM reported_fault rf
        INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
        WHERE rf.REP_FAULT_ID = $fault_id AND a.ASSIGN_ID = $assign_id AND at2.EMP_ID = $tech_id
        LIMIT 1"));
}

// Get all faults for dropdown (in case navigating without pre-selection)
$all_faults = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, c.COMPANY_NAME, a.ASSIGN_ID
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS IN ('In Progress','Assigned','Completed')
    ORDER BY rf.REPORT_DATE DESC");

// Handle POST — save quotation as invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quotation'])) {
    $f_id   = intval($_POST['fault_id']);
    $a_id   = intval($_POST['assign_id']);
    $client_id = intval($_POST['client_id']);
    $action = $_POST['submit_quotation']; // 'draft' or 'submit'
    $status = ($action === 'submit') ? 'Submitted' : 'Draft';
    $due_days = 14;
    $due_date = date('Y-m-d', strtotime("+$due_days days"));
    $today = date('Y-m-d');

    // Lines
    $descs  = $_POST['line_desc']  ?? [];
    $qtys   = $_POST['line_qty']   ?? [];
    $prices = $_POST['line_price'] ?? [];

    $total = 0;
    $lines = [];
    foreach ($descs as $i => $d) {
        $d = trim($d);
        $q = floatval($qtys[$i] ?? 0);
        $p = floatval($prices[$i] ?? 0);
        if ($d && $q > 0 && $p >= 0) {
            $lt = round($q * $p, 2);
            $total += $lt;
            $lines[] = [$d, $q, $p, $lt];
        }
    }

    if (empty($lines)) {
        $_SESSION['toast'] = ['msg'=>'Add at least one line item.','type'=>'error'];
    } else {
        $total = round($total, 2);
        // Create invoice record (quotation stored as invoice with STATUS=Draft/Submitted)
        mysqli_query($conn, "INSERT INTO invoice (CLIENT_ID, ASSIGN_ID, INVOICE_DATE, DUE_DATE, STATUS, TYPE, TOTAL) VALUES ($client_id, $a_id, '$today', '$due_date', '$status', 'Quotation', $total)");
        $inv_id = mysqli_insert_id($conn);

        foreach ($lines as [$d,$q,$p,$lt]) {
            $de = mysqli_real_escape_string($conn,$d);
            mysqli_query($conn, "INSERT INTO invoice_line (INVOICE_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, LINE_TOTAL) VALUES ($inv_id, '$de', $q, $p, $lt)");
        }
        // Track
        mysqli_query($conn, "INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role) VALUES ($inv_id, 'Quotation $status', 'Quotation created by technician $tech_name for fault #$f_id', $tech_id, 'Technician')");
        // Notify accountant
        if ($action === 'submit') {
            $acc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT EMP_ID FROM employee WHERE ROLE='Accountant' LIMIT 1"));
            if ($acc) {
                mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ({$acc['EMP_ID']}, 'Employee', 'New Quotation Submitted', 'Technician $tech_name submitted quotation #$inv_id (E$total) for fault #$f_id. Please review and create invoice.')");
            }
            $_SESSION['toast'] = ['msg'=>"Quotation #$inv_id submitted to Accountant! Total: E".number_format($total,2),'type'=>'success'];
        } else {
            $_SESSION['toast'] = ['msg'=>"Quotation saved as draft #$inv_id.",'type'=>'info'];
        }
        header("Location: quotation_history.php"); exit();
    }
}

$hourly_rate = $_SESSION['hourly_rate'] ?? 85;
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-file-invoice" style="color:var(--accent)"></i> Create Quotation</h1>
    <p>Build quotation linked to a fault assignment</p>
  </div>
  <a href="quotation_history.php" class="btn btn-secondary btn-sm"><i class="ti ti-history"></i> Quotation History</a>
</div>

<form method="POST" id="quot-form">
<div style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start" class="quot-grid">

  <!-- LEFT -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Fault Selection -->
    <div class="card">
      <div class="card-title"><i class="ti ti-link" style="color:var(--accent)"></i> Link to Fault</div>
      <?php if ($fault): ?>
      <input type="hidden" name="fault_id"  value="<?= $fault['REP_FAULT_ID'] ?>">
      <input type="hidden" name="assign_id" value="<?= $fault['ASSIGN_ID'] ?>">
      <input type="hidden" name="client_id" value="<?= $fault['CLIENT_ID'] ?>">
      <div style="background:var(--surface2);border-radius:8px;padding:1rem;border:1px solid rgba(240,165,0,.3)">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div>
            <div style="font-weight:600;color:var(--accent)">Fault #<?= $fault['REP_FAULT_ID'] ?></div>
            <div style="font-size:.85rem;color:var(--text2);margin-top:.2rem"><?= htmlspecialchars($fault['COMPANY_NAME'] ?? '') ?></div>
            <div style="font-size:.78rem;color:var(--text2);margin-top:.2rem"><?= htmlspecialchars(mb_strimwidth(strip_tags($fault['DESCRIPTION']),0,80,'...')) ?></div>
          </div>
          <span class="badge badge-progress"><?= $fault['STATUS'] ?></span>
        </div>
      </div>
      <?php else: ?>
      <div class="form-group">
        <label>Select Fault *</label>
        <select name="fault_id" class="form-control" required onchange="this.form.submit()">
          <option value="">-- Choose a fault --</option>
          <?php while ($af = mysqli_fetch_assoc($all_faults)): ?>
          <option value="<?= $af['REP_FAULT_ID'] ?>" data-assign="<?= $af['ASSIGN_ID'] ?>" <?= $fault_id==$af['REP_FAULT_ID']?'selected':'' ?>>
            #<?= $af['REP_FAULT_ID'] ?> &mdash; <?= htmlspecialchars($af['COMPANY_NAME'] ?? '') ?> (<?= $af['STATUS'] ?>)
          </option>
          <?php endwhile; ?>
        </select>
        <div class="form-error">A quotation must be linked to a fault.</div>
      </div>
      <input type="hidden" name="assign_id" id="assign-hidden" value="<?= $assign_id ?>">
      <input type="hidden" name="client_id" id="client-hidden" value="">
      <?php endif; ?>
    </div>

    <!-- Labor -->
    <div class="card">
      <div class="card-title"><i class="ti ti-clock" style="color:var(--accent)"></i> Labour Cost</div>
      <div class="form-row">
        <div class="form-group">
          <label>Hours Worked *</label>
          <input type="number" step="0.25" min="0" class="form-control" id="labor-hours" name="line_qty[]" placeholder="e.g. 3" value="" oninput="calcLabor()">
        </div>
        <div class="form-group">
          <label>Hourly Rate (E) *</label>
          <input type="number" step="0.01" min="0" class="form-control" id="labor-rate" name="line_price[]" value="<?= $hourly_rate ?>" oninput="calcLabor()">
        </div>
      </div>
      <input type="hidden" name="line_desc[]" value="Labour – Technician time">
      <div style="background:var(--surface2);border-radius:8px;padding:.75rem;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:.85rem;color:var(--text2)">Labour Subtotal</span>
        <span style="font-size:1.1rem;font-weight:700;color:var(--accent)" id="labor-total">E 0.00</span>
      </div>
    </div>

    <!-- Transport -->
    <div class="card">
      <div class="card-title"><i class="ti ti-car" style="color:var(--accent)"></i> Transport Cost</div>
      <input type="hidden" name="line_desc[]" value="Transport – Travel & fuel">
      <div class="form-row">
        <div class="form-group">
          <label>Distance (km round trip)</label>
          <input type="number" step="1" min="0" class="form-control" id="dist" placeholder="e.g. 40" oninput="calcTransport()">
        </div>
        <div class="form-group">
          <label>Rate per km (E)</label>
          <input type="number" step="0.01" min="0" class="form-control" id="km-rate" value="3.50" oninput="calcTransport()">
        </div>
      </div>
      <div class="form-group">
        <label>Additional fees (tolls, parking, etc.) (E)</label>
        <input type="number" step="0.01" min="0" class="form-control" id="extra-fees" placeholder="0.00" oninput="calcTransport()">
      </div>
      <input type="hidden" id="transport-qty"   name="line_qty[]"   value="1">
      <input type="hidden" id="transport-price" name="line_price[]" value="0">
      <div style="background:var(--surface2);border-radius:8px;padding:.75rem;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:.85rem;color:var(--text2)">Transport Subtotal</span>
        <span style="font-size:1.1rem;font-weight:700;color:var(--accent)" id="transport-total">E 0.00</span>
      </div>
    </div>

    <!-- Materials -->
    <div class="card">
      <div class="card-title"><i class="ti ti-package" style="color:var(--accent)"></i> Materials / Parts Used</div>
      <div id="materials-list">
        <div class="material-row" style="display:grid;grid-template-columns:1fr 80px 100px 36px;gap:.5rem;align-items:end;margin-bottom:.5rem">
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">Item Name</label>
            <input type="text" name="line_desc[]" class="form-control mat-desc" placeholder="e.g. Network cable 5m">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">Qty</label>
            <input type="number" name="line_qty[]" class="form-control mat-qty" min="0" step="1" placeholder="1" value="" oninput="calcMaterials()">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">Unit Price (E)</label>
            <input type="number" name="line_price[]" class="form-control mat-price" step="0.01" min="0" placeholder="0.00" oninput="calcMaterials()">
          </div>
          <button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm" style="margin-bottom:0;height:38px">✕</button>
        </div>
      </div>
      <button type="button" onclick="addMaterial()" class="btn btn-secondary btn-sm"><i class="ti ti-plus"></i> Add Item</button>
      <div style="background:var(--surface2);border-radius:8px;padding:.75rem;display:flex;justify-content:space-between;align-items:center;margin-top:.75rem">
        <span style="font-size:.85rem;color:var(--text2)">Materials Subtotal</span>
        <span style="font-size:1.1rem;font-weight:700;color:var(--accent)" id="mat-total">E 0.00</span>
      </div>
    </div>

  </div>

  <!-- RIGHT – Summary -->
  <div style="position:sticky;top:calc(var(--topbar-h) + 1rem);display:flex;flex-direction:column;gap:1rem">
    <div class="card" style="border-color:rgba(240,165,0,.3)">
      <div class="card-title"><i class="ti ti-calculator" style="color:var(--accent)"></i> Quotation Summary</div>
      <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;font-size:.875rem">
          <span style="color:var(--text2)">Labour</span>
          <span id="s-labor">E 0.00</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.875rem">
          <span style="color:var(--text2)">Transport</span>
          <span id="s-transport">E 0.00</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:.875rem">
          <span style="color:var(--text2)">Materials</span>
          <span id="s-materials">E 0.00</span>
        </div>
        <hr class="divider" style="margin:.25rem 0">
        <div style="display:flex;justify-content:space-between;font-size:1.15rem;font-weight:700">
          <span>TOTAL</span>
          <span style="color:var(--accent)" id="s-total">E 0.00</span>
        </div>
      </div>

      <?php if ($fault): ?>
      <div style="display:flex;flex-direction:column;gap:.5rem">
        <button type="submit" name="submit_quotation" value="submit" class="btn btn-primary" style="width:100%;justify-content:center" onclick="return validateForm()">
          <i class="ti ti-send"></i> Submit to Accountant
        </button>
        <button type="submit" name="submit_quotation" value="draft" class="btn btn-secondary" style="width:100%;justify-content:center">
          <i class="ti ti-device-floppy"></i> Save as Draft
        </button>
      </div>
      <p style="font-size:.72rem;color:var(--text2);margin-top:.75rem;line-height:1.5">
        Submitting will notify the Accountant to create an invoice. Drafts can be edited later.
      </p>
      <?php else: ?>
      <p style="font-size:.82rem;color:var(--text2)">Select a fault above to enable quotation submission.</p>
      <?php endif; ?>
    </div>

    <!-- Validation hints -->
    <div class="card" id="validation-card" style="display:none;border-color:rgba(248,81,73,.3)">
      <div style="font-size:.85rem;font-weight:600;color:var(--danger);margin-bottom:.5rem"><i class="ti ti-alert-circle"></i> Please fix:</div>
      <ul id="validation-list" style="font-size:.8rem;color:var(--danger);padding-left:1rem;line-height:1.8"></ul>
    </div>
  </div>
</div>
</form>

<style>@media(max-width:900px){.quot-grid{grid-template-columns:1fr!important}}</style>
<script>
function fmt(n){ return 'E '+parseFloat(n||0).toFixed(2); }
function calcLabor(){
  const h=parseFloat(document.getElementById('labor-hours').value||0);
  const r=parseFloat(document.getElementById('labor-rate').value||0);
  const t=h*r;
  document.getElementById('labor-total').textContent=fmt(t);
  document.getElementById('s-labor').textContent=fmt(t);
  calcGrandTotal();
}
function calcTransport(){
  const d=parseFloat(document.getElementById('dist').value||0);
  const r=parseFloat(document.getElementById('km-rate').value||0);
  const f=parseFloat(document.getElementById('extra-fees').value||0);
  const t=d*r+f;
  document.getElementById('transport-price').value=t.toFixed(2);
  document.getElementById('transport-total').textContent=fmt(t);
  document.getElementById('s-transport').textContent=fmt(t);
  calcGrandTotal();
}
function calcMaterials(){
  let t=0;
  document.querySelectorAll('.material-row').forEach(row=>{
    const q=parseFloat(row.querySelector('.mat-qty')?.value||0);
    const p=parseFloat(row.querySelector('.mat-price')?.value||0);
    t+=q*p;
  });
  document.getElementById('mat-total').textContent=fmt(t);
  document.getElementById('s-materials').textContent=fmt(t);
  calcGrandTotal();
}
function calcGrandTotal(){
  const l=parseFloat(document.getElementById('labor-hours')?.value||0)*parseFloat(document.getElementById('labor-rate')?.value||0);
  const tr=parseFloat(document.getElementById('transport-price')?.value||0);
  let m=0;
  document.querySelectorAll('.material-row').forEach(row=>{
    m+=parseFloat(row.querySelector('.mat-qty')?.value||0)*parseFloat(row.querySelector('.mat-price')?.value||0);
  });
  document.getElementById('s-total').textContent=fmt(l+tr+m);
}
function addMaterial(){
  const tpl=`<div class="material-row" style="display:grid;grid-template-columns:1fr 80px 100px 36px;gap:.5rem;align-items:end;margin-bottom:.5rem">
    <input type="text" name="line_desc[]" class="form-control mat-desc" placeholder="Item name">
    <input type="number" name="line_qty[]" class="form-control mat-qty" min="0" step="1" placeholder="1" oninput="calcMaterials()">
    <input type="number" name="line_price[]" class="form-control mat-price" step="0.01" min="0" placeholder="0.00" oninput="calcMaterials()">
    <button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm" style="height:38px">✕</button>
  </div>`;
  document.getElementById('materials-list').insertAdjacentHTML('beforeend',tpl);
}
function removeRow(btn){ btn.closest('.material-row').remove(); calcMaterials(); }
function validateForm(){
  const errors=[];
  const h=parseFloat(document.getElementById('labor-hours')?.value||0);
  const r=parseFloat(document.getElementById('labor-rate')?.value||0);
  const total=parseFloat(document.getElementById('s-total').textContent.replace('E ',''));
  if(h<=0) errors.push('Enter hours worked for labour.');
  if(r<=0) errors.push('Enter a valid hourly rate.');
  if(total<=0) errors.push('Quotation total must be greater than E 0.00.');
  const vc=document.getElementById('validation-card');
  const vl=document.getElementById('validation-list');
  if(errors.length){
    vl.innerHTML=errors.map(e=>`<li>${e}</li>`).join('');
    vc.style.display='block';
    window.scrollTo({top:0,behavior:'smooth'});
    return false;
  }
  vc.style.display='none';
  return true;
}
</script>

<?php require_once 'includes/tech_footer.php'; ?>


