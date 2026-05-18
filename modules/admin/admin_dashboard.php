<?php
// ============================================================
// BUSIQUIP FAULT MANAGEMENT SYSTEM — ADMIN DASHBOARD
// admin_dashboard.php
// Place in: fault_management/modules/admin/
// DB: busiquip_final | Matches existing schema exactly
// ============================================================

session_start();

// ── DB CONFIG — uses environment variables (Railway/Docker) or XAMPP defaults ──
require_once __DIR__ . '/../../config/database.php';
$conn->set_charset('utf8mb4');

// ── AUTH GUARD ─────────────────────────────────────────────
// Uncomment in production:
// if (!isset($_SESSION['admin_id'])) { header('Location: ../../login.php'); exit; }
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

// ── AJAX HANDLER ──────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    switch ($_GET['ajax']) {

        // ── DASHBOARD STATS ──────────────────────────────
        case 'stats':
            $stats = [];

            // Fault counts by status
            $r = $conn->query("SELECT STATUS, COUNT(*) as cnt FROM reported_fault GROUP BY STATUS");
            $fault_stats = ['Pending'=>0,'Assigned'=>0,'In Progress'=>0,'Completed'=>0,'Rejected'=>0,'Verified'=>0];
            while($row = $r->fetch_assoc()) $fault_stats[$row['STATUS']] = (int)$row['cnt'];
            $stats['faults'] = $fault_stats;
            $stats['total_faults'] = array_sum($fault_stats);

            // Clients
            $stats['clients'] = (int)$conn->query("SELECT COUNT(*) as c FROM client")->fetch_assoc()['c'];

            // Technicians
            $stats['technicians'] = (int)$conn->query("SELECT COUNT(*) as c FROM employee WHERE ROLE='Technician'")->fetch_assoc()['c'];

            // Invoices
            $inv = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(TOTAL),0) as total FROM invoice")->fetch_assoc();
            $stats['invoices'] = (int)$inv['cnt'];
            $stats['invoice_total'] = number_format((float)$inv['total'], 2);

            // Payments
            $pay = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(AMOUNT_PAID),0) as total FROM payment WHERE STATUS='Paid'")->fetch_assoc();
            $stats['payments'] = (int)$pay['cnt'];
            $stats['revenue'] = number_format((float)$pay['total'], 2);

            // Quotations (assignments with no invoice yet)
            $stats['quotations'] = (int)$conn->query("SELECT COUNT(*) as c FROM assignment a LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID WHERE i.INVOICE_ID IS NULL")->fetch_assoc()['c'];

            echo json_encode($stats);
            break;

        // ── ALL FAULTS ────────────────────────────────────
        case 'faults':
            $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
            $status_f = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
            $priority_f = isset($_GET['priority']) ? $conn->real_escape_string($_GET['priority']) : '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = 12;
            $offset = ($page - 1) * $per;

            $where = "WHERE 1=1";
            if ($search) $where .= " AND (c.COMPANY_NAME LIKE '%$search%' OR rf.DESCRIPTION LIKE '%$search%' OR f.FAULT_TYPE LIKE '%$search%' OR rf.REPORTED_BY LIKE '%$search%')";
            if ($status_f) $where .= " AND rf.STATUS = '$status_f'";
            if ($priority_f) $where .= " AND rf.PRIORITY = '$priority_f'";

            $sql = "SELECT rf.*, c.COMPANY_NAME, c.COMPANY_EMAIL, f.FAULT_TYPE,
                           p.PROD_NAME, cp.SERIAL_NUM,
                           a.ASSIGN_ID, a.STATUS as ASSIGN_STATUS, a.DUE_DATE,
                           GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') as TECHNICIANS
                    FROM reported_fault rf
                    LEFT JOIN client c ON c.CLIENT_ID = rf.CLIENT_ID
                    LEFT JOIN fault f ON f.FAULT_ID = rf.FAULT_ID
                    LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID = rf.CLIENT_PROD_ID
                    LEFT JOIN product p ON p.PROD_ID = cp.PROD_ID
                    LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
                    LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
                    LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
                    $where
                    GROUP BY rf.REP_FAULT_ID
                    ORDER BY rf.REPORT_DATE DESC
                    LIMIT $per OFFSET $offset";

            $total_r = $conn->query("SELECT COUNT(DISTINCT rf.REP_FAULT_ID) as cnt FROM reported_fault rf LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID $where");
            $total_count = (int)$total_r->fetch_assoc()['cnt'];

            $faults = [];
            $res = $conn->query($sql);
            while($row = $res->fetch_assoc()) $faults[] = $row;

            echo json_encode(['faults' => $faults, 'total' => $total_count, 'pages' => ceil($total_count/$per), 'page' => $page]);
            break;

        // ── SINGLE FAULT DETAIL ───────────────────────────
        case 'fault_detail':
            $id = (int)$_GET['id'];
            $sql = "SELECT rf.*, c.COMPANY_NAME, c.COMPANY_EMAIL, c.COMPANY_PHONE, c.CONTACT_PERSON_NAME,
                           f.FAULT_TYPE, f.DEFAULT_SLA_DAYS,
                           p.PROD_NAME, p.PROD_TYPE, cp.SERIAL_NUM, cp.PURCHASE_DATE, cp.WARRANTY_END_DATE,
                           a.ASSIGN_ID, a.ASSIGN_DATE, a.DUE_DATE, a.STATUS as ASSIGN_STATUS
                    FROM reported_fault rf
                    LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
                    LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                    LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID=rf.CLIENT_PROD_ID
                    LEFT JOIN product p ON p.PROD_ID=cp.PROD_ID
                    LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                    WHERE rf.REP_FAULT_ID=$id";
            $fault = $conn->query($sql)->fetch_assoc();

            // Technicians
            $techs = [];
            if ($fault['ASSIGN_ID']) {
                $tr = $conn->query("SELECT e.EMP_ID, e.FULL_NAME, e.EMAIL, at2.ROLE_IN_JOB FROM assignment_technician at2 JOIN employee e ON e.EMP_ID=at2.EMP_ID WHERE at2.ASSIGN_ID=".(int)$fault['ASSIGN_ID']);
                while($t=$tr->fetch_assoc()) $techs[]=$t;
            }

            // Work logs
            $logs = [];
            if ($fault['ASSIGN_ID']) {
                $lr = $conn->query("SELECT wl.*, e.FULL_NAME FROM work_log wl JOIN employee e ON e.EMP_ID=wl.EMP_ID WHERE wl.ASSIGN_ID=".(int)$fault['ASSIGN_ID']." ORDER BY wl.LOG_DATE DESC");
                while($l=$lr->fetch_assoc()) $logs[]=$l;
            }

            echo json_encode(['fault'=>$fault,'technicians'=>$techs,'logs'=>$logs]);
            break;

        // ── TECHNICIANS LIST ──────────────────────────────
        case 'technicians':
            $res = $conn->query("SELECT e.*,
                COUNT(DISTINCT CASE WHEN rf.STATUS IN ('Assigned','In Progress') THEN rf.REP_FAULT_ID END) as active_faults,
                COUNT(DISTINCT rf.REP_FAULT_ID) as total_faults
                FROM employee e
                LEFT JOIN assignment_technician at2 ON at2.EMP_ID=e.EMP_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=at2.ASSIGN_ID
                LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                WHERE e.ROLE='Technician'
                GROUP BY e.EMP_ID ORDER BY e.FULL_NAME");
            $techs = [];
            while($r=$res->fetch_assoc()) $techs[]=$r;
            echo json_encode($techs);
            break;

        // ── CLIENTS LIST ──────────────────────────────────
        case 'clients':
            $res = $conn->query("SELECT c.*,
                COUNT(DISTINCT rf.REP_FAULT_ID) as total_faults,
                COUNT(DISTINCT CASE WHEN rf.STATUS NOT IN ('Completed','Rejected','Verified') THEN rf.REP_FAULT_ID END) as open_faults,
                COUNT(DISTINCT i.INVOICE_ID) as invoices,
                COALESCE(SUM(i.TOTAL),0) as invoice_total
                FROM client c
                LEFT JOIN reported_fault rf ON rf.CLIENT_ID=c.CLIENT_ID
                LEFT JOIN invoice i ON i.CLIENT_ID=c.CLIENT_ID
                GROUP BY c.CLIENT_ID ORDER BY c.COMPANY_NAME");
            $clients=[];
            while($r=$res->fetch_assoc()) $clients[]=$r;
            echo json_encode($clients);
            break;

        // ── INVOICES (READ-ONLY) ───────────────────────────
        case 'invoices':
            $res=$conn->query("SELECT i.*, c.COMPANY_NAME, a.STATUS as ASSIGN_STATUS,
                COALESCE(SUM(p.AMOUNT_PAID),0) as paid_amount
                FROM invoice i
                LEFT JOIN client c ON c.CLIENT_ID=i.CLIENT_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID
                LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID AND p.STATUS='Paid'
                GROUP BY i.INVOICE_ID ORDER BY i.INVOICE_DATE DESC");
            $invs=[];
            while($r=$res->fetch_assoc()) $invs[]=$r;
            echo json_encode($invs);
            break;

        // ── PAYMENTS (READ-ONLY) ──────────────────────────
        case 'payments':
            $res=$conn->query("SELECT p.*, i.TOTAL as invoice_total, c.COMPANY_NAME
                FROM payment p
                JOIN invoice i ON i.INVOICE_ID=p.INVOICE_ID
                JOIN client c ON c.CLIENT_ID=i.CLIENT_ID
                ORDER BY p.PAYMENT_DATE DESC");
            $pays=[];
            while($r=$res->fetch_assoc()) $pays[]=$r;
            echo json_encode($pays);
            break;

        // ── ACCOUNTS (EMPLOYEES) ──────────────────────────
        case 'accounts':
            $res=$conn->query("SELECT EMP_ID,FULL_NAME,EMAIL,ROLE,HIRE_DATE,USERNAME FROM employee ORDER BY ROLE,FULL_NAME");
            $emps=[];
            while($r=$res->fetch_assoc()) $emps[]=$r;
            echo json_encode($emps);
            break;

        // ── RECENT ACTIVITY ───────────────────────────────
        case 'activity':
            $items=[];
            // Recent fault reports
            $r=$conn->query("SELECT 'fault_reported' as type, rf.REP_FAULT_ID as ref_id, c.COMPANY_NAME as actor,
                f.FAULT_TYPE as detail, rf.REPORT_DATE as ts, rf.STATUS as status
                FROM reported_fault rf JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                ORDER BY rf.REPORT_DATE DESC LIMIT 5");
            while($row=$r->fetch_assoc()) $items[]=$row;
            // Recent assignments
            $r=$conn->query("SELECT 'fault_assigned' as type, a.ASSIGN_ID as ref_id, 'Admin' as actor,
                CONCAT('Fault #',rf.REP_FAULT_ID,' assigned') as detail, a.ASSIGN_DATE as ts, a.STATUS as status
                FROM assignment a JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                ORDER BY a.ASSIGN_DATE DESC LIMIT 5");
            while($row=$r->fetch_assoc()) $items[]=$row;
            // Sort by ts desc
            usort($items, fn($a,$b)=>strtotime($b['ts'])-strtotime($a['ts']));
            echo json_encode(array_slice($items,0,15));
            break;

        // ── ANALYTICS ────────────────────────────────────
        case 'analytics':
            // Faults per month last 6 months
            $monthly=$conn->query("SELECT DATE_FORMAT(REPORT_DATE,'%b %Y') as month, COUNT(*) as cnt
                FROM reported_fault WHERE REPORT_DATE >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY YEAR(REPORT_DATE), MONTH(REPORT_DATE) ORDER BY REPORT_DATE");
            $m_data=[];
            while($r=$monthly->fetch_assoc()) $m_data[]=$r;

            // By priority
            $prio=$conn->query("SELECT PRIORITY, COUNT(*) as cnt FROM reported_fault WHERE PRIORITY IS NOT NULL GROUP BY PRIORITY");
            $p_data=[];
            while($r=$prio->fetch_assoc()) $p_data[]=$r;

            // By status
            $stat=$conn->query("SELECT STATUS, COUNT(*) as cnt FROM reported_fault GROUP BY STATUS");
            $s_data=[];
            while($r=$stat->fetch_assoc()) $s_data[]=$r;

            echo json_encode(['monthly'=>$m_data,'priority'=>$p_data,'status'=>$s_data]);
            break;
    }

    $conn->close();
    exit;
}

// ── POST HANDLER (MUTATIONS) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ── ASSIGN TECHNICIAN TO FAULT ────────────────────
        case 'assign_fault':
            $fault_id = (int)$_POST['fault_id'];
            $tech_ids = array_map('intval', (array)($_POST['tech_ids'] ?? []));
            $due_date = $conn->real_escape_string($_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days')));

            if (empty($tech_ids)) { echo json_encode(['ok'=>false,'msg'=>'Select at least one technician']); exit; }

            // Check if assignment already exists
            $existing = $conn->query("SELECT ASSIGN_ID FROM assignment WHERE REP_FAULT_ID=$fault_id")->fetch_assoc();

            $conn->begin_transaction();
            try {
                if ($existing) {
                    $assign_id = $existing['ASSIGN_ID'];
                    $conn->query("UPDATE assignment SET STATUS='Assigned', DUE_DATE='$due_date' WHERE ASSIGN_ID=$assign_id");
                    $conn->query("DELETE FROM assignment_technician WHERE ASSIGN_ID=$assign_id");
                } else {
                    $today = date('Y-m-d');
                    $conn->query("INSERT INTO assignment (REP_FAULT_ID,ASSIGN_DATE,DUE_DATE,STATUS) VALUES ($fault_id,'$today','$due_date','Assigned')");
                    $assign_id = $conn->insert_id;
                }

                // Add technicians
                foreach ($tech_ids as $tid) {
                    $conn->query("INSERT INTO assignment_technician (ASSIGN_ID,EMP_ID,ROLE_IN_JOB) VALUES ($assign_id,$tid,'Technician')");
                }

                // Update fault status → Assigned
                $conn->query("UPDATE reported_fault SET STATUS='Assigned' WHERE REP_FAULT_ID=$fault_id");

                $conn->commit();
                echo json_encode(['ok'=>true,'msg'=>'Fault assigned successfully']);
            } catch(Exception $e) {
                $conn->rollback();
                echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
            }
            break;

        // ── UPDATE FAULT STATUS ───────────────────────────
        case 'update_status':
            $fault_id = (int)$_POST['fault_id'];
            $new_status = $conn->real_escape_string($_POST['status']);
            $comment = $conn->real_escape_string($_POST['comment'] ?? '');

            $allowed = ['Pending','Assigned','In Progress','Completed','Verified','Rejected'];
            if (!in_array($new_status, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }

            $conn->query("UPDATE reported_fault SET STATUS='$new_status' WHERE REP_FAULT_ID=$fault_id");

            // Also sync assignment status
            $assign = $conn->query("SELECT ASSIGN_ID FROM assignment WHERE REP_FAULT_ID=$fault_id")->fetch_assoc();
            if ($assign) {
                $conn->query("UPDATE assignment SET STATUS='$new_status' WHERE ASSIGN_ID=".(int)$assign['ASSIGN_ID']);
            }

            echo json_encode(['ok'=>true,'msg'=>'Status updated to '.$new_status]);
            break;

        // ── UPDATE PRIORITY ───────────────────────────────
        case 'update_priority':
            $fault_id = (int)$_POST['fault_id'];
            $priority = $conn->real_escape_string($_POST['priority']);
            $allowed = ['Low','Medium','High','Critical'];
            if (!in_array($priority, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'Invalid priority']); exit; }
            $conn->query("UPDATE reported_fault SET PRIORITY='$priority' WHERE REP_FAULT_ID=$fault_id");
            echo json_encode(['ok'=>true,'msg'=>'Priority updated']);
            break;

        // ── ADD TECHNICIAN ────────────────────────────────
        case 'add_technician':
            $name = $conn->real_escape_string($_POST['full_name'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $username = $conn->real_escape_string($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $hire_date = $conn->real_escape_string($_POST['hire_date'] ?? date('Y-m-d'));
            $rate = (float)($_POST['hourly_rate'] ?? 0);

            if (!$name || !$email || !$username || !$password) { echo json_encode(['ok'=>false,'msg'=>'All fields required']); exit; }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $result = $conn->query("INSERT INTO employee (FULL_NAME,EMAIL,ROLE,HIRE_DATE,HOURLY_RATE,USERNAME,PASSWORD_HASH) VALUES ('$name','$email','Technician','$hire_date',$rate,'$username','$hash')");
            if ($result) echo json_encode(['ok'=>true,'msg'=>'Technician added','id'=>$conn->insert_id]);
            else echo json_encode(['ok'=>false,'msg'=>$conn->error]);
            break;

        // ── ADD CLIENT ────────────────────────────────────
        case 'add_client':
            $company = $conn->real_escape_string($_POST['company_name'] ?? '');
            $phone = $conn->real_escape_string($_POST['phone'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $address = $conn->real_escape_string($_POST['address'] ?? '');
            $contact = $conn->real_escape_string($_POST['contact_person'] ?? '');
            $type = $conn->real_escape_string($_POST['client_type'] ?? 'CORPORATE');
            $username = $conn->real_escape_string($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!$company || !$email || !$username || !$password) { echo json_encode(['ok'=>false,'msg'=>'Required fields missing']); exit; }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $result = $conn->query("INSERT INTO client (COMPANY_NAME,COMPANY_PHONE,COMPANY_EMAIL,COMPANY_ADDRESS,CONTACT_PERSON_NAME,CLIENT_TYPE,USERNAME,PASSWORD_HASH) VALUES ('$company','$phone','$email','$address','$contact','$type','$username','$hash')");
            if ($result) echo json_encode(['ok'=>true,'msg'=>'Client added','id'=>$conn->insert_id]);
            else echo json_encode(['ok'=>false,'msg'=>$conn->error]);
            break;

        // ── ADD ACCOUNT (ACCOUNTANT) ──────────────────────
        case 'add_account':
            $name = $conn->real_escape_string($_POST['full_name'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $role = $conn->real_escape_string($_POST['role'] ?? 'Accountant');
            $username = $conn->real_escape_string($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!$name || !$email || !$username || !$password) { echo json_encode(['ok'=>false,'msg'=>'All fields required']); exit; }
            if (!in_array($role, ['Technician','Admin','Accountant'])) { echo json_encode(['ok'=>false,'msg'=>'Invalid role']); exit; }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $result = $conn->query("INSERT INTO employee (FULL_NAME,EMAIL,ROLE,HIRE_DATE,USERNAME,PASSWORD_HASH) VALUES ('$name','$email','$role','".date('Y-m-d')."','$username','$hash')");
            if ($result) echo json_encode(['ok'=>true,'msg'=>'Account created']);
            else echo json_encode(['ok'=>false,'msg'=>$conn->error]);
            break;

        // ── DELETE EMPLOYEE ───────────────────────────────
        case 'delete_employee':
            $id = (int)$_POST['emp_id'];
            $conn->query("DELETE FROM employee WHERE EMP_ID=$id AND ROLE!='Admin'");
            echo json_encode(['ok'=>true,'msg'=>'Account removed']);
            break;
    }

    $conn->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — BUSIQUIP Fault Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════
   BUSIQUIP ADMIN — Unified Design System (Client Portal)
═══════════════════════════════════════════════════════ */
:root{
    --burg:#8B0000; --burg2:#C0392B; --burg-g:rgba(139,0,0,.3);
    --gold:#E8B84B; --gold2:#FFD700; --gold-p:rgba(232,184,75,.12);
    --teal:#0D9488; --sky:#0EA5E9; --em:#10B981;
    --warn:#F59E0B; --dan:#EF4444; --ind:#6366F1;
    --bg0:#070C14; --bg1:#0D1421; --bg2:#111B2E; --bg3:#1A2640; --bg4:#243055;
    --sur:rgba(17,27,46,.95); --gl:rgba(255,255,255,.04); --glb:rgba(255,255,255,.07);
    --bor:rgba(232,184,75,.16); --borh:rgba(232,184,75,.4);
    --t1:#EFF4FF; --t2:#8A9CC4; --t3:#445570;
    --r:14px; --rl:22px; --rx:32px;
    --sh:0 8px 32px rgba(0,0,0,.5); --shl:0 20px 60px rgba(0,0,0,.55);
    --blur:blur(18px); --tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif; --fb:'DM Sans',sans-serif; --fm:'JetBrains Mono',monospace;
    --sw:260px; --hh:82px;

    /* legacy aliases so existing JS colour refs still work */
    --bg-base:var(--bg0); --bg-panel:var(--bg1); --bg-card:var(--sur);
    --bg-card2:var(--bg3); --bg-hover:var(--bg4);
    --border:var(--bor); --border-focus:var(--borh);
    --orange:var(--gold); --orange-dim:var(--gold-p); --orange-glow:rgba(232,184,75,.08);
    --red:var(--dan); --red-dim:rgba(239,68,68,.12);
    --green:var(--em); --green-dim:rgba(16,185,129,.12);
    --yellow:var(--warn); --yellow-dim:rgba(245,158,11,.12);
    --blue:var(--sky); --blue-dim:rgba(14,165,233,.12);
    --purple:var(--ind); --purple-dim:rgba(99,102,241,.12);
    --teal-dim:rgba(13,148,136,.12);
    --text-primary:var(--t1); --text-sec:var(--t2); --text-muted:var(--t3);
    --sidebar-w:var(--sw); --topbar-h:var(--hh); --radius:var(--r); --radius-sm:8px;
    --font-head:var(--fh); --font-mono:var(--fm); --font-body:var(--fb);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;font-size:15px}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}

/* ── ANIMATED BACKGROUND ─────────────────────────────── */
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);
    background-size:50px 50px}
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0}
.o1{width:520px;height:520px;background:radial-gradient(circle,rgba(139,0,0,.18),transparent 70%);top:-180px;left:-140px}
.o2{width:400px;height:400px;background:radial-gradient(circle,rgba(232,184,75,.1),transparent 70%);bottom:-100px;right:-120px}
.o3{width:300px;height:300px;background:radial-gradient(circle,rgba(13,148,136,.08),transparent 70%);top:40%;left:50%}

/* ══════════════════════════════
   TOPBAR / HEADER
══════════════════════════════ */
#topbar {
    position:fixed;top:0;left:0;right:0;z-index:1100;
    height:var(--hh);
    background:rgba(13,20,33,.96);
    border-bottom:1px solid var(--bor);
    backdrop-filter:var(--blur);
    display:flex;align-items:center;padding:0 24px;gap:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.4);
}
.topbar-logo {
    display:flex;align-items:center;gap:12px;flex-shrink:0;
}
.topbar-logo-icon {
    width:44px;height:44px;border-radius:10px;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:22px;
    box-shadow:0 0 18px var(--burg-g);
    animation:spin-s 14s linear infinite;
}
@keyframes spin-s{to{transform:rotate(360deg)}}
.topbar-logo-text{font-family:var(--fh);font-size:22px;font-weight:800;
    background:linear-gradient(135deg,var(--gold2),var(--burg2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.topbar-logo-sub{font-size:10px;color:var(--t3);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm);margin-top:-2px}

/* logo image in topbar */
.topbar-logo-img{
    height:68px;width:auto;max-width:180px;object-fit:contain;
    background:#fff;padding:5px 10px;border-radius:9px;
    box-shadow:0 4px 18px rgba(232,184,75,.35),0 2px 8px rgba(0,0,0,.35);
    flex-shrink:0;margin-right:4px;
    transition:var(--tr);
}
.topbar-logo-img:hover{transform:scale(1.04);box-shadow:0 8px 28px rgba(232,184,75,.5)}

.topbar-breadcrumb {
    display:flex;align-items:center;gap:6px;
    color:var(--t2);font-size:13px;font-family:var(--fm);flex:1;
}
.topbar-breadcrumb span{color:var(--gold);font-weight:600}

.topbar-pills{display:flex;align-items:center;gap:8px}
.pill{
    display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;
    font-size:11px;font-family:var(--fm);
    background:var(--glb);border:1px solid var(--bor);color:var(--t2);
}
.pill .dot{width:7px;height:7px;border-radius:50%}
.pill.live .dot{background:var(--em);box-shadow:0 0 7px var(--em);animation:pulse 2s infinite}

#clock{font-family:var(--fm);font-size:12px;color:var(--t2);
    padding:5px 13px;border:1px solid var(--bor);border-radius:99px;background:var(--gl)}

.topbar-user{
    display:flex;align-items:center;gap:9px;padding:6px 12px;border-radius:99px;
    background:var(--glb);border:1px solid var(--bor);cursor:pointer;transition:var(--tr);
}
.topbar-user:hover{border-color:var(--borh)}
.avatar{
    width:32px;height:32px;border-radius:50%;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;
    font-family:var(--fh);font-weight:800;font-size:14px;color:#fff;
    border:2px solid var(--gold);
}
.topbar-user-name{font-size:13px;font-weight:600;color:var(--t1)}
.topbar-user-role{font-size:10px;color:var(--gold);letter-spacing:.08em;text-transform:uppercase;font-family:var(--fm)}

#notif-btn{
    position:relative;width:38px;height:38px;border-radius:50%;
    background:var(--gl);border:1px solid var(--bor);
    display:flex;align-items:center;justify-content:center;cursor:pointer;
    color:var(--t2);font-size:16px;transition:var(--tr);
}
#notif-btn:hover{border-color:var(--borh);color:var(--gold);background:var(--gold-p)}
.notif-badge{
    position:absolute;top:-3px;right:-3px;
    width:10px;height:10px;border-radius:50%;
    background:var(--dan);border:2px solid var(--bg0);
}

/* ══════════════════════════════
   SIDEBAR
══════════════════════════════ */
#sidebar{
    position:fixed;top:var(--hh);left:0;bottom:0;
    width:var(--sw);z-index:1200;
    background:rgba(11,18,33,.97);
    backdrop-filter:var(--blur);
    border-right:1px solid var(--bor);
    overflow-y:auto;overflow-x:hidden;
    padding:20px 0 80px;
}
.nav-group-label{
    padding:12px 20px 5px;font-size:11px;letter-spacing:.16em;
    text-transform:uppercase;color:var(--t3);font-family:var(--fm);font-weight:600;display:block;
}
.nav-item{
    display:flex;align-items:center;gap:11px;padding:11px 18px;margin:2px 8px;
    border-radius:10px;color:var(--t2);font-size:15px;font-weight:500;
    cursor:pointer;transition:var(--tr);border:1px solid transparent;position:relative;
}
.nav-item:hover,.nav-item.active{
    background:var(--gold-p);color:var(--gold);border-color:var(--bor);
    transform:translateX(3px);
}
.nav-item .nav-icon{
    width:30px;height:30px;border-radius:8px;background:var(--gl);
    display:flex;align-items:center;justify-content:center;font-size:15px;
    transition:var(--tr);flex-shrink:0;
}
.nav-item:hover .nav-icon,.nav-item.active .nav-icon{
    background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;
    box-shadow:0 4px 10px var(--burg-g);
}
.nav-badge{
    margin-left:auto;background:var(--dan);color:#fff;
    font-size:11px;padding:2px 7px;border-radius:99px;font-weight:700;
    font-family:var(--fm);
}
.nav-badge.tl{background:var(--teal)}
.nav-badge.gold{background:var(--burg)}
.nav-divider{height:1px;background:var(--bor);margin:10px 18px}

/* ══════════════════════════════
   MAIN CONTENT
══════════════════════════════ */
#main{
    margin-left:var(--sw);
    margin-top:var(--hh);
    padding:28px 28px 60px;
    min-height:calc(100vh - var(--hh));
    position:relative;z-index:1;
}
@media(max-width:1024px){
    #main{margin-left:0;padding:20px 14px 60px}
    #sidebar{transform:translateX(-100%);transition:transform .35s ease}
    #sidebar.open{transform:translateX(0)}
}
@media(max-width:768px){
    .stats-grid{grid-template-columns:repeat(2,1fr)}
    .grid-2,.grid-3{grid-template-columns:1fr}
    .topbar-logo-img{display:none}
}

.page{display:none;animation:pageIn .3s ease}
.page.active{display:block}
@keyframes pageIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══════════════════════════════
   PAGE HEADER
══════════════════════════════ */
.page-header{
    display:flex;align-items:flex-start;justify-content:space-between;
    margin-bottom:28px;flex-wrap:wrap;gap:14px;
}
.page-title{
    font-family:var(--fh);font-size:28px;font-weight:800;
    background:linear-gradient(135deg,var(--t1),var(--gold));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    letter-spacing:.02em;
}
.page-subtitle{color:var(--t3);font-size:13px;margin-top:4px;font-family:var(--fm)}
.page-actions{display:flex;gap:10px;align-items:center}

/* ══════════════════════════════
   STAT CARDS
══════════════════════════════ */
.stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(165px,1fr));
    gap:14px;margin-bottom:28px;
}
.stat-card{
    background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);
    padding:20px 18px;cursor:default;backdrop-filter:var(--blur);
    transition:var(--tr);position:relative;overflow:hidden;
    animation:scaleIn .4s ease both;
}
@keyframes scaleIn{from{opacity:0;transform:scale(.93)}to{opacity:1;transform:scale(1)}}
.stat-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,var(--burg),var(--accent-color,var(--gold)));
}
.stat-card::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(135deg,var(--burg-g),transparent);
    opacity:0;transition:opacity .3s;
}
.stat-card:hover{transform:translateY(-5px);border-color:var(--borh);box-shadow:var(--sh)}
.stat-card:hover::after{opacity:1}
.stat-label{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--t3);font-family:var(--fm)}
.stat-value{
    font-family:var(--fh);font-size:36px;font-weight:800;margin:8px 0 4px;
    color:var(--accent-color,var(--gold));line-height:1;
}
.stat-sub{font-size:12px;color:var(--t2)}
.stat-icon{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:34px;opacity:.06}

