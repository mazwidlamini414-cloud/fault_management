<?php
// ═══════════════════════════════════════════════════════════════════════
//  client_make_payment.php  —  BUSIQUIP ESWATINI  —  Make a Payment
//  Database: busiquip_final
//  Identical payment processing engine to client_invoices.php
//  Session-simulated wallet (E1,000 per session, school project demo)
// ═══════════════════════════════════════════════════════════════════════
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
@ini_set('log_errors', 1);

session_start();

if (!isset($_SESSION['client_id'])) {
    header("Location: client_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
if ($conn->connect_error) {
    die("<div style='font:16px sans-serif;padding:40px;color:red'>
         <strong>Database connection failed:</strong> " . htmlspecialchars($conn->connect_error) . "</div>");
}
$conn->set_charset('utf8mb4');

$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';
$client_email   = $_SESSION['client_email']   ?? '';
$client_type    = $_SESSION['client_type']    ?? 'CORPORATE';

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: client_login.php");
    exit;
}

// ── Simulated wallet (identical to client_invoices.php) ─────────────
if (!isset($_SESSION['sim_wallet']))      $_SESSION['sim_wallet']      = 1000.00;
if (!isset($_SESSION['sim_payments']))    $_SESSION['sim_payments']    = [];
if (!isset($_SESSION['sim_pay_counter'])) $_SESSION['sim_pay_counter'] = 100;

// ══════════════════════════════════════════════════════════════════════
//  AJAX / ACTION HANDLERS  (exact same logic as client_invoices.php)
// ══════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $action = $_GET['action'];
    $esc = fn(string $v): string => $conn->real_escape_string(trim($v));

    // ── get_unpaid_invoices ─────────────────────────────────────────
    if ($action === 'get_unpaid_invoices') {
        $filter = $esc($_GET['filter'] ?? 'all');
        $where  = "i.CLIENT_ID = $client_id";
        if ($filter === 'Unpaid')   $where .= " AND i.STATUS = 'Unpaid'";
        if ($filter === 'Partial')  $where .= " AND i.STATUS = 'Partial'";
        if ($filter === 'Overdue')  $where .= " AND i.STATUS = 'Overdue'";

        $res = $conn->query("
            SELECT i.*,
                   rf.REP_FAULT_ID,
                   rf.DESCRIPTION AS FAULT_DESC,
                   rf.STATUS      AS FAULT_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS,
                   c.COMPANY_NAME
            FROM invoice i
            LEFT JOIN client c   ON c.CLIENT_ID  = i.CLIENT_ID
            LEFT JOIN reported_fault rf ON rf.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN assignment a   ON a.REP_FAULT_ID = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
            WHERE $where
            GROUP BY i.INVOICE_ID
            ORDER BY
                CASE i.STATUS
                    WHEN 'Overdue'  THEN 1
                    WHEN 'Unpaid'   THEN 2
                    WHEN 'Partial'  THEN 3
                    ELSE 4
                END,
                i.DUE_DATE ASC,
                i.INVOICE_DATE DESC
        ");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $inv_id   = (int)$r['INVOICE_ID'];
                $sim_paid = 0;
                foreach ($_SESSION['sim_payments'] as $sp) {
                    if ($sp['INVOICE_ID'] == $inv_id) $sim_paid += $sp['AMOUNT_PAID'];
                }
                $r['PAID_AMT']  = $sim_paid;
                $total = floatval($r['TOTAL'] ?? 0);
                if ($sim_paid >= $total && $total > 0) $r['STATUS'] = 'Paid';
                elseif ($sim_paid > 0)                 $r['STATUS'] = 'Partial';
                // Only return non-fully-paid invoices for this page
                if ($filter === 'all' && $r['STATUS'] === 'Paid') continue;
                $rows[] = $r;
            }
        }
        echo json_encode($rows);
        exit;
    }

    // ── get_invoice_detail ──────────────────────────────────────────
    if ($action === 'get_invoice_detail') {
        $iid = (int)($_GET['id'] ?? 0);
        $res = $conn->query("
            SELECT i.*, c.COMPANY_NAME, c.COMPANY_EMAIL, c.COMPANY_PHONE,
                   rf.REP_FAULT_ID, rf.DESCRIPTION AS FAULT_DESC, rf.STATUS AS FAULT_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS
            FROM invoice i
            LEFT JOIN client c   ON c.CLIENT_ID  = i.CLIENT_ID
            LEFT JOIN reported_fault rf ON rf.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN assignment a   ON a.REP_FAULT_ID = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
            WHERE i.INVOICE_ID = $iid AND i.CLIENT_ID = $client_id
            GROUP BY i.INVOICE_ID
        ");
        $inv = $res ? $res->fetch_assoc() : null;
        if (!$inv) { echo json_encode(['error' => 'Invoice not found']); exit; }

        $lines = [];
        $lr = $conn->query("SELECT * FROM invoice_line WHERE INVOICE_ID=$iid");
        if ($lr) while ($l = $lr->fetch_assoc()) $lines[] = $l;

        $payments   = array_values(array_filter($_SESSION['sim_payments'], fn($p) => $p['INVOICE_ID'] == $iid));
        $paid_total = array_sum(array_column($payments, 'AMOUNT_PAID'));

        $total = floatval($inv['TOTAL'] ?? 0);
        if ($paid_total >= $total && $total > 0) $inv['STATUS'] = 'Paid';
        elseif ($paid_total > 0)                 $inv['STATUS'] = 'Partial';

        echo json_encode(['invoice' => $inv, 'lines' => $lines, 'payments' => $payments, 'paid_total' => $paid_total]);
        exit;
    }

    // ── get_wallet_balance ──────────────────────────────────────────
    if ($action === 'get_wallet_balance') {
        echo json_encode(['balance' => floatval($_SESSION['sim_wallet'])]);
        exit;
    }

    // ── submit_payment  (identical logic to client_invoices.php) ────
    if ($action === 'submit_payment') {
        $iid    = (int)($_POST['invoice_id'] ?? 0);
        $amount = round(floatval($_POST['amount'] ?? 0), 2);
        $method = trim($_POST['method']         ?? '');
        $ref    = trim($_POST['reference']      ?? '');
        $mobile = trim($_POST['mobile_number']  ?? '');
        $network= trim($_POST['network']        ?? '');
        $bank   = trim($_POST['bank']           ?? '');
        $acc    = trim($_POST['account_number'] ?? '');

        if (!$iid || $amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid invoice or amount.']); exit;
        }
        if (!$method) {
            echo json_encode(['success' => false, 'error' => 'Please select a payment method.']); exit;
        }

        $inv_res = $conn->query("SELECT INVOICE_ID, TOTAL, STATUS FROM invoice WHERE INVOICE_ID=$iid AND CLIENT_ID=$client_id");
        $inv = $inv_res ? $inv_res->fetch_assoc() : null;
        if (!$inv) {
            echo json_encode(['success' => false, 'error' => 'Invoice not found.']); exit;
        }

        $already_paid = 0;
        foreach ($_SESSION['sim_payments'] as $sp) {
            if ($sp['INVOICE_ID'] == $iid) $already_paid += $sp['AMOUNT_PAID'];
        }
        $inv_total   = floatval($inv['TOTAL']);
        $balance_due = round($inv_total - $already_paid, 2);

        if ($balance_due <= 0) {
            echo json_encode(['success' => false, 'error' => 'This invoice is already fully paid.']); exit;
        }
        if ($amount > $balance_due + 0.01) {
            echo json_encode(['success' => false, 'error' => 'Amount E'.number_format($amount,2).' exceeds balance due of E'.number_format($balance_due,2).'.']); exit;
        }

        $pay_status = 'Confirmed';
        if ($method === 'Wallet') {
            if ($_SESSION['sim_wallet'] < $amount) {
                echo json_encode(['success' => false, 'error' => 'Insufficient wallet balance. Available: E'.number_format($_SESSION['sim_wallet'],2).'.']); exit;
            }
            $_SESSION['sim_wallet'] = round($_SESSION['sim_wallet'] - $amount, 2);
        } elseif ($method === 'Bank Transfer') {
            $pay_status = 'Pending Verification';
        }

        if (!$ref) $ref = 'BQ-'.strtoupper(substr(md5(uniqid()), 0, 8));

        $_SESSION['sim_pay_counter']++;
        $pay_id = $_SESSION['sim_pay_counter'];
        $today  = date('Y-m-d H:i:s');

        $_SESSION['sim_payments'][] = [
            'PAYMENT_ID'       => $pay_id,
            'INVOICE_ID'       => $iid,
            'PAYMENT_DATE'     => $today,
            'AMOUNT_PAID'      => $amount,
            'METHOD'           => $method,
            'REFERENCE_NUMBER' => $ref,
            'STATUS'           => $pay_status,
            'MOBILE_NUMBER'    => $mobile,
            'NETWORK'          => $network,
            'BANK_NAME'        => $bank,
            'ACCOUNT_NUMBER'   => $acc,
            'PROOF_PATH'       => '',
            'NOTES'            => '',
        ];

        $new_paid       = $already_paid + $amount;
        $new_inv_status = ($new_paid >= $inv_total && $inv_total > 0) ? 'Paid' : 'Partial';
        @$conn->query("UPDATE invoice SET STATUS='$new_inv_status', PAID_AMOUNT=COALESCE(PAID_AMOUNT,0)+$amount WHERE INVOICE_ID=$iid");

        $new_wallet = ($method === 'Wallet') ? $_SESSION['sim_wallet'] : null;

        echo json_encode([
            'success'        => true,
            'message'        => 'Payment of E'.number_format($amount,2).' processed successfully!',
            'pay_status'     => $pay_status,
            'new_inv_status' => $new_inv_status,
            'new_wallet'     => $new_wallet,
            'reference'      => $ref,
            'payment_id'     => $pay_id,
            'amount'         => $amount,
            'method'         => $method,
            'invoice_id'     => $iid,
            'balance_remaining' => max(0, $balance_due - $amount),
        ]);
        exit;
    }

    // ── get_transactions ────────────────────────────────────────────
    if ($action === 'get_transactions') {
        $iid  = (int)($_GET['id'] ?? 0);
        $rows = $iid
            ? array_values(array_filter($_SESSION['sim_payments'], fn($p) => $p['INVOICE_ID'] == $iid))
            : array_reverse($_SESSION['sim_payments']);
        echo json_encode(array_values($rows));
        exit;
    }

    // ── get_stats ───────────────────────────────────────────────────
    if ($action === 'get_stats') {
        $total_val   = floatval($conn->query("SELECT COALESCE(SUM(TOTAL),0) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS NOT IN ('Paid')")->fetch_assoc()['n'] ?? 0);
        $unpaid_cnt  = (int)$conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS IN ('Unpaid','Overdue')")->fetch_assoc()['n'];
        $partial_cnt = (int)$conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Partial'")->fetch_assoc()['n'];
        $overdue_cnt = (int)$conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Overdue'")->fetch_assoc()['n'];
        $total_paid  = array_sum(array_column($_SESSION['sim_payments'], 'AMOUNT_PAID'));
        $wallet      = floatval($_SESSION['sim_wallet']);
        echo json_encode(compact('total_val','unpaid_cnt','partial_cnt','overdue_cnt','total_paid','wallet'));
        exit;
    }

    // ── reset_wallet ────────────────────────────────────────────────
    if ($action === 'reset_wallet') {
        $_SESSION['sim_wallet']      = 1000.00;
        $_SESSION['sim_payments']    = [];
        $_SESSION['sim_pay_counter'] = 100;
        echo json_encode(['success' => true, 'balance' => 1000.00, 'message' => 'Demo reset! Wallet back to E1,000.00.']);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  PAGE-LOAD DATA
// ══════════════════════════════════════════════════════════════════════
function dbVal(mysqli $c, string $sql): string {
    $r = $c->query($sql);
    return $r ? (string)array_values($r->fetch_assoc())[0] : '0';
}
$unpaid_count  = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS IN ('Unpaid','Partial','Overdue')");
$overdue_count = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Overdue'");
$partial_count = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Partial'");
$total_due_amt = floatval(dbVal($conn, "SELECT COALESCE(SUM(TOTAL),0) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS NOT IN ('Paid')"));
$wallet_balance= floatval($_SESSION['sim_wallet']);
$total_paid_amt= array_sum(array_column($_SESSION['sim_payments'], 'AMOUNT_PAID'));
$client_row    = $conn->query("SELECT * FROM client WHERE CLIENT_ID=$client_id")->fetch_assoc();
$c_initial     = strtoupper(substr($client_row['COMPANY_NAME'] ?? $client_name, 0, 1));
$company_phone = htmlspecialchars($client_row['COMPANY_PHONE'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Make a Payment — BUSIQUIP ESWATINI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══ DESIGN TOKENS (identical to client_invoices.php) ══════════════ */
:root{
    --burg:#8B0000;--burg2:#C0392B;--burg-g:rgba(139,0,0,.3);
    --gold:#E8B84B;--gold2:#FFD700;--gold-p:rgba(232,184,75,.12);
    --teal:#0D9488;--sky:#0EA5E9;--em:#10B981;
    --warn:#F59E0B;--dan:#EF4444;--ind:#6366F1;--pur:#8B5CF6;
    --bg0:#070C14;--bg1:#0D1421;--bg2:#111B2E;--bg3:#1A2640;--bg4:#243055;
    --sur:rgba(17,27,46,.95);--gl:rgba(255,255,255,.04);--glb:rgba(255,255,255,.07);
    --bor:rgba(232,184,75,.16);--borh:rgba(232,184,75,.4);
    --t1:#EFF4FF;--t2:#8A9CC4;--t3:#445570;
    --r:14px;--rl:22px;--rx:32px;
    --sh:0 8px 32px rgba(0,0,0,.5);--shl:0 20px 60px rgba(0,0,0,.55);
    --blur:blur(18px);--tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;
    --sw:260px;--hh:68px;
}
body.lm{--bg0:#F0F4FA;--bg1:#E6EEF8;--bg2:#DDE5F5;--bg3:#fff;--sur:rgba(255,255,255,.96);--gl:rgba(0,0,0,.02);--bor:rgba(139,0,0,.14);--t1:#0D1421;--t2:#4A5A7A;--t3:#9AAAC4;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;transition:background .4s,color .4s}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}

/* BG */
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);
    background-size:48px 48px}
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;opacity:.15;animation:orb 18s ease-in-out infinite}
.o1{width:480px;height:480px;top:-150px;left:-150px;background:radial-gradient(circle,var(--burg),transparent)}
.o2{width:380px;height:380px;bottom:-80px;right:-80px;background:radial-gradient(circle,var(--gold),transparent);animation-delay:-6s}
.o3{width:280px;height:280px;top:45%;left:42%;background:radial-gradient(circle,var(--teal),transparent);animation-delay:-12s}
@keyframes orb{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(35px,-55px) scale(1.1)}66%{transform:translate(-25px,35px) scale(.9)}}

/* TICKER */
.ticker{position:fixed;top:0;left:0;right:0;height:26px;z-index:2000;
    background:linear-gradient(90deg,var(--burg),#6B0000,var(--burg));overflow:hidden;display:flex;align-items:center}
.ticker-inner{display:flex;gap:70px;white-space:nowrap;animation:tick 32s linear infinite;
    font-family:var(--fm);font-size:10px;letter-spacing:.06em;color:var(--gold2)}
@keyframes tick{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* HEADER */
header{position:fixed;top:26px;left:0;right:0;height:var(--hh);z-index:1500;
    background:rgba(7,12,20,.9);backdrop-filter:var(--blur);border-bottom:1px solid var(--bor);
    display:flex;align-items:center;padding:0 24px 0 calc(var(--sw)+24px);gap:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.4)}
body.lm header{background:rgba(240,244,250,.93)}
.brand{position:absolute;left:16px;display:flex;align-items:center;gap:10px}
.brand-ic{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;box-shadow:0 0 18px var(--burg-g)}
.brand-nm{font-family:var(--fh);font-size:20px;font-weight:800;background:linear-gradient(135deg,var(--gold2),var(--burg2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.brand-sub{font-size:9px;color:var(--t2);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm)}
.h-search{flex:1;max-width:400px;display:flex;align-items:center;gap:8px;background:var(--glb);border:1px solid var(--bor);border-radius:var(--r);padding:0 12px;transition:var(--tr)}
.h-search:focus-within{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.h-search i{color:var(--t3);font-size:13px}
.h-search input{flex:1;background:none;border:none;outline:none;color:var(--t1);font-family:var(--fb);font-size:13px;padding:9px 0}
.h-search input::placeholder{color:var(--t3)}
.h-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.h-av{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));
    display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:17px;font-weight:800;color:#fff;
    border:2px solid var(--gold);cursor:pointer;transition:var(--tr);position:relative}
.h-av:hover{transform:scale(1.1);box-shadow:0 0 18px var(--burg-g)}
.h-av .dot{position:absolute;bottom:1px;right:1px;width:10px;height:10px;background:var(--em);border-radius:50%;border:2px solid var(--bg0)}
.h-nm .n{font-weight:600;font-size:13px}.h-nm .e{font-size:11px;color:var(--t2)}
.hb{width:38px;height:38px;border-radius:50%;border:1px solid var(--bor);background:var(--gl);color:var(--t2);font-size:15px;display:flex;align-items:center;justify-content:center;transition:var(--tr)}
.hb:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}
.hb.lo{border-color:rgba(239,68,68,.3);color:var(--dan)}
.hb.lo:hover{background:rgba(239,68,68,.1);border-color:var(--dan)}

/* SIDEBAR */
.sidebar{position:fixed;top:calc(26px + var(--hh));left:0;width:var(--sw);
    height:calc(100vh - 26px - var(--hh));background:rgba(11,18,33,.97);
    backdrop-filter:var(--blur);border-right:1px solid var(--bor);padding:20px 0 80px;overflow-y:auto;z-index:1200}
body.lm .sidebar{background:rgba(255,255,255,.97)}
.slbl{padding:10px 20px 5px;font-size:9px;letter-spacing:.16em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);font-weight:600}
.ni{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;border-radius:10px;color:var(--t2);font-size:13px;font-weight:500;cursor:pointer;transition:var(--tr);border:1px solid transparent}
.ni:hover,.ni.act{background:var(--gold-p);color:var(--gold);border-color:var(--bor);transform:translateX(3px)}
.ni .ic{width:30px;height:30px;border-radius:8px;background:var(--gl);display:flex;align-items:center;justify-content:center;font-size:14px;transition:var(--tr)}
.ni:hover .ic,.ni.act .ic{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 10px var(--burg-g)}
.ni .nb{margin-left:auto;background:var(--dan);color:#fff;font-size:9px;padding:2px 6px;border-radius:99px;font-weight:700}
.nb-gold{background:var(--burg) !important}
.exp-btn{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;border-radius:10px;background:none;border:1px solid var(--borh);color:var(--gold);font-size:12px;font-weight:700;width:calc(100% - 16px);transition:var(--tr)}
.exp-btn:hover{background:var(--gold-p)}
.exp-btn .ch{margin-left:auto;transition:transform .3s}
.exp-btn.open .ch{transform:rotate(180deg)}
.sub-menu{max-height:0;overflow:hidden;transition:max-height .4s ease}
.sub-menu.open{max-height:500px}
.si{display:flex;align-items:center;gap:9px;padding:8px 14px 8px 42px;margin:2px 8px;border-radius:8px;color:var(--t3);font-size:12px;cursor:pointer;transition:var(--tr)}
.si:hover{color:var(--gold);background:var(--gold-p)}
.s-banner{margin:14px 10px;padding:14px;background:linear-gradient(135deg,rgba(139,0,0,.22),rgba(232,184,75,.1));border:1px solid var(--borh);border-radius:var(--r);text-align:center}
.s-banner i{font-size:26px;color:var(--gold);margin-bottom:8px;display:block}
.s-banner p{font-size:11px;color:var(--t2);line-height:1.5}
.s-banner a{display:inline-block;margin-top:10px;padding:6px 14px;border-radius:6px;background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-size:11px;font-weight:700}

/* MAIN */
main{margin-left:var(--sw);padding-top:calc(26px + var(--hh) + 28px);padding-bottom:60px;padding-left:28px;padding-right:28px;position:relative;z-index:1;min-height:100vh}
@media(max-width:1024px){main{margin-left:0;padding-left:14px;padding-right:14px}.sidebar{transform:translateX(-100%)}header{padding-left:24px}.brand{position:static}}

/* ALERTS */
#alerts{position:fixed;top:calc(26px + var(--hh) + 14px);right:18px;z-index:9999;display:flex;flex-direction:column;gap:9px;pointer-events:none}
.al{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:var(--r);font-size:13px;font-weight:500;pointer-events:all;backdrop-filter:var(--blur);box-shadow:var(--sh);min-width:260px;max-width:380px;animation:alin .3s ease,alout .4s ease 5.5s forwards}
.al-s{background:rgba(16,185,129,.14);border:1px solid var(--em);color:var(--em)}
.al-e{background:rgba(239,68,68,.14);border:1px solid var(--dan);color:var(--dan)}
.al-i{background:rgba(59,130,246,.14);border:1px solid #3B82F6;color:#3B82F6}
.al-w{background:rgba(245,158,11,.14);border:1px solid var(--warn);color:var(--warn)}
@keyframes alin{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes alout{to{transform:translateX(120%);opacity:0;pointer-events:none}}

/* PAGE HEADER */
.pg-head{margin-bottom:28px;animation:sd .5s ease}
@keyframes sd{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:translateY(0)}}
.pg-head h1{font-family:var(--fh);font-size:clamp(22px,3vw,32px);font-weight:800;background:linear-gradient(135deg,#fff,var(--gold2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
.pg-head p{font-size:13px;color:var(--t2)}
.pg-head .breadcrumb{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--t3);margin-bottom:10px;font-family:var(--fm)}
.pg-head .breadcrumb a{color:var(--gold)}
.pg-head .breadcrumb span{color:var(--t3)}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:28px}
.sc{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);padding:20px 18px;backdrop-filter:var(--blur);position:relative;overflow:hidden;transition:var(--tr);animation:pi .45s ease both}
@keyframes pi{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
.sc:nth-child(1){animation-delay:.05s}.sc:nth-child(2){animation-delay:.1s}.sc:nth-child(3){animation-delay:.15s}.sc:nth-child(4){animation-delay:.2s}.sc:nth-child(5){animation-delay:.25s}
.sc::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--burg-g),transparent);opacity:0;transition:opacity .3s}
.sc:hover{transform:translateY(-4px);border-color:var(--borh);box-shadow:var(--sh)}
.sc:hover::after{opacity:1}
.si-w{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:12px}
.si-b{background:rgba(139,0,0,.2);color:var(--burg2)}.si-g{background:rgba(232,184,75,.14);color:var(--gold)}
.si-t{background:rgba(13,148,136,.14);color:var(--teal)}.si-e{background:rgba(16,185,129,.14);color:var(--em)}
.si-r{background:rgba(244,63,94,.14);color:#F43F5E}.si-s{background:rgba(14,165,233,.14);color:var(--sky)}
.sn{font-family:var(--fh);font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--t1),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:3px}
.sl{font-size:11px;color:var(--t2);letter-spacing:.03em}.ss{font-size:10px;color:var(--t3);margin-top:5px;font-family:var(--fm)}

/* WALLET CARD */
.wallet-card{background:linear-gradient(135deg,rgba(139,0,0,.25),rgba(232,184,75,.12));border:1px solid var(--borh);border-radius:var(--rx);padding:24px 28px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;animation:sd .6s ease .1s both}
.wc-left .wl{font-size:11px;color:var(--t3);letter-spacing:.1em;text-transform:uppercase;font-family:var(--fm);margin-bottom:6px}
.wc-left .wa{font-family:var(--fh);font-size:36px;font-weight:800;color:var(--gold2)}
.wc-left .ws{font-size:12px;color:var(--t2);margin-top:4px}
.wc-right{display:flex;gap:10px;flex-wrap:wrap}

/* LAYOUT: two-column below wallet */
.pay-layout{display:grid;grid-template-columns:1fr 420px;gap:24px;align-items:start}
@media(max-width:1200px){.pay-layout{grid-template-columns:1fr}}

/* INVOICE LIST PANEL */
.panel{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);backdrop-filter:var(--blur);overflow:hidden}
.panel-head{display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid var(--bor);flex-wrap:wrap}
.panel-head h2{font-family:var(--fh);font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px;flex:1}
.panel-head h2 .dot{width:9px;height:9px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));box-shadow:0 0 7px var(--burg-g)}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;padding:14px 22px;border-bottom:1px solid var(--bor);background:rgba(255,255,255,.01)}
.ftab{padding:6px 14px;border-radius:99px;border:1px solid var(--bor);background:none;color:var(--t2);font-size:11px;font-weight:600;cursor:pointer;transition:var(--tr)}
.ftab:hover,.ftab.act{background:var(--gold-p);border-color:var(--borh);color:var(--gold)}

