<?php
// ============================================================
// BUSIQUIP FAULT MANAGEMENT SYSTEM — ADMIN REPORTS
// admin_reports.php
// Place in: fault_management/modules/admin/
// DB: busiquip_final | Matches existing schema exactly
// ============================================================

session_start();

// ── DB CONFIG — uses environment variables (Railway/Docker) or XAMPP defaults ──
require_once __DIR__ . '/../../config/database.php';

// ── AUTH GUARD ─────────────────────────────────────────────
// Uncomment in production:
// if (!isset($_SESSION['admin_id'])) { header('Location: ../../login.php'); exit; }
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

// ── HELPER: safe escape ──────────────────────────────────────
function esc($conn, $v) { return $conn->real_escape_string($v ?? ''); }
function dateWhere($conn, $alias, $col, $from, $to) {
    $w = '';
    if ($from) $w .= " AND $alias.$col >= '".esc($conn,$from)."'";
    if ($to)   $w .= " AND $alias.$col <= '".esc($conn,$to)." 23:59:59'";
    return $w;
}

// ── AJAX HANDLER ───────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $from    = $_GET['from']     ?? '';
    $to      = $_GET['to']       ?? '';
    $status  = $_GET['status']   ?? '';
    $client  = (int)($_GET['client_id'] ?? 0);
    $tech    = (int)($_GET['tech_id']   ?? 0);
    $prio    = $_GET['priority'] ?? '';
    $cat     = $_GET['category'] ?? '';
    $method  = $_GET['method']   ?? '';

    switch ($_GET['ajax']) {

        // ═══════════════════════════════════════════════════
        // 1. SUMMARY OVERVIEW REPORT
        // ═══════════════════════════════════════════════════
        case 'report_summary':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            $row = $conn->query("
                SELECT
                  COUNT(*) as total_faults,
                  SUM(STATUS='Pending') as pending,
                  SUM(STATUS='Assigned') as assigned,
                  SUM(STATUS='In Progress') as in_progress,
                  SUM(STATUS='Completed') as completed,
                  SUM(STATUS='Verified') as verified,
                  SUM(STATUS='Rejected') as rejected,
                  SUM(STATUS='Client Approved') as client_approved,
                  SUM(STATUS='Closed') as closed,
                  SUM(STATUS='Rework Required') as rework,
                  SUM(PRIORITY='Critical') as p_critical,
                  SUM(PRIORITY='High') as p_high,
                  SUM(PRIORITY='Medium') as p_medium,
                  SUM(PRIORITY='Low') as p_low,
                  AVG(DATEDIFF(IFNULL(
                    (SELECT MIN(LOG_DATE) FROM work_log wl JOIN assignment a ON a.ASSIGN_ID=wl.ASSIGN_ID WHERE a.REP_FAULT_ID=rf.REP_FAULT_ID),
                    NOW()
                  ), rf.REPORT_DATE)) as avg_resolution_days
                FROM reported_fault rf WHERE 1=1 $dw
            ")->fetch_assoc();

            // Revenue summary
            $rev = $conn->query("
                SELECT
                  COUNT(DISTINCT i.INVOICE_ID) as total_invoices,
                  COALESCE(SUM(i.TOTAL),0) as total_invoiced,
                  COALESCE(SUM(CASE WHEN p.STATUS='Paid' THEN p.AMOUNT_PAID END),0) as total_collected,
                  COALESCE(SUM(CASE WHEN i.STATUS='Pending Payment' THEN i.TOTAL END),0) as outstanding
                FROM invoice i
                LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID
                LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                WHERE 1=1 $dw
            ")->fetch_assoc();

            // Clients & Techs
            $clients_count = $conn->query("SELECT COUNT(DISTINCT CLIENT_ID) as c FROM reported_fault rf WHERE 1=1 $dw")->fetch_assoc()['c'];
            $techs_count   = $conn->query("SELECT COUNT(DISTINCT at2.EMP_ID) as c FROM assignment_technician at2 JOIN assignment a ON a.ASSIGN_ID=at2.ASSIGN_ID JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID WHERE 1=1 $dw")->fetch_assoc()['c'];

            echo json_encode(array_merge($row,$rev,['active_clients'=>$clients_count,'active_techs'=>$techs_count]));
            break;

        // ═══════════════════════════════════════════════════
        // 2. FAULT DETAIL REPORT (all faults with full info)
        // ═══════════════════════════════════════════════════
        case 'report_faults':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            if ($status)  $dw .= " AND rf.STATUS='".esc($conn,$status)."'";
            if ($client)  $dw .= " AND rf.CLIENT_ID=$client";
            if ($prio)    $dw .= " AND rf.PRIORITY='".esc($conn,$prio)."'";
            if ($cat)     $dw .= " AND f.FAULT_TYPE='".esc($conn,$cat)."'";
            if ($tech)    $dw .= " AND at2.EMP_ID=$tech";

            $sql = "
                SELECT
                  rf.REP_FAULT_ID, rf.REPORT_DATE, rf.STATUS, rf.PRIORITY, rf.DESCRIPTION,
                  c.COMPANY_NAME, c.CONTACT_PERSON_NAME, c.COMPANY_EMAIL,
                  f.FAULT_TYPE,
                  p.PROD_NAME, cp.SERIAL_NUM,
                  a.ASSIGN_DATE, a.DUE_DATE, a.STATUS as ASSIGN_STATUS,
                  GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') as TECHNICIANS,
                  i.INVOICE_NO, i.TOTAL as INVOICE_TOTAL, i.STATUS as INVOICE_STATUS,
                  COALESCE(pay.AMOUNT_PAID,0) as PAID_AMOUNT,
                  DATEDIFF(IFNULL(a.ASSIGN_DATE, NOW()), rf.REPORT_DATE) as days_to_assign
                FROM reported_fault rf
                LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
                LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                LEFT JOIN client_product cp ON cp.CLIENT_PROD_ID=rf.CLIENT_PROD_ID
                LEFT JOIN product p ON p.PROD_ID=cp.PROD_ID
                LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
                LEFT JOIN employee e ON e.EMP_ID=at2.EMP_ID
                LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID
                LEFT JOIN payment pay ON pay.INVOICE_ID=i.INVOICE_ID AND pay.STATUS='Paid'
                WHERE 1=1 $dw
                GROUP BY rf.REP_FAULT_ID
                ORDER BY rf.REPORT_DATE DESC
                LIMIT 500
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 3. TECHNICIAN PERFORMANCE REPORT
        // ═══════════════════════════════════════════════════
        case 'report_technicians':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            if ($tech) $dw .= " AND e.EMP_ID=$tech";

            $sql = "
                SELECT
                  e.EMP_ID, e.FULL_NAME, e.EMAIL,
                  COUNT(DISTINCT a.ASSIGN_ID) as total_assigned,
                  SUM(rf.STATUS='Completed') as completed,
                  SUM(rf.STATUS='In Progress') as in_progress,
                  SUM(rf.STATUS='Rework Required') as rework,
                  SUM(rf.STATUS='Closed') as closed,
                  COALESCE(SUM(wl.HOURS_WORKED),0) as total_hours,
                  AVG(wl.HOURS_WORKED) as avg_hours_per_log,
                  COUNT(DISTINCT wl.LOG_ID) as total_logs,
                  COALESCE(SUM(wl.TRANSPORT_COST),0) as total_transport,
                  ROUND(
                    (SUM(rf.STATUS IN ('Completed','Verified','Closed','Client Approved')) /
                     NULLIF(COUNT(DISTINCT a.ASSIGN_ID),0))*100, 1
                  ) as completion_rate
                FROM employee e
                LEFT JOIN assignment_technician at2 ON at2.EMP_ID=e.EMP_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=at2.ASSIGN_ID
                LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                LEFT JOIN work_log wl ON wl.ASSIGN_ID=a.ASSIGN_ID AND wl.EMP_ID=e.EMP_ID
                WHERE e.ROLE='Technician' $dw
                GROUP BY e.EMP_ID
                ORDER BY completed DESC, total_assigned DESC
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 4. CLIENT REPORT
        // ═══════════════════════════════════════════════════
        case 'report_clients':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            if ($client) $dw .= " AND c.CLIENT_ID=$client";

            $sql = "
                SELECT
                  c.CLIENT_ID, c.COMPANY_NAME, c.COMPANY_EMAIL, c.CONTACT_PERSON_NAME, c.COMPANY_PHONE,
                  COUNT(DISTINCT rf.REP_FAULT_ID) as total_faults,
                  SUM(rf.STATUS='Pending') as pending,
                  SUM(rf.STATUS IN ('Assigned','In Progress')) as active,
                  SUM(rf.STATUS IN ('Completed','Verified','Closed','Client Approved')) as resolved,
                  SUM(rf.STATUS='Rejected') as rejected,
                  SUM(rf.PRIORITY='Critical') as critical_faults,
                  COUNT(DISTINCT i.INVOICE_ID) as invoices,
                  COALESCE(SUM(i.TOTAL),0) as total_billed,
                  COALESCE(SUM(CASE WHEN pay.STATUS='Paid' THEN pay.AMOUNT_PAID END),0) as total_paid,
                  COALESCE(SUM(CASE WHEN i.STATUS='Pending Payment' THEN i.TOTAL END),0) as outstanding
                FROM client c
                LEFT JOIN reported_fault rf ON rf.CLIENT_ID=c.CLIENT_ID
                LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID
                LEFT JOIN payment pay ON pay.INVOICE_ID=i.INVOICE_ID
                WHERE 1=1 $dw
                GROUP BY c.CLIENT_ID
                ORDER BY total_faults DESC
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 5. FINANCIAL / INVOICE REPORT
        // ═══════════════════════════════════════════════════
        case 'report_financial':
            $dw = dateWhere($conn,'i','INVOICE_DATE',$from,$to);
            if ($client) $dw .= " AND i.CLIENT_ID=$client";
            if ($status) $dw .= " AND i.STATUS='".esc($conn,$status)."'";

            $sql = "
                SELECT
                  i.INVOICE_ID, i.INVOICE_NO, i.INVOICE_DATE, i.DUE_DATE, i.STATUS as INV_STATUS,
                  i.LABOUR_COST, i.MATERIAL_COST, i.TRANSPORT_COST, i.TAX_AMOUNT, i.TOTAL,
                  c.COMPANY_NAME, c.CONTACT_PERSON_NAME,
                  rf.REP_FAULT_ID, rf.STATUS as FAULT_STATUS,
                  f.FAULT_TYPE,
                  GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') as TECHNICIANS,
                  COALESCE(SUM(CASE WHEN pay.STATUS='Paid' THEN pay.AMOUNT_PAID END),0) as paid_amount,
                  COALESCE(SUM(CASE WHEN pay.STATUS='Paid' THEN pay.AMOUNT_PAID END),0) as collected,
                  pay2.PAYMENT_METHOD, pay2.PAYMENT_DATE, pay2.TRANSACTION_REF
                FROM invoice i
                LEFT JOIN client c ON c.CLIENT_ID=i.CLIENT_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID
                LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
                LEFT JOIN employee e ON e.EMP_ID=at2.EMP_ID
                LEFT JOIN payment pay ON pay.INVOICE_ID=i.INVOICE_ID
                LEFT JOIN payment pay2 ON pay2.INVOICE_ID=i.INVOICE_ID AND pay2.STATUS='Paid'
                WHERE 1=1 $dw
                GROUP BY i.INVOICE_ID
                ORDER BY i.INVOICE_DATE DESC
                LIMIT 500
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;

            // Totals
            $totals = $conn->query("
                SELECT
                  COALESCE(SUM(i.TOTAL),0) as grand_total,
                  COALESCE(SUM(i.LABOUR_COST),0) as total_labour,
                  COALESCE(SUM(i.MATERIAL_COST),0) as total_materials,
                  COALESCE(SUM(i.TRANSPORT_COST),0) as total_transport,
                  COALESCE(SUM(i.TAX_AMOUNT),0) as total_tax,
                  COALESCE(SUM(CASE WHEN p.STATUS='Paid' THEN p.AMOUNT_PAID END),0) as total_collected,
                  COALESCE(SUM(CASE WHEN i.STATUS='Pending Payment' THEN i.TOTAL END),0) as total_outstanding
                FROM invoice i
                LEFT JOIN payment p ON p.INVOICE_ID=i.INVOICE_ID
                WHERE 1=1 $dw
            ")->fetch_assoc();

            echo json_encode(['rows'=>$rows,'totals'=>$totals]);
            break;

        // ═══════════════════════════════════════════════════
        // 6. PAYMENT REPORT
        // ═══════════════════════════════════════════════════
        case 'report_payments':
            $dw = dateWhere($conn,'pay','PAYMENT_DATE',$from,$to);
            if ($client) $dw .= " AND c.CLIENT_ID=$client";
            if ($method) $dw .= " AND pay.PAYMENT_METHOD='".esc($conn,$method)."'";
            if ($status) $dw .= " AND pay.STATUS='".esc($conn,$status)."'";

            $sql = "
                SELECT
                  pay.PAYMENT_ID, pay.PAYMENT_DATE, pay.AMOUNT_PAID, pay.PAYMENT_METHOD,
                  pay.TRANSACTION_REF, pay.STATUS as PAY_STATUS, pay.NOTES,
                  i.INVOICE_NO, i.TOTAL as INVOICE_TOTAL, i.STATUS as INV_STATUS,
                  c.COMPANY_NAME, c.CONTACT_PERSON_NAME,
                  rf.REP_FAULT_ID, f.FAULT_TYPE,
                  e_acc.FULL_NAME as VERIFIED_BY
                FROM payment pay
                JOIN invoice i ON i.INVOICE_ID=pay.INVOICE_ID
                JOIN client c ON c.CLIENT_ID=i.CLIENT_ID
                LEFT JOIN assignment a ON a.ASSIGN_ID=i.ASSIGN_ID
                LEFT JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                LEFT JOIN employee e_acc ON e_acc.EMP_ID=pay.VERIFIED_BY
                WHERE 1=1 $dw
                ORDER BY pay.PAYMENT_DATE DESC
                LIMIT 500
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 7. FAULT CATEGORY / TYPE BREAKDOWN
        // ═══════════════════════════════════════════════════
        case 'report_categories':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            $sql = "
                SELECT
                  f.FAULT_TYPE,
                  COUNT(rf.REP_FAULT_ID) as total,
                  SUM(rf.STATUS='Pending') as pending,
                  SUM(rf.STATUS IN ('Assigned','In Progress')) as active,
                  SUM(rf.STATUS IN ('Completed','Verified','Closed','Client Approved')) as resolved,
                  SUM(rf.PRIORITY='Critical') as critical,
                  SUM(rf.PRIORITY='High') as high,
                  AVG(DATEDIFF(IFNULL(a.ASSIGN_DATE,NOW()), rf.REPORT_DATE)) as avg_response_days,
                  COALESCE(SUM(i.TOTAL),0) as revenue_generated
                FROM fault f
                LEFT JOIN reported_fault rf ON rf.FAULT_ID=f.FAULT_ID
                LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID
                WHERE 1=1 $dw
                GROUP BY f.FAULT_ID
                ORDER BY total DESC
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 8. MONTHLY TREND REPORT
        // ═══════════════════════════════════════════════════
        case 'report_trend':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            $sql = "
                SELECT
                  DATE_FORMAT(rf.REPORT_DATE,'%Y-%m') as ym,
                  DATE_FORMAT(rf.REPORT_DATE,'%b %Y') as label,
                  COUNT(*) as faults_reported,
                  SUM(rf.STATUS IN ('Completed','Verified','Closed','Client Approved')) as resolved,
                  SUM(rf.PRIORITY='Critical') as critical,
                  COALESCE(SUM(i.TOTAL),0) as revenue
                FROM reported_fault rf
                LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                LEFT JOIN invoice i ON i.ASSIGN_ID=a.ASSIGN_ID
                WHERE 1=1 $dw
                GROUP BY ym
                ORDER BY ym ASC
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 9. SLA / RESPONSE TIME REPORT
        // ═══════════════════════════════════════════════════
        case 'report_sla':
            $dw = dateWhere($conn,'rf','REPORT_DATE',$from,$to);
            $sql = "
                SELECT
                  rf.REP_FAULT_ID, rf.REPORT_DATE, rf.STATUS, rf.PRIORITY,
                  c.COMPANY_NAME, f.FAULT_TYPE, f.DEFAULT_SLA_DAYS,
                  a.ASSIGN_DATE, a.DUE_DATE,
                  GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') as TECHNICIANS,
                  DATEDIFF(IFNULL(a.ASSIGN_DATE, NOW()), rf.REPORT_DATE) as response_days,
                  DATEDIFF(NOW(), rf.REPORT_DATE) as age_days,
                  CASE
                    WHEN a.DUE_DATE IS NULL THEN 'No Due Date'
                    WHEN NOW() > a.DUE_DATE AND rf.STATUS NOT IN ('Completed','Verified','Closed','Client Approved') THEN 'Overdue'
                    WHEN DATEDIFF(a.DUE_DATE, NOW()) <= 1 AND rf.STATUS NOT IN ('Completed','Verified','Closed','Client Approved') THEN 'Due Soon'
                    WHEN rf.STATUS IN ('Completed','Verified','Closed','Client Approved') THEN 'Resolved'
                    ELSE 'On Track'
                  END as sla_status
                FROM reported_fault rf
                LEFT JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
                LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                LEFT JOIN assignment a ON a.REP_FAULT_ID=rf.REP_FAULT_ID
                LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID=a.ASSIGN_ID
                LEFT JOIN employee e ON e.EMP_ID=at2.EMP_ID
                WHERE 1=1 $dw
                GROUP BY rf.REP_FAULT_ID
                ORDER BY response_days DESC
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // 10. WORK LOG / MATERIALS REPORT
        // ═══════════════════════════════════════════════════
        case 'report_worklogs':
            $dw = dateWhere($conn,'wl','LOG_DATE',$from,$to);
            if ($tech) $dw .= " AND wl.EMP_ID=$tech";
            $sql = "
                SELECT
                  wl.LOG_ID, wl.LOG_DATE, wl.HOURS_WORKED, wl.NOTES,
                  wl.TRANSPORT_COST, wl.MATERIALS_USED,
                  e.FULL_NAME as TECHNICIAN,
                  rf.REP_FAULT_ID, rf.STATUS as FAULT_STATUS, rf.PRIORITY,
                  c.COMPANY_NAME,
                  f.FAULT_TYPE
                FROM work_log wl
                JOIN employee e ON e.EMP_ID=wl.EMP_ID
                JOIN assignment a ON a.ASSIGN_ID=wl.ASSIGN_ID
                JOIN reported_fault rf ON rf.REP_FAULT_ID=a.REP_FAULT_ID
                JOIN client c ON c.CLIENT_ID=rf.CLIENT_ID
                LEFT JOIN fault f ON f.FAULT_ID=rf.FAULT_ID
                WHERE 1=1 $dw
                ORDER BY wl.LOG_DATE DESC
                LIMIT 500
            ";
            $rows=[];
            $res=$conn->query($sql);
            while($r=$res->fetch_assoc()) $rows[]=$r;
            echo json_encode($rows);
            break;

        // ═══════════════════════════════════════════════════
        // FILTER DATA SOURCES
        // ═══════════════════════════════════════════════════
        case 'filter_clients':
            $res=$conn->query("SELECT CLIENT_ID, COMPANY_NAME FROM client ORDER BY COMPANY_NAME");
            $d=[];while($r=$res->fetch_assoc())$d[]=$r;
            echo json_encode($d); break;

        case 'filter_techs':
            $res=$conn->query("SELECT EMP_ID, FULL_NAME FROM employee WHERE ROLE='Technician' ORDER BY FULL_NAME");
            $d=[];while($r=$res->fetch_assoc())$d[]=$r;
            echo json_encode($d); break;

        case 'filter_categories':
            $res=$conn->query("SELECT FAULT_TYPE FROM fault ORDER BY FAULT_TYPE");
            $d=[];while($r=$res->fetch_assoc())$d[]=$r['FAULT_TYPE'];
            echo json_encode($d); break;
    }

    $conn->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reports — BUSIQUIP Fault Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════════════
   BUSIQUIP — ADMIN REPORTS  (matches dashboard design)
═══════════════════════════════════════════════════════ */
:root{
    --burg:#8B0000;--burg2:#C0392B;--burg-g:rgba(139,0,0,.3);
    --gold:#E8B84B;--gold2:#FFD700;--gold-p:rgba(232,184,75,.12);
    --teal:#0D9488;--sky:#0EA5E9;--em:#10B981;
    --warn:#F59E0B;--dan:#EF4444;--ind:#6366F1;
    --bg0:#070C14;--bg1:#0D1421;--bg2:#111B2E;--bg3:#1A2640;--bg4:#243055;
    --sur:rgba(17,27,46,.95);--gl:rgba(255,255,255,.04);--glb:rgba(255,255,255,.07);
    --bor:rgba(232,184,75,.16);--borh:rgba(232,184,75,.4);
    --t1:#EFF4FF;--t2:#8A9CC4;--t3:#445570;
    --r:14px;--rl:22px;
    --sh:0 8px 32px rgba(0,0,0,.5);
    --blur:blur(18px);--tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;
    --sw:260px;--hh:82px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;font-size:15px}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}

/* BG GRID */
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);
    background-size:50px 50px}
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0}
.o1{width:520px;height:520px;background:radial-gradient(circle,rgba(139,0,0,.18),transparent 70%);top:-180px;left:-140px}
.o2{width:400px;height:400px;background:radial-gradient(circle,rgba(232,184,75,.1),transparent 70%);bottom:-100px;right:-120px}

