<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: login.php?role=admin');
    exit;
}

// Unread notifications count
$_uid = $_SESSION['user_id'];
$_nRes = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE admin_id=? AND is_read=0");
$_nRes->bind_param('i', $_uid);
$_nRes->execute();
$unreadNotifs = $_nRes->get_result()->fetch_assoc()['cnt'] ?? 0;

// Pending edit requests count
$_eRes = $conn->query("SELECT COUNT(*) AS cnt FROM edit_requests WHERE status='pending'");
$pendingEdits = $_eRes->fetch_assoc()['cnt'] ?? 0;

$pendingRes = $conn->query("
    SELECT COUNT(*) AS cnt
    FROM tuition_accounts ta
    JOIN (
        SELECT account_id,
               SUM(CASE WHEN entry_type='CHARGE'  THEN amount ELSE 0 END) -
               SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) AS bal
        FROM student_ledgers
        GROUP BY account_id
    ) ledger ON ta.account_id = ledger.account_id
    WHERE ledger.bal > 0
");
$pendingCount = $pendingRes->fetch_assoc()['cnt'] ?? 0;

$totalRes = $conn->query("
    SELECT
        SUM(CASE WHEN entry_type='CHARGE'  THEN amount ELSE 0 END) -
        SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) AS total_receivables
    FROM student_ledgers
");
$totalReceivables = $totalRes->fetch_assoc()['total_receivables'] ?? 0;
$activeDebtors    = $pendingCount;

// ── Account counts ─────────────────────────────────────────────
$countsRes = $conn->query("
    SELECT
        SUM(role='student')  AS total_students,
        SUM(role='teacher')  AS total_teachers,
        SUM(role IN ('admin','superadmin')) AS total_admins,
        COUNT(*)             AS total_accounts
    FROM users
    WHERE deleted_at IS NULL
");
$counts = $countsRes->fetch_assoc();

// Accounts in the Bin (awaiting permanent deletion)
$binRes  = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE deleted_at IS NOT NULL");
$binCount = $binRes->fetch_assoc()['cnt'] ?? 0;

// ── Pending payment proofs awaiting review ───────────────────────
$pendingProofsCount = 0;
$tblChk = $conn->query("SHOW TABLES LIKE 'payment_proofs'");
if ($tblChk && $tblChk->num_rows > 0) {
    $ppRes = $conn->query("SELECT COUNT(*) AS cnt FROM payment_proofs WHERE status='pending'");
    $pendingProofsCount = $ppRes->fetch_assoc()['cnt'] ?? 0;
}

// ── This month's collections ──────────────────────────────────────
$monthRes = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS tx_count
    FROM payments
    WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())
");
$monthCollection = $monthRes->fetch_assoc();

