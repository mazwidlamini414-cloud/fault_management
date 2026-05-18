<?php
// ═══════════════════════════════════════════════════════════════════════
//  report_fault.php  —  BUSIQUIP ESWATINI  —  Fault Reporting Form
//  Database: busiquip_final
//  Opens as a new page from client_portal.php
//  Session keys (set by client_login.php):
//    $_SESSION['client_id']      → client.CLIENT_ID
//    $_SESSION['client_name']    → client.COMPANY_NAME
//    $_SESSION['client_contact'] → client.CONTACT_PERSON_NAME
//    $_SESSION['client_email']   → client.COMPANY_EMAIL
//    $_SESSION['client_type']    → client.CLIENT_TYPE
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// ── Session Guard ──────────────────────────────────────────────────────
if (!isset($_SESSION['client_id'])) {
    header("Location: client_login.php");
    exit;
}

// ── DB Connection ──────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/database.php';
if ($conn->connect_error) {
    die("<div style='font:16px sans-serif;padding:40px;color:red'>
         <strong>Database connection failed:</strong> " . htmlspecialchars($conn->connect_error) .
        "<br><br>Make sure the database <strong>busiquip_final</strong> exists and MySQL is running.</div>");
}
$conn->set_charset('utf8mb4');

// ── Session vars ───────────────────────────────────────────────────────
$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';
$client_email   = $_SESSION['client_email']   ?? '';
$client_type    = $_SESSION['client_type']    ?? 'CORPORATE';

// ── Fetch full client record ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM client WHERE CLIENT_ID = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

$company_name    = htmlspecialchars($client['COMPANY_NAME']         ?? $client_name);
$company_phone   = htmlspecialchars($client['COMPANY_PHONE']        ?? '');
$company_email   = htmlspecialchars($client['COMPANY_EMAIL']        ?? $client_email);
$company_address = htmlspecialchars($client['COMPANY_ADDRESS']      ?? '');
$contact_person  = htmlspecialchars($client['CONTACT_PERSON_NAME']  ?? $client_contact);
$client_type_v   = htmlspecialchars($client['CLIENT_TYPE']          ?? $client_type);

// ── CSRF Token ─────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Auto-generate reference number ────────────────────────────────────
$ref_num = 'BQ-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

// ── Fetch fault types from DB ──────────────────────────────────────────
$fault_types = [];
$ftRes = $conn->query("SELECT FAULT_ID, FAULT_TYPE, DEFAULT_PRIORITY FROM fault ORDER BY FAULT_TYPE ASC");
if ($ftRes) {
    while ($r = $ftRes->fetch_assoc()) $fault_types[] = $r;
}

// ══════════════════════════════════════════════════════════════════════
//  AJAX / ACTION HANDLERS
// ══════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── submit_fault ──────────────────────────────────────────────────
    if ($action === 'submit_fault' && $_SERVER['REQUEST_METHOD'] === 'POST') {

        // CSRF check
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            echo json_encode(['success' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
            exit;
        }

        // Rate limiting: max 10 fault submissions per hour per client
        if (!isset($_SESSION['fault_submit_times'])) $_SESSION['fault_submit_times'] = [];
        $_SESSION['fault_submit_times'] = array_filter($_SESSION['fault_submit_times'], fn($t) => $t > time() - 3600);
        if (count($_SESSION['fault_submit_times']) >= 10) {
            echo json_encode(['success' => false, 'error' => 'Rate limit reached. Maximum 10 fault reports per hour.']);
            exit;
        }

        // ── Sanitize inputs ──────────────────────────────────────────
        $safe = fn($v) => htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');

        $fault_ref        = $safe($_POST['fault_ref']         ?? $ref_num);
        $description      = $safe($_POST['description']       ?? '');
        $fault_id_raw     = (int)($_POST['fault_id']          ?? 0);   // FK to fault table
        $fault_type_label = $safe($_POST['fault_type_label']  ?? '');
        $equipment_type   = $safe($_POST['equipment_type']    ?? '');
        $brand_model      = $safe($_POST['brand_model']       ?? '');
        $serial_number    = $safe($_POST['serial_number']     ?? '');
        $client_prod_id_r = $_POST['client_prod_id'] ?? '';
        $client_prod_id   = ($client_prod_id_r !== '' && is_numeric($client_prod_id_r)) ? (int)$client_prod_id_r : null;
        $priority_raw     = $_POST['priority']                ?? 'Medium';
        $priority         = in_array($priority_raw, ['Low','Medium','High','Critical']) ? $priority_raw : 'Medium';
        $fault_date       = $safe($_POST['fault_date']        ?? date('Y-m-d'));
        $fault_time       = $safe($_POST['fault_time']        ?? date('H:i'));
        $is_operational   = $safe($_POST['is_operational']    ?? 'No');
        $occurred_before  = $safe($_POST['occurred_before']   ?? 'No');
        $users_affected   = max(1, (int)($_POST['users_affected'] ?? 1));
        $fault_location   = $safe($_POST['fault_location']    ?? '');
        $dept_branch      = $safe($_POST['dept_branch']       ?? '');
        $contact_method   = $safe($_POST['contact_method']    ?? 'Email');
        $service_visit    = $safe($_POST['service_visit']     ?? 'Unsure');
        $reported_by      = $safe($_POST['reported_by']       ?? $contact_person);

        // Validation
        $errors = [];
        if (strlen($reported_by) < 2)       $errors[] = 'Reported By name must be at least 2 characters.';
        if (strlen($description) < 20)      $errors[] = 'Fault description must be at least 20 characters.';
        if ($fault_id_raw < 1)              $errors[] = 'Please select a fault type.';
        if (empty($equipment_type))         $errors[] = 'Please select the equipment type.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fault_date)) $errors[] = 'Invalid fault date.';
        elseif (strtotime($fault_date) > mktime(23,59,59)) $errors[] = 'Fault date cannot be in the future.';
        if (!preg_match('/^\d{2}:\d{2}$/', $fault_time)) $errors[] = 'Invalid fault time.';

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
            exit;
        }

        $report_date = date('Y-m-d H:i:s');

        // Build full description
        $full_desc  = "FAULT REFERENCE: $fault_ref\n";
        $full_desc .= "FAULT TYPE: $fault_type_label\n";
        $full_desc .= "EQUIPMENT TYPE: $equipment_type\n";
        if ($brand_model)    $full_desc .= "BRAND/MODEL: $brand_model\n";
        if ($serial_number)  $full_desc .= "SERIAL/ASSET NO: $serial_number\n";
        $full_desc .= "FAULT DATE/TIME: $fault_date $fault_time\n";
        $full_desc .= "IS OPERATIONAL: $is_operational\n";
        $full_desc .= "OCCURRED BEFORE: $occurred_before\n";
        $full_desc .= "USERS AFFECTED: $users_affected\n";
        if ($fault_location) $full_desc .= "FAULT LOCATION: $fault_location\n";
        if ($dept_branch)    $full_desc .= "DEPARTMENT/BRANCH: $dept_branch\n";
        $full_desc .= "PREFERRED CONTACT: $contact_method\n";
        $full_desc .= "SERVICE VISIT REQUIRED: $service_visit\n";
        $full_desc .= "\nDETAILED DESCRIPTION:\n$description";

        // Insert into reported_fault
        if ($client_prod_id) {
            $stmt = $conn->prepare(
                "INSERT INTO reported_fault (CLIENT_ID, CLIENT_PROD_ID, FAULT_ID, REPORT_DATE, STATUS, PRIORITY, REPORTED_BY, DESCRIPTION)
                 VALUES (?, ?, ?, ?, 'Pending', ?, ?, ?)"
            );
            $stmt->bind_param("iiissss", $client_id, $client_prod_id, $fault_id_raw, $report_date, $priority, $reported_by, $full_desc);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO reported_fault (CLIENT_ID, FAULT_ID, REPORT_DATE, STATUS, PRIORITY, REPORTED_BY, DESCRIPTION)
                 VALUES (?, ?, ?, 'Pending', ?, ?, ?)"
            );
            $stmt->bind_param("iissss", $client_id, $fault_id_raw, $report_date, $priority, $reported_by, $full_desc);
        }

        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            $stmt->close();

            $_SESSION['fault_submit_times'][] = time();

            // Handle file uploads
            $upload_results = [];
            $upload_dir = 'uploads/fault_files/' . $new_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $allowed_types = [
                'image/jpeg','image/png','image/gif','image/webp',
                'video/mp4','video/mpeg','video/quicktime','video/webm',
                'application/pdf','application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain'
            ];
            $max_file_size = 20 * 1024 * 1024;

            $all_files = $_FILES['attachments'] ?? null;
            if ($all_files && is_array($all_files['name'])) {
                foreach ($all_files['name'] as $i => $fname) {
                    if ($all_files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($all_files['size'][$i] > $max_file_size) continue;
                    $tmp  = $all_files['tmp_name'][$i];
                    $mime = mime_content_type($tmp);
                    if (!in_array($mime, $allowed_types)) continue;
                    $ext      = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $safe_nm  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($fname, PATHINFO_FILENAME));
                    $new_name = $safe_nm . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($tmp, $upload_dir . $new_name)) $upload_results[] = $new_name;
                }
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            echo json_encode([
                'success' => true,
                'message' => "Fault report submitted! Reference: <strong>$fault_ref</strong>. The Busiquip admin team will respond promptly.",
                'id'      => $new_id,
                'ref'     => $fault_ref,
                'files'   => count($upload_results)
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $stmt->error]);
        }
        exit;
    }

    // ── get_client_products ───────────────────────────────────────────
    if ($action === 'get_client_products') {
        $stmt = $conn->prepare("
            SELECT cp.CLIENT_PROD_ID, cp.SERIAL_NUM, cp.PURCHASE_DATE, cp.WARRANTY_END_DATE,
                   p.PROD_NAME, p.PROD_TYPE
            FROM client_product cp
            JOIN product p ON p.PROD_ID = cp.PROD_ID
            WHERE cp.CLIENT_ID = ?
            ORDER BY p.PROD_NAME ASC
        ");
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$today    = date('Y-m-d');
$now_time = date('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report a Fault — Busiquip Eswatini</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
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
}
body.lm {
  --bg0:#F0F4FA;--bg1:#E6EEF8;--bg2:#DDE5F5;--bg3:#fff;
  --sur:rgba(255,255,255,.96);--gl:rgba(0,0,0,.02);
  --bor:rgba(139,0,0,.14);--t1:#0D1421;--t2:#4A5A7A;--t3:#9AAAC4;
  --glb:rgba(0,0,0,.04);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden;transition:background .4s,color .4s}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer;border:none;outline:none}
::-webkit-scrollbar{width:6px}
::-webkit-scrollbar-track{background:var(--bg1)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}

.orb-wrap{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.orb{position:absolute;border-radius:50%;filter:blur(90px);opacity:.12;animation:drift 20s ease-in-out infinite alternate}
.o1{width:500px;height:500px;top:-180px;left:-180px;background:radial-gradient(circle,var(--burg),transparent)}
.o2{width:400px;height:400px;bottom:-100px;right:-100px;background:radial-gradient(circle,var(--gold),transparent);animation-delay:-7s}
.o3{width:320px;height:320px;top:50%;right:20%;background:radial-gradient(circle,var(--teal),transparent);animation-delay:-14s}
@keyframes drift{0%{transform:translate(0,0) scale(1)}100%{transform:translate(40px,30px) scale(1.12)}}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);background-size:48px 48px}