/* TOPBAR */
#topbar{
    position:fixed;top:0;left:0;right:0;z-index:1100;
    height:var(--hh);
    background:rgba(13,20,33,.96);
    border-bottom:1px solid var(--bor);
    backdrop-filter:var(--blur);
    display:flex;align-items:center;padding:0 24px;gap:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.4);
}
.topbar-logo{display:flex;align-items:center;gap:12px;flex-shrink:0}
.topbar-logo-icon{
    width:44px;height:44px;border-radius:10px;
    background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:22px;
    box-shadow:0 0 18px var(--burg-g);
}
.topbar-logo-text{font-family:var(--fh);font-size:22px;font-weight:800;
    background:linear-gradient(135deg,var(--gold2),var(--burg2));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.topbar-logo-sub{font-size:10px;color:var(--t3);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm);margin-top:-2px}
.topbar-breadcrumb{display:flex;align-items:center;gap:6px;color:var(--t2);font-size:13px;font-family:var(--fm);flex:1}
.topbar-breadcrumb span{color:var(--gold);font-weight:600}
.topbar-right{display:flex;align-items:center;gap:10px;margin-left:auto}
.pill{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;font-size:11px;font-family:var(--fm);background:var(--glb);border:1px solid var(--bor);color:var(--t2)}
.pill .dot{width:7px;height:7px;border-radius:50%}
.pill.live .dot{background:var(--em);box-shadow:0 0 7px var(--em);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
#clock{font-family:var(--fm);font-size:12px;color:var(--t2);padding:5px 13px;border:1px solid var(--bor);border-radius:99px;background:var(--gl)}
.back-btn{
    display:flex;align-items:center;gap:7px;padding:8px 16px;border-radius:99px;
    background:var(--gl);border:1px solid var(--bor);color:var(--t2);font-size:13px;
    transition:var(--tr);cursor:pointer;
}
.back-btn:hover{border-color:var(--borh);color:var(--gold)}

/* SIDEBAR */
#sidebar{
    position:fixed;top:var(--hh);left:0;bottom:0;
    width:var(--sw);z-index:1200;
    background:rgba(11,18,33,.97);
    backdrop-filter:var(--blur);
    border-right:1px solid var(--bor);
    overflow-y:auto;overflow-x:hidden;
    padding:20px 0 80px;
}
.nav-group-label{padding:12px 20px 5px;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);font-weight:600;display:block}
.nav-item{display:flex;align-items:center;gap:11px;padding:11px 18px;margin:2px 8px;border-radius:10px;color:var(--t2);font-size:14px;font-weight:500;cursor:pointer;transition:var(--tr);border:1px solid transparent}
.nav-item:hover,.nav-item.active{background:var(--gold-p);color:var(--gold);border-color:var(--bor)}
.nav-item .ni{width:30px;height:30px;border-radius:8px;background:var(--gl);display:flex;align-items:center;justify-content:center;font-size:14px;transition:var(--tr);flex-shrink:0}
.nav-item:hover .ni,.nav-item.active .ni{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 10px var(--burg-g)}
.nav-divider{height:1px;background:var(--bor);margin:10px 18px}

