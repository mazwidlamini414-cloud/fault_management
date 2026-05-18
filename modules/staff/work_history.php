<?php
$page_title = 'Work History';
require_once '../../includes/tech_header.php';

$month_filter = $_GET['month'] ?? date('Y-m');

// Performance summary
$perf = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT rf.REP_FAULT_ID) as total_completed,
        COALESCE(SUM(wl.HOURS_SPENT),0) as total_hours
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
    LEFT JOIN work_log wl ON wl.ASSIGN_ID=a.ASSIGN_ID AND wl.EMP_ID=$tech_id
    WHERE at2.EMP_ID=$tech_id AND rf.STATUS='Completed'"));

$month_perf = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT rf.REP_FAULT_ID) as total_completed,
        COALESCE(SUM(wl.HOURS_SPENT),0) as total_hours
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
    LEFT JOIN work_log wl ON wl.ASSIGN_ID=a.ASSIGN_ID AND wl.EMP_ID=$tech_id
    WHERE at2.EMP_ID=$tech_id AND rf.STATUS='Completed'
    AND DATE_FORMAT(a.ASSIGN_DATE,'%Y-%m')='".mysqli_real_escape_string($conn,$month_filter)."'"));

// Completed faults with logs
$history = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.PRIORITY, rf.REPORT_DATE, rf.STATUS,
           c.COMPANY_NAME, a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE,
           COALESCE(SUM(wl.HOURS_SPENT),0) as hours_logged,
           COUNT(wl.LOG_ID) as log_entries
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID=c.CLIENT_ID
    LEFT JOIN work_log wl ON wl.ASSIGN_ID=a.ASSIGN_ID AND wl.EMP_ID=$tech_id
    WHERE at2.EMP_ID=$tech_id AND rf.STATUS='Completed'
    AND ('' = '".mysqli_real_escape_string($conn,$month_filter)."' OR DATE_FORMAT(a.ASSIGN_DATE,'%Y-%m')='".mysqli_real_escape_string($conn,$month_filter)."')
    GROUP BY rf.REP_FAULT_ID, a.ASSIGN_ID
    ORDER BY rf.REPORT_DATE DESC");

