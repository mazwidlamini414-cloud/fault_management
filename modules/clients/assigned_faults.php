<?php
$page_title = 'Assigned Faults';
require_once 'includes/tech_header.php';

// Filters
$status_filter   = $_GET['status']   ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search          = $_GET['search']   ?? '';

$where = "WHERE at2.EMP_ID = $tech_id";
if ($status_filter)   $where .= " AND rf.STATUS = '".mysqli_real_escape_string($conn,$status_filter)."'";
if ($priority_filter) $where .= " AND rf.PRIORITY = '".mysqli_real_escape_string($conn,$priority_filter)."'";
if ($search) {
    $s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (c.COMPANY_NAME LIKE '%$s%' OR rf.DESCRIPTION LIKE '%$s%' OR rf.REPORTED_BY LIKE '%$s%')";
}

$faults = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE, rf.REPORTED_BY,
           c.COMPANY_NAME, c.COMPANY_PHONE, a.ASSIGN_ID, a.DUE_DATE, a.ASSIGN_DATE
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    $where
    ORDER BY rf.REPORT_DATE DESC");

$total_rows = mysqli_num_rows($faults);
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-alert-circle" style="color:var(--accent)"></i> Assigned Faults</h1>
    <p><?= $total_rows ?> fault(s) found</p>
  </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
  <form method="GET" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;width:100%">
    <div class="search-bar">
      <i class="ti ti-search"></i>
      <input type="text" name="search" placeholder="Search client, description..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <select name="status" class="form-control" style="width:160px">
      <option value="">All Statuses</option>
      <?php foreach (['Assigned','In Progress','Completed','Pending'] as $s): ?>
      <option value="<?= $s ?>" <?= $status_filter===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <select name="priority" class="form-control" style="width:140px">
      <option value="">All Priorities</option>
      <?php foreach (['High','Medium','Low','Urgent'] as $p): ?>
      <option value="<?= $p ?>" <?= $priority_filter===$p?'selected':'' ?>><?= $p ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-search"></i> Filter</button>
    <a href="assigned_faults.php" class="btn btn-secondary btn-sm"><i class="ti ti-x"></i> Clear</a>
  </form>
</div>

<?php if ($total_rows === 0): ?>
<div class="empty-state" style="margin-top:3rem">
  <i class="ti ti-inbox"></i>
  <p>No faults match your criteria.</p>
  <a href="assigned_faults.php" class="btn btn-secondary btn-sm" style="margin-top:.75rem">Clear Filters</a>
</div>
<?php else: ?>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Client</th>
        <th>Description</th>
        <th>Priority</th>
        <th>Status</th>
        <th>Assigned</th>
        <th>Due Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $i = 1;
    while ($f = mysqli_fetch_assoc($faults)):
        $status_map = ['Assigned'=>'assigned','In Progress'=>'progress','Completed'=>'completed','Pending'=>'pending'];
        $sc = $status_map[$f['STATUS']] ?? 'draft';
        $prio_map = ['High'=>'high','Low'=>'low','Medium'=>'medium','Urgent'=>'urgent'];
        $pc = $prio_map[$f['PRIORITY']] ?? 'draft';
        $desc = mb_strimwidth(strip_tags($f['DESCRIPTION']),0,70,'...');
        $due = $f['DUE_DATE'];
        $overdue = $due && strtotime($due) < time() && $f['STATUS'] !== 'Completed';
    ?>
    <tr>
      <td style="color:var(--text2);font-size:.78rem">#<?= $f['REP_FAULT_ID'] ?></td>
      <td>
        <div style="font-weight:600;font-size:.875rem"><?= htmlspecialchars($f['COMPANY_NAME'] ?? '—') ?></div>
        <div style="font-size:.75rem;color:var(--text2)"><?= htmlspecialchars($f['REPORTED_BY'] ?? '') ?></div>
      </td>
      <td style="max-width:220px;font-size:.82rem"><?= htmlspecialchars($desc) ?></td>
      <td><span class="badge badge-<?= $pc ?>"><span class="priority-dot p-<?= strtolower($f['PRIORITY']) ?>"></span><?= $f['PRIORITY'] ?></span></td>
      <td><span class="badge badge-<?= $sc ?>"><?= $f['STATUS'] ?></span></td>
      <td style="font-size:.8rem;color:var(--text2)"><?= $f['ASSIGN_DATE'] ? date('d M Y', strtotime($f['ASSIGN_DATE'])) : '—' ?></td>
      <td style="font-size:.8rem">
        <?php if ($due): ?>
          <span style="color:<?= $overdue ? 'var(--danger)' : 'var(--text2)' ?>">
            <?= $overdue ? '⚠ ' : '' ?><?= date('d M Y', strtotime($due)) ?>
          </span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          <a href="fault_details.php?id=<?= $f['REP_FAULT_ID'] ?>" class="btn btn-secondary btn-sm"><i class="ti ti-eye"></i> View</a>
          <?php if ($f['STATUS'] === 'Assigned'): ?>
          <a href="fault_details.php?id=<?= $f['REP_FAULT_ID'] ?>&action=start" class="btn btn-primary btn-sm"><i class="ti ti-player-play"></i> Start</a>
          <?php elseif ($f['STATUS'] === 'In Progress'): ?>
          <a href="work_progress.php?id=<?= $f['REP_FAULT_ID'] ?>" class="btn btn-success btn-sm"><i class="ti ti-loader"></i> Progress</a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php $i++; endwhile; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once 'includes/tech_footer.php'; ?>