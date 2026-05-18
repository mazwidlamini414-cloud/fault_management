<?php
// ═══════════════════════════════════════════════════════════════════════
//  client_invoices.php  —  BUSIQUIP ESWATINI  —  Invoice & Payment System
//  Database: busiquip_final
// ═══════════════════════════════════════════════════════════════════════
// CRITICAL: Buffer ALL output so that PHP notices/warnings never corrupt
// the JSON responses sent to fetch() calls. Without this, any stray output
// (DB warnings, notices, etc.) breaks JSON.parse() → "Network error".
ob_start();

ini_set('display_errors', 0);          // Never echo errors into JSON responses
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
// Log errors to file instead of stdout
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

// ── Logout ─────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: client_login.php");
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  SCHOOL PROJECT — SIMULATED PAYMENT SYSTEM
//  Payments are stored in PHP session (not the payment table) so the
//  system works perfectly even if the DB has no payment/wallet columns.
//  Wallet starts at E1,000 per session and decreases as you pay.
// ══════════════════════════════════════════════════════════════════════

// ── Initialize simulated wallet & payments in session ──────────────
if (!isset($_SESSION['sim_wallet'])) {
    $_SESSION['sim_wallet']   = 1000.00; // Starting balance: E1,000
}
if (!isset($_SESSION['sim_payments'])) {
    $_SESSION['sim_payments'] = [];
}
if (!isset($_SESSION['sim_pay_counter'])) {
    $_SESSION['sim_pay_counter'] = 100;
}

// ══════════════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $action = $_GET['action'];
    $esc = fn(string $v): string => $conn->real_escape_string(trim($v));

    // ── get_invoices ─────────────────────────────────────────────────
    if ($action === 'get_invoices') {
        $filter = $esc($_GET['filter'] ?? 'all');
        $where = "i.CLIENT_ID = $client_id";
        if (in_array($filter, ['Paid','Unpaid','Partial','Overdue','Pending'])) {
            $where .= " AND i.STATUS = '$filter'";
        }
        $res = $conn->query("
            SELECT i.*,
                   rf.REP_FAULT_ID, rf.DESCRIPTION AS FAULT_DESC,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS,
                   c.COMPANY_NAME
            FROM invoice i
            LEFT JOIN reported_fault rf ON rf.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
            LEFT JOIN client c ON c.CLIENT_ID = i.CLIENT_ID
            WHERE $where
            GROUP BY i.INVOICE_ID
            ORDER BY i.INVOICE_DATE DESC
        ");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                // Merge in simulated payments for this invoice
                $inv_id = (int)$r['INVOICE_ID'];
                $sim_paid = 0;
                $sim_count = 0;
                foreach ($_SESSION['sim_payments'] as $sp) {
                    if ($sp['INVOICE_ID'] == $inv_id) {
                        $sim_paid += $sp['AMOUNT_PAID'];
                        $sim_count++;
                    }
                }
                $r['PAID_AMT']  = $sim_paid;
                $r['PAY_COUNT'] = $sim_count;
                // Update status based on simulated payments
                $total = floatval($r['TOTAL'] ?? 0);
                if ($sim_paid >= $total && $total > 0) {
                    $r['STATUS'] = 'Paid';
                } elseif ($sim_paid > 0) {
                    $r['STATUS'] = 'Partial';
                }
                $rows[] = $r;
            }
        }
        echo json_encode($rows);
        exit;
    }

    // ── get_invoice_detail ───────────────────────────────────────────
    if ($action === 'get_invoice_detail') {
        $iid = (int)($_GET['id'] ?? 0);
        $res = $conn->query("
            SELECT i.*, c.COMPANY_NAME, c.COMPANY_EMAIL, c.COMPANY_PHONE,
                   rf.REP_FAULT_ID, rf.DESCRIPTION AS FAULT_DESC, rf.STATUS AS FAULT_STATUS,
                   GROUP_CONCAT(DISTINCT e.FULL_NAME SEPARATOR ', ') AS TECHNICIANS
            FROM invoice i
            LEFT JOIN client c ON c.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN reported_fault rf ON rf.CLIENT_ID = i.CLIENT_ID
            LEFT JOIN assignment a ON a.REP_FAULT_ID = rf.REP_FAULT_ID
            LEFT JOIN assignment_technician at2 ON at2.ASSIGN_ID = a.ASSIGN_ID
            LEFT JOIN employee e ON e.EMP_ID = at2.EMP_ID
            WHERE i.INVOICE_ID = $iid AND i.CLIENT_ID = $client_id
            GROUP BY i.INVOICE_ID
        ");
        $inv = $res ? $res->fetch_assoc() : null;
        if (!$inv) { echo json_encode(['error'=>'Invoice not found']); exit; }

        // Get lines from DB (these are read-only, safe)
        $lines = [];
        $lr = $conn->query("SELECT * FROM invoice_line WHERE INVOICE_ID=$iid");
        if ($lr) while ($l = $lr->fetch_assoc()) $lines[] = $l;

        // Use SIMULATED payments only
        $payments = array_values(array_filter($_SESSION['sim_payments'], fn($p) => $p['INVOICE_ID'] == $iid));
        $paid_total = array_sum(array_column($payments, 'AMOUNT_PAID'));

        // Update invoice status from simulation
        $total = floatval($inv['TOTAL'] ?? 0);
        if ($paid_total >= $total && $total > 0) {
            $inv['STATUS'] = 'Paid';
        } elseif ($paid_total > 0) {
            $inv['STATUS'] = 'Partial';
        }

        // Work logs (read from DB, safe)
        $logs = [];
        if (!empty($inv['REP_FAULT_ID'])) {
            $fid = (int)$inv['REP_FAULT_ID'];
            $lr2 = $conn->query("
                SELECT wl.*, e.FULL_NAME
                FROM work_log wl
                JOIN assignment a ON a.ASSIGN_ID = wl.ASSIGN_ID
                JOIN reported_fault rf ON rf.REP_FAULT_ID = a.REP_FAULT_ID
                LEFT JOIN employee e ON e.EMP_ID = wl.EMP_ID
                WHERE rf.REP_FAULT_ID = $fid
                ORDER BY wl.LOG_DATE DESC LIMIT 5
            ");
            if ($lr2) while ($l = $lr2->fetch_assoc()) $logs[] = $l;
        }

        echo json_encode(['invoice'=>$inv,'lines'=>$lines,'payments'=>$payments,'paid_total'=>$paid_total,'logs'=>$logs]);
        exit;
    }

    // ── get_wallet_balance ───────────────────────────────────────────
    if ($action === 'get_wallet_balance') {
        echo json_encode(['balance' => floatval($_SESSION['sim_wallet'])]);
        exit;
    }

    // ── submit_payment ────────────────────────────────────────────────
    // SCHOOL PROJECT: All payment processing is simulated in session.
    // No INSERT into payment table needed — works 100% without DB columns.
    if ($action === 'submit_payment') {
        $iid    = (int)($_POST['invoice_id'] ?? 0);
        $amount = round(floatval($_POST['amount'] ?? 0), 2);
        $method = trim($_POST['method'] ?? '');
        $ref    = trim($_POST['reference'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        $network= trim($_POST['network'] ?? '');
        $bank   = trim($_POST['bank'] ?? '');
        $acc    = trim($_POST['account_number'] ?? '');

        if (!$iid || $amount <= 0) {
            echo json_encode(['success'=>false,'error'=>'Invalid invoice or amount.']); exit;
        }
        if (!$method) {
            echo json_encode(['success'=>false,'error'=>'Please select a payment method.']); exit;
        }

        // Fetch invoice from DB to get total
        $inv_res = $conn->query("SELECT INVOICE_ID, TOTAL, STATUS FROM invoice WHERE INVOICE_ID=$iid AND CLIENT_ID=$client_id");
        $inv = $inv_res ? $inv_res->fetch_assoc() : null;
        if (!$inv) {
            echo json_encode(['success'=>false,'error'=>'Invoice not found.']); exit;
        }

        // Calculate already-simulated paid amount
        $already_paid = 0;
        foreach ($_SESSION['sim_payments'] as $sp) {
            if ($sp['INVOICE_ID'] == $iid) $already_paid += $sp['AMOUNT_PAID'];
        }
        $inv_total   = floatval($inv['TOTAL']);
        $balance_due = round($inv_total - $already_paid, 2);

        if ($balance_due <= 0) {
            echo json_encode(['success'=>false,'error'=>'This invoice is already fully paid.']); exit;
        }
        if ($amount > $balance_due + 0.01) {
            echo json_encode(['success'=>false,'error'=>'Amount E'.number_format($amount,2).' exceeds balance due of E'.number_format($balance_due,2).'.']); exit;
        }

        // Wallet method: check simulated balance
        $pay_status = 'Confirmed';
        if ($method === 'Wallet') {
            if ($_SESSION['sim_wallet'] < $amount) {
                echo json_encode(['success'=>false,'error'=>'Insufficient wallet balance. Available: E'.number_format($_SESSION['sim_wallet'],2).'.']); exit;
            }
            $_SESSION['sim_wallet'] = round($_SESSION['sim_wallet'] - $amount, 2);
        }

        // Auto-generate reference
        if (!$ref) $ref = 'BQ-'.strtoupper(substr(md5(uniqid()),0,8));

        // Store payment in session
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

        // Recalculate new invoice status
        $new_paid = $already_paid + $amount;
        $new_inv_status = 'Partial';
        if ($new_paid >= $inv_total && $inv_total > 0) {
            $new_inv_status = 'Paid';
        }

        // ALSO try to update the real invoice STATUS in DB (optional, ignore errors)
        @$conn->query("UPDATE invoice SET STATUS='$new_inv_status' WHERE INVOICE_ID=$iid");

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
        ]);
        exit;
    }

    // ── get_transactions ─────────────────────────────────────────────
    if ($action === 'get_transactions') {
        $iid = (int)($_GET['id'] ?? 0);
        if ($iid) {
            $rows = array_values(array_filter($_SESSION['sim_payments'], fn($p) => $p['INVOICE_ID'] == $iid));
        } else {
            $rows = array_reverse($_SESSION['sim_payments']);
        }
        echo json_encode(array_values($rows));
        exit;
    }

    // ── get_stats ────────────────────────────────────────────────────
    if ($action === 'get_stats') {
        $total_inv   = (int)($conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id")->fetch_assoc()['n'] ?? 0);
        $total_val   = floatval($conn->query("SELECT COALESCE(SUM(TOTAL),0) n FROM invoice WHERE CLIENT_ID=$client_id")->fetch_assoc()['n'] ?? 0);
        $total_paid  = array_sum(array_column($_SESSION['sim_payments'], 'AMOUNT_PAID'));
        $total_due   = max(0, $total_val - $total_paid);
        $overdue_cnt = (int)($conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Overdue'")->fetch_assoc()['n'] ?? 0);
        $wallet      = floatval($_SESSION['sim_wallet']);
        echo json_encode(compact('total_inv','total_val','total_paid','total_due','overdue_cnt','wallet'));
        exit;
    }

    // ── reset_wallet ─────────────────────────────────────────────────
    // Resets simulation back to E1,000 (for demo purposes)
    if ($action === 'reset_wallet') {
        $_SESSION['sim_wallet']      = 1000.00;
        $_SESSION['sim_payments']    = [];
        $_SESSION['sim_pay_counter'] = 100;
        echo json_encode(['success'=>true,'balance'=>1000.00,'message'=>'Demo reset! Wallet back to E1,000.00 and all payments cleared.']);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
//  PAGE-LOAD DATA
// ══════════════════════════════════════════════════════════════════════
function dbVal(mysqli $c, string $sql): string {
    $r = $c->query($sql);
    return $r ? (string)array_values($r->fetch_assoc())[0] : '0';
}

$inv_total_count = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id");
$inv_unpaid      = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS IN ('Unpaid','Partial','Overdue')");
$inv_paid        = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Paid'");
$inv_overdue     = (int)dbVal($conn, "SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Overdue'");
$total_billed    = floatval(dbVal($conn, "SELECT COALESCE(SUM(TOTAL),0) n FROM invoice WHERE CLIENT_ID=$client_id"));
$total_paid_amt  = array_sum(array_column($_SESSION['sim_payments'], 'AMOUNT_PAID'));
$wallet_balance  = floatval($_SESSION['sim_wallet']); // Simulated wallet balance
$client_row      = $conn->query("SELECT * FROM client WHERE CLIENT_ID=$client_id")->fetch_assoc();
$c_initial       = strtoupper(substr($client_row['COMPANY_NAME'] ?? $client_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoices & Payments — BUSIQUIP ESWATINI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══ DESIGN TOKENS ════════════════════════════════════════════════ */
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
.ticker-inner{display:flex;gap:70px;white-space:nowrap;animation:tick 30s linear infinite;
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
    display:flex;align-items:center;justify-content:center;font-size:20px;animation:spin-s 14s linear infinite;box-shadow:0 0 18px var(--burg-g)}
@keyframes spin-s{to{transform:rotate(360deg)}}
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
.ni .nb.tl{background:var(--teal)}
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
.al{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:var(--r);font-size:13px;font-weight:500;pointer-events:all;backdrop-filter:var(--blur);box-shadow:var(--sh);min-width:260px;max-width:360px;animation:alin .3s ease,alout .4s ease 5s forwards}
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
.pg-head .breadcrumb a{color:var(--gold);text-decoration:none}
.pg-head .breadcrumb span{color:var(--t3)}

/* STATS GRID */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:28px}
.sc{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);padding:20px 18px;backdrop-filter:var(--blur);cursor:pointer;position:relative;overflow:hidden;transition:var(--tr);animation:pi .45s ease both}
@keyframes pi{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
.sc:nth-child(1){animation-delay:.05s}.sc:nth-child(2){animation-delay:.1s}.sc:nth-child(3){animation-delay:.15s}.sc:nth-child(4){animation-delay:.2s}.sc:nth-child(5){animation-delay:.25s}.sc:nth-child(6){animation-delay:.3s}
.sc::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--burg-g),transparent);opacity:0;transition:opacity .3s}
.sc:hover{transform:translateY(-5px);border-color:var(--borh);box-shadow:var(--sh)}
.sc:hover::after{opacity:1}
.si-w{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:12px}
.si-b{background:rgba(139,0,0,.2);color:var(--burg2)}.si-g{background:rgba(232,184,75,.14);color:var(--gold)}
.si-t{background:rgba(13,148,136,.14);color:var(--teal)}.si-e{background:rgba(16,185,129,.14);color:var(--em)}
.si-r{background:rgba(244,63,94,.14);color:#F43F5E}.si-s{background:rgba(14,165,233,.14);color:var(--sky)}
.si-p{background:rgba(139,92,246,.14);color:var(--pur)}
.sn{font-family:var(--fh);font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--t1),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:3px}
.sl{font-size:11px;color:var(--t2);letter-spacing:.03em}.ss{font-size:10px;color:var(--t3);margin-top:5px;font-family:var(--fm)}

/* WALLET CARD */
.wallet-card{background:linear-gradient(135deg,rgba(139,0,0,.25),rgba(232,184,75,.12));border:1px solid var(--borh);border-radius:var(--rx);padding:24px 28px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;animation:sd .6s ease .1s both}
.wc-left .wl{font-size:11px;color:var(--t3);letter-spacing:.1em;text-transform:uppercase;font-family:var(--fm);margin-bottom:6px}
.wc-left .wa{font-family:var(--fh);font-size:36px;font-weight:800;color:var(--gold2)}
.wc-left .ws{font-size:12px;color:var(--t2);margin-top:4px}
.wc-right{display:flex;gap:10px;flex-wrap:wrap}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.toolbar h2{font-family:var(--fh);font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px}
.toolbar h2 .dot{width:9px;height:9px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));box-shadow:0 0 7px var(--burg-g)}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap}
.ftab{padding:6px 14px;border-radius:99px;border:1px solid var(--bor);background:none;color:var(--t2);font-size:11px;font-weight:600;cursor:pointer;transition:var(--tr)}
.ftab:hover,.ftab.act{background:var(--gold-p);border-color:var(--borh);color:var(--gold)}
.ml-auto{margin-left:auto}