/* ══════════════════════════════
   CARDS & PANELS
══════════════════════════════ */
.card{
    background:var(--sur);border:1px solid var(--bor);
    border-radius:var(--rl);overflow:hidden;backdrop-filter:var(--blur);
}
.card-header{
    padding:16px 20px;border-bottom:1px solid var(--bor);
    display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.card-title{font-family:var(--fh);font-size:16px;font-weight:700;
    display:flex;align-items:center;gap:8px;color:var(--t1)}
.card-title::before{content:'';width:8px;height:8px;border-radius:50%;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    box-shadow:0 0 7px var(--burg-g);flex-shrink:0}
.card-body{padding:18px}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}}

/* ══════════════════════════════
   TABLES
══════════════════════════════ */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px}
thead th{
    padding:11px 14px;text-align:left;
    font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;
    color:var(--t3);border-bottom:1px solid var(--bor);white-space:nowrap;
    font-family:var(--fm);
}
tbody tr{border-bottom:1px solid rgba(255,255,255,.03);transition:background .15s}
tbody tr:hover{background:var(--gl)}
tbody td{padding:12px 14px;color:var(--t1);font-size:14px}
.td-mono{font-family:var(--fm);font-size:12px;color:var(--t2)}

/* ══════════════════════════════
   BADGES / STATUS
══════════════════════════════ */
.badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:99px;
    font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
    white-space:nowrap;border:1px solid currentColor;
}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}
.badge-pending   {background:rgba(245,158,11,.14);color:var(--warn);border-color:rgba(245,158,11,.3)}
.badge-assigned  {background:rgba(14,165,233,.14);color:var(--sky);border-color:rgba(14,165,233,.3)}
.badge-inprogress{background:rgba(99,102,241,.14);color:var(--ind);border-color:rgba(99,102,241,.3)}
.badge-completed {background:rgba(13,148,136,.14);color:var(--teal);border-color:rgba(13,148,136,.3)}
.badge-verified  {background:rgba(16,185,129,.14);color:var(--em);border-color:rgba(16,185,129,.3)}
.badge-rejected  {background:rgba(239,68,68,.14);color:var(--dan);border-color:rgba(239,68,68,.3)}
.badge-paid      {background:rgba(16,185,129,.14);color:var(--em);border-color:rgba(16,185,129,.3)}
.badge-unpaid    {background:rgba(239,68,68,.14);color:var(--dan);border-color:rgba(239,68,68,.3)}
.badge-partial   {background:rgba(245,158,11,.14);color:var(--warn);border-color:rgba(245,158,11,.3)}
.badge-overdue   {background:rgba(244,63,94,.18);color:#F43F5E;border-color:rgba(244,63,94,.4)}
.badge-low       {background:rgba(16,185,129,.14);color:var(--em);border-color:rgba(16,185,129,.3)}
.badge-medium    {background:rgba(14,165,233,.14);color:var(--sky);border-color:rgba(14,165,233,.3)}
.badge-high      {background:rgba(245,158,11,.14);color:var(--warn);border-color:rgba(245,158,11,.3)}
.badge-critical  {background:rgba(239,68,68,.14);color:var(--dan);border-color:rgba(239,68,68,.3)}
.badge-available {background:rgba(16,185,129,.14);color:var(--em);border-color:rgba(16,185,129,.3)}
.badge-busy      {background:rgba(232,184,75,.14);color:var(--gold);border-color:rgba(232,184,75,.3)}

/* ══════════════════════════════
   BUTTONS
══════════════════════════════ */
.btn{
    display:inline-flex;align-items:center;gap:7px;
    padding:10px 20px;border-radius:10px;
    font-size:14px;font-weight:600;font-family:var(--fb);
    cursor:pointer;border:none;transition:var(--tr);
    text-decoration:none;white-space:nowrap;flex-shrink:0;
}
.btn-primary{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 22px var(--burg-g)}
.btn-outline{background:none;border:1px solid var(--borh);color:var(--gold)}
.btn-outline:hover{background:var(--gold-p)}
.btn-danger{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.btn-danger:hover{background:var(--dan);color:#fff;transform:translateY(-1px)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-icon{padding:7px;border-radius:9px}

/* ══════════════════════════════
   FORMS
══════════════════════════════ */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--t2);margin-bottom:6px;
    letter-spacing:.05em;text-transform:uppercase}
.form-control{
    width:100%;padding:11px 14px;
    background:var(--bg3);border:1px solid var(--bor);
    border-radius:9px;color:var(--t1);
    font-size:14px;font-family:var(--fb);transition:var(--tr);outline:none;
}
.form-control:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.form-control::placeholder{color:var(--t3)}
select.form-control option{background:var(--bg2);color:var(--t1)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}

/* ══════════════════════════════
   SEARCH / FILTER BAR
══════════════════════════════ */
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap input{padding-left:36px}
.search-wrap .search-icon{
    position:absolute;left:11px;top:50%;transform:translateY(-50%);
    color:var(--t3);font-size:15px;
}
.filter-bar select{min-width:130px}

/* ══════════════════════════════
   MODAL
══════════════════════════════ */
.modal-overlay{
    position:fixed;inset:0;z-index:3000;
    background:rgba(0,0,0,.75);backdrop-filter:blur(5px);
    display:none;align-items:center;justify-content:center;padding:18px;
}
.modal-overlay.open{display:flex;animation:fadeInOv .2s ease}
@keyframes fadeInOv{from{opacity:0}to{opacity:1}}
.modal{
    background:var(--bg2);border:1px solid var(--borh);border-radius:var(--rx);
    width:100%;max-width:660px;max-height:92vh;overflow-y:auto;
    box-shadow:var(--shl);animation:modalUp .32s cubic-bezier(.34,1.56,.64,1);
}
@keyframes modalUp{from{transform:translateY(36px) scale(.96);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.modal-header{
    padding:22px 26px 18px;border-bottom:1px solid var(--bor);
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;background:var(--bg2);z-index:1;
}
.modal-title{font-family:var(--fh);font-size:20px;font-weight:800;
    display:flex;align-items:center;gap:9px;color:var(--t1)}
.modal-close{
    width:34px;height:34px;border-radius:50%;border:1px solid var(--bor);
    background:none;color:var(--t2);font-size:16px;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;transition:var(--tr);
}
.modal-close:hover{border-color:var(--gold);color:var(--gold);transform:rotate(90deg)}
.modal-body{padding:22px 26px;display:grid;gap:14px}
.modal-footer{
    padding:16px 26px 22px;border-top:1px solid var(--bor);
    display:flex;gap:11px;flex-wrap:wrap;justify-content:flex-end;
}

/* ══════════════════════════════
   FAULT DETAIL SECTIONS
══════════════════════════════ */
.detail-section{
    background:var(--bg3);border:1px solid var(--bor);
    border-radius:10px;padding:16px;margin-bottom:12px;
}
.detail-section h4{
    font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
    color:var(--gold);margin-bottom:12px;font-family:var(--fm);
    display:flex;align-items:center;gap:7px;
}
.detail-section h4::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--gold)}
.detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px}
.detail-item label{display:block;font-size:10px;color:var(--t3);margin-bottom:3px;
    text-transform:uppercase;letter-spacing:.07em;font-family:var(--fm)}
