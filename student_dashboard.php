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
<link href="css/studentd.css" rel="stylesheet" />
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