/* Ticker */
.ticker{position:fixed;top:0;left:0;right:0;height:26px;z-index:3000;background:linear-gradient(90deg,var(--burg),#6B0000,var(--burg));overflow:hidden;display:flex;align-items:center}
.ticker-inner{display:flex;gap:60px;animation:tick 32s linear infinite;white-space:nowrap;font-family:var(--fm);font-size:10px;letter-spacing:.06em;color:var(--gold2)}
@keyframes tick{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* Header */
header{position:fixed;top:26px;left:0;right:0;height:68px;z-index:1500;background:rgba(7,12,20,.9);backdrop-filter:var(--blur);border-bottom:1px solid var(--bor);display:flex;align-items:center;padding:0 28px;gap:16px;box-shadow:0 4px 24px rgba(0,0,0,.4);transition:var(--tr)}
body.lm header{background:rgba(240,244,250,.93)}
.brand-wrap{display:flex;align-items:center;gap:12px;flex:1}
.brand-ic{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;box-shadow:0 0 18px var(--burg-g);animation:spin-s 14s linear infinite}
@keyframes spin-s{0%{box-shadow:0 0 18px var(--burg-g)}50%{box-shadow:0 0 30px var(--gold-p)}100%{box-shadow:0 0 18px var(--burg-g)}}
.brand-nm{font-family:var(--fh);font-size:21px;font-weight:800;background:linear-gradient(135deg,var(--gold2),var(--burg2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.05em}
.brand-sub{font-size:9px;color:var(--t2);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm);margin-top:2px}
.h-right{display:flex;align-items:center;gap:10px;margin-left:auto}
.h-badge{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);padding:5px 12px;border-radius:var(--r);font-family:var(--fm);font-size:11px;color:var(--em)}
.h-badge small{font-size:9px;color:var(--t2);display:block;font-family:var(--fb)}
.hb{width:38px;height:38px;border-radius:50%;border:1px solid var(--bor);background:var(--gl);color:var(--t2);font-size:15px;display:flex;align-items:center;justify-content:center;transition:var(--tr)}
.hb:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}
.btn-back{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:var(--r);border:1px solid var(--bor);background:var(--gl);color:var(--t2);font-size:13px;font-weight:500;transition:var(--tr)}
.btn-back:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}

/* Layout */
.page-wrap{position:relative;z-index:1;padding:110px 24px 60px;max-width:1040px;margin:0 auto}

/* Hero */
.page-hero{text-align:center;padding:40px 24px 32px;margin-bottom:32px;background:linear-gradient(135deg,rgba(139,0,0,.15),rgba(232,184,75,.08));border:1px solid var(--borh);border-radius:var(--rl);position:relative;overflow:hidden}
.page-hero::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(139,0,0,.06),transparent,rgba(232,184,75,.04));pointer-events:none}
.hero-icon{width:72px;height:72px;margin:0 auto 16px;border-radius:20px;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-size:30px;color:#fff;box-shadow:0 12px 36px var(--burg-g);animation:pulse-ic 3s ease-in-out infinite}
@keyframes pulse-ic{0%,100%{transform:scale(1);box-shadow:0 12px 36px var(--burg-g)}50%{transform:scale(1.06);box-shadow:0 16px 48px var(--burg-g),0 0 40px var(--gold-p)}}
.page-hero h1{font-family:var(--fh);font-size:32px;font-weight:800;letter-spacing:.04em;margin-bottom:8px}
.page-hero p{color:var(--t2);font-size:14px;max-width:540px;margin:0 auto 20px;line-height:1.7}
.ref-display{display:inline-flex;align-items:center;gap:8px;background:var(--gl);border:1px solid var(--borh);border-radius:var(--r);padding:8px 18px;font-family:var(--fm);font-size:13px;color:var(--gold);letter-spacing:.08em}
.ref-display .dot{width:8px;height:8px;border-radius:50%;background:var(--em);animation:blink 1.4s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

/* Progress Steps */
.progress-steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:36px;padding:0 12px}
.step{display:flex;flex-direction:column;align-items:center;flex:1;max-width:160px}
.step-num{width:36px;height:36px;border-radius:50%;border:2px solid var(--t3);background:var(--bg1);color:var(--t3);font-family:var(--fm);font-size:13px;display:flex;align-items:center;justify-content:center;transition:var(--tr);position:relative;z-index:1}
.step.active .step-num{border-color:var(--gold);background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 0 18px var(--gold-p)}
.step.done .step-num{border-color:var(--em);background:var(--em);color:#fff}
.step-label{font-size:10px;color:var(--t3);margin-top:6px;text-align:center;letter-spacing:.05em;text-transform:uppercase;font-family:var(--fm)}
.step.active .step-label{color:var(--gold)}
.step.done .step-label{color:var(--em)}
.step-line{flex:1;height:2px;background:var(--t3);max-width:80px;position:relative;top:-9px;transition:var(--tr)}
.step-line.done{background:linear-gradient(90deg,var(--em),var(--gold))}

/* Cards */
.card{background:var(--sur);border:1px solid var(--bor);border-radius:var(--rl);margin-bottom:20px;overflow:hidden;transition:var(--tr);backdrop-filter:var(--blur)}
.card:hover{border-color:var(--borh);box-shadow:var(--sh)}
.card-header{display:flex;align-items:center;gap:14px;padding:20px 24px 16px;border-bottom:1px solid var(--bor);user-select:none;transition:var(--tr)}
.sec-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;flex-shrink:0}
.ic-client{background:linear-gradient(135deg,#1A5276,#2E86C1)}
.ic-fault{background:linear-gradient(135deg,var(--burg),var(--burg2))}
.ic-equip{background:linear-gradient(135deg,#7D3C98,#A569BD)}
.ic-sev{background:linear-gradient(135deg,var(--warn),#E67E22)}
.ic-attach{background:linear-gradient(135deg,var(--teal),var(--sky))}
.card-header h3{font-family:var(--fh);font-size:16px;font-weight:700;flex:1}
.badge-req{font-size:9px;font-family:var(--fm);padding:3px 8px;border-radius:6px;background:rgba(239,68,68,.15);color:var(--dan);border:1px solid rgba(239,68,68,.3);letter-spacing:.05em;text-transform:uppercase}
.badge-auto{font-size:9px;font-family:var(--fm);padding:3px 8px;border-radius:6px;background:rgba(16,185,129,.12);color:var(--em);border:1px solid rgba(16,185,129,.3);letter-spacing:.05em;text-transform:uppercase}

/* Section lock overlay */
.card.locked{position:relative}
.card.locked .card-body::after{content:'Complete the section above first';position:absolute;inset:0;background:rgba(7,12,20,.75);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;font-family:var(--fm);font-size:13px;color:var(--t2);letter-spacing:.05em;z-index:10;border-radius:0 0 var(--rl) var(--rl);pointer-events:all;cursor:not-allowed}
.card.locked .card-body{position:relative}
.card.locked .card-header{opacity:.6;cursor:not-allowed}
.card.locked .card-header .lock-ic{display:inline-flex!important}
.lock-ic{display:none!important;align-items:center;gap:5px;font-size:11px;color:var(--t3);font-family:var(--fm);padding:4px 10px;background:rgba(255,255,255,.04);border:1px solid var(--bor);border-radius:8px}

.card-body{padding:24px;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

/* Form fields */
.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.fg:last-child{margin-bottom:0}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.fr3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px}
label{font-size:12px;font-weight:600;color:var(--t2);letter-spacing:.06em;text-transform:uppercase;font-family:var(--fm);display:flex;align-items:center;gap:6px}
label .req-star{color:var(--dan)}
label .auto-tag{font-size:9px;padding:2px 7px;border-radius:5px;background:rgba(16,185,129,.12);color:var(--em);border:1px solid rgba(16,185,129,.25);letter-spacing:.04em}
label .tip{color:var(--t3);font-size:10px;text-transform:none;letter-spacing:normal;font-family:var(--fb)}

input[type="text"],input[type="number"],input[type="date"],input[type="time"],select,textarea{
  width:100%;padding:11px 14px;border-radius:var(--r);
  border:1px solid var(--bor);background:var(--bg2);color:var(--t1);
  font-family:var(--fb);font-size:14px;transition:var(--tr);
  -webkit-appearance:none;appearance:none;
}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p);background:var(--bg3)}
input[readonly],select[disabled]{background:var(--bg1)!important;color:var(--t2)!important;border-color:rgba(255,255,255,.06)!important;cursor:not-allowed}
textarea{resize:vertical;min-height:130px;line-height:1.6}
select option{background:var(--bg2);color:var(--t1)}

/* Error/OK states */
.char-count{font-size:11px;font-family:var(--fm);color:var(--t3);text-align:right;margin-top:3px}
.char-count.warn{color:var(--warn)}
.char-count.over{color:var(--dan)}
.field-err{font-size:11px;color:var(--dan);display:none;align-items:center;gap:5px;margin-top:3px}
.fg.has-error input,.fg.has-error select,.fg.has-error textarea{border-color:var(--dan)!important;box-shadow:0 0 0 3px rgba(239,68,68,.15)!important;background:rgba(139,0,0,.08)!important}
.fg.has-error .field-err{display:flex}
.fg.has-ok input,.fg.has-ok select,.fg.has-ok textarea{border-color:var(--em)!important;box-shadow:0 0 0 3px rgba(16,185,129,.1)!important}

/* Toggle group */
.toggle-group{display:flex;gap:10px;flex-wrap:wrap}
.toggle-opt{flex:1;min-width:80px;padding:10px 14px;border-radius:var(--r);border:1px solid var(--bor);background:var(--bg2);color:var(--t2);font-size:13px;font-weight:500;text-align:center;cursor:pointer;transition:var(--tr);user-select:none}
.toggle-opt:hover{border-color:var(--gold);color:var(--gold)}
.toggle-opt.selected-yes{border-color:var(--em);background:rgba(16,185,129,.12);color:var(--em)}
.toggle-opt.selected-no{border-color:var(--dan);background:rgba(239,68,68,.1);color:var(--dan)}
.toggle-opt.selected-neutral{border-color:var(--gold);background:var(--gold-p);color:var(--gold)}

/* Severity pills */
.sev-group{display:flex;gap:10px;flex-wrap:wrap}
.sev-pill{flex:1;min-width:90px;padding:14px 10px;border-radius:var(--r);border:2px solid var(--bor);background:var(--bg2);text-align:center;cursor:pointer;transition:var(--tr);user-select:none}
.sev-pill .sev-label{font-size:12px;font-weight:700;font-family:var(--fm);letter-spacing:.05em;text-transform:uppercase}
.sev-pill .sev-desc{font-size:10px;color:var(--t3);margin-top:3px}
.sev-pill .sev-icon{font-size:20px;margin-bottom:6px}
.sev-pill[data-val="Low"]{border-color:rgba(16,185,129,.3)}
.sev-pill[data-val="Low"]:hover,.sev-pill[data-val="Low"].selected{border-color:var(--em);background:rgba(16,185,129,.1)}
.sev-pill[data-val="Low"] .sev-label{color:var(--em)}
.sev-pill[data-val="Medium"]{border-color:rgba(245,158,11,.3)}
.sev-pill[data-val="Medium"]:hover,.sev-pill[data-val="Medium"].selected{border-color:var(--warn);background:rgba(245,158,11,.1)}
.sev-pill[data-val="Medium"] .sev-label{color:var(--warn)}
.sev-pill[data-val="High"]{border-color:rgba(239,68,68,.3)}
.sev-pill[data-val="High"]:hover,.sev-pill[data-val="High"].selected{border-color:var(--dan);background:rgba(239,68,68,.1)}
.sev-pill[data-val="High"] .sev-label{color:var(--dan)}
.sev-pill[data-val="Critical"]{border-color:rgba(139,0,0,.5)}
.sev-pill[data-val="Critical"]:hover,.sev-pill[data-val="Critical"].selected{border-color:var(--burg);background:rgba(139,0,0,.2);animation:critical-pulse 1.5s ease-in-out infinite}
.sev-pill[data-val="Critical"] .sev-label{color:#ff4444}
@keyframes critical-pulse{0%,100%{box-shadow:0 0 0 0 rgba(139,0,0,.4)}50%{box-shadow:0 0 0 6px rgba(139,0,0,0)}}
input[name="priority"]{display:none}

/* Upload */
.upload-zone{border:2px dashed var(--bor);border-radius:var(--rl);padding:30px;text-align:center;cursor:pointer;transition:var(--tr);background:var(--gl);position:relative;overflow:hidden}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--gold);background:var(--gold-p)}
.upload-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-icon{font-size:36px;color:var(--t3);margin-bottom:10px;display:block;transition:var(--tr)}
.upload-zone:hover .upload-icon{color:var(--gold);transform:scale(1.1)}
.upload-zone h4{font-family:var(--fh);font-size:14px;font-weight:700;margin-bottom:6px}
.upload-zone p{font-size:12px;color:var(--t3)}
.upload-types{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:12px}
.type-badge{font-size:10px;font-family:var(--fm);padding:3px 8px;border-radius:6px;background:var(--gl);border:1px solid var(--bor);color:var(--t3)}
.preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;margin-top:14px}
.preview-item{position:relative;border-radius:var(--r);overflow:hidden;border:1px solid var(--bor);background:var(--bg2);aspect-ratio:1;display:flex;align-items:center;justify-content:center;flex-direction:column;font-size:11px;color:var(--t2);gap:4px;animation:fadeIn .3s ease}
.preview-item img,.preview-item video{width:100%;height:100%;object-fit:cover}
.preview-item .file-name{font-size:9px;text-align:center;padding:0 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%}
.preview-item .rem-btn{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:var(--dan);color:#fff;font-size:11px;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;transition:opacity .2s}
.preview-item:hover .rem-btn{opacity:1}

/* Info boxes */
.ib{display:flex;gap:12px;padding:14px 18px;border-radius:var(--r);margin-bottom:18px;font-size:13px;line-height:1.6}
.ib-info{background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.25);color:var(--sky)}
.ib-warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:var(--warn)}
.ib-auto{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);color:var(--em)}
.ib i{font-size:16px;flex-shrink:0;margin-top:1px}

/* Data strip */
.data-strip{display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap}
.data-chip{display:flex;align-items:center;gap:8px;background:var(--gl);border:1px solid var(--bor);border-radius:var(--r);padding:8px 14px;font-size:12px;font-family:var(--fm);color:var(--t2);flex:1;min-width:140px}
.data-chip .dc-dot{width:7px;height:7px;border-radius:50%;background:var(--em);animation:blink 2s ease-in-out infinite;flex-shrink:0}
.data-chip .dc-val{color:var(--gold);font-weight:600}

/* Autosave */
.autosave{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--t3);font-family:var(--fm);padding:6px 12px;border-radius:8px;background:var(--gl);border:1px solid var(--bor)}
.autosave .as-dot{width:6px;height:6px;border-radius:50%;background:var(--t3)}
.autosave.saving .as-dot{background:var(--warn);animation:blink .6s ease-in-out infinite}
.autosave.saved .as-dot{background:var(--em)}