.detail-item span{font-size:14px;color:var(--t1);font-weight:600}

/* Timeline */
.timeline{position:relative;padding-left:22px}
.timeline::before{content:'';position:absolute;left:7px;top:0;bottom:0;width:1px;
    background:linear-gradient(to bottom,var(--burg),var(--gold),transparent)}
.timeline-item{position:relative;margin-bottom:14px}
.timeline-item::before{content:'';position:absolute;left:-18px;top:5px;width:9px;height:9px;
    border-radius:50%;background:var(--gold);border:2px solid var(--bg3);box-shadow:0 0 6px var(--burg-g)}
.timeline-time{font-family:var(--fm);font-size:10px;color:var(--t3)}
.timeline-text{font-size:13px;color:var(--t2);margin-top:3px}

/* ══════════════════════════════
   ACTIVITY FEED
══════════════════════════════ */
.activity-item{
    display:flex;gap:12px;align-items:flex-start;
    padding:12px 0;border-bottom:1px solid rgba(255,255,255,.04);
}
.activity-item:last-child{border-bottom:none}
.activity-icon{
    width:36px;height:36px;border-radius:10px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:15px;
}
.activity-body{flex:1}
.activity-title{font-size:13px;color:var(--t1);font-weight:500}
.activity-meta{font-size:11px;color:var(--t3);margin-top:3px;font-family:var(--fm)}

