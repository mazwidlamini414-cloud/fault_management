<?php
$page_title = 'Quotation History';
require_once 'includes/tech_header.php';

$status_filter = $_GET['status'] ?? '';

$where = "WHERE at2.EMP_ID = $tech_id AND i.TYPE = 'Quotation'";
if ($status_filter) $where .= " AND i.STATUS = '".mysqli_real_escape_string($conn,$status_filter)."'";

$quotations = mysqli_query($conn, "
    SELECT i.*, c.COMPANY_NAME, rf.REP_FAULT_ID, rf.DESCRIPTION as fault_desc, rf.STATUS as fault_status,
           (SELECT SUM(QUANTITY*UNIT_PRICE) FROM invoice_line WHERE INVOICE_ID=i.INVOICE_ID) as calc_total
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    $where
    ORDER BY i.INVOICE_DATE DESC");

// Totals by status
$totals_r = mysqli_query($conn, "
    SELECT i.STATUS, COUNT(*) as cnt, SUM(i.TOTAL) as tot
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID=a.ASSIGN_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
    WHERE at2.EMP_ID=$tech_id AND i.TYPE='Quotation'
    GROUP BY i.STATUS");
$stat_totals = [];
while ($sr = mysqli_fetch_assoc($totals_r)) $stat_totals[$sr['STATUS']] = $sr;

// View single quotation lines modal data
$view_id = intval($_GET['view'] ?? 0);
$view_quot = null;
$view_lines = null;
if ($view_id) {
    $view_quot = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT i.*, c.COMPANY_NAME, rf.REP_FAULT_ID, rf.DESCRIPTION as fault_desc
        FROM invoice i
        INNER JOIN assignment a ON i.ASSIGN_ID=a.ASSIGN_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
        INNER JOIN reported_fault rf ON a.REP_FAULT_ID=rf.REP_FAULT_ID
        LEFT JOIN client c ON i.CLIENT_ID=c.CLIENT_ID
        WHERE i.INVOICE_ID=$view_id AND at2.EMP_ID=$tech_id AND i.TYPE='Quotation' LIMIT 1"));
    if ($view_quot) {
        $view_lines = mysqli_query($conn, "SELECT * FROM invoice_line WHERE INVOICE_ID=$view_id");
    }
}
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-history" style="color:var(--accent)"></i> Quotation History</h1>
    <p>All quotations you have created</p>
  </div>
  <a href="create_quotation.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> New Quotation</a>
</div>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-bottom:1.5rem">
<?php
$s_cards = [
  ['Draft',     'draft',     'pencil',       'var(--text2)'],
  ['Submitted', 'submitted', 'send',         'var(--info)'],
  ['Approved',  'approved',  'circle-check', 'var(--success)'],
  ['Rejected',  'rejected',  'circle-x',     'var(--danger)'],
];
foreach ($s_cards as [$st,$cls,$ic,$col]):
  $d = $stat_totals[$st] ?? ['cnt'=>0,'tot'=>0];
?>
<div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center">
  <i class="ti ti-<?= $ic ?>" style="font-size:1.4rem;color:<?= $col ?>"></i>
  <div style="font-size:1.4rem;font-weight:700;color:var(--text);margin:.3rem 0"><?= $d['cnt'] ?></div>
  <div style="font-size:.75rem;color:var(--text2)"><?= $st ?></div>
  <?php if ($d['tot'] > 0): ?>
  <div style="font-size:.72rem;color:var(--accent);margin-top:.2rem">E<?= number_format($d['tot'],2) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Filter -->
<div class="filter-bar">
  <form method="GET" style="display:flex;gap:.5rem;align-items:center">
    <select name="status" class="form-control" style="width:170px">
      <option value="">All Statuses</option>
      <?php foreach (['Draft','Submitted','Approved','Rejected'] as $s): ?>
      <option value="<?= $s ?>" <?= $status_filter===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter"></i> Filter</button>
    <a href="quotation_history.php" class="btn btn-secondary btn-sm">Clear</a>
  </form>
</div>

<?php if (mysqli_num_rows($quotations) === 0): ?>
<div class="empty-state" style="margin-top:2rem">
  <i class="ti ti-file-off"></i>
  <p>No quotations found.</p>
  <a href="create_quotation.php" class="btn btn-primary btn-sm" style="margin-top:.75rem"><i class="ti ti-plus"></i> Create Your First Quotation</a>
</div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Quot. #</th>
        <th>Client</th>
        <th>Fault</th>
        <th>Date</th>
        <th>Due</th>
        <th>Total</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($q = mysqli_fetch_assoc($quotations)):
        $sc_map = ['Draft'=>'draft','Submitted'=>'submitted','Approved'=>'approved','Rejected'=>'rejected'];
        $sc = $sc_map[$q['STATUS']] ?? 'draft';
    ?>
    <tr>
      <td style="font-weight:600;color:var(--accent)">#<?= $q['INVOICE_ID'] ?></td>
      <td><?= htmlspecialchars($q['COMPANY_NAME'] ?? '—') ?></td>
      <td>
        <a href="fault_details.php?id=<?= $q['REP_FAULT_ID'] ?>" style="color:var(--info);text-decoration:none;font-size:.82rem">
          Fault #<?= $q['REP_FAULT_ID'] ?>
        </a>
      </td>
      <td style="font-size:.8rem;color:var(--text2)"><?= date('d M Y', strtotime($q['INVOICE_DATE'])) ?></td>
      <td style="font-size:.8rem;color:var(--text2)"><?= $q['DUE_DATE'] ? date('d M Y', strtotime($q['DUE_DATE'])) : '—' ?></td>
      <td style="font-weight:700;color:var(--accent)">E <?= number_format($q['TOTAL'] ?? $q['calc_total'] ?? 0, 2) ?></td>
      <td><span class="badge badge-<?= $sc ?>"><?= $q['STATUS'] ?></span></td>
      <td>
        <div style="display:flex;gap:.4rem">
          <a href="quotation_history.php?view=<?= $q['INVOICE_ID'] ?>" class="btn btn-secondary btn-sm"><i class="ti ti-eye"></i></a>
          <?php if ($q['STATUS'] === 'Draft'): ?>
          <a href="create_quotation.php?fault_id=<?= $q['REP_FAULT_ID'] ?>&assign_id=<?= $q['ASSIGN_ID'] ?>" class="btn btn-primary btn-sm"><i class="ti ti-edit"></i></a>
          <?php endif; ?>
          <button onclick="printQuot(<?= $q['INVOICE_ID'] ?>)" class="btn btn-secondary btn-sm" title="Print"><i class="ti ti-printer"></i></button>
        </div>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- View Modal -->
<?php if ($view_quot): ?>
<div id="quot-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;display:flex;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;padding:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <div>
        <div style="font-size:1.1rem;font-weight:700">Quotation #<?= $view_quot['INVOICE_ID'] ?></div>
        <div style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($view_quot['COMPANY_NAME'] ?? '') ?> &bull; <?= date('d M Y', strtotime($view_quot['INVOICE_DATE'])) ?></div>
      </div>
      <a href="quotation_history.php" class="btn btn-secondary btn-sm"><i class="ti ti-x"></i></a>
    </div>

    <div style="display:flex;gap:.75rem;margin-bottom:1rem">
      <?php
      $sc_map = ['Draft'=>'draft','Submitted'=>'submitted','Approved'=>'approved','Rejected'=>'rejected'];
      $sc = $sc_map[$view_quot['STATUS']] ?? 'draft';
      ?>
      <span class="badge badge-<?= $sc ?>"><?= $view_quot['STATUS'] ?></span>
      <span style="font-size:.82rem;color:var(--text2)">Fault #<?= $view_quot['REP_FAULT_ID'] ?></span>
    </div>

    <div class="table-wrap" style="margin-bottom:1rem">
      <table id="print-table">
        <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
        <tbody>
        <?php
        $grand = 0;
        while ($line = mysqli_fetch_assoc($view_lines)):
            $lt = $line['LINE_TOTAL'] ?? ($line['QUANTITY'] * $line['UNIT_PRICE']);
            $grand += $lt;
        ?>
        <tr>
          <td><?= htmlspecialchars($line['DESCRIPTION']) ?></td>
          <td><?= $line['QUANTITY'] ?></td>
          <td>E <?= number_format($line['UNIT_PRICE'],2) ?></td>
          <td style="font-weight:600">E <?= number_format($lt,2) ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" style="text-align:right;font-weight:700;font-size:1rem;padding:.75rem 1rem">GRAND TOTAL</td>
            <td style="font-weight:700;font-size:1rem;color:var(--accent)">E <?= number_format($view_quot['TOTAL'] ?? $grand,2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div style="display:flex;gap:.5rem">
      <button onclick="printQuot(<?= $view_quot['INVOICE_ID'] ?>)" class="btn btn-primary btn-sm"><i class="ti ti-printer"></i> Print / PDF</button>
      <a href="quotation_history.php" class="btn btn-secondary btn-sm">Close</a>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function printQuot(id){ window.open('quotation_history.php?view='+id+'&print=1','_blank','width=700,height=900'); }
</script>

<?php require_once 'includes/tech_footer.php'; ?>

