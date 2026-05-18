<?php
$page_title = 'Messages';
require_once 'includes/tech_header.php';

// Handle send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $to_id   = intval($_POST['to_id']);
    $to_type = mysqli_real_escape_string($conn, $_POST['to_type'] ?? 'Admin');
    $subject = mysqli_real_escape_string($conn, trim($_POST['subject'] ?? 'No Subject'));
    $content = mysqli_real_escape_string($conn, trim($_POST['content'] ?? ''));
    $to_name = mysqli_real_escape_string($conn, trim($_POST['to_name'] ?? 'Admin'));
    $prio    = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Normal');

    if ($content && $to_id) {
        mysqli_query($conn, "
            INSERT INTO unified_messages (from_id, from_type, from_name, to_id, to_type, to_name, subject, content, priority)
            VALUES ($tech_id, 'Employee', '".mysqli_real_escape_string($conn,$tech_name)."', $to_id, '$to_type', '$to_name', '$subject', '$content', '$prio')");
        // Notify recipient
        mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ($to_id, '$to_type', 'New Message from $tech_name', '$subject')");
        $_SESSION['toast'] = ['msg'=>'Message sent!','type'=>'success'];
    }
    $redirect_to = intval($_GET['to_id'] ?? $to_id);
    header("Location: messages.php?to_id=$redirect_to&to_type=$to_type"); exit();
}

// Mark messages as read
$active_to_id   = intval($_GET['to_id']   ?? 1);
$active_to_type = $_GET['to_type'] ?? 'Admin';
if ($active_to_id) {
    mysqli_query($conn, "UPDATE unified_messages SET is_read=1, read_at=NOW() WHERE to_id=$tech_id AND to_type='Employee' AND from_id=$active_to_id AND is_read=0");
}

