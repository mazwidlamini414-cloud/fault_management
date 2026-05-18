<?php
$page_title = 'Work Progress';
require_once '../../includes/tech_header.php';

$fault_id = intval($_GET['id'] ?? 0);

// Get all in-progress faults for this technician
$active_faults = mysqli_query($conn, "
    SELECT rf.REP_FAULT_ID, rf.DESCRIPTION, rf.STATUS, rf.PRIORITY,
           c.COMPANY_NAME, a.ASSIGN_ID, a.DUE_DATE, a.ASSIGN_DATE
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS IN ('In Progress','Assigned')
    ORDER BY rf.PRIORITY DESC, rf.REPORT_DATE ASC");

// Selected fault
$selected = null;
$logs = null;
$total_hours = 0;
if ($fault_id) {
    $selected = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rf.*, c.COMPANY_NAME, a.ASSIGN_ID, a.DUE_DATE
        FROM reported_fault rf
        INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
        WHERE rf.REP_FAULT_ID = $fault_id AND at2.EMP_ID = $tech_id LIMIT 1"));
    if ($selected) {
        $logs = mysqli_query($conn, "SELECT wl.*, e.FULL_NAME FROM work_log wl LEFT JOIN employee e ON wl.EMP_ID = e.EMP_ID WHERE wl.ASSIGN_ID = {$selected['ASSIGN_ID']} ORDER BY wl.LOG_DATE DESC");
        $th = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(HOURS_SPENT),0) as hrs FROM work_log WHERE ASSIGN_ID={$selected['ASSIGN_ID']}"));
        $total_hours = round($th['hrs'],2);
    }
}