// ── Last 6 months collection trend (for chart) ────────────────────
$trendRes = $conn->query("
    SELECT DATE_FORMAT(payment_date, '%b %Y') AS month_label,
           DATE_FORMAT(payment_date, '%Y-%m') AS month_sort,
           SUM(amount) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_sort, month_label
    ORDER BY month_sort ASC
");
$trendRows = $trendRes->fetch_all(MYSQLI_ASSOC);

// ── Recent activity feed ──────────────────────────────────────────
$activityRes = $conn->query("
    SELECT al.action, al.timestamp, u.full_name
    FROM audit_logs al
    JOIN users u ON al.user_id = u.user_id
    ORDER BY al.timestamp DESC
    LIMIT 8
");
$recentActivity = $activityRes->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="js/export_preview_modal.js"></script>
<link href="css/admind.css" rel="stylesheet" />
</head>
<body>

<!-- ===== TOP NAVBAR ===== -->
<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="admin_dashboard.php" class="active">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="financial_report.php">📊 Reports</a>
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <a href="backup.php">💾 Backup</a>
        <?php endif; ?>
    </div>
    <div class="navbar-right">
        <a href="notifications.php" style="text-decoration:none;position:relative;display:flex;align-items:center;">
            <span style="font-size:20px;">🔔</span>
            <?php if ($unreadNotifs > 0): ?>
            <span style="position:absolute;top:-6px;right:-6px;background:#ff3b30;color:white;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?= min($unreadNotifs,99) ?></span>
            <?php endif; ?>
        </a>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN CONTENT ===== -->
<div class="main">
    <div class="title">Finance &amp; Assessment Dashboard</div>

    <div class="cards" style="flex-wrap:wrap;">
        <div class="card">
            <h4>Total Receivables</h4>
            <p>₱<?= number_format($totalReceivables, 2) ?></p>
        </div>
        <div class="card">
            <h4>Active Debtors</h4>
            <p><?= $activeDebtors ?></p>
        </div>
        <div class="card" style="border-left-color:#198754;">
            <h4>Collected This Month</h4>
            <p>₱<?= number_format($monthCollection['total'], 2) ?></p>
            <small style="color:#94a3b8;"><?= $monthCollection['tx_count'] ?> transaction<?= $monthCollection['tx_count'] == 1 ? '' : 's' ?></small>
        </div>
        <div class="card" style="border-left-color:#0ea5e9;">
            <h4>Total Students</h4>
            <p><?= $counts['total_students'] ?? 0 ?></p>
        </div>
        <div class="card" style="border-left-color:#8b5cf6;">
            <h4>Total Teachers</h4>
            <p><?= $counts['total_teachers'] ?? 0 ?></p>
        </div>
        <div class="card" style="border-left-color:#6b7280;">
            <h4>Total Accounts</h4>
            <p><?= $counts['total_accounts'] ?? 0 ?></p>
            <small style="color:#94a3b8;">incl. <?= $counts['total_admins'] ?? 0 ?> admin(s)</small>
        </div>
        <?php if ($pendingProofsCount > 0): ?>
        <div class="card" style="border-left-color:#f59e0b;">
            <h4>Payment Proofs Awaiting Review</h4>
            <p><?= $pendingProofsCount ?></p>
            <a href="payment_history.php" style="font-size:12px;">Review now →</a>
        </div>
        <?php endif; ?>
        <?php if ($binCount > 0): ?>
        <div class="card" style="border-left-color:#dc2626;">
            <h4>Accounts in Bin</h4>
            <p><?= $binCount ?></p>
            <a href="user_management.php" style="font-size:12px;">View Bin →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Collection trend + recent activity -->
    <div class="section-title-row">
        <h3>Collection Trend (Last 6 Months)</h3>
    </div>
    <div class="table-container" style="padding:20px;">
        <?php if (empty($trendRows)): ?>
            <p style="color:#94a3b8;text-align:center;padding:20px;">No payment data yet for the last 6 months.</p>
        <?php else: ?>
            <?php $maxVal = max(array_column($trendRows, 'total')) ?: 1; ?>
            <div style="display:flex;align-items:flex-end;gap:16px;height:160px;padding:0 10px;">
                <?php foreach ($trendRows as $t): ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                    <div style="font-size:11px;color:#64748b;margin-bottom:4px;">₱<?= number_format($t['total'], 0) ?></div>
                    <div style="width:100%;max-width:48px;background:linear-gradient(180deg,#0077b6,#0096c7);border-radius:6px 6px 0 0;height:<?= max(6, round(($t['total'] / $maxVal) * 120)) ?>px;"></div>
                    <div style="font-size:12px;color:#374151;margin-top:6px;font-weight:600;"><?= htmlspecialchars($t['month_label']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-title-row">
        <h3>Recent Activity</h3>
        <a href="audit_logs.php" style="font-size:13px;">View all →</a>
    </div>
    <div class="table-container" style="padding:8px 20px;">
        <?php if (empty($recentActivity)): ?>
            <p style="color:#94a3b8;text-align:center;padding:20px;">No recent activity.</p>
        <?php else: ?>
            <?php foreach ($recentActivity as $act): ?>
            <div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">
                <div><strong><?= htmlspecialchars($act['full_name']) ?></strong> — <?= htmlspecialchars($act['action']) ?></div>
                <div style="color:#94a3b8;white-space:nowrap;"><?= date('M d, Y g:i A', strtotime($act['timestamp'])) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="section-title-row">
        <h3>Tuition Ledger Overview</h3>
    </div>

    <div class="search-export-row">
        <input type="text" id="searchInput" placeholder="🔍  Search student name or section…" onkeyup="searchTable()">
        <button class="btn-export" onclick="exportToExcel()">📥 Export to Excel</button>
    </div>

    <div class="grade-filter-row">
        <label for="gradeSelect">Filter by Grade:</label>
        <select class="grade-select" id="gradeSelect" onchange="filterGrade(this.value)">
            <option value="all">All Grades</option>
            <?php for ($g = 1; $g <= 12; $g++): ?>
            <option value="<?= $g ?>">Grade <?= $g ?></option>
            <?php endfor; ?>
        </select>
        <div class="section-buttons" id="sectionButtons"></div>
    </div>

    <div class="table-container">
        <table id="studentTable">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Grade</th>
                    <th>Section</th>
                    <th>Remaining Balance</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
<?php
$result = $conn->query("
    SELECT s.student_id, u.full_name, s.grade_level, s.section, ta.account_id
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    JOIN tuition_accounts ta ON s.student_id = ta.student_id
    ORDER BY u.full_name ASC
");
while ($row = $result->fetch_assoc()) {
    $balance = getBalance($conn, $row['account_id']);
    $status  = ($balance <= 0) ? 'Paid' : 'Pending';
    $badge   = ($status === 'Paid') ? 'badge-paid' : 'badge-pending';
    $action  = ($status === 'Pending')
        ? "<button class='btn-payment' onclick='pay({$row['account_id']})'>Post Payment</button>"
        : "—";

    // Normalize grade_level to a plain numeric string so it matches the <option value="N"> in the filter dropdown
    $gradeRaw = trim((string)$row['grade_level']);
    $gradeNum = preg_replace('/[^0-9]/', '', $gradeRaw); // strips "Grade " prefix if present
    $gradeAttr = ($gradeNum !== '') ? $gradeNum : '';
    $gradeDisplay = ($gradeRaw !== '') ? htmlspecialchars($gradeRaw) : 'N/A';

    $sectionAttr = htmlspecialchars($row['section'] ?? '', ENT_QUOTES);
    $sectionDisplay = ($row['section'] !== '' && $row['section'] !== null) ? htmlspecialchars($row['section']) : 'N/A';

    echo "<tr data-grade='{$gradeAttr}' data-section='{$sectionAttr}'>
        <td>{$row['student_id']}</td>
        <td>{$row['full_name']}</td>
        <td>{$gradeDisplay}</td>
        <td>{$sectionDisplay}</td>
        <td>₱" . number_format($balance, 2) . "</td>
        <td><span class='{$badge}'>{$status}</span></td>
        <td>{$action}</td>
    </tr>";
}
?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="popup" id="popupBox">
    <strong>⚠ Pending Payments</strong>
    <?= $pendingCount ?> overdue account<?= $pendingCount !== 1 ? 's' : '' ?> detected.<br><br>
    <a onclick="dismissPopup()">Dismiss</a>
</div>
<?php endif; ?>

<script src="js/admin.js"></script>

</body>
</html>