<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        <a href="backup.php">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <?php if ($pendingCount > 0): ?>
        <div class="notif-badge">⚠ <?= $pendingCount ?> Pending</div>
        <?php endif; ?>
        <?php if ($pendingEdits > 0): ?>
        <a href="edit_requests_admin.php" style="text-decoration:none;position:relative;">
            <span style="font-size:13px;color:rgba(255,255,255,0.7);background:rgba(255,255,255,0.1);padding:5px 11px;border-radius:6px;">✏️ <?= $pendingEdits ?> Request<?= $pendingEdits!==1?'s':'' ?></span>
        </a>
        <?php endif; ?>
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

    <div class="cards">
        <div class="card">
            <h4>Total Receivables</h4>
            <p>₱<?= number_format($totalReceivables, 2) ?></p>
        </div>
        <div class="card">
            <h4>Active Debtors</h4>
            <p><?= $activeDebtors ?></p>
        </div>
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
    echo "<tr data-grade='{$row['grade_level']}' data-section='{$row['section']}'>
        <td>{$row['student_id']}</td>
        <td>{$row['full_name']}</td>
        <td>{$row['grade_level']}</td>
        <td>{$row['section']}</td>
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