// Monthly breakdown for chart (last 6 months)
$monthly_chart = mysqli_query($conn, "
    SELECT DATE_FORMAT(a.ASSIGN_DATE,'%b %Y') as month_label,
           DATE_FORMAT(a.ASSIGN_DATE,'%Y-%m') as month_key,
           COUNT(DISTINCT rf.REP_FAULT_ID) as jobs,
           COALESCE(SUM(wl.HOURS_SPENT),0) as hours
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID=a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID=at2.ASSIGN_ID
    LEFT JOIN work_log wl ON wl.ASSIGN_ID=a.ASSIGN_ID AND wl.EMP_ID=$tech_id
    WHERE at2.EMP_ID=$tech_id AND rf.STATUS='Completed'
    AND a.ASSIGN_DATE >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC");

$chart_labels = $chart_jobs = $chart_hours = [];
while ($mc = mysqli_fetch_assoc($monthly_chart)) {
    $chart_labels[] = $mc['month_label'];
    $chart_jobs[]   = intval($mc['jobs']);
    $chart_hours[]  = round($mc['hours'],1);
}
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-checklist" style="color:var(--accent)"></i> Work History</h1>
    <p>Your completed jobs and performance record</p>
  </div>
  <form method="GET" style="display:flex;gap:.5rem;align-items:center">
    <input type="month" name="month" value="<?= htmlspecialchars($month_filter) ?>" class="form-control" style="width:160px">
    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter"></i> Filter</button>
    <a href="work_history.php" class="btn btn-secondary btn-sm">All Time</a>
  </form>
</div>

<!-- Summary Stats -->
<div class="grid-4" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-icon green"><i class="ti ti-circle-check"></i></div>
    <div>
      <div class="stat-num"><?= intval($perf['total_completed']) ?></div>
      <div class="stat-label">Total Completed (All Time)</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="ti ti-clock"></i></div>
    <div>
      <div class="stat-num"><?= round($perf['total_hours'],1) ?>h</div>
      <div class="stat-label">Total Hours Logged</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="ti ti-calendar-stats"></i></div>
    <div>
      <div class="stat-num"><?= intval($month_perf['total_completed']) ?></div>
      <div class="stat-label">Completed This Month</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="ti ti-trending-up"></i></div>
    <div>
      <div class="stat-num"><?= $perf['total_completed'] > 0 ? round($perf['total_hours'] / $perf['total_completed'],1) : 0 ?>h</div>
      <div class="stat-label">Avg Hours Per Job</div>
    </div>
  </div>
</div>

<!-- Performance Chart -->
<?php if (!empty($chart_labels)): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-title"><i class="ti ti-chart-bar" style="color:var(--accent)"></i> Monthly Performance (Last 6 Months)</div>
  <div style="position:relative;height:220px">
    <canvas id="perf-chart" role="img" aria-label="Monthly jobs completed chart"></canvas>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
new Chart(document.getElementById('perf-chart'),{
  type:'bar',
  data:{
    labels:<?= json_encode($chart_labels) ?>,
    datasets:[
      {label:'Jobs Completed',data:<?= json_encode($chart_jobs) ?>,backgroundColor:'rgba(240,165,0,.6)',borderColor:'#f0a500',borderWidth:1.5,borderRadius:4},
      {label:'Hours Logged',data:<?= json_encode($chart_hours) ?>,backgroundColor:'rgba(88,166,255,.4)',borderColor:'#58a6ff',borderWidth:1.5,borderRadius:4,yAxisID:'y1'}
    ]
  },
  options:{
    responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,labels:{color:'#8b949e',font:{size:11}}}},
    scales:{
      x:{ticks:{color:'#8b949e'},grid:{color:'rgba(255,255,255,.05)'}},
      y:{ticks:{color:'#8b949e'},grid:{color:'rgba(255,255,255,.05)'},title:{display:true,text:'Jobs',color:'#8b949e'}},
      y1:{position:'right',ticks:{color:'#58a6ff'},grid:{display:false},title:{display:true,text:'Hours',color:'#58a6ff'}}
    }
  }
});
</script>
<?php endif; ?>

<!-- History Table -->
<?php if (mysqli_num_rows($history) === 0): ?>
<div class="empty-state">
  <i class="ti ti-calendar-off"></i>
  <p>No completed faults found for the selected period.</p>
</div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Fault #</th>
        <th>Client</th>
        <th>Description</th>
        <th>Priority</th>
        <th>Assigned</th>
        <th>Hours</th>
        <th>Log Entries</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php while ($h = mysqli_fetch_assoc($history)):
        $prio_map = ['High'=>'high','Low'=>'low','Medium'=>'medium','Urgent'=>'urgent'];
        $pc = $prio_map[$h['PRIORITY']] ?? 'draft';
    ?>
    <tr>
      <td style="font-weight:600;color:var(--success)">#<?= $h['REP_FAULT_ID'] ?></td>
      <td style="font-size:.875rem"><?= htmlspecialchars($h['COMPANY_NAME'] ?? '—') ?></td>
      <td style="font-size:.82rem;max-width:200px"><?= htmlspecialchars(mb_strimwidth(strip_tags($h['DESCRIPTION']),0,60,'...')) ?></td>
      <td><span class="badge badge-<?= $pc ?>"><?= $h['PRIORITY'] ?></span></td>
      <td style="font-size:.8rem;color:var(--text2)"><?= $h['ASSIGN_DATE'] ? date('d M Y', strtotime($h['ASSIGN_DATE'])) : '—' ?></td>
      <td style="font-weight:600;color:var(--accent)"><?= round($h['hours_logged'],2) ?>h</td>
      <td style="font-size:.82rem;color:var(--text2)"><?= $h['log_entries'] ?> entries</td>
      <td>
        <a href="fault_details.php?id=<?= $h['REP_FAULT_ID'] ?>" class="btn btn-secondary btn-sm"><i class="ti ti-eye"></i> View</a>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once '../../includes/tech_footer.php'; ?>