/* ══════════════════════════════
   CHARTS (CSS-based)
══════════════════════════════ */
.chart-bar-wrap{display:flex;align-items:flex-end;gap:8px;height:90px}
.chart-bar{flex:1;border-radius:5px 5px 0 0;min-height:5px;transition:height .5s ease;position:relative}
.chart-bar:hover .chart-tooltip{display:block}
.chart-tooltip{
    display:none;position:absolute;bottom:110%;left:50%;transform:translateX(-50%);
    background:var(--bg3);border:1px solid var(--bor);border-radius:8px;
    padding:5px 10px;font-size:11px;white-space:nowrap;z-index:10;color:var(--t1);
}
.chart-labels{display:flex;gap:6px;margin-top:8px}
.chart-labels span{flex:1;text-align:center;font-size:10px;color:var(--t3)}

/* Donut */
.donut-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center}
.donut-center{position:absolute;text-align:center}
.donut-center .big{font-family:var(--fh);font-size:22px;font-weight:800;color:var(--t1)}
.donut-center .small{font-size:10px;color:var(--t3)}

/* ══════════════════════════════
   PAGINATION
══════════════════════════════ */
.pagination{
    display:flex;align-items:center;gap:7px;justify-content:center;
    padding:18px 0;flex-wrap:wrap;
}
.page-btn{
    width:34px;height:34px;border-radius:9px;
    background:var(--bg3);border:1px solid var(--bor);
    color:var(--t2);font-size:13px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;transition:var(--tr);
}
.page-btn:hover,.page-btn.active{
    background:linear-gradient(135deg,var(--burg),var(--gold));
    color:#fff;border-color:var(--borh);box-shadow:0 4px 12px var(--burg-g);
}
.page-btn:disabled{opacity:.3;cursor:not-allowed}

/* ══════════════════════════════
   TECHNICIAN CARD GRID
══════════════════════════════ */
.tech-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
.tech-card{
    background:var(--sur);border:1px solid var(--bor);
    border-radius:var(--rl);padding:20px;
    transition:var(--tr);backdrop-filter:var(--blur);
}
.tech-card:hover{border-color:var(--borh);transform:translateY(-4px);box-shadow:var(--sh)}
.tech-avatar{
    width:48px;height:48px;border-radius:12px;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:20px;
    color:#fff;font-weight:800;font-family:var(--fh);
    margin-bottom:12px;box-shadow:0 4px 12px var(--burg-g);
}
.tech-name{font-weight:700;font-size:15px;color:var(--t1)}
.tech-email{font-size:12px;color:var(--t3);font-family:var(--fm)}
.tech-stats{display:flex;gap:10px;margin-top:12px}
.tech-stat{flex:1;text-align:center;background:var(--bg3);border-radius:9px;padding:8px;border:1px solid var(--bor)}
.tech-stat .num{font-family:var(--fh);font-size:20px;font-weight:800;color:var(--gold)}
.tech-stat .lbl{font-size:10px;color:var(--t3);font-family:var(--fm)}

/* ══════════════════════════════
   TOAST NOTIFICATIONS
══════════════════════════════ */
#toast-container{
    position:fixed;bottom:22px;right:20px;
    z-index:9999;display:flex;flex-direction:column;gap:9px;
}
.toast{
    display:flex;align-items:center;gap:10px;
    padding:13px 18px;border-radius:var(--r);
    border:1px solid var(--bor);backdrop-filter:var(--blur);
    background:var(--bg2);min-width:270px;
    animation:toastIn .3s ease,toastOut .4s ease 3.6s forwards;
    box-shadow:var(--sh);font-size:14px;
}
.toast.success{border-left:3px solid var(--em);border-color:var(--em)}
.toast.error  {border-left:3px solid var(--dan);border-color:var(--dan)}
.toast.info   {border-left:3px solid var(--sky);border-color:var(--sky)}
.toast-icon{font-size:18px}
.toast-msg{font-size:14px;color:var(--t1)}
@keyframes toastIn{from{transform:translateX(110px);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateX(40px)}}

/* ══════════════════════════════
   LOADING SPINNER
══════════════════════════════ */
.spinner{
    width:34px;height:34px;border-radius:50%;
    border:3px solid var(--bor);border-top-color:var(--gold);
    animation:spin .8s linear infinite;margin:40px auto;
}
.loading-wrap{display:flex;flex-direction:column;align-items:center;gap:9px;
    padding:40px;color:var(--t3);font-size:13px}

/* ══════════════════════════════
   CONFIRM DIALOG
══════════════════════════════ */
#confirm-overlay{
    position:fixed;inset:0;z-index:4000;
    background:rgba(0,0,0,.8);backdrop-filter:blur(6px);
    display:none;align-items:center;justify-content:center;
}
#confirm-overlay.open{display:flex}
#confirm-box{
    background:var(--bg2);border:1px solid var(--borh);
    border-radius:var(--rx);padding:28px;max-width:380px;
    width:100%;text-align:center;box-shadow:var(--shl);
    animation:modalUp .3s cubic-bezier(.34,1.56,.64,1);
}
#confirm-box h3{font-family:var(--fh);font-size:22px;font-weight:800;margin-bottom:10px;color:var(--t1)}
#confirm-box p{font-size:14px;color:var(--t2);margin-bottom:22px;line-height:1.6}
#confirm-box .actions{display:flex;gap:10px;justify-content:center}

/* ══════════════════════════════
   EMPTY STATE
══════════════════════════════ */
.empty{
    display:flex;flex-direction:column;align-items:center;
    padding:50px 20px;color:var(--t3);
}
.empty-icon{font-size:50px;margin-bottom:14px;opacity:.35}
.empty h4{font-size:16px;color:var(--t2);margin-bottom:5px;font-family:var(--fh);font-weight:700}
.empty p{font-size:13px}

/* ══════════════════════════════
   MISC
══════════════════════════════ */
.separator{height:1px;background:var(--bor);margin:18px 0}
.text-orange{color:var(--gold)}
.text-muted{color:var(--t3)}
.font-mono{font-family:var(--fm)}
.truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px}

/* Checkboxes for multi-select technicians */
.check-list{display:flex;flex-direction:column;gap:7px;max-height:210px;overflow-y:auto}
.check-item{
    display:flex;align-items:center;gap:9px;
    padding:9px 12px;border-radius:9px;cursor:pointer;
    border:1px solid var(--bor);background:var(--bg3);
    transition:var(--tr);
}
.check-item:hover{border-color:var(--borh);background:var(--gold-p)}
.check-item input[type=checkbox]{accent-color:var(--gold);width:16px;height:16px}

/* ══════════════════════════════
   INFO BOXES
══════════════════════════════ */
.ib{padding:12px 14px;border-radius:9px;display:flex;gap:10px;align-items:flex-start;font-size:14px}
.ib i{font-size:18px;flex-shrink:0;margin-top:1px}
.ib-g{background:rgba(232,184,75,.09);border-left:3px solid var(--gold);color:var(--t1)}
.ib-t{background:rgba(13,148,136,.09);border-left:3px solid var(--teal);color:var(--t1)}
.ib-e{background:rgba(16,185,129,.09);border-left:3px solid var(--em);color:var(--t1)}
.ib-r{background:rgba(239,68,68,.09);border-left:3px solid var(--dan);color:var(--t1)}

</style>
</head>
<body>

<!-- ANIMATED BACKGROUND -->
<div class="bg-grid"></div>
<div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>

<!-- ═══════ TOPBAR ═══════ -->
<header id="topbar">
  <img src="../../images/logo.png" alt="Busiquip Logo" class="topbar-logo-img">
  <div class="topbar-logo">
    <div class="topbar-logo-icon"><i class="fas fa-cog"></i></div>
    <div>
      <div class="topbar-logo-text">BUSIQUIP</div>
      <div class="topbar-logo-sub">Admin Portal</div>
    </div>
  </div>
  </div>

  <div class="topbar-breadcrumb">
    <i class="fas fa-home" style="color:var(--t3)"></i>&nbsp;Admin&nbsp;›&nbsp;<span id="breadcrumb-text">Dashboard</span>
  </div>

  <div class="topbar-pills">
    <div class="pill live"><div class="dot"></div> LIVE</div>
    <div id="clock">--:--:--</div>
  </div>

  <div id="notif-btn" onclick="showPage('notifications')" title="Notifications">
    <i class="fas fa-bell"></i><div class="notif-badge"></div>
  </div>

  <div class="topbar-user">
    <div class="avatar">A</div>
    <div>
      <div class="topbar-user-name"><?= htmlspecialchars($admin_username) ?></div>
      <div class="topbar-user-role">Administrator</div>
    </div>
  </div>

  <form method="POST" style="display:inline;margin-left:4px">
    <button type="button" onclick="if(confirm('Log out?')) window.location='../../logout.php'"
      style="width:38px;height:38px;border-radius:50%;border:1px solid rgba(239,68,68,.3);
             background:none;color:var(--dan);font-size:15px;cursor:pointer;display:flex;
             align-items:center;justify-content:center;transition:var(--tr)"
      title="Sign Out"
      onmouseover="this.style.background='rgba(239,68,68,.1)'"
      onmouseout="this.style.background='none'">
      <i class="fas fa-sign-out-alt"></i>
    </button>
  </form>
</header>

