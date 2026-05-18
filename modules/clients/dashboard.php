<?php
$page_title    = 'Dashboard';
$page_subtitle = 'Overview';
require_once 'includes/tech_header.php';

// ── Stats ─────────────────────────────────────────────────────────────────────
// Total assigned faults
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    WHERE at2.EMP_ID = $tech_id");
$total_assigned = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// In-progress
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'In Progress'");
$in_progress = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Completed
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'Completed'");
$completed = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Pending (assigned but not started)
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'Assigned'");
$pending = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Quotations submitted
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM invoice i
    INNER JOIN assignment a ON i.ASSIGN_ID = a.ASSIGN_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    WHERE at2.EMP_ID = $tech_id");
$my_quotations = mysqli_fetch_assoc($q)['cnt'] ?? 0;

// Recent assigned faults (last 5)
$recent_faults = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY, rf.REPORT_DATE,
           c.COMPANY_NAME, a.ASSIGN_ID, a.DUE_DATE
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    WHERE at2.EMP_ID = $tech_id
    ORDER BY rf.REPORT_DATE DESC LIMIT 5");

// Recent notifications
$recent_notifs = mysqli_query($conn, "
    SELECT * FROM notifications
    WHERE user_id = $tech_id AND user_type = 'Employee'
    ORDER BY created_at DESC LIMIT 5");

// Hours logged this week
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(wl.HOURS_SPENT),0) as hrs
    FROM work_log wl
    INNER JOIN assignment_technician at2 ON wl.ASSIGN_ID = at2.ASSIGN_ID
    WHERE at2.EMP_ID = $tech_id
    AND wl.LOG_DATE >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$hours_week = round(mysqli_fetch_assoc($q)['hrs'] ?? 0, 1);
?>

<!-- Quick-nav cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1.5rem">
<?php
$quick = [
    ['assigned_faults',   'alert-circle',  'Assigned Faults', 'orange', $total_assigned],
    ['work_progress',     'loader',        'Work Progress',   'blue',   $in_progress],
    ['create_quotation',  'file-invoice',  'New Quotation',   'purple', null],
    ['quotation_history', 'history',       'Quotations',      'green',  $my_quotations],
    ['work_history',      'checklist',     'Work History',    'green',  $completed],
    ['messages',          'message-circle','Messages',        'blue',   $msg_count],
    ['notifications',     'bell',          'Notifications',   'red',    $notif_count],
    ['profile',           'user-circle',   'My Profile',      'orange', null],
];
foreach ($quick as [$page,$icon,$label,$color,$badge]):
?>
<a href="<?= $page ?>.php" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1rem;display:flex;flex-direction:column;align-items:flex-start;gap:.5rem;text-decoration:none;transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.3)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
  <div class="stat-icon <?= $color ?>"><i class="ti ti-<?= $icon ?>"></i></div>
  <div style="font-size:.8rem;color:var(--text2);font-weight:500;line-height:1.2"><?= $label ?></div>
  <?php if ($badge !== null): ?>
  <div style="font-size:1.3rem;font-weight:700;color:var(--text)"><?= $badge ?></div>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- Stats Row -->
<div class="grid-4" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-icon orange"><i class="ti ti-clipboard-list"></i></div>
    <div>
      <div class="stat-num"><?= $total_assigned ?></div>
      <div class="stat-label">Total Assigned</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="ti ti-loader"></i></div>
    <div>
      <div class="stat-num"><?= $in_progress ?></div>
      <div class="stat-label">In Progress</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="ti ti-circle-check"></i></div>
    <div>
      <div class="stat-num"><?= $completed ?></div>
      <div class="stat-label">Completed</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="ti ti-clock"></i></div>
    <div>
      <div class="stat-num"><?= $hours_week ?>h</div>
      <div class="stat-label">Hours This Week</div>
    </div>
  </div>
</div>

<!-- Recent Faults + Notifications -->
<div class="grid-2">

  <!-- Recent Faults -->
  <div class="card">
    <div class="card-title"><i class="ti ti-alert-circle" style="color:var(--accent)"></i> Recent Assigned Faults</div>
    <?php if (mysqli_num_rows($recent_faults) === 0): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No faults assigned yet</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php while ($f = mysqli_fetch_assoc($recent_faults)):
        $status_map = ['Assigned'=>'assigned','In Progress'=>'progress','Completed'=>'completed','Pending'=>'pending'];
        $sc = $status_map[$f['STATUS']] ?? 'draft';
        $prio_map = ['High'=>'high','Low'=>'low','Medium'=>'medium','Urgent'=>'urgent'];
        $pc = $prio_map[$f['PRIORITY']] ?? 'draft';
        $desc = mb_strimwidth(strip_tags($f['DESCRIPTION']),0,60,'...');
    ?>
    <a href="fault_details.php?id=<?= $f['REP_FAULT_ID'] ?>" style="display:flex;align-items:center;gap:.75rem;padding:.75rem;background:var(--surface2);border-radius:8px;border:1px solid var(--border);text-decoration:none;transition:background .2s" onmouseover="this.style.background='#2d333b'" onmouseout="this.style.background='var(--surface2)'">
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($f['COMPANY_NAME'] ?? 'Unknown Client') ?></div>
        <div style="font-size:.75rem;color:var(--text2);margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($desc) ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem;flex-shrink:0">
        <span class="badge badge-<?= $sc ?>"><?= $f['STATUS'] ?></span>
        <span class="badge badge-<?= $pc ?>" style="font-size:.65rem"><?= $f['PRIORITY'] ?></span>
      </div>
    </a>
    <?php endwhile; ?>
    </div>
    <a href="assigned_faults.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;width:100%;justify-content:center">View All Faults</a>
    <?php endif; ?>
  </div>

  <!-- Notifications -->
  <div class="card">
    <div class="card-title"><i class="ti ti-bell" style="color:var(--accent)"></i> Recent Notifications</div>
    <?php if (mysqli_num_rows($recent_notifs) === 0): ?>
      <div class="empty-state"><i class="ti ti-bell-off"></i><p>No notifications</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php while ($n = mysqli_fetch_assoc($recent_notifs)): ?>
    <div style="padding:.75rem;background:<?= $n['is_read'] ? 'var(--surface2)' : 'rgba(240,165,0,.07)' ?>;border-radius:8px;border:1px solid <?= $n['is_read'] ? 'var(--border)' : 'rgba(240,165,0,.2)' ?>">
      <div style="font-size:.82rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($n['title'] ?? '') ?></div>
      <div style="font-size:.75rem;color:var(--text2);margin-top:.2rem"><?= htmlspecialchars(mb_strimwidth($n['message'] ?? '',0,80,'...')) ?></div>
      <div style="font-size:.7rem;color:var(--text2);margin-top:.3rem"><?= date('d M H:i', strtotime($n['created_at'])) ?></div>
    </div>
    <?php endwhile; ?>
    </div>
    <a href="notifications.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;width:100%;justify-content:center">View All</a>
    <?php endif; ?>
  </div>

</div>

<!-- Live clock -->
<div style="text-align:right;margin-top:1rem;font-size:.75rem;color:var(--text2)">
  Last updated: <span id="live-time"></span>
</div>
<script>
function tick(){ document.getElementById('live-time').textContent = new Date().toLocaleString('en-SZ'); }
tick(); setInterval(tick,1000);
</script>

<?php require_once 'includes/tech_footer.php'; ?>