/* Submit */
.submit-area{background:linear-gradient(135deg,rgba(139,0,0,.12),rgba(232,184,75,.07));border:1px solid var(--borh);border-radius:var(--rl);padding:30px 28px;text-align:center;margin-top:28px}
.submit-area h3{font-family:var(--fh);font-size:20px;font-weight:800;margin-bottom:8px}
.submit-area p{color:var(--t2);font-size:13px;margin-bottom:24px;line-height:1.7}
.checklist{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:24px}
.check-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);background:var(--gl);border:1px solid var(--bor);border-radius:var(--r);padding:6px 12px}
.check-item .ci{color:var(--em);font-size:11px}
.btn-submit{padding:16px 48px;border-radius:var(--r);background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-family:var(--fh);font-size:16px;font-weight:800;letter-spacing:.04em;transition:var(--tr);box-shadow:0 8px 28px var(--burg-g);display:inline-flex;align-items:center;gap:10px}
.btn-submit:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 36px var(--burg-g),0 0 40px var(--gold-p)}
.btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
.btn-submit .spin{animation:rotate .7s linear infinite}
@keyframes rotate{to{transform:rotate(360deg)}}
.btn-cancel{padding:14px 32px;border-radius:var(--r);background:var(--gl);border:1px solid var(--bor);color:var(--t2);font-size:14px;font-weight:500;transition:var(--tr);display:inline-flex;align-items:center;gap:8px;margin-top:12px}
.btn-cancel:hover{border-color:var(--dan);color:var(--dan);background:rgba(239,68,68,.07)}