/* INVOICE CARDS */
.inv-list{padding:14px}
.inv-card{background:var(--bg3);border:1px solid var(--bor);border-radius:var(--r);padding:16px 18px;margin-bottom:10px;transition:var(--tr);cursor:pointer;position:relative;overflow:hidden}
.inv-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--t3);transition:background .3s}
.inv-card.overdue::before{background:var(--dan)}
.inv-card.partial::before{background:var(--warn)}
.inv-card.unpaid::before{background:var(--sky)}
.inv-card:hover{border-color:var(--borh);transform:translateX(3px);box-shadow:var(--sh)}
.inv-card.selected{border-color:var(--gold);background:linear-gradient(135deg,rgba(232,184,75,.05),var(--bg3));box-shadow:0 0 0 2px var(--gold-p)}
.inv-card.selected::before{background:var(--gold)}
.ic-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
.ic-num{font-family:var(--fm);font-size:14px;font-weight:700;color:var(--gold)}
.ic-status{display:flex;align-items:center;gap:6px}
.ic-body{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px}
.ic-field .k{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;font-family:var(--fm);margin-bottom:2px}
.ic-field .v{font-size:12px;font-weight:600;font-family:var(--fm)}
.ic-field .v.red{color:var(--dan)}.ic-field .v.grn{color:var(--em)}.ic-field .v.gold{color:var(--gold)}
.ic-footer{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ic-progress{flex:1;min-width:100px}
.ic-progress .pb-wrap{height:5px;background:var(--bg1);border-radius:99px;overflow:hidden;margin-bottom:3px}
.ic-progress .pb-bar{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--burg),var(--gold));transition:width 1.2s ease}
.ic-progress .pb-pct{font-size:9px;color:var(--t3);font-family:var(--fm)}
.ic-due{font-size:10px;font-family:var(--fm);color:var(--t3);display:flex;align-items:center;gap:4px}
.ic-due.urgent{color:var(--dan);font-weight:700}
.inv-card-actions{display:flex;gap:6px;flex-wrap:wrap}

