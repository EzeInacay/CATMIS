<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

// ── Auth guard: students and teachers only ───────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['role'] === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// ── Fetch user info ──────────────────────────────────────────────
$uStmt = $conn->prepare("
    SELECT user_id, student_number, full_name, email, role, status, created_at
    FROM users WHERE user_id = ?
");
$uStmt->bind_param('i', $user_id);
$uStmt->execute();
$user = $uStmt->get_result()->fetch_assoc();

// ── Student-specific data ────────────────────────────────────────
$student     = null;
$account     = null;
$balance     = 0;
$ledger      = [];
$payments    = [];

if ($role === 'student') {

    // Get student + account info
    $sStmt = $conn->prepare("
        SELECT
            s.student_id, s.grade_level, s.section,
            sec.section_name,
            ta.account_id, ta.base_fee, ta.misc_fee,
            ta.discount, ta.penalties, ta.balance AS stored_balance,
            sy.name AS sy_name
        FROM students s
        LEFT JOIN sections sec      ON s.section_id    = sec.section_id
        LEFT JOIN tuition_accounts ta ON s.student_id  = ta.student_id
        LEFT JOIN school_years sy   ON ta.sy_id        = sy.sy_id
        WHERE s.user_id = ?
        ORDER BY ta.sy_id DESC
        LIMIT 1
    ");
    $sStmt->bind_param('i', $user_id);
    $sStmt->execute();
    $student = $sStmt->get_result()->fetch_assoc();

    if ($student && $student['account_id']) {
        $account_id = $student['account_id'];
        $balance    = getBalance($conn, $account_id);

        // Ledger
        $lStmt = $conn->prepare("
            SELECT
                sl.ledger_id, sl.entry_type, sl.amount, sl.remarks, sl.created_at,
                tf.label AS fee_label
            FROM student_ledgers sl
            LEFT JOIN tuition_fees tf ON sl.fee_id = tf.fee_id
            WHERE sl.account_id = ?
            ORDER BY sl.created_at ASC, sl.ledger_id ASC
        ");
        $lStmt->bind_param('i', $account_id);
        $lStmt->execute();
        $ledger = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Payment history
        $pStmt = $conn->prepare("
            SELECT p.payment_date, p.amount, p.method, p.or_number,
                   u.full_name AS processed_by
            FROM payments p
            JOIN users u ON p.posted_by = u.user_id
            WHERE p.account_id = ?
            ORDER BY p.payment_date DESC
        ");
        $pStmt->bind_param('i', $account_id);
        $pStmt->execute();
        $payments = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // ── Payment proofs submitted by this student ──────────────
        $ppStmt = $conn->prepare("
            SELECT proof_id, amount, method, reference, status, submitted_at, admin_note
            FROM payment_proofs
            WHERE student_id = ? AND account_id = ?
            ORDER BY submitted_at DESC
        ");
        $ppStmt->bind_param('ii', $student['student_id'], $account_id);
        $ppStmt->execute();
        $proofs = $ppStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Check if there's already a pending proof (limit 1 at a time)
        $hasPending = !empty(array_filter($proofs, fn($p) => $p['status'] === 'pending'));
    }
}

// ── Totals for ledger summary ────────────────────────────────────
$totalCharged  = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'CHARGE'),   'amount'));
$totalPaid     = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'PAYMENT'),  'amount'));
$totalDiscount = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'DISCOUNT'), 'amount'));
$totalPenalty  = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'PENALTY'),  'amount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Account | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #eef1f4; }

