<?php
$page_title = 'Fault Details';
require_once 'includes/tech_header.php';

$fault_id = intval($_GET['id'] ?? 0);
if (!$fault_id) { header('Location: assigned_faults.php'); exit(); }

// Fetch fault + verify it belongs to this technician
$fault = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT rf.*, c.COMPANY_NAME, c.COMPANY_PHONE, c.COMPANY_EMAIL, c.COMPANY_ADDRESS,
           c.CONTACT_PERSON_NAME, a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE
    FROM reported_fault rf
    INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
    WHERE rf.REP_FAULT_ID = $fault_id AND at2.EMP_ID = $tech_id
    LIMIT 1"));

if (!$fault) { $_SESSION['toast']=['msg'=>'Fault not found or not assigned to you.','type'=>'error']; header('Location: assigned_faults.php'); exit(); }

// Handle status actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $note   = trim($_POST['note'] ?? '');

    if ($action === 'start' && $fault['STATUS'] === 'Assigned') {
        mysqli_query($conn, "UPDATE reported_fault SET STATUS='In Progress' WHERE REP_FAULT_ID=$fault_id");
        mysqli_query($conn, "INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT) VALUES ({$fault['ASSIGN_ID']}, $tech_id, 'Start', 'Work started by technician', 0)");
        // Notify admin
        mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES (1, 'Admin', 'Work Started', 'Technician $tech_name started work on fault #$fault_id')");
        $_SESSION['toast'] = ['msg'=>'Work started. Status updated to In Progress.','type'=>'success'];
        header("Location: fault_details.php?id=$fault_id"); exit();
    }
    if ($action === 'complete' && $fault['STATUS'] === 'In Progress') {
        if (!$note) { $_SESSION['toast']=['msg'=>'Please add a completion note.','type'=>'error']; header("Location: fault_details.php?id=$fault_id"); exit(); }
        mysqli_query($conn, "UPDATE reported_fault SET STATUS='Completed' WHERE REP_FAULT_ID=$fault_id");
        $note_esc = mysqli_real_escape_string($conn, $note);
        mysqli_query($conn, "INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT) VALUES ({$fault['ASSIGN_ID']}, $tech_id, 'Complete', '$note_esc', 0)");
        mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES (1, 'Admin', 'Fault Completed', 'Fault #$fault_id marked completed by $tech_name')");
        // Notify client
        mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ({$fault['CLIENT_ID']}, 'Client', 'Fault Resolved', 'Your fault #$fault_id has been resolved. Please verify and confirm.')");
        $_SESSION['toast'] = ['msg'=>'Fault marked as completed!','type'=>'success'];
        header("Location: fault_details.php?id=$fault_id"); exit();
    }
    if ($action === 'note' && $note) {
        $note_esc = mysqli_real_escape_string($conn, $note);
        mysqli_query($conn, "INSERT INTO work_log (ASSIGN_ID, EMP_ID, LOG_TYPE, ACTION_TAKEN, HOURS_SPENT) VALUES ({$fault['ASSIGN_ID']}, $tech_id, 'Note', '$note_esc', 0)");
        $_SESSION['toast'] = ['msg'=>'Note added to fault timeline.','type'=>'success'];
        header("Location: fault_details.php?id=$fault_id"); exit();
    }
    // Re-fetch after update
    $fault = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rf.*, c.COMPANY_NAME, c.COMPANY_PHONE, c.COMPANY_EMAIL, c.COMPANY_ADDRESS,
               c.CONTACT_PERSON_NAME, a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE
        FROM reported_fault rf
        INNER JOIN assignment a ON rf.REP_FAULT_ID = a.REP_FAULT_ID
        INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
        LEFT JOIN client c ON rf.CLIENT_ID = c.CLIENT_ID
        WHERE rf.REP_FAULT_ID = $fault_id AND at2.EMP_ID = $tech_id LIMIT 1"));
}