<!-- ═══════ SIDEBAR ═══════ -->
<nav id="sidebar">
  <span class="nav-group-label">Overview</span>
  <div class="nav-item active" onclick="showPage('dashboard')" data-page="dashboard">
    <div class="nav-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
  </div>

  <span class="nav-group-label" style="margin-top:8px">Operations</span>
  <div class="nav-item" onclick="showPage('faults')" data-page="faults">
    <div class="nav-icon"><i class="fas fa-exclamation-triangle"></i></div> Fault Management
    <span class="nav-badge" id="badge-faults">—</span>
  </div>
  <div class="nav-item" onclick="showPage('technicians')" data-page="technicians">
    <div class="nav-icon"><i class="fas fa-tools"></i></div> Technicians
    <span class="nav-badge tl" id="badge-techs">—</span>
  </div>
  <div class="nav-item" onclick="showPage('clients')" data-page="clients">
    <div class="nav-icon"><i class="fas fa-building"></i></div> Clients
    <span class="nav-badge tl" id="badge-clients">—</span>
  </div>

  <span class="nav-group-label" style="margin-top:8px">Finance (View)</span>
  <div class="nav-item" onclick="showPage('invoices')" data-page="invoices">
    <div class="nav-icon"><i class="fas fa-receipt"></i></div> Invoices
  </div>
  <div class="nav-item" onclick="showPage('payments')" data-page="payments">
    <div class="nav-icon"><i class="fas fa-credit-card"></i></div> Payments
  </div>

  <span class="nav-group-label" style="margin-top:8px">System</span>
  <div class="nav-item" onclick="showPage('accounts')" data-page="accounts">
    <div class="nav-icon"><i class="fas fa-user-cog"></i></div> Account Management
  </div>
  <div class="nav-item" onclick="window.open('admin_reports.php','_blank')" data-page="analytics">
    <div class="nav-icon"><i class="fas fa-chart-line"></i></div> Reports &amp; Analytics
    <i class="fas fa-external-link-alt" style="margin-left:auto;font-size:10px;color:var(--t3)"></i>
  </div>
  <div class="nav-item" onclick="showPage('notifications')" data-page="notifications">
    <div class="nav-icon"><i class="fas fa-bell"></i></div> Notifications
  </div>

  <div class="nav-divider"></div>
  <div class="nav-item" onclick="if(confirm('Log out?')) window.location='../../logout.php'"
       style="color:var(--dan)">
    <div class="nav-icon" style="color:var(--dan)"><i class="fas fa-sign-out-alt"></i></div> Log Out
  </div>
</nav>

<!-- ═══════ MAIN CONTENT ═══════ -->
<main id="main">

  <!-- ───────────── DASHBOARD ───────────── -->
  <section class="page active" id="page-dashboard">
    <div class="page-header">
      <div>
        <div class="page-title">System Dashboard</div>
        <div class="page-subtitle">Live overview of the BUSIQUIP fault management platform</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-outline btn-sm" onclick="loadDashboard()">↻ Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="showPage('faults')">+ New Fault</button>
      </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid" id="stat-cards">
      <div class="stat-card" style="--accent-color:var(--orange)">
        <div class="stat-label">Total Faults</div>
        <div class="stat-value" id="s-total-faults">—</div>
        <div class="stat-sub">All time</div>
        <div class="stat-icon">⚠</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--yellow)">
        <div class="stat-label">Pending</div>
        <div class="stat-value" id="s-pending">—</div>
        <div class="stat-sub">Awaiting assignment</div>
        <div class="stat-icon">⏳</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--blue)">
        <div class="stat-label">Assigned</div>
        <div class="stat-value" id="s-assigned">—</div>
        <div class="stat-sub">Technician allocated</div>
        <div class="stat-icon">👤</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--purple)">
        <div class="stat-label">In Progress</div>
        <div class="stat-value" id="s-inprogress">—</div>
        <div class="stat-sub">Actively being resolved</div>
        <div class="stat-icon">🔧</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--teal)">
        <div class="stat-label">Completed</div>
        <div class="stat-value" id="s-completed">—</div>
        <div class="stat-sub">Awaiting verification</div>
        <div class="stat-icon">✓</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--green)">
        <div class="stat-label">Verified</div>
        <div class="stat-value" id="s-verified">—</div>
        <div class="stat-sub">Fully resolved</div>
        <div class="stat-icon">✅</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--red)">
        <div class="stat-label">Rejected</div>
        <div class="stat-value" id="s-rejected">—</div>
        <div class="stat-sub">Not approved</div>
        <div class="stat-icon">✗</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--teal)">
        <div class="stat-label">Clients</div>
        <div class="stat-value" id="s-clients">—</div>
        <div class="stat-sub">Registered companies</div>
        <div class="stat-icon">🏢</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--blue)">
        <div class="stat-label">Technicians</div>
        <div class="stat-value" id="s-technicians">—</div>
        <div class="stat-sub">Field engineers</div>
        <div class="stat-icon">🔧</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--orange)">
        <div class="stat-label">Invoices</div>
        <div class="stat-value" id="s-invoices">—</div>
        <div class="stat-sub">Read-only view</div>
        <div class="stat-icon">🧾</div>
      </div>
      <div class="stat-card" style="--accent-color:var(--green)">
        <div class="stat-label">Revenue</div>
        <div class="stat-value" id="s-revenue" style="font-size:20px">—</div>
        <div class="stat-sub">Collected payments</div>
        <div class="stat-icon">💰</div>
      </div>
    </div>

    <!-- Bottom Grid -->
    <div class="grid-2">
      <!-- Activity Feed -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Activity</div>
          <button class="btn btn-outline btn-sm" onclick="loadActivity()">↻</button>
        </div>
        <div class="card-body" id="activity-feed">
          <div class="spinner"></div>
        </div>
      </div>

      <!-- Status Chart -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Fault Status Breakdown</div>
        </div>
        <div class="card-body">
          <div id="status-chart-wrap"></div>
          <div class="separator"></div>
          <div id="fault-legend" style="display:flex;flex-wrap:wrap;gap:10px;font-size:11px;"></div>
        </div>
      </div>
    </div>
  </section>

  <!-- ───────────── FAULT MANAGEMENT ───────────── -->
  <section class="page" id="page-faults">
    <div class="page-header">
      <div>
        <div class="page-title">Fault Management</div>
        <div class="page-subtitle">View, assign, and manage all reported equipment faults</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-outline btn-sm" onclick="loadFaults()">↻ Refresh</button>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="filter-bar" style="flex:1;margin-bottom:0">
          <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="form-control" id="fault-search" placeholder="Search faults, clients..." onkeyup="debounce(loadFaults,400)()">
          </div>
          <select class="form-control" id="fault-status-filter" onchange="loadFaults()">
            <option value="">All Statuses</option>
            <option>Pending</option><option>Assigned</option>
            <option>In Progress</option><option>Completed</option>
            <option>Verified</option><option>Rejected</option>
          </select>
          <select class="form-control" id="fault-priority-filter" onchange="loadFaults()">
            <option value="">All Priorities</option>
            <option>Low</option><option>Medium</option>
            <option>High</option><option>Critical</option>
          </select>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th>
              <th>Client</th>
              <th>Fault Type</th>
              <th>Product</th>
              <th>Reported By</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Technician(s)</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="faults-tbody">
            <tr><td colspan="10"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>

      <div id="faults-pagination" style="padding:0 16px"></div>
    </div>
  </section>

  <!-- ───────────── TECHNICIANS ───────────── -->
  <section class="page" id="page-technicians">
    <div class="page-header">
      <div>
        <div class="page-title">Technician Management</div>
        <div class="page-subtitle">Field engineers and their current workload</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('modal-add-tech')">+ Add Technician</button>
      </div>
    </div>
    <div class="tech-grid" id="tech-grid">
      <div class="loading-wrap"><div class="spinner"></div><span>Loading technicians…</span></div>
    </div>
  </section>

  <!-- ───────────── CLIENTS ───────────── -->
  <section class="page" id="page-clients">
    <div class="page-header">
      <div>
        <div class="page-title">Client Management</div>
        <div class="page-subtitle">Registered corporate and government clients</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('modal-add-client')">+ Add Client</button>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th>
              <th>Company Name</th>
              <th>Contact Person</th>
              <th>Type</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Total Faults</th>
              <th>Open Faults</th>
              <th>Invoices</th>
              <th>Invoice Total</th>
            </tr>
          </thead>
          <tbody id="clients-tbody">
            <tr><td colspan="10"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ───────────── INVOICES (READ-ONLY) ───────────── -->
  <section class="page" id="page-invoices">
    <div class="page-header">
      <div>
        <div class="page-title">Invoices <span style="font-size:14px;color:var(--text-muted);font-family:var(--font-body)">(Read-only — managed by Accountant)</span></div>
        <div class="page-subtitle">View all invoices issued by the Accountant module</div>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>#ID</th><th>Client</th><th>Invoice Date</th><th>Due Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr>
          </thead>
          <tbody id="invoices-tbody">
            <tr><td colspan="8"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ───────────── PAYMENTS (READ-ONLY) ───────────── -->
  <section class="page" id="page-payments">
    <div class="page-header">
      <div>
        <div class="page-title">Payments <span style="font-size:14px;color:var(--text-muted);font-family:var(--font-body)">(Read-only)</span></div>
        <div class="page-subtitle">Revenue summary — processed by Accountant module</div>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>#ID</th><th>Client</th><th>Invoice #</th><th>Amount Paid</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th></tr>
          </thead>
          <tbody id="payments-tbody">
            <tr><td colspan="8"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ───────────── ACCOUNT MANAGEMENT ───────────── -->
  <section class="page" id="page-accounts">
    <div class="page-header">
      <div>
        <div class="page-title">Account Management</div>
        <div class="page-subtitle">System user accounts and role permissions</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('modal-add-account')">+ Create Account</button>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>#ID</th><th>Full Name</th><th>Email</th><th>Role</th><th>Username</th><th>Hire Date</th><th>Actions</th></tr>
          </thead>
          <tbody id="accounts-tbody">
            <tr><td colspan="7"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ───────────── ANALYTICS ───────────── -->
  <section class="page" id="page-analytics">
    <div class="page-header">
      <div>
        <div class="page-title">Reports & Analytics</div>
        <div class="page-subtitle">System performance insights</div>
      </div>
      <div class="page-actions">
        <button class="btn btn-outline btn-sm" onclick="window.print()">🖨 Print Report</button>
      </div>
    </div>

    <div class="grid-2" style="margin-bottom:16px">
      <div class="card">
        <div class="card-header"><div class="card-title">Faults by Priority</div></div>
        <div class="card-body" id="priority-chart-wrap"></div>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Faults by Status</div></div>
        <div class="card-body" id="status-chart-wrap2"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Monthly Fault Trend (Last 6 Months)</div></div>
      <div class="card-body" id="monthly-chart-wrap"></div>
    </div>
  </section>

  <!-- ───────────── NOTIFICATIONS ───────────── -->
  <section class="page" id="page-notifications">
    <div class="page-header">
      <div><div class="page-title">Notifications</div></div>
    </div>
    <div class="card">
      <div class="card-body" id="notif-list">
        <div class="spinner"></div>
      </div>
    </div>
  </section>

</main><!-- #main -->

<!-- ═══════ MODALS ═══════ -->

<!-- Fault Detail / Assign Modal -->
<div class="modal-overlay" id="modal-fault-detail">
  <div class="modal" style="max-width:760px">
    <div class="modal-header">
      <div class="modal-title" id="detail-title">Fault Details</div>
      <div class="modal-close" onclick="closeModal('modal-fault-detail')">✕</div>
    </div>
    <div class="modal-body" id="modal-fault-body">
      <div class="spinner"></div>
    </div>
  </div>
</div>

<!-- Add Technician Modal -->
<div class="modal-overlay" id="modal-add-tech">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add Technician</div>
      <div class="modal-close" onclick="closeModal('modal-add-tech')">✕</div>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input id="t-name" class="form-control" placeholder="e.g. John Dlamini">
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input id="t-email" class="form-control" type="email" placeholder="john@busiquip.com">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Username *</label>
          <input id="t-username" class="form-control" placeholder="john_dlamini">
        </div>
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input id="t-password" class="form-control" type="password" placeholder="Minimum 8 chars">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Hire Date</label>
          <input id="t-hire" class="form-control" type="date" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Hourly Rate (E)</label>
          <input id="t-rate" class="form-control" type="number" step="0.01" placeholder="0.00">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-add-tech')">Cancel</button>
      <button class="btn btn-primary" onclick="addTechnician()">✓ Add Technician</button>
    </div>
  </div>
</div>

