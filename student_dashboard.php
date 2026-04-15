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

        // Ledger — fee-level breakdown (CHARGEs linked to tuition_fees)
        $lStmt = $conn->prepare("
            SELECT
                sl.ledger_id,
                sl.entry_type,
                sl.amount,
                sl.remarks,
                sl.created_at,
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
            SELECT
                p.payment_date, p.amount, p.method, p.or_number,
                u.full_name AS processed_by
            FROM payments p
            JOIN users u ON p.posted_by = u.user_id
            WHERE p.account_id = ?
            ORDER BY p.payment_date DESC
        ");
        $pStmt->bind_param('i', $account_id);
        $pStmt->execute();
        $payments = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Totals for ledger summary ────────────────────────────────────
$totalCharged  = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'CHARGE'),  'amount'));
$totalPaid     = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'PAYMENT'), 'amount'));
$totalDiscount = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'DISCOUNT'),'amount'));
$totalPenalty  = array_sum(array_column(array_filter($ledger, fn($r) => $r['entry_type'] === 'PENALTY'), 'amount'));
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
</style>
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav class="navbar">
    <a href="user_dashboard.php" class="navbar-brand">
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

    <!-- ===== SUMMARY CARDS ===== -->
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
                        <?php if ($entry['fee_label']): ?>
                            <?= htmlspecialchars($entry['fee_label']) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($entry['remarks'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="entry-badge entry-<?= $entry['entry_type'] ?>"><?= $entry['entry_type'] ?></span></td>
                    <td class="amount-col">
                        <?php
                            $sign = in_array($entry['entry_type'], ['PAYMENT','DISCOUNT']) ? '−' : '+';
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

</body>
</html>