// Handle log submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assign_id  = intval($_POST['assign_id']);
    $log_type   = mysqli_real_escape_string($conn, $_POST['log_type'] ?? 'Update');
    $action_txt = mysqli_real_escape_string($conn, trim($_POST['action_taken'] ?? ''));
    $hours      = floatval($_POST['hours_spent'] ?? 0);

    if ($action_txt) {
        mysqli_query($conn, "INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT) VALUES ($assign_id, $tech_id, '$log_type', '$action_txt', $hours)");
        // Update fault status if needed
        if ($log_type === 'Start') {
            mysqli_query($conn, "UPDATE reported_fault SET STATUS='In Progress' WHERE REP_FAULT_ID=$fault_id");
        }
        $_SESSION['toast'] = ['msg'=>'Work log entry saved.','type'=>'success'];
        header("Location: work_progress.php?id=$fault_id"); exit();
    }
}
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-loader" style="color:var(--accent)"></i> Work Progress</h1>
    <p>Track your active work sessions and log hours</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:1.25rem;align-items:start" class="wp-grid">

  <!-- Fault Selector -->
  <div class="card" style="position:sticky;top:calc(var(--topbar-h) + 1rem)">
    <div class="card-title"><i class="ti ti-list" style="color:var(--accent)"></i> Active Jobs</div>
    <?php if (mysqli_num_rows($active_faults) === 0): ?>
      <div class="empty-state"><i class="ti ti-inbox"></i><p>No active faults</p></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.4rem">
    <?php mysqli_data_seek($active_faults,0); while ($af = mysqli_fetch_assoc($active_faults)):
        $is_active = $fault_id === intval($af['REP_FAULT_ID']);
        $sc = ['Assigned'=>'assigned','In Progress'=>'progress'][$af['STATUS']] ?? 'draft';
    ?>
    <a href="work_progress.php?id=<?= $af['REP_FAULT_ID'] ?>" style="display:block;padding:.75rem;background:<?= $is_active ? 'rgba(240,165,0,.12)' : 'var(--surface2)' ?>;border:1px solid <?= $is_active ? 'rgba(240,165,0,.4)' : 'var(--border)' ?>;border-radius:8px;text-decoration:none;transition:all .2s">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.25rem">
        <span style="font-size:.8rem;font-weight:600;color:<?= $is_active ? 'var(--accent)' : 'var(--text)' ?>">#<?= $af['REP_FAULT_ID'] ?></span>
        <span class="badge badge-<?= $sc ?>" style="font-size:.65rem"><?= $af['STATUS'] ?></span>
      </div>
      <div style="font-size:.78rem;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($af['COMPANY_NAME'] ?? '—') ?></div>
      <div style="font-size:.72rem;color:var(--text2);margin-top:.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_strimwidth(strip_tags($af['DESCRIPTION']),0,50,'...')) ?></div>
    </a>
    <?php endwhile; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right Panel -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">
  <?php if (!$selected): ?>
    <div class="card">
      <div class="empty-state">
        <i class="ti ti-hand-click"></i>
        <p>Select an active fault from the left panel to track your work.</p>
      </div>
    </div>
  <?php else: ?>

    <!-- Fault Header -->
    <div class="card" style="border-color:rgba(240,165,0,.25)">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
          <div style="font-size:1.1rem;font-weight:700;color:var(--text)">Fault #<?= $fault_id ?> &mdash; <?= htmlspecialchars($selected['COMPANY_NAME'] ?? '') ?></div>
          <div style="font-size:.82rem;color:var(--text2);margin-top:.25rem"><?= htmlspecialchars(mb_strimwidth(strip_tags($selected['DESCRIPTION']),0,100,'...')) ?></div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:.75rem;color:var(--text2)">Total Logged</div>
          <div style="font-size:1.5rem;font-weight:700;color:var(--accent)" id="total-display"><?= $total_hours ?>h</div>
        </div>
      </div>

      <!-- Live Timer -->
      <div style="background:var(--surface2);border-radius:10px;padding:1.25rem;margin-top:1rem;text-align:center;border:1px solid var(--border)">
        <div style="font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem">Session Timer</div>
        <div id="timer" style="font-size:3rem;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:2px">00:00:00</div>
        <div style="display:flex;gap:.75rem;justify-content:center;margin-top:1rem">
          <button onclick="startTimer()" id="btn-start" class="btn btn-success"><i class="ti ti-player-play"></i> Start Session</button>
          <button onclick="stopTimer()" id="btn-stop" class="btn btn-danger" style="display:none"><i class="ti ti-player-stop"></i> Stop & Log</button>
        </div>
      </div>
    </div>

    <!-- Log Work Form -->
    <div class="card">
      <div class="card-title"><i class="ti ti-pencil" style="color:var(--accent)"></i> Log Work Entry</div>
      <form method="POST" id="log-form">
        <input type="hidden" name="assign_id" value="<?= $selected['ASSIGN_ID'] ?>">
        <input type="hidden" name="hours_spent" id="hours-field" value="0">
        <div class="form-row">
          <div class="form-group">
            <label>Entry Type</label>
            <select name="log_type" class="form-control" required>
              <option value="Update">Progress Update</option>
              <option value="Note">Technical Note</option>
              <option value="Parts">Parts Used</option>
              <option value="Issue">Issue Found</option>
              <option value="Complete">Completion Note</option>
            </select>
          </div>
          <div class="form-group">
            <label>Hours Spent (manual override)</label>
            <input type="number" step="0.25" min="0" max="24" class="form-control" id="manual-hours" placeholder="0.00" value="">
          </div>
        </div>
        <div class="form-group">
          <label>Work Description *</label>
          <textarea name="action_taken" class="form-control" rows="4" placeholder="Describe the work performed, findings, actions taken..." required></textarea>
        </div>
        <div style="display:flex;gap:.75rem">
          <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Save Log Entry</button>
          <a href="fault_details.php?id=<?= $fault_id ?>" class="btn btn-secondary"><i class="ti ti-file-description"></i> View Full Details</a>
          <a href="create_quotation.php?fault_id=<?= $fault_id ?>&assign_id=<?= $selected['ASSIGN_ID'] ?>" class="btn btn-secondary"><i class="ti ti-file-invoice"></i> Create Quotation</a>
        </div>
      </form>
    </div>

    <!-- Work Log History -->
    <div class="card">
      <div class="card-title"><i class="ti ti-history" style="color:var(--accent)"></i> Work Log for this Fault</div>
      <?php if (!$logs || mysqli_num_rows($logs) === 0): ?>
        <div class="empty-state"><i class="ti ti-clipboard"></i><p>No work logged yet</p></div>
      <?php else: ?>
      <div class="table-wrap" style="border:none">
        <table>
          <thead>
            <tr><th>Date/Time</th><th>Type</th><th>Description</th><th>Hours</th></tr>
          </thead>
          <tbody>
          <?php while ($wl = mysqli_fetch_assoc($logs)): ?>
          <tr>
            <td style="font-size:.78rem;white-space:nowrap"><?= date('d M Y H:i', strtotime($wl['LOG_DATE'])) ?></td>
            <td><span class="badge badge-assigned" style="font-size:.7rem"><?= htmlspecialchars($wl['LOG_TYPE']) ?></span></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($wl['ACTION_TAKEN']) ?></td>
            <td style="font-size:.82rem;font-weight:600;color:var(--accent)"><?= $wl['HOURS_SPENT'] > 0 ? $wl['HOURS_SPENT'].'h' : '—' ?></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
  </div>
</div>

<style>@media(max-width:800px){.wp-grid{grid-template-columns:1fr!important}}</style>
<script>
let timerInterval = null, seconds = 0, running = false;
function pad(n){ return String(n).padStart(2,'0'); }
function tick(){
  seconds++;
  const h=Math.floor(seconds/3600), m=Math.floor((seconds%3600)/60), s=seconds%60;
  document.getElementById('timer').textContent = pad(h)+':'+pad(m)+':'+pad(s);
}
function startTimer(){
  if(running) return;
  running = true;
  timerInterval = setInterval(tick, 1000);
  document.getElementById('btn-start').style.display='none';
  document.getElementById('btn-stop').style.display='inline-flex';
}
function stopTimer(){
  clearInterval(timerInterval); running=false;
  const hrs = (seconds/3600).toFixed(2);
  document.getElementById('hours-field').value = hrs;
  document.getElementById('manual-hours').value = hrs;
  document.getElementById('btn-start').style.display='inline-flex';
  document.getElementById('btn-stop').style.display='none';
  showToast('Session stopped: '+hrs+' hours logged in timer field.','info');
}
document.getElementById('manual-hours')?.addEventListener('input', function(){
  document.getElementById('hours-field').value = this.value || 0;
});
document.getElementById('log-form')?.addEventListener('submit', function(){
  if(running) stopTimer();
});
</script>

<?php require_once '../../includes/tech_footer.php'; ?>