/* INVOICE TABLE */
.inv-table-wrap{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);backdrop-filter:var(--blur);overflow:hidden;margin-bottom:28px}
.inv-table{width:100%;border-collapse:collapse}
.inv-table th{padding:11px 14px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);border-bottom:1px solid var(--bor);font-weight:600;font-family:var(--fm);white-space:nowrap}
.inv-table td{padding:14px 14px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03);vertical-align:middle}
.inv-table tr:hover td{background:var(--gl)}
.inv-table tr:last-child td{border-bottom:none}
.inv-table .inv-num{font-family:var(--fm);color:var(--gold);font-weight:600;font-size:13px}
.inv-table .amt{font-family:var(--fm);font-weight:700;color:var(--t1)}
.inv-table .bal{font-family:var(--fm);font-weight:700;color:var(--dan)}
.inv-table .bal.zero{color:var(--em)}

/* PROGRESS BAR */
.pb-wrap{width:90px;background:var(--bg3);border-radius:99px;height:5px;overflow:hidden}
.pb-bar{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--burg),var(--gold));transition:width 1.2s ease}
.pb-pct{font-size:10px;color:var(--t3);font-family:var(--fm);margin-top:2px}

/* BADGES */
.b{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.b-paid{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-unpaid{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
.b-partial{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.b-overdue{background:rgba(244,63,94,.2);color:#F43F5E;border:1px solid rgba(244,63,94,.4)}
.b-pending{background:rgba(99,102,241,.14);color:var(--ind);border:1px solid rgba(99,102,241,.3)}
.b-conf{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-pv{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.b-fail{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:12px;font-weight:600;border:none;cursor:pointer;transition:var(--tr);flex-shrink:0}
.btn-p{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-p:hover{transform:translateY(-2px);box-shadow:0 8px 22px var(--burg-g)}
.btn-s{background:none;border:1px solid var(--borh);color:var(--gold)}
.btn-s:hover{background:var(--gold-p)}
.btn-e{background:var(--em);color:#fff}.btn-e:hover{background:#059669;transform:translateY(-2px)}
.btn-d{background:var(--dan);color:#fff}.btn-d:hover{background:#B91C1C}
.btn-t{background:rgba(13,148,136,.2);border:1px solid var(--teal);color:var(--teal)}
.btn-t:hover{background:rgba(13,148,136,.35)}
.btn-sm{padding:6px 12px;font-size:11px}
.btn-full{width:100%;justify-content:center}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none !important}

/* MODAL */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:3000;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(6px)}
.mo.show{display:flex;animation:fi .2s ease}
@keyframes fi{from{opacity:0}to{opacity:1}}
.mb{background:var(--bg2);border:1px solid var(--borh);border-radius:var(--rx);width:100%;max-width:700px;max-height:94vh;overflow-y:auto;box-shadow:var(--shl);animation:mu .32s cubic-bezier(.34,1.56,.64,1)}
.mb.wide{max-width:860px}
@keyframes mu{from{transform:translateY(38px) scale(.96);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.mh{padding:22px 26px 18px;border-bottom:1px solid var(--bor);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--bg2);z-index:1}
.mh h2{font-family:var(--fh);font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px}
.mc{width:34px;height:34px;border-radius:50%;border:1px solid var(--bor);background:none;color:var(--t2);font-size:17px;display:flex;align-items:center;justify-content:center;transition:var(--tr)}
.mc:hover{border-color:var(--gold);color:var(--gold);transform:rotate(90deg)}
.mbody{padding:22px 26px;display:grid;gap:16px}
.mfoot{padding:16px 26px 22px;border-top:1px solid var(--bor);display:flex;gap:10px;flex-wrap:wrap}
.mtabs{display:flex;gap:3px;padding:14px 26px 0;border-bottom:1px solid var(--bor)}
.mt{padding:9px 16px;background:none;border:none;color:var(--t2);font-size:12px;font-weight:600;border-bottom:2px solid transparent;cursor:pointer;transition:var(--tr);margin-bottom:-1px}
.mt.act{color:var(--gold);border-bottom-color:var(--gold)}
.tp{display:none}.tp.act{display:block;padding-top:16px}

/* FORM */
.fr{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:11px;font-weight:600;color:var(--t2);letter-spacing:.04em;text-transform:uppercase}
.fg input,.fg select,.fg textarea{background:var(--bg3);border:1px solid var(--bor);color:var(--t1);padding:10px 13px;border-radius:8px;font-family:var(--fb);font-size:13px;transition:var(--tr);outline:none;width:100%}
.fg input::placeholder,.fg textarea::placeholder{color:var(--t3)}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.fg select option{background:var(--bg2);color:var(--t1)}
.full{grid-column:1/-1}

/* DETAIL GRID */
.dg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.di{padding:11px 13px;background:var(--bg3);border:1px solid var(--bor);border-radius:8px}
.di .k{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.di .v{font-size:13px;font-weight:600}
.di.full{grid-column:1/-1}

/* COST BREAKDOWN */
.cost-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px}
.cost-row:last-child{border:none;font-weight:700;font-size:14px;color:var(--gold);padding-top:14px;border-top:2px solid var(--borh)}
.cost-row .lbl{color:var(--t2)}.cost-row .val{font-family:var(--fm);font-weight:600}

/* PAYMENT METHOD CARDS */
.pm-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.pm-card{padding:16px;border-radius:var(--r);border:2px solid var(--bor);background:var(--bg3);cursor:pointer;transition:var(--tr);text-align:center}
.pm-card:hover{border-color:var(--borh)}
.pm-card.sel{border-color:var(--gold);background:var(--gold-p);box-shadow:0 0 18px var(--gold-p)}
.pm-card i{font-size:24px;margin-bottom:8px;display:block}
.pm-card .pm-label{font-size:12px;font-weight:700;font-family:var(--fh)}
.pm-card .pm-sub{font-size:10px;color:var(--t3);margin-top:3px}
.pm-mobile i{color:var(--em)}.pm-bank i{color:var(--sky)}.pm-wallet i{color:var(--gold)}

/* PAYMENT FIELD PANELS */
.pay-panel{display:none;animation:fi .2s ease}.pay-panel.show{display:block}

/* BALANCE DISPLAY */
.bal-display{background:linear-gradient(135deg,rgba(232,184,75,.08),rgba(139,0,0,.08));border:1px solid var(--borh);border-radius:var(--r);padding:14px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.bd-item .bdl{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.07em;font-family:var(--fm)}
.bd-item .bdv{font-family:var(--fh);font-size:18px;font-weight:800;color:var(--gold)}
.bd-item .bdv.red{color:var(--dan)}.bd-item .bdv.grn{color:var(--em)}

/* SUCCESS OVERLAY */
.pay-success{display:none;text-align:center;padding:30px;animation:fi .3s ease}
.pay-success.show{display:block}
.ps-icon{width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,var(--em),var(--teal));display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px;animation:pop .5s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}

/* SPINNER */
.spin{width:34px;height:34px;border-radius:50%;border:3px solid var(--bor);border-top-color:var(--gold);animation:sp .8s linear infinite;margin:18px auto}
@keyframes sp{to{transform:rotate(360deg)}}
.empty{text-align:center;padding:36px 18px}
.empty i{font-size:36px;color:var(--t3);margin-bottom:10px;display:block}
.empty p{color:var(--t2);font-size:13px}

/* INFO BOX */
.ib{padding:12px 14px;border-radius:8px;display:flex;gap:10px;align-items:flex-start;font-size:12px}
.ib-g{background:rgba(232,184,75,.09);border-left:3px solid var(--gold);color:var(--t1)}
.ib-t{background:rgba(13,148,136,.09);border-left:3px solid var(--teal);color:var(--t1)}
.ib-e{background:rgba(16,185,129,.09);border-left:3px solid var(--em);color:var(--t1)}
.ib-r{background:rgba(239,68,68,.09);border-left:3px solid var(--dan);color:var(--t1)}

/* TRANSACTION TABLE */
.tbl{width:100%;border-collapse:collapse}
.tbl th{padding:9px 13px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);border-bottom:1px solid var(--bor);font-weight:600}
.tbl td{padding:11px 13px;font-size:12px;border-bottom:1px solid rgba(255,255,255,.03)}
.tbl tr:hover td{background:var(--gl)}
.tbl tr:last-child td{border-bottom:none}

/* WORK LOG */
.wl-item{padding:11px 14px;background:var(--bg3);border:1px solid var(--bor);border-radius:8px;margin-bottom:8px}
.wl-item .wl-top{display:flex;justify-content:space-between;margin-bottom:4px}
.wl-item .wl-name{font-weight:600;font-size:12px}
.wl-item .wl-date{font-size:10px;color:var(--t3);font-family:var(--fm)}
.wl-item .wl-act{font-size:12px;color:var(--t2)}

/* MOBILE NETWORK SELECT */
.net-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.net-opt{padding:10px;border:1px solid var(--bor);border-radius:8px;background:var(--bg3);cursor:pointer;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;transition:var(--tr)}
.net-opt:hover,.net-opt.sel{border-color:var(--gold);background:var(--gold-p);color:var(--gold)}
.net-dot{width:12px;height:12px;border-radius:50%}
.net-mtn .net-dot{background:#FFCB05}.net-esw .net-dot{background:#00A650}

/* DIVIDER */
.div{display:flex;align-items:center;gap:12px;margin:22px 0}
.div::before,.div::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,transparent,var(--bor),transparent)}
.div span{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);white-space:nowrap}

/* FOOTER */
footer{background:var(--bg1);border-top:1px solid var(--bor);padding:22px 28px;text-align:center;margin-left:var(--sw);position:relative;z-index:1}
footer p{font-size:11px;color:var(--t3)}footer strong{color:var(--gold);font-family:var(--fh)}

@media(max-width:768px){
    .fr{grid-template-columns:1fr}.dg{grid-template-columns:1fr}
    .pm-grid{grid-template-columns:1fr}
    footer{margin-left:0}
    .inv-table-wrap{overflow-x:auto}
    .h-nm{display:none}
    .toolbar{flex-direction:column;align-items:flex-start}
}
</style>
</head>
<body>

<div class="bg-grid"></div>
<div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div>

<!-- TICKER -->
<div class="ticker">
    <div class="ticker-inner">
        <span>💰 BUSIQUIP ESWATINI — BILLING & PAYMENT SYSTEM &nbsp;✦&nbsp; View · Pay · Track</span>
        <span>🧾 Invoices for <?php echo htmlspecialchars($client_name); ?> &nbsp;✦&nbsp; Wallet Balance: E<?php echo number_format($wallet_balance,2); ?></span>
        <span>📊 Total Invoices: <?php echo $inv_total_count; ?> &nbsp;✦&nbsp; Unpaid: <?php echo $inv_unpaid; ?> &nbsp;✦&nbsp; Paid: <?php echo $inv_paid; ?></span>
        <span>💰 BUSIQUIP ESWATINI — BILLING & PAYMENT SYSTEM &nbsp;✦&nbsp; View · Pay · Track</span>
        <span>🧾 Invoices for <?php echo htmlspecialchars($client_name); ?> &nbsp;✦&nbsp; Wallet Balance: E<?php echo number_format($wallet_balance,2); ?></span>
        <span>📊 Total Invoices: <?php echo $inv_total_count; ?> &nbsp;✦&nbsp; Unpaid: <?php echo $inv_unpaid; ?> &nbsp;✦&nbsp; Paid: <?php echo $inv_paid; ?></span>
    </div>
</div>

<!-- HEADER -->
<header>
    <a href="client_portal.php" class="brand" target="_blank">
        <div class="brand-ic"⚙️</div>
        <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
    </a>
    <div class="h-search">
        <i class="fas fa-search"></i>
        <input type="text" id="gsearch" placeholder="Search invoices by number, status, amount…" oninput="filterInvoices(this.value)">
    </div>
    <div class="h-right">
        <div class="h-nm"><div class="n"><?php echo htmlspecialchars($client_name); ?></div><div class="e"><?php echo htmlspecialchars($client_email); ?></div></div>
        <div class="h-av" onclick="window.open('client_profile.php','_blank')" title="My Profile">
            <?php echo $c_initial; ?><span class="dot"></span>
        </div>
        <button class="hb" onclick="window.open('client_notifications.php','_blank')" title="Notifications"><i class="fas fa-bell"></i></button>
        <form method="POST" style="display:inline">
            <button type="submit" name="logout" class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
        </form>
    </div>
</header>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="slbl">Main Menu</div>
    <div class="ni" onclick="window.open('client_portal.php','_blank')"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</div>
    <div class="ni" onclick="window.open('client_profile.php','_blank')"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</div>
    <div class="ni" onclick="window.open('report_fault.php','_blank')"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></div>

    <div class="slbl" style="margin-top:8px">Equipment</div>
    <div class="ni" onclick="window.open('client_faults.php','_blank')"><div class="ic"><i class="fas fa-tools"></i></div> My Faults</div>
    <div class="ni" onclick="window.open('client_repair_progress.php','_blank')"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</div>
    <div class="ni" onclick="window.open('client_products.php','_blank')"><div class="ic"><i class="fas fa-box-open"></i></div> My Products</div>
    <div class="ni" onclick="window.open('client_technicians.php','_blank')"><div class="ic"><i class="fas fa-user-cog"></i></div> Technicians</div>

    <div class="slbl" style="margin-top:8px">Finance</div>
    <div class="ni act" onclick="window.open('client_invoices.php','_blank')"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices <?php if($inv_unpaid>0): ?><span class="nb"><?php echo $inv_unpaid; ?></span><?php endif; ?></div>
    <div class="ni" onclick="window.open('client_make_payment.php','_blank')"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment</div>
    <div class="ni" onclick="window.open('client_payment_history.php','_blank')"><div class="ic"><i class="fas fa-history"></i></div> Payment History</div>

    <div class="slbl" style="margin-top:8px">More</div>
    <button class="exp-btn" id="exp-btn" onclick="togMore()">
        <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
    </button>
    <div class="sub-menu" id="sub-menu">
        <div class="si" onclick="window.open('client_notifications.php','_blank')"><i class="fas fa-bell"></i> Notifications</div>
        <div class="si" onclick="window.open('client_documents.php','_blank')"><i class="fas fa-folder"></i> Documents</div>
        <div class="si" onclick="window.open('client_reports.php','_blank')"><i class="fas fa-chart-line"></i> Reports</div>
        <div class="si" onclick="window.open('client_help.php','_blank')"><i class="fas fa-life-ring"></i> Help & Support</div>
        <div class="si" onclick="window.open('client_settings.php','_blank')"><i class="fas fa-cog"></i> Settings</div>
    </div>

    <div class="s-banner">
        <i class="fas fa-headset"></i>
        <p>Payment queries? Our finance team is available Mon–Fri 8AM–5PM.</p>
        <a href="mailto:billing@busiquip.co.sz">Contact Billing</a>
    </div>
</aside>

<!-- ALERTS -->
<div id="alerts"></div>

<!-- MAIN -->
<main>

<!-- PAGE HEADER -->
<div class="pg-head">
    <div class="breadcrumb">
        <a href="client_portal.php" target="_blank"><i class="fas fa-home"></i> Dashboard</a>
        <span>›</span><span>Invoices & Payments</span>
    </div>
    <h1><i class="fas fa-receipt" style="font-size:24px;-webkit-text-fill-color:var(--gold);margin-right:8px"></i>Invoices & Payments</h1>
    <p>View all invoices issued to your account, track payment status, and make payments securely.</p>
</div>

<!-- STATS -->
<div class="stats">
    <div class="sc" onclick="setFilter('all')">
        <div class="si-w si-b"><i class="fas fa-file-invoice"></i></div>
        <div class="sn"><?php echo $inv_total_count; ?></div>
        <div class="sl">Total Invoices</div><div class="ss">All time</div>
    </div>
    <div class="sc" onclick="setFilter('Unpaid')">
        <div class="si-w si-r"><i class="fas fa-exclamation-circle"></i></div>
        <div class="sn"><?php echo $inv_unpaid; ?></div>
        <div class="sl">Unpaid / Partial</div><div class="ss">Action required</div>
    </div>
    <div class="sc" onclick="setFilter('Paid')">
        <div class="si-w si-e"><i class="fas fa-check-circle"></i></div>
        <div class="sn"><?php echo $inv_paid; ?></div>
        <div class="sl">Fully Paid</div><div class="ss">Completed</div>
    </div>
    <div class="sc" onclick="setFilter('Overdue')">
        <div class="si-w si-r"><i class="fas fa-clock"></i></div>
        <div class="sn"><?php echo $inv_overdue; ?></div>
        <div class="sl">Overdue</div><div class="ss">Past due date</div>
    </div>
    <div class="sc">
        <div class="si-w si-g"><i class="fas fa-coins"></i></div>
        <div class="sn" style="font-size:20px">E <?php echo number_format($total_billed,0); ?></div>
        <div class="sl">Total Billed</div><div class="ss">All invoices</div>
    </div>
    <div class="sc">
        <div class="si-w si-t"><i class="fas fa-arrow-down"></i></div>
        <div class="sn" style="font-size:20px">E <?php echo number_format(max(0,$total_billed-$total_paid_amt),0); ?></div>
        <div class="sl">Outstanding</div><div class="ss">Remaining balance</div>
    </div>
</div>

<!-- WALLET CARD -->
<div class="wallet-card">
    <div class="wc-left">
        <div class="wl"><i class="fas fa-wallet"></i> &nbsp;My Wallet Balance &nbsp;<span style="background:var(--burg);color:#fff;font-size:9px;padding:2px 8px;border-radius:99px;font-family:var(--fm);letter-spacing:.08em">DEMO</span></div>
        <div class="wa" id="wallet-amount">E <?php echo number_format($wallet_balance,2); ?></div>
        <div class="ws">Simulated wallet — starts at <strong>E1,000.00</strong> per session &nbsp;·&nbsp; School Project Demo</div>
    </div>
    <div class="wc-right">
        <button class="btn btn-p" id="btn-topup" onclick="resetDemo()" title="Reset wallet to E1,000 and clear all demo payments"><i class="fas fa-redo"></i> Reset Demo (E1,000)</button>
        <button class="btn btn-s" onclick="openTxnHistory(0)"><i class="fas fa-history"></i> All Transactions</button>
    </div>
</div>

<!-- INVOICE LIST -->
<div class="toolbar">
    <h2><span class="dot"></span> My Invoices</h2>
    <div class="filter-tabs">
        <button class="ftab act" id="ftab-all" onclick="setFilter('all')">All</button>
        <button class="ftab" id="ftab-Unpaid" onclick="setFilter('Unpaid')">Unpaid</button>
        <button class="ftab" id="ftab-Partial" onclick="setFilter('Partial')">Partial</button>
        <button class="ftab" id="ftab-Overdue" onclick="setFilter('Overdue')">Overdue</button>
        <button class="ftab" id="ftab-Paid" onclick="setFilter('Paid')">Paid</button>
    </div>
    <div class="ml-auto" style="display:flex;gap:8px">
        <button class="btn btn-s btn-sm" onclick="window.open('client_payment_history.php','_blank')"><i class="fas fa-history"></i> All Transactions</button>
        <button class="btn btn-p btn-sm" onclick="loadInvoices()"><i class="fas fa-sync"></i> Refresh</button>
    </div>
</div>

<div class="inv-table-wrap">
    <div id="inv-loading" style="padding:32px;text-align:center"><div class="spin"></div></div>
    <table class="inv-table" id="inv-table" style="display:none">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Due Date</th>
                <th>Fault Ref</th>
                <th>Technician</th>
                <th>Total (E)</th>
                <th>Paid (E)</th>
                <th>Balance (E)</th>
                <th>Progress</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="inv-tbody"></tbody>
    </table>
    <div id="inv-empty" style="display:none" class="empty">
        <i class="fas fa-receipt"></i><p>No invoices found.</p>
    </div>
</div>

</main>

<footer>
    <p>&copy; <?php echo date("Y"); ?> <strong>BUSIQUIP</strong> Billing System &nbsp;|&nbsp; 🇸🇿 Eswatini &nbsp;|&nbsp; All Rights Reserved</p>
</footer>

<!-- ═══════════════════════ MODALS ═══════════════════════════════════ -->

<!-- INVOICE DETAIL MODAL -->
<div class="mo" id="m-detail">
<div class="mb wide">
    <div class="mh">
        <h2><i class="fas fa-file-invoice" style="color:var(--gold)"></i><span id="det-title">Invoice Detail</span></h2>
        <button class="mc" onclick="cm('m-detail')">×</button>
    </div>
    <div class="mtabs">
        <button class="mt act" onclick="stab(this,'t-overview')">Overview</button>
        <button class="mt" onclick="stab(this,'t-breakdown')">Cost Breakdown</button>
        <button class="mt" onclick="stab(this,'t-payments')">Payments</button>
        <button class="mt" onclick="stab(this,'t-worklog')">Work Log</button>
    </div>
    <div style="padding:0 26px 22px">
        <div id="t-overview" class="tp act"></div>
        <div id="t-breakdown" class="tp"></div>
        <div id="t-payments" class="tp"></div>
        <div id="t-worklog" class="tp"></div>
    </div>
    <div class="mfoot" id="det-foot"></div>
</div>
</div>

<!-- PAYMENT MODAL -->
<div class="mo" id="m-pay">
<div class="mb wide">
    <div class="mh">
        <h2><i class="fas fa-credit-card" style="color:var(--gold)"></i>Make Payment — Invoice #<span id="pay-inv-num"></span></h2>
        <button class="mc" onclick="cm('m-pay');resetPayForm()">×</button>
    </div>

    <!-- Success overlay (shown after payment) -->
    <div id="pay-success-overlay" class="pay-success" style="padding:40px;display:none">
        <div class="ps-icon">✓</div>
        <h3 id="pso-title" style="font-family:var(--fh);font-size:22px;font-weight:800;margin-bottom:8px;color:var(--em)">Payment Successful!</h3>
        <p id="pso-msg" style="color:var(--t2);font-size:14px;margin-bottom:8px"></p>
        <div id="pso-ref" style="font-family:var(--fm);font-size:12px;color:var(--gold);padding:10px 20px;background:var(--bg3);border-radius:10px;display:inline-block;margin:10px 0;word-break:break-all"></div>
        <!-- Receipt box -->
        <div id="pso-receipt" style="background:var(--bg2);border:1px solid var(--bor);border-radius:12px;padding:16px;margin-top:14px;text-align:left;max-width:380px;margin-inline:auto"></div>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap">
            <button class="btn btn-e" onclick="cm('m-pay');resetPayForm();loadInvoices()"><i class="fas fa-check-double"></i> Done</button>
            <button class="btn btn-t" onclick="openTxnHistory(document.getElementById('pay-inv-id').value)"><i class="fas fa-exchange-alt"></i> View Transactions</button>
        </div>
    </div>

    <div id="pay-form-wrap">
        <div class="mbody" style="padding-bottom:0">
            <div id="pay-inv-info" class="ib ib-g"><i class="fas fa-info-circle" style="color:var(--gold)"></i><div></div></div>

            <!-- Balance Display -->
            <div class="bal-display" id="pay-bal-display">
                <div class="bd-item">
                    <div class="bdl">Invoice Total</div>
                    <div class="bdv" id="bd-total">E 0.00</div>
                </div>
                <div class="bd-item">
                    <div class="bdl">Already Paid</div>
                    <div class="bdv grn" id="bd-paid">E 0.00</div>
                </div>
                <div class="bd-item">
                    <div class="bdl">Balance Due</div>
                    <div class="bdv red" id="bd-due">E 0.00</div>
                </div>
                <div class="bd-item">
                    <div class="bdl">Wallet Balance</div>
                    <div class="bdv" id="bd-wallet" style="color:var(--sky)">E 0.00</div>
                </div>
            </div>

            <input type="hidden" id="pay-inv-id" value="">

            <!-- Step 1: Method Selection -->
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;font-family:var(--fm)">Select Payment Method</div>
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
            </div>

            <!-- MOBILE PAYMENT PANEL -->
            <div id="panel-mobile" class="pay-panel">
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
                    <div class="fg">
                        <label>Mobile Number *</label>
                        <input type="tel" id="mob-number" placeholder="e.g. 76123456" maxlength="15">
                    </div>
                    <div class="fg">
                        <label>Amount (E) *</label>
                        <input type="number" id="mob-amount" placeholder="0.00" step="0.01" min="1" oninput="updateMobAfter()">
                    </div>
                    <div class="fg">
                        <label>Payment PIN (simulated)</label>
                        <input type="password" id="mob-pin" placeholder="••••" maxlength="6">
                    </div>
                    <div class="fg">
                        <label>Reference (auto)</label>
                        <input type="text" id="mob-ref" readonly style="opacity:.7">
                    </div>
                </div>
                <div class="bal-display" style="margin-top:12px">
                    <div class="bd-item"><div class="bdl">Current Balance</div><div class="bdv" id="mob-bal-before">—</div></div>
                    <div class="bd-item"><div class="bdl">After Payment</div><div class="bdv red" id="mob-bal-after">—</div></div>
                    <div class="bd-item"><div class="bdl">Status</div><div class="bdv" id="mob-status" style="font-size:13px;color:var(--ind)">Pending</div></div>
                </div>
            </div>

            <!-- BANK TRANSFER PANEL -->
            <div id="panel-bank" class="pay-panel">
                <div class="div"><span>Bank Transfer Details</span></div>
                <div class="fr">
                    <div class="fg">
                        <label>Select Bank *</label>
                        <select id="bank-name">
                            <option value="">— Choose Bank —</option>
                            <option>First National Bank (FNB)</option>
                            <option>Standard Bank Eswatini</option>
                            <option>Nedbank Eswatini</option>
                            <option>Swazi Bank</option>
                            <option>Central Bank of Eswatini</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Account Number</label>
                        <input type="text" id="bank-acc" placeholder="Your registered account">
                    </div>
                    <div class="fg">
                        <label>Amount (E) *</label>
                        <input type="number" id="bank-amount" placeholder="0.00" step="0.01" min="1">
                    </div>
                    <div class="fg">
                        <label>Reference (auto-generated)</label>
                        <input type="text" id="bank-ref" readonly style="opacity:.7">
                    </div>
                    <div class="fg full">
                        <label>Upload Proof of Payment (JPG/PNG/PDF)</label>
                        <input type="file" id="bank-proof" accept=".jpg,.jpeg,.png,.pdf" style="padding:8px">
                    </div>
                </div>
                <div class="ib ib-t" style="margin-top:8px">
                    <i class="fas fa-info-circle" style="color:var(--teal)"></i>
                    <div>Bank transfer payments are reviewed by our accountant. Status will show <strong>Pending Verification</strong> until confirmed. You'll be notified once approved.</div>
                </div>
                <div class="bal-display" style="margin-top:10px">
                    <div class="bd-item"><div class="bdl">Bank Balance (before)</div><div class="bdv">—</div></div>
                    <div class="bd-item"><div class="bdl">After Payment</div><div class="bdv red">—</div></div>
                    <div class="bd-item"><div class="bdl">Status</div><div class="bdv" style="font-size:12px;color:var(--warn)">Pending Verification</div></div>
                </div>
            </div>

            <!-- WALLET PAYMENT PANEL -->
            <div id="panel-wallet" class="pay-panel">
                <div class="div"><span>Wallet Payment</span></div>
                <div class="bal-display">
                    <div class="bd-item"><div class="bdl">Wallet Balance</div><div class="bdv" id="wal-before">E 0.00</div></div>
                    <div class="bd-item"><div class="bdl">Paying</div><div class="bdv red" id="wal-paying">E 0.00</div></div>
                    <div class="bd-item"><div class="bdl">New Balance</div><div class="bdv grn" id="wal-after">E 0.00</div></div>
                    <div class="bd-item"><div class="bdl">Status</div><div class="bdv" style="font-size:12px;color:var(--em)">Instant</div></div>
                </div>
                <div class="fr">
                    <div class="fg">
                        <label>Amount to Pay (E) *</label>
                        <input type="number" id="wal-amount" placeholder="0.00" step="0.01" min="1" oninput="updateWalAfter()">
                    </div>
                    <div class="fg" style="align-self:end">
                        <button class="btn btn-s" onclick="payFullWallet()"><i class="fas fa-check-double"></i> Pay Full Balance</button>
                    </div>
                </div>
                <div class="ib ib-e" style="margin-top:6px">
                    <i class="fas fa-bolt" style="color:var(--em)"></i>
                    <div>Wallet payments are <strong>processed instantly</strong>. The invoice status will update immediately after confirmation.</div>
                </div>
            </div>

        </div><!-- end mbody -->

        <div class="mfoot">
            <button class="btn btn-p" id="btn-submit-pay" onclick="submitPayment()" disabled><i class="fas fa-lock"></i> Confirm Payment</button>
            <button class="btn btn-s" onclick="cm('m-pay');resetPayForm()">Cancel</button>
            <div style="margin-left:auto;font-size:11px;color:var(--t3);display:flex;align-items:center;gap:6px"><i class="fas fa-shield-alt" style="color:var(--em)"></i> Secured & Encrypted</div>
        </div>
    </div>
</div>
</div>

<!-- TRANSACTION DETAIL MODAL -->
<div class="mo" id="m-txn">
<div class="mb wide">
    <div class="mh">
        <h2><i class="fas fa-exchange-alt" style="color:var(--sky)"></i>Transaction History — Invoice #<span id="txn-inv-num"></span></h2>
        <button class="mc" onclick="cm('m-txn')">×</button>
    </div>
    <div class="mbody">
        <div id="txn-body"><div class="spin"></div></div>
    </div>
    <div class="mfoot">
        <button class="btn btn-s" onclick="cm('m-txn')"><i class="fas fa-arrow-left"></i> Close</button>
    </div>
</div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
//  GLOBALS
// ═══════════════════════════════════════════════════════════════════
let allInvoices = [];
let currentFilter = 'all';
let walletBal = <?php echo $wallet_balance; ?>;
let selectedMethod = '';
let selectedNetwork = '';
let currentInvForPay = null;

// ── helpers ────────────────────────────────────────────────────────
const h = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const cur = v => 'E '+parseFloat(v||0).toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2});
const fd  = s => s ? new Date(s).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : 'N/A';
const om  = id => document.getElementById(id).classList.add('show');
const cm  = id => document.getElementById(id).classList.remove('show');

// ── ROBUST FETCH: never shows "Network error" for server-side issues ──
// Uses the script's own path so relative URLs always resolve correctly.
const SCRIPT_URL = (()=>{
    // Get clean path without query string
    return window.location.pathname;
})();

async function apiFetch(params, postData = null) {
    const url = SCRIPT_URL + '?' + new URLSearchParams(params).toString();
    const opts = postData
        ? { method: 'POST', body: postData }
        : { method: 'GET' };
    try {
        const res = await fetch(url, opts);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch(parseErr) {
            // Server returned non-JSON (PHP error, HTML page, etc.)
            // Extract the actual error from the response text for debugging
            const snippet = text.replace(/<[^>]+>/g,' ').trim().substring(0,200);
            console.error('API returned non-JSON:', text);
            return { success: false, error: 'Server error: ' + (snippet || 'Unknown. Check PHP error log.') };
        }
    } catch(netErr) {
        console.error('Fetch failed:', netErr);
        return { success: false, error: 'Could not reach the server. Make sure the PHP server is running on this machine.' };
    }
}

// ── Reset demo wallet (E1,000 + clears all payments) ──────────────
async function resetDemo(){
    if(!confirm('Reset demo? This will set your wallet back to E1,000.00 and clear all demo payments.')) return;
    const btn = document.getElementById('btn-topup');
    if(btn){ btn.disabled=true; btn.textContent='Resetting…'; }
    const data = await apiFetch({action:'reset_wallet'});
    if(data.success){
        walletBal = 1000.00;
        document.getElementById('wallet-amount').textContent = 'E ' + walletBal.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2});
        alert2('s', '✓ Demo reset! Wallet = E1,000.00. All demo payments cleared.');
        loadInvoices();
    } else {
        alert2('e', data.error || 'Reset failed.');
    }
    if(btn){ btn.disabled=false; btn.innerHTML='<i class="fas fa-redo"></i> Reset Demo (E1,000)'; }
}

function alert2(t,msg){
    const d=document.createElement('div');
    d.className='al al-'+(t==='s'?'s':t==='e'?'e':t==='w'?'w':'i');
    d.innerHTML=`<i class="fas fa-${t==='s'?'check-circle':t==='e'?'exclamation-circle':t==='w'?'exclamation-triangle':'info-circle'}"></i>${h(msg)}`;
    document.getElementById('alerts').appendChild(d);
    setTimeout(()=>d.remove(),5500);
}

function stab(btn,tid){
    btn.closest('.mtabs').querySelectorAll('.mt').forEach(b=>b.classList.remove('act'));
    btn.classList.add('act');
    btn.closest('.mb').querySelectorAll('.tp').forEach(t=>t.classList.remove('act'));
    document.getElementById(tid).classList.add('act');
}

function togMore(){
    const sm=document.getElementById('sub-menu');
    const btn=document.getElementById('exp-btn');
    sm.classList.toggle('open');
    btn.classList.toggle('open');
}

function togTheme(){document.body.classList.toggle('lm')}

// ═══════════════════════════════════════════════════════════════════
//  LOAD INVOICES
// ═══════════════════════════════════════════════════════════════════
async function loadInvoices(){
    document.getElementById('inv-loading').style.display='block';
    document.getElementById('inv-table').style.display='none';
    document.getElementById('inv-empty').style.display='none';

    const filter = currentFilter === 'all' ? 'all' : currentFilter;
    allInvoices = await apiFetch({action:'get_invoices', filter});
    if(allInvoices && allInvoices.error){
        alert2('e','Failed to load invoices: '+allInvoices.error);
        allInvoices=[];
    }
    renderInvoices(allInvoices);
}

function renderInvoices(data){
    document.getElementById('inv-loading').style.display='none';

    if(!data.length){
        document.getElementById('inv-table').style.display='none';
        document.getElementById('inv-empty').style.display='block';
        return;
    }

    document.getElementById('inv-table').style.display='table';
    document.getElementById('inv-empty').style.display='none';

    const tbody = document.getElementById('inv-tbody');
    tbody.innerHTML = data.map(inv => {
        const total    = parseFloat(inv.TOTAL||0);
        const paid     = parseFloat(inv.PAID_AMT||0);
        const balance  = Math.max(0, total - paid);
        const pct      = total > 0 ? Math.min(100, Math.round(paid/total*100)) : 0;
        const overdue  = inv.STATUS !== 'Paid' && inv.DUE_DATE && new Date(inv.DUE_DATE) < new Date();
        const status   = overdue && inv.STATUS !== 'Paid' ? 'Overdue' : (inv.STATUS || 'Unpaid');
        const badgeCls = {Paid:'b-paid',Partial:'b-partial',Overdue:'b-overdue',Unpaid:'b-unpaid',Pending:'b-pending'}[status] || 'b-unpaid';

        const canPay = status !== 'Paid';
        return `<tr>
            <td><span class="inv-num">#${h(inv.INVOICE_ID)}</span></td>
            <td>${fd(inv.INVOICE_DATE)}</td>
            <td style="${overdue && status!=='Paid'?'color:var(--dan);font-weight:700':''}">${fd(inv.DUE_DATE)}</td>
            <td>${inv.REP_FAULT_ID ? `<span style="font-family:var(--fm);color:var(--teal)">F-${h(inv.REP_FAULT_ID)}</span>` : '<span style="color:var(--t3)">—</span>'}</td>
            <td>${inv.TECHNICIANS ? `<span style="font-size:11px">${h(inv.TECHNICIANS)}</span>` : '<span style="color:var(--t3)">—</span>'}</td>
            <td class="amt">${cur(total)}</td>
            <td class="amt" style="color:var(--em)">${cur(paid)}</td>
            <td class="bal ${balance===0?'zero':''}">${cur(balance)}</td>
            <td>
                <div class="pb-wrap"><div class="pb-bar" style="width:${pct}%"></div></div>
                <div class="pb-pct">${pct}%</div>
            </td>
            <td><span class="b ${badgeCls}">${h(status)}</span></td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn btn-s btn-sm" onclick="openDetail(${inv.INVOICE_ID})" title="View Detail"><i class="fas fa-eye"></i></button>
                    ${canPay ? `<button class="btn btn-p btn-sm" onclick="openPayment(${inv.INVOICE_ID})" title="Pay Now"><i class="fas fa-credit-card"></i> Pay</button>` : ''}
                    <button class="btn btn-t btn-sm" onclick="openTxnHistory(${inv.INVOICE_ID})" title="Transactions"><i class="fas fa-exchange-alt"></i></button>
                    <button class="btn btn-sm" style="background:var(--bg3);border:1px solid var(--bor);color:var(--t2)" onclick="printInvoice(${inv.INVOICE_ID})" title="Print"><i class="fas fa-print"></i></button>
                </div>
            </td>
        </tr>`;
    }).join('');

    // Animate progress bars
    setTimeout(()=>{
        document.querySelectorAll('.pb-bar').forEach(b=>{
            const w=b.style.width;b.style.width='0';
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

function filterInvoices(q){
    q = q.toLowerCase();
    const filtered = allInvoices.filter(inv =>
        String(inv.INVOICE_ID).includes(q) ||
        (inv.STATUS||'').toLowerCase().includes(q) ||
        (inv.TECHNICIANS||'').toLowerCase().includes(q) ||
        String(inv.TOTAL||'').includes(q)
    );
    renderInvoices(filtered);
}

// ═══════════════════════════════════════════════════════════════════
//  INVOICE DETAIL
// ═══════════════════════════════════════════════════════════════════
async function openDetail(id){
    document.getElementById('det-title').textContent = 'Invoice #'+id;
    ['t-overview','t-breakdown','t-payments','t-worklog'].forEach(t=>{
        document.getElementById(t).innerHTML='<div class="spin"></div>';
        document.getElementById(t).classList.remove('act');
    });
    document.getElementById('t-overview').classList.add('act');
    document.getElementById('det-foot').innerHTML='';
    om('m-detail');

    const data = await apiFetch({action:'get_invoice_detail', id});
    if(data.error){ document.getElementById('t-overview').innerHTML=`<div class="ib ib-r"><i class="fas fa-exclamation-triangle" style="color:var(--dan)"></i>${h(data.error)}</div>`; return; }

    const inv = data.invoice;
    const lines = data.lines || [];
    const payments = data.payments || [];
    const paid_total = parseFloat(data.paid_total||0);
    const logs = data.logs || [];
    const total = parseFloat(inv.TOTAL||0);
    const balance = Math.max(0, total - paid_total);
    const pct = total>0?Math.min(100,Math.round(paid_total/total*100)):0;
    const overdue = inv.STATUS!=='Paid' && inv.DUE_DATE && new Date(inv.DUE_DATE)<new Date();
    const statusDisp = overdue && inv.STATUS!=='Paid' ? 'Overdue' : (inv.STATUS||'Unpaid');
    const badgeCls = {Paid:'b-paid',Partial:'b-partial',Overdue:'b-overdue',Unpaid:'b-unpaid',Pending:'b-pending'}[statusDisp]||'b-unpaid';

    // OVERVIEW TAB
    document.getElementById('t-overview').innerHTML=`
        <div class="dg" style="margin-bottom:16px">
            <div class="di"><div class="k">Invoice Number</div><div class="v" style="font-family:var(--fm);color:var(--gold)">#${h(inv.INVOICE_ID)}</div></div>
            <div class="di"><div class="k">Status</div><div class="v"><span class="b ${badgeCls}">${h(statusDisp)}</span></div></div>
            <div class="di"><div class="k">Invoice Date</div><div class="v">${fd(inv.INVOICE_DATE)}</div></div>
            <div class="di"><div class="k">Due Date</div><div class="v" style="${overdue&&statusDisp!=='Paid'?'color:var(--dan)':''}">${fd(inv.DUE_DATE)}</div></div>
            <div class="di"><div class="k">Client</div><div class="v">${h(inv.COMPANY_NAME||'')}</div></div>
            <div class="di"><div class="k">Technician(s)</div><div class="v">${h(inv.TECHNICIANS||'N/A')}</div></div>
            ${inv.REP_FAULT_ID?`<div class="di"><div class="k">Fault Reference</div><div class="v" style="font-family:var(--fm);color:var(--teal)">F-${h(inv.REP_FAULT_ID)}</div></div>`:''}
            ${inv.TYPE?`<div class="di"><div class="k">Invoice Type</div><div class="v">${h(inv.TYPE)}</div></div>`:''}
        </div>
        <div style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:6px">
                <span style="color:var(--t2)">Payment Progress</span>
                <span style="font-family:var(--fm);color:var(--gold)">${pct}% paid</span>
            </div>
            <div style="height:8px;background:var(--bg3);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--burg),var(--gold));border-radius:99px;transition:width 1.2s ease"></div>
            </div>
        </div>
        <div class="dg">
            <div class="di" style="background:linear-gradient(135deg,rgba(139,0,0,.12),transparent)">
                <div class="k">Total Amount</div>
                <div class="v" style="font-family:var(--fm);font-size:18px;color:var(--gold)">${cur(total)}</div>
            </div>
            <div class="di" style="background:linear-gradient(135deg,rgba(16,185,129,.08),transparent)">
                <div class="k">Amount Paid</div>
                <div class="v" style="font-family:var(--fm);font-size:18px;color:var(--em)">${cur(paid_total)}</div>
            </div>
            <div class="di full" style="background:linear-gradient(135deg,rgba(239,68,68,.08),transparent)">
                <div class="k">Outstanding Balance</div>
                <div class="v" style="font-family:var(--fm);font-size:20px;color:${balance>0?'var(--dan)':'var(--em)'}">${cur(balance)}</div>
            </div>
        </div>
        ${inv.NOTES||inv.ACCOUNTANT_NOTES?`
        <div class="ib ib-g" style="margin-top:14px">
            <i class="fas fa-comment-alt" style="color:var(--gold)"></i>
            <div><strong>Accountant Notes:</strong> ${h(inv.NOTES||inv.ACCOUNTANT_NOTES||'')}</div>
        </div>`:''}
        ${inv.FAULT_DESC?`
        <div class="ib ib-t" style="margin-top:8px">
            <i class="fas fa-tools" style="color:var(--teal)"></i>
            <div><strong>Fault Description:</strong> ${h(inv.FAULT_DESC)}</div>
        </div>`:''}
    `;

    // BREAKDOWN TAB
    let lineRows = '';
    let labour=0,transport=0,materials=0,service=0,vat=0;
    if(lines.length){
        lineRows = lines.map(l=>{
            const ltype=(l.LINE_TYPE||l.DESCRIPTION||'').toLowerCase();
            if(ltype.includes('labour')) labour+=parseFloat(l.AMOUNT||0);
            else if(ltype.includes('transport')) transport+=parseFloat(l.AMOUNT||0);
            else if(ltype.includes('material')) materials+=parseFloat(l.AMOUNT||0);
            else if(ltype.includes('service')) service+=parseFloat(l.AMOUNT||0);
            else if(ltype.includes('vat')) vat+=parseFloat(l.AMOUNT||0);
            return `<div class="cost-row">
                <span class="lbl"><i class="fas fa-${ltype.includes('labour')?'user-cog':ltype.includes('transport')?'car':ltype.includes('material')?'box':ltype.includes('vat')?'percent':'tag'}" style="width:16px;text-align:center;color:var(--gold)"></i> ${h(l.DESCRIPTION||l.LINE_TYPE||'Item')}</span>
                <span class="val">${cur(l.AMOUNT)}</span>
            </div>`;
        }).join('');
    } else {
        // Reconstruct from totals if no line items
        const subtotal = total / 1.15;
        vat = total - subtotal;
        lineRows=`
            <div class="cost-row"><span class="lbl"><i class="fas fa-user-cog" style="width:16px;text-align:center;color:var(--gold)"></i> Labour Cost</span><span class="val">${cur(subtotal * 0.5)}</span></div>
            <div class="cost-row"><span class="lbl"><i class="fas fa-car" style="width:16px;text-align:center;color:var(--sky)"></i> Transport</span><span class="val">${cur(subtotal * 0.1)}</span></div>
            <div class="cost-row"><span class="lbl"><i class="fas fa-box" style="width:16px;text-align:center;color:var(--teal)"></i> Materials</span><span class="val">${cur(subtotal * 0.25)}</span></div>
            <div class="cost-row"><span class="lbl"><i class="fas fa-tag" style="width:16px;text-align:center;color:var(--ind)"></i> Service Fee</span><span class="val">${cur(subtotal * 0.15)}</span></div>
            <div class="cost-row"><span class="lbl"><i class="fas fa-percent" style="width:16px;text-align:center;color:var(--warn)"></i> VAT (15%)</span><span class="val">${cur(vat)}</span></div>`;
    }
    document.getElementById('t-breakdown').innerHTML=`
        <div style="background:var(--bg3);border:1px solid var(--bor);border-radius:var(--r);padding:16px 18px">
            ${lineRows}
            <div class="cost-row">
                <span class="lbl"><i class="fas fa-receipt" style="width:16px;text-align:center"></i> Total Invoice Amount</span>
                <span class="val" style="font-size:16px">${cur(total)}</span>
            </div>
        </div>
        <div class="ib ib-g" style="margin-top:12px">
            <i class="fas fa-info-circle" style="color:var(--gold)"></i>
            <div>All amounts are in <strong>Emalangeni (E)</strong>. VAT registration: BUSIQUIP-VAT-ESW-2024</div>
        </div>`;

    // PAYMENTS TAB
    if(!payments.length){
        document.getElementById('t-payments').innerHTML='<div class="empty"><i class="fas fa-credit-card"></i><p>No payments recorded yet.</p></div>';
    } else {
        document.getElementById('t-payments').innerHTML=`
            <table class="tbl">
                <thead><tr><th>#</th><th>Date</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th>Proof</th></tr></thead>
                <tbody>${payments.map((p,i)=>{
                    const sc={Confirmed:'b-conf','Pending Verification':'b-pv',Pending:'b-pending',Failed:'b-fail'}[p.STATUS]||'b-pending';
                    return `<tr>
                        <td style="font-family:var(--fm);color:var(--t3)">${i+1}</td>
                        <td>${fd(p.PAYMENT_DATE)}</td>
                        <td><span style="font-size:11px">${h(p.METHOD||'N/A')}</span>${p.NETWORK?`<br><span style="font-size:10px;color:var(--t3)">${h(p.NETWORK)}</span>`:''}</td>
                        <td style="font-family:var(--fm);font-weight:700;color:var(--em)">${cur(p.AMOUNT_PAID)}</td>
                        <td style="font-family:var(--fm);font-size:10px;color:var(--t2)">${h(p.REFERENCE_NUMBER||'—')}</td>
                        <td><span class="b ${sc}">${h(p.STATUS||'Pending')}</span></td>
                        <td>${p.PROOF_PATH?`<a href="${h(p.PROOF_PATH)}" target="_blank" class="btn btn-t btn-sm"><i class="fas fa-file"></i></a>`:'<span style="color:var(--t3)">—</span>'}</td>
                    </tr>`;
                }).join('')}</tbody>
            </table>`;
    }

    // WORK LOG TAB
    if(!logs.length){
        document.getElementById('t-worklog').innerHTML='<div class="empty"><i class="fas fa-clipboard-list"></i><p>No work log entries.</p></div>';
    } else {
        document.getElementById('t-worklog').innerHTML=logs.map(l=>`
            <div class="wl-item">
                <div class="wl-top">
                    <span class="wl-name">${h(l.FULL_NAME||'Technician')} <span style="color:var(--t3);font-size:10px">(${h(l.LOG_TYPE||'Update')})</span></span>
                    <span class="wl-date">${fd(l.LOG_DATE)}</span>
                </div>
                <div class="wl-act">${h(l.ACTION_TAKEN||'—')}</div>
                ${l.HOURS_SPENT?`<div style="font-size:10px;color:var(--t3);margin-top:4px">${l.HOURS_SPENT}h logged</div>`:''}
            </div>`).join('');
    }

    // Footer
    const canPay = statusDisp !== 'Paid';
    document.getElementById('det-foot').innerHTML=`
        ${canPay ? `<button class="btn btn-p" onclick="cm('m-detail');openPayment(${inv.INVOICE_ID})"><i class="fas fa-credit-card"></i> Pay Now — ${cur(balance)}</button>` : '<button class="btn btn-e" disabled><i class="fas fa-check-double"></i> Fully Paid</button>'}
        <button class="btn btn-t" onclick="openTxnHistory(${inv.INVOICE_ID})"><i class="fas fa-exchange-alt"></i> Transactions</button>
        <button class="btn btn-s" onclick="printInvoice(${inv.INVOICE_ID})"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-s" onclick="cm('m-detail')">Close</button>
    `;
}

// ═══════════════════════════════════════════════════════════════════
//  PAYMENT SYSTEM
// ═══════════════════════════════════════════════════════════════════
async function openPayment(id){
    // Find invoice from cache or fetch
    let inv = allInvoices.find(i=>i.INVOICE_ID==id);
    if(!inv){
        const data = await apiFetch({action:'get_invoice_detail', id});
        inv = data.invoice;
    }
    currentInvForPay = inv;

    const total   = parseFloat(inv.TOTAL||0);
    const paid    = parseFloat(inv.PAID_AMT||0);
    const balance = Math.max(0, total - paid);

    document.getElementById('pay-inv-num').textContent = id;
    document.getElementById('pay-inv-id').value = id;

    // Info box
    document.getElementById('pay-inv-info').querySelector('div').innerHTML =
        `Invoice #${id} &nbsp;·&nbsp; Total: <strong>${cur(total)}</strong> &nbsp;·&nbsp; Paid: <strong style="color:var(--em)">${cur(paid)}</strong> &nbsp;·&nbsp; Balance Due: <strong style="color:var(--dan)">${cur(balance)}</strong>`;

    // Balance display
    document.getElementById('bd-total').textContent  = cur(total);
    document.getElementById('bd-paid').textContent   = cur(paid);
    document.getElementById('bd-due').textContent    = cur(balance);
    document.getElementById('bd-wallet').textContent = cur(walletBal);

    // Wallet panel defaults
    document.getElementById('wal-before').textContent  = cur(walletBal);
    document.getElementById('wal-paying').textContent  = cur(balance);
    document.getElementById('wal-after').textContent   = cur(Math.max(0, walletBal - balance));
    document.getElementById('wal-amount').value = balance.toFixed(2);

    // Reset method selection
    resetPayForm(false);
    document.getElementById('pay-success-overlay').classList.remove('show');
    document.getElementById('pay-success-overlay').style.display='none';
    document.getElementById('pay-form-wrap').style.display='block';

    // Auto-generate refs
    const refId = 'BQ-'+(Math.random().toString(36).substr(2,8)).toUpperCase();
    document.getElementById('mob-ref').value  = refId;
    document.getElementById('bank-ref').value = refId;

    // Pre-fill mobile amount
    document.getElementById('mob-amount').value  = balance.toFixed(2);
    document.getElementById('bank-amount').value = balance.toFixed(2);

    om('m-pay');
}

function selMethod(m){
    selectedMethod = m;
    document.querySelectorAll('.pm-card').forEach(c=>c.classList.remove('sel'));
    document.querySelector('.pm-'+m).classList.add('sel');
    ['mobile','bank','wallet'].forEach(p=>{
        const el=document.getElementById('panel-'+p);
        el.classList.remove('show');
    });
    document.getElementById('panel-'+m).classList.add('show');
    document.getElementById('btn-submit-pay').disabled=false;
    document.getElementById('btn-submit-pay').innerHTML='<i class="fas fa-lock"></i> Confirm Payment';

    if(m==='wallet'){
        const walEl=document.getElementById('wal-amount');
        if(currentInvForPay){
            const bal=Math.max(0,parseFloat(currentInvForPay.TOTAL||0)-parseFloat(currentInvForPay.PAID_AMT||0));
            walEl.value=bal.toFixed(2);
        }
        updateWalAfter();
    }
}

function selNet(n){
    selectedNetwork = n;
    document.querySelectorAll('.net-opt').forEach(o=>o.classList.remove('sel'));
    document.getElementById(n==='MTN'?'net-mtn':'net-esw').classList.add('sel');
    // Simulate network balance
    const mockBal = n==='MTN' ? 1250.00 : 875.50;
    document.getElementById('mob-bal-before').textContent = cur(mockBal);
    updateMobAfter();
}

function updateMobAfter(){
    const amt=parseFloat(document.getElementById('mob-amount').value||0);
    const mockBal=selectedNetwork==='MTN'?1250.00:selectedNetwork?875.50:0;
    document.getElementById('mob-bal-after').textContent = cur(Math.max(0,mockBal-amt));
}

function updateWalAfter(){
    const amt=parseFloat(document.getElementById('wal-amount').value||0);
    document.getElementById('wal-paying').textContent=cur(amt);
    document.getElementById('wal-after').textContent=cur(Math.max(0,walletBal-amt));
}

function payFullWallet(){
    if(!currentInvForPay) return;
    const bal=Math.max(0,parseFloat(currentInvForPay.TOTAL||0)-parseFloat(currentInvForPay.PAID_AMT||0));
    document.getElementById('wal-amount').value=bal.toFixed(2);
    updateWalAfter();
}

async function submitPayment(){
    if(!selectedMethod){ alert2('e','Please select a payment method.'); return; }

    const invId  = document.getElementById('pay-inv-id').value;
    if(!invId){ alert2('e','No invoice selected.'); return; }

    let amount=0, valid=true, errMsg='';

    if(selectedMethod==='mobile'){
        amount=parseFloat(document.getElementById('mob-amount').value||0);
        const mob=document.getElementById('mob-number').value.trim();
        if(!mob){errMsg='Please enter your mobile number.';valid=false;}
        else if(!selectedNetwork){errMsg='Please select a mobile network.';valid=false;}
        else if(amount<=0){errMsg='Please enter a valid amount.';valid=false;}
    } else if(selectedMethod==='bank'){
        amount=parseFloat(document.getElementById('bank-amount').value||0);
        const bank=document.getElementById('bank-name').value;
        if(!bank){errMsg='Please select a bank.';valid=false;}
        else if(amount<=0){errMsg='Please enter a valid amount.';valid=false;}
    } else if(selectedMethod==='wallet'){
        amount=parseFloat(document.getElementById('wal-amount').value||0);
        if(amount<=0){errMsg='Please enter a valid amount.';valid=false;}
        else if(amount>walletBal+0.01){errMsg=`Insufficient wallet balance. Available: ${cur(walletBal)}.`;valid=false;}
    }

    if(!valid){ alert2('e',errMsg); return; }

    const btn=document.getElementById('btn-submit-pay');
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Processing…';

    const fd2=new FormData();
    fd2.append('invoice_id', invId);
    fd2.append('amount', amount);
    fd2.append('method', selectedMethod==='mobile'?'Mobile Money':selectedMethod==='bank'?'Bank Transfer':'Wallet');
    fd2.append('reference', selectedMethod==='mobile'?document.getElementById('mob-ref').value:document.getElementById('bank-ref').value);
    fd2.append('mobile_number', selectedMethod==='mobile'?document.getElementById('mob-number').value:'');
    fd2.append('network', selectedMethod==='mobile'?selectedNetwork:'');
    fd2.append('bank', selectedMethod==='bank'?document.getElementById('bank-name').value:'');
    fd2.append('account_number', selectedMethod==='bank'?document.getElementById('bank-acc').value:'');

    // Bank proof file
    if(selectedMethod==='bank'){
        const proofFile=document.getElementById('bank-proof').files[0];
        if(proofFile) fd2.append('proof', proofFile);
    }

    try {
        const data = await apiFetch({action:'submit_payment'}, fd2);

        if(data.success){
            // ── Show the beautiful success popup ──────────────────
            document.getElementById('pay-form-wrap').style.display='none';
            const ov=document.getElementById('pay-success-overlay');
            ov.style.display='block';
            ov.classList.add('show');

            const methodIcon = {
                'Mobile Money':'📱', 'Bank Transfer':'🏦', 'Wallet':'👛'
            }[data.method] || '💳';

            const statusLabel = data.pay_status === 'Confirmed'
                ? '✅ Confirmed & Applied Instantly'
                : data.pay_status === 'Pending Verification'
                    ? '⏳ Pending Accountant Verification'
                    : '⏳ Processing';

            document.getElementById('pso-title').textContent =
                data.pay_status==='Confirmed' ? '🎉 Payment Successful!' : '✅ Payment Submitted!';
            document.getElementById('pso-msg').textContent =
                `Your payment of ${cur(amount)} has been ${data.pay_status==='Confirmed'?'confirmed and applied to your invoice!':'submitted and is being processed.'}`;
            document.getElementById('pso-ref').textContent =
                `📋 Ref: ${data.reference}   ·   Status: ${data.pay_status}`;

            // Receipt
            document.getElementById('pso-receipt').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">
                    <div style="color:var(--t2)">Payment ID</div><div style="font-family:var(--fm);color:var(--gold)">#${data.payment_id}</div>
                    <div style="color:var(--t2)">Invoice</div><div style="font-family:var(--fm)">#${data.invoice_id}</div>
                    <div style="color:var(--t2)">Amount</div><div style="font-family:var(--fm);color:var(--em);font-weight:700">${cur(amount)}</div>
                    <div style="color:var(--t2)">Method</div><div>${methodIcon} ${data.method}</div>
                    <div style="color:var(--t2)">Reference</div><div style="font-family:var(--fm);font-size:11px;color:var(--gold)">${data.reference}</div>
                    <div style="color:var(--t2)">Status</div><div style="color:var(--em)">${statusLabel}</div>
                    <div style="color:var(--t2)">New Inv Status</div><div><span class="b ${{'Paid':'b-paid','Partial':'b-partial','Unpaid':'b-unpaid'}[data.new_inv_status]||'b-pending'}">${data.new_inv_status}</span></div>
                    ${data.new_wallet!==null ? `<div style="color:var(--t2)">Wallet Balance</div><div style="font-family:var(--fm)">${cur(data.new_wallet)}</div>` : ''}
                </div>`;

            if(selectedMethod==='wallet' && data.new_wallet!==null){
                walletBal=parseFloat(data.new_wallet);
                document.getElementById('wallet-amount').textContent='E '+walletBal.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2});
                document.getElementById('bd-wallet').textContent=cur(walletBal);
            }

            alert2('s',`✓ Payment of ${cur(amount)} confirmed! Ref: ${data.reference}`);
            loadInvoices();
        } else {
            alert2('e', data.error||'Payment failed. Please try again.');
            btn.disabled=false;
            btn.innerHTML='<i class="fas fa-lock"></i> Confirm Payment';
        }
    } catch(e){
        // This should never fire now because apiFetch handles all errors,
        // but kept as a last-resort safety net.
        alert2('e','Unexpected error: '+e.message);
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-lock"></i> Confirm Payment';
    }
}

function resetPayForm(closeOverlay=true){
    selectedMethod='';
    selectedNetwork='';
    currentInvForPay=null;
    document.querySelectorAll('.pm-card').forEach(c=>c.classList.remove('sel'));
    document.querySelectorAll('.pay-panel').forEach(p=>p.classList.remove('show'));
    document.getElementById('btn-submit-pay').disabled=true;
    document.getElementById('btn-submit-pay').innerHTML='<i class="fas fa-lock"></i> Confirm Payment';
    if(closeOverlay){
        document.getElementById('pay-success-overlay').style.display='none';
        document.getElementById('pay-success-overlay').classList.remove('show');
        document.getElementById('pay-form-wrap').style.display='block';
    }
    document.querySelectorAll('.net-opt').forEach(o=>o.classList.remove('sel'));
}

// ═══════════════════════════════════════════════════════════════════
//  TRANSACTION HISTORY
// ═══════════════════════════════════════════════════════════════════
async function openTxnHistory(id){
    id = parseInt(id) || 0;
    document.getElementById('txn-inv-num').textContent = id ? '#'+id : 'All';
    document.getElementById('txn-body').innerHTML='<div class="spin"></div>';
    om('m-txn');

    const txns = await apiFetch({action:'get_transactions', id});

    if(!txns.length){
        document.getElementById('txn-body').innerHTML='<div class="empty"><i class="fas fa-exchange-alt"></i><p>No transactions for this invoice yet.</p></div>';
        return;
    }
    document.getElementById('txn-body').innerHTML=`
        <table class="tbl">
            <thead><tr><th>TXN ID</th><th>Date/Time</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th>Proof</th></tr></thead>
            <tbody>${txns.map(t=>{
                const sc={Confirmed:'b-conf','Pending Verification':'b-pv',Pending:'b-pending',Failed:'b-fail'}[t.STATUS]||'b-pending';
                return `<tr>
                    <td style="font-family:var(--fm);color:var(--t3)">#${h(t.PAYMENT_ID)}</td>
                    <td style="font-size:11px">${fd(t.PAYMENT_DATE)}</td>
                    <td>
                        <div style="font-weight:600;font-size:12px">${h(t.METHOD||'N/A')}</div>
                        ${t.NETWORK?`<div style="font-size:10px;color:var(--t3)">${h(t.NETWORK)}</div>`:''}
                        ${t.BANK_NAME?`<div style="font-size:10px;color:var(--t3)">${h(t.BANK_NAME)}</div>`:''}
                    </td>
                    <td style="font-family:var(--fm);font-weight:700;color:var(--em)">${cur(t.AMOUNT_PAID)}</td>
                    <td style="font-family:var(--fm);font-size:10px;color:var(--gold)">${h(t.REFERENCE_NUMBER||'—')}</td>
                    <td><span class="b ${sc}">${h(t.STATUS||'Pending')}</span></td>
                    <td>${t.PROOF_PATH?`<a href="${h(t.PROOF_PATH)}" target="_blank" class="btn btn-t btn-sm"><i class="fas fa-file"></i> View</a>`:'<span style="color:var(--t3)">—</span>'}</td>
                </tr>`;
            }).join('')}</tbody>
        </table>`;
}

// ═══════════════════════════════════════════════════════════════════
//  PRINT / EXPORT
// ═══════════════════════════════════════════════════════════════════
function printInvoice(id){
    window.open(`client_invoice_pdf.php?id=${id}`, '_blank');
}

// ═══════════════════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', ()=>{
    loadInvoices();

    // Animate progress bars after a tick
    setTimeout(()=>{
        document.querySelectorAll('.pb-bar').forEach(b=>{
            const w=b.style.width;b.style.width='0';
            setTimeout(()=>{b.style.width=w;},80);
        });
    },400);
});

// Close modal on background click
document.querySelectorAll('.mo').forEach(m=>{
    m.addEventListener('click', e=>{
        if(e.target===m){
            const mid=m.id;
            cm(mid);
            if(mid==='m-pay') resetPayForm();
        }
    });
});
</script>
</body>
</html>