<!-- Add Client Modal -->
<div class="modal-overlay" id="modal-add-client">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add Client</div>
      <div class="modal-close" onclick="closeModal('modal-add-client')">✕</div>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Company Name *</label>
          <input id="c-company" class="form-control" placeholder="e.g. ECOT">
        </div>
        <div class="form-group">
          <label class="form-label">Client Type</label>
          <select id="c-type" class="form-control">
            <option value="CORPORATE">Corporate</option>
            <option value="GOVERNMENT">Government</option>
            <option value="SME">SME</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Company Email *</label>
          <input id="c-email" class="form-control" type="email" placeholder="info@company.com">
        </div>
        <div class="form-group">
          <label class="form-label">Company Phone</label>
          <input id="c-phone" class="form-control" placeholder="+268 7000 0000">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Address</label>
        <input id="c-address" class="form-control" placeholder="Street, City">
      </div>
      <div class="form-group">
        <label class="form-label">Contact Person</label>
        <input id="c-contact" class="form-control" placeholder="Full name of primary contact">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Username *</label>
          <input id="c-username" class="form-control" placeholder="client_username">
        </div>
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input id="c-password" class="form-control" type="password">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-add-client')">Cancel</button>
      <button class="btn btn-primary" onclick="addClient()">✓ Add Client</button>
    </div>
  </div>
</div>

<!-- Add Account Modal -->
<div class="modal-overlay" id="modal-add-account">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Create System Account</div>
      <div class="modal-close" onclick="closeModal('modal-add-account')">✕</div>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input id="a-name" class="form-control" placeholder="Full name">
        </div>
        <div class="form-group">
          <label class="form-label">Role *</label>
          <select id="a-role" class="form-control">
            <option value="Accountant">Accountant</option>
            <option value="Technician">Technician</option>
            <option value="Admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input id="a-email" class="form-control" type="email">
        </div>
        <div class="form-group">
          <label class="form-label">Username *</label>
          <input id="a-username" class="form-control">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Password *</label>
        <input id="a-password" class="form-control" type="password">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('modal-add-account')">Cancel</button>
      <button class="btn btn-primary" onclick="addAccount()">✓ Create Account</button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="toast-container"></div>

<!-- Confirm Dialog -->
<div id="confirm-overlay">
  <div id="confirm-box">
    <h3 id="confirm-title">Are you sure?</h3>
    <p id="confirm-msg">This action cannot be undone.</p>
    <div class="actions">
      <button class="btn btn-outline" onclick="confirmCancel()">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok-btn" onclick="confirmOk()">Confirm</button>
    </div>
  </div>
</div>

<!-- ═══════ JAVASCRIPT ═══════ -->
<script>
// ─── STATE ──────────────────────────────────────────────
const state = {
  currentPage: 'dashboard',
  faultPage: 1,
  faultSearch: '',
  faultStatus: '',
  faultPriority: '',
  stats: null,
  allTechs: []
};

let confirmCallback = null;
let debounceTimer = null;

// ─── UTILS ──────────────────────────────────────────────
function debounce(fn, ms) {
  return function(...args) {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fn.apply(this, args), ms);
  };
}

function api(params) {
  const url = window.location.pathname + '?' + new URLSearchParams(params).toString();
  return fetch(url).then(r => r.json());
}

function post(data) {
  return fetch(window.location.pathname, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(data).toString()
  }).then(r => r.json());
}

function toast(msg, type = 'info') {
  const icons = {success:'✅', error:'❌', info:'ℹ️'};
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span class="toast-icon">${icons[type]}</span><span class="toast-msg">${msg}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), 4200);
}

function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function confirm2(title, msg, cb) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent = msg;
  confirmCallback = cb;
  document.getElementById('confirm-overlay').classList.add('open');
}
function confirmOk() {
  document.getElementById('confirm-overlay').classList.remove('open');
  if (confirmCallback) { confirmCallback(); confirmCallback = null; }
}
function confirmCancel() {
  document.getElementById('confirm-overlay').classList.remove('open');
  confirmCallback = null;
}

// ─── STATUS BADGE ────────────────────────────────────────
function statusBadge(s) {
  if (!s) return '<span class="badge badge-pending">Unknown</span>';
  const map = {
    'Pending':'badge-pending','Assigned':'badge-assigned',
    'In Progress':'badge-inprogress','Completed':'badge-completed',
    'Verified':'badge-verified','Rejected':'badge-rejected',
    'Paid':'badge-paid','Unpaid':'badge-unpaid','Failed':'badge-rejected',
    'Low':'badge-low','Medium':'badge-medium','High':'badge-high','Critical':'badge-critical',
  };
  return `<span class="badge ${map[s]||'badge-pending'}">${s}</span>`;
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  return dt.toLocaleDateString('en-ZA', {day:'2-digit',month:'short',year:'numeric'});
}
function fmtDateTime(d) {
  if (!d) return '—';
  const dt = new Date(d);
  return dt.toLocaleString('en-ZA', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function timeAgo(d) {
  if (!d) return '';
  const diff = Date.now() - new Date(d).getTime();
  const m = Math.floor(diff/60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m/60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h/24)}d ago`;
}

// ─── CLOCK ───────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('clock').textContent =
    now.toLocaleTimeString('en-ZA', {hour12:false}) + ' · ' +
    now.toLocaleDateString('en-ZA', {day:'2-digit',month:'short'});
}
setInterval(updateClock, 1000); updateClock();

// ─── NAVIGATION ──────────────────────────────────────────
function showPage(name) {
  // Hide all
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  // Show target
  const page = document.getElementById('page-'+name);
  if (page) page.classList.add('active');

  // Activate nav
  const nav = document.querySelector(`[data-page="${name}"]`);
  if (nav) nav.classList.add('active');

  // Update breadcrumb
  const labels = {
    dashboard:'Dashboard', faults:'Fault Management', technicians:'Technicians',
    clients:'Clients', invoices:'Invoices', payments:'Payments',
    accounts:'Account Management', analytics:'Analytics', notifications:'Notifications'
  };
  document.getElementById('breadcrumb-text').textContent = labels[name] || name;

  state.currentPage = name;

  // Load page data
  const loaders = {
    dashboard: loadDashboard,
    faults: loadFaults,
    technicians: loadTechnicians,
    clients: loadClients,
    invoices: loadInvoices,
    payments: loadPayments,
    accounts: loadAccounts,
    analytics: loadAnalytics,
    notifications: loadNotifications
  };
  if (loaders[name]) loaders[name]();
}

// ─── DASHBOARD ───────────────────────────────────────────
function loadDashboard() {
  api({ajax:'stats'}).then(s => {
    state.stats = s;
    // Update stat cards
    const f = s.faults || {};
    document.getElementById('s-total-faults').textContent = s.total_faults || 0;
    document.getElementById('s-pending').textContent = f.Pending || 0;
    document.getElementById('s-assigned').textContent = f.Assigned || 0;
    document.getElementById('s-inprogress').textContent = f['In Progress'] || 0;
    document.getElementById('s-completed').textContent = f.Completed || 0;
    document.getElementById('s-verified').textContent = f.Verified || 0;
    document.getElementById('s-rejected').textContent = f.Rejected || 0;
    document.getElementById('s-clients').textContent = s.clients || 0;
    document.getElementById('s-technicians').textContent = s.technicians || 0;
    document.getElementById('s-invoices').textContent = s.invoices || 0;
    document.getElementById('s-revenue').textContent = 'E' + (s.revenue || '0.00');

    // Update nav badges
    document.getElementById('badge-faults').textContent = s.total_faults || 0;
    document.getElementById('badge-techs').textContent = s.technicians || 0;
    document.getElementById('badge-clients').textContent = s.clients || 0;

    renderStatusChart(f, 'status-chart-wrap');
  });
  loadActivity();
}

function renderStatusChart(f, containerId) {
  const items = [
    {label:'Pending', val: f.Pending||0, color:'var(--yellow)'},
    {label:'Assigned', val: f.Assigned||0, color:'var(--blue)'},
    {label:'In Progress', val: f['In Progress']||0, color:'var(--purple)'},
    {label:'Completed', val: f.Completed||0, color:'var(--teal)'},
    {label:'Verified', val: f.Verified||0, color:'var(--green)'},
    {label:'Rejected', val: f.Rejected||0, color:'var(--red)'},
  ];
  const max = Math.max(...items.map(i=>i.val), 1);
  const bars = items.map(i => `
    <div class="chart-bar" style="background:${i.color};height:${Math.max(4, (i.val/max)*80)}px;opacity:0.85">
      <div class="chart-tooltip">${i.label}: ${i.val}</div>
    </div>`).join('');
  const labels = items.map(i => `<span style="color:${i.color}">${i.label}<br><b>${i.val}</b></span>`).join('');

  const wrap = document.getElementById(containerId);
  if (!wrap) return;
  wrap.innerHTML = `<div class="chart-bar-wrap">${bars}</div><div class="chart-labels">${labels}</div>`;

  // Legend for dashboard
  const leg = document.getElementById('fault-legend');
  if (leg) {
    leg.innerHTML = items.map(i =>
      `<span style="display:flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:${i.color};display:inline-block"></span>${i.label}: <b>${i.val}</b></span>`
    ).join('');
  }
}

function loadActivity() {
  api({ajax:'activity'}).then(items => {
    const feed = document.getElementById('activity-feed');
    if (!feed) return;
    if (!items.length) {
      feed.innerHTML = '<div class="empty"><div class="empty-icon">📋</div><h4>No activity yet</h4><p>Actions will appear here</p></div>';
      return;
    }
    const typeMap = {
      'fault_reported':  {icon:'⚠️', bg:'var(--yellow-dim)', color:'var(--yellow)'},
      'fault_assigned':  {icon:'👤', bg:'var(--blue-dim)',   color:'var(--blue)'},
    };
    feed.innerHTML = items.map(i => {
      const t = typeMap[i.type] || {icon:'ℹ️', bg:'var(--bg-card2)', color:'var(--text-sec)'};
      return `<div class="activity-item">
        <div class="activity-icon" style="background:${t.bg};color:${t.color}">${t.icon}</div>
        <div class="activity-body">
          <div class="activity-title">${i.detail||'—'}</div>
          <div class="activity-meta">${i.actor||'System'} · ${timeAgo(i.ts)} · ${statusBadge(i.status)}</div>
        </div>
      </div>`;
    }).join('');
  });
}

// ─── FAULTS ──────────────────────────────────────────────
let faultCurrentPage = 1;

function loadFaults(page) {
  page = page || faultCurrentPage;
  faultCurrentPage = page;
  const search = document.getElementById('fault-search')?.value || '';
  const status = document.getElementById('fault-status-filter')?.value || '';
  const priority = document.getElementById('fault-priority-filter')?.value || '';

  document.getElementById('faults-tbody').innerHTML = '<tr><td colspan="10"><div class="loading-wrap"><div class="spinner"></div><span>Loading…</span></div></td></tr>';

  api({ajax:'faults', page, search, status, priority}).then(data => {
    const tbody = document.getElementById('faults-tbody');
    if (!data.faults || !data.faults.length) {
      tbody.innerHTML = '<tr><td colspan="10"><div class="empty"><div class="empty-icon">📋</div><h4>No faults found</h4><p>Try adjusting your filters</p></div></td></tr>';
      document.getElementById('faults-pagination').innerHTML = '';
      return;
    }

    tbody.innerHTML = data.faults.map(f => `
      <tr>
        <td class="td-mono">#${f.REP_FAULT_ID}</td>
        <td><b>${f.COMPANY_NAME||'—'}</b><br><span class="text-muted" style="font-size:10px">${f.CLIENT_TYPE||''}</span></td>
        <td>${f.FAULT_TYPE||'—'}</td>
        <td class="truncate">${f.PROD_NAME||'—'}</td>
        <td>${f.REPORTED_BY||'—'}</td>
        <td>${statusBadge(f.PRIORITY)}</td>
        <td>${statusBadge(f.STATUS)}</td>
        <td class="truncate" style="max-width:140px">${f.TECHNICIANS||'<span class="text-muted">Unassigned</span>'}</td>
        <td class="td-mono" style="font-size:11px">${fmtDate(f.REPORT_DATE)}</td>
        <td>
          <div style="display:flex;gap:4px">
            <button class="btn btn-outline btn-sm btn-icon" title="View Details" onclick="openFaultDetail(${f.REP_FAULT_ID})">👁</button>
            <button class="btn btn-primary btn-sm btn-icon" title="Assign Technician" onclick="openAssignModal(${f.REP_FAULT_ID},'${(f.COMPANY_NAME||'').replace(/'/g,"\\'")}')">👤</button>
          </div>
        </td>
      </tr>
    `).join('');

    // Pagination
    const pag = document.getElementById('faults-pagination');
    if (data.pages > 1) {
      let btns = `<div class="pagination">`;
      btns += `<div class="page-btn" onclick="loadFaults(${Math.max(1,page-1)})">‹</div>`;
      for (let i = 1; i <= data.pages; i++) {
        btns += `<div class="page-btn ${i==page?'active':''}" onclick="loadFaults(${i})">${i}</div>`;
      }
      btns += `<div class="page-btn" onclick="loadFaults(${Math.min(data.pages,page+1)})">›</div>`;
      btns += `</div><div style="text-align:center;font-size:11px;color:var(--text-muted);padding-bottom:12px">Showing page ${page} of ${data.pages} — ${data.total} total faults</div>`;
      pag.innerHTML = btns;
    } else {
      pag.innerHTML = `<div style="text-align:center;font-size:11px;color:var(--text-muted);padding:12px">${data.total} fault${data.total!=1?'s':''} found</div>`;
    }
  });
}

function openFaultDetail(faultId) {
  document.getElementById('detail-title').textContent = `Fault #${faultId} — Details`;
  document.getElementById('modal-fault-body').innerHTML = '<div class="spinner"></div>';
  openModal('modal-fault-detail');

  api({ajax:'fault_detail', id:faultId}).then(data => {
    const f = data.fault;
    const techs = data.technicians || [];
    const logs = data.logs || [];

    if (!f) {
      document.getElementById('modal-fault-body').innerHTML = '<div class="empty"><h4>Fault not found</h4></div>';
      return;
    }

    const techList = techs.length
      ? techs.map(t => `<div style="display:flex;align-items:center;gap:8px;padding:6px 0">
          <div class="avatar" style="width:28px;height:28px;font-size:11px;background:var(--blue)">${t.FULL_NAME.charAt(0)}</div>
          <div><div style="font-size:12px;font-weight:600">${t.FULL_NAME}</div><div style="font-size:10px;color:var(--text-muted)">${t.EMAIL}</div></div>
          <div style="margin-left:auto">${statusBadge(t.ROLE_IN_JOB||'Technician')}</div>
        </div>`).join('')
      : '<div class="text-muted" style="font-size:12px">No technician assigned yet</div>';

    const logList = logs.length
      ? `<div class="timeline">${logs.map(l => `
          <div class="timeline-item">
            <div class="timeline-time">${fmtDateTime(l.LOG_DATE)}</div>
            <div class="timeline-text"><b>${l.FULL_NAME||'Technician'}</b>: ${l.NOTES||'Updated status'}</div>
          </div>`).join('')}</div>`
      : '<div class="text-muted" style="font-size:12px">No work logs yet</div>';

    document.getElementById('modal-fault-body').innerHTML = `
      <div class="detail-section">
        <h4>Fault Information</h4>
        <div class="detail-grid">
          <div class="detail-item"><label>Fault ID</label><span class="font-mono">#${f.REP_FAULT_ID}</span></div>
          <div class="detail-item"><label>Type</label><span>${f.FAULT_TYPE||'—'}</span></div>
          <div class="detail-item"><label>Status</label><span>${statusBadge(f.STATUS)}</span></div>
          <div class="detail-item"><label>Priority</label><span>${statusBadge(f.PRIORITY)}</span></div>
          <div class="detail-item"><label>Reported</label><span>${fmtDateTime(f.REPORT_DATE)}</span></div>
          <div class="detail-item"><label>Reported By</label><span>${f.REPORTED_BY||'—'}</span></div>
        </div>
        <div style="margin-top:10px">
          <label class="form-label">Description</label>
          <div style="font-size:13px;color:var(--text-sec);line-height:1.5;background:var(--bg-base);padding:10px;border-radius:6px">${f.DESCRIPTION||'No description provided'}</div>
        </div>
      </div>

      <div class="detail-section">
        <h4>Client & Product</h4>
        <div class="detail-grid">
          <div class="detail-item"><label>Company</label><span>${f.COMPANY_NAME||'—'}</span></div>
          <div class="detail-item"><label>Email</label><span>${f.COMPANY_EMAIL||'—'}</span></div>
          <div class="detail-item"><label>Phone</label><span>${f.COMPANY_PHONE||'—'}</span></div>
          <div class="detail-item"><label>Contact</label><span>${f.CONTACT_PERSON_NAME||'—'}</span></div>
          <div class="detail-item"><label>Product</label><span>${f.PROD_NAME||'—'}</span></div>
          <div class="detail-item"><label>Serial #</label><span class="font-mono">${f.SERIAL_NUM||'—'}</span></div>
          <div class="detail-item"><label>Warranty End</label><span>${fmtDate(f.WARRANTY_END_DATE)}</span></div>
          <div class="detail-item"><label>SLA Days</label><span>${f.DEFAULT_SLA_DAYS||'—'}</span></div>
        </div>
      </div>

      <div class="detail-section">
        <h4>Assignment</h4>
        ${f.ASSIGN_ID
          ? `<div class="detail-grid" style="margin-bottom:10px">
              <div class="detail-item"><label>Assigned Date</label><span>${fmtDate(f.ASSIGN_DATE)}</span></div>
              <div class="detail-item"><label>Due Date</label><span>${fmtDate(f.DUE_DATE)}</span></div>
              <div class="detail-item"><label>Assign Status</label><span>${statusBadge(f.ASSIGN_STATUS)}</span></div>
            </div>`
          : '<div class="text-muted" style="font-size:12px;margin-bottom:10px">Not yet assigned</div>'}
        ${techList}
      </div>

      <div class="detail-section">
        <h4>Quick Actions</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
          <button class="btn btn-primary btn-sm" onclick="closeModal('modal-fault-detail');openAssignModal(${f.REP_FAULT_ID},'${(f.COMPANY_NAME||'').replace(/'/g,"\\'")}')"}>👤 Assign Technician</button>
          <select class="form-control" style="width:auto" id="quick-status-${f.REP_FAULT_ID}">
            <option value="">Change Status…</option>
            <option>Pending</option><option>Assigned</option><option>In Progress</option>
            <option>Completed</option><option>Verified</option><option>Rejected</option>
          </select>
          <button class="btn btn-outline btn-sm" onclick="quickStatus(${f.REP_FAULT_ID})">Apply Status</button>
          <select class="form-control" style="width:auto" id="quick-priority-${f.REP_FAULT_ID}">
            <option value="">Change Priority…</option>
            <option>Low</option><option>Medium</option><option>High</option><option>Critical</option>
          </select>
          <button class="btn btn-outline btn-sm" onclick="quickPriority(${f.REP_FAULT_ID})">Apply Priority</button>
        </div>
      </div>

      <div class="detail-section">
        <h4>Work Log / Timeline</h4>
        ${logList}
      </div>
    `;
  });
}

