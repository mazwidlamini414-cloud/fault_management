<?php
// ═══════════════════════════════════════════════════════════════════════
//  FILE PATH: fault_management/modules/clients/client_profile.php
//  BUSIQUIP ESWATINI — Client Profile Management (Full & Fixed)
// ═══════════════════════════════════════════════════════════════════════
ini_set('display_errors',1); error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['client_id'])) { header('Location: client_login.php'); exit; }

$client_id      = (int)$_SESSION['client_id'];
$client_name    = $_SESSION['client_name']    ?? 'Client';
$client_contact = $_SESSION['client_contact'] ?? '';
$client_email   = $_SESSION['client_email']   ?? '';
$client_type    = $_SESSION['client_type']    ?? 'CORPORATE';

require_once __DIR__ . '/../../config/database.php';
if ($conn->connect_error) die("DB Error: ".$conn->connect_error);
$conn->set_charset('utf8mb4');

if (isset($_POST['logout'])) { session_destroy(); header('Location: client_login.php'); exit; }

// ── AJAX ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_profile') {
        $stmt = $conn->prepare("SELECT * FROM client WHERE CLIENT_ID=?");
        $stmt->bind_param("i",$client_id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        echo json_encode($row ?: []); exit;
    }

    if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data    = json_decode(file_get_contents('php://input'),true);
        $phone   = $conn->real_escape_string(trim($data['phone']   ?? ''));
        $email   = $conn->real_escape_string(trim($data['email']   ?? ''));
        $address = $conn->real_escape_string(trim($data['address'] ?? ''));
        $contact = $conn->real_escape_string(trim($data['contact'] ?? ''));
        if (!filter_var($data['email']??'',FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'error'=>'Invalid email address.']); exit;
        }
        $conn->query("UPDATE client SET COMPANY_PHONE='$phone',COMPANY_EMAIL='$email',COMPANY_ADDRESS='$address',CONTACT_PERSON_NAME='$contact' WHERE CLIENT_ID=$client_id");
        if (!$conn->error) {
            $_SESSION['client_contact']=$data['contact']; $_SESSION['client_email']=$data['email'];
            echo json_encode(['success'=>true,'message'=>'Profile updated successfully.']);
        } else { echo json_encode(['success'=>false,'error'=>$conn->error]); }
        exit;
    }

    if ($action === 'change_password' && $_SERVER['REQUEST_METHOD']==='POST') {
        $data    = json_decode(file_get_contents('php://input'),true);
        $current = $data['current_password'] ?? '';
        $newpw   = $data['new_password']     ?? '';
        $confirm = $data['confirm_password'] ?? '';
        if (strlen($newpw)<6)         { echo json_encode(['success'=>false,'error'=>'Password must be at least 6 characters.']); exit; }
        if ($newpw !== $confirm)      { echo json_encode(['success'=>false,'error'=>'Passwords do not match.']); exit; }
        $stmt = $conn->prepare("SELECT PASSWORD_HASH FROM client WHERE CLIENT_ID=?");
        $stmt->bind_param("i",$client_id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!password_verify($current,$row['PASSWORD_HASH']??'')) {
            echo json_encode(['success'=>false,'error'=>'Current password is incorrect.']); exit;
        }
        $hash = $conn->real_escape_string(password_hash($newpw,PASSWORD_DEFAULT));
        $conn->query("UPDATE client SET PASSWORD_HASH='$hash' WHERE CLIENT_ID=$client_id");
        echo json_encode(['success'=>true,'message'=>'Password changed successfully.']); exit;
    }

    if ($action === 'get_stats') {
        $stats = [];
        $stats['total_faults']     = (int)$conn->query("SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id")->fetch_assoc()['n'];
        $stats['closed_faults']    = (int)$conn->query("SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS IN('Closed','Client Approved')")->fetch_assoc()['n'];
        $stats['active_faults']    = (int)$conn->query("SELECT COUNT(*) n FROM reported_fault WHERE CLIENT_ID=$client_id AND STATUS NOT IN('Closed','Rejected','Client Approved')")->fetch_assoc()['n'];
        $stats['total_paid']       = floatval($conn->query("SELECT COALESCE(SUM(TOTAL),0) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS='Paid'")->fetch_assoc()['n']);
        $stats['products']         = (int)$conn->query("SELECT COUNT(*) n FROM client_product WHERE CLIENT_ID=$client_id")->fetch_assoc()['n'];
        $stats['pending_invoices'] = (int)$conn->query("SELECT COUNT(*) n FROM invoice WHERE CLIENT_ID=$client_id AND STATUS NOT IN('Paid')")->fetch_assoc()['n'];
        echo json_encode($stats); exit;
    }

    if ($action === 'get_activity') {
        $res = $conn->query("SELECT rf.REP_FAULT_ID, rf.REPORT_DATE, rf.STATUS, rf.PRIORITY, rf.DESCRIPTION FROM reported_fault rf WHERE rf.CLIENT_ID=$client_id ORDER BY rf.REPORT_DATE DESC LIMIT 10");
        $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode($rows); exit;
    }

    if ($action === 'get_notifications') {
        $res = $conn->query("SELECT * FROM notifications WHERE user_id=$client_id AND user_type='Client' ORDER BY created_at DESC LIMIT 30");
        $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r;
        echo json_encode($rows); exit;
    }

    if ($action === 'mark_read') {
        $nid = (int)($_GET['nid']??0);
        if($nid) $conn->query("UPDATE notifications SET is_read=1 WHERE id=$nid AND user_id=$client_id");
        else $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$client_id AND user_type='Client'");
        echo json_encode(['success'=>true]); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

$notif_count = (int)$conn->query("SELECT COUNT(*) n FROM notifications WHERE user_id=$client_id AND user_type='Client' AND is_read=0")->fetch_assoc()['n'];
$client_row  = $conn->query("SELECT * FROM client WHERE CLIENT_ID=$client_id")->fetch_assoc();
$c_initial   = strtoupper(substr($client_row['COMPANY_NAME'] ?? $client_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — BUSIQUIP ESWATINI</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --burg:#8B0000;--burg2:#C0392B;--burg-g:rgba(139,0,0,.3);
    --gold:#E8B84B;--gold2:#FFD700;--gold-p:rgba(232,184,75,.12);
    --teal:#0D9488;--sky:#0EA5E9;--em:#10B981;
    --warn:#F59E0B;--dan:#EF4444;--ind:#6366F1;
    --bg0:#070C14;--bg1:#0D1421;--bg2:#111B2E;--bg3:#1A2640;
    --sur:rgba(17,27,46,.95);--gl:rgba(255,255,255,.04);--glb:rgba(255,255,255,.07);
    --bor:rgba(232,184,75,.16);--borh:rgba(232,184,75,.4);
    --t1:#EFF4FF;--t2:#8A9CC4;--t3:#445570;
    --r:14px;--rl:22px;--sh:0 8px 32px rgba(0,0,0,.5);
    --blur:blur(18px);--tr:all .28s cubic-bezier(.4,0,.2,1);
    --fh:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;
    --sw:260px;--hh:70px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg0);color:var(--t1);min-height:100vh;overflow-x:hidden}
a{text-decoration:none;color:inherit}
button{font-family:var(--fb);cursor:pointer;border:none;outline:none}
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:var(--bg1)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:99px}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(232,184,75,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(232,184,75,.03) 1px,transparent 1px);background-size:48px 48px}
/* ORB */
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;opacity:.15;animation:orb 18s ease-in-out infinite}
.o1{width:480px;height:480px;top:-150px;left:-150px;background:radial-gradient(circle,var(--burg),transparent)}
.o2{width:380px;height:380px;bottom:-80px;right:-80px;background:radial-gradient(circle,var(--gold),transparent);animation-delay:-6s}
@keyframes orb{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,-40px) scale(1.1)}}
/* TICKER */
.ticker{position:fixed;top:0;left:0;right:0;height:26px;z-index:2000;background:linear-gradient(90deg,var(--burg),#6B0000,var(--burg));overflow:hidden;display:flex;align-items:center}
.ticker-inner{display:flex;gap:70px;white-space:nowrap;animation:tick 28s linear infinite;font-family:var(--fm);font-size:10px;letter-spacing:.06em;color:var(--gold2)}
@keyframes tick{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
/* HEADER */
header{position:fixed;top:26px;left:0;right:0;height:var(--hh);z-index:1500;background:rgba(7,12,20,.92);backdrop-filter:var(--blur);border-bottom:1px solid var(--bor);display:flex;align-items:center;padding:0 24px 0 calc(var(--sw)+24px);gap:16px;box-shadow:0 4px 24px rgba(0,0,0,.4)}
.brand{position:absolute;left:16px;display:flex;align-items:center;gap:10px}
.brand-ic{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-size:20px}
.brand-nm{font-family:var(--fh);font-size:20px;font-weight:800;background:linear-gradient(135deg,var(--gold2),var(--burg2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:.06em}
.brand-sub{font-size:9px;color:var(--t2);letter-spacing:.15em;text-transform:uppercase;font-family:var(--fm)}
.h-title{flex:1;font-family:var(--fh);font-size:16px;font-weight:700}
.h-title span{color:var(--t3);font-size:12px;font-weight:400;margin-left:8px;font-family:var(--fb)}
.h-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.hb{width:36px;height:36px;border-radius:50%;border:1px solid var(--bor);background:var(--gl);color:var(--t2);font-size:14px;display:flex;align-items:center;justify-content:center;transition:var(--tr);cursor:pointer;position:relative;text-decoration:none}
.hb:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-p)}
.hb.lo{border-color:rgba(239,68,68,.3);color:var(--dan)}.hb.lo:hover{background:rgba(239,68,68,.1);border-color:var(--dan)}
.h-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:15px;font-weight:800;color:#fff;border:2px solid var(--gold);cursor:pointer;transition:var(--tr)}
.h-av:hover{transform:scale(1.08)}
.h-nm{line-height:1.3}.h-nm .n{font-weight:600;font-size:13px}.h-nm .e{font-size:11px;color:var(--t2)}
/* SIDEBAR */
.sidebar{position:fixed;top:calc(26px + var(--hh));left:0;width:var(--sw);height:calc(100vh - 26px - var(--hh));background:rgba(11,18,33,.97);backdrop-filter:var(--blur);border-right:1px solid var(--bor);padding:16px 0 80px;overflow-y:auto;z-index:1200}
.slbl{padding:10px 20px 5px;font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--t3);font-family:var(--fm);font-weight:600}
.ni{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;border-radius:10px;color:var(--t2);font-size:13px;font-weight:500;cursor:pointer;transition:var(--tr);border:1px solid transparent;text-decoration:none}
.ni:hover,.ni.act{background:var(--gold-p);color:var(--gold);border-color:var(--bor);transform:translateX(3px)}
.ni .ic{width:28px;height:28px;border-radius:8px;background:var(--gl);display:flex;align-items:center;justify-content:center;font-size:13px;transition:var(--tr);flex-shrink:0}
.ni:hover .ic,.ni.act .ic{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 10px var(--burg-g)}
.ni .nb{margin-left:auto;background:var(--dan);color:#fff;font-size:10px;padding:2px 6px;border-radius:99px;font-weight:700}
.nb-gold{background:var(--burg)!important}
.exp-btn{display:flex;align-items:center;gap:11px;padding:10px 18px;margin:2px 8px;border-radius:10px;background:none;border:1px solid var(--borh);color:var(--gold);font-size:12px;font-weight:700;width:calc(100% - 16px);transition:var(--tr)}
.exp-btn:hover{background:var(--gold-p)}.exp-btn .ch{margin-left:auto;transition:transform .3s}.exp-btn.open .ch{transform:rotate(180deg)}
.sub-menu{max-height:0;overflow:hidden;transition:max-height .4s ease}.sub-menu.open{max-height:400px}
.si{display:flex;align-items:center;gap:9px;padding:8px 14px 8px 42px;margin:2px 8px;border-radius:8px;color:var(--t3);font-size:12px;cursor:pointer;transition:var(--tr);text-decoration:none}
.si:hover{color:var(--gold);background:var(--gold-p)}
.s-banner{margin:14px 10px;padding:12px;background:linear-gradient(135deg,rgba(139,0,0,.22),rgba(232,184,75,.1));border:1px solid var(--borh);border-radius:var(--r);text-align:center}
.s-banner i{font-size:22px;color:var(--gold);margin-bottom:6px;display:block}
.s-banner p{font-size:11px;color:var(--t2);line-height:1.5}
.s-banner a{display:inline-block;margin-top:8px;padding:5px 12px;border-radius:6px;background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;font-size:11px;font-weight:700}
/* MAIN */
main{margin-left:var(--sw);padding-top:calc(26px + var(--hh) + 28px);padding-bottom:60px;padding-left:28px;padding-right:28px;position:relative;z-index:1;min-height:100vh}
/* ALERTS */
#alerts{position:fixed;top:calc(26px + var(--hh) + 12px);right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.al{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--r);font-size:13px;font-weight:500;pointer-events:all;backdrop-filter:var(--blur);box-shadow:var(--sh);min-width:260px;animation:alin .3s ease}
.al-s{background:rgba(16,185,129,.14);border:1px solid var(--em);color:var(--em)}
.al-e{background:rgba(239,68,68,.14);border:1px solid var(--dan);color:var(--dan)}
.al-i{background:rgba(99,102,241,.14);border:1px solid var(--ind);color:var(--ind)}
@keyframes alin{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
/* PROFILE BANNER */
.profile-banner{background:linear-gradient(135deg,rgba(139,0,0,.2),rgba(232,184,75,.08));border:1px solid var(--borh);border-radius:24px;padding:32px 36px;margin-bottom:28px;display:flex;align-items:center;gap:28px;position:relative;overflow:hidden}
.profile-banner::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(232,184,75,.06),transparent);pointer-events:none}
.avatar-xl{width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,var(--burg),var(--gold));display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:36px;font-weight:800;color:#fff;border:3px solid var(--borh);box-shadow:0 0 32px var(--burg-g);flex-shrink:0;animation:pulse-av 3s ease-in-out infinite}
@keyframes pulse-av{0%,100%{box-shadow:0 0 24px var(--burg-g)}50%{box-shadow:0 0 42px var(--burg-g),0 0 60px var(--gold-p)}}
.profile-info h1{font-family:var(--fh);font-size:26px;font-weight:800;margin-bottom:4px}
.profile-info p{color:var(--t2);font-size:14px;margin-bottom:12px}
.profile-chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:600;border:1px solid}
.chip-gold{border-color:var(--borh);color:var(--gold);background:var(--gold-p)}
.chip-em{border-color:rgba(16,185,129,.3);color:var(--em);background:rgba(16,185,129,.08)}
.chip-teal{border-color:rgba(13,148,136,.3);color:var(--teal);background:rgba(13,148,136,.08)}
.profile-actions{margin-left:auto;display:flex;flex-direction:column;gap:8px;flex-shrink:0}
/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:28px}
.stat-c{background:var(--sur);border:1px solid var(--bor);border-radius:16px;padding:18px 16px;backdrop-filter:var(--blur);transition:var(--tr);cursor:default;animation:pi .4s ease both}
@keyframes pi{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
.stat-c:hover{border-color:var(--borh);transform:translateY(-3px);box-shadow:var(--sh)}
.stat-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:10px}
.ic-b{background:rgba(139,0,0,.18);color:var(--burg2)}
.ic-g{background:rgba(232,184,75,.14);color:var(--gold)}
.ic-e{background:rgba(16,185,129,.14);color:var(--em)}
.ic-t{background:rgba(13,148,136,.14);color:var(--teal)}
.ic-i{background:rgba(99,102,241,.14);color:var(--ind)}
.ic-r{background:rgba(239,68,68,.14);color:var(--dan)}
.stat-n{font-family:var(--fh);font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--t1),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:3px}
.stat-l{font-size:11px;color:var(--t2)}
/* TABS */
.tab-nav{display:flex;gap:3px;border-bottom:1px solid var(--bor);margin-bottom:24px}
.tab-btn{padding:10px 18px;background:none;color:var(--t2);font-size:13px;font-weight:600;border-bottom:2px solid transparent;cursor:pointer;transition:var(--tr);margin-bottom:-1px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:7px}
.tab-btn:hover{color:var(--t1);background:var(--gl)}
.tab-btn.act{color:var(--gold);border-bottom-color:var(--gold)}
.tab-panel{display:none}.tab-panel.act{display:block;animation:fi .25s ease}
@keyframes fi{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
/* CARDS */
.card{background:var(--sur);border:1px solid var(--bor);border-radius:20px;padding:24px;backdrop-filter:var(--blur);margin-bottom:20px;transition:var(--tr)}
.card:hover{border-color:var(--borh)}
.card-title{font-family:var(--fh);font-size:16px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.card-title i{color:var(--gold)}
/* FORM */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.fg label{font-size:11px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;font-family:var(--fm)}
.fg input,.fg select,.fg textarea{background:var(--bg3);border:1px solid var(--bor);color:var(--t1);padding:10px 13px;border-radius:10px;font-family:var(--fb);font-size:14px;transition:var(--tr);outline:none;width:100%}
.fg input::placeholder,.fg textarea::placeholder{color:var(--t3)}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-p)}
.fg input[readonly]{background:var(--bg2)!important;color:var(--t3)!important;cursor:not-allowed}
.fg select option{background:var(--bg2);color:var(--t1)}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;transition:var(--tr)}
.btn-p{background:linear-gradient(135deg,var(--burg),var(--gold));color:#fff;box-shadow:0 4px 14px var(--burg-g)}
.btn-p:hover{transform:translateY(-2px);box-shadow:0 8px 22px var(--burg-g)}
.btn-s{background:var(--gl);border:1px solid var(--borh);color:var(--gold)}
.btn-s:hover{background:var(--gold-p)}
.btn-d{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:var(--dan)}
.btn-d:hover{background:rgba(239,68,68,.25)}
/* NOTIFICATIONS */
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:12px;border:1px solid var(--bor);margin-bottom:8px;transition:var(--tr);cursor:pointer}
.notif-item:hover{border-color:var(--borh);background:var(--gl)}
.notif-item.unread{border-left:3px solid var(--gold);background:var(--gold-p)}
.notif-ic{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,rgba(139,0,0,.2),rgba(232,184,75,.1));display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;color:var(--gold)}
.notif-title{font-size:13px;font-weight:600;margin-bottom:3px}
.notif-msg{font-size:12px;color:var(--t2);line-height:1.5}
.notif-time{font-size:10px;color:var(--t3);margin-top:4px;font-family:var(--fm)}
/* ACTIVITY */
.act-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:1px solid var(--bor);margin-bottom:8px;transition:var(--tr)}
.act-item:hover{border-color:var(--borh);transform:translateX(4px)}
.act-ic{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,rgba(139,0,0,.18),rgba(232,184,75,.1));display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
/* BADGE */
.b{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.b-p{background:rgba(245,158,11,.14);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.b-i{background:rgba(14,165,233,.14);color:var(--sky);border:1px solid rgba(14,165,233,.3)}
.b-a{background:rgba(99,102,241,.14);color:var(--ind);border:1px solid rgba(99,102,241,.3)}
.b-r{background:rgba(16,185,129,.14);color:var(--em);border:1px solid rgba(16,185,129,.3)}
.b-d{background:rgba(239,68,68,.14);color:var(--dan);border:1px solid rgba(239,68,68,.3)}
/* WALLET */
.wallet-banner{background:linear-gradient(135deg,var(--burg),rgba(139,0,0,.6),var(--bg3));border:1px solid var(--borh);border-radius:20px;padding:28px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
.wallet-amt{font-family:var(--fh);font-size:40px;font-weight:800;color:var(--gold2)}
.wallet-lbl{font-size:12px;color:var(--t2);margin-top:4px}
/* SECURITY INFO */
.sec-item{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--bg3);border:1px solid var(--bor);border-radius:12px;margin-bottom:10px}
.sec-item i{font-size:20px;flex-shrink:0}
.sec-item h4{font-size:13px;font-weight:600;margin-bottom:2px}
.sec-item p{font-size:11px;color:var(--t2)}
/* SPINNER */
.spin-lg{width:40px;height:40px;border-radius:50%;border:3px solid var(--bor);border-top-color:var(--gold);animation:sp .8s linear infinite;margin:20px auto}
@keyframes sp{to{transform:rotate(360deg)}}
/* RESPONSIVE */
@media(max-width:1024px){main{margin-left:0;padding-left:14px;padding-right:14px}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}header{padding-left:70px}.brand{position:static}}
@media(max-width:768px){.form-row{grid-template-columns:1fr}.profile-banner{flex-direction:column;align-items:flex-start}.profile-actions{margin-left:0}.stats-grid{grid-template-columns:repeat(2,1fr)}.h-nm{display:none}}
</style>
</head>
<body>
<div class="orb o1"></div><div class="orb o2"></div>

<!-- TICKER -->
<div class="ticker">
  <div class="ticker-inner">
    <span>⚙️ BUSIQUIP ESWATINI — CLIENT PORTAL &nbsp;✦&nbsp; My Profile &amp; Account Management</span>
    <span>🛡️ Your data is secure and encrypted &nbsp;✦&nbsp; Update your details anytime</span>
    <span>⚙️ BUSIQUIP ESWATINI — CLIENT PORTAL &nbsp;✦&nbsp; My Profile &amp; Account Management</span>
    <span>🛡️ Your data is secure and encrypted &nbsp;✦&nbsp; Update your details anytime</span>
  </div>
</div>

<!-- HEADER -->
<header>
  <a href="client_portal.php" class="brand">
    <div class="brand-ic">⚙️</div>
    <div><div class="brand-nm">BUSIQUIP</div><div class="brand-sub">Client Portal</div></div>
  </a>
  <button style="background:none;color:var(--t2);font-size:18px;margin-right:8px;display:none" id="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
  <div class="h-title">My Profile <span>Account &amp; Settings</span></div>
  <div class="h-right">
    <div class="h-nm"><div class="n"><?= htmlspecialchars($client_name) ?></div><div class="e"><?= htmlspecialchars($client_email) ?></div></div>
    <div class="h-av"><?= $c_initial ?></div>
    <a href="client_portal.php" class="hb" title="Dashboard"><i class="fas fa-home"></i></a>
    <a href="client_notifications.php" class="hb" title="Notifications" style="position:relative">
      <i class="fas fa-bell"></i>
      <?php if($notif_count>0): ?><span style="position:absolute;top:-3px;right:-3px;background:var(--dan);color:#fff;width:16px;height:16px;border-radius:50%;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center"><?= $notif_count ?></span><?php endif; ?>
    </a>
    <form method="POST" style="display:inline">
      <button type="submit" name="logout" class="hb lo" title="Sign Out"><i class="fas fa-sign-out-alt"></i></button>
    </form>
  </div>
  <img src="../../images/logo.png" alt="Logo" style="height:50px;width:auto;max-width:140px;object-fit:contain;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 4px 14px rgba(232,184,75,.3);margin-left:8px">
</header>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="slbl">Main Menu</div>
  <a href="client_portal.php" class="ni"><div class="ic"><i class="fas fa-home"></i></div> Dashboard</a>
  <a href="client_profile.php" class="ni act"><div class="ic"><i class="fas fa-user-circle"></i></div> My Profile</a>
  <a href="report_fault.php" class="ni"><div class="ic"><i class="fas fa-exclamation-triangle"></i></div> Report Fault <span class="nb nb-gold">+</span></a>
  <div class="slbl" style="margin-top:8px">Equipment</div>
  <a href="client_faults.php" class="ni"><div class="ic"><i class="fas fa-tools"></i></div> My Faults</a>
  <a href="client_repair_progress.php" class="ni"><div class="ic"><i class="fas fa-wrench"></i></div> Repair Progress</a>
  <a href="client_products.php" class="ni"><div class="ic"><i class="fas fa-box-open"></i></div> My Products</a>
  <div class="slbl" style="margin-top:8px">Finance</div>
  <a href="client_invoices.php" class="ni"><div class="ic"><i class="fas fa-receipt"></i></div> Invoices</a>
  <a href="client_invoices.php" class="ni"><div class="ic"><i class="fas fa-credit-card"></i></div> Make Payment</a>
  <div class="slbl" style="margin-top:8px">More</div>
  <button class="exp-btn" id="exp-btn" onclick="document.getElementById('sub-menu').classList.toggle('open');this.classList.toggle('open')">
    <div class="ic"><i class="fas fa-ellipsis-h"></i></div>More Options<i class="fas fa-chevron-down ch"></i>
  </button>
  <div class="sub-menu" id="sub-menu">
    <a class="si" href="client_notifications.php"><i class="fas fa-bell"></i> Notifications</a>
    <a class="si" href="client_documents.php"><i class="fas fa-folder"></i> Documents</a>
    <a class="si" href="client_reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a class="si" href="client_feedback.php"><i class="fas fa-star"></i> Leave Feedback</a>
    <a class="si" href="client_help.php"><i class="fas fa-life-ring"></i> Help &amp; Support</a>
    <a class="si" href="client_settings.php"><i class="fas fa-cog"></i> Settings</a>
  </div>
  <div class="s-banner">
    <i class="fas fa-headset"></i>
    <p>Need help? Mon–Fri 8AM–5PM.</p>
    <a href="mailto:support@busiquip.co.sz">Contact Support</a>
  </div>
</aside>

<div id="alerts"></div>

<!-- MAIN -->
<main>

<!-- PROFILE BANNER -->
<div class="profile-banner">
  <div class="avatar-xl" id="avLetter"><?= $c_initial ?></div>
  <div class="profile-info">
    <h1 id="bannerName"><?= htmlspecialchars($client_row['COMPANY_NAME'] ?? $client_name) ?></h1>
    <p id="bannerEmail"><?= htmlspecialchars($client_row['COMPANY_EMAIL'] ?? $client_email) ?></p>
    <div class="profile-chips">
      <span class="chip chip-gold" id="bannerType"><i class="fas fa-building"></i> <?= htmlspecialchars($client_row['CLIENT_TYPE'] ?? $client_type) ?></span>
      <span class="chip chip-em"><i class="fas fa-check-circle"></i> Account Active</span>
      <span class="chip chip-teal"><i class="fas fa-id-badge"></i> ID #<?= str_pad($client_id,4,'0',STR_PAD_LEFT) ?></span>
    </div>
  </div>
  <div class="profile-actions">
    <button class="btn btn-p" onclick="switchTab('info')"><i class="fas fa-edit"></i> Edit Profile</button>
    <button class="btn btn-s" onclick="switchTab('security')"><i class="fas fa-lock"></i> Change Password</button>
  </div>
</div>

<!-- STATS -->
<div class="stats-grid" id="statsGrid">
  <div class="stat-c"><div class="stat-ic ic-b"><i class="fas fa-list-alt"></i></div><div class="stat-n" id="sTotFaults">—</div><div class="stat-l">Total Faults</div></div>
  <div class="stat-c"><div class="stat-ic ic-g"><i class="fas fa-spinner"></i></div><div class="stat-n" id="sActFaults">—</div><div class="stat-l">Active Faults</div></div>
  <div class="stat-c"><div class="stat-ic ic-e"><i class="fas fa-check-double"></i></div><div class="stat-n" id="sClsFaults">—</div><div class="stat-l">Closed Faults</div></div>
  <div class="stat-c"><div class="stat-ic ic-t"><i class="fas fa-box-open"></i></div><div class="stat-n" id="sProds">—</div><div class="stat-l">Products</div></div>
  <div class="stat-c"><div class="stat-ic ic-i"><i class="fas fa-file-invoice-dollar"></i></div><div class="stat-n" id="sPendInv">—</div><div class="stat-l">Pending Invoices</div></div>
  <div class="stat-c"><div class="stat-ic ic-r"><i class="fas fa-coins"></i></div><div class="stat-n" id="sTotPaid" style="font-size:16px">—</div><div class="stat-l">Total Paid (E)</div></div>
</div>

<!-- TABS -->
<div class="tab-nav">
  <button class="tab-btn act" onclick="switchTab('info')"><i class="fas fa-building"></i> Company Info</button>
  <button class="tab-btn" onclick="switchTab('security')"><i class="fas fa-lock"></i> Security</button>
  <button class="tab-btn" onclick="switchTab('wallet')"><i class="fas fa-wallet"></i> Wallet</button>
  <button class="tab-btn" onclick="switchTab('activity')"><i class="fas fa-clock"></i> Recent Activity</button>
  <button class="tab-btn" onclick="switchTab('notifications');loadNotifs()"><i class="fas fa-bell"></i> Notifications <?php if($notif_count>0): ?><span style="background:var(--dan);color:#fff;font-size:9px;padding:1px 6px;border-radius:99px;font-weight:700"><?=$notif_count?></span><?php endif; ?></button>
</div>

<!-- TAB: COMPANY INFO -->
<div class="tab-panel act" id="tab-info">
  <div class="card">
    <div class="card-title"><i class="fas fa-building"></i> Company Information</div>
    <div class="form-row">
      <div class="fg"><label>Company Name</label><input id="companyName" readonly></div>
      <div class="fg"><label>Client Type</label><input id="clientType" readonly></div>
    </div>
    <div class="form-row">
      <div class="fg"><label>Contact Person *</label><input id="contactPerson" placeholder="Full name of contact person"></div>
      <div class="fg"><label>Company Phone *</label><input id="companyPhone" placeholder="+268 XXXX XXXX"></div>
    </div>
    <div class="form-row">
      <div class="fg"><label>Company Email *</label><input type="email" id="companyEmail" placeholder="company@email.com"></div>
      <div class="fg"><label>Physical Address *</label><input id="companyAddress" placeholder="Street, City, Country"></div>
    </div>
    <div class="fg"><label>Username</label><input id="username" readonly></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-p" onclick="saveProfile()"><i class="fas fa-save"></i> Save Changes</button>
      <button class="btn btn-s" onclick="loadProfile()"><i class="fas fa-undo"></i> Reset</button>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><i class="fas fa-chart-bar"></i> Account Overview</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px" id="overviewGrid">
      <div style="padding:14px;background:var(--bg3);border:1px solid var(--bor);border-radius:12px;text-align:center">
        <div style="font-size:11px;color:var(--t2);margin-bottom:4px">Client Since</div>
        <div style="font-family:var(--fm);font-size:14px;color:var(--gold)">Active Account</div>
      </div>
      <div style="padding:14px;background:var(--bg3);border:1px solid var(--bor);border-radius:12px;text-align:center">
        <div style="font-size:11px;color:var(--t2);margin-bottom:4px">Account Type</div>
        <div style="font-family:var(--fm);font-size:14px;color:var(--teal)" id="ovType">—</div>
      </div>
      <div style="padding:14px;background:var(--bg3);border:1px solid var(--bor);border-radius:12px;text-align:center">
        <div style="font-size:11px;color:var(--t2);margin-bottom:4px">Portal Status</div>
        <div style="font-family:var(--fm);font-size:14px;color:var(--em)">✓ Online</div>
      </div>
    </div>
  </div>
</div>

<!-- TAB: SECURITY -->
<div class="tab-panel" id="tab-security">
  <div class="card">
    <div class="card-title"><i class="fas fa-key"></i> Change Password</div>
    <p style="color:var(--t2);font-size:13px;margin-bottom:18px">Choose a strong password with at least 6 characters, combining letters, numbers and symbols.</p>
    <div class="fg"><label>Current Password *</label><input type="password" id="curPw" placeholder="Enter your current password"></div>
    <div class="form-row">
      <div class="fg"><label>New Password *</label><input type="password" id="newPw" placeholder="Minimum 6 characters" oninput="checkStrength()"></div>
      <div class="fg"><label>Confirm New Password *</label><input type="password" id="conPw" placeholder="Repeat new password"></div>
    </div>
    <!-- Password strength -->
    <div style="margin-bottom:16px">
      <div style="font-size:11px;color:var(--t2);margin-bottom:6px">Password Strength: <span id="pwStrengthLabel" style="font-weight:700">—</span></div>
      <div style="height:5px;background:var(--bg3);border-radius:99px;overflow:hidden">
        <div id="pwStrengthBar" style="height:100%;border-radius:99px;width:0;transition:width .4s,background .4s"></div>
      </div>
    </div>
    <button class="btn btn-p" onclick="changePassword()"><i class="fas fa-lock"></i> Update Password</button>
  </div>

  <div class="card">
    <div class="card-title"><i class="fas fa-shield-alt"></i> Security Status</div>
    <div class="sec-item"><i class="fas fa-check-circle" style="color:var(--em)"></i><div><h4>Session Active</h4><p>You are currently logged in securely</p></div></div>
    <div class="sec-item"><i class="fas fa-lock" style="color:var(--gold)"></i><div><h4>Password Encrypted</h4><p>Your password is hashed using bcrypt — industry standard</p></div></div>
    <div class="sec-item"><i class="fas fa-user-shield" style="color:var(--sky)"></i><div><h4>Role-Based Access</h4><p>Your portal shows only data relevant to your account</p></div></div>
    <div class="sec-item"><i class="fas fa-history" style="color:var(--teal)"></i><div><h4>Session Management</h4><p>Sessions expire automatically for security</p></div></div>
  </div>
</div>

<!-- TAB: WALLET -->
<div class="tab-panel" id="tab-wallet">
  <div class="card">
    <div class="wallet-banner">
      <div>
        <div style="font-size:11px;color:var(--t3);letter-spacing:.12em;text-transform:uppercase;font-family:var(--fm);margin-bottom:6px"><i class="fas fa-wallet" style="color:var(--gold)"></i> &nbsp;Available Balance</div>
        <div class="wallet-amt" id="walBal">E 0.00</div>
        <div class="wallet-lbl">Busiquip Account Wallet — Eswatini Lilangeni (E)</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end">
        <a href="client_invoices.php" class="btn btn-p"><i class="fas fa-credit-card"></i> Pay Invoice</a>
        <a href="client_invoices.php" class="btn btn-s"><i class="fas fa-history"></i> View Transactions</a>
      </div>
    </div>
    <div style="padding:18px;background:var(--bg3);border:1px solid var(--bor);border-radius:14px;margin-bottom:16px">
      <div class="card-title" style="margin-bottom:12px"><i class="fas fa-info-circle"></i> How to Top Up Your Wallet</div>
      <p style="font-size:13px;color:var(--t2);line-height:1.8">To add funds to your wallet, please contact the Busiquip Eswatini accounts team with your payment proof. Transfers are processed within 1 business day.</p>
      <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
        <div><div style="color:var(--t3);font-size:11px;margin-bottom:3px">BANK NAME</div><div style="color:var(--t1);font-weight:600">Standard Bank Eswatini</div></div>
        <div><div style="color:var(--t3);font-size:11px;margin-bottom:3px">ACCOUNT NUMBER</div><div style="color:var(--gold);font-family:var(--fm);font-weight:600">9870034567</div></div>
        <div><div style="color:var(--t3);font-size:11px;margin-bottom:3px">ACCOUNT NAME</div><div style="color:var(--t1);font-weight:600">Busiquip (Pty) Ltd</div></div>
        <div><div style="color:var(--t3);font-size:11px;margin-bottom:3px">REFERENCE</div><div style="color:var(--teal);font-family:var(--fm);font-weight:600">CLIENT-<?= str_pad($client_id,4,'0',STR_PAD_LEFT) ?></div></div>
      </div>
    </div>
    <div style="display:flex;gap:12px;align-items:center;padding:12px 16px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:10px;font-size:13px;color:var(--em)">
      <i class="fas fa-envelope" style="font-size:18px;flex-shrink:0"></i>
      <span>After payment, email your proof to <strong>accounts@busiquip.co.sz</strong> with your Client ID in the subject line.</span>
    </div>
  </div>
</div>

<!-- TAB: ACTIVITY -->
<div class="tab-panel" id="tab-activity">
  <div class="card">
    <div class="card-title" style="justify-content:space-between"><span><i class="fas fa-clock"></i> Recent Fault Activity</span><a href="client_faults.php" class="btn btn-s" style="padding:6px 14px;font-size:12px"><i class="fas fa-arrow-right"></i> View All</a></div>
    <div id="activityList"><div class="spin-lg"></div></div>
  </div>
</div>

<!-- TAB: NOTIFICATIONS -->
<div class="tab-panel" id="tab-notifications">
  <div class="card">
    <div class="card-title" style="justify-content:space-between">
      <span><i class="fas fa-bell"></i> Notifications</span>
      <button class="btn btn-s" style="padding:6px 14px;font-size:12px" onclick="markAllRead()"><i class="fas fa-check-double"></i> Mark All Read</button>
    </div>
    <div id="notifList"><div class="spin-lg"></div></div>
  </div>
</div>

</main>

<script>
function h(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function fd(s){return s?new Date(s).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'N/A';}
function alert2(t,msg){
  const el=document.createElement('div');
  el.className='al al-'+t;
  el.innerHTML=`<i class="fas fa-${t==='s'?'check-circle':t==='e'?'exclamation-circle':'info-circle'}"></i>${msg}`;
  document.getElementById('alerts').appendChild(el);
  setTimeout(()=>{el.style.opacity='0';el.style.transition='opacity .4s';setTimeout(()=>el.remove(),400)},5000);
}

function switchTab(name){
  document.querySelectorAll('.tab-btn').forEach((b,i)=>{
    b.classList.toggle('act',['info','security','wallet','activity','notifications'][i]===name);
  });
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('act'));
  document.getElementById('tab-'+name).classList.add('act');
  if(name==='activity') loadActivity();
}

async function loadProfile(){
  const res=await fetch('client_profile.php?action=get_profile');
  const d=await res.json();
  if(!d.CLIENT_ID) return;
  document.getElementById('companyName').value   = d.COMPANY_NAME||'';
  document.getElementById('clientType').value    = d.CLIENT_TYPE||'';
  document.getElementById('contactPerson').value = d.CONTACT_PERSON_NAME||'';
  document.getElementById('companyPhone').value  = d.COMPANY_PHONE||'';
  document.getElementById('companyEmail').value  = d.COMPANY_EMAIL||'';
  document.getElementById('companyAddress').value= d.COMPANY_ADDRESS||'';
  document.getElementById('username').value      = d.USERNAME||'';
  document.getElementById('bannerName').textContent  = d.COMPANY_NAME||'—';
  document.getElementById('bannerEmail').textContent = d.COMPANY_EMAIL||'—';
  document.getElementById('bannerType').innerHTML    = `<i class="fas fa-building"></i> ${h(d.CLIENT_TYPE||'—')}`;
  document.getElementById('ovType').textContent      = d.CLIENT_TYPE||'—';
  document.getElementById('walBal').textContent      = 'E '+parseFloat(d.WALLET_BALANCE||0).toLocaleString('en',{minimumFractionDigits:2});
}

async function loadStats(){
  const res=await fetch('client_profile.php?action=get_stats');
  const d=await res.json();
  document.getElementById('sTotFaults').textContent = d.total_faults||0;
  document.getElementById('sActFaults').textContent = d.active_faults||0;
  document.getElementById('sClsFaults').textContent = d.closed_faults||0;
  document.getElementById('sProds').textContent     = d.products||0;
  document.getElementById('sPendInv').textContent   = d.pending_invoices||0;
  document.getElementById('sTotPaid').textContent   = 'E '+parseFloat(d.total_paid||0).toLocaleString('en',{minimumFractionDigits:0});
}

async function saveProfile(){
  const payload={
    phone:   document.getElementById('companyPhone').value,
    email:   document.getElementById('companyEmail').value,
    address: document.getElementById('companyAddress').value,
    contact: document.getElementById('contactPerson').value,
  };
  if(!payload.contact||!payload.email){alert2('e','Contact person and email are required.');return;}
  const res=await fetch('client_profile.php?action=update_profile',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await res.json();
  if(d.success){alert2('s',d.message);loadProfile();}
  else alert2('e',d.error||'Update failed.');
}

async function changePassword(){
  const payload={current_password:document.getElementById('curPw').value,new_password:document.getElementById('newPw').value,confirm_password:document.getElementById('conPw').value};
  if(!payload.current_password||!payload.new_password){alert2('e','All password fields are required.');return;}
  const res=await fetch('client_profile.php?action=change_password',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await res.json();
  if(d.success){
    alert2('s',d.message);
    document.getElementById('curPw').value='';
    document.getElementById('newPw').value='';
    document.getElementById('conPw').value='';
    document.getElementById('pwStrengthBar').style.width='0';
    document.getElementById('pwStrengthLabel').textContent='—';
  } else alert2('e',d.error||'Error');
}

function checkStrength(){
  const pw=document.getElementById('newPw').value;
  let score=0;
  if(pw.length>=6) score++;
  if(pw.length>=10) score++;
  if(/[A-Z]/.test(pw)) score++;
  if(/[0-9]/.test(pw)) score++;
  if(/[^A-Za-z0-9]/.test(pw)) score++;
  const labels=['','Weak','Fair','Good','Strong','Very Strong'];
  const colors=['','var(--dan)','var(--warn)','var(--gold)','var(--em)','var(--teal)'];
  const bar=document.getElementById('pwStrengthBar');
  const lbl=document.getElementById('pwStrengthLabel');
  bar.style.width=(score*20)+'%';
  bar.style.background=colors[score]||colors[1];
  lbl.textContent=labels[score]||'—';
  lbl.style.color=colors[score]||colors[1];
}

async function loadActivity(){
  const el=document.getElementById('activityList');
  el.innerHTML='<div class="spin-lg"></div>';
  const res=await fetch('client_profile.php?action=get_activity');
  const data=await res.json();
  if(!data.length){el.innerHTML='<div style="text-align:center;padding:24px;color:var(--t2)"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:8px;opacity:.4"></i>No activity yet.</div>';return;}
  const smap={Pending:'b-p',Assigned:'b-a','In Progress':'b-i',Completed:'b-r','Client Approved':'b-r',Closed:'b-r','Rework Required':'b-d'};
  el.innerHTML=data.map(f=>{
    const title=f.DESCRIPTION?.match(/FAULT TITLE:\s*(.+)/i)?.[1]||f.DESCRIPTION?.substring(0,60)||'No description';
    return `<div class="act-item">
      <div class="act-ic"><i class="fas fa-tools" style="color:var(--gold)"></i></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(title)}</div>
        <div style="font-size:11px;color:var(--t2);margin-top:2px"><i class="fas fa-calendar"></i> ${fd(f.REPORT_DATE)} &nbsp;·&nbsp; Priority: ${h(f.PRIORITY||'N/A')}</div>
      </div>
      <span class="b ${smap[f.STATUS]||'b-p'}">${h(f.STATUS)}</span>
    </div>`;
  }).join('');
}

async function loadNotifs(){
  const el=document.getElementById('notifList');
  el.innerHTML='<div class="spin-lg"></div>';
  const res=await fetch('client_profile.php?action=get_notifications');
  const data=await res.json();
  if(!data.length){el.innerHTML='<div style="text-align:center;padding:24px;color:var(--t2)"><i class="fas fa-bell-slash" style="font-size:32px;display:block;margin-bottom:8px;opacity:.4"></i>No notifications.</div>';return;}
  el.innerHTML=data.map(n=>`
    <div class="notif-item ${n.is_read=='0'?'unread':''}" onclick="markRead(${n.id},this)">
      <div class="notif-ic"><i class="fas fa-bell"></i></div>
      <div style="flex:1">
        <div class="notif-title">${h(n.title||'Notification')}</div>
        <div class="notif-msg">${h(n.message||'')}</div>
        <div class="notif-time"><i class="fas fa-clock"></i> ${new Date(n.created_at).toLocaleString()}</div>
      </div>
      ${n.is_read=='0'?'<span style="width:9px;height:9px;border-radius:50%;background:var(--gold);flex-shrink:0;margin-top:4px"></span>':''}
    </div>`).join('');
}

async function markRead(id,el){
  await fetch('client_profile.php?action=mark_read&nid='+id);
  el.classList.remove('unread');
  const dot=el.querySelector('span[style*="border-radius:50%"]');
  if(dot) dot.remove();
}

async function markAllRead(){
  await fetch('client_profile.php?action=mark_read');
  document.querySelectorAll('.notif-item.unread').forEach(el=>{
    el.classList.remove('unread');
    const dot=el.querySelector('span[style*="border-radius:50%"]');
    if(dot) dot.remove();
  });
  alert2('s','All notifications marked as read.');
}

// Init
loadProfile();
loadStats();
</script>
</body>
</html>