/* Alerts */
#alerts{position:fixed;top:108px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.al{display:flex;align-items:flex-start;gap:10px;padding:14px 18px;border-radius:var(--r);font-size:13px;font-weight:500;pointer-events:all;backdrop-filter:var(--blur);box-shadow:var(--sh);min-width:280px;max-width:400px;animation:slide-in .35s cubic-bezier(.34,1.56,.64,1);transition:opacity .4s;line-height:1.5}
.al-s{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.4);color:var(--em)}
.al-e{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:var(--dan)}
.al-w{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:var(--warn)}
.al-i{background:rgba(14,165,233,.12);border:1px solid rgba(14,165,233,.3);color:var(--sky)}
@keyframes slide-in{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}

/* Validation summary */
#validation-summary{display:none;margin-bottom:20px;padding:16px 20px;border-radius:var(--r);background:rgba(139,0,0,.12);border:1px solid rgba(139,0,0,.4);color:#ff7070;font-size:13px;text-align:left}
#validation-summary strong{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:14px}
#val-list{margin-left:20px;line-height:2.2}
#val-list li::marker{color:var(--dan)}

/* Success screen */
#success-screen{display:none;position:fixed;inset:0;z-index:5000;background:rgba(7,12,20,.97);backdrop-filter:var(--blur);align-items:center;justify-content:center;padding:24px}
#success-screen.show{display:flex}
.success-card{background:var(--bg2);border:1px solid var(--borh);border-radius:var(--rl);padding:48px 40px;text-align:center;max-width:560px;width:100%;animation:pop .6s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{from{transform:scale(.7);opacity:0}to{transform:scale(1);opacity:1}}
.success-icon{width:88px;height:88px;border-radius:50%;margin:0 auto 24px;background:linear-gradient(135deg,var(--em),var(--teal));display:flex;align-items:center;justify-content:center;font-size:36px;color:#fff;box-shadow:0 16px 48px rgba(16,185,129,.35);animation:success-bounce .8s ease .3s both}
@keyframes success-bounce{0%{transform:scale(0)}70%{transform:scale(1.15)}100%{transform:scale(1)}}
.success-card h2{font-family:var(--fh);font-size:28px;font-weight:800;margin-bottom:12px}
.success-card p{color:var(--t2);font-size:14px;line-height:1.8;margin-bottom:8px}
.success-ref{background:var(--bg1);border:1px solid var(--borh);border-radius:var(--r);padding:14px 24px;margin:20px 0;font-family:var(--fm);font-size:18px;color:var(--gold);letter-spacing:.1em}
.success-steps{display:flex;gap:12px;margin:20px 0;text-align:left}
.ss-item{flex:1;background:var(--gl);border:1px solid var(--bor);border-radius:var(--r);padding:14px;font-size:12px;color:var(--t2);line-height:1.5}
.ss-item strong{display:block;color:var(--t1);margin-bottom:4px;font-family:var(--fm);font-size:11px}
.success-btns{display:flex;gap:12px;justify-content:center;margin-top:24px;flex-wrap:wrap}
.btn-home{padding:12px 28px;border-radius:var(--r);background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-family:var(--fh);font-size:14px;font-weight:700;transition:var(--tr)}
.btn-home:hover{transform:translateY(-2px);box-shadow:0 8px 24px var(--burg-g)}
.btn-new{padding:12px 28px;border-radius:var(--r);background:var(--gl);border:1px solid var(--bor);color:var(--t2);font-size:14px;transition:var(--tr)}
.btn-new:hover{border-color:var(--gold);color:var(--gold)}

/* Particles */
.particles{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.particle{position:absolute;width:3px;height:3px;border-radius:50%;background:var(--gold);opacity:0;animation:float-up 8s linear infinite}
@keyframes float-up{0%{transform:translateY(100vh);opacity:0}10%{opacity:.6}90%{opacity:.2}100%{transform:translateY(-100px) translateX(60px);opacity:0}}
.particle:nth-child(2n){background:var(--burg);animation-duration:10s}
.particle:nth-child(3n){background:var(--teal);width:2px;height:2px;animation-duration:12s}

/* Fault type card grid */
.fault-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-top:6px}
.fault-card{padding:14px 12px;border-radius:var(--r);border:1.5px solid var(--bor);background:var(--bg2);cursor:pointer;transition:var(--tr);text-align:center;user-select:none;position:relative}
.fault-card:hover{border-color:var(--gold);background:var(--gold-p)}
.fault-card.selected{border-color:var(--burg2);background:rgba(139,0,0,.15);box-shadow:0 0 0 3px rgba(139,0,0,.2)}
.fault-card .fc-icon{font-size:22px;margin-bottom:6px}
.fault-card .fc-name{font-size:12px;font-weight:600;font-family:var(--fm);letter-spacing:.04em;color:var(--t1);line-height:1.3}
.fault-card .fc-pri{font-size:10px;margin-top:4px;padding:2px 8px;border-radius:6px;display:inline-block}
.fault-card .fc-pri.Low{background:rgba(16,185,129,.15);color:var(--em)}
.fault-card .fc-pri.Medium{background:rgba(245,158,11,.15);color:var(--warn)}
.fault-card .fc-pri.High{background:rgba(239,68,68,.15);color:var(--dan)}
.fault-card .fc-pri.Critical{background:rgba(139,0,0,.2);color:#ff4444}
.fault-card.selected .fc-name{color:var(--gold)}
.fault-card .sel-check{position:absolute;top:6px;right:6px;width:18px;height:18px;border-radius:50%;background:var(--em);color:#fff;font-size:10px;display:none;align-items:center;justify-content:center}
.fault-card.selected .sel-check{display:flex}

@media(max-width:768px){
  .fr,.fr3{grid-template-columns:1fr}
  .sev-group{grid-template-columns:1fr 1fr}
  .page-hero h1{font-size:24px}
  .progress-steps{display:none}
  .data-strip{flex-direction:column}
  .success-steps{flex-direction:column}
  .success-card{padding:32px 24px}
  .fault-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}
}
@media(max-width:480px){
  header{padding:0 14px}
  .brand-nm{font-size:17px}
}
</style>
</head>
<body>

<div class="orb-wrap">
  <div class="orb o1"></div>
  <div class="orb o2"></div>
  <div class="orb o3"></div>
</div>
<div class="particles" id="particles"></div>

<!-- Ticker -->
<div class="ticker">
  <div class="ticker-inner" id="tickerInner">
    <span>⚡ BUSIQUIP ESWATINI — FAULT MANAGEMENT SYSTEM</span>
    <span>🔧 Submit Your Fault Report — We Respond Within 2 Business Hours</span>
    <span>📋 All Reports Reviewed By Our Admin Team Immediately</span>
    <span>🛡️ Secure &amp; Encrypted Submission</span>
    <span>🇸🇿 Mbabane, Eswatini — Serving Businesses With Excellence</span>
    <span>📞 Contact: +268 2404 0000 | support@busiquip.co.sz</span>
    <span>⚡ BUSIQUIP ESWATINI — FAULT MANAGEMENT SYSTEM</span>
    <span>🔧 Submit Your Fault Report — We Respond Within 2 Business Hours</span>
    <span>📋 All Reports Reviewed By Our Admin Team Immediately</span>
    <span>🛡️ Secure &amp; Encrypted Submission</span>
    <span>🇸🇿 Mbabane, Eswatini — Serving Businesses With Excellence</span>
    <span>📞 Contact: +268 2404 0000 | support@busiquip.co.sz</span>
  </div>
</div>

<!-- Header -->
<header>
  <div class="brand-wrap">
    <div class="brand-ic"><i class="fas fa-cog"></i></div>
    <div>
      <div class="brand-nm">BUSIQUIP</div>
      <div class="brand-sub">Eswatini — Fault Management</div>
    </div>
  </div>
  <div class="h-right">
    <div class="h-badge">
      <span class="dc-dot" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--em);margin-right:5px;animation:blink 2s ease-in-out infinite"></span>
      <?= htmlspecialchars($company_name) ?>
      <small>Session Active</small>
    </div>
    <div class="autosave" id="autosave-ind">
      <div class="as-dot"></div>
      <span id="autosave-txt">Auto-save on</span>
    </div>
    <button class="hb" onclick="toggleTheme()" title="Toggle Theme"><i class="fas fa-moon" id="themeIcon"></i></button>
    <a href="client_portal.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Portal</a>
  </div>
</header>

<div id="alerts"></div>

<!-- SUCCESS SCREEN -->
<div id="success-screen">
  <div class="success-card">
    <div class="success-icon"><i class="fas fa-check"></i></div>
    <h2>Fault Report Submitted!</h2>
    <p>Your report has been successfully submitted to the Busiquip admin team. You will be contacted promptly.</p>
    <div class="success-ref" id="success-ref-num">BQ-2026-00000</div>
    <p style="font-size:12px;color:var(--t3)">Keep this reference number to track your fault status.</p>
    <div class="success-steps">
      <div class="ss-item"><strong>01 — Admin Review</strong>Your report is now visible on the admin dashboard for immediate review.</div>
      <div class="ss-item"><strong>02 — Technician Assigned</strong>A technician will be dispatched based on severity and location.</div>
      <div class="ss-item"><strong>03 — You'll Be Notified</strong>Updates sent via your preferred contact method.</div>
    </div>
    <div class="success-btns">
      <a href="client_portal.php" class="btn-home"><i class="fas fa-home"></i> Return to Portal</a>
      <button class="btn-new" onclick="resetForm()"><i class="fas fa-plus"></i> Report Another</button>
    </div>
  </div>
</div>

<!-- MAIN FORM -->
<div class="page-wrap">

  <!-- Hero -->
  <div class="page-hero">
    <div class="hero-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <h1>Report a Fault</h1>
    <p>Complete each section in order. Our team reviews all reports immediately and assigns a qualified technician.</p>
    <div class="ref-display">
      <span class="dot"></span>
      <span>Reference: </span>
      <strong id="hero-ref"><?= $ref_num ?></strong>
    </div>
  </div>

  <!-- Progress Steps -->
  <div class="progress-steps">
    <div class="step active" id="step-1"><div class="step-num">01</div><div class="step-label">Reporter</div></div>
    <div class="step-line" id="line-1"></div>
    <div class="step" id="step-2"><div class="step-num">02</div><div class="step-label">Fault Type</div></div>
    <div class="step-line" id="line-2"></div>
    <div class="step" id="step-3"><div class="step-num">03</div><div class="step-label">Equipment</div></div>
    <div class="step-line" id="line-3"></div>
    <div class="step" id="step-4"><div class="step-num">04</div><div class="step-label">Details</div></div>
    <div class="step-line" id="line-4"></div>
    <div class="step" id="step-5"><div class="step-num">05</div><div class="step-label">Severity</div></div>
  </div>

  <!-- Data strip -->
  <div class="data-strip">
    <div class="data-chip"><span class="dc-dot"></span> Reference <span class="dc-val" id="strip-ref"><?= $ref_num ?></span></div>
    <div class="data-chip"><span class="dc-dot"></span> Status <span class="dc-val">Pending</span></div>
    <div class="data-chip"><span class="dc-dot"></span> Date <span class="dc-val"><?= date('d M Y') ?></span></div>
    <div class="data-chip"><span class="dc-dot"></span> Time <span class="dc-val" id="strip-time"><?= date('H:i') ?></span></div>
    <div class="data-chip"><span class="dc-dot"></span> Client <span class="dc-val"><?= htmlspecialchars(substr($company_name,0,20)) ?></span></div>
  </div>

  <form id="faultForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="fault_ref" id="fault_ref" value="<?= $ref_num ?>">
    <input type="hidden" name="fault_id" id="fault_id_hidden" value="">
    <input type="hidden" name="fault_type_label" id="fault_type_label" value="">
    <input type="hidden" name="priority" id="priority_val" value="Medium">
    <input type="hidden" name="is_operational" id="is_operational" value="Yes">
    <input type="hidden" name="occurred_before" id="occurred_before" value="No">
    <input type="hidden" name="service_visit" id="service_visit" value="Unsure">

    <!-- ═══════════════════════════════════════════════
         SECTION 1 — WHO IS REPORTING (auto + reporter)
    ═══════════════════════════════════════════════ -->
    <div class="card" id="card-reporter">
      <div class="card-header">
        <div class="sec-icon ic-client"><i class="fas fa-user-circle"></i></div>
        <h3>Reporter &amp; Company Details</h3>
        <span class="badge-auto">AUTO-FILLED</span>
      </div>
      <div class="card-body">
        <div class="ib ib-auto">
          <i class="fas fa-lock"></i>
          <div>Company information is pulled from your registered account. Only the <strong>Reported By</strong> field is editable — confirm or change who is submitting this report.</div>
        </div>

        <div class="fr">
          <div class="fg">
            <label><i class="fas fa-building"></i> Company Name <span class="auto-tag">SESSION</span></label>
            <input type="text" value="<?= $company_name ?>" readonly>
          </div>
          <div class="fg">
            <label><i class="fas fa-tags"></i> Client Type <span class="auto-tag">SESSION</span></label>
            <input type="text" value="<?= $client_type_v ?>" readonly>
          </div>
        </div>

        <div class="fr">
          <div class="fg">
            <label><i class="fas fa-phone"></i> Company Phone <span class="auto-tag">SESSION</span></label>
            <input type="text" value="<?= $company_phone ?>" readonly>
          </div>
          <div class="fg">
            <label><i class="fas fa-envelope"></i> Company Email <span class="auto-tag">SESSION</span></label>
            <input type="text" value="<?= $company_email ?>" readonly>
          </div>
        </div>

        <div class="fr">
          <div class="fg has-ok" id="fg-reported-by">
            <label><i class="fas fa-user-tie"></i> Reported By <span class="req-star">*</span></label>
            <input type="text" name="reported_by" id="reported_by"
                   value="<?= $contact_person ?>"
                   placeholder="Full name of person reporting" maxlength="150" required>
            <div class="field-err"><i class="fas fa-circle-exclamation"></i> Please enter the reporter's full name (at least 2 characters).</div>
          </div>
          <div class="fg">
            <label><i class="fas fa-comment-dots"></i> Preferred Contact Method</label>
            <select name="contact_method" id="contact_method">
              <option value="Email">Email</option>
              <option value="Phone">Phone Call</option>
              <option value="WhatsApp">WhatsApp</option>
              <option value="In-Person Visit">In-Person Visit</option>
              <option value="Any">Any Method</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 2 — FAULT TYPE (from DB `fault` table)
    ═══════════════════════════════════════════════ -->
    <div class="card locked" id="card-fault-type">
      <div class="card-header">
        <div class="sec-icon ic-fault"><i class="fas fa-bug"></i></div>
        <h3>Fault Type</h3>
        <span class="badge-req">REQUIRED</span>
        <span class="lock-ic"><i class="fas fa-lock"></i> Complete Section 1</span>
      </div>
      <div class="card-body">
        <div class="ib ib-info">
          <i class="fas fa-info-circle"></i>
          <div>Select the fault type that best matches your issue. The type is used to assign the right technician and set the default priority.</div>
        </div>
        <?php if (!empty($fault_types)): ?>
        <div class="fault-grid" id="faultTypeGrid">
          <?php
          $ft_icons = [
            'Hardware'      => '🖥️',
            'Software'      => '💻',
            'Network'       => '🌐',
            'Power'         => '⚡',
            'Print'         => '🖨️',
            'Printer'       => '🖨️',
            'Scan'          => '🖨️',
            'Display'       => '🖥️',
            'Monitor'       => '🖥️',
            'Storage'       => '💾',
            'Security'      => '🔒',
            'Access'        => '🔒',
            'Performance'   => '🚀',
            'Speed'         => '🚀',
            'Communication' => '📡',
            'Phone'         => '📞',
            'Server'        => '🗄️',
            'Peripheral'    => '🖱️',
            'Internet'      => '🌐',
            'Email'         => '📧',
          ];
          foreach ($fault_types as $ft):
            $ic = '🔧';
            foreach ($ft_icons as $kw => $emoji) {
              if (stripos($ft['FAULT_TYPE'], $kw) !== false) { $ic = $emoji; break; }
            }
            $pri = $ft['DEFAULT_PRIORITY'] ?? 'Medium';
          ?>
          <div class="fault-card" data-id="<?= $ft['FAULT_ID'] ?>" data-label="<?= htmlspecialchars($ft['FAULT_TYPE']) ?>" data-pri="<?= htmlspecialchars($pri) ?>" onclick="selectFaultType(this)">
            <div class="sel-check"><i class="fas fa-check"></i></div>
            <div class="fc-icon"><?= $ic ?></div>
            <div class="fc-name"><?= htmlspecialchars($ft['FAULT_TYPE']) ?></div>
            <div class="fc-pri <?= htmlspecialchars($pri) ?>"><?= htmlspecialchars($pri) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="ib ib-warn"><i class="fas fa-triangle-exclamation"></i><div>No fault types configured in the database. Please contact your Busiquip administrator to add fault types to the system.</div></div>
        <?php endif; ?>
        <div class="fg" id="fg-fault-type" style="margin-top:14px">
          <div class="field-err" style="display:none"><i class="fas fa-circle-exclamation"></i> Please select a fault type above.</div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 3 — EQUIPMENT
    ═══════════════════════════════════════════════ -->
    <div class="card locked" id="card-equip">
      <div class="card-header">
        <div class="sec-icon ic-equip"><i class="fas fa-desktop"></i></div>
        <h3>Equipment Details</h3>
        <span class="badge-req">REQUIRED</span>
        <span class="lock-ic"><i class="fas fa-lock"></i> Complete Section 2</span>
      </div>
      <div class="card-body">
        <div class="ib ib-info">
          <i class="fas fa-info-circle"></i>
          <div>Select the type of equipment affected. If this equipment is registered in the system, select it from the dropdown to link your report.</div>
        </div>

        <div class="fr">
          <div class="fg" id="fg-equipment-type">
            <label><i class="fas fa-laptop"></i> Equipment Type <span class="req-star">*</span></label>
            <select name="equipment_type" id="equipment_type" required>
              <option value="">— Select Equipment Type —</option>
              <option value="Desktop Computer">Desktop Computer</option>
              <option value="Laptop">Laptop</option>
              <option value="Printer">Printer</option>
              <option value="Photocopier / MFP">Photocopier / MFP</option>
              <option value="Scanner">Scanner</option>
              <option value="Server">Server</option>
              <option value="Network Switch">Network Switch</option>
              <option value="Router / Firewall">Router / Firewall</option>
              <option value="Monitor / Display">Monitor / Display</option>
              <option value="UPS / Power Equipment">UPS / Power Equipment</option>
              <option value="Projector">Projector</option>
              <option value="POS Terminal">POS Terminal</option>
              <option value="CCTV / Security System">CCTV / Security System</option>
              <option value="Telephone / PABX">Telephone / PABX</option>
              <option value="Other">Other</option>
            </select>
            <div class="field-err"><i class="fas fa-circle-exclamation"></i> Please select the equipment type.</div>
          </div>
          <div class="fg">
            <label><i class="fas fa-tag"></i> Brand / Model <span class="tip">(optional)</span></label>
            <input type="text" name="brand_model" id="brand_model" placeholder="e.g. HP LaserJet Pro M404, Dell OptiPlex…" maxlength="120">
          </div>
        </div>

        <div class="fr">
          <div class="fg">
            <label><i class="fas fa-barcode"></i> Serial / Asset Number <span class="tip">(optional)</span></label>
            <input type="text" name="serial_number" id="serial_number" placeholder="Device serial or company asset tag" maxlength="100">
          </div>
          <div class="fg">
            <label><i class="fas fa-box-open"></i> Registered Equipment <span class="tip">(if in system)</span></label>
            <select name="client_prod_id" id="client_prod_id">
              <option value="">— Loading your equipment… —</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 4 — FAULT DETAILS
    ═══════════════════════════════════════════════ -->
    <div class="card locked" id="card-details">
      <div class="card-header">
        <div class="sec-icon ic-fault"><i class="fas fa-align-left"></i></div>
        <h3>Fault Details</h3>
        <span class="badge-req">REQUIRED</span>
        <span class="lock-ic"><i class="fas fa-lock"></i> Complete Section 3</span>
      </div>
      <div class="card-body">
        <div class="ib ib-info">
          <i class="fas fa-info-circle"></i>
          <div>Describe the fault clearly. Include what happened, when it started, any error messages shown, and steps already attempted.</div>
        </div>

        <div class="fg" id="fg-description">
          <label><i class="fas fa-align-left"></i> Fault Description <span class="req-star">*</span></label>
          <textarea name="description" id="description"
            placeholder="Describe the fault in detail:&#10;• What happened / what you observed&#10;• When it started&#10;• Any error messages displayed&#10;• Steps already tried&#10;• Any recent changes to the equipment or environment"
            maxlength="2000" required></textarea>
          <div class="char-count" id="desc-count">0 / 2000</div>
          <div class="field-err"><i class="fas fa-circle-exclamation"></i> Please describe the fault (at least 20 characters).</div>
        </div>

        <div class="fr">
          <div class="fg" id="fg-fault-date">
            <label><i class="fas fa-calendar-alt"></i> Date Fault Occurred <span class="req-star">*</span></label>
            <input type="date" name="fault_date" id="fault_date" value="<?= $today ?>" max="<?= $today ?>" required>
            <div class="field-err"><i class="fas fa-circle-exclamation"></i> Select date the fault occurred (cannot be future).</div>
          </div>
          <div class="fg" id="fg-fault-time">
            <label><i class="fas fa-clock"></i> Time Fault Occurred <span class="req-star">*</span></label>
            <input type="time" name="fault_time" id="fault_time" value="<?= $now_time ?>" required>
            <div class="field-err"><i class="fas fa-circle-exclamation"></i> Please enter the time of the fault.</div>
          </div>
        </div>

        <div class="fr">
          <div class="fg">
            <label><i class="fas fa-map-pin"></i> Location of Fault <span class="tip">(optional)</span></label>
            <input type="text" name="fault_location" id="fault_location" placeholder="e.g. Accounts Department, IT Room, Ground Floor…" maxlength="150">
          </div>
          <div class="fg">
            <label><i class="fas fa-sitemap"></i> Department / Branch <span class="tip">(optional)</span></label>
            <input type="text" name="dept_branch" id="dept_branch" placeholder="e.g. Finance, IT, Operations, Branch Name…" maxlength="100">
          </div>
        </div>

        <div class="fr">
          <div class="fg">
            <label><i class="fas fa-users"></i> Number of Users Affected</label>
            <input type="number" name="users_affected" id="users_affected" value="1" min="1" max="9999">
          </div>
          <div class="fg">
            <label><i class="fas fa-truck-field"></i> On-Site Visit Required?</label>
            <div class="toggle-group">
              <div class="toggle-opt" data-group="service_visit" data-val="Yes" onclick="selectToggle(this,'service_visit')">🔧 Yes</div>
              <div class="toggle-opt" data-group="service_visit" data-val="No" onclick="selectToggle(this,'service_visit')">💻 Remote Only</div>
              <div class="toggle-opt selected-neutral" data-group="service_visit" data-val="Unsure" onclick="selectToggle(this,'service_visit')">❓ Not Sure</div>
            </div>
          </div>
        </div>

        <div class="fr" style="margin-top:6px">
          <div class="fg">
            <label><i class="fas fa-power-off"></i> Equipment Still Operational?</label>
            <div class="toggle-group">
              <div class="toggle-opt selected-yes" data-group="operational" data-val="Yes" onclick="selectToggle(this,'operational')">✅ Partially Working</div>
              <div class="toggle-opt" data-group="operational" data-val="No" onclick="selectToggle(this,'operational')">❌ Completely Down</div>
            </div>
          </div>
          <div class="fg">
            <label><i class="fas fa-rotate-left"></i> Occurred Before?</label>
            <div class="toggle-group">
              <div class="toggle-opt selected-no" data-group="recurrence" data-val="No" onclick="selectToggle(this,'recurrence')">❌ First Time</div>
              <div class="toggle-opt" data-group="recurrence" data-val="Yes" onclick="selectToggle(this,'recurrence')">⚠️ Recurring Issue</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 5 — SEVERITY
    ═══════════════════════════════════════════════ -->
    <div class="card locked" id="card-severity">
      <div class="card-header">
        <div class="sec-icon ic-sev"><i class="fas fa-thermometer-half"></i></div>
        <h3>Fault Severity</h3>
        <span class="badge-req">REQUIRED</span>
        <span class="lock-ic"><i class="fas fa-lock"></i> Complete Section 4</span>
      </div>
      <div class="card-body">
        <div class="ib ib-warn">
          <i class="fas fa-exclamation-triangle"></i>
          <div>The fault type above pre-selects a default severity. You may override it here. <strong>Critical</strong> faults get immediate priority response.</div>
        </div>
        <div class="sev-group">
          <div class="sev-pill" data-val="Low" onclick="selectSeverity(this)">
            <div class="sev-icon">🟢</div>
            <div class="sev-label">Low</div>
            <div class="sev-desc">Minor issue, workaround available</div>
          </div>
          <div class="sev-pill selected" data-val="Medium" onclick="selectSeverity(this)">
            <div class="sev-icon">🟡</div>
            <div class="sev-label">Medium</div>
            <div class="sev-desc">Affecting productivity, no workaround</div>
          </div>
          <div class="sev-pill" data-val="High" onclick="selectSeverity(this)">
            <div class="sev-icon">🔴</div>
            <div class="sev-label">High</div>
            <div class="sev-desc">Major disruption to operations</div>
          </div>
          <div class="sev-pill" data-val="Critical" onclick="selectSeverity(this)">
            <div class="sev-icon">🚨</div>
            <div class="sev-label">Critical</div>
            <div class="sev-desc">Complete business stoppage</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         SECTION 6 — FILE ATTACHMENTS (optional)
    ═══════════════════════════════════════════════ -->
    <div class="card" id="card-attach">
      <div class="card-header">
        <div class="sec-icon ic-attach"><i class="fas fa-paperclip"></i></div>
        <h3>Attachments <span style="font-weight:400;font-size:13px;color:var(--t3)">(Optional)</span></h3>
      </div>
      <div class="card-body">
        <div class="upload-zone" id="uploadZone">
          <input type="file" name="attachments[]" id="fileInput" multiple accept="image/*,video/*,.pdf,.doc,.docx,.txt" onchange="handleFiles(this.files)">
          <span class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></span>
          <h4>Drag &amp; Drop or Click to Browse</h4>
          <p>Upload photos, screenshots, or documents showing the fault</p>
          <div class="upload-types">
            <span class="type-badge">JPG / PNG</span>
            <span class="type-badge">MP4 / MOV</span>
            <span class="type-badge">PDF</span>
            <span class="type-badge">DOCX</span>
            <span class="type-badge">Max 20MB each</span>
          </div>
        </div>
        <div class="preview-grid" id="previewGrid"></div>
        <div style="text-align:right;margin-top:8px;font-size:12px;color:var(--t3);font-family:var(--fm)" id="fileCount">0 files selected</div>
      </div>
    </div>

    <!-- SUBMIT AREA -->
    <div class="submit-area">
      <h3>Ready to Submit?</h3>
      <p>Your fault report will be submitted securely and appear immediately on the Admin Dashboard for review and technician assignment.</p>
      <div class="checklist">
        <div class="check-item"><i class="fas fa-check ci"></i> Auto reference assigned</div>
        <div class="check-item"><i class="fas fa-check ci"></i> Status set to "Pending"</div>
        <div class="check-item"><i class="fas fa-check ci"></i> Admin notified instantly</div>
        <div class="check-item"><i class="fas fa-check ci"></i> Technician assignment queued</div>
        <div class="check-item"><i class="fas fa-shield-alt ci"></i> Secure &amp; encrypted</div>
      </div>

      <div id="validation-summary">
        <strong><i class="fas fa-triangle-exclamation"></i> Please fix the following before submitting:</strong>
        <ul id="val-list"></ul>
      </div>

      <button type="button" class="btn-submit" id="submitBtn" onclick="submitFault()" disabled>
        <i class="fas fa-paper-plane"></i>
        Submit Fault Report
      </button>
      <br>
      <a href="client_portal.php" class="btn-cancel">
        <i class="fas fa-times"></i> Cancel — Return to Portal
      </a>
    </div>
  </form>
</div>

<script>
// ── Live clock ───────────────────────────────────────────────────────
setInterval(()=>{
  const now=new Date();
  const el=document.getElementById('strip-time');
  if(el) el.textContent=now.toTimeString().slice(0,5);
},1000);

// ── Particles ────────────────────────────────────────────────────────
(function(){
  const c=document.getElementById('particles');
  for(let i=0;i<18;i++){
    const p=document.createElement('div');p.className='particle';
    p.style.left=Math.random()*100+'%';
    p.style.animationDelay=-(Math.random()*12)+'s';
    p.style.animationDuration=(8+Math.random()*8)+'s';
    c.appendChild(p);
  }
})();

// ── Theme ────────────────────────────────────────────────────────────
function toggleTheme(){
  document.body.classList.toggle('lm');
  document.getElementById('themeIcon').className=document.body.classList.contains('lm')?'fas fa-sun':'fas fa-moon';
  localStorage.setItem('bq_theme',document.body.classList.contains('lm')?'lm':'');
}
if(localStorage.getItem('bq_theme')==='lm'){
  document.body.classList.add('lm');
  document.getElementById('themeIcon').className='fas fa-sun';
}

// ── Section locking ──────────────────────────────────────────────────
// Sections unlock sequentially when previous is valid
const SECTIONS = ['card-reporter','card-fault-type','card-equip','card-details','card-severity'];

function unlockSections(){
  const s1Ok = reporterOk();
  const s2Ok = faultTypeOk();
  const s3Ok = equipOk();
  const s4Ok = detailsOk();

  setLocked('card-fault-type', !s1Ok);
  setLocked('card-equip',      !s2Ok);
  setLocked('card-details',    !s3Ok);
  setLocked('card-severity',   !s4Ok);
  updateProgress(s1Ok,s2Ok,s3Ok,s4Ok);
  validateSubmitBtn();
}

function setLocked(id, locked){
  const card=document.getElementById(id);
  if(!card) return;
  if(locked) card.classList.add('locked');
  else card.classList.remove('locked');
}

// ── Section validators ───────────────────────────────────────────────
function reporterOk(){
  const rb=document.getElementById('reported_by');
  return rb && rb.value.trim().length>=2;
}
function faultTypeOk(){
  return document.getElementById('fault_id_hidden').value !== '';
}
function equipOk(){
  return document.getElementById('equipment_type').value !== '';
}
function detailsOk(){
  const desc=document.getElementById('description').value.trim();
  const fd=document.getElementById('fault_date').value;
  const ft=document.getElementById('fault_time').value;
  return desc.length>=20 && fd && ft && new Date(fd)<=new Date();
}

// ── Fault type selector ──────────────────────────────────────────────
function selectFaultType(el){
  document.querySelectorAll('.fault-card').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
  const id  = el.dataset.id;
  const lbl = el.dataset.label;
  const pri = el.dataset.pri || 'Medium';
  document.getElementById('fault_id_hidden').value   = id;
  document.getElementById('fault_type_label').value  = lbl;
  // Auto-set severity to default priority
  selectSeverityByVal(pri);
  // Clear error
  const fgFt = document.getElementById('fg-fault-type');
  if(fgFt) fgFt.querySelector('.field-err').style.display='none';
  unlockSections();
  autoSave();
}

// ── Severity ─────────────────────────────────────────────────────────
function selectSeverity(el){
  document.querySelectorAll('.sev-pill').forEach(p=>p.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('priority_val').value=el.dataset.val;
  unlockSections();
}
function selectSeverityByVal(val){
  const pil=document.querySelector('.sev-pill[data-val="'+val+'"]');
  if(pil) selectSeverity(pil);
}

// ── Toggle group ─────────────────────────────────────────────────────
function selectToggle(el,group){
  document.querySelectorAll('[data-group="'+group+'"]').forEach(t=>{
    t.classList.remove('selected-yes','selected-no','selected-neutral');
  });
  const val=el.dataset.val;
  if(val==='Yes') el.classList.add('selected-yes');
  else if(val==='No') el.classList.add('selected-no');
  else el.classList.add('selected-neutral');
  const map={operational:'is_operational',recurrence:'occurred_before',service_visit:'service_visit'};
  if(map[group]) document.getElementById(map[group]).value=val;
}

// ── Progress steps ───────────────────────────────────────────────────
function updateProgress(s1,s2,s3,s4){
  const s5=document.getElementById('priority_val').value!=='';
  const done=[s1,s2,s3,s4,s5];
  for(let i=1;i<=5;i++){
    const s=document.getElementById('step-'+i);
    const l=document.getElementById('line-'+i);
    if(!s) continue;
    s.classList.toggle('done',done[i-1]);
    s.classList.toggle('active',!done[i-1]&&(i===1||done[i-2]));
    if(l) l.classList.toggle('done',done[i-1]);
  }
}

// ── Character counter ────────────────────────────────────────────────
(function(){
  const inp=document.getElementById('description');
  const cnt=document.getElementById('desc-count');
  inp.addEventListener('input',()=>{
    const len=inp.value.length;
    cnt.textContent=len+' / 2000';
    cnt.className='char-count'+(len>1800?' warn':'')+(len>=2000?' over':'');
  });
})();

// ── Live field validation ────────────────────────────────────────────
function markField(fgId,ok,touched){
  const fg=document.getElementById(fgId);
  if(!fg) return;
  if(!touched){fg.classList.remove('has-error','has-ok');return;}
  fg.classList.toggle('has-error',!ok);
  fg.classList.toggle('has-ok',ok);
}

// Reporter field
(function(){
  const el=document.getElementById('reported_by');
  el.addEventListener('input',()=>{
    const v=el.value.trim();
    markField('fg-reported-by',v.length>=2,v.length>0);
    unlockSections();
    autoSave();
  });
  el.addEventListener('blur',()=>{
    const v=el.value.trim();
    markField('fg-reported-by',v.length>=2,true);
    unlockSections();
  });
  // Initial mark since it has a pre-filled value
  markField('fg-reported-by',el.value.trim().length>=2,el.value.trim().length>0);
})();

// Equipment type
(function(){
  const el=document.getElementById('equipment_type');
  el.addEventListener('change',()=>{
    markField('fg-equipment-type',el.value!=='',true);
    unlockSections();
    autoSave();
  });
})();

// Description
(function(){
  const el=document.getElementById('description');
  el.addEventListener('input',()=>{
    const v=el.value.trim();
    if(el.dataset.touched) markField('fg-description',v.length>=20,true);
    unlockSections();
    autoSave();
  });
  el.addEventListener('blur',()=>{
    el.dataset.touched='1';
    markField('fg-description',el.value.trim().length>=20,true);
    unlockSections();
  });
})();

// Date
(function(){
  const el=document.getElementById('fault_date');
  el.addEventListener('change',()=>{
    const ok = el.value && new Date(el.value)<=new Date();
    markField('fg-fault-date',ok,true);
    unlockSections();
  });
})();

// Time
(function(){
  const el=document.getElementById('fault_time');
  el.addEventListener('change',()=>{
    markField('fg-fault-time',el.value!=='',true);
    unlockSections();
  });
})();

// ── Validate submit button ───────────────────────────────────────────
function validateSubmitBtn(){
  const errors=[];
  if(!reporterOk())   errors.push('Reporter name is required (at least 2 characters)');
  if(!faultTypeOk())  errors.push('Please select a fault type');
  if(!equipOk())      errors.push('Please select the equipment type');
  if(document.getElementById('description').value.trim().length<20)
                      errors.push('Fault description must be at least 20 characters');
  if(!document.getElementById('fault_date').value)
                      errors.push('Date the fault occurred is required');
  else if(new Date(document.getElementById('fault_date').value)>new Date())
                      errors.push('Fault date cannot be in the future');
  if(!document.getElementById('fault_time').value)
                      errors.push('Time the fault occurred is required');

  const btn=document.getElementById('submitBtn');
  btn.disabled=errors.length>0;

  const vs=document.getElementById('validation-summary');
  const vl=document.getElementById('val-list');
  if(errors.length>0){
    vs.style.display='block';
    vl.innerHTML=errors.map(e=>'<li>'+e+'</li>').join('');
  }else{
    vs.style.display='none';
  }
}

// ── Load client equipment ────────────────────────────────────────────
(function(){
  fetch('?action=get_client_products')
    .then(r=>r.json())
    .then(data=>{
      const sel=document.getElementById('client_prod_id');
      sel.innerHTML='<option value="">— Select if applicable —</option>';
      if(!data.length){sel.innerHTML='<option value="">No registered equipment found</option>';return;}
      data.forEach(p=>{
        const o=document.createElement('option');
        o.value=p.CLIENT_PROD_ID;
        o.textContent=p.PROD_NAME+(p.SERIAL_NUM?' (SN: '+p.SERIAL_NUM+')':'')+(p.PROD_TYPE?' — '+p.PROD_TYPE:'');
        sel.appendChild(o);
      });
    })
    .catch(()=>{document.getElementById('client_prod_id').innerHTML='<option value="">Could not load equipment</option>';});
})();

// ── File upload & preview ────────────────────────────────────────────
let selectedFiles=[];
function handleFiles(fileList){
  const allowed=['image/jpeg','image/png','image/gif','image/webp',
    'video/mp4','video/mpeg','video/quicktime','video/webm',
    'application/pdf','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain'];
  const maxSize=20*1024*1024;
  Array.from(fileList).forEach(f=>{
    if(!allowed.includes(f.type)){showPopup('error','File type not allowed: '+f.name);return;}
    if(f.size>maxSize){showPopup('error','File too large (max 20MB): '+f.name);return;}
    if(selectedFiles.find(sf=>sf.name===f.name&&sf.size===f.size)) return;
    selectedFiles.push(f);addPreview(f);
  });
  document.getElementById('fileCount').textContent=selectedFiles.length+' file'+(selectedFiles.length!==1?'s':'')+' selected';
}
function addPreview(file){
  const grid=document.getElementById('previewGrid');
  const div=document.createElement('div');div.className='preview-item';div.dataset.name=file.name;
  const rem=document.createElement('button');rem.className='rem-btn';rem.innerHTML='×';rem.type='button';
  rem.onclick=()=>{selectedFiles=selectedFiles.filter(f=>f.name!==file.name||f.size!==file.size);div.remove();
    document.getElementById('fileCount').textContent=selectedFiles.length+' file'+(selectedFiles.length!==1?'s':'')+' selected';};
  if(file.type.startsWith('image/')){
    const img=document.createElement('img');img.src=URL.createObjectURL(file);img.onload=()=>URL.revokeObjectURL(img.src);div.appendChild(img);
  }else if(file.type.startsWith('video/')){
    const vid=document.createElement('video');vid.src=URL.createObjectURL(file);vid.muted=true;vid.loop=true;vid.autoplay=true;div.appendChild(vid);
  }else{
    const iconMap={'application/pdf':'fa-file-pdf','application/msword':'fa-file-word','application/vnd.openxmlformats-officedocument.wordprocessingml.document':'fa-file-word','text/plain':'fa-file-alt'};
    const ic=document.createElement('i');ic.className='fas '+(iconMap[file.type]||'fa-file');ic.style.cssText='font-size:28px;color:var(--gold)';
    const nm=document.createElement('div');nm.className='file-name';nm.textContent=file.name;
    div.appendChild(ic);div.appendChild(nm);
  }
  div.appendChild(rem);grid.appendChild(div);
}
const zone=document.getElementById('uploadZone');
zone.addEventListener('dragover',e=>{e.preventDefault();zone.classList.add('drag-over')});
zone.addEventListener('dragleave',()=>zone.classList.remove('drag-over'));
zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('drag-over');handleFiles(e.dataTransfer.files)});

// ── Autosave ─────────────────────────────────────────────────────────
let autoTimer;
function autoSave(){
  const ind=document.getElementById('autosave-ind');
  const txt=document.getElementById('autosave-txt');
  ind.className='autosave saving';txt.textContent='Saving…';
  clearTimeout(autoTimer);
  autoTimer=setTimeout(()=>{
    try{
      sessionStorage.setItem('bq_draft',JSON.stringify({
        reported_by:document.getElementById('reported_by').value,
        fault_id:document.getElementById('fault_id_hidden').value,
        fault_type_label:document.getElementById('fault_type_label').value,
        description:document.getElementById('description').value,
        equipment_type:document.getElementById('equipment_type').value,
        brand_model:document.getElementById('brand_model').value,
        serial_number:document.getElementById('serial_number').value,
        fault_location:document.getElementById('fault_location').value,
        dept_branch:document.getElementById('dept_branch').value,
        users_affected:document.getElementById('users_affected').value,
        priority:document.getElementById('priority_val').value
      }));
    }catch(e){}
    ind.className='autosave saved';txt.textContent='Draft saved';
    setTimeout(()=>{ind.className='autosave';txt.textContent='Auto-save on'},3000);
  },800);
}

// Restore draft
(function(){
  try{
    const d=JSON.parse(sessionStorage.getItem('bq_draft')||'{}');
    if(d.fault_id&&d.fault_id!==''){
      // Restore simple fields
      ['reported_by','description','equipment_type','brand_model','serial_number','fault_location','dept_branch','users_affected'].forEach(k=>{
        const el=document.getElementById(k);
        if(el&&d[k]) el.value=d[k];
      });
      // Restore fault type selection
      const card=document.querySelector('.fault-card[data-id="'+d.fault_id+'"]');
      if(card){selectFaultType(card);}
      // Restore priority
      if(d.priority) selectSeverityByVal(d.priority);
      showPopup('info','Draft restored from your previous session.');
      markField('fg-reported-by',document.getElementById('reported_by').value.trim().length>=2,true);
    }
  }catch(e){}
  unlockSections();
})();

// ── Popup alert ───────────────────────────────────────────────────────
function showPopup(type,msg){
  const container=document.getElementById('alerts');
  const al=document.createElement('div');
  const map={success:'al-s',error:'al-e',warning:'al-w',info:'al-i'};
  const icons={success:'check-circle',error:'circle-xmark',warning:'triangle-exclamation',info:'info-circle'};
  al.className='al '+(map[type]||'al-i');
  al.innerHTML='<i class="fas fa-'+(icons[type]||'info-circle')+'" style="flex-shrink:0;margin-top:1px"></i><span>'+msg+'</span>';
  container.appendChild(al);
  setTimeout(()=>{al.style.opacity='0';setTimeout(()=>al.remove(),400);},6000);
}

// ── SUBMIT ────────────────────────────────────────────────────────────
async function submitFault(){
  // Trigger touched states for validation display
  document.getElementById('reported_by').dispatchEvent(new Event('blur'));
  document.getElementById('description').dataset.touched='1';
  document.getElementById('description').dispatchEvent(new Event('blur'));
  document.getElementById('fault_date').dispatchEvent(new Event('change'));
  document.getElementById('fault_time').dispatchEvent(new Event('change'));
  document.getElementById('equipment_type').dispatchEvent(new Event('change'));

  if(!reporterOk()||!faultTypeOk()||!equipOk()||!detailsOk()){
    showPopup('error','Please complete all required fields correctly before submitting.');
    validateSubmitBtn();
    return;
  }

  const btn=document.getElementById('submitBtn');
  btn.disabled=true;
  btn.innerHTML='<i class="fas fa-spinner spin"></i> Submitting…';

  const fd=new FormData(document.getElementById('faultForm'));
  selectedFiles.forEach(f=>fd.append('attachments[]',f));

  try{
    const res=await fetch('?action=submit_fault',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
      try{sessionStorage.removeItem('bq_draft');}catch(e){}
      document.getElementById('success-ref-num').textContent=data.ref||'BQ-'+data.id;
      document.getElementById('success-screen').classList.add('show');
      showPopup('success','Fault submitted! Ref: '+data.ref);
    }else{
      showPopup('error',data.error||'Submission failed. Please try again.');
      btn.disabled=false;
      btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Fault Report';
    }
  }catch(err){
    showPopup('error','Network error. Please check your connection and try again.');
    btn.disabled=false;
    btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Fault Report';
  }
}

// ── Reset form ────────────────────────────────────────────────────────
function resetForm(){
  document.getElementById('success-screen').classList.remove('show');
  document.getElementById('faultForm').reset();
  document.getElementById('previewGrid').innerHTML='';
  selectedFiles=[];
  document.getElementById('fileCount').textContent='0 files selected';
  document.getElementById('fault_id_hidden').value='';
  document.getElementById('fault_type_label').value='';
  document.querySelectorAll('.fault-card').forEach(c=>c.classList.remove('selected'));
  document.querySelectorAll('.sev-pill').forEach(p=>p.classList.remove('selected'));
  document.querySelector('.sev-pill[data-val="Medium"]').classList.add('selected');
  document.getElementById('priority_val').value='Medium';
  document.querySelectorAll('[data-group]').forEach(t=>t.classList.remove('selected-yes','selected-no','selected-neutral'));
  document.querySelector('[data-group="operational"][data-val="Yes"]').classList.add('selected-yes');
  document.querySelector('[data-group="recurrence"][data-val="No"]').classList.add('selected-no');
  document.querySelector('[data-group="service_visit"][data-val="Unsure"]').classList.add('selected-neutral');
  document.getElementById('is_operational').value='Yes';
  document.getElementById('occurred_before').value='No';
  document.getElementById('service_visit').value='Unsure';
  document.getElementById('desc-count').textContent='0 / 2000';
  document.querySelectorAll('.fg').forEach(fg=>fg.classList.remove('has-error','has-ok'));
  const newRef='BQ-'+new Date().getFullYear()+'-'+String(Math.floor(Math.random()*99999)+1).padStart(5,'0');
  document.getElementById('fault_ref').value=newRef;
  document.getElementById('hero-ref').textContent=newRef;
  document.getElementById('strip-ref').textContent=newRef;
  document.getElementById('submitBtn').innerHTML='<i class="fas fa-paper-plane"></i> Submit Fault Report';
  // Re-prefill reported_by
  document.getElementById('reported_by').value='<?= addslashes($contact_person) ?>';
  markField('fg-reported-by',document.getElementById('reported_by').value.trim().length>=2,true);
  unlockSections();
  window.scrollTo({top:0,behavior:'smooth'});
}

// Initial unlock check
unlockSections();
</script>
</body>
</html>