/* PAYMENT PANEL (right column) */
.pay-panel-wrap{position:sticky;top:calc(26px + var(--hh) + 28px)}
.pay-box{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);backdrop-filter:var(--blur);overflow:hidden}
.pay-box-head{padding:20px 24px 16px;border-bottom:1px solid var(--bor);background:linear-gradient(135deg,rgba(139,0,0,.12),rgba(232,184,75,.04))}
.pay-box-head h2{font-family:var(--fh);font-size:17px;font-weight:800;display:flex;align-items:center;gap:8px;margin-bottom:4px}
.pay-box-head p{font-size:12px;color:var(--t2)}

/* Selected invoice summary inside pay panel */
.inv-summary{background:var(--bg3);border:1px solid var(--bor);border-radius:var(--r);padding:14px 16px;margin:0 20px 4px}
.inv-summary .is-num{font-family:var(--fm);font-size:13px;font-weight:700;color:var(--gold);margin-bottom:8px}
.is-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.is-item .k{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fm);margin-bottom:2px}
.is-item .v{font-size:13px;font-weight:700;font-family:var(--fm)}

/* Balance display */
.bal-display{background:linear-gradient(135deg,rgba(232,184,75,.06),rgba(139,0,0,.06));border:1px solid var(--borh);border-radius:var(--r);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:0 20px 4px}
.bd-item .bdl{font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;font-family:var(--fm)}
.bd-item .bdv{font-family:var(--fh);font-size:16px;font-weight:800;color:var(--gold)}
.bd-item .bdv.red{color:var(--dan)}.bd-item .bdv.grn{color:var(--em)}

/* Payment method cards */
.pm-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 20px;margin-bottom:14px}
.pm-card{padding:14px 10px;border-radius:var(--r);border:2px solid var(--bor);background:var(--bg3);cursor:pointer;transition:var(--tr);text-align:center}
.pm-card:hover{border-color:var(--borh)}
.pm-card.sel{border-color:var(--gold);background:var(--gold-p);box-shadow:0 0 18px var(--gold-p)}
.pm-card i{font-size:22px;margin-bottom:6px;display:block}
.pm-card .pm-label{font-size:11px;font-weight:700;font-family:var(--fh);line-height:1.2}
.pm-card .pm-sub{font-size:9px;color:var(--t3);margin-top:3px}
.pm-mobile i{color:var(--em)}.pm-bank i{color:var(--sky)}.pm-wallet i{color:var(--gold)}
.pm-cash i{color:var(--warn)}