function quickStatus(faultId) {
  const s = document.getElementById('quick-status-'+faultId)?.value;
  if (!s) { toast('Select a status first','error'); return; }
  confirm2('Change Status', `Set fault #${faultId} to "${s}"?`, () => {
    post({action:'update_status', fault_id:faultId, status:s}).then(r => {
      toast(r.msg, r.ok ? 'success' : 'error');
      if (r.ok) { closeModal('modal-fault-detail'); loadFaults(); loadDashboard(); }
    });
  });
}

function quickPriority(faultId) {
  const p = document.getElementById('quick-priority-'+faultId)?.value;
  if (!p) { toast('Select a priority first','error'); return; }
  post({action:'update_priority', fault_id:faultId, priority:p}).then(r => {
    toast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) { closeModal('modal-fault-detail'); loadFaults(); }
  });
}

// ─── ASSIGN MODAL ────────────────────────────────────────
function openAssignModal(faultId, clientName) {
  // Build assign section inside fault detail or as a quick panel
  api({ajax:'technicians'}).then(techs => {
    state.allTechs = techs;

    const techCheckboxes = techs.length
      ? techs.map(t => {
          const busy = (t.active_faults||0) > 0;
          return `<label class="check-item">
            <input type="checkbox" name="tech_assign" value="${t.EMP_ID}">
            <div>
              <div style="font-size:13px;font-weight:600">${t.FULL_NAME}</div>
              <div style="font-size:10px;color:var(--text-muted)">${t.EMAIL}</div>
            </div>
            <div style="margin-left:auto">${statusBadge(busy?'Busy':'Available')}</div>
          </label>`;
        }).join('')
      : '<div class="empty"><h4>No technicians found</h4><p>Add technicians first</p></div>';

    const content = `
      <div style="background:var(--orange-dim);border:1px solid var(--orange);border-radius:8px;padding:12px;margin-bottom:14px">
        <div style="font-size:12px;font-weight:700;color:var(--orange)">Assigning: Fault #${faultId}</div>
        <div style="font-size:11px;color:var(--text-sec);margin-top:2px">Client: ${clientName}</div>
      </div>
      <div class="form-group">
        <label class="form-label">Select Technician(s) *</label>
        <div class="check-list">${techCheckboxes}</div>
      </div>
      <div class="form-group">
        <label class="form-label">Due Date</label>
        <input type="date" class="form-control" id="assign-due-date" value="${new Date(Date.now()+7*864e5).toISOString().slice(0,10)}">
      </div>
    `;

    // Inject into a new simple modal by re-using detail modal
    document.getElementById('detail-title').textContent = `Assign Technician — Fault #${faultId}`;
    document.getElementById('modal-fault-body').innerHTML = content + `
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
        <button class="btn btn-outline" onclick="closeModal('modal-fault-detail')">Cancel</button>
        <button class="btn btn-primary" onclick="submitAssign(${faultId})">✓ Assign Now</button>
      </div>
    `;
    openModal('modal-fault-detail');
  });
}

function submitAssign(faultId) {
  const checked = [...document.querySelectorAll('input[name=tech_assign]:checked')].map(el => el.value);
  const dueDate = document.getElementById('assign-due-date')?.value || '';
  if (!checked.length) { toast('Select at least one technician','error'); return; }

  // Build form data with multiple tech_ids[]
  const data = new URLSearchParams();
  data.append('action','assign_fault');
  data.append('fault_id', faultId);
  data.append('due_date', dueDate);
  checked.forEach(id => data.append('tech_ids[]', id));

  fetch(window.location.pathname, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: data.toString()
  }).then(r => r.json()).then(r => {
    toast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) { closeModal('modal-fault-detail'); loadFaults(); loadDashboard(); }
  });
}

// ─── TECHNICIANS ─────────────────────────────────────────
function loadTechnicians() {
  api({ajax:'technicians'}).then(techs => {
    const grid = document.getElementById('tech-grid');
    if (!techs.length) {
      grid.innerHTML = '<div class="empty"><div class="empty-icon">🔧</div><h4>No technicians yet</h4><p>Add your first technician</p></div>';
      return;
    }
    grid.innerHTML = techs.map(t => {
      const busy = (t.active_faults||0) > 0;
      return `<div class="tech-card">
        <div class="tech-avatar">${t.FULL_NAME.charAt(0)}</div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <div class="tech-name">${t.FULL_NAME}</div>
            <div class="tech-email">${t.EMAIL||'—'}</div>
          </div>
          ${statusBadge(busy?'Busy':'Available')}
        </div>
        <div class="tech-stats">
          <div class="tech-stat"><div class="num">${t.active_faults||0}</div><div class="lbl">Active</div></div>
          <div class="tech-stat"><div class="num">${t.total_faults||0}</div><div class="lbl">Total</div></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:6px">
          <span style="font-size:10px;color:var(--text-muted)">@${t.USERNAME||'—'}</span>
          <span style="font-size:10px;color:var(--text-muted)">·</span>
          <span style="font-size:10px;color:var(--text-muted)">Since ${fmtDate(t.HIRE_DATE)}</span>
        </div>
      </div>`;
    }).join('');
    document.getElementById('badge-techs').textContent = techs.length;
  });
}

function addTechnician() {
  const data = {
    action:'add_technician',
    full_name: document.getElementById('t-name').value,
    email: document.getElementById('t-email').value,
    username: document.getElementById('t-username').value,
    password: document.getElementById('t-password').value,
    hire_date: document.getElementById('t-hire').value,
    hourly_rate: document.getElementById('t-rate').value
  };
  if (!data.full_name || !data.email || !data.username || !data.password) {
    toast('Fill in all required fields','error'); return;
  }
  post(data).then(r => {
    toast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) { closeModal('modal-add-tech'); loadTechnicians(); }
  });
}

