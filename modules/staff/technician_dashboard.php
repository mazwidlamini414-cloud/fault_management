<?php
$page_title    = 'Dashboard';
$page_subtitle = 'Overview';
require_once __DIR__ . '/../../includes/tech_header.php';

// ── Stats ─────────────────────────────────────────────────────────────────────
$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    WHERE at2.EMP_ID = $tech_id");
$total_assigned = mysqli_fetch_assoc($q)['cnt'] ?? 0;

$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'In Progress'");
$in_progress = mysqli_fetch_assoc($q)['cnt'] ?? 0;

$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'Completed'");
$completed = mysqli_fetch_assoc($q)['cnt'] ?? 0;

$q = mysqli_query($conn, "
    SELECT COUNT(*) as cnt FROM assignment a
    INNER JOIN assignment_technician at2 ON a.ASSIGN_ID = at2.ASSIGN_ID
    INNER JOIN reported_fault rf ON a.REP_FAULT_ID = rf.REP_FAULT_ID
    WHERE at2.EMP_ID = $tech_id AND rf.STATUS = 'Assigned'");
$pending = mysqli_fetch_assoc($q)['cnt'] ?? 0;

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

// Hours logged this week
$q = mysqli_query($conn, "
    SELECT COALESCE(SUM(wl.HOURS_SPENT),0) as hrs
    FROM work_log wl
    INNER JOIN assignment_technician at2 ON wl.ASSIGN_ID = at2.ASSIGN_ID
    WHERE at2.EMP_ID = $tech_id
    AND wl.LOG_DATE >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$hours_week = round(mysqli_fetch_assoc($q)['hrs'] ?? 0, 1);
?>

<style>
/* ── Keyframes ───────────────────────────────── */
@keyframes fadeInUp   { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeInDown { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideInRight{from{opacity:0;transform:translateX(32px)} to{opacity:1;transform:translateX(0)}}
@keyframes popIn      { 0%{opacity:0;transform:scale(.85)} 70%{transform:scale(1.04)} 100%{opacity:1;transform:scale(1)} }
@keyframes pulse-ring { 0%{transform:scale(1);opacity:.7} 70%{transform:scale(1.55);opacity:0} 100%{opacity:0} }
@keyframes spin-slow  { to{transform:rotate(360deg)} }
@keyframes glow-border{ 0%,100%{box-shadow:0 0 0 0 rgba(240,165,0,0)} 50%{box-shadow:0 0 0 4px rgba(240,165,0,.25)} }
@keyframes counter-up { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
@keyframes icon-dance { 0%,100%{transform:translateY(0) rotate(0)} 25%{transform:translateY(-4px) rotate(-5deg)} 75%{transform:translateY(-2px) rotate(4deg)} }
@keyframes slide-down { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:translateY(0)} }
@keyframes notif-ping { 0%{transform:scale(1)} 50%{transform:scale(1.2)} 100%{transform:scale(1)} }







/* ── Quick-nav cards ─────────────────────── */
.db-quick-grid {
  display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
  gap:.7rem;margin-bottom:1.5rem;
}
.db-quick-card {
  background:var(--surface);border:1px solid var(--border);
  border-radius:13px;padding:1rem .85rem;
  display:flex;flex-direction:column;align-items:flex-start;gap:.45rem;
  text-decoration:none;transition:transform .22s, box-shadow .22s, border-color .22s;
  opacity:0;animation:fadeInUp .45s ease both;cursor:pointer;
}
.db-quick-card:hover { transform:translateY(-4px) scale(1.02);box-shadow:0 10px 28px rgba(0,0,0,.28);border-color:var(--accent); }
.db-quick-card:hover .db-qc-icon { animation:icon-dance .6s ease-in-out; }
.db-qc-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;transition:transform .22s; }
.db-qc-label { font-size:.77rem;color:var(--text2);font-weight:600;line-height:1.25; }
.db-qc-badge { font-size:1.25rem;font-weight:700;color:var(--text); }

/* ── Stat cards ─────────────────────────── */
.db-stats-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.5rem; }
@media(max-width:700px){.db-stats-grid{grid-template-columns:repeat(2,1fr)}}
.db-stat-card {
  background:var(--surface);border:1px solid var(--border);border-radius:13px;
  padding:1rem 1.1rem;display:flex;align-items:center;gap:.85rem;
  opacity:0;animation:popIn .45s ease both;transition:transform .22s,box-shadow .22s;
  overflow:hidden;position:relative;
}
.db-stat-card:hover { transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.22); }
.db-stat-icon { width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0; }
.db-stat-num  { font-size:1.6rem;font-weight:800;color:var(--text);line-height:1;animation:counter-up .6s ease both; }
.db-stat-lbl  { font-size:.75rem;color:var(--text2);margin-top:.2rem;font-weight:500; }

/* ── Bottom grid ───────────────────────── */
.db-bottom-grid { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem; }
@media(max-width:700px){.db-bottom-grid{grid-template-columns:1fr}}
.db-card { background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.1rem;opacity:0;animation:slideInRight .45s ease both; }
.db-card-title { display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:.85rem;padding-bottom:.65rem;border-bottom:1px solid var(--border); }

/* fault row */
.db-fault-row { display:flex;align-items:center;gap:.7rem;padding:.65rem .7rem;background:var(--surface2);border-radius:9px;border:1px solid var(--border);text-decoration:none;transition:all .18s;margin-bottom:.4rem; }
.db-fault-row:hover { background:#2d333b;transform:translateX(3px); }
.db-fault-client { font-size:.83rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.db-fault-desc   { font-size:.73rem;color:var(--text2);margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }

/* color helpers */
.c-orange{background:rgba(240,140,0,.15);color:#f0a500}
.c-blue  {background:rgba(56,139,253,.15);color:#56a3ff}
.c-green {background:rgba(63,185,80,.15);color:#3fb850}
.c-purple{background:rgba(148,103,241,.15);color:#a47dfa}
.c-red   {background:rgba(229,57,53,.15);color:#e57373}

.spin-icon { animation:spin-slow 1.8s linear infinite; }
.db-prog-bar { height:5px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:.5rem; }
.db-prog-fill { height:100%;border-radius:3px;transition:width 1.2s cubic-bezier(.22,1,.36,1); }
.db-empty { text-align:center;padding:2rem 1rem;color:var(--text2); }
.db-empty .ti{font-size:2.2rem;display:block;margin-bottom:.5rem;opacity:.4}
.db-clock { text-align:right;margin-top:.75rem;font-size:.72rem;color:var(--text2); }

/* staggered animation delays */
.db-quick-card:nth-child(1){animation-delay:.05s}
.db-quick-card:nth-child(2){animation-delay:.1s}
.db-quick-card:nth-child(3){animation-delay:.15s}
.db-quick-card:nth-child(4){animation-delay:.2s}
.db-quick-card:nth-child(5){animation-delay:.25s}
.db-quick-card:nth-child(6){animation-delay:.3s}
.db-quick-card:nth-child(7){animation-delay:.35s}
.db-stat-card:nth-child(1){animation-delay:.2s}
.db-stat-card:nth-child(2){animation-delay:.3s}
.db-stat-card:nth-child(3){animation-delay:.4s}
.db-stat-card:nth-child(4){animation-delay:.5s}
.db-card:nth-child(1){animation-delay:.45s}
.db-card:nth-child(2){animation-delay:.55s}

/* overlay */
.db-overlay{display:none;position:fixed;inset:0;z-index:400;}
.db-overlay.open{display:block;}

/* success/error flash */
.db-flash{display:none;padding:.6rem 1rem;border-radius:8px;font-size:.83rem;font-weight:600;margin-bottom:.75rem;}
.db-flash.success{background:rgba(63,185,80,.15);color:#3fb850;border:1px solid rgba(63,185,80,.3);display:block;}
.db-flash.error{background:rgba(229,57,53,.12);color:#e57373;border:1px solid rgba(229,57,53,.3);display:block;}
</style>


<!-- ══════════════════════════════════════════════
     QUICK-NAV CARDS
══════════════════════════════════════════════ -->
<div class="db-quick-grid">
<?php
$quick = [
  ['assigned_faults',   'alert-circle',  'Assigned Faults', 'orange', $total_assigned,  false],
  ['work_progress',     'loader',        'Work Progress',   'blue',   $in_progress,      false],
  ['create_quotation',  'file-invoice',  'New Quotation',   'purple', null,              false],
  ['quotation_history', 'history',       'Quotations',      'green',  $my_quotations,    false],
  ['work_history',      'checklist',     'Work History',    'green',  $completed,        false],
];
foreach ($quick as $idx => [$page,$icon,$label,$color,$badge,$embed]):
?>
<a href="<?= $embed ? '#' : $page.'.php' ?>"
   class="db-quick-card"
   <?= $embed ? 'onclick="handleQuickNav(\''.$page.'\'); return false;"' : '' ?>>
  <div class="db-qc-icon c-<?= $color ?>">
    <i class="ti ti-<?= $icon ?><?= $icon==='loader' ? ' spin-icon' : '' ?>"></i>
  </div>
  <div class="db-qc-label"><?= $label ?></div>
  <?php if ($badge !== null): ?>
  <div class="db-qc-badge"><?= $badge ?></div>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>


<!-- ══════════════════════════════════════════════
     STAT CARDS
══════════════════════════════════════════════ -->
<div class="db-stats-grid">
  <div class="db-stat-card" style="border-top:3px solid #f0a500">
    <div class="db-stat-icon c-orange"><i class="ti ti-clipboard-list"></i></div>
    <div>
      <div class="db-stat-num" data-target="<?= $total_assigned ?>">0</div>
      <div class="db-stat-lbl">Total Assigned</div>
      <div class="db-prog-bar"><div class="db-prog-fill" style="background:#f0a500;width:0" data-pct="<?= min(100,$total_assigned*10) ?>"></div></div>
    </div>
  </div>
  <div class="db-stat-card" style="border-top:3px solid #56a3ff">
    <div class="db-stat-icon c-blue"><i class="ti ti-loader spin-icon"></i></div>
    <div>
      <div class="db-stat-num" data-target="<?= $in_progress ?>">0</div>
      <div class="db-stat-lbl">In Progress</div>
      <div class="db-prog-bar"><div class="db-prog-fill" style="background:#56a3ff;width:0" data-pct="<?= $total_assigned>0?round($in_progress/$total_assigned*100):0 ?>"></div></div>
    </div>
  </div>
  <div class="db-stat-card" style="border-top:3px solid #3fb850">
    <div class="db-stat-icon c-green"><i class="ti ti-circle-check"></i></div>
    <div>
      <div class="db-stat-num" data-target="<?= $completed ?>">0</div>
      <div class="db-stat-lbl">Completed</div>
      <div class="db-prog-bar"><div class="db-prog-fill" style="background:#3fb850;width:0" data-pct="<?= $total_assigned>0?round($completed/$total_assigned*100):0 ?>"></div></div>
    </div>
  </div>
  <div class="db-stat-card" style="border-top:3px solid #a47dfa">
    <div class="db-stat-icon c-purple"><i class="ti ti-clock"></i></div>
    <div>
      <div class="db-stat-num" data-target="<?= $hours_week ?>" data-suffix="h">0h</div>
      <div class="db-stat-lbl">Hours This Week</div>
      <div class="db-prog-bar"><div class="db-prog-fill" style="background:#a47dfa;width:0" data-pct="<?= min(100,round($hours_week/40*100)) ?>"></div></div>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════
     RECENT FAULTS + ACTIVITY SUMMARY
══════════════════════════════════════════════ -->
<div class="db-bottom-grid">

  <!-- Recent Faults -->
  <div class="db-card">
    <div class="db-card-title">
      <i class="ti ti-alert-circle" style="color:var(--accent)"></i>
      Recent Assigned Faults
      <span style="margin-left:auto;font-size:.72rem;color:var(--text2);font-weight:400">Last 5</span>
    </div>
    <?php if (mysqli_num_rows($recent_faults) === 0): ?>
      <div class="db-empty"><i class="ti ti-inbox"></i><p>No faults assigned yet</p></div>
    <?php else: ?>
    <?php while ($f = mysqli_fetch_assoc($recent_faults)):
        $status_map = ['Assigned'=>'assigned','In Progress'=>'progress','Completed'=>'completed','Pending'=>'pending'];
        $sc = $status_map[$f['STATUS']] ?? 'draft';
        $prio_map = ['High'=>'high','Low'=>'low','Medium'=>'medium','Urgent'=>'urgent'];
        $pc = $prio_map[$f['PRIORITY']] ?? 'draft';
        $desc = mb_strimwidth(strip_tags($f['DESCRIPTION']),0,58,'...');
    ?>
    <a href="fault_details.php?id=<?= $f['REP_FAULT_ID'] ?>" class="db-fault-row">
      <div style="flex:1;min-width:0">
        <div class="db-fault-client"><?= htmlspecialchars($f['COMPANY_NAME'] ?? 'Unknown Client') ?></div>
        <div class="db-fault-desc"><?= htmlspecialchars($desc) ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.25rem;flex-shrink:0">
        <span class="badge badge-<?= $sc ?>"><?= $f['STATUS'] ?></span>
        <span class="badge badge-<?= $pc ?>" style="font-size:.62rem"><?= $f['PRIORITY'] ?></span>
      </div>
    </a>
    <?php endwhile; ?>
    <a href="assigned_faults.php" class="btn btn-secondary btn-sm" style="margin-top:.85rem;width:100%;justify-content:center">
      <i class="ti ti-list"></i> View All Faults
    </a>
    <?php endif; ?>
  </div>

  <!-- Summary / Quick Stats Panel -->
  <div class="db-card">
    <div class="db-card-title">
      <i class="ti ti-activity" style="color:var(--accent)"></i>
      My Activity Summary
    </div>
    <div style="display:flex;align-items:center;justify-content:center;gap:1.5rem;margin-bottom:1rem">
      <div style="position:relative;width:90px;height:90px;flex-shrink:0">
        <svg width="90" height="90" viewBox="0 0 90 90">
          <circle cx="45" cy="45" r="36" fill="none" stroke="var(--border)" stroke-width="9"/>
          <circle cx="45" cy="45" r="36" fill="none" stroke="#3fb850" stroke-width="9"
            stroke-dasharray="<?= $total_assigned > 0 ? round(226 * $completed / $total_assigned) : 0 ?> 226"
            stroke-dashoffset="56.5" stroke-linecap="round"
            id="donut-arc" style="transition:stroke-dasharray 1.4s cubic-bezier(.22,1,.36,1)"/>
        </svg>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
          <span style="font-size:1.25rem;font-weight:800;color:var(--text)"><?= $total_assigned > 0 ? round($completed/$total_assigned*100) : 0 ?>%</span>
          <span style="font-size:.6rem;color:var(--text2)">done</span>
        </div>
      </div>
      <div style="flex:1;display:flex;flex-direction:column;gap:.5rem">
        <?php foreach([
          ['Assigned','c-orange',$total_assigned],
          ['In Progress','c-blue',$in_progress],
          ['Completed','c-green',$completed],
          ['Pending','c-purple',$pending],
        ] as [$lbl,$cls,$val]): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:.78rem">
          <span style="display:flex;align-items:center;gap:.4rem">
            <span style="width:8px;height:8px;border-radius:2px;display:inline-block" class="<?= $cls ?>"></span>
            <span style="color:var(--text2)"><?= $lbl ?></span>
          </span>
          <span style="font-weight:700;color:var(--text)"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="border-top:1px solid var(--border);padding-top:.85rem;display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
      <div style="background:var(--surface2);border-radius:10px;padding:.7rem;text-align:center;border:1px solid var(--border)">
        <div style="font-size:1.3rem;font-weight:800;color:var(--text)"><?= $my_quotations ?></div>
        <div style="font-size:.72rem;color:var(--text2);margin-top:.15rem">Quotations</div>
      </div>
      <div style="background:var(--surface2);border-radius:10px;padding:.7rem;text-align:center;border:1px solid var(--border)">
        <div style="font-size:1.3rem;font-weight:800;color:var(--text)"><?= $hours_week ?>h</div>
        <div style="font-size:.72rem;color:var(--text2);margin-top:.15rem">Hours / Week</div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.6rem">
      <a href="create_quotation.php" class="btn btn-primary btn-sm" style="justify-content:center"><i class="ti ti-file-plus"></i> New Quotation</a>
      <a href="work_history.php" class="btn btn-secondary btn-sm" style="justify-content:center"><i class="ti ti-history"></i> Work History</a>
    </div>
  </div>

</div><!-- /bottom-grid -->


<div class="db-clock">
  Last updated: <span id="live-time"></span>
  <span style="margin-left:.5rem;opacity:.5">&bull;</span>
  <span style="margin-left:.5rem" id="live-date"></span>
</div>


<!-- ══════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════ -->
<script>
// ── Live clock ────────────────────────────────────────────
function tick() {
  const now = new Date();
  document.getElementById('live-time').textContent = now.toLocaleTimeString('en-SZ');
  document.getElementById('live-date').textContent  = now.toLocaleDateString('en-SZ', {weekday:'short',day:'numeric',month:'short',year:'numeric'});
}
tick(); setInterval(tick, 1000);

// ── Counter animation ────────────────────────────────────
document.querySelectorAll('.db-stat-num[data-target]').forEach(el => {
  const target = parseFloat(el.dataset.target);
  const suffix = el.dataset.suffix || '';
  const start  = performance.now();
  const dur    = 900;
  function step(now) {
    const t = Math.min((now - start) / dur, 1);
    const ease = 1 - Math.pow(1 - t, 3);
    const val = target % 1 === 0 ? Math.round(target * ease) : (target * ease).toFixed(1);
    el.textContent = val + suffix;
    if (t < 1) requestAnimationFrame(step);
  }
  setTimeout(() => requestAnimationFrame(step), 300);
});

// ── Progress bars ────────────────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.db-prog-fill[data-pct]').forEach(el => {
    el.style.width = el.dataset.pct + '%';
  });
}, 400);

// ── Donut arc animate ────────────────────────────────────
setTimeout(() => {
  const arc = document.getElementById('donut-arc');
  if (arc) {
    const current = arc.getAttribute('stroke-dasharray');
    arc.setAttribute('stroke-dasharray', '0 226');
    setTimeout(() => arc.setAttribute('stroke-dasharray', current), 50);
  }
}, 300);

// ── Idle icon dance ──────────────────────────────────────
setInterval(() => {
  const icons = document.querySelectorAll('.db-quick-card .db-qc-icon');
  if (icons.length === 0) return;
  const pick = icons[Math.floor(Math.random() * icons.length)];
  const i = pick.querySelector('i');
  if (!i || i.classList.contains('spin-icon')) return;
  i.style.animation = 'icon-dance .6s ease-in-out';
  setTimeout(() => i.style.animation = '', 700);
}, 2200);
</script>

<?php require_once '../../includes/tech_footer.php'; ?>