/* Payment detail panels */
.pay-detail{display:none;padding:0 20px;animation:fd .25s ease}
.pay-detail.show{display:block}
@keyframes fd{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* Network selector */
.net-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.net-opt{padding:10px;border:1px solid var(--bor);border-radius:8px;background:var(--bg3);cursor:pointer;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;transition:var(--tr)}
.net-opt:hover,.net-opt.sel{border-color:var(--gold);background:var(--gold-p);color:var(--gold)}
.net-dot{width:12px;height:12px;border-radius:50%}
.net-mtn .net-dot{background:#FFCB05}.net-esw .net-dot{background:#00A650}

/* Form fields */
.fr{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:10px;font-weight:700;color:var(--t2);letter-spacing:.06em;text-transform:uppercase;font-family:var(--fm)}
.fg input,.fg select,.fg textarea{background:var(--bg3);border:1px solid var(--bor);color:var(--t1);padding:10px 12px;border-radius:8px;font-family:var(--fb);font-size:13px;transition:var(--tr);outline:none;width:100%;-webkit-appearance:none;appearance:none}
.fg input::placeholder{color:var(--t3)}
.fg input:focus,.fg select:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.fg select option{background:var(--bg2);color:var(--t1)}
.fg input[readonly]{opacity:.6;cursor:not-allowed}
.full{grid-column:1/-1}
.fg input:invalid{border-color:var(--dan)}

/* Mini balance display inside panels */
.mini-bal{background:var(--bg1);border:1px solid var(--bor);border-radius:8px;padding:10px 12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:10px;font-size:11px;font-family:var(--fm)}
.mini-bal .k{color:var(--t3)}.mini-bal .v{font-weight:700;color:var(--t1)}
.mini-bal .v.red{color:var(--dan)}.mini-bal .v.grn{color:var(--em)}

/* Divider */
.div{display:flex;align-items:center;gap:12px;margin:16px 0}
.div::before,.div::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--bor),transparent)}
.div span{font-size:9px;letter-spacing:.14em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);white-space:nowrap}