// Contacts list (Admin users + other employees who have messaged)
$contacts = mysqli_query($conn, "
    SELECT DISTINCT
        CASE WHEN um.from_id=$tech_id THEN um.to_id ELSE um.from_id END as contact_id,
        CASE WHEN um.from_id=$tech_id THEN um.to_type ELSE um.from_type END as contact_type,
        CASE WHEN um.from_id=$tech_id THEN um.to_name ELSE um.from_name END as contact_name,
        MAX(um.sent_time) as last_time,
        SUM(CASE WHEN um.to_id=$tech_id AND um.is_read=0 THEN 1 ELSE 0 END) as unread
    FROM unified_messages um
    WHERE um.from_id=$tech_id OR um.to_id=$tech_id
    GROUP BY contact_id, contact_type, contact_name
    ORDER BY last_time DESC");

// Add admin as default contact if not in list
$admin_in_list = false;
$contact_rows = [];
while ($cr = mysqli_fetch_assoc($contacts)) {
    if ($cr['contact_id'] == 1 && $cr['contact_type'] === 'Admin') $admin_in_list = true;
    $contact_rows[] = $cr;
}
if (!$admin_in_list) {
    array_unshift($contact_rows, ['contact_id'=>1,'contact_type'=>'Admin','contact_name'=>'Admin','last_time'=>null,'unread'=>0]);
}

// Get conversation thread
$messages = [];
if ($active_to_id) {
    $res = mysqli_query($conn, "
        SELECT um.*, e.FULL_NAME as sender_name
        FROM unified_messages um
        LEFT JOIN employee e ON um.from_id=e.EMP_ID AND um.from_type='Employee'
        WHERE (um.from_id=$tech_id AND um.to_id=$active_to_id)
           OR (um.from_id=$active_to_id AND um.to_id=$tech_id AND um.to_type='Employee')
        ORDER BY um.sent_time ASC");
    while ($m = mysqli_fetch_assoc($res)) $messages[] = $m;
}

// Get active contact name
$active_name = 'Admin';
foreach ($contact_rows as $cr) {
    if ($cr['contact_id'] == $active_to_id && $cr['contact_type'] === $active_to_type) {
        $active_name = $cr['contact_name'];
        break;
    }
}
?>

<div class="page-head">
  <div>
    <h1><i class="ti ti-message-circle" style="color:var(--accent)"></i> Messages</h1>
    <p>Real-time communication with Admin</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:260px 1fr;gap:1.25rem;height:calc(100vh - 200px);min-height:500px" class="msg-grid">

  <!-- Contacts Sidebar -->
  <div class="card" style="display:flex;flex-direction:column;overflow:hidden;padding:0">
    <div style="padding:1rem;border-bottom:1px solid var(--border);font-size:.85rem;font-weight:600;color:var(--text2)">CONVERSATIONS</div>
    <div style="flex:1;overflow-y:auto">
    <?php foreach ($contact_rows as $cr):
        $is_active = $cr['contact_id'] == $active_to_id && $cr['contact_type'] === $active_to_type;
        $initials  = strtoupper(substr($cr['contact_name'],0,1));
    ?>
    <a href="messages.php?to_id=<?= $cr['contact_id'] ?>&to_type=<?= urlencode($cr['contact_type']) ?>"
       style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;background:<?= $is_active ? 'rgba(240,165,0,.1)' : 'transparent' ?>;border-left:3px solid <?= $is_active ? 'var(--accent)' : 'transparent' ?>;text-decoration:none;transition:background .2s"
       onmouseover="this.style.background='<?= $is_active ? 'rgba(240,165,0,.1)' : 'var(--surface2)' ?>'"
       onmouseout="this.style.background='<?= $is_active ? 'rgba(240,165,0,.1)' : 'transparent' ?>'">
      <div style="width:38px;height:38px;border-radius:50%;background:<?= $is_active ? 'var(--accent)' : 'var(--surface2)' ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:<?= $is_active ? '#000' : 'var(--text)' ?>;flex-shrink:0"><?= $initials ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.85rem;font-weight:600;color:<?= $is_active ? 'var(--accent)' : 'var(--text)' ?>"><?= htmlspecialchars($cr['contact_name']) ?></div>
        <div style="font-size:.72rem;color:var(--text2)"><?= $cr['contact_type'] ?></div>
      </div>
      <?php if ($cr['unread'] > 0): ?>
      <span style="background:var(--danger);color:#fff;border-radius:99px;font-size:.65rem;font-weight:700;padding:.15rem .45rem"><?= $cr['unread'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    </div>
    <!-- New conversation button -->
    <div style="padding:.75rem;border-top:1px solid var(--border)">
      <button onclick="document.getElementById('new-msg-modal').style.display='flex'" class="btn btn-primary btn-sm" style="width:100%;justify-content:center">
        <i class="ti ti-plus"></i> New Message
      </button>
    </div>
  </div>

  <!-- Chat Window -->
  <div class="card" style="display:flex;flex-direction:column;overflow:hidden;padding:0">
    <!-- Header -->
    <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
      <div style="width:38px;height:38px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:#000"><?= strtoupper(substr($active_name,0,1)) ?></div>
      <div>
        <div style="font-weight:600;font-size:.95rem"><?= htmlspecialchars($active_name) ?></div>
        <div style="font-size:.72rem;color:var(--text2)"><?= htmlspecialchars($active_to_type) ?></div>
      </div>
    </div>

    <!-- Messages -->
    <div style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.75rem" id="chat-area">
    <?php if (empty($messages)): ?>
      <div class="empty-state" style="margin:auto">
        <i class="ti ti-message"></i>
        <p>No messages yet. Start the conversation!</p>
      </div>
    <?php else: ?>
    <?php foreach ($messages as $m):
        $is_mine = $m['from_id'] == $tech_id && $m['from_type'] === 'Employee';
    ?>
    <div style="display:flex;flex-direction:column;align-items:<?= $is_mine ? 'flex-end' : 'flex-start' ?>">
      <?php if (!$is_mine): ?>
      <div style="font-size:.72rem;color:var(--text2);margin-bottom:.25rem"><?= htmlspecialchars($m['from_name'] ?? $active_name) ?></div>
      <?php endif; ?>
      <div style="max-width:70%;background:<?= $is_mine ? 'var(--accent)' : 'var(--surface2)' ?>;color:<?= $is_mine ? '#000' : 'var(--text)' ?>;border-radius:<?= $is_mine ? '16px 16px 4px 16px' : '16px 16px 16px 4px' ?>;padding:.65rem 1rem">
        <?php if (!$is_mine && $m['subject'] && $m['subject'] !== 'No Subject'): ?>
        <div style="font-size:.72rem;font-weight:700;margin-bottom:.3rem;opacity:.7"><?= htmlspecialchars($m['subject']) ?></div>
        <?php endif; ?>
        <div style="font-size:.875rem;line-height:1.5"><?= nl2br(htmlspecialchars($m['content'])) ?></div>
      </div>
      <div style="font-size:.65rem;color:var(--text2);margin-top:.25rem"><?= date('d M H:i', strtotime($m['sent_time'])) ?>
        <?php if ($is_mine): ?><?= $m['is_read'] ? ' ✓✓' : ' ✓' ?><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- Input -->
    <form method="POST" style="padding:1rem;border-top:1px solid var(--border);display:flex;gap:.75rem;align-items:flex-end">
      <input type="hidden" name="to_id"   value="<?= $active_to_id ?>">
      <input type="hidden" name="to_type" value="<?= htmlspecialchars($active_to_type) ?>">
      <input type="hidden" name="to_name" value="<?= htmlspecialchars($active_name) ?>">
      <input type="hidden" name="subject" value="Message from <?= htmlspecialchars($tech_name) ?>">
      <input type="hidden" name="priority" value="Normal">
      <textarea name="content" class="form-control" rows="2" placeholder="Type your message..." required style="resize:none;flex:1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit()}"></textarea>
      <button type="submit" name="send_message" value="1" class="btn btn-primary" style="height:fit-content">
        <i class="ti ti-send"></i>
      </button>
    </form>
  </div>
</div>

<!-- New Message Modal -->
<div id="new-msg-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999;align-items:center;justify-content:center;padding:1rem">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;padding:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
      <div style="font-size:1rem;font-weight:700">New Message</div>
      <button onclick="document.getElementById('new-msg-modal').style.display='none'" class="btn btn-secondary btn-sm"><i class="ti ti-x"></i></button>
    </div>
    <form method="POST">
      <div class="form-group">
        <label>To</label>
        <select name="to_id" class="form-control" required onchange="updateToName(this)">
          <option value="1" data-type="Admin" data-name="Admin">Admin</option>
          <?php
          $emps = mysqli_query($conn, "SELECT EMP_ID, FULL_NAME, ROLE FROM employee WHERE EMP_ID != $tech_id ORDER BY FULL_NAME");
          while ($ep = mysqli_fetch_assoc($emps)):
          ?>
          <option value="<?= $ep['EMP_ID'] ?>" data-type="Employee" data-name="<?= htmlspecialchars($ep['FULL_NAME']) ?>"><?= htmlspecialchars($ep['FULL_NAME']) ?> (<?= $ep['ROLE'] ?>)</option>
          <?php endwhile; ?>
        </select>
      </div>
      <input type="hidden" name="to_type" id="new-to-type" value="Admin">
      <input type="hidden" name="to_name" id="new-to-name" value="Admin">
      <div class="form-group">
        <label>Subject</label>
        <input type="text" name="subject" class="form-control" placeholder="Message subject..." required>
      </div>
      <div class="form-group">
        <label>Priority</label>
        <select name="priority" class="form-control">
          <option value="Low">Low</option>
          <option value="Normal" selected>Normal</option>
          <option value="High">High</option>
          <option value="Urgent">Urgent</option>
        </select>
      </div>
      <div class="form-group">
        <label>Message *</label>
        <textarea name="content" class="form-control" rows="4" required placeholder="Write your message..."></textarea>
      </div>
      <div style="display:flex;gap:.5rem">
        <button type="submit" name="send_message" value="1" class="btn btn-primary"><i class="ti ti-send"></i> Send</button>
        <button type="button" onclick="document.getElementById('new-msg-modal').style.display='none'" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<style>@media(max-width:700px){.msg-grid{grid-template-columns:1fr!important;height:auto!important}}</style>
<script>
// Scroll to bottom of chat
const ca = document.getElementById('chat-area');
if(ca) ca.scrollTop = ca.scrollHeight;

function updateToName(sel){
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('new-to-type').value = opt.dataset.type || 'Admin';
  document.getElementById('new-to-name').value = opt.dataset.name || 'Admin';
}

// Auto-refresh messages every 15 seconds
setInterval(()=>{ location.reload(); }, 15000);
</script>

<?php require_once 'includes/tech_footer.php'; ?>