/* ===== NAVBAR ===== */
.navbar {
    position: fixed; top: 0; left: 0; right: 0; height: 60px;
    background: linear-gradient(90deg, #0f2027, #203a43);
    display: flex; align-items: center; padding: 0 24px;
    z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.navbar-brand { display: flex; align-items: baseline; gap: 10px; text-decoration: none; flex-shrink: 0; }
.navbar-brand h2 { margin: 0; color: #fff; font-size: 20px; letter-spacing: -0.5px; }
.navbar-brand span { font-size: 11px; color: rgba(255,255,255,0.45); letter-spacing: 1px; }
.navbar-right { display: flex; align-items: center; gap: 14px; margin-left: auto; }
.nav-name { font-size: 13px; color: rgba(255,255,255,0.7); }
.logout-btn {
    background: #ff3b30; border: none; color: white; padding: 7px 16px;
    border-radius: 6px; cursor: pointer; font-size: 13px;
    font-family: 'Segoe UI', Arial, sans-serif;
}
.logout-btn:hover { background: #d0302a; }

/* ===== MAIN ===== */
.main { margin-top: 60px; padding: 30px; max-width: 1000px; margin-left: auto; margin-right: auto; }

/* ===== PROFILE CARD ===== */
.profile-card {
    background: white; border-radius: 14px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    padding: 28px 32px; margin-bottom: 24px;
    display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
}
.avatar {
    width: 64px; height: 64px; border-radius: 50%;
    background: #e0f2fe; color: #0369a1;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 700; flex-shrink: 0;
}
.profile-info h2 { margin: 0 0 4px; font-size: 20px; color: #0f2027; }
.profile-info p  { margin: 0; font-size: 13px; color: #64748b; }
.profile-meta { margin-left: auto; display: flex; gap: 20px; flex-wrap: wrap; }
.meta-item { text-align: center; }
.meta-item .label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-item .value { font-size: 15px; font-weight: 600; color: #0f2027; margin-top: 2px; }

/* ===== SUMMARY CARDS ===== */
.summary-cards { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.sum-card {
    flex: 1; min-width: 150px; background: white; border-radius: 10px;
    padding: 18px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    border-left: 5px solid #0077b6;
}
.sum-card.red    { border-color: #dc2626; }
.sum-card.green  { border-color: #198754; }
.sum-card.amber  { border-color: #d97706; }
.sum-card.purple { border-color: #7c3aed; }
.sum-card h4 { margin: 0 0 6px; font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.sum-card p  { margin: 0; font-size: 22px; font-weight: 700; color: #0f2027; }

/* ===== BALANCE HERO ===== */
.balance-hero {
    background: linear-gradient(135deg, #0f2027, #203a43);
    border-radius: 14px; padding: 28px 32px;
    margin-bottom: 24px; color: white;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.balance-hero .label { font-size: 13px; color: rgba(255,255,255,0.5); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 6px; }
.balance-hero .amount { font-size: 40px; font-weight: 700; letter-spacing: -1px; }
.balance-hero .amount.paid { color: #6ee7b7; }
.balance-hero .amount.due  { color: #fca5a5; }
.status-pill {
    padding: 8px 20px; border-radius: 20px; font-size: 14px; font-weight: 600;
}
.status-pill.paid    { background: rgba(110,231,183,0.15); color: #6ee7b7; border: 1px solid rgba(110,231,183,0.3); }
.status-pill.pending { background: rgba(252,165,165,0.15); color: #fca5a5; border: 1px solid rgba(252,165,165,0.3); }

/* ===== SECTION HEADER ===== */
.section-header { font-size: 16px; font-weight: 700; color: #0f2027; margin: 28px 0 12px; }

/* ===== TABLE ===== */
.table-wrap { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 28px; }
table { width: 100%; border-collapse: collapse; }
th {
    background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600;
    color: #64748b; padding: 11px 16px; border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase; letter-spacing: 0.5px;
}
td { padding: 11px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }
.amount-col { text-align: right; font-variant-numeric: tabular-nums; }

.entry-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.entry-CHARGE     { background: #e0f2fe; color: #0369a1; }
.entry-PAYMENT    { background: #d1fae5; color: #065f46; }
.entry-DISCOUNT   { background: #f3e8ff; color: #6b21a8; }
.entry-PENALTY    { background: #fee2e2; color: #991b1b; }
.entry-ADJUSTMENT { background: #fef3c7; color: #92400e; }

.method-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.method-Cash          { background: #d1fae5; color: #065f46; }
.method-GCash         { background: #e0f2fe; color: #0369a1; }
.method-Bank-Transfer { background: #f3e8ff; color: #6b21a8; }

.empty-msg { text-align: center; color: #94a3b8; padding: 32px; font-size: 14px; }
.total-row td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; }

/* ===== TEACHER VIEW ===== */
.teacher-card {
    background: white; border-radius: 14px; padding: 40px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06); text-align: center;
}
.teacher-card h3 { color: #0f2027; margin-bottom: 8px; }
.teacher-card p  { color: #64748b; font-size: 14px; }

/* ===== PAYMENT PROOF UPLOAD ===== */
.proof-section {
    background: white; border-radius: 14px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    overflow: hidden; margin-bottom: 28px;
}
.proof-section-head {
    background: linear-gradient(90deg, #0f2027, #1a3a35);
    color: white; padding: 16px 24px;
    display: flex; align-items: center; justify-content: space-between;
}
.proof-section-head h3 { margin: 0; font-size: 16px; }
.proof-section-head span { font-size: 12px; color: rgba(255,255,255,0.5); }
.proof-body { padding: 24px; }

.proof-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.proof-form-grid.single { grid-template-columns: 1fr; }
.form-group label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group input,
.form-group select,
.form-group textarea { width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 14px; font-family: inherit; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: #0f6e56; box-shadow: 0 0 0 3px rgba(15,110,86,0.1); }
.form-group textarea { resize: vertical; min-height: 70px; }

.file-drop {
    border: 2px dashed #cbd5e1; border-radius: 10px; padding: 28px;
    text-align: center; cursor: pointer; transition: all 0.2s; background: #f8fafc;
    margin-bottom: 14px;
}
.file-drop:hover, .file-drop.dragover { border-color: #0f6e56; background: #f0fdf4; }
.file-drop .icon { font-size: 32px; margin-bottom: 8px; }
.file-drop p { margin: 0; font-size: 13px; color: #64748b; }
.file-drop .hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
#filePreview { max-width: 100%; max-height: 200px; border-radius: 8px; margin-top: 12px; display: none; }

.btn-submit-proof {
    padding: 11px 28px; background: #0f6e56; color: white; border: none;
    border-radius: 8px; cursor: pointer; font-size: 14px; font-family: inherit;
    font-weight: 600; transition: background 0.18s; width: 100%;
}
.btn-submit-proof:hover { background: #0a5242; }
.btn-submit-proof:disabled { background: #94a3b8; cursor: not-allowed; }

.pending-notice {
    background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px;
    padding: 16px 20px; display: flex; gap: 12px; align-items: flex-start;
}
.pending-notice .icon { font-size: 20px; flex-shrink: 0; }
.pending-notice h4 { margin: 0 0 4px; font-size: 14px; color: #92400e; }
.pending-notice p  { margin: 0; font-size: 13px; color: #b45309; }

/* Proof history badges */
.badge-pending   { background: #fef3c7; color: #92400e;  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-confirmed { background: #d1fae5; color: #065f46;  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-rejected  { background: #fee2e2; color: #991b1b;  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }

/* Toast */
.toast {
    position: fixed; bottom: 28px; right: 28px; background: #0f2027; color: white;
    padding: 13px 22px; border-radius: 10px; font-size: 14px; z-index: 999;
    opacity: 0; transform: translateY(10px); transition: all 0.3s; pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav class="navbar">
    <a href="student_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-right">
        <span class="nav-name">👋 <?= htmlspecialchars($user['full_name']) ?></span>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">

<?php if ($role === 'teacher'): ?>

    <!-- ===== TEACHER VIEW ===== -->
    <div class="teacher-card">
        <div class="avatar" style="width:72px;height:72px;font-size:26px;margin:0 auto 16px;">
            <?= strtoupper(substr($user['full_name'], 0, 2)) ?>
        </div>
        <h3>Welcome, <?= htmlspecialchars($user['full_name']) ?></h3>
        <p>You are logged in as a Teacher.<br>Use this portal to view your class and student information.</p>
        <p style="margin-top:20px;font-size:13px;color:#94a3b8;">More teacher features coming soon.</p>
    </div>

<?php elseif ($role === 'student'): ?>

    <!-- ===== PROFILE CARD ===== -->
    <div class="profile-card">
        <div class="avatar">
            <?= strtoupper(substr($user['full_name'], 0, 2)) ?>
        </div>
        <div class="profile-info">
            <h2><?= htmlspecialchars($user['full_name']) ?></h2>
            <p><?= htmlspecialchars($user['email']) ?></p>
        </div>
        <div class="profile-meta">
            <div class="meta-item">
                <div class="label">Student ID</div>
                <div class="value"><?= htmlspecialchars($user['student_number'] ?? '—') ?></div>
            </div>
            <?php if ($student): ?>
            <div class="meta-item">
                <div class="label">Grade</div>
                <div class="value"><?= htmlspecialchars($student['grade_level'] ?? '—') ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Section</div>
                <div class="value"><?= htmlspecialchars($student['section'] ?? '—') ?></div>
            </div>
            <div class="meta-item">
                <div class="label">School Year</div>
                <div class="value"><?= htmlspecialchars($student['sy_name'] ?? '—') ?></div>
            </div>
            <?php endif; ?>
            <div class="meta-item" style="align-self:center;">
                <a href="edit_request_form.php" style="display:inline-block;padding:8px 18px;background:#0077b6;color:white;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;">
                    ✏️ Request Info Edit
                </a>
            </div>
        </div>
    </div>

    <?php if ($student && $student['account_id']): ?>

    <!-- ===== BALANCE HERO ===== -->
    <div class="balance-hero">
        <div>
            <div class="label">Remaining Balance</div>
            <div class="amount <?= $balance <= 0 ? 'paid' : 'due' ?>">
                ₱<?= number_format($balance, 2) ?>
            </div>
        </div>
        <div class="<?= $balance <= 0 ? 'status-pill paid' : 'status-pill pending' ?>">
            <?= $balance <= 0 ? '✓ Fully Paid' : '⚠ Balance Due' ?>
        </div>
    </div>

    <!-- ===== SUMMARY CARDS ===== */
    <div class="summary-cards">
        <div class="sum-card">
            <h4>Total Assessment</h4>
            <p>₱<?= number_format($totalCharged, 2) ?></p>
        </div>
        <div class="sum-card green">
            <h4>Total Paid</h4>
            <p>₱<?= number_format($totalPaid, 2) ?></p>
        </div>
        <?php if ($totalDiscount > 0): ?>
        <div class="sum-card purple">
            <h4>Discounts</h4>
            <p>₱<?= number_format($totalDiscount, 2) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($totalPenalty > 0): ?>
        <div class="sum-card amber">
            <h4>Penalties</h4>
            <p>₱<?= number_format($totalPenalty, 2) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== PAYMENT PROOF UPLOAD ===== -->
    <?php if ($balance > 0): ?>
    <div class="section-header">📤 Submit Payment Proof</div>
    <div class="proof-section">
        <div class="proof-section-head">
            <h3>Upload Payment Screenshot</h3>
            <span>GCash, bank transfer, or any digital payment</span>
        </div>
        <div class="proof-body">

            <?php if ($hasPending): ?>
            <!-- Already has a pending proof -->
            <div class="pending-notice">
                <div class="icon">⏳</div>
                <div>
                    <h4>Proof Already Submitted</h4>
                    <p>Your payment proof is currently being reviewed by the admin. You'll be notified once it's confirmed. Please check back later or contact the school office.</p>
                </div>
            </div>

            <?php else: ?>
            <!-- Upload form -->
            <div class="proof-form-grid">
                <div class="form-group">
                    <label>Amount Paid (₱)</label>
                    <input type="number" id="proofAmount" min="1" step="0.01"
                           max="<?= $balance ?>"
                           placeholder="e.g. <?= number_format($balance, 2) ?>"
                           value="<?= $balance ?>">
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select id="proofMethod">
                        <option value="GCash">GCash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                    </select>
                </div>
            </div>
            <div class="proof-form-grid">
                <div class="form-group">
                    <label>Reference / Transaction Number</label>
                    <input type="text" id="proofRef" placeholder="e.g. 1234567890">
                </div>
                <div class="form-group">
                    <label>Note <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                    <input type="text" id="proofNote" placeholder="Any additional info…">
                </div>
            </div>

            <!-- File drop zone -->
            <div class="file-drop" id="fileDrop" onclick="document.getElementById('proofFile').click()">
                <div class="icon">📎</div>
                <p>Click to attach your payment screenshot</p>
                <div class="hint">JPG, PNG, WebP, or PDF · Max 5MB</div>
                <img id="filePreview" alt="Preview">
            </div>
            <input type="file" id="proofFile" accept="image/jpeg,image/png,image/webp,application/pdf"
                   style="display:none" onchange="previewFile(this)">

            <button class="btn-submit-proof" id="submitProofBtn" onclick="submitProof()">
                📤 Submit Payment Proof
            </button>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- ===== PROOF HISTORY ===== -->
    <?php if (!empty($proofs)): ?>
    <div class="section-header">📋 Proof Submission History</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Submitted</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Admin Note</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($proofs as $pr): ?>
            <tr>
                <td style="font-size:13px;color:#64748b;">
                    <?= date('M d, Y h:i A', strtotime($pr['submitted_at'])) ?>
                </td>
                <td class="amount-col">₱<?= number_format($pr['amount'], 2) ?></td>
                <td><?= htmlspecialchars($pr['method']) ?></td>
                <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($pr['reference'] ?: '—') ?></td>
                <td><span class="badge-<?= $pr['status'] ?>"><?= ucfirst($pr['status']) ?></span></td>
                <td style="font-size:13px;color:#64748b;"><?= htmlspecialchars($pr['admin_note'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== FEE BREAKDOWN ===== -->
    <div class="section-header">Fee Breakdown</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Fee / Description</th>
                    <th>Type</th>
                    <th class="amount-col">Amount</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($ledger)): ?>
                <tr><td colspan="4" class="empty-msg">No ledger entries found.</td></tr>
            <?php else: ?>
                <?php foreach ($ledger as $entry): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($entry['fee_label'] ?: ($entry['remarks'] ?? '—')) ?>
                    </td>
                    <td><span class="entry-badge entry-<?= $entry['entry_type'] ?>"><?= $entry['entry_type'] ?></span></td>
                    <td class="amount-col">
                        <?php
                            $sign  = in_array($entry['entry_type'], ['PAYMENT','DISCOUNT']) ? '−' : '+';
                            $color = in_array($entry['entry_type'], ['PAYMENT','DISCOUNT']) ? '#198754' : ($entry['entry_type'] === 'PENALTY' ? '#dc2626' : '#0f2027');
                        ?>
                        <span style="color:<?= $color ?>"><?= $sign ?>₱<?= number_format($entry['amount'], 2) ?></span>
                    </td>
                    <td style="color:#94a3b8;font-size:13px;"><?= date('M d, Y', strtotime($entry['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">Remaining Balance</td>
                    <td class="amount-col" style="color:<?= $balance <= 0 ? '#198754' : '#dc2626' ?>">
                        ₱<?= number_format($balance, 2) ?>
                    </td>
                    <td></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ===== PAYMENT HISTORY ===== -->
    <div class="section-header">Payment History</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>OR Number</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="5" class="empty-msg">No payments recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <?php $mc = 'method-' . str_replace(' ', '-', $p['method']); ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                    <td class="amount-col">₱<?= number_format($p['amount'], 2) ?></td>
                    <td><span class="method-badge <?= $mc ?>"><?= htmlspecialchars($p['method']) ?></span></td>
                    <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($p['or_number']) ?></td>
                    <td style="color:#64748b;font-size:13px;"><?= htmlspecialchars($p['processed_by']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
        <div style="background:white;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 10px rgba(0,0,0,0.05);">
            <p style="color:#94a3b8;font-size:14px;">No tuition account found. Please contact your administrator.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
// ── File preview ──────────────────────────────────────────────────
function previewFile(input) {
    const file = input.files[0];
    if (!file) return;

    const drop = document.getElementById('fileDrop');
    const preview = document.getElementById('filePreview');

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }

    drop.querySelector('p').textContent = '📎 ' + file.name;
    drop.querySelector('.hint').textContent = (file.size / 1024).toFixed(1) + ' KB';
}

// ── Drag and drop ─────────────────────────────────────────────────
const dropZone = document.getElementById('fileDrop');
if (dropZone) {
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) {
            const input = document.getElementById('proofFile');
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            previewFile(input);
        }
    });
}

// ── Submit proof ──────────────────────────────────────────────────
async function submitProof() {
    const amount  = parseFloat(document.getElementById('proofAmount').value);
    const method  = document.getElementById('proofMethod').value;
    const ref     = document.getElementById('proofRef').value.trim();
    const note    = document.getElementById('proofNote').value.trim();
    const file    = document.getElementById('proofFile').files[0];

    if (!amount || amount <= 0)  { showToast('Please enter a valid amount.', true); return; }
    if (!file)                   { showToast('Please attach your payment screenshot.', true); return; }

    const btn = document.getElementById('submitProofBtn');
    btn.textContent = '⏳ Uploading…'; btn.disabled = true;

    const body = new FormData();
    body.append('action',      'submit_proof');
    body.append('account_id',  '<?= $account_id ?? 0 ?>');
    body.append('amount',      amount);
    body.append('method',      method);
    body.append('reference',   ref);
    body.append('note',        note);
    body.append('proof_file',  file);

    try {
        const res  = await fetch('php/payment_proof.php', { method: 'POST', body });
        const data = await res.json();

        if (data.error) {
            showToast('⚠ ' + data.error, true);
            btn.textContent = '📤 Submit Payment Proof'; btn.disabled = false;
        } else {
            showToast('✓ Proof submitted! Admin will verify soon.');
            setTimeout(() => location.reload(), 2000);
        }
    } catch (e) {
        showToast('⚠ Network error. Please try again.', true);
        btn.textContent = '📤 Submit Payment Proof'; btn.disabled = false;
    }
}

function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = isError ? '#dc2626' : '#0f2027';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 4000);
}
</script>
</body>
</html>