/* Info boxes */
.ib{padding:10px 14px;border-radius:8px;display:flex;gap:10px;align-items:flex-start;font-size:12px;line-height:1.5}
.ib-g{background:rgba(232,184,75,.09);border-left:3px solid var(--gold);color:var(--t1)}
.ib-t{background:rgba(13,148,136,.09);border-left:3px solid var(--teal);color:var(--t1)}
.ib-e{background:rgba(16,185,129,.09);border-left:3px solid var(--em);color:var(--t1)}
.ib-r{background:rgba(239,68,68,.09);border-left:3px solid var(--dan);color:var(--t1)}
.ib-w{background:rgba(245,158,11,.09);border-left:3px solid var(--warn);color:var(--t1)}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:12px;font-weight:600;border:none;cursor:pointer;transition:var(--tr);flex-shrink:0}
.btn-p{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-p:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 22px var(--burg-g)}
.btn-s{background:none;border:1px solid var(--borh);color:var(--gold)}
.btn-s:hover{background:var(--gold-p)}
.btn-e{background:var(--em);color:#fff}.btn-e:hover{background:#059669;transform:translateY(-2px)}
.btn-d{background:var(--dan);color:#fff}.btn-d:hover{background:#B91C1C}
.btn-t{background:rgba(13,148,136,.2);border:1px solid var(--teal);color:var(--teal)}
.btn-t:hover{background:rgba(13,148,136,.35)}
.btn-sm{padding:6px 12px;font-size:11px}
.btn-full{width:100%;justify-content:center;padding:14px}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none !important}

/* Badges */
.b{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.b-paid{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-unpaid{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.b-partial{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.b-overdue{background:rgba(244,63,94,.2);color:#F43F5E;border:1px solid rgba(244,63,94,.4)}
.b-pending{background:rgba(99,102,241,.14);color:var(--ind);border:1px solid rgba(99,102,241,.3)}
.b-conf{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-pv{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}

/* Pay action footer */
.pay-footer{padding:16px 20px 20px;border-top:1px solid var(--bor)}
.secure-note{display:flex;align-items:center;gap:6px;font-size:10px;color:var(--t3);justify-content:center;margin-top:10px;font-family:var(--fm)}

/* Empty / spinner */
.spin{width:34px;height:34px;border-radius:50%;border:3px solid var(--bor);border-top-color:var(--gold);animation:sp .8s linear infinite;margin:18px auto}
@keyframes sp{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:36px 18px}
.empty i{font-size:36px;color:var(--t3);margin-bottom:10px;display:block}
.empty p{color:var(--t2);font-size:13px}

/* Success overlay (in right panel, identical to invoices) */
.pay-success{display:none;text-align:center;padding:28px 24px;animation:fd .3s ease}
.pay-success.show{display:block}
.ps-icon{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--em),var(--teal));display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 14px;animation:pop .5s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.receipt-box{background:var(--bg2);border:1px solid var(--bor);border-radius:var(--r);padding:14px;margin-top:14px;text-align:left}
.receipt-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:11px}
.receipt-row:last-child{border:none}
.receipt-row .rk{color:var(--t3)}.receipt-row .rv{font-family:var(--fm);font-weight:600;color:var(--t1)}

/* NO-SELECTION placeholder */
.no-sel{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:36px 24px;text-align:center;color:var(--t3)}
.no-sel i{font-size:40px;margin-bottom:12px;animation:pulse-icon 2.5s ease-in-out infinite}
@keyframes pulse-icon{0%,100%{opacity:.4;transform:scale(1)}50%{opacity:.8;transform:scale(1.08)}}
.no-sel p{font-size:13px;line-height:1.6;color:var(--t2)}

/* Modal (for transaction history) */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:3000;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(6px)}
.mo.show{display:flex;animation:fi .2s ease}
@keyframes fi{from{opacity:0}to{opacity:1}}
.mb{background:var(--bg2);border:1px solid var(--borh);border-radius:var(--rx);width:100%;max-width:760px;max-height:92vh;overflow-y:auto;box-shadow:var(--shl);animation:mu .32s cubic-bezier(.34,1.56,.64,1)}
@keyframes mu{from{transform:translateY(38px) scale(.96);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.mh{padding:22px 26px 18px;border-bottom:1px solid var(--bor);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--bg2);z-index:1}
.mh h2{font-family:var(--fh);font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px}
.mc{width:34px;height:34px;border-radius:50%;border:1px solid var(--bor);background:none;color:var(--t2);font-size:17px;display:flex;align-items:center;justify-content:center;transition:var(--tr)}
.mc:hover{border-color:var(--gold);color:var(--gold);transform:rotate(90deg)}
.mbody{padding:22px 26px;display:grid;gap:16px}
.mfoot{padding:16px 26px 22px;border-top:1px solid var(--bor);display:flex;gap:10px}
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:9px 13px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);border-bottom:1px solid var(--bor);font-weight:600}
.tbl td{padding:11px 13px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tbl tr:hover td{background:var(--gl)}
.tbl tr:last-child td{border-bottom:none}

/* Footer */
footer{background:var(--bg1);border-top:1px solid var(--bor);padding:22px 28px;text-align:center;margin-left:var(--sw);position:relative;z-index:1}
footer p{font-size:11px;color:var(--t3)}footer strong{color:var(--gold);font-family:var(--fh)}
@media(max-width:768px){
    .fr{grid-template-columns:1fr}
    .pm-grid{grid-template-columns:1fr 1fr}
    .ic-body{grid-template-columns:1fr 1fr}
    footer{margin-left:0}
    .inv-card-actions{flex-direction:column}
    .is-grid{grid-template-columns:1fr}
    .h-nm{display:none}
}
</style>
</head>
<body>

<div class="bg-grid"></div>
<div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>

<!-- TICKER -->
<div class="ticker">
  <div class="ticker-inner">
    <span>💳 BUSIQUIP ESWATINI — PAYMENT CENTRE &nbsp;✦&nbsp; Select an invoice below and complete your payment</span>
    <span>🧾 Outstanding invoices: <?php echo $unpaid_count; ?> &nbsp;✦&nbsp; Overdue: <?php echo $overdue_count; ?> &nbsp;✦&nbsp; Wallet Balance: E<?php echo number_format($wallet_balance, 2); ?></span>
    <span>🔒 Secure &amp; Encrypted &nbsp;✦&nbsp; Mobile Money · Bank Transfer · Wallet &nbsp;✦&nbsp; +268 2404 0000</span>
    <span>💳 BUSIQUIP ESWATINI — PAYMENT CENTRE &nbsp;✦&nbsp; Select an invoice below and complete your payment</span>
    <span>🧾 Outstanding invoices: <?php echo $unpaid_count; ?> &nbsp;✦&nbsp; Overdue: <?php echo $overdue_count; ?> &nbsp;✦&nbsp; Wallet Balance: E<?php echo number_format($wallet_balance, 2); ?></span>
    <span>🔒 Secure &amp; Encrypted &nbsp;✦&nbsp; Mobile Money · Bank Transfer · Wallet &nbsp;✦&nbsp; +268 2404 0000</span>
  </div>
</div>

<!-- HEADER -->
<header>
  <a href="client_portal.php" class="brand">
    <div class="brand-ic"><i class="fas fa-cog"></i></div>
    <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
  </a>
  <div class="h-search">
    <i class="fas fa-search"></i>
    <input type="text" id="gsearch" placeholder="Search invoices — number, amount, status…" oninput="filterInvoiceCards(this.value)">
  </div>
  <div class="h-right">
    <div class="h-nm">
      <div class="n"><?php echo htmlspecialchars($client_name); ?></div>
      <div class="e"><?php echo htmlspecialchars($client_email); ?></div>
    </div>
    <div class="h-av" title="My Profile"><?php echo $c_initial; ?><span class="dot"></span></div>
    <button class="hb" onclick="document.body.classList.toggle('lm')" title="Toggle Theme"><i class="fas fa-moon"></i></button>
    <form method="POST" style="display:inline">
      <button type="submit" name="logout" class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
    </form>
  </div>
</header>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="slbl">Main Menu</div>
  <div class="ni" onclick="location.href='client_portal.php'"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</div>
  <div class="ni" onclick="location.href='client_profile.php'"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</div>
  <div class="ni" onclick="location.href='report_fault.php'"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></div>

  <div class="slbl" style="margin-top:8px">Equipment</div>
  <div class="ni" onclick="location.href='client_faults.php'"><div class="ic"><i class="fas fa-tools"></i></div> My Faults</div>
  <div class="ni" onclick="location.href='client_repair_progress.php'"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</div>
  <div class="ni" onclick="location.href='client_products.php'"><div class="ic"><i class="fas fa-box-open"></i></div> My Products</div>

  <div class="slbl" style="margin-top:8px">Finance</div>
  <div class="ni" onclick="location.href='client_invoices.php'"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices <?php if($unpaid_count>0):?><span class="nb"><?php echo $unpaid_count;?></span><?php endif;?></div>
  <div class="ni act"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment <?php if($overdue_count>0):?><span class="nb"><?php echo $overdue_count;?></span><?php endif;?></div>
  <div class="ni" onclick="location.href='client_payment_history.php'"><div class="ic"><i class="fas fa-history"></i></div> Payment History</div>

  <div class="slbl" style="margin-top:8px">More</div>
  <button class="exp-btn" id="exp-btn" onclick="document.getElementById('sub-menu').classList.toggle('open');this.classList.toggle('open')">
    <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
  </button>
  <div class="sub-menu" id="sub-menu">
    <div class="si" onclick="location.href='client_notifications.php'"><i class="fas fa-bell"></i> Notifications</div>
    <div class="si" onclick="location.href='client_documents.php'"><i class="fas fa-folder"></i> Documents</div>
    <div class="si" onclick="location.href='client_help.php'"><i class="fas fa-life-ring"></i> Help & Support</div>
  </div>

  <div class="s-banner">
    <i class="fas fa-headset"></i>
    <p>Payment queries? Our finance team is available Mon–Fri 8AM–5PM.</p>
    <a href="mailto:billing@busiquip.co.sz">Contact Billing</a>
  </div>
</aside>

<div id="alerts"></div>

<!-- MAIN -->
<main>

<!-- PAGE HEADER -->
<div class="pg-head">
  <div class="breadcrumb">
    <a href="client_portal.php"><i class="fas fa-home"></i> Dashboard</a>
    <span>›</span>
    <a href="client_invoices.php"><i class="fas fa-receipt"></i> Invoices</a>
    <span>›</span>
    <span>Make a Payment</span>
  </div>
  <h1><i class="fas fa-credit-card" style="font-size:24px;-webkit-text-fill-color:var(--gold);margin-right:8px"></i>Make a Payment</h1>
  <p>Select an unpaid invoice from the list, then complete your payment on the right. All methods are fully supported.</p>
</div>

<!-- STATS -->
<div class="stats">
  <div class="sc">
    <div class="si-w si-r"><i class="fas fa-file-invoice-dollar"></i></div>
    <div class="sn"><?php echo $unpaid_count; ?></div>
    <div class="sl">Invoices Due</div><div class="ss">Requires payment</div>
  </div>
  <div class="sc">
    <div class="si-w si-b"><i class="fas fa-clock"></i></div>
    <div class="sn"><?php echo $overdue_count; ?></div>
    <div class="sl">Overdue</div><div class="ss">Past due date</div>
  </div>
  <div class="sc">
    <div class="si-w" style="background:rgba(245,158,11,.14);color:var(--warn)"><i class="fas fa-hourglass-half"></i></div>
    <div class="sn"><?php echo $partial_count; ?></div>
    <div class="sl">Partial Payments</div><div class="ss">Balance remaining</div>
  </div>
  <div class="sc">
    <div class="si-w si-g"><i class="fas fa-coins"></i></div>
    <div class="sn" style="font-size:18px">E <?php echo number_format($total_due_amt, 0); ?></div>
    <div class="sl">Total Outstanding</div><div class="ss">All unpaid invoices</div>
  </div>
  <div class="sc">
    <div class="si-w si-e"><i class="fas fa-check-circle"></i></div>
    <div class="sn" style="font-size:18px">E <?php echo number_format($total_paid_amt, 0); ?></div>
    <div class="sl">Paid This Session</div><div class="ss">Demo payments</div>
  </div>
</div>

<!-- WALLET CARD -->
<div class="wallet-card">
  <div class="wc-left">
    <div class="wl"><i class="fas fa-wallet"></i> &nbsp;My Wallet Balance &nbsp;<span style="background:var(--burg);color:#fff;font-size:9px;padding:2px 8px;border-radius:99px;font-family:var(--fm);letter-spacing:.08em">DEMO</span></div>
    <div class="wa" id="wallet-amount">E <?php echo number_format($wallet_balance, 2); ?></div>
    <div class="ws">Simulated wallet — starts at <strong>E1,000.00</strong> per session &nbsp;·&nbsp; School Project Demo</div>
  </div>
  <div class="wc-right">
    <button class="btn btn-p" id="btn-reset" onclick="resetDemo()"><i class="fas fa-redo"></i> Reset Demo (E1,000)</button>
    <button class="btn btn-s" onclick="openTxnHistory(0)"><i class="fas fa-history"></i> All Transactions</button>
    <a href="client_invoices.php" class="btn btn-t"><i class="fas fa-receipt"></i> View All Invoices</a>
  </div>
</div>

<!-- TWO-COLUMN LAYOUT -->
<div class="pay-layout">

  <!-- LEFT: Invoice list -->
  <div>
    <div class="panel">
      <div class="panel-head">
        <h2><span class="dot"></span> Unpaid Invoices</h2>
        <button class="btn btn-s btn-sm" onclick="loadInvoices()"><i class="fas fa-sync"></i> Refresh</button>
      </div>
      <div class="filter-tabs">
        <button class="ftab act" id="ftab-all"     onclick="setFilter('all')">All Due</button>
        <button class="ftab"     id="ftab-Overdue" onclick="setFilter('Overdue')">
          <i class="fas fa-clock" style="color:var(--dan);font-size:10px"></i> Overdue <?php if($overdue_count>0):?><span style="background:var(--dan);color:#fff;padding:1px 5px;border-radius:99px;font-size:9px"><?php echo $overdue_count;?></span><?php endif;?>
        </button>
        <button class="ftab"     id="ftab-Unpaid"  onclick="setFilter('Unpaid')">Unpaid</button>
        <button class="ftab"     id="ftab-Partial" onclick="setFilter('Partial')">Partial</button>
      </div>
      <div class="inv-list" id="inv-list">
        <div style="padding:28px;text-align:center"><div class="spin"></div></div>
      </div>
    </div>
  </div>

  <!-- RIGHT: Payment panel -->
  <div class="pay-panel-wrap">
    <div class="pay-box" id="pay-box">

      <div class="pay-box-head">
        <h2><i class="fas fa-lock" style="color:var(--gold);font-size:14px"></i> Secure Payment</h2>
        <p>Select an invoice on the left, then choose a payment method and confirm.</p>
      </div>

      <!-- No selection state -->
      <div class="no-sel" id="no-sel-state">
        <i class="fas fa-hand-pointer"></i>
        <p>Select an invoice from the list to start your payment</p>
      </div>

      <!-- Payment form (hidden until invoice selected) -->
      <div id="pay-form-area" style="display:none">

        <!-- Selected invoice summary -->
        <div style="padding:16px 20px 8px">
          <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;font-family:var(--fm);margin-bottom:8px">Selected Invoice</div>
          <div class="inv-summary">
            <div class="is-num" id="ps-num">Invoice #—</div>
            <div class="is-grid">
              <div class="is-item"><div class="k">Total</div><div class="v gold" id="ps-total">—</div></div>
              <div class="is-item"><div class="k">Paid</div><div class="v grn" id="ps-paid">—</div></div>
              <div class="is-item"><div class="k">Balance Due</div><div class="v red" id="ps-due">—</div></div>
              <div class="is-item"><div class="k">Due Date</div><div class="v" id="ps-duedate">—</div></div>
            </div>
          </div>
        </div>

        <!-- Balance display -->
        <div class="bal-display">
          <div class="bd-item"><div class="bdl">Balance Due</div><div class="bdv red" id="bd-due">E 0.00</div></div>
          <div class="bd-item"><div class="bdl">Wallet</div><div class="bdv" id="bd-wallet">E 0.00</div></div>
          <div class="bd-item"><div class="bdl">Status</div><div class="bdv" id="bd-status" style="font-size:12px">—</div></div>
        </div>

        <!-- Payment method selection -->
        <div style="padding:14px 20px 10px">
          <div style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;font-family:var(--fm);margin-bottom:10px">Choose Payment Method</div>
        </div>
        <div class="pm-grid">
          <div class="pm-card pm-mobile" onclick="selMethod('mobile')">
            <i class="fas fa-mobile-alt"></i>
            <div class="pm-label">Mobile Money</div>
            <div class="pm-sub">MTN / Eswatini Mobile</div>
          </div>
          <div class="pm-card pm-bank" onclick="selMethod('bank')">
            <i class="fas fa-university"></i>
            <div class="pm-label">Bank Transfer</div>
            <div class="pm-sub">Direct bank payment</div>
          </div>
          <div class="pm-card pm-wallet" onclick="selMethod('wallet')">
            <i class="fas fa-wallet"></i>
            <div class="pm-label">Wallet</div>
            <div class="pm-sub">Instant deduction</div>
          </div>
        </div>

        <!-- ── MOBILE MONEY PANEL ── -->
        <div id="panel-mobile" class="pay-detail">
          <div class="div"><span>Mobile Money Details</span></div>
          <div class="net-grid">
            <div class="net-opt net-mtn" id="net-mtn" onclick="selNet('MTN')">
              <div class="net-dot"></div>MTN MoMo
            </div>
            <div class="net-opt net-esw" id="net-esw" onclick="selNet('Eswatini Mobile')">
              <div class="net-dot"></div>Eswatini Mobile
            </div>
          </div>
          <div class="fr">
            <div class="fg full">
              <label>Mobile Number <span style="color:var(--dan)">*</span></label>
              <input type="tel" id="mob-number" placeholder="e.g. 76 123 456" maxlength="15" pattern="[0-9]{8,12}">
            </div>
            <div class="fg">
              <label>Amount (E) <span style="color:var(--dan)">*</span></label>
              <input type="number" id="mob-amount" placeholder="0.00" step="0.01" min="1" oninput="updateMobAfter()">
            </div>
            <div class="fg">
              <label>PIN (simulated)</label>
              <input type="password" id="mob-pin" placeholder="••••" maxlength="6">
            </div>
            <div class="fg full">
              <label>Auto-generated Reference</label>
              <input type="text" id="mob-ref" readonly>
            </div>
          </div>
          <div class="mini-bal">
            <span><span class="k">Network Balance Before:</span> <span class="v" id="mob-bal-before">—</span></span>
            <span><span class="k">After Payment:</span> <span class="v red" id="mob-bal-after">—</span></span>
            <span><span class="k">Status:</span> <span class="v" style="color:var(--ind)">Confirmed Instantly</span></span>
          </div>
          <div class="ib ib-e" style="margin-top:10px">
            <i class="fas fa-bolt" style="color:var(--em)"></i>
            <div>Mobile Money payments are <strong>confirmed instantly</strong>. Invoice status updates immediately.</div>
          </div>
        </div>

        <!-- ── BANK TRANSFER PANEL ── -->
        <div id="panel-bank" class="pay-detail">
          <div class="div"><span>Bank Transfer Details</span></div>
          <div class="fr">
            <div class="fg full">
              <label>Select Bank <span style="color:var(--dan)">*</span></label>
              <select id="bank-name">
                <option value="">— Choose your bank —</option>
                <option>First National Bank (FNB) Eswatini</option>
                <option>Standard Bank Eswatini</option>
                <option>Nedbank Eswatini</option>
                <option>Swazi Bank</option>
                <option>Central Bank of Eswatini</option>
                <option>African Banking Corporation (ABC)</option>
              </select>
            </div>
            <div class="fg">
              <label>Your Account Number</label>
              <input type="text" id="bank-acc" placeholder="Registered account number">
            </div>
            <div class="fg">
              <label>Amount (E) <span style="color:var(--dan)">*</span></label>
              <input type="number" id="bank-amount" placeholder="0.00" step="0.01" min="1">
            </div>
            <div class="fg full">
              <label>Busiquip Receiving Account</label>
              <input type="text" readonly value="FNB Eswatini — ACC: 6251 0045 892 — Branch: 283275" style="font-family:var(--fm);font-size:11px;color:var(--gold)">
            </div>
            <div class="fg full">
              <label>Payment Reference (use this when making the transfer)</label>
              <input type="text" id="bank-ref" readonly style="color:var(--gold);font-family:var(--fm)">
            </div>
            <div class="fg full">
              <label>Upload Proof of Payment (JPG / PNG / PDF)</label>
              <input type="file" id="bank-proof" accept=".jpg,.jpeg,.png,.pdf" style="padding:8px">
            </div>
          </div>
          <div class="ib ib-t" style="margin-top:8px">
            <i class="fas fa-info-circle" style="color:var(--teal)"></i>
            <div>Bank transfer payments require <strong>accountant verification</strong>. Status will show <em>Pending Verification</em> until our finance team confirms. You'll be notified within 1 business day.</div>
          </div>
        </div>

        <!-- ── WALLET PANEL ── -->
        <div id="panel-wallet" class="pay-detail">
          <div class="div"><span>Wallet Payment</span></div>
          <div class="mini-bal" style="margin-bottom:12px">
            <span><span class="k">Wallet Balance:</span> <span class="v" id="wal-before">E 0.00</span></span>
            <span><span class="k">Paying:</span> <span class="v red" id="wal-paying">E 0.00</span></span>
            <span><span class="k">New Balance:</span> <span class="v grn" id="wal-after">E 0.00</span></span>
          </div>
          <div class="fr">
            <div class="fg">
              <label>Amount to Pay (E) <span style="color:var(--dan)">*</span></label>
              <input type="number" id="wal-amount" placeholder="0.00" step="0.01" min="1" oninput="updateWalAfter()">
            </div>
            <div class="fg" style="align-self:end">
              <button class="btn btn-s" style="width:100%" onclick="payFullWallet()"><i class="fas fa-check-double"></i> Pay Full Balance</button>
            </div>
          </div>
          <div class="ib ib-e" style="margin-top:8px">
            <i class="fas fa-bolt" style="color:var(--em)"></i>
            <div>Wallet payments are <strong>processed instantly</strong> and the invoice status updates immediately.</div>
          </div>
        </div>

        <!-- Submit footer -->
        <div class="pay-footer">
          <button class="btn btn-p btn-full" id="btn-submit-pay" onclick="submitPayment()" disabled>
            <i class="fas fa-lock"></i> Confirm & Process Payment
          </button>
          <div class="secure-note"><i class="fas fa-shield-alt" style="color:var(--em)"></i> SSL Encrypted &nbsp;·&nbsp; Secure Transaction &nbsp;·&nbsp; BUSIQUIP ESWATINI</div>
        </div>

      </div><!-- /pay-form-area -->

      <!-- SUCCESS OVERLAY (shown after payment) -->
      <div id="pay-success-overlay" class="pay-success">
        <div class="ps-icon">✓</div>
        <h3 id="pso-title" style="font-family:var(--fh);font-size:20px;font-weight:800;margin-bottom:8px;color:var(--em)">Payment Successful!</h3>
        <p id="pso-msg" style="color:var(--t2);font-size:13px;margin-bottom:8px;line-height:1.6"></p>
        <div id="pso-ref" style="font-family:var(--fm);font-size:11px;color:var(--gold);padding:8px 14px;background:var(--bg3);border-radius:8px;display:inline-block;margin:8px 0"></div>
        <div class="receipt-box" id="pso-receipt"></div>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap">
          <button class="btn btn-e" onclick="afterPaymentDone()"><i class="fas fa-check-double"></i> Done</button>
          <button class="btn btn-t" onclick="openTxnHistory(lastPaidInvId)"><i class="fas fa-exchange-alt"></i> Transactions</button>
          <a href="client_invoices.php" class="btn btn-s"><i class="fas fa-receipt"></i> All Invoices</a>
        </div>
      </div>

    </div><!-- /pay-box -->
  </div><!-- /pay-panel-wrap -->
</div><!-- /pay-layout -->

</main>

<footer>
  <p>&copy; <?php echo date('Y'); ?> <strong>BUSIQUIP</strong> Payment System &nbsp;|&nbsp; 🇸🇿 Eswatini &nbsp;|&nbsp; All Rights Reserved &nbsp;|&nbsp; <a href="client_invoices.php" style="color:var(--gold)">View All Invoices</a></p>
</footer>

<!-- TRANSACTION HISTORY MODAL -->
<div class="mo" id="m-txn">
  <div class="mb">
    <div class="mh">
      <h2><i class="fas fa-exchange-alt" style="color:var(--sky)"></i>Transaction History <span id="txn-title"></span></h2>
      <button class="mc" onclick="document.getElementById('m-txn').classList.remove('show')">×</button>
    </div>
    <div class="mbody"><div id="txn-body"><div class="spin"></div></div></div>
    <div class="mfoot">
      <button class="btn btn-s" onclick="document.getElementById('m-txn').classList.remove('show')"><i class="fas fa-arrow-left"></i> Close</button>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
//  GLOBALS
// ═══════════════════════════════════════════════════════════════════
let allInvoices      = [];
let currentFilter    = 'all';
let selectedInv      = null;
let selectedMethod   = '';
let selectedNetwork  = '';
let walletBal        = <?php echo $wallet_balance; ?>;
let lastPaidInvId    = 0;

// ── helpers (identical to client_invoices.php) ────────────────────
const h   = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const cur = v => 'E '+parseFloat(v||0).toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2});
const fd  = s => {
    if(!s) return 'N/A';
    const d = new Date(s);
    return isNaN(d) ? 'N/A' : d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
};
const isOverdue = inv => inv.STATUS !== 'Paid' && inv.DUE_DATE && new Date(inv.DUE_DATE) < new Date();

const SCRIPT_URL = window.location.pathname;

async function apiFetch(params, postData=null){
    const url  = SCRIPT_URL + '?' + new URLSearchParams(params).toString();
    const opts = postData ? {method:'POST',body:postData} : {method:'GET'};
    try {
        const res  = await fetch(url, opts);
        const text = await res.text();
        try { return JSON.parse(text); }
        catch(e){
            console.error('API non-JSON:', text);
            return {success:false, error:'Server error — check PHP logs.'};
        }
    } catch(e){
        return {success:false, error:'Cannot reach server. Is PHP running?'};
    }
}

function alert2(t, msg){
    const d = document.createElement('div');
    d.className = 'al al-'+(t==='s'?'s':t==='e'?'e':t==='w'?'w':'i');
    const icons = {s:'check-circle',e:'exclamation-circle',w:'exclamation-triangle',i:'info-circle'};
    d.innerHTML = `<i class="fas fa-${icons[t]||'info-circle'}"></i><span>${msg}</span>`;
    document.getElementById('alerts').appendChild(d);
    setTimeout(()=>d.remove(), 6000);
}

// ═══════════════════════════════════════════════════════════════════
//  LOAD & RENDER INVOICES
// ═══════════════════════════════════════════════════════════════════
async function loadInvoices(){
    document.getElementById('inv-list').innerHTML = '<div style="padding:28px;text-align:center"><div class="spin"></div></div>';
    allInvoices = await apiFetch({action:'get_unpaid_invoices', filter:currentFilter});
    if(allInvoices && allInvoices.error){ alert2('e','Failed to load: '+allInvoices.error); allInvoices=[]; }
    renderInvoiceCards(allInvoices);
}

function renderInvoiceCards(data){
    const list = document.getElementById('inv-list');
    if(!data.length){
        list.innerHTML = `<div class="empty">
            <i class="fas fa-check-circle" style="color:var(--em)"></i>
            <p>No unpaid invoices found.<br><a href="client_invoices.php" style="color:var(--gold)">View all invoices →</a></p>
        </div>`;
        return;
    }

    list.innerHTML = data.map(inv => {
        const total    = parseFloat(inv.TOTAL||0);
        const paid     = parseFloat(inv.PAID_AMT||0);
        const balance  = Math.max(0, total - paid);
        const pct      = total > 0 ? Math.min(100, Math.round(paid/total*100)) : 0;
        const overdue  = isOverdue(inv);
        const status   = overdue ? 'Overdue' : (inv.STATUS||'Unpaid');
        const badgeCls = {Overdue:'b-overdue',Unpaid:'b-unpaid',Partial:'b-partial'}[status]||'b-unpaid';
        const cardCls  = {Overdue:'overdue',Unpaid:'unpaid',Partial:'partial'}[status]||'unpaid';
        const daysLeft = inv.DUE_DATE ? Math.ceil((new Date(inv.DUE_DATE)-new Date())/86400000) : null;
        const dueText  = daysLeft===null ? '' : daysLeft < 0
            ? `<span class="ic-due urgent"><i class="fas fa-exclamation-circle"></i> ${Math.abs(daysLeft)} days overdue</span>`
            : daysLeft === 0
                ? `<span class="ic-due urgent"><i class="fas fa-exclamation-circle"></i> Due today!</span>`
                : `<span class="ic-due"><i class="fas fa-calendar-alt"></i> ${daysLeft} days remaining</span>`;
        const isSelected = selectedInv && selectedInv.INVOICE_ID == inv.INVOICE_ID;
        return `
        <div class="inv-card ${cardCls}${isSelected?' selected':''}" id="icard-${inv.INVOICE_ID}" onclick="selectInvoice(${inv.INVOICE_ID})">
            <div class="ic-top">
                <div>
                    <div class="ic-num">Invoice #${h(inv.INVOICE_ID)}</div>
                    <div style="font-size:10px;color:var(--t2);margin-top:2px;font-family:var(--fm)">${fd(inv.INVOICE_DATE)}</div>
                </div>
                <div class="ic-status">
                    <span class="b ${badgeCls}">${h(status)}</span>
                    ${isSelected?'<i class="fas fa-check-circle" style="color:var(--gold);font-size:14px"></i>':''}
                </div>
            </div>
            <div class="ic-body">
                <div class="ic-field">
                    <div class="k">Total</div>
                    <div class="v gold">${cur(total)}</div>
                </div>
                <div class="ic-field">
                    <div class="k">Paid</div>
                    <div class="v grn">${cur(paid)}</div>
                </div>
                <div class="ic-field">
                    <div class="k">Balance Due</div>
                    <div class="v red">${cur(balance)}</div>
                </div>
            </div>
            <div class="ic-progress" style="margin-bottom:10px">
                <div class="pb-wrap"><div class="pb-bar" style="width:${pct}%"></div></div>
                <div class="pb-pct">${pct}% paid ${inv.TECHNICIANS?'· '+h(inv.TECHNICIANS.split(',')[0]):''}</div>
            </div>
            <div class="ic-footer">
                <div style="display:flex;gap:6px;align-items:center;flex:1;flex-wrap:wrap">
                    ${dueText}
                    ${inv.REP_FAULT_ID?`<span style="font-size:10px;font-family:var(--fm);color:var(--teal)"><i class="fas fa-tools"></i> F-${h(inv.REP_FAULT_ID)}</span>`:''}
                </div>
                <div class="inv-card-actions">
                    <button class="btn btn-p btn-sm" onclick="event.stopPropagation();selectInvoice(${inv.INVOICE_ID})">
                        <i class="fas fa-credit-card"></i> Pay
                    </button>
                    <button class="btn btn-t btn-sm" onclick="event.stopPropagation();openTxnHistory(${inv.INVOICE_ID})" title="Transactions">
                        <i class="fas fa-exchange-alt"></i>
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');

    // Animate progress bars
    setTimeout(()=>{
        document.querySelectorAll('.pb-bar').forEach(b=>{
            const w=b.style.width; b.style.width='0';
            setTimeout(()=>{b.style.width=w;},100);
        });
    },100);
}

function setFilter(f){
    currentFilter = f;
    document.querySelectorAll('.ftab').forEach(t=>t.classList.remove('act'));
    const el = document.getElementById('ftab-'+f);
    if(el) el.classList.add('act');
    loadInvoices();
}

function filterInvoiceCards(q){
    q = q.toLowerCase();
    const filtered = allInvoices.filter(inv =>
        String(inv.INVOICE_ID).includes(q) ||
        (inv.STATUS||'').toLowerCase().includes(q) ||
        String(inv.TOTAL||'').includes(q) ||
        (inv.TECHNICIANS||'').toLowerCase().includes(q)
    );
    renderInvoiceCards(filtered);
}

// ═══════════════════════════════════════════════════════════════════
//  SELECT INVOICE — loads the right payment panel
// ═══════════════════════════════════════════════════════════════════
async function selectInvoice(id){
    // Mark selected visually
    document.querySelectorAll('.inv-card').forEach(c=>c.classList.remove('selected'));
    const card = document.getElementById('icard-'+id);
    if(card){ card.classList.add('selected'); }

    // Find in cached list or fetch
    let inv = allInvoices.find(i=>i.INVOICE_ID==id);
    if(!inv){
        const d = await apiFetch({action:'get_invoice_detail', id});
        if(d.error){ alert2('e',d.error); return; }
        inv = d.invoice;
        inv.PAID_AMT = d.paid_total;
    }
    selectedInv = inv;

    const total   = parseFloat(inv.TOTAL||0);
    const paid    = parseFloat(inv.PAID_AMT||0);
    const balance = Math.max(0, total - paid);
    const overdue = isOverdue(inv);
    const status  = overdue ? 'Overdue' : (inv.STATUS||'Unpaid');
    const badgeCls= {Overdue:'b-overdue',Unpaid:'b-unpaid',Partial:'b-partial'}[status]||'b-unpaid';

    // Show form area
    document.getElementById('no-sel-state').style.display  = 'none';
    document.getElementById('pay-form-area').style.display  = 'block';
    document.getElementById('pay-success-overlay').classList.remove('show');

    // Populate summary
    document.getElementById('ps-num').textContent     = 'Invoice #'+id;
    document.getElementById('ps-total').textContent   = cur(total);
    document.getElementById('ps-paid').textContent    = cur(paid);
    document.getElementById('ps-due').textContent     = cur(balance);
    document.getElementById('ps-duedate').innerHTML   = `${fd(inv.DUE_DATE)}${overdue?'<span style="color:var(--dan);margin-left:6px;font-size:10px">OVERDUE</span>':''}`;
    document.getElementById('bd-due').textContent     = cur(balance);
    document.getElementById('bd-wallet').textContent  = cur(walletBal);
    document.getElementById('bd-status').innerHTML    = `<span class="b ${badgeCls}">${status}</span>`;

    // Auto-generate refs
    const refId = 'BQ-'+(Math.random().toString(36).substr(2,8)).toUpperCase();
    document.getElementById('mob-ref').value  = refId;
    document.getElementById('bank-ref').value = refId;

    // Pre-fill amounts
    document.getElementById('mob-amount').value   = balance.toFixed(2);
    document.getElementById('bank-amount').value  = balance.toFixed(2);
    document.getElementById('wal-amount').value   = balance.toFixed(2);
    document.getElementById('wal-before').textContent = cur(walletBal);
    document.getElementById('wal-paying').textContent = cur(balance);
    document.getElementById('wal-after').textContent  = cur(Math.max(0, walletBal-balance));

    // Reset method
    resetPayMethod();
}

// ═══════════════════════════════════════════════════════════════════
//  PAYMENT METHOD SELECTION
// ═══════════════════════════════════════════════════════════════════
function selMethod(m){
    selectedMethod = m;
    document.querySelectorAll('.pm-card').forEach(c=>c.classList.remove('sel'));
    document.querySelector('.pm-'+m).classList.add('sel');
    ['mobile','bank','wallet'].forEach(p=>{
        document.getElementById('panel-'+p).classList.remove('show');
    });
    document.getElementById('panel-'+m).classList.add('show');
    document.getElementById('btn-submit-pay').disabled = false;
    document.getElementById('btn-submit-pay').innerHTML = '<i class="fas fa-lock"></i> Confirm & Process Payment';

    if(m==='wallet' && selectedInv){
        const bal = Math.max(0,parseFloat(selectedInv.TOTAL||0)-parseFloat(selectedInv.PAID_AMT||0));
        document.getElementById('wal-amount').value = bal.toFixed(2);
        updateWalAfter();
    }
    if(m==='mobile') updateMobAfter();
}

function selNet(n){
    selectedNetwork = n;
    document.querySelectorAll('.net-opt').forEach(o=>o.classList.remove('sel'));
    document.getElementById(n==='MTN'?'net-mtn':'net-esw').classList.add('sel');
    const mockBal = n==='MTN' ? 1250.00 : 875.50;
    document.getElementById('mob-bal-before').textContent = cur(mockBal);
    updateMobAfter();
}

function updateMobAfter(){
    const amt = parseFloat(document.getElementById('mob-amount').value||0);
    const mockBal = selectedNetwork==='MTN'?1250:selectedNetwork?875.50:0;
    document.getElementById('mob-bal-after').textContent = cur(Math.max(0,mockBal-amt));
}

function updateWalAfter(){
    const amt = parseFloat(document.getElementById('wal-amount').value||0);
    document.getElementById('wal-paying').textContent = cur(amt);
    document.getElementById('wal-after').textContent  = cur(Math.max(0,walletBal-amt));
}

function payFullWallet(){
    if(!selectedInv) return;
    const bal = Math.max(0,parseFloat(selectedInv.TOTAL||0)-parseFloat(selectedInv.PAID_AMT||0));
    document.getElementById('wal-amount').value = bal.toFixed(2);
    updateWalAfter();
}

function resetPayMethod(){
    selectedMethod  = '';
    selectedNetwork = '';
    document.querySelectorAll('.pm-card').forEach(c=>c.classList.remove('sel'));
    document.querySelectorAll('.pay-detail').forEach(p=>p.classList.remove('show'));
    document.getElementById('btn-submit-pay').disabled = true;
    document.getElementById('btn-submit-pay').innerHTML = '<i class="fas fa-lock"></i> Confirm & Process Payment';
    document.querySelectorAll('.net-opt').forEach(o=>o.classList.remove('sel'));
}

// ═══════════════════════════════════════════════════════════════════
//  SUBMIT PAYMENT (identical logic to client_invoices.php)
// ═══════════════════════════════════════════════════════════════════
async function submitPayment(){
    if(!selectedInv){ alert2('e','Please select an invoice first.'); return; }
    if(!selectedMethod){ alert2('e','Please select a payment method.'); return; }

    const invId = selectedInv.INVOICE_ID;
    let amount=0, valid=true, errMsg='';

    if(selectedMethod==='mobile'){
        amount = parseFloat(document.getElementById('mob-amount').value||0);
        const mob = document.getElementById('mob-number').value.trim();
        if(!mob)             { errMsg='Please enter your mobile number.';     valid=false; }
        else if(!selectedNetwork) { errMsg='Please select a mobile network (MTN or Eswatini Mobile).'; valid=false; }
        else if(amount<=0)   { errMsg='Please enter a valid payment amount.'; valid=false; }
        else if(!/^\d{7,12}$/.test(mob.replace(/\s/g,''))) { errMsg='Enter a valid Eswatini mobile number.'; valid=false; }
    } else if(selectedMethod==='bank'){
        amount = parseFloat(document.getElementById('bank-amount').value||0);
        const bank = document.getElementById('bank-name').value;
        if(!bank)     { errMsg='Please select your bank.';              valid=false; }
        else if(amount<=0){ errMsg='Please enter a valid payment amount.'; valid=false; }
    } else if(selectedMethod==='wallet'){
        amount = parseFloat(document.getElementById('wal-amount').value||0);
        if(amount<=0) { errMsg='Please enter a valid amount.'; valid=false; }
        else if(amount > walletBal+0.01) { errMsg=`Insufficient wallet balance. Available: ${cur(walletBal)}.`; valid=false; }
    }

    if(!valid){ alert2('e',errMsg); return; }

    const btn = document.getElementById('btn-submit-pay');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

    const fd2 = new FormData();
    fd2.append('invoice_id', invId);
    fd2.append('amount', amount);
    fd2.append('method', selectedMethod==='mobile'?'Mobile Money':selectedMethod==='bank'?'Bank Transfer':'Wallet');
    fd2.append('reference', selectedMethod==='mobile'?document.getElementById('mob-ref').value:document.getElementById('bank-ref').value);
    fd2.append('mobile_number', selectedMethod==='mobile'?document.getElementById('mob-number').value:'');
    fd2.append('network', selectedMethod==='mobile'?selectedNetwork:'');
    fd2.append('bank', selectedMethod==='bank'?document.getElementById('bank-name').value:'');
    fd2.append('account_number', selectedMethod==='bank'?document.getElementById('bank-acc').value:'');

    if(selectedMethod==='bank'){
        const proof = document.getElementById('bank-proof').files[0];
        if(proof) fd2.append('proof',proof);
    }

    const data = await apiFetch({action:'submit_payment'}, fd2);

    if(data.success){
        lastPaidInvId = invId;

        // Update wallet balance display globally
        if(selectedMethod==='wallet' && data.new_wallet !== null){
            walletBal = parseFloat(data.new_wallet);
            document.getElementById('wallet-amount').textContent = 'E '+walletBal.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2});
        }

        // Show success overlay (identical to client_invoices)
        document.getElementById('pay-form-area').style.display = 'none';
        const ov = document.getElementById('pay-success-overlay');
        ov.classList.add('show');

        const methodIcon = {'Mobile Money':'📱','Bank Transfer':'🏦','Wallet':'👛'}[data.method]||'💳';
        const statusLabel = data.pay_status==='Confirmed'
            ? '✅ Confirmed & Applied Instantly'
            : '⏳ Pending Accountant Verification';

        document.getElementById('pso-title').textContent =
            data.pay_status==='Confirmed' ? '🎉 Payment Successful!' : '✅ Payment Submitted!';
        document.getElementById('pso-msg').textContent =
            `Your payment of ${cur(amount)} has been ${data.pay_status==='Confirmed'?'confirmed and applied to Invoice #'+invId+'.':'submitted and is pending verification.'}`;
        document.getElementById('pso-ref').textContent =
            `📋 Ref: ${data.reference}   ·   ${data.pay_status}`;

        // Receipt grid
        document.getElementById('pso-receipt').innerHTML = `
            <div class="receipt-row"><span class="rk">Payment ID</span><span class="rv">#${data.payment_id}</span></div>
            <div class="receipt-row"><span class="rk">Invoice</span><span class="rv">#${data.invoice_id}</span></div>
            <div class="receipt-row"><span class="rk">Amount</span><span class="rv" style="color:var(--em)">${cur(amount)}</span></div>
            <div class="receipt-row"><span class="rk">Balance Remaining</span><span class="rv" style="color:${data.balance_remaining>0?'var(--dan)':'var(--em)'}">${cur(data.balance_remaining)}</span></div>
            <div class="receipt-row"><span class="rk">Method</span><span class="rv">${methodIcon} ${data.method}</span></div>
            <div class="receipt-row"><span class="rk">Reference</span><span class="rv" style="color:var(--gold)">${data.reference}</span></div>
            <div class="receipt-row"><span class="rk">Status</span><span class="rv">${statusLabel}</span></div>
            <div class="receipt-row"><span class="rk">Invoice Status</span><span class="rv"><span class="b ${{Paid:'b-paid',Partial:'b-partial',Unpaid:'b-unpaid'}[data.new_inv_status]||'b-pending'}">${data.new_inv_status}</span></span></div>
            ${data.new_wallet!==null?`<div class="receipt-row"><span class="rk">Wallet Balance</span><span class="rv">${cur(data.new_wallet)}</span></div>`:''}`;

        // Update the selected invoice's cached data
        if(selectedInv){
            selectedInv.PAID_AMT  = (parseFloat(selectedInv.PAID_AMT||0)+amount);
            selectedInv.STATUS    = data.new_inv_status;
        }

        alert2('s',`✓ Payment of ${cur(amount)} confirmed! Ref: ${data.reference}`);

        // If fully paid, remove from list
        if(data.new_inv_status==='Paid'){
            allInvoices = allInvoices.filter(i=>i.INVOICE_ID!=invId);
        } else {
            // Update the invoice in list
            const idx = allInvoices.findIndex(i=>i.INVOICE_ID==invId);
            if(idx>=0){ allInvoices[idx].PAID_AMT=selectedInv.PAID_AMT; allInvoices[idx].STATUS=data.new_inv_status; }
        }
        renderInvoiceCards(allInvoices);

    } else {
        alert2('e', data.error||'Payment failed. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-lock"></i> Confirm & Process Payment';
    }
}

function afterPaymentDone(){
    document.getElementById('pay-success-overlay').classList.remove('show');
    document.getElementById('pay-form-area').style.display = 'none';
    document.getElementById('no-sel-state').style.display  = 'flex';
    selectedInv    = null;
    selectedMethod = '';
    document.querySelectorAll('.inv-card').forEach(c=>c.classList.remove('selected'));
    loadInvoices();
}

// ═══════════════════════════════════════════════════════════════════
//  TRANSACTION HISTORY MODAL
// ═══════════════════════════════════════════════════════════════════
async function openTxnHistory(id){
    id = parseInt(id)||0;
    document.getElementById('txn-title').textContent = id ? '— Invoice #'+id : '— All';
    document.getElementById('txn-body').innerHTML = '<div class="spin"></div>';
    document.getElementById('m-txn').classList.add('show');

    const txns = await apiFetch({action:'get_transactions', id});
    if(!txns.length){
        document.getElementById('txn-body').innerHTML = '<div class="empty"><i class="fas fa-exchange-alt"></i><p>No transactions yet.</p></div>';
        return;
    }
    document.getElementById('txn-body').innerHTML = `
        <table class="tbl">
            <thead><tr><th>ID</th><th>Date</th><th>Invoice</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead>
            <tbody>${txns.map(t=>{
                const sc = {Confirmed:'b-conf','Pending Verification':'b-pv',Pending:'b-pending',Failed:'b-fail'}[t.STATUS]||'b-pending';
                return `<tr>
                    <td style="font-family:var(--fm);color:var(--t3)">#${h(t.PAYMENT_ID)}</td>
                    <td style="font-size:11px">${fd(t.PAYMENT_DATE)}</td>
                    <td style="font-family:var(--fm);color:var(--gold)">#${h(t.INVOICE_ID)}</td>
                    <td>
                        <div style="font-weight:600;font-size:12px">${h(t.METHOD||'N/A')}</div>
                        ${t.NETWORK?`<div style="font-size:10px;color:var(--t3)">${h(t.NETWORK)}</div>`:''}
                        ${t.BANK_NAME?`<div style="font-size:10px;color:var(--t3)">${h(t.BANK_NAME)}</div>`:''}
                    </td>
                    <td style="font-family:var(--fm);font-weight:700;color:var(--em)">${cur(t.AMOUNT_PAID)}</td>
                    <td style="font-family:var(--fm);font-size:10px;color:var(--gold)">${h(t.REFERENCE_NUMBER||'—')}</td>
                    <td><span class="b ${sc}">${h(t.STATUS||'Pending')}</span></td>
                </tr>`;
            }).join('')}</tbody>
        </table>`;
}

document.getElementById('m-txn').addEventListener('click', e=>{
    if(e.target===document.getElementById('m-txn'))
        document.getElementById('m-txn').classList.remove('show');
});

// ═══════════════════════════════════════════════════════════════════
//  RESET DEMO
// ═══════════════════════════════════════════════════════════════════
async function resetDemo(){
    if(!confirm('Reset demo? Wallet returns to E1,000.00 and all demo payments are cleared.')) return;
    const btn = document.getElementById('btn-reset');
    btn.disabled=true; btn.textContent='Resetting…';
    const data = await apiFetch({action:'reset_wallet'});
    if(data.success){
        walletBal = 1000.00;
        document.getElementById('wallet-amount').textContent = 'E 1,000.00';
        if(document.getElementById('bd-wallet')) document.getElementById('bd-wallet').textContent = cur(walletBal);
        alert2('s','✓ Demo reset! Wallet = E1,000.00. All demo payments cleared.');
        loadInvoices();
    } else { alert2('e', data.error||'Reset failed.'); }
    btn.disabled=false; btn.innerHTML='<i class="fas fa-redo"></i> Reset Demo (E1,000)';
}

// ═══════════════════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', ()=>{ loadInvoices(); });
</script>
</body>
</html>