/* MAIN */
#main{margin-left:var(--sw);margin-top:var(--hh);padding:28px 28px 80px;min-height:calc(100vh - var(--hh));position:relative;z-index:1}
@media(max-width:1024px){#main{margin-left:0;padding:20px 14px 60px}#sidebar{transform:translateX(-100%);transition:transform .35s ease}#sidebar.open{transform:translateX(0)}}

/* PAGE HEADER */
.page-header{margin-bottom:24px}
.page-title{font-family:var(--fh);font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--t1),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.page-sub{color:var(--t2);font-size:14px}

/* REPORT TABS */
.report-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:24px;background:rgba(13,20,33,.8);border:1px solid var(--bor);border-radius:16px;padding:8px}
.rtab{
    display:flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;
    font-size:13px;font-weight:500;cursor:pointer;transition:var(--tr);
    border:1px solid transparent;color:var(--t2);background:none;white-space:nowrap;
}
.rtab:hover{color:var(--t1);background:var(--gl)}
.rtab.active{background:linear-gradient(135deg,var(--burg),rgba(232,184,75,.2));color:var(--gold);border-color:var(--bor);box-shadow:0 4px 14px var(--burg-g)}
.rtab i{font-size:13px}

/* FILTER BAR */
.filter-bar{
    background:var(--sur);border:1px solid var(--bor);border-radius:16px;
    padding:18px 20px;margin-bottom:20px;
    display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;
}
.filter-group{display:flex;flex-direction:column;gap:5px;min-width:140px}
.filter-group label{font-size:11px;color:var(--t3);font-family:var(--fm);letter-spacing:.08em;text-transform:uppercase}
.filter-group input,.filter-group select{
    background:var(--bg3);border:1px solid var(--bor);border-radius:8px;
    padding:8px 12px;color:var(--t1);font-family:var(--fb);font-size:13px;
    outline:none;transition:var(--tr);
}
.filter-group input:focus,.filter-group select:focus{border-color:var(--borh);box-shadow:0 0 0 3px var(--gold-p)}
.filter-group select option{background:var(--bg2)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:600;border:none;transition:var(--tr);cursor:pointer;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--burg),var(--burg2));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.btn-outline{background:var(--gl);border:1px solid var(--bor);color:var(--t2)}
.btn-outline:hover{border-color:var(--borh);color:var(--gold)}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#111;font-weight:700}
.btn-gold:hover{opacity:.9;transform:translateY(-1px)}
.btn-teal{background:rgba(13,148,136,.2);border:1px solid rgba(13,148,136,.3);color:var(--teal)}
.btn-teal:hover{background:rgba(13,148,136,.3)}
.btn-sm{padding:6px 12px;font-size:12px}
.filter-actions{display:flex;gap:8px;align-items:flex-end;padding-bottom:1px}

/* REPORT PANELS */
.report-panel{display:none}
.report-panel.active{display:block}

/* SUMMARY CARDS */
.sum-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px}
.sum-card{
    background:var(--sur);border:1px solid var(--bor);border-radius:14px;
    padding:18px 16px;text-align:center;transition:var(--tr);
}
.sum-card:hover{border-color:var(--borh);transform:translateY(-2px)}
.sum-card .sc-val{font-family:var(--fh);font-size:28px;font-weight:800;margin-bottom:4px}
.sum-card .sc-lab{font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;font-family:var(--fm)}
.sum-card .sc-icon{font-size:20px;margin-bottom:8px}
.c-gold{color:var(--gold)}
.c-green{color:var(--em)}
.c-blue{color:var(--sky)}
.c-red{color:var(--dan)}
.c-teal{color:var(--teal)}
.c-warn{color:var(--warn)}
.c-purple{color:var(--ind)}
.c-gray{color:var(--t3)}

/* SECTION CARDS */
.sec-card{background:var(--sur);border:1px solid var(--bor);border-radius:16px;margin-bottom:20px;overflow:hidden}
.sec-card-head{
    display:flex;align-items:center;gap:12px;padding:16px 20px;
    border-bottom:1px solid var(--bor);
    background:rgba(232,184,75,.04);
}
.sec-card-head h3{font-family:var(--fh);font-size:16px;font-weight:700;flex:1}
.sec-card-head .hbadge{font-size:11px;font-family:var(--fm);padding:3px 9px;border-radius:99px;background:var(--gold-p);color:var(--gold);border:1px solid var(--bor)}

/* TABLE */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:600px}
thead tr{background:rgba(232,184,75,.06)}
th{padding:11px 14px;text-align:left;font-size:11px;color:var(--t3);font-family:var(--fm);text-transform:uppercase;letter-spacing:.1em;border-bottom:1px solid var(--bor);white-space:nowrap}
td{padding:11px 14px;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.02)}
.td-mono{font-family:var(--fm);font-size:12px;color:var(--t2)}
.td-bold{font-weight:600}
.tbl-empty{text-align:center;padding:40px;color:var(--t3)}
.tbl-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--bor);background:rgba(232,184,75,.03)}
.tbl-count{font-size:12px;color:var(--t3);font-family:var(--fm)}

