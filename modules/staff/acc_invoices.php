<?php
$page_title    = 'Invoices';
$page_subtitle = 'Generated Client Invoices';
require_once __DIR__ . '/../../includes/acc_header.php';

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$query = "
    SELECT i.INVOICE_ID, i.TOTAL, i.INVOICE_DATE, i.DUE_DATE, i.STATUS,
           i.PAID_AMOUNT, (i.TOTAL - i.PAID_AMOUNT) as outstanding,
           c.COMPANY_NAME, c.CLIENT_ID, a.ASSIGN_ID
    FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    LEFT JOIN client c ON i.CLIENT_ID = c.CLIENT_ID
    WHERE i.TYPE = 'Invoice'";

if ($status) {
    $query .= " AND i.STATUS = '$status'";
}
if ($search) {
    $query .= " AND (c.COMPANY_NAME LIKE '%$search%' OR i.INVOICE_ID LIKE '%$search%')";
}

$query .= " ORDER BY i.INVOICE_DATE DESC";
$result = mysqli_query($conn, $query);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
  <h2 style="margin:0"><?= $page_title ?></h2>
  <div style="display:flex;gap:.5rem">
    <a href="generate_invoice.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> New Invoice</a>
    <a href="dashboard.php" class="btn btn-secondary btn-sm"><i class="ti ti-arrow-left"></i> Dashboard</a>
  </div>
</div>

<!-- Filters -->
<div class="card" style="display:flex;gap:1rem;align-items:flex-end;margin-bottom:1.5rem;padding:1rem">
  <div style="flex:1">
    <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.5rem">Search</label>
    <input type="text" id="search" value="<?= htmlspecialchars($search) ?>" placeholder="Company name or Invoice ID..." style="width:100%;padding:.5rem;border:1px solid var(--border);border-radius:6px;font-size:.85rem">
  </div>
  <div>
    <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.5rem">Status</label>
    <select id="status" style="padding:.5rem;border:1px solid var(--border);border-radius:6px;font-size:.85rem">
      <option value="">All Status</option>
      <option value="Pending Payment" <?= $status === 'Pending Payment' ? 'selected' : '' ?>>Pending Payment</option>
      <option value="Paid" <?= $status === 'Paid' ? 'selected' : '' ?>>Paid</option>
      <option value="Overdue" <?= $status === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
    </select>
  </div>
  <button onclick="filterInvoices()" class="btn btn-primary" style="padding:.5rem 1.5rem">
    <i class="ti ti-filter"></i> Filter
  </button>
</div>

<!-- Invoices Table -->
<div class="card" style="overflow:auto">
  <?php if (mysqli_num_rows($result) === 0): ?>
    <div class="empty-state"><i class="ti ti-inbox"></i><p>No invoices found</p></div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse">
    <thead style="border-bottom:1px solid var(--border)">
      <tr style="background:var(--surface2)">
        <th style="padding:1rem;text-align:left;font-size:.85rem;font-weight:600;color:var(--text2)">Invoice ID</th>
        <th style="padding:1rem;text-align:left;font-size:.85rem;font-weight:600;color:var(--text2)">Client</th>
        <th style="padding:1rem;text-align:right;font-size:.85rem;font-weight:600;color:var(--text2)">Total</th>
        <th style="padding:1rem;text-align:right;font-size:.85rem;font-weight:600;color:var(--text2)">Paid</th>
        <th style="padding:1rem;text-align:right;font-size:.85rem;font-weight:600;color:var(--text2)">Outstanding</th>
        <th style="padding:1rem;text-align:center;font-size:.85rem;font-weight:600;color:var(--text2)">Status</th>
        <th style="padding:1rem;text-align:center;font-size:.85rem;font-weight:600;color:var(--text2)">Due Date</th>
        <th style="padding:1rem;text-align:center;font-size:.85rem;font-weight:600;color:var(--text2)">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($row = mysqli_fetch_assoc($result)): 
        $status_map = ['Paid'=>'success', 'Pending Payment'=>'warning', 'Overdue'=>'danger'];
        $status_color = $status_map[$row['STATUS']] ?? 'secondary';
        $is_overdue = (strtotime($row['DUE_DATE']) < time() && $row['STATUS'] !== 'Paid');
        if ($is_overdue) {
            $row['STATUS'] = 'Overdue';
            $status_color = 'danger';
        }
    ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:1rem;font-size:.85rem;font-weight:600">#<?= $row['INVOICE_ID'] ?></td>
        <td style="padding:1rem;font-size:.85rem"><?= htmlspecialchars($row['COMPANY_NAME'] ?? 'Unknown') ?></td>
        <td style="padding:1rem;text-align:right;font-size:.85rem;font-weight:600;color:var(--primary)">$<?= number_format($row['TOTAL'], 2) ?></td>
        <td style="padding:1rem;text-align:right;font-size:.85rem;color:var(--success)">$<?= number_format($row['PAID_AMOUNT'], 2) ?></td>
        <td style="padding:1rem;text-align:right;font-size:.85rem;font-weight:600;color:<?= $row['outstanding'] > 0 ? 'var(--danger)' : 'var(--success)' ?>">$<?= number_format($row['outstanding'], 2) ?></td>
        <td style="padding:1rem;text-align:center"><span class="badge badge-<?= $status_color ?>"><?= $row['STATUS'] ?></span></td>
        <td style="padding:1rem;text-align:center;font-size:.85rem;color:var(--text2)"><?= date('d M Y', strtotime($row['DUE_DATE'])) ?></td>
        <td style="padding:1rem;text-align:center">
          <a href="invoice_details.php?invoice_id=<?= $row['INVOICE_ID'] ?>" class="btn btn-primary btn-sm"><i class="ti ti-eye"></i> View</a>
        </td>
      </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
function filterInvoices() {
  const search = document.getElementById('search').value;
  const status = document.getElementById('status').value;
  window.location.href = `?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`;
}
</script>

<?php require_once '../../includes/acc_footer.php'; ?>