// ─── CLIENTS ─────────────────────────────────────────────
function loadClients() {
  api({ajax:'clients'}).then(clients => {
    const tbody = document.getElementById('clients-tbody');
    if (!clients.length) {
      tbody.innerHTML = '<tr><td colspan="10"><div class="empty"><div class="empty-icon">🏢</div><h4>No clients yet</h4></div></td></tr>';
      return;
    }
    tbody.innerHTML = clients.map(c => `<tr>
      <td class="td-mono">#${c.CLIENT_ID}</td>
      <td><b>${c.COMPANY_NAME}</b></td>
      <td>${c.CONTACT_PERSON_NAME||'—'}</td>
      <td><span class="badge" style="background:var(--teal-dim);color:var(--teal)">${c.CLIENT_TYPE||'—'}</span></td>
      <td>${c.COMPANY_EMAIL||'—'}</td>
      <td>${c.COMPANY_PHONE||'—'}</td>
      <td class="td-mono">${c.total_faults||0}</td>
      <td class="td-mono">${c.open_faults||0}</td>
      <td class="td-mono">${c.invoices||0}</td>
      <td class="td-mono">E${parseFloat(c.invoice_total||0).toFixed(2)}</td>
    </tr>`).join('');
    document.getElementById('badge-clients').textContent = clients.length;
  });
}

function addClient() {
  const data = {
    action:'add_client',
    company_name: document.getElementById('c-company').value,
    email: document.getElementById('c-email').value,
    phone: document.getElementById('c-phone').value,
    address: document.getElementById('c-address').value,
    contact_person: document.getElementById('c-contact').value,
    client_type: document.getElementById('c-type').value,
    username: document.getElementById('c-username').value,
    password: document.getElementById('c-password').value
  };
  if (!data.company_name || !data.email || !data.username || !data.password) {
    toast('Fill all required fields','error'); return;
  }
  post(data).then(r => {
    toast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) { closeModal('modal-add-client'); loadClients(); }
  });
}

// ─── INVOICES ────────────────────────────────────────────
function loadInvoices() {
  api({ajax:'invoices'}).then(invs => {
    const tbody = document.getElementById('invoices-tbody');
    if (!invs.length) {
      tbody.innerHTML = '<tr><td colspan="8"><div class="empty"><div class="empty-icon">🧾</div><h4>No invoices yet</h4><p>Invoices are created by the Accountant</p></div></td></tr>';
      return;
    }
    tbody.innerHTML = invs.map(i => {
      const total = parseFloat(i.TOTAL||0);
      const paid = parseFloat(i.paid_amount||0);
      const balance = total - paid;
      return `<tr>
        <td class="td-mono">#${i.INVOICE_ID}</td>
        <td><b>${i.COMPANY_NAME||'—'}</b></td>
        <td class="td-mono">${fmtDate(i.INVOICE_DATE)}</td>
        <td class="td-mono">${fmtDate(i.DUE_DATE)}</td>
        <td class="td-mono">E${total.toFixed(2)}</td>
        <td class="td-mono" style="color:var(--green)">E${paid.toFixed(2)}</td>
        <td class="td-mono" style="color:${balance>0?'var(--red)':'var(--green)'}">E${balance.toFixed(2)}</td>
        <td>${statusBadge(i.STATUS)}</td>
      </tr>`;
    }).join('');
  });
}

// ─── PAYMENTS ────────────────────────────────────────────
function loadPayments() {
  api({ajax:'payments'}).then(pays => {
    const tbody = document.getElementById('payments-tbody');
    if (!pays.length) {
      tbody.innerHTML = '<tr><td colspan="8"><div class="empty"><div class="empty-icon">💳</div><h4>No payments recorded</h4></div></td></tr>';
      return;
    }
    tbody.innerHTML = pays.map(p => `<tr>
      <td class="td-mono">#${p.PAYMENT_ID}</td>
      <td><b>${p.COMPANY_NAME||'—'}</b></td>
      <td class="td-mono">#${p.INVOICE_ID}</td>
      <td class="td-mono" style="color:var(--green)">E${parseFloat(p.AMOUNT_PAID||0).toFixed(2)}</td>
      <td>${p.METHOD||'—'}</td>
      <td class="td-mono">${p.REFERENCE_NUMBER||'—'}</td>
      <td class="td-mono">${fmtDate(p.PAYMENT_DATE)}</td>
      <td>${statusBadge(p.STATUS)}</td>
    </tr>`).join('');
  });
}

// ─── ACCOUNTS ────────────────────────────────────────────
function loadAccounts() {
  api({ajax:'accounts'}).then(emps => {
    const tbody = document.getElementById('accounts-tbody');
    if (!emps.length) {
      tbody.innerHTML = '<tr><td colspan="7"><div class="empty"><div class="empty-icon">👤</div><h4>No system accounts</h4></div></td></tr>';
      return;
    }
    const roleColors = {Admin:'var(--orange)', Accountant:'var(--teal)', Technician:'var(--blue)'};
    tbody.innerHTML = emps.map(e => `<tr>
      <td class="td-mono">#${e.EMP_ID}</td>
      <td><b>${e.FULL_NAME}</b></td>
      <td>${e.EMAIL||'—'}</td>
      <td><span class="badge" style="background:rgba(255,255,255,0.06);color:${roleColors[e.ROLE]||'var(--text-sec)'}">${e.ROLE}</span></td>
      <td class="td-mono">@${e.USERNAME||'—'}</td>
      <td class="td-mono">${fmtDate(e.HIRE_DATE)}</td>
      <td>
        ${e.ROLE !== 'Admin' ? `<button class="btn btn-danger btn-sm" onclick="deleteEmp(${e.EMP_ID},'${e.FULL_NAME.replace(/'/g,"\\'")}')">Remove</button>` : '<span class="text-muted" style="font-size:11px">Protected</span>'}
      </td>
    </tr>`).join('');
  });
}

function addAccount() {
  const data = {
    action:'add_account',
    full_name: document.getElementById('a-name').value,
    email: document.getElementById('a-email').value,
    role: document.getElementById('a-role').value,
    username: document.getElementById('a-username').value,
    password: document.getElementById('a-password').value
  };
  if (!data.full_name || !data.email || !data.username || !data.password) {
    toast('Fill all required fields','error'); return;
  }
  post(data).then(r => {
    toast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) { closeModal('modal-add-account'); loadAccounts(); }
  });
}

function deleteEmp(id, name) {
  confirm2('Remove Account', `Remove "${name}" from the system?`, () => {
    post({action:'delete_employee', emp_id:id}).then(r => {
      toast(r.msg, r.ok?'success':'error');
      if (r.ok) loadAccounts();
    });
  });
}

// ─── ANALYTICS ───────────────────────────────────────────
function loadAnalytics() {
  api({ajax:'analytics'}).then(data => {
    // Priority chart
    const pWrap = document.getElementById('priority-chart-wrap');
    if (pWrap && data.priority) {
      const colors = {Low:'var(--green)',Medium:'var(--blue)',High:'var(--yellow)',Critical:'var(--red)'};
      const max = Math.max(...data.priority.map(p=>p.cnt), 1);
      const bars = data.priority.map(p => `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <div style="width:60px;font-size:11px;color:var(--text-sec);text-align:right">${p.PRIORITY||'N/A'}</div>
          <div style="flex:1;height:20px;background:var(--bg-base);border-radius:4px;overflow:hidden">
            <div style="height:100%;background:${colors[p.PRIORITY]||'var(--orange)'};width:${(p.cnt/max*100).toFixed(1)}%;border-radius:4px;transition:width .5s"></div>
          </div>
          <div style="width:30px;font-size:11px;font-family:var(--font-mono);color:var(--text-sec)">${p.cnt}</div>
        </div>`).join('');
      pWrap.innerHTML = bars || '<div class="text-muted" style="font-size:12px;padding:20px">No data yet</div>';
    }

    // Status chart
    const sWrap = document.getElementById('status-chart-wrap2');
    if (sWrap && data.status) {
      const smax = Math.max(...data.status.map(s=>s.cnt), 1);
      const statColors = {'Pending':'var(--yellow)','Assigned':'var(--blue)','In Progress':'var(--purple)','Completed':'var(--teal)','Verified':'var(--green)','Rejected':'var(--red)'};
      sWrap.innerHTML = data.status.map(s => `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <div style="width:80px;font-size:10px;color:var(--text-sec);text-align:right">${s.STATUS}</div>
          <div style="flex:1;height:20px;background:var(--bg-base);border-radius:4px;overflow:hidden">
            <div style="height:100%;background:${statColors[s.STATUS]||'var(--orange)'};width:${(s.cnt/smax*100).toFixed(1)}%;border-radius:4px;transition:width .5s"></div>
          </div>
          <div style="width:30px;font-size:11px;font-family:var(--font-mono);color:var(--text-sec)">${s.cnt}</div>
        </div>`).join('') || '<div class="text-muted" style="font-size:12px;padding:20px">No data yet</div>';
    }

    // Monthly trend
    const mWrap = document.getElementById('monthly-chart-wrap');
    if (mWrap && data.monthly) {
      if (!data.monthly.length) {
        mWrap.innerHTML = '<div class="empty"><div class="empty-icon">📈</div><h4>No monthly data yet</h4></div>';
        return;
      }
      const mmax = Math.max(...data.monthly.map(m=>m.cnt), 1);
      const bars = data.monthly.map(m => `
        <div class="chart-bar" style="background:var(--orange);height:${Math.max(4,(m.cnt/mmax)*120)}px;opacity:0.8;flex:1;min-width:40px;position:relative;border-radius:4px 4px 0 0">
          <div class="chart-tooltip">${m.month}: ${m.cnt} faults</div>
        </div>`).join('');
      const labels = data.monthly.map(m => `<span style="flex:1;text-align:center;font-size:10px;color:var(--text-muted)">${m.month}<br><b style="color:var(--orange)">${m.cnt}</b></span>`).join('');
      mWrap.innerHTML = `
        <div style="height:140px;display:flex;align-items:flex-end;gap:6px;padding:0 4px">
          <div class="chart-bar-wrap" style="height:120px;width:100%">${bars}</div>
        </div>
        <div class="chart-labels" style="margin-top:8px">${labels}</div>`;
    }
  });
}

// ─── NOTIFICATIONS ───────────────────────────────────────
function loadNotifications() {
  api({ajax:'activity'}).then(items => {
    const el = document.getElementById('notif-list');
    if (!items.length) {
      el.innerHTML = '<div class="empty"><div class="empty-icon">🔔</div><h4>No notifications</h4><p>System activity will appear here</p></div>';
      return;
    }
    const typeMap = {
      'fault_reported': {icon:'⚠️', bg:'var(--yellow-dim)', label:'Fault Reported'},
      'fault_assigned': {icon:'👤', bg:'var(--blue-dim)',   label:'Fault Assigned'},
    };
    el.innerHTML = items.map(i => {
      const t = typeMap[i.type] || {icon:'ℹ️', bg:'var(--bg-card2)', label:'Activity'};
      return `<div class="activity-item">
        <div class="activity-icon" style="background:${t.bg}">${t.icon}</div>
        <div class="activity-body">
          <div class="activity-title">${i.detail||'System event'}</div>
          <div class="activity-meta">${t.label} · ${i.actor||'System'} · ${fmtDateTime(i.ts)} · ${statusBadge(i.status)}</div>
        </div>
      </div>`;
    }).join('');
  });
}

// ─── INIT ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();

  // Auto-refresh every 30 seconds
  setInterval(() => {
    if (state.currentPage === 'dashboard') loadDashboard();
  }, 30000);

  // Close modals on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
      if (e.target === overlay) overlay.classList.remove('open');
    });
  });

  // Mobile sidebar toggle (add hamburger button in topbar for mobile)
  if (window.innerWidth <= 900) {
    const btn = document.createElement('button');
    btn.innerHTML = '☰';
    btn.style.cssText = 'background:none;border:none;color:var(--text-primary);font-size:20px;cursor:pointer;padding:4px 8px;';
    btn.onclick = () => document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('topbar').insertBefore(btn, document.getElementById('topbar').firstChild);
  }
});
</script>
</body>
</html>
