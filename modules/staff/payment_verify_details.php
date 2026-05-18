<?php
$page_title    = 'Payment Verification';
$page_subtitle = 'Review & Verify Payment';
require_once __DIR__ . '/../../includes/acc_header.php';

$payment_id = (int)($_GET['payment_id'] ?? 0);

if (!$payment_id) {
    header('Location: payment_verification.php');
    exit();
}

// ── Fetch payment + invoice + client ──────────────────────────────────────────
$res = mysqli_query($conn, "
    SELECT p.*,
           i.INVOICE_ID, i.TOTAL, i.STATUS as INV_STATUS, i.INVOICE_DATE,
           i.DUE_DATE, i.TYPE as INV_TYPE, i.PAID_AMOUNT, i.ASSIGN_ID,
           c.COMPANY_NAME, c.COMPANY_EMAIL, c.COMPANY_PHONE, c.COMPANY_ADDRESS,
           c.CONTACT_PERSON_NAME, c.CLIENT_ID,
           rf.REP_FAULT_ID, rf.DESCRIPTION as FAULT_DESC, rf.STATUS as FAULT_STATUS
    FROM payment p
    INNER JOIN invoice i          ON p.INVOICE_ID    = i.INVOICE_ID
    LEFT  JOIN client c           ON i.CLIENT_ID     = c.CLIENT_ID
    LEFT  JOIN assignment a       ON i.ASSIGN_ID     = a.ASSIGN_ID
    LEFT  JOIN reported_fault rf  ON a.REP_FAULT_ID  = rf.REP_FAULT_ID
    WHERE p.PAYMENT_ID = $payment_id
");

if (mysqli_num_rows($res) === 0) {
    echo '<div class="alert alert-danger"><i class="ti ti-alert-circle"></i> Payment not found.</div>';
    require_once '../../includes/acc_footer.php';
    exit();
}

$pay = mysqli_fetch_assoc($res);

// ── Fetch invoice line items ───────────────────────────────────────────────────
$lines_res = mysqli_query($conn, "SELECT * FROM invoice_line WHERE INVOICE_ID = {$pay['INVOICE_ID']} ORDER BY LINE_ID");

// ── Fetch invoice audit trail ──────────────────────────────────────────────────
$audit_res = mysqli_query($conn, "
    SELECT it.*, e.FULL_NAME
    FROM invoice_tracking it
    LEFT JOIN employee e ON it.performed_by_id = e.EMP_ID
    WHERE it.invoice_id = {$pay['INVOICE_ID']}
    ORDER BY it.created_at DESC
");

// ── Fetch all payments on same invoice ────────────────────────────────────────
$prev_payments = mysqli_query($conn, "SELECT * FROM payment WHERE INVOICE_ID = {$pay['INVOICE_ID']} ORDER BY PAYMENT_DATE DESC");

// ── Handle VERIFY / REJECT ────────────────────────────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        if ($pay['STATUS'] !== 'Pending') {
            $error_msg = 'This payment has already been ' . $pay['STATUS'] . '.';
        } else {
            $notes     = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
            $amount    = (float)$pay['AMOUNT_PAID'];
            $inv_id    = (int)$pay['INVOICE_ID'];
            $client_id = (int)$pay['CLIENT_ID'];
            $fault_id  = (int)$pay['REP_FAULT_ID'];
            $inv_num_f = 'INV-' . str_pad($inv_id, 4, '0', STR_PAD_LEFT);

            // 1. Mark payment Verified
            mysqli_query($conn, "UPDATE payment SET STATUS='Verified' WHERE PAYMENT_ID=$payment_id");

            // 2. Update invoice paid amount + status
            $new_paid   = (float)$pay['PAID_AMOUNT'] + $amount;
            $inv_status = ($new_paid >= (float)$pay['TOTAL']) ? 'Paid' : 'Pending Payment';
            mysqli_query($conn, "UPDATE invoice SET PAID_AMOUNT=$new_paid, STATUS='$inv_status' WHERE INVOICE_ID=$inv_id");

            // 3. REAL COMPANY BALANCE UPDATE — saves to database immediately
            mysqli_query($conn, "UPDATE company_settings SET company_balance = company_balance + $amount WHERE id = 1");

            // 4. Close fault if invoice fully paid
            if ($inv_status === 'Paid' && $fault_id) {
                mysqli_query($conn, "UPDATE reported_fault SET STATUS='Closed' WHERE REP_FAULT_ID=$fault_id");
            }

            // 5. Invoice audit trail
            $audit_desc = mysqli_real_escape_string($conn,
                'Payment #' . $payment_id . ' of E' . number_format($amount, 2) .
                ' verified by accountant. Invoice status: ' . $inv_status .
                ($notes ? '. Notes: ' . $notes : '')
            );
            mysqli_query($conn, "INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
                VALUES ($inv_id, 'Payment Verified', '$audit_desc', $acc_id, 'Accountant')");

            // 6. Payment tracking log
            mysqli_query($conn, "INSERT INTO payment_tracking (payment_id, action, old_status, new_status, notes)
                VALUES ($payment_id, 'Verified', 'Pending', 'Verified', '$notes')");

            // 7. Generate receipt + notify client
            if ($inv_status === 'Paid') {
                $receipt_json = mysqli_real_escape_string($conn, json_encode([
                    'payment_id'  => $payment_id,
                    'invoice_id'  => $inv_id,
                    'company'     => $pay['COMPANY_NAME'],
                    'amount'      => $amount,
                    'method'      => $pay['METHOD'],
                    'reference'   => $pay['REFERENCE_NUMBER'],
                    'verified_by' => $acc_name,
                    'verified_at' => date('Y-m-d H:i:s'),
                ]));
                mysqli_query($conn, "INSERT INTO receipt (PAYMENT_ID, RECEIPT_DATE, RECEIPT_DATA)
                    VALUES ($payment_id, CURDATE(), '$receipt_json')");

                $ntitle = mysqli_real_escape_string($conn, 'Payment Confirmed - ' . $inv_num_f);
                $nmsg   = mysqli_real_escape_string($conn,
                    'Your payment of E' . number_format($amount, 2) .
                    ' for invoice ' . $inv_num_f . ' has been verified and confirmed. Your receipt has been generated.' .
                    ($fault_id ? ' Fault #' . $fault_id . ' is now Closed.' : '')
                );
                mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ($client_id, 'Client', '$ntitle', '$nmsg')");

                $success_msg = 'Payment verified! Invoice ' . $inv_num_f . ' is now fully PAID. +E' . number_format($amount, 2) . ' added to company balance. Receipt generated.';
            } else {
                $outstanding = max(0, (float)$pay['TOTAL'] - $new_paid);
                $ntitle2 = mysqli_real_escape_string($conn, 'Partial Payment Confirmed - ' . $inv_num_f);
                $nmsg2   = mysqli_real_escape_string($conn,
                    'Your payment of E' . number_format($amount, 2) .
                    ' has been verified. Outstanding balance: E' . number_format($outstanding, 2) . '.'
                );
                mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ($client_id, 'Client', '$ntitle2', '$nmsg2')");

                $success_msg = 'Partial payment verified. +E' . number_format($amount, 2) . ' added to company balance. Outstanding: E' . number_format($outstanding, 2);
            }

            $pay['STATUS'] = 'Verified';
        }

    } elseif ($action === 'reject') {
        if ($pay['STATUS'] !== 'Pending') {
            $error_msg = 'This payment has already been ' . $pay['STATUS'] . '.';
        } else {
            $reason    = mysqli_real_escape_string($conn, $_POST['reject_reason'] ?? 'Payment rejected by accountant.');
            $inv_id    = (int)$pay['INVOICE_ID'];
            $client_id = (int)$pay['CLIENT_ID'];
            $inv_num_f = 'INV-' . str_pad($inv_id, 4, '0', STR_PAD_LEFT);

            mysqli_query($conn, "UPDATE payment SET STATUS='Rejected' WHERE PAYMENT_ID=$payment_id");

            mysqli_query($conn, "INSERT INTO invoice_tracking (invoice_id, action, description, performed_by_id, performed_by_role)
                VALUES ($inv_id, 'Payment Rejected', 'Payment #$payment_id rejected. Reason: $reason', $acc_id, 'Accountant')");

            mysqli_query($conn, "INSERT INTO payment_tracking (payment_id, action, old_status, new_status, notes)
                VALUES ($payment_id, 'Rejected', 'Pending', 'Rejected', '$reason')");

            $rtitle = mysqli_real_escape_string($conn, 'Payment Rejected - ' . $inv_num_f);
            $rmsg   = mysqli_real_escape_string($conn,
                'Your payment submission was rejected. Reason: ' . $_POST['reject_reason'] .
                '. Please resubmit with correct proof of payment.'
            );
            mysqli_query($conn, "INSERT INTO notifications (user_id, user_type, title, message) VALUES ($client_id, 'Client', '$rtitle', '$rmsg')");

            $pay['STATUS'] = 'Rejected';
            $error_msg = 'Payment rejected. Client has been notified.';
        }
    }
}

// ── Reload live company balance after any action ───────────────────────────────
$bal_res = mysqli_query($conn, "SELECT company_balance FROM company_settings WHERE id=1 LIMIT 1");
$live_balance = (float)(mysqli_fetch_assoc($bal_res)['company_balance'] ?? 0);

function payStatusBadge($s) {
    return ['Pending'=>'badge-pending','Verified'=>'badge-paid','Rejected'=>'badge-overdue'][$s] ?? 'badge-cancelled';
}

$inv_num        = 'INV-' . str_pad($pay['INVOICE_ID'], 4, '0', STR_PAD_LEFT);
$is_pending     = ($pay['STATUS'] === 'Pending');
$balance_after  = max(0, (float)$pay['TOTAL'] - ((float)$pay['PAID_AMOUNT'] + (float)$pay['AMOUNT_PAID']));
?>

<style>
.detail-row{display:flex;justify-content:space-between;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--border)}
.detail-row:last-child{border-bottom:none}
.detail-row .dk{font-size:.8rem;color:var(--text2);font-weight:500}
.detail-row .dv{font-size:.87rem;color:var(--text);font-weight:600;text-align:right;max-width:60%}
.amount-hero{text-align:center;padding:1.5rem;background:rgba(240,165,0,.07);border-radius:12px;border:1px solid rgba(240,165,0,.2);margin-bottom:1.25rem}
.amount-hero .lbl{font-size:.78rem;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.amount-hero .amt{font-size:2.8rem;font-weight:800;color:var(--accent);line-height:1.1}
.timeline-dot{width:10px;height:10px;border-radius:50%;background:var(--accent);margin-top:.25rem;flex-shrink:0}
.action-panel{background:var(--surface);border:2px solid var(--border);border-radius:12px;padding:1.25rem}
.action-panel.vp{border-color:rgba(63,185,80,.3)}
.action-panel.rp{border-color:rgba(248,81,73,.3)}
.bal-box{background:linear-gradient(135deg,#0d1f0d,#131d13);border:1px solid rgba(63,185,80,.3);border-radius:12px;padding:1rem 1.25rem}
</style>

<?php if ($success_msg): ?>
<div class="alert alert-success"><i class="ti ti-circle-check"></i> <?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-<?= ($pay['STATUS'] === 'Rejected') ? 'warning' : 'danger' ?>"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1><i class="ti ti-circle-check" style="color:var(--accent)"></i> Payment #<?= $payment_id ?></h1>
        <p><?= htmlspecialchars($pay['COMPANY_NAME'] ?? 'Unknown') ?> &mdash; <?= $inv_num ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="payment_verification.php" class="btn btn-secondary"><i class="ti ti-arrow-left"></i> Back</a>
        <a href="acc_invoice_details.php?invoice_id=<?= $pay['INVOICE_ID'] ?>" class="btn btn-info"><i class="ti ti-receipt"></i> View Invoice</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:flex-start">

<!-- ───── LEFT ───── -->
<div style="display:flex;flex-direction:column;gap:1.25rem">

    <div class="amount-hero">
        <div class="lbl">Payment Submitted</div>
        <div class="amt">E<?= number_format($pay['AMOUNT_PAID'], 2) ?></div>
        <div style="font-size:.85rem;color:var(--text2);margin-top:.35rem">
            via <strong><?= htmlspecialchars($pay['METHOD']) ?></strong>
            &nbsp;|&nbsp; <?= date('d M Y', strtotime($pay['PAYMENT_DATE'])) ?>
        </div>
        <div style="margin-top:.75rem">
            <span class="badge <?= payStatusBadge($pay['STATUS']) ?>" style="font-size:.85rem;padding:.35rem .9rem"><?= $pay['STATUS'] ?></span>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="ti ti-credit-card" style="color:var(--accent)"></i> Payment Details</div>
        <div class="detail-row"><span class="dk">Payment ID</span><span class="dv">#<?= $payment_id ?></span></div>
        <div class="detail-row"><span class="dk">Amount Paid</span><span class="dv" style="color:var(--success)">E<?= number_format($pay['AMOUNT_PAID'], 2) ?></span></div>
        <div class="detail-row"><span class="dk">Method</span><span class="dv"><?= htmlspecialchars($pay['METHOD']) ?></span></div>
        <div class="detail-row"><span class="dk">Reference</span><span class="dv"><?= $pay['REFERENCE_NUMBER'] ? htmlspecialchars($pay['REFERENCE_NUMBER']) : '<em style="opacity:.5">Not provided</em>' ?></span></div>
        <div class="detail-row"><span class="dk">Payment Date</span><span class="dv"><?= date('d M Y', strtotime($pay['PAYMENT_DATE'])) ?></span></div>
        <div class="detail-row"><span class="dk">Status</span><span class="dv"><span class="badge <?= payStatusBadge($pay['STATUS']) ?>"><?= $pay['STATUS'] ?></span></span></div>
    </div>

    <div class="card">
        <div class="card-title"><i class="ti ti-receipt" style="color:var(--accent)"></i> Invoice – <?= $inv_num ?></div>
        <div class="detail-row"><span class="dk">Invoice Number</span><span class="dv"><?= $inv_num ?></span></div>
        <div class="detail-row"><span class="dk">Invoice Date</span><span class="dv"><?= date('d M Y', strtotime($pay['INVOICE_DATE'])) ?></span></div>
        <div class="detail-row">
            <span class="dk">Due Date</span>
            <span class="dv" style="<?= ($pay['DUE_DATE'] < date('Y-m-d') && $pay['INV_STATUS'] !== 'Paid') ? 'color:var(--danger)' : '' ?>">
                <?= date('d M Y', strtotime($pay['DUE_DATE'])) ?>
                <?= ($pay['DUE_DATE'] < date('Y-m-d') && $pay['INV_STATUS'] !== 'Paid') ? ' &#9888; Overdue' : '' ?>
            </span>
        </div>
        <div class="detail-row"><span class="dk">Invoice Total</span><span class="dv">E<?= number_format($pay['TOTAL'], 2) ?></span></div>
        <div class="detail-row"><span class="dk">Previously Paid</span><span class="dv">E<?= number_format($pay['PAID_AMOUNT'], 2) ?></span></div>
        <div class="detail-row"><span class="dk">This Payment</span><span class="dv" style="color:var(--success)">E<?= number_format($pay['AMOUNT_PAID'], 2) ?></span></div>
        <div class="detail-row" style="border-top:2px solid var(--border)">
            <span class="dk" style="font-weight:700;color:var(--text)">Balance After Verification</span>
            <span class="dv" style="font-size:1rem;color:<?= $balance_after <= 0 ? 'var(--success)' : 'var(--warning)' ?>">
                <?= $balance_after <= 0 ? 'FULLY PAID' : 'E' . number_format($balance_after, 2) ?>
            </span>
        </div>
        <div class="detail-row"><span class="dk">Invoice Status</span><span class="dv"><span class="badge badge-<?= strtolower(str_replace(' ', '-', $pay['INV_STATUS'])) ?>"><?= $pay['INV_STATUS'] ?></span></span></div>
        <?php if ($pay['REP_FAULT_ID']): ?>
        <div class="detail-row"><span class="dk">Fault Reference</span><span class="dv">Fault #<?= $pay['REP_FAULT_ID'] ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (mysqli_num_rows($lines_res) > 0): ?>
    <div class="card">
        <div class="card-title"><i class="ti ti-list" style="color:var(--accent)"></i> Invoice Line Items</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Description</th><th style="text-align:center">Qty</th><th style="text-align:right">Unit Price</th><th style="text-align:right">Total</th></tr>
                </thead>
                <tbody>
                <?php $grand = 0; while ($ln = mysqli_fetch_assoc($lines_res)): $grand += (float)$ln['LINE_TOTAL']; ?>
                <tr>
                    <td><?= htmlspecialchars($ln['DESCRIPTION']) ?></td>
                    <td style="text-align:center"><?= $ln['QUANTITY'] ?></td>
                    <td style="text-align:right">E<?= number_format($ln['UNIT_PRICE'], 2) ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--accent)">E<?= number_format($ln['LINE_TOTAL'], 2) ?></td>
                </tr>
                <?php endwhile; ?>
                <tr>
                    <td colspan="3" style="text-align:right;font-weight:700">TOTAL</td>
                    <td style="text-align:right;font-weight:800;font-size:1rem;color:var(--accent)">E<?= number_format($grand, 2) ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (mysqli_num_rows($audit_res) > 0): ?>
    <div class="card">
        <div class="card-title"><i class="ti ti-timeline" style="color:var(--accent)"></i> Invoice History</div>
        <?php while ($at = mysqli_fetch_assoc($audit_res)): ?>
        <div style="display:flex;gap:.75rem;padding:.6rem 0;border-bottom:1px solid var(--border)">
            <div class="timeline-dot" style="margin-top:.35rem"></div>
            <div>
                <div style="font-size:.83rem;font-weight:600;color:var(--text)"><?= htmlspecialchars($at['action']) ?></div>
                <div style="font-size:.75rem;color:var(--text2);margin-top:.1rem"><?= htmlspecialchars($at['description']) ?></div>
                <div style="font-size:.7rem;color:var(--text2);margin-top:.1rem">
                    <?= date('d M Y H:i', strtotime($at['created_at'])) ?>
                    <?= $at['FULL_NAME'] ? '— ' . htmlspecialchars($at['FULL_NAME']) : '' ?>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

</div><!-- end left -->

<!-- ───── RIGHT ───── -->
<div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Live Company Balance -->
    <div class="bal-box">
        <div style="font-size:.7rem;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:.4rem">
            <i class="ti ti-building-bank"></i> Company Balance (Live)
        </div>
        <div style="font-size:1.7rem;font-weight:800;color:var(--success)">E<?= number_format($live_balance, 2) ?></div>
        <div style="font-size:.75rem;color:var(--text2);margin-top:.3rem">
            <?php if ($pay['STATUS'] === 'Pending'): ?>
                Verifying will add <strong style="color:var(--accent)">+E<?= number_format($pay['AMOUNT_PAID'], 2) ?></strong> to this balance
            <?php elseif ($pay['STATUS'] === 'Verified'): ?>
                <span style="color:var(--success)">&#10003; E<?= number_format($pay['AMOUNT_PAID'], 2) ?> already credited from this payment</span>
            <?php else: ?>
                Payment rejected &mdash; no balance change
            <?php endif; ?>
        </div>
    </div>

    <!-- Client Info -->
    <div class="card">
        <div class="card-title"><i class="ti ti-building" style="color:var(--accent)"></i> Client</div>
        <div style="text-align:center;padding:.5rem 0 .75rem">
            <div style="width:50px;height:50px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.3rem;color:#000;margin:0 auto .6rem">
                <?= strtoupper(substr($pay['COMPANY_NAME'] ?? 'C', 0, 1)) ?>
            </div>
            <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars($pay['COMPANY_NAME'] ?? 'Unknown') ?></div>
            <?php if ($pay['CONTACT_PERSON_NAME']): ?>
            <div style="font-size:.78rem;color:var(--text2)"><?= htmlspecialchars($pay['CONTACT_PERSON_NAME']) ?></div>
            <?php endif; ?>
        </div>
        <div class="detail-row"><span class="dk">Email</span><span class="dv" style="font-size:.78rem"><?= htmlspecialchars($pay['COMPANY_EMAIL'] ?? '—') ?></span></div>
        <div class="detail-row"><span class="dk">Phone</span><span class="dv"><?= htmlspecialchars($pay['COMPANY_PHONE'] ?? '—') ?></span></div>
        <div class="detail-row"><span class="dk">Address</span><span class="dv" style="font-size:.76rem"><?= htmlspecialchars($pay['COMPANY_ADDRESS'] ?? '—') ?></span></div>
    </div>

    <!-- Actions -->
    <?php if ($is_pending): ?>
    <div class="action-panel vp">
        <div style="font-size:.95rem;font-weight:700;color:var(--success);margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem">
            <i class="ti ti-shield-check"></i> Verify This Payment
        </div>
        <div style="font-size:.81rem;color:var(--text2);margin-bottom:1rem;line-height:1.55">
            Confirm <strong style="color:var(--text)">E<?= number_format($pay['AMOUNT_PAID'], 2) ?></strong>
            received via <strong style="color:var(--text)"><?= htmlspecialchars($pay['METHOD']) ?></strong>
            <?= $pay['REFERENCE_NUMBER'] ? '(ref: <strong style="color:var(--accent)">' . htmlspecialchars($pay['REFERENCE_NUMBER']) . '</strong>)' : '' ?>.
            <br><span style="color:var(--success)">&#10003; Saves +E<?= number_format($pay['AMOUNT_PAID'], 2) ?> to company balance in the database.</span>
        </div>
        <form method="POST" onsubmit="return confirm('Verify payment of E<?= number_format($pay['AMOUNT_PAID'], 2) ?>?\n\nThis will:\n  + Add E<?= number_format($pay['AMOUNT_PAID'], 2) ?> to company balance\n  + Mark payment Verified\n  + Notify the client\n\nCannot be undone.')">
            <input type="hidden" name="action" value="verify">
            <div class="form-group">
                <label>Verification Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Bank statement confirmed..."></textarea>
            </div>
            <button type="submit" class="btn btn-success" style="width:100%;justify-content:center;padding:.75rem;font-size:.95rem">
                <i class="ti ti-circle-check"></i> Confirm & Verify Payment
            </button>
        </form>
    </div>

    <div class="action-panel rp">
        <div style="font-size:.95rem;font-weight:700;color:var(--danger);margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem">
            <i class="ti ti-x"></i> Reject Payment
        </div>
        <form method="POST" onsubmit="return confirm('Reject this payment? Client will be notified.')">
            <input type="hidden" name="action" value="reject">
            <div class="form-group">
                <label>Rejection Reason <span style="color:var(--danger)">*</span></label>
                <textarea name="reject_reason" class="form-control" rows="3" required
                    placeholder="e.g. Amount mismatch, reference not found, payment not received..."></textarea>
            </div>
            <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center">
                <i class="ti ti-alert-circle"></i> Reject & Notify Client
            </button>
        </form>
    </div>

    <?php elseif ($pay['STATUS'] === 'Verified'): ?>
    <div class="action-panel vp" style="text-align:center;padding:1.5rem">
        <i class="ti ti-circle-check" style="font-size:2.5rem;color:var(--success);display:block;margin-bottom:.5rem"></i>
        <div style="font-weight:700;color:var(--success);font-size:1rem">Payment Verified</div>
        <div style="font-size:.8rem;color:var(--text2);margin-top:.35rem">
            E<?= number_format($pay['AMOUNT_PAID'], 2) ?> credited to company balance.<br>Client notified.
        </div>
        <?php
        $rcp = mysqli_query($conn, "SELECT RECEIPT_ID FROM receipt WHERE PAYMENT_ID=$payment_id LIMIT 1");
        if (mysqli_num_rows($rcp) > 0):
            $rcp_row = mysqli_fetch_assoc($rcp);
        ?>
        <a href="view_receipt.php?receipt_id=<?= $rcp_row['RECEIPT_ID'] ?>" class="btn btn-success btn-sm" style="margin-top:.85rem">
            <i class="ti ti-file-certificate"></i> View Receipt
        </a>
        <?php endif; ?>
    </div>

    <?php elseif ($pay['STATUS'] === 'Rejected'): ?>
    <div class="action-panel rp" style="text-align:center;padding:1.5rem">
        <i class="ti ti-x" style="font-size:2.5rem;color:var(--danger);display:block;margin-bottom:.5rem"></i>
        <div style="font-weight:700;color:var(--danger);font-size:1rem">Payment Rejected</div>
        <div style="font-size:.8rem;color:var(--text2);margin-top:.35rem">Client notified to resubmit with correct proof.</div>
    </div>
    <?php endif; ?>

    <!-- All Payments on Invoice -->
    <div class="card">
        <div class="card-title"><i class="ti ti-history" style="color:var(--accent)"></i> All Payments – <?= $inv_num ?></div>
        <?php mysqli_data_seek($prev_payments, 0); if (mysqli_num_rows($prev_payments) === 0): ?>
        <div class="empty-state" style="padding:1rem"><i class="ti ti-inbox"></i><p>No payments</p></div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.5rem">
        <?php while ($pp = mysqli_fetch_assoc($prev_payments)): ?>
        <a href="payment_verify_details.php?payment_id=<?= $pp['PAYMENT_ID'] ?>"
           style="display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem;background:var(--surface2);border-radius:8px;border:1px solid <?= ($pp['PAYMENT_ID'] == $payment_id) ? 'var(--accent)' : 'var(--border)' ?>;text-decoration:none">
            <div>
                <div style="font-size:.8rem;font-weight:600;color:var(--text)">#<?= $pp['PAYMENT_ID'] ?> — <?= htmlspecialchars($pp['METHOD']) ?></div>
                <div style="font-size:.72rem;color:var(--text2)"><?= date('d M Y', strtotime($pp['PAYMENT_DATE'])) ?></div>
            </div>
            <div style="text-align:right">
                <span class="badge <?= payStatusBadge($pp['STATUS']) ?>"><?= $pp['STATUS'] ?></span>
                <div style="font-size:.82rem;font-weight:600;color:var(--accent);margin-top:.2rem">E<?= number_format($pp['AMOUNT_PAID'], 2) ?></div>
            </div>
        </a>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- end right -->
</div>

<script>
<?php if ($success_msg): ?>showToast('<?= addslashes($success_msg) ?>', 'success');<?php endif; ?>
<?php if ($error_msg): ?>showToast('<?= addslashes($error_msg) ?>', '<?= ($pay['STATUS'] === 'Rejected') ? 'warning' : 'error' ?>');<?php endif; ?>
</script>

<?php require_once '../../includes/acc_footer.php'; ?>