// Work log / timeline
$logs = mysqli_query($conn, "
    SELECT wl.*, e.FULL_NAME
    FROM work_log wl
    LEFT JOIN employee e ON wl.EMP_ID = e.EMP_ID
    WHERE wl.ASSIGN_ID = {$fault['ASSIGN_ID']}
    ORDER BY wl.LOG_DATE ASC");

// Status map
$status_map = ['Assigned'=>'assigned','In Progress'=>'progress','Completed'=>'completed','Pending'=>'pending'];
$sc = $status_map[$fault['STATUS']] ?? 'draft';
$prio_map = ['High'=>'high','Low'=>'low','Medium'=>'medium','Urgent'=>'urgent'];
$pc = $prio_map[$fault['PRIORITY']] ?? 'draft';

$page_subtitle = 'Fault #' . $fault_id;
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-file-description" style="color:var(--accent)"></i> Fault #<?= $fault_id ?></h1>
    <p><?= htmlspecialchars($fault['COMPANY_NAME'] ?? '') ?> &mdash; <?= date('d M Y', strtotime($fault['REPORT_DATE'])) ?></p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="assigned_faults.php" class="btn btn-secondary btn-sm"><i class="ti ti-arrow-left"></i> Back</a>
    <?php if ($fault['STATUS'] === 'Assigned'): ?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="start">
      <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Start work on this fault?')"><i class="ti ti-player-play"></i> Start Work</button>
    </form>
    <?php elseif ($fault['STATUS'] === 'In Progress'): ?>
    <a href="work_progress.php?id=<?= $fault_id ?>" class="btn btn-success btn-sm"><i class="ti ti-loader"></i> Work Progress</a>
    <a href="create_quotation.php?fault_id=<?= $fault_id ?>&assign_id=<?= $fault['ASSIGN_ID'] ?>" class="btn btn-primary btn-sm"><i class="ti ti-file-invoice"></i> Create Quotation</a>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem" class="detail-grid">

  <!-- LEFT COLUMN -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Fault Info -->
    <div class="card">
      <div class="card-title"><i class="ti ti-info-circle" style="color:var(--accent)"></i> Fault Information</div>
      <div class="form-row" style="margin-bottom:.75rem">
        <div>
          <div style="font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Status</div>
          <span class="badge badge-<?= $sc ?>" style="font-size:.85rem;padding:.4rem .9rem"><?= $fault['STATUS'] ?></span>
        </div>
        <div>
          <div style="font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Priority</div>
          <span class="badge badge-<?= $pc ?>" style="font-size:.85rem;padding:.4rem .9rem"><?= $fault['PRIORITY'] ?></span>
        </div>
      </div>
      <div style="background:var(--surface2);border-radius:8px;padding:1rem;margin-bottom:.75rem">
        <div style="font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">Description</div>
        <div style="font-size:.875rem;color:var(--text);white-space:pre-wrap;line-height:1.6"><?= htmlspecialchars($fault['DESCRIPTION'] ?? '') ?></div>
      </div>
      <div class="form-row">
        <?php
        $meta = [
          ['Reported By', $fault['REPORTED_BY'] ?? '—'],
          ['Report Date', $fault['REPORT_DATE'] ? date('d M Y H:i', strtotime($fault['REPORT_DATE'])) : '—'],
          ['Assigned Date', $fault['ASSIGN_DATE'] ? date('d M Y', strtotime($fault['ASSIGN_DATE'])) : '—'],
          ['Due Date', $fault['DUE_DATE'] ? date('d M Y', strtotime($fault['DUE_DATE'])) : '—'],
        ];
        foreach ($meta as [$label,$val]):
        ?>
        <div>
          <div style="font-size:.75rem;color:var(--text2);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.25rem"><?= $label ?></div>
          <div style="font-size:.875rem;color:var(--text)"><?= htmlspecialchars($val) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card-title"><i class="ti ti-timeline" style="color:var(--accent)"></i> Fault Timeline</div>
      <div class="timeline">
        <!-- Initial report entry -->
        <div class="tl-item">
          <div class="tl-dot">R</div>
          <div class="tl-content">
            <div class="tl-action">Fault Reported</div>
            <div class="tl-meta"><?= $fault['REPORT_DATE'] ? date('d M Y H:i', strtotime($fault['REPORT_DATE'])) : '' ?> &bull; <?= htmlspecialchars($fault['REPORTED_BY'] ?? 'Client') ?></div>
          </div>
        </div>
        <div class="tl-item">
          <div class="tl-dot">A</div>
          <div class="tl-content">
            <div class="tl-action">Fault Assigned</div>
            <div class="tl-meta"><?= $fault['ASSIGN_DATE'] ? date('d M Y', strtotime($fault['ASSIGN_DATE'])) : '' ?> &bull; Admin</div>
            <div class="tl-note">Assigned to <?= htmlspecialchars($tech_name) ?></div>
          </div>
        </div>
        <?php while ($log = mysqli_fetch_assoc($logs)):
            $log_icons = ['Start'=>'play','Complete'=>'check','Note'=>'note','Update'=>'refresh'];
            $li = $log_icons[$log['LOG_TYPE']] ?? 'dot';
        ?>
        <div class="tl-item">
          <div class="tl-dot" style="background:var(--accent);color:#000"><?= strtoupper(substr($log['LOG_TYPE'],0,1)) ?></div>
          <div class="tl-content">
            <div class="tl-action"><?= htmlspecialchars($log['LOG_TYPE']) ?></div>
            <div class="tl-meta"><?= date('d M Y H:i', strtotime($log['LOG_DATE'])) ?> &bull; <?= htmlspecialchars($log['FULL_NAME'] ?? 'Technician') ?><?= $log['HOURS_SPENT'] > 0 ? ' &bull; '.$log['HOURS_SPENT'].'h' : '' ?></div>
            <?php if ($log['ACTION_TAKEN']): ?>
            <div class="tl-note"><?= htmlspecialchars($log['ACTION_TAKEN']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; ?>
      </div>

      <!-- Add Note -->
      <?php if (in_array($fault['STATUS'],['Assigned','In Progress'])): ?>
      <hr class="divider">
      <div style="font-size:.85rem;font-weight:600;color:var(--text2);margin-bottom:.5rem">Add Update / Note</div>
      <form method="POST" style="display:flex;gap:.5rem">
        <input type="hidden" name="action" value="note">
        <input type="text" name="note" class="form-control" placeholder="Enter update note..." required style="flex:1">
        <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i></button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Complete Fault -->
    <?php if ($fault['STATUS'] === 'In Progress'): ?>
    <div class="card" style="border-color:rgba(63,185,80,.3)">
      <div class="card-title"><i class="ti ti-circle-check" style="color:var(--success)"></i> Mark as Completed</div>
      <p style="font-size:.85rem;color:var(--text2);margin-bottom:1rem">Provide a completion note before marking this fault as completed. The client will be notified automatically.</p>
      <form method="POST" onsubmit="return confirm('Mark this fault as completed? This action cannot be undone.')">
        <input type="hidden" name="action" value="complete">
        <div class="form-group">
          <label>Completion Note *</label>
          <textarea name="note" class="form-control" rows="3" placeholder="Describe what was done to resolve the fault..." required></textarea>
        </div>
        <button type="submit" class="btn btn-success"><i class="ti ti-circle-check"></i> Mark as Completed</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT COLUMN -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Client Info -->
    <div class="card">
      <div class="card-title"><i class="ti ti-building" style="color:var(--accent)"></i> Client Details</div>
      <?php
      $ci = [
        ['ti-building','Company',$fault['COMPANY_NAME'] ?? '—'],
        ['ti-user','Contact',$fault['CONTACT_PERSON_NAME'] ?? '—'],
        ['ti-phone','Phone',$fault['COMPANY_PHONE'] ?? '—'],
        ['ti-mail','Email',$fault['COMPANY_EMAIL'] ?? '—'],
        ['ti-map-pin','Address',$fault['COMPANY_ADDRESS'] ?? '—'],
      ];
      foreach ($ci as [$icon,$label,$val]):
      ?>
      <div style="display:flex;align-items:flex-start;gap:.75rem;margin-bottom:.75rem">
        <i class="ti <?= $icon ?>" style="color:var(--text2);margin-top:.15rem;flex-shrink:0"></i>
        <div>
          <div style="font-size:.72rem;color:var(--text2);font-weight:600;text-transform:uppercase"><?= $label ?></div>
          <div style="font-size:.85rem;color:var(--text)"><?= htmlspecialchars($val) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-title"><i class="ti ti-bolt" style="color:var(--accent)"></i> Quick Actions</div>
      <div style="display:flex;flex-direction:column;gap:.5rem">
        <?php if ($fault['STATUS'] === 'Assigned'): ?>
        <form method="POST">
          <input type="hidden" name="action" value="start">
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center" onclick="return confirm('Start work on this fault?')">
            <i class="ti ti-player-play"></i> Start Work
          </button>
        </form>
        <?php endif; ?>
        <?php if (in_array($fault['STATUS'],['Assigned','In Progress','Completed'])): ?>
        <a href="create_quotation.php?fault_id=<?= $fault_id ?>&assign_id=<?= $fault['ASSIGN_ID'] ?>" class="btn btn-secondary" style="width:100%;justify-content:center">
          <i class="ti ti-file-invoice"></i> Create Quotation
        </a>
        <?php endif; ?>
        <a href="work_progress.php?id=<?= $fault_id ?>" class="btn btn-secondary" style="width:100%;justify-content:center">
          <i class="ti ti-loader"></i> Work Progress
        </a>
        <a href="messages.php?to_id=1&to_type=Admin" class="btn btn-secondary" style="width:100%;justify-content:center">
          <i class="ti ti-message-circle"></i> Message Admin
        </a>
      </div>
    </div>

    <!-- Assignment Info -->
    <div class="card">
      <div class="card-title"><i class="ti ti-clipboard" style="color:var(--accent)"></i> Assignment</div>
      <div style="display:flex;flex-direction:column;gap:.6rem">
        <?php
        $ai = [
          ['Assignment ID','#'.$fault['ASSIGN_ID']],
          ['Assigned On', $fault['ASSIGN_DATE'] ? date('d M Y', strtotime($fault['ASSIGN_DATE'])) : '—'],
          ['Due Date', $fault['DUE_DATE'] ? date('d M Y', strtotime($fault['DUE_DATE'])) : '—'],
          ['Your Role','Technician'],
        ];
        foreach ($ai as [$l,$v]):
        ?>
        <div style="display:flex;justify-content:space-between;font-size:.85rem">
          <span style="color:var(--text2)"><?= $l ?></span>
          <span style="font-weight:600"><?= htmlspecialchars($v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<style>
@media(max-width:800px){.detail-grid{grid-template-columns:1fr!important}}
</style>

<?php require_once 'includes/tech_footer.php'; ?>