/* BADGE */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;font-family:var(--fm)}
.badge-pending{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.badge-assigned{background:rgba(14,165,233,.15);color:var(--sky);border:1px solid rgba(14,165,233,.3)}
.badge-inprogress{background:rgba(99,102,241,.15);color:var(--ind);border:1px solid rgba(99,102,241,.3)}
.badge-completed{background:rgba(16,185,129,.15);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.badge-verified,.badge-closed,.badge-approved{background:rgba(13,148,136,.15);color:var(--teal);border:1px solid rgba(13,148,136,.3)}
.badge-rejected{background:rgba(239,68,68,.15);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.badge-rework{background:rgba(232,184,75,.15);color:var(--gold);border:1px solid var(--bor)}
.badge-overdue{background:rgba(239,68,68,.15);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.badge-ontrack{background:rgba(16,185,129,.15);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.badge-duesoon{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.badge-paid{background:rgba(16,185,129,.15);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.badge-pending-pay{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.prio-critical{color:var(--dan);font-weight:700}
.prio-high{color:var(--warn);font-weight:600}
.prio-medium{color:var(--sky)}
.prio-low{color:var(--em)}

/* CHART BARS */
.mini-bar-wrap{display:flex;align-items:center;gap:8px;width:100%}
.mini-bar-bg{flex:1;height:6px;background:var(--bg3);border-radius:99px;overflow:hidden}
.mini-bar{height:100%;border-radius:99px;min-width:2px}
.mini-val{font-family:var(--fm);font-size:11px;color:var(--t2);width:32px;text-align:right}

/* TREND CHART */
.trend-chart{height:160px;display:flex;align-items:flex-end;gap:8px;padding:0 8px 8px;border-bottom:1px solid var(--bor)}
.trend-bar{flex:1;border-radius:6px 6px 0 0;min-width:30px;position:relative;cursor:pointer;transition:var(--tr)}
.trend-bar:hover{opacity:.8}
.trend-bar-tooltip{
    position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);
    background:var(--bg3);border:1px solid var(--bor);border-radius:8px;
    padding:6px 10px;font-size:11px;font-family:var(--fm);white-space:nowrap;
    pointer-events:none;opacity:0;transition:opacity .2s;z-index:10;
}
.trend-bar:hover .trend-bar-tooltip{opacity:1}
.trend-labels{display:flex;gap:8px;padding:6px 8px 0;font-size:10px;color:var(--t3);font-family:var(--fm)}
.trend-label{flex:1;text-align:center;min-width:30px}

/* PROGRESS RING  */
.pring{display:inline-block;position:relative;width:52px;height:52px}
.pring svg{transform:rotate(-90deg)}
.pring .val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:11px;font-family:var(--fm);font-weight:600}

/* LOADING */
.loading{display:flex;align-items:center;justify-content:center;gap:12px;padding:50px;color:var(--t3);font-family:var(--fm);font-size:13px}
.spin{width:20px;height:20px;border:2px solid var(--bor);border-top-color:var(--gold);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* TOAST */
#toast{
    position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:8px;
}
.toast-item{
    display:flex;align-items:center;gap:10px;padding:12px 18px;
    border-radius:12px;font-size:13px;font-weight:500;min-width:220px;max-width:340px;
    background:var(--bg3);border:1px solid var(--bor);box-shadow:var(--sh);
    animation:slideIn .3s ease;
}
.toast-item.success{border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.12)}
.toast-item.error{border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.12)}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}

/* SUMMARY DONUT */
.donut-wrap{display:flex;gap:32px;align-items:center;flex-wrap:wrap;padding:20px}
.donut-legend{display:flex;flex-direction:column;gap:8px;flex:1;min-width:160px}
.donut-leg-item{display:flex;align-items:center;gap:8px;font-size:13px}
.donut-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

/* FINANCIAL TOTALS */
.fin-totals{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;padding:18px 20px;background:rgba(232,184,75,.04);border-bottom:1px solid var(--bor)}
.fin-tot-item{text-align:center}
.fin-tot-val{font-family:var(--fh);font-size:22px;font-weight:800;margin-bottom:3px}
.fin-tot-lab{font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;font-family:var(--fm)}

/* PRINT STYLES */
@media print {
    body{background:#fff;color:#111;font-size:12px}
    #topbar,#sidebar,#toast,.filter-bar,.report-tabs,.no-print{display:none!important}
    #main{margin:0;padding:0}
    .print-header{display:block!important}
    .sec-card{break-inside:avoid;page-break-inside:avoid;border:1px solid #ccc;border-radius:0;margin-bottom:16px}
    .sec-card-head{background:#f5f5f5!important;border-bottom:1px solid #ccc}
    th{background:#f0f0f0!important;color:#333!important}
    td{color:#111!important}
    .badge{border:1px solid #999;background:none!important}
    .sum-card{border:1px solid #ccc;border-radius:0}
    .sc-val,.fin-tot-val{color:#111!important;-webkit-text-fill-color:#111!important}
    .page-title{color:#111!important;-webkit-text-fill-color:#111!important;font-size:22px}
    table{min-width:100%}
    .report-panel{display:block!important}
    .print-only-active ~ .report-panel{display:none!important}
    a{color:#111}
    .trend-chart,.donut-wrap svg{display:none}
    @page{margin:18mm;size:A4}
}
.print-header{
    display:none;
    text-align:center;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #ccc;
}
.print-header h1{font-size:20px;font-weight:800;margin-bottom:4px}
.print-header p{font-size:12px;color:#555}

/* RESPONSIVE */
@media(max-width:768px){
    .sum-grid{grid-template-columns:repeat(2,1fr)}
    .filter-bar{flex-direction:column}
    .filter-group{min-width:100%}
    .report-tabs{gap:4px}
    .rtab{font-size:11px;padding:7px 10px}
}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="orb o1"></div>
<div class="orb o2"></div>

<!-- ══ TOPBAR ══ -->
<header id="topbar">
    <div class="topbar-logo">
        <div class="topbar-logo-icon">⚙</div>
        <div>
            <div class="topbar-logo-text">BUSIQUIP</div>
            <div class="topbar-logo-sub">Fault Management</div>
        </div>
    </div>
    <div class="topbar-breadcrumb">
        <span style="color:var(--t3)">Admin</span>
        <span style="color:var(--t3)"> / </span>
        <span>Reports &amp; Analytics</span>
    </div>
    <div class="topbar-right">
        <div class="pill live"><div class="dot"></div>Live</div>
        <div id="clock" style="font-family:var(--fm)">--:--:--</div>
        <button class="back-btn" onclick="window.location.href='admin_dashboard.php'">
            <i class="fas fa-arrow-left"></i> Dashboard
        </button>
    </div>
</header>

<!-- ══ SIDEBAR ══ -->
<nav id="sidebar">
    <span class="nav-group-label">Reports</span>
    <div class="nav-item active" onclick="showReport('summary')"><div class="ni">📊</div>Overview Summary</div>
    <div class="nav-item" onclick="showReport('faults')"><div class="ni"⚠️</div>Fault Details</div>
    <div class="nav-item" onclick="showReport('technicians')"><div class="ni">🔧</div>Technician Performance</div>
    <div class="nav-item" onclick="showReport('clients')"><div class="ni">🏢</div>Client Report</div>
    <div class="nav-divider"></div>
    <span class="nav-group-label">Financial</span>
    <div class="nav-item" onclick="showReport('financial')"><div class="ni">🧾</div>Invoice Report</div>
    <div class="nav-item" onclick="showReport('payments')"><div class="ni">💳</div>Payment Report</div>
    <div class="nav-divider"></div>
    <span class="nav-group-label">Analytics</span>
    <div class="nav-item" onclick="showReport('categories')"><div class="ni">🏷️</div>Category Breakdown</div>
    <div class="nav-item" onclick="showReport('trend')"><div class="ni">📈</div>Monthly Trends</div>
    <div class="nav-item" onclick="showReport('sla')"><div class="ni">⏱️</div>SLA &amp; Response</div>
    <div class="nav-item" onclick="showReport('worklogs')"><div class="ni">📋</div>Work Logs</div>
</nav>

<!-- ══ MAIN ══ -->
<main id="main">

  <!-- PRINT HEADER (visible only when printing) -->
  <div class="print-header">
    <h1>BUSIQUIP Fault Management — Report</h1>
    <p id="print-meta">Generated: <?= date('d M Y H:i') ?> | Admin: <?= htmlspecialchars($admin_username) ?></p>
  </div>

  <!-- PAGE HEADER -->
  <div class="page-header no-print">
    <div class="page-title" id="page-title">📊 Overview Summary Report</div>
    <div class="page-sub" id="page-sub">High-level system performance snapshot</div>
  </div>

  <!-- REPORT TABS (top nav alternative) -->
  <div class="report-tabs no-print" id="rtabs">
    <button class="rtab active" onclick="showReport('summary')"><i class="fas fa-chart-pie"></i> Summary</button>
    <button class="rtab" onclick="showReport('faults')"><i class="fas fa-exclamation-triangle"></i> Faults</button>
    <button class="rtab" onclick="showReport('technicians')"><i class="fas fa-tools"></i> Technicians</button>
    <button class="rtab" onclick="showReport('clients')"><i class="fas fa-building"></i> Clients</button>
    <button class="rtab" onclick="showReport('financial')"><i class="fas fa-file-invoice-dollar"></i> Invoices</button>
    <button class="rtab" onclick="showReport('payments')"><i class="fas fa-credit-card"></i> Payments</button>
    <button class="rtab" onclick="showReport('categories')"><i class="fas fa-tags"></i> Categories</button>
    <button class="rtab" onclick="showReport('trend')"><i class="fas fa-chart-line"></i> Trends</button>
    <button class="rtab" onclick="showReport('sla')"><i class="fas fa-clock"></i> SLA</button>
    <button class="rtab" onclick="showReport('worklogs')"><i class="fas fa-clipboard-list"></i> Work Logs</button>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar no-print" id="filter-bar">
    <div class="filter-group">
      <label><i class="fas fa-calendar-alt"></i> Date From</label>
      <input type="date" id="f-from">
    </div>
    <div class="filter-group">
      <label><i class="fas fa-calendar-alt"></i> Date To</label>
      <input type="date" id="f-to">
    </div>
    <div class="filter-group" id="fg-status">
      <label>Status</label>
      <select id="f-status">
        <option value="">All Statuses</option>
        <option>Pending</option><option>Assigned</option><option>In Progress</option>
        <option>Completed</option><option>Verified</option><option>Client Approved</option>
        <option>Rework Required</option><option>Rejected</option><option>Closed</option>
      </select>
    </div>
    <div class="filter-group" id="fg-priority">
      <label>Priority</label>
      <select id="f-priority">
        <option value="">All Priorities</option>
        <option>Critical</option><option>High</option><option>Medium</option><option>Low</option>
      </select>
    </div>
    <div class="filter-group" id="fg-client">
      <label>Client</label>
      <select id="f-client"><option value="">All Clients</option></select>
    </div>
    <div class="filter-group" id="fg-tech">
      <label>Technician</label>
      <select id="f-tech"><option value="">All Technicians</option></select>
    </div>
    <div class="filter-group" id="fg-category">
      <label>Category</label>
      <select id="f-category"><option value="">All Categories</option></select>
    </div>
    <div class="filter-group" id="fg-method">
      <label>Payment Method</label>
      <select id="f-method">
        <option value="">All Methods</option>
        <option>Mobile Payment</option><option>Bank Transfer</option><option>Wallet</option><option>Cash</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn btn-primary" onclick="runReport()"><i class="fas fa-search"></i> Generate</button>
      <button class="btn btn-outline" onclick="clearFilters()"><i class="fas fa-times"></i> Clear</button>
      <button class="btn btn-gold" onclick="printReport()"><i class="fas fa-print"></i> Print</button>
      <button class="btn btn-teal" onclick="exportCSV()"><i class="fas fa-download"></i> CSV</button>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 1: SUMMARY OVERVIEW
  ═══════════════════════════════════════════ -->
  <div id="panel-summary" class="report-panel active">
    <div id="summary-content">
      <div class="loading"><div class="spin"></div>Loading summary...</div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 2: FAULT DETAILS
  ═══════════════════════════════════════════ -->
  <div id="panel-faults" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-exclamation-triangle c-warn"></i>
        <h3>Fault Detail Report</h3>
        <span class="hbadge" id="fault-count-badge">—</span>
      </div>
      <div class="tbl-wrap">
        <table id="faults-table">
          <thead><tr>
            <th>#ID</th><th>Reported</th><th>Client</th><th>Category</th>
            <th>Priority</th><th>Status</th><th>Technician(s)</th>
            <th>Invoice #</th><th>Invoice Total</th><th>Paid</th><th>Assign Days</th>
          </tr></thead>
          <tbody id="faults-tbody"><tr><td colspan="11" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
      <div class="tbl-footer">
        <span class="tbl-count" id="faults-count">—</span>
        <span style="font-size:12px;color:var(--t3);font-family:var(--fm)">Max 500 rows</span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 3: TECHNICIAN PERFORMANCE
  ═══════════════════════════════════════════ -->
  <div id="panel-technicians" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-tools c-blue"></i>
        <h3>Technician Performance Report</h3>
        <span class="hbadge" id="tech-count-badge">—</span>
      </div>
      <div class="tbl-wrap">
        <table id="tech-table">
          <thead><tr>
            <th>Technician</th><th>Email</th>
            <th>Assigned</th><th>Completed</th><th>In Progress</th><th>Reworks</th><th>Closed</th>
            <th>Completion Rate</th><th>Total Hours</th><th>Logs</th><th>Transport Cost</th>
          </tr></thead>
          <tbody id="tech-tbody"><tr><td colspan="11" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 4: CLIENT REPORT
  ═══════════════════════════════════════════ -->
  <div id="panel-clients" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-building c-teal"></i>
        <h3>Client Summary Report</h3>
        <span class="hbadge" id="client-count-badge">—</span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>#</th><th>Company</th><th>Contact</th><th>Email</th>
            <th>Total Faults</th><th>Pending</th><th>Active</th><th>Resolved</th><th>Rejected</th><th>Critical</th>
            <th>Invoices</th><th>Total Billed</th><th>Total Paid</th><th>Outstanding</th>
          </tr></thead>
          <tbody id="client-tbody"><tr><td colspan="14" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 5: FINANCIAL / INVOICE
  ═══════════════════════════════════════════ -->
  <div id="panel-financial" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-file-invoice-dollar c-gold"></i>
        <h3>Invoice &amp; Financial Report</h3>
        <span class="hbadge" id="fin-count-badge">—</span>
      </div>
      <div class="fin-totals" id="fin-totals">
        <!-- populated by JS -->
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Invoice #</th><th>Date</th><th>Due Date</th><th>Client</th><th>Fault #</th>
            <th>Category</th><th>Technicians</th>
            <th>Labour</th><th>Materials</th><th>Transport</th><th>Tax</th><th>Total</th>
            <th>Collected</th><th>Status</th><th>Payment Method</th><th>Tx Ref</th>
          </tr></thead>
          <tbody id="fin-tbody"><tr><td colspan="16" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 6: PAYMENT REPORT
  ═══════════════════════════════════════════ -->
  <div id="panel-payments" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-credit-card c-green"></i>
        <h3>Payment Transaction Report</h3>
        <span class="hbadge" id="pay-count-badge">—</span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Pay ID</th><th>Date</th><th>Client</th><th>Invoice #</th><th>Invoice Total</th>
            <th>Amount Paid</th><th>Method</th><th>Status</th><th>Tx Ref</th>
            <th>Fault #</th><th>Category</th><th>Verified By</th><th>Notes</th>
          </tr></thead>
          <tbody id="pay-tbody"><tr><td colspan="13" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
      <div class="tbl-footer">
        <span class="tbl-count" id="pay-total-display">—</span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 7: CATEGORY BREAKDOWN
  ═══════════════════════════════════════════ -->
  <div id="panel-categories" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-tags c-purple"></i>
        <h3>Fault Category Breakdown</h3>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Fault Category</th><th>Total</th><th>Pending</th><th>Active</th><th>Resolved</th>
            <th>Critical</th><th>High</th>
            <th>Avg Response (days)</th><th>Revenue Generated</th><th>Volume</th>
          </tr></thead>
          <tbody id="cat-tbody"><tr><td colspan="10" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 8: MONTHLY TREND
  ═══════════════════════════════════════════ -->
  <div id="panel-trend" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-chart-line c-sky" style="color:var(--sky)"></i>
        <h3>Monthly Fault &amp; Revenue Trends</h3>
      </div>
      <div style="padding:20px">
        <div style="margin-bottom:8px;font-size:12px;color:var(--t3);font-family:var(--fm)">FAULTS REPORTED PER MONTH</div>
        <div class="trend-chart" id="trend-chart-faults"></div>
        <div class="trend-labels" id="trend-labels-faults"></div>
      </div>
      <div style="padding:0 20px 20px">
        <div style="margin-bottom:8px;font-size:12px;color:var(--t3);font-family:var(--fm)">REVENUE PER MONTH (E)</div>
        <div class="trend-chart" id="trend-chart-revenue"></div>
        <div class="trend-labels" id="trend-labels-revenue"></div>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Month</th><th>Faults Reported</th><th>Resolved</th><th>Critical</th>
            <th>Resolution Rate</th><th>Revenue (E)</th>
          </tr></thead>
          <tbody id="trend-tbody"><tr><td colspan="6" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 9: SLA / RESPONSE TIME
  ═══════════════════════════════════════════ -->
  <div id="panel-sla" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-clock c-warn"></i>
        <h3>SLA &amp; Response Time Report</h3>
        <span class="hbadge" id="sla-count-badge">—</span>
      </div>
      <!-- SLA Summary -->
      <div id="sla-summary" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;padding:18px 20px;background:rgba(232,184,75,.04);border-bottom:1px solid var(--bor)"></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Fault #</th><th>Reported</th><th>Client</th><th>Category</th><th>Priority</th>
            <th>SLA Days</th><th>Assign Date</th><th>Due Date</th><th>Technician</th>
            <th>Response Days</th><th>Age Days</th><th>SLA Status</th>
          </tr></thead>
          <tbody id="sla-tbody"><tr><td colspan="12" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       REPORT 10: WORK LOGS
  ═══════════════════════════════════════════ -->
  <div id="panel-worklogs" class="report-panel">
    <div class="sec-card">
      <div class="sec-card-head">
        <i class="fas fa-clipboard-list c-teal"></i>
        <h3>Work Log &amp; Materials Report</h3>
        <span class="hbadge" id="wl-count-badge">—</span>
      </div>
      <!-- Work Log Summary -->
      <div id="wl-summary" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;padding:18px 20px;background:rgba(232,184,75,.04);border-bottom:1px solid var(--bor)"></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>Log #</th><th>Date</th><th>Technician</th><th>Fault #</th><th>Client</th>
            <th>Category</th><th>Status</th><th>Priority</th>
            <th>Hours Worked</th><th>Transport Cost</th><th>Materials Used</th><th>Notes</th>
          </tr></thead>
          <tbody id="wl-tbody"><tr><td colspan="12" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

</main>

<div id="toast"></div>

<script>
// ══════════════════════════════════════════════════
//  BUSIQUIP — ADMIN REPORTS JS
// ══════════════════════════════════════════════════

const BASE = window.location.pathname;
let currentReport = 'summary';
let lastData = null; // for CSV export

// ── CLOCK ─────────────────────────────────────────
function updateClock(){
    const d=new Date();
    document.getElementById('clock').textContent=d.toLocaleTimeString('en-GB',{hour12:false});
}
setInterval(updateClock,1000); updateClock();

// ── TOAST ─────────────────────────────────────────
function toast(msg,type='info'){
    const el=document.createElement('div');
    el.className='toast-item '+(type==='success'?'success':type==='error'?'error':'');
    el.innerHTML=`<i class="fas fa-${type==='success'?'check-circle':type==='error'?'times-circle':'info-circle'}"></i>${msg}`;
    document.getElementById('toast').appendChild(el);
    setTimeout(()=>el.remove(),3500);
}

// ── API ───────────────────────────────────────────
function api(params){
    const url=BASE+'?'+Object.entries(params).map(([k,v])=>`${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    return fetch(url).then(r=>r.json());
}

// ── FILTERS ───────────────────────────────────────
function getFilters(){
    return {
        from: document.getElementById('f-from').value,
        to:   document.getElementById('f-to').value,
        status:   document.getElementById('f-status').value,
        priority: document.getElementById('f-priority').value,
        client_id: document.getElementById('f-client').value,
        tech_id:   document.getElementById('f-tech').value,
        category:  document.getElementById('f-category').value,
        method:    document.getElementById('f-method').value,
    };
}
function clearFilters(){
    ['f-from','f-to','f-status','f-priority','f-client','f-tech','f-category','f-method'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.value='';
    });
    runReport();
}

// ── SHOW FILTER FIELDS PER REPORT ────────────────
const filterMap = {
    summary:     ['fg-client'],
    faults:      ['fg-status','fg-priority','fg-client','fg-tech','fg-category'],
    technicians: ['fg-tech'],
    clients:     ['fg-client'],
    financial:   ['fg-status','fg-client'],
    payments:    ['fg-client','fg-method','fg-status'],
    categories:  [],
    trend:       [],
    sla:         ['fg-status','fg-priority'],
    worklogs:    ['fg-tech'],
};
const reportMeta = {
    summary:     {title:'📊 Overview Summary Report',sub:'High-level system performance snapshot'},
    faults:      {title:'⚠️ Fault Detail Report',sub:'All reported faults with full information'},
    technicians: {title:'🔧 Technician Performance Report',sub:'Workload and completion metrics per technician'},
    clients:     {title:'🏢 Client Report',sub:'Fault and billing summary per client'},
    financial:   {title:'🧾 Invoice & Financial Report',sub:'All invoices with cost breakdown and payment status'},
    payments:    {title:'💳 Payment Transaction Report',sub:'All payment records with verification details'},
    categories:  {title:'🏷️ Fault Category Breakdown',sub:'Volume and revenue per fault type'},
    trend:       {title:'📈 Monthly Trend Report',sub:'Fault volume and revenue trends over time'},
    sla:         {title:'⏱️ SLA & Response Time Report',sub:'Assignment response times and SLA compliance'},
    worklogs:    {title:'📋 Work Log & Materials Report',sub:'Technician work logs, hours and materials'},
};

// ── NAVIGATION ────────────────────────────────────
function showReport(name){
    currentReport = name;
    // panels
    document.querySelectorAll('.report-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+name)?.classList.add('active');
    // tabs
    document.querySelectorAll('.rtab').forEach(t=>t.classList.remove('active'));
    const tIdx = ['summary','faults','technicians','clients','financial','payments','categories','trend','sla','worklogs'].indexOf(name);
    document.querySelectorAll('.rtab')[tIdx]?.classList.add('active');
    // sidebar
    document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
    document.querySelectorAll('.nav-item')[tIdx]?.classList.add('active');
    // filter visibility
    ['fg-status','fg-priority','fg-client','fg-tech','fg-category','fg-method'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.style.display = (filterMap[name]||[]).includes(id)?'flex':'none';
    });
    // meta
    const m=reportMeta[name]||{};
    document.getElementById('page-title').textContent=m.title||name;
    document.getElementById('page-sub').textContent=m.sub||'';
    document.title=`${m.title||name} — BUSIQUIP`;
    document.getElementById('print-meta').textContent=
        `Report: ${m.title||name} | Generated: ${new Date().toLocaleString()} | Filters applied`;

    runReport();
}

function runReport(){
    switch(currentReport){
        case 'summary':     loadSummary();      break;
        case 'faults':      loadFaults();       break;
        case 'technicians': loadTechnicians();  break;
        case 'clients':     loadClients();      break;
        case 'financial':   loadFinancial();    break;
        case 'payments':    loadPayments();     break;
        case 'categories':  loadCategories();   break;
        case 'trend':       loadTrend();        break;
        case 'sla':         loadSLA();          break;
        case 'worklogs':    loadWorklogs();     break;
    }
}

// ── HELPERS ──────────────────────────────────────
const M=(v,d=2)=>v!==null&&v!==undefined?parseFloat(v).toLocaleString('en-ZA',{minimumFractionDigits:d,maximumFractionDigits:d}):'0.00';
const N=(v)=>v!=null?parseInt(v).toLocaleString():'0';
const fmtDate=v=>v?new Date(v).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'—';

function statusBadge(s){
    const map={
        'Pending':'badge-pending','Assigned':'badge-assigned','In Progress':'badge-inprogress',
        'Completed':'badge-completed','Verified':'badge-verified','Client Approved':'badge-approved',
        'Closed':'badge-closed','Rejected':'badge-rejected','Rework Required':'badge-rework',
        'Paid':'badge-paid','Pending Payment':'badge-pending-pay',
        'Overdue':'badge-overdue','On Track':'badge-ontrack','Due Soon':'badge-duesoon','Resolved':'badge-completed',
        'No Due Date':'badge-rework',
    };
    return `<span class="badge ${map[s]||''}">${s||'—'}</span>`;
}
function prioBadge(p){
    const cl={'Critical':'prio-critical','High':'prio-high','Medium':'prio-medium','Low':'prio-low'};
    return `<span class="${cl[p]||''}">${p||'—'}</span>`;
}
function miniBar(val,max,color){
    const pct=max?Math.min(100,(val/max)*100):0;
    return `<div class="mini-bar-wrap"><div class="mini-bar-bg"><div class="mini-bar" style="width:${pct}%;background:${color}"></div></div><span class="mini-val">${N(val)}</span></div>`;
}
function progressRing(pct,color='var(--em)'){
    const r=20,c=2*Math.PI*r,dash=(pct/100)*c;
    return `<div class="pring"><svg width="52" height="52" viewBox="0 0 52 52">
        <circle cx="26" cy="26" r="${r}" fill="none" stroke="var(--bg3)" stroke-width="5"/>
        <circle cx="26" cy="26" r="${r}" fill="none" stroke="${color}" stroke-width="5"
            stroke-dasharray="${dash} ${c}" stroke-linecap="round"/>
    </svg><div class="val" style="color:${color}">${pct||0}%</div></div>`;
}

// ═══════════════════════════════════════════════════
// 1. SUMMARY REPORT
// ═══════════════════════════════════════════════════
function loadSummary(){
    const f=getFilters();
    const el=document.getElementById('summary-content');
    el.innerHTML='<div class="loading"><div class="spin"></div>Loading summary...</div>';
    api({ajax:'report_summary',...f}).then(d=>{
        const total=parseInt(d.total_faults)||1;
        const resolvedPct=Math.round(((parseInt(d.completed||0)+parseInt(d.verified||0)+parseInt(d.closed||0)+parseInt(d.client_approved||0))/total)*100);
        el.innerHTML=`
        <!-- KEY METRICS -->
        <div class="sum-grid">
          <div class="sum-card"><div class="sc-icon">⚠️</div><div class="sc-val c-gold">${N(d.total_faults)}</div><div class="sc-lab">Total Faults</div></div>
          <div class="sum-card"><div class="sc-icon">⏳</div><div class="sc-val c-warn">${N(d.pending)}</div><div class="sc-lab">Pending</div></div>
          <div class="sum-card"><div class="sc-icon">🔧</div><div class="sc-val c-blue">${N(d.in_progress)}</div><div class="sc-lab">In Progress</div></div>
          <div class="sum-card"><div class="sc-icon">✅</div><div class="sc-val c-green">${N(parseInt(d.completed||0)+parseInt(d.verified||0)+parseInt(d.closed||0)+parseInt(d.client_approved||0))}</div><div class="sc-lab">Resolved</div></div>
          <div class="sum-card"><div class="sc-icon">❌</div><div class="sc-val c-red">${N(d.rejected)}</div><div class="sc-lab">Rejected</div></div>
          <div class="sum-card"><div class="sc-icon">🔁</div><div class="sc-val c-warn">${N(d.rework)}</div><div class="sc-lab">Rework</div></div>
          <div class="sum-card"><div class="sc-icon">🏢</div><div class="sc-val c-teal">${N(d.active_clients)}</div><div class="sc-lab">Active Clients</div></div>
          <div class="sum-card"><div class="sc-icon">👷</div><div class="sc-val c-purple">${N(d.active_techs)}</div><div class="sc-lab">Active Techs</div></div>
          <div class="sum-card"><div class="sc-icon">🧾</div><div class="sc-val c-gold">${N(d.total_invoices)}</div><div class="sc-lab">Invoices</div></div>
          <div class="sum-card"><div class="sc-icon">💰</div><div class="sc-val c-green">E ${M(d.total_collected)}</div><div class="sc-lab">Revenue Collected</div></div>
          <div class="sum-card"><div class="sc-icon">📋</div><div class="sc-val c-sky">E ${M(d.total_invoiced)}</div><div class="sc-lab">Total Invoiced</div></div>
          <div class="sum-card"><div class="sc-icon">⚡</div><div class="sc-val c-red">E ${M(d.outstanding)}</div><div class="sc-lab">Outstanding</div></div>
        </div>

        <!-- DUAL DETAIL GRID -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

          <!-- Fault Status Breakdown -->
          <div class="sec-card">
            <div class="sec-card-head"><i class="fas fa-chart-pie c-gold"></i><h3>Fault Status Breakdown</h3></div>
            <div style="padding:18px;display:flex;flex-direction:column;gap:10px">
              ${renderStatusBars(d,total)}
            </div>
          </div>

          <!-- Priority Breakdown -->
          <div class="sec-card">
            <div class="sec-card-head"><i class="fas fa-exclamation c-red"></i><h3>Priority Breakdown</h3></div>
            <div style="padding:18px;display:flex;flex-direction:column;gap:10px">
              ${renderPriorityBars(d,total)}
            </div>
          </div>
        </div>

        <!-- FINANCIAL SUMMARY -->
        <div class="sec-card">
          <div class="sec-card-head"><i class="fas fa-coins c-gold"></i><h3>Financial Summary</h3></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;border-bottom:1px solid var(--bor)">
            ${[
              ['Total Invoiced','E '+M(d.total_invoiced),'c-sky'],
              ['Total Collected','E '+M(d.total_collected),'c-green'],
              ['Outstanding','E '+M(d.outstanding),'c-red'],
              ['Collection Rate',Math.round((parseFloat(d.total_collected||0)/Math.max(parseFloat(d.total_invoiced||1),1))*100)+'%','c-gold'],
            ].map(([lab,val,col])=>`
              <div style="padding:20px 24px;border-right:1px solid var(--bor);border-bottom:1px solid var(--bor)">
                <div style="font-size:12px;color:var(--t3);font-family:var(--fm);margin-bottom:8px;text-transform:uppercase">${lab}</div>
                <div style="font-family:var(--fh);font-size:26px;font-weight:800" class="${col}">${val}</div>
              </div>`).join('')}
          </div>
        </div>

        <!-- RESOLUTION PERFORMANCE -->
        <div class="sec-card" style="margin-top:20px">
          <div class="sec-card-head"><i class="fas fa-trophy c-green"></i><h3>Resolution Performance</h3></div>
          <div style="padding:24px;display:flex;align-items:center;gap:40px;flex-wrap:wrap">
            ${progressRing(resolvedPct,'var(--em)')}
            <div>
              <div style="font-size:28px;font-weight:800;font-family:var(--fh);color:var(--em)">${resolvedPct}%</div>
              <div style="font-size:13px;color:var(--t2);margin-top:4px">Overall Resolution Rate</div>
              <div style="font-size:12px;color:var(--t3);margin-top:8px;font-family:var(--fm)">
                ${N(parseInt(d.completed||0)+parseInt(d.verified||0)+parseInt(d.closed||0)+parseInt(d.client_approved||0))} resolved of ${N(d.total_faults)} total faults
              </div>
            </div>
            <div style="flex:1;min-width:200px">
              <div style="font-size:11px;color:var(--t3);font-family:var(--fm);margin-bottom:12px;text-transform:uppercase">Avg Days to Assign</div>
              <div style="font-size:36px;font-weight:800;font-family:var(--fh);color:var(--sky)">${parseFloat(d.avg_resolution_days||0).toFixed(1)}</div>
              <div style="font-size:12px;color:var(--t2)">days average (reported → assigned)</div>
            </div>
          </div>
        </div>`;
    }).catch(()=>{
        el.innerHTML='<div class="tbl-empty">Failed to load summary. Check database connection.</div>';
    });
}

function renderStatusBars(d,total){
    const items=[
        ['Pending',d.pending,'var(--warn)'],
        ['Assigned',d.assigned,'var(--sky)'],
        ['In Progress',d.in_progress,'var(--ind)'],
        ['Completed',d.completed,'var(--em)'],
        ['Client Approved',d.client_approved,'var(--teal)'],
        ['Verified',d.verified,'var(--teal)'],
        ['Rework',d.rework,'var(--gold)'],
        ['Closed',d.closed,'#555'],
        ['Rejected',d.rejected,'var(--dan)'],
    ];
    return items.map(([lab,val,col])=>`
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:110px;font-size:12px;color:var(--t2);text-align:right">${lab}</div>
        <div style="flex:1">${miniBar(val||0,total,col)}</div>
        <div style="width:40px;font-size:11px;color:var(--t3);font-family:var(--fm);text-align:right">${total?Math.round(((val||0)/total)*100):0}%</div>
      </div>`).join('');
}
function renderPriorityBars(d,total){
    return [
        ['Critical',d.p_critical,'var(--dan)'],
        ['High',d.p_high,'var(--warn)'],
        ['Medium',d.p_medium,'var(--sky)'],
        ['Low',d.p_low,'var(--em)'],
    ].map(([lab,val,col])=>`
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:70px;font-size:12px;color:var(--t2);text-align:right">${lab}</div>
        <div style="flex:1">${miniBar(val||0,total,col)}</div>
        <div style="width:40px;font-size:11px;color:var(--t3);font-family:var(--fm);text-align:right">${total?Math.round(((val||0)/total)*100):0}%</div>
      </div>`).join('');
}

// ═══════════════════════════════════════════════════
// 2. FAULT DETAILS
// ═══════════════════════════════════════════════════
function loadFaults(){
    const f=getFilters();
    const tbody=document.getElementById('faults-tbody');
    tbody.innerHTML='<tr><td colspan="11" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading faults...</div></td></tr>';
    api({ajax:'report_faults',...f}).then(rows=>{
        lastData={type:'faults',rows};
        document.getElementById('fault-count-badge').textContent=rows.length+' records';
        document.getElementById('faults-count').textContent=`Showing ${rows.length} faults`;
        if(!rows.length){tbody.innerHTML='<tr><td colspan="11" class="tbl-empty">No faults match the selected filters</td></tr>';return;}
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-mono">#${r.REP_FAULT_ID}</td>
          <td class="td-mono">${fmtDate(r.REPORT_DATE)}</td>
          <td class="td-bold">${r.COMPANY_NAME||'—'}<br><span style="font-size:11px;color:var(--t3)">${r.CONTACT_PERSON_NAME||''}</span></td>
          <td>${r.FAULT_TYPE||'—'}</td>
          <td>${prioBadge(r.PRIORITY)}</td>
          <td>${statusBadge(r.STATUS)}</td>
          <td style="font-size:12px;color:var(--t2)">${r.TECHNICIANS||'—'}</td>
          <td class="td-mono">${r.INVOICE_NO||'—'}</td>
          <td class="td-mono">${r.INVOICE_TOTAL?'E '+M(r.INVOICE_TOTAL):'—'}</td>
          <td class="td-mono ${parseFloat(r.PAID_AMOUNT)>0?'c-green':''}">${parseFloat(r.PAID_AMOUNT)>0?'E '+M(r.PAID_AMOUNT):'—'}</td>
          <td class="td-mono">${r.days_to_assign!=null?r.days_to_assign+' d':'—'}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 3. TECHNICIANS
// ═══════════════════════════════════════════════════
function loadTechnicians(){
    const f=getFilters();
    const tbody=document.getElementById('tech-tbody');
    tbody.innerHTML='<tr><td colspan="11" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_technicians',...f}).then(rows=>{
        lastData={type:'technicians',rows};
        document.getElementById('tech-count-badge').textContent=rows.length+' technicians';
        if(!rows.length){tbody.innerHTML='<tr><td colspan="11" class="tbl-empty">No technicians found</td></tr>';return;}
        const maxAssigned=Math.max(...rows.map(r=>parseInt(r.total_assigned)||0),1);
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-bold">${r.FULL_NAME}</td>
          <td style="font-size:12px;color:var(--t2)">${r.EMAIL||'—'}</td>
          <td>${miniBar(r.total_assigned||0,maxAssigned,'var(--sky)')}</td>
          <td class="c-green td-bold">${N(r.completed)}</td>
          <td class="c-purple">${N(r.in_progress)}</td>
          <td class="c-warn">${N(r.rework)}</td>
          <td class="c-teal">${N(r.closed)}</td>
          <td>${progressRing(parseFloat(r.completion_rate||0).toFixed(0),'var(--em)')}</td>
          <td class="td-mono">${parseFloat(r.total_hours||0).toFixed(1)} hrs</td>
          <td class="td-mono">${N(r.total_logs)}</td>
          <td class="td-mono c-warn">E ${M(r.total_transport)}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 4. CLIENTS
// ═══════════════════════════════════════════════════
function loadClients(){
    const f=getFilters();
    const tbody=document.getElementById('client-tbody');
    tbody.innerHTML='<tr><td colspan="14" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_clients',...f}).then(rows=>{
        lastData={type:'clients',rows};
        document.getElementById('client-count-badge').textContent=rows.length+' clients';
        if(!rows.length){tbody.innerHTML='<tr><td colspan="14" class="tbl-empty">No clients found</td></tr>';return;}
        tbody.innerHTML=rows.map((r,i)=>`<tr>
          <td class="td-mono">${i+1}</td>
          <td class="td-bold">${r.COMPANY_NAME||'—'}</td>
          <td style="font-size:12px">${r.CONTACT_PERSON_NAME||'—'}</td>
          <td style="font-size:12px;color:var(--t2)">${r.COMPANY_EMAIL||'—'}</td>
          <td class="td-bold c-gold">${N(r.total_faults)}</td>
          <td class="c-warn">${N(r.pending)}</td>
          <td class="c-blue" style="color:var(--sky)">${N(r.active)}</td>
          <td class="c-green">${N(r.resolved)}</td>
          <td class="c-red">${N(r.rejected)}</td>
          <td class="c-red">${N(r.critical_faults)}</td>
          <td class="td-mono">${N(r.invoices)}</td>
          <td class="td-mono">E ${M(r.total_billed)}</td>
          <td class="td-mono c-green">E ${M(r.total_paid)}</td>
          <td class="td-mono c-red">E ${M(r.outstanding)}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 5. FINANCIAL
// ═══════════════════════════════════════════════════
function loadFinancial(){
    const f=getFilters();
    const tbody=document.getElementById('fin-tbody');
    tbody.innerHTML='<tr><td colspan="16" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_financial',...f}).then(data=>{
        const rows=data.rows||[];
        const t=data.totals||{};
        lastData={type:'financial',rows,totals:t};
        document.getElementById('fin-count-badge').textContent=rows.length+' invoices';

        // Totals bar
        document.getElementById('fin-totals').innerHTML=[
            ['Total Invoiced','E '+M(t.grand_total),'c-sky'],
            ['Labour','E '+M(t.total_labour),'c-blue'],
            ['Materials','E '+M(t.total_materials),'c-purple'],
            ['Transport','E '+M(t.total_transport),'c-warn'],
            ['Tax','E '+M(t.total_tax),'c-gray'],
            ['Collected','E '+M(t.total_collected),'c-green'],
            ['Outstanding','E '+M(t.total_outstanding),'c-red'],
        ].map(([lab,val,col])=>`
          <div class="fin-tot-item">
            <div class="fin-tot-val ${col}">${val}</div>
            <div class="fin-tot-lab">${lab}</div>
          </div>`).join('');

        if(!rows.length){tbody.innerHTML='<tr><td colspan="16" class="tbl-empty">No invoices match filters</td></tr>';return;}
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-mono td-bold">${r.INVOICE_NO||'—'}</td>
          <td class="td-mono">${fmtDate(r.INVOICE_DATE)}</td>
          <td class="td-mono">${fmtDate(r.DUE_DATE)}</td>
          <td class="td-bold">${r.COMPANY_NAME||'—'}</td>
          <td class="td-mono">#${r.REP_FAULT_ID||'—'}</td>
          <td>${r.FAULT_TYPE||'—'}</td>
          <td style="font-size:12px;color:var(--t2)">${r.TECHNICIANS||'—'}</td>
          <td class="td-mono">E ${M(r.LABOUR_COST)}</td>
          <td class="td-mono">E ${M(r.MATERIAL_COST)}</td>
          <td class="td-mono">E ${M(r.TRANSPORT_COST)}</td>
          <td class="td-mono">E ${M(r.TAX_AMOUNT)}</td>
          <td class="td-mono td-bold c-gold">E ${M(r.TOTAL)}</td>
          <td class="td-mono c-green">E ${M(r.collected)}</td>
          <td>${statusBadge(r.INV_STATUS)}</td>
          <td style="font-size:12px">${r.PAYMENT_METHOD||'—'}</td>
          <td class="td-mono" style="font-size:11px;color:var(--t3)">${r.TRANSACTION_REF||'—'}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 6. PAYMENTS
// ═══════════════════════════════════════════════════
function loadPayments(){
    const f=getFilters();
    const tbody=document.getElementById('pay-tbody');
    tbody.innerHTML='<tr><td colspan="13" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_payments',...f}).then(rows=>{
        lastData={type:'payments',rows};
        document.getElementById('pay-count-badge').textContent=rows.length+' transactions';
        let total=0;
        rows.forEach(r=>total+=parseFloat(r.AMOUNT_PAID||0));
        document.getElementById('pay-total-display').textContent=`${rows.length} records | Total: E ${M(total)}`;
        if(!rows.length){tbody.innerHTML='<tr><td colspan="13" class="tbl-empty">No payments found</td></tr>';return;}
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-mono">#${r.PAYMENT_ID}</td>
          <td class="td-mono">${fmtDate(r.PAYMENT_DATE)}</td>
          <td class="td-bold">${r.COMPANY_NAME||'—'}</td>
          <td class="td-mono">${r.INVOICE_NO||'—'}</td>
          <td class="td-mono">E ${M(r.INVOICE_TOTAL)}</td>
          <td class="td-mono td-bold c-green">E ${M(r.AMOUNT_PAID)}</td>
          <td>${r.PAYMENT_METHOD||'—'}</td>
          <td>${statusBadge(r.PAY_STATUS)}</td>
          <td class="td-mono" style="font-size:11px;color:var(--t3)">${r.TRANSACTION_REF||'—'}</td>
          <td class="td-mono">${r.REP_FAULT_ID?'#'+r.REP_FAULT_ID:'—'}</td>
          <td style="font-size:12px">${r.FAULT_TYPE||'—'}</td>
          <td style="font-size:12px;color:var(--t2)">${r.VERIFIED_BY||'—'}</td>
          <td style="font-size:12px;color:var(--t3)">${r.NOTES||'—'}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 7. CATEGORIES
// ═══════════════════════════════════════════════════
function loadCategories(){
    const f=getFilters();
    const tbody=document.getElementById('cat-tbody');
    tbody.innerHTML='<tr><td colspan="10" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_categories',...f}).then(rows=>{
        lastData={type:'categories',rows};
        if(!rows.length){tbody.innerHTML='<tr><td colspan="10" class="tbl-empty">No categories found</td></tr>';return;}
        const maxTotal=Math.max(...rows.map(r=>parseInt(r.total)||0),1);
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-bold">${r.FAULT_TYPE||'—'}</td>
          <td class="td-bold c-gold">${N(r.total)}</td>
          <td class="c-warn">${N(r.pending)}</td>
          <td style="color:var(--sky)">${N(r.active)}</td>
          <td class="c-green">${N(r.resolved)}</td>
          <td class="c-red">${N(r.critical)}</td>
          <td class="c-warn">${N(r.high)}</td>
          <td class="td-mono">${parseFloat(r.avg_response_days||0).toFixed(1)} d</td>
          <td class="td-mono c-green">E ${M(r.revenue_generated)}</td>
          <td style="min-width:160px">${miniBar(r.total||0,maxTotal,'var(--gold)')}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 8. TREND
// ═══════════════════════════════════════════════════
function loadTrend(){
    const f=getFilters();
    document.getElementById('trend-tbody').innerHTML='<tr><td colspan="6" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_trend',...f}).then(rows=>{
        lastData={type:'trend',rows};
        if(!rows.length){
            document.getElementById('trend-tbody').innerHTML='<tr><td colspan="6" class="tbl-empty">No trend data</td></tr>';
            return;
        }
        const maxF=Math.max(...rows.map(r=>parseInt(r.faults_reported)||0),1);
        const maxR=Math.max(...rows.map(r=>parseFloat(r.revenue)||0),1);

        // Fault chart
        document.getElementById('trend-chart-faults').innerHTML=rows.map(r=>`
          <div class="trend-bar" style="background:var(--sky);opacity:.8;height:${Math.max(4,(parseInt(r.faults_reported)/maxF)*140)}px">
            <div class="trend-bar-tooltip">${r.label}: ${N(r.faults_reported)} faults</div>
          </div>`).join('');
        document.getElementById('trend-labels-faults').innerHTML=rows.map(r=>`<div class="trend-label">${r.label}<br><b style="color:var(--sky)">${N(r.faults_reported)}</b></div>`).join('');

        // Revenue chart
        document.getElementById('trend-chart-revenue').innerHTML=rows.map(r=>`
          <div class="trend-bar" style="background:var(--em);opacity:.8;height:${Math.max(4,(parseFloat(r.revenue)/maxR)*140)}px">
            <div class="trend-bar-tooltip">${r.label}: E ${M(r.revenue)}</div>
          </div>`).join('');
        document.getElementById('trend-labels-revenue').innerHTML=rows.map(r=>`<div class="trend-label">${r.label}<br><b style="color:var(--em)">E ${M(r.revenue,0)}</b></div>`).join('');

        // Table
        document.getElementById('trend-tbody').innerHTML=rows.map(r=>{
            const res=parseInt(r.resolved)||0;
            const rep=parseInt(r.faults_reported)||0;
            const rate=rep?Math.round((res/rep)*100):0;
            return `<tr>
              <td class="td-bold">${r.label}</td>
              <td class="td-mono c-gold">${N(r.faults_reported)}</td>
              <td class="td-mono c-green">${N(r.resolved)}</td>
              <td class="td-mono c-red">${N(r.critical)}</td>
              <td>
                <div class="mini-bar-wrap">
                  <div class="mini-bar-bg"><div class="mini-bar" style="width:${rate}%;background:var(--em)"></div></div>
                  <span class="mini-val">${rate}%</span>
                </div>
              </td>
              <td class="td-mono c-green">E ${M(r.revenue)}</td>
            </tr>`;
        }).join('');
    });
}

// ═══════════════════════════════════════════════════
// 9. SLA
// ═══════════════════════════════════════════════════
function loadSLA(){
    const f=getFilters();
    const tbody=document.getElementById('sla-tbody');
    tbody.innerHTML='<tr><td colspan="12" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_sla',...f}).then(rows=>{
        lastData={type:'sla',rows};
        document.getElementById('sla-count-badge').textContent=rows.length+' records';

        // SLA summary
        const cnt={Overdue:0,'On Track':0,'Due Soon':0,Resolved:0,'No Due Date':0};
        rows.forEach(r=>cnt[r.sla_status]=(cnt[r.sla_status]||0)+1);
        document.getElementById('sla-summary').innerHTML=[
            ['Overdue',cnt.Overdue,'c-red','fas fa-exclamation-circle'],
            ['Due Soon',cnt['Due Soon'],'c-warn','fas fa-clock'],
            ['On Track',cnt['On Track'],'c-green','fas fa-check-circle'],
            ['Resolved',cnt.Resolved,'c-teal','fas fa-check-double'],
            ['No Due Date',cnt['No Due Date'],'c-gray','fas fa-minus-circle'],
        ].map(([lab,val,col,ic])=>`
          <div style="text-align:center;padding:12px">
            <i class="fas ${ic} ${col}" style="font-size:20px;margin-bottom:8px;display:block"></i>
            <div style="font-family:var(--fh);font-size:28px;font-weight:800" class="${col}">${val}</div>
            <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-family:var(--fm)">${lab}</div>
          </div>`).join('');

        if(!rows.length){tbody.innerHTML='<tr><td colspan="12" class="tbl-empty">No data</td></tr>';return;}
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-mono">#${r.REP_FAULT_ID}</td>
          <td class="td-mono">${fmtDate(r.REPORT_DATE)}</td>
          <td class="td-bold">${r.COMPANY_NAME||'—'}</td>
          <td>${r.FAULT_TYPE||'—'}</td>
          <td>${prioBadge(r.PRIORITY)}</td>
          <td class="td-mono">${r.DEFAULT_SLA_DAYS!=null?r.DEFAULT_SLA_DAYS+' d':'—'}</td>
          <td class="td-mono">${fmtDate(r.ASSIGN_DATE)}</td>
          <td class="td-mono">${fmtDate(r.DUE_DATE)}</td>
          <td style="font-size:12px;color:var(--t2)">${r.TECHNICIANS||'—'}</td>
          <td class="td-mono ${parseInt(r.response_days)>3?'c-warn':''}">${r.response_days!=null?r.response_days+' d':'—'}</td>
          <td class="td-mono ${parseInt(r.age_days)>14?'c-red':''}">${r.age_days!=null?r.age_days+' d':'—'}</td>
          <td>${statusBadge(r.sla_status)}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// 10. WORK LOGS
// ═══════════════════════════════════════════════════
function loadWorklogs(){
    const f=getFilters();
    const tbody=document.getElementById('wl-tbody');
    tbody.innerHTML='<tr><td colspan="12" class="tbl-empty"><div class="loading"><div class="spin"></div>Loading...</div></td></tr>';
    api({ajax:'report_worklogs',...f}).then(rows=>{
        lastData={type:'worklogs',rows};
        document.getElementById('wl-count-badge').textContent=rows.length+' logs';

        let totalHrs=0,totalTransport=0;
        rows.forEach(r=>{totalHrs+=parseFloat(r.HOURS_WORKED||0);totalTransport+=parseFloat(r.TRANSPORT_COST||0);});

        document.getElementById('wl-summary').innerHTML=[
            ['Total Logs',rows.length,'c-gold','fas fa-clipboard'],
            ['Total Hours',parseFloat(totalHrs).toFixed(1)+' hrs','c-sky','fas fa-hourglass-half'],
            ['Avg Hrs/Log',(rows.length?totalHrs/rows.length:0).toFixed(1)+' hrs','c-teal','fas fa-chart-bar'],
            ['Total Transport','E '+M(totalTransport),'c-warn','fas fa-car'],
        ].map(([lab,val,col,ic])=>`
          <div style="text-align:center;padding:12px">
            <i class="${ic} ${col}" style="font-size:18px;margin-bottom:8px;display:block"></i>
            <div style="font-family:var(--fh);font-size:24px;font-weight:800" class="${col}">${val}</div>
            <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-family:var(--fm)">${lab}</div>
          </div>`).join('');

        if(!rows.length){tbody.innerHTML='<tr><td colspan="12" class="tbl-empty">No work logs found</td></tr>';return;}
        tbody.innerHTML=rows.map(r=>`<tr>
          <td class="td-mono">#${r.LOG_ID}</td>
          <td class="td-mono">${fmtDate(r.LOG_DATE)}</td>
          <td class="td-bold">${r.TECHNICIAN||'—'}</td>
          <td class="td-mono">#${r.REP_FAULT_ID}</td>
          <td>${r.COMPANY_NAME||'—'}</td>
          <td>${r.FAULT_TYPE||'—'}</td>
          <td>${statusBadge(r.FAULT_STATUS)}</td>
          <td>${prioBadge(r.PRIORITY)}</td>
          <td class="td-mono c-sky">${parseFloat(r.HOURS_WORKED||0).toFixed(1)} hrs</td>
          <td class="td-mono c-warn">E ${M(r.TRANSPORT_COST)}</td>
          <td style="font-size:12px;color:var(--t2);max-width:200px">${r.MATERIALS_USED||'—'}</td>
          <td style="font-size:12px;color:var(--t3);max-width:220px">${r.NOTES||'—'}</td>
        </tr>`).join('');
    });
}

// ═══════════════════════════════════════════════════
// PRINT
// ═══════════════════════════════════════════════════
function printReport(){
    window.print();
}

// ═══════════════════════════════════════════════════
// CSV EXPORT
// ═══════════════════════════════════════════════════
function exportCSV(){
    if(!lastData||!lastData.rows||!lastData.rows.length){toast('No data to export','error');return;}
    const rows=lastData.rows;
    const keys=Object.keys(rows[0]);
    const csv=[keys.join(','),...rows.map(r=>keys.map(k=>{
        const v=r[k]!=null?String(r[k]).replace(/"/g,'""'):'';
        return /[",\n]/.test(v)?`"${v}"`:v;
    }).join(','))].join('\n');
    const blob=new Blob([csv],{type:'text/csv'});
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download=`busiquip_${lastData.type}_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    toast('CSV exported successfully','success');
}

// ═══════════════════════════════════════════════════
// INIT — LOAD FILTER DROPDOWNS
// ═══════════════════════════════════════════════════
async function initFilters(){
    // Clients
    const clients=await api({ajax:'filter_clients'});
    const cs=document.getElementById('f-client');
    clients.forEach(c=>cs.insertAdjacentHTML('beforeend',`<option value="${c.CLIENT_ID}">${c.COMPANY_NAME}</option>`));
    // Techs
    const techs=await api({ajax:'filter_techs'});
    const ts=document.getElementById('f-tech');
    techs.forEach(t=>ts.insertAdjacentHTML('beforeend',`<option value="${t.EMP_ID}">${t.FULL_NAME}</option>`));
    // Categories
    const cats=await api({ajax:'filter_categories'});
    const cats_el=document.getElementById('f-category');
    cats.forEach(c=>cats_el.insertAdjacentHTML('beforeend',`<option value="${c}">${c}</option>`));
}

// SET DEFAULT DATE RANGE (last 90 days)
(function setDefaultDates(){
    const to=new Date();
    const from=new Date();
    from.setDate(from.getDate()-90);
    document.getElementById('f-to').value=to.toISOString().slice(0,10);
    document.getElementById('f-from').value=from.toISOString().slice(0,10);
})();

document.addEventListener('DOMContentLoaded',()=>{
    initFilters().then(()=>showReport('summary'));
    // Mobile sidebar
    if(window.innerWidth<=1024){
        const btn=document.createElement('button');
        btn.innerHTML='☰';
        btn.style.cssText='background:none;border:none;color:var(--t1);font-size:22px;cursor:pointer;padding:4px 8px;';
        btn.onclick=()=>document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('topbar').insertBefore(btn,document.getElementById('topbar').firstChild);
    }
});
</script>
</body>
</html>

