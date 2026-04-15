<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── School years for filter ──────────────────────────────────────
$syList = $conn->query("SELECT sy_id, name, status FROM school_years ORDER BY sy_id DESC")->fetch_all(MYSQLI_ASSOC);
$selectedSy = intval($_GET['sy_id'] ?? ($syList[0]['sy_id'] ?? 0));
$syName     = '';
foreach ($syList as $sy) { if ($sy['sy_id'] === $selectedSy) $syName = $sy['name']; }

// ── Collection summary ───────────────────────────────────────────
$summary = $conn->prepare("
    SELECT
        COUNT(DISTINCT p.student_id)                             AS students_paid,
        SUM(p.amount)                                            AS total_collected,
        SUM(CASE WHEN p.method='Cash'          THEN p.amount ELSE 0 END) AS cash,
        SUM(CASE WHEN p.method='GCash'         THEN p.amount ELSE 0 END) AS gcash,
        SUM(CASE WHEN p.method='Bank Transfer' THEN p.amount ELSE 0 END) AS bank,
        COUNT(p.payment_id)                                      AS tx_count
    FROM payments p
    JOIN tuition_accounts ta ON p.account_id = ta.account_id
    WHERE ta.sy_id = ?
");
$summary->bind_param('i', $selectedSy);
$summary->execute();
$sum = $summary->get_result()->fetch_assoc();

// ── Total assessment vs collected ────────────────────────────────
$assessed = $conn->prepare("
    SELECT
        SUM(CASE WHEN sl.entry_type='CHARGE'  THEN sl.amount ELSE 0 END) AS total_assessed,
        SUM(CASE WHEN sl.entry_type='PAYMENT' THEN sl.amount ELSE 0 END) AS total_paid,
        SUM(CASE WHEN sl.entry_type='DISCOUNT' THEN sl.amount ELSE 0 END) AS total_discount,
        SUM(CASE WHEN sl.entry_type='PENALTY'  THEN sl.amount ELSE 0 END) AS total_penalty
    FROM student_ledgers sl
    JOIN tuition_accounts ta ON sl.account_id = ta.account_id
    WHERE ta.sy_id = ?
");
$assessed->bind_param('i', $selectedSy);
$assessed->execute();
$fin = $assessed->get_result()->fetch_assoc();
$outstanding = ($fin['total_assessed'] + $fin['total_penalty']) - ($fin['total_paid'] + $fin['total_discount']);
$collectionRate = $fin['total_assessed'] > 0
    ? round(($fin['total_paid'] / $fin['total_assessed']) * 100, 1)
    : 0;

// ── Per-grade breakdown ──────────────────────────────────────────
$gradeBreakdown = $conn->prepare("
    SELECT
        s.grade_level,
        COUNT(DISTINCT s.student_id)                                        AS student_count,
        SUM(CASE WHEN sl.entry_type='CHARGE'  THEN sl.amount ELSE 0 END)   AS assessed,
        SUM(CASE WHEN sl.entry_type='PAYMENT' THEN sl.amount ELSE 0 END)   AS collected,
        SUM(CASE WHEN sl.entry_type='CHARGE'  THEN sl.amount ELSE 0 END) -
        SUM(CASE WHEN sl.entry_type='PAYMENT' THEN sl.amount ELSE 0 END)   AS outstanding
    FROM student_ledgers sl
    JOIN tuition_accounts ta ON sl.account_id = ta.account_id
    JOIN students s ON ta.student_id = s.student_id
    WHERE ta.sy_id = ?
    GROUP BY s.grade_level
    ORDER BY CAST(s.grade_level AS UNSIGNED) ASC
");
$gradeBreakdown->bind_param('i', $selectedSy);
$gradeBreakdown->execute();
$gradeRows = $gradeBreakdown->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Monthly collections ──────────────────────────────────────────
$monthly = $conn->prepare("
    SELECT
        DATE_FORMAT(p.payment_date, '%b %Y') AS month_label,
        DATE_FORMAT(p.payment_date, '%Y-%m') AS month_sort,
        SUM(p.amount)                        AS total,
        COUNT(p.payment_id)                  AS tx_count
    FROM payments p
    JOIN tuition_accounts ta ON p.account_id = ta.account_id
    WHERE ta.sy_id = ?
    GROUP BY month_sort, month_label
    ORDER BY month_sort ASC
");
$monthly->bind_param('i', $selectedSy);
$monthly->execute();
$monthlyRows = $monthly->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Top collectors (most paying students) ────────────────────────
$topPayers = $conn->prepare("
    SELECT u.full_name, u.student_number, s.grade_level, s.section,
           SUM(p.amount) AS total_paid, COUNT(p.payment_id) AS payments
    FROM payments p
    JOIN tuition_accounts ta ON p.account_id = ta.account_id
    JOIN students s ON ta.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    WHERE ta.sy_id = ?
    GROUP BY p.student_id
    ORDER BY total_paid DESC
    LIMIT 10
");
$topPayers->bind_param('i', $selectedSy);
$topPayers->execute();
$topRows = $topPayers->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Pending students (highest balance first) ─────────────────────
$pending = $conn->prepare("
    SELECT u.full_name, u.student_number, s.grade_level, s.section,
        (
            SUM(CASE WHEN sl.entry_type IN ('CHARGE','PENALTY')  THEN sl.amount ELSE 0 END) -
            SUM(CASE WHEN sl.entry_type IN ('PAYMENT','DISCOUNT') THEN sl.amount ELSE 0 END)
        ) AS balance
    FROM student_ledgers sl
    JOIN tuition_accounts ta ON sl.account_id = ta.account_id
    JOIN students s ON ta.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    WHERE ta.sy_id = ?
    GROUP BY sl.account_id
    HAVING balance > 0
    ORDER BY balance DESC
    LIMIT 15
");
$pending->bind_param('i', $selectedSy);
$pending->execute();
$pendingRows = $pending->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Financial Report | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<link href="css/admind.css" rel="stylesheet">
<style>
.report-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.report-header h2 { margin:0; font-size:24px; color:#0f2027; }
.sy-select { padding:8px 32px 8px 12px; border:1px solid #cbd5e1; border-radius:6px; background:white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 10px center; appearance:none; font-size:13px; cursor:pointer; outline:none; }
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:28px; }
.kpi { background:white; border-radius:10px; padding:18px 20px; box-shadow:0 4px 10px rgba(0,0,0,0.05); border-left:5px solid #0077b6; }
.kpi.green  { border-color:#198754; }
.kpi.amber  { border-color:#d97706; }
.kpi.red    { border-color:#dc2626; }
.kpi.purple { border-color:#7c3aed; }
.kpi h4 { margin:0 0 6px; font-size:12px; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px; }
.kpi p  { margin:0; font-size:20px; font-weight:700; color:#0f2027; }
.section-label { font-size:16px; font-weight:700; color:#0f2027; margin:28px 0 12px; display:flex; align-items:center; justify-content:space-between; }
.table-wrap { background:white; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.05); overflow:hidden; margin-bottom:28px; }
table { width:100%; border-collapse:collapse; }
th { background:#f8fafc; text-align:left; font-size:12px; font-weight:600; color:#64748b; padding:11px 16px; border-bottom:1px solid #e2e8f0; text-transform:uppercase; letter-spacing:0.5px; }
td { padding:11px 16px; font-size:14px; border-bottom:1px solid #f1f5f9; color:#0f2027; }
tr:last-child td { border-bottom:none; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
.total-row td { font-weight:700; background:#f8fafc; border-top:2px solid #e2e8f0; }
.prog-wrap { background:#e2e8f0; border-radius:4px; height:8px; min-width:80px; }
.prog-bar  { background:#198754; border-radius:4px; height:8px; }
.btn-export { padding:8px 14px; background:#198754; color:white; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-family:inherit; display:inline-flex; align-items:center; gap:5px; }
.btn-export:hover { background:#157347; }
.print-btn { padding:8px 14px; background:#0077b6; color:white; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-family:inherit; display:inline-flex; align-items:center; gap:5px; }
.print-btn:hover { background:#005f8e; }
@media print {
    .navbar, .no-print { display:none !important; }
    .main { margin-top:0 !important; }
}
</style>
</head>
<body>
<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand"><h2>CATMIS</h2><span>CCS Portal</span></a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="financial_report.php" class="active">📊 Reports</a>
        <a href="backup.php">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="report-header">
        <h2>📊 Financial Report</h2>
        <div style="display:flex;gap:10px;align-items:center;" class="no-print">
            <form method="GET" style="display:inline;">
                <select name="sy_id" class="sy-select" onchange="this.form.submit()">
                    <?php foreach ($syList as $sy): ?>
                    <option value="<?= $sy['sy_id'] ?>" <?= $sy['sy_id'] == $selectedSy ? 'selected' : '' ?>>
                        SY <?= htmlspecialchars($sy['name']) ?><?= $sy['status']==='active' ? ' ✓' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn-export" onclick="exportFull()">📥 Excel</button>
            <button class="print-btn" onclick="window.print()">🖨 Print</button>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="kpi-grid">
        <div class="kpi green">
            <h4>Total Collected</h4>
            <p>₱<?= number_format($sum['total_collected'] ?? 0, 2) ?></p>
        </div>
        <div class="kpi">
            <h4>Total Assessed</h4>
            <p>₱<?= number_format($fin['total_assessed'] ?? 0, 2) ?></p>
        </div>
        <div class="kpi red">
            <h4>Outstanding</h4>
            <p>₱<?= number_format(max(0, $outstanding), 2) ?></p>
        </div>
        <div class="kpi amber">
            <h4>Collection Rate</h4>
            <p><?= $collectionRate ?>%</p>
        </div>
        <div class="kpi">
            <h4>Transactions</h4>
            <p><?= number_format($sum['tx_count'] ?? 0) ?></p>
        </div>
        <div class="kpi purple">
            <h4>Discounts Given</h4>
            <p>₱<?= number_format($fin['total_discount'] ?? 0, 2) ?></p>
        </div>
    </div>

    <!-- Payment method breakdown -->
    <div class="section-label">Payment Method Breakdown</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Method</th><th class="num">Amount</th><th class="num">Share</th><th>Progress</th></tr></thead>
            <tbody>
            <?php
                $methods = ['Cash' => $sum['cash']??0, 'GCash' => $sum['gcash']??0, 'Bank Transfer' => $sum['bank']??0];
                $total_c = array_sum($methods);
                foreach ($methods as $m => $amt):
                    $pct = $total_c > 0 ? round(($amt / $total_c) * 100, 1) : 0;
            ?>
            <tr>
                <td><?= $m ?></td>
                <td class="num">₱<?= number_format($amt, 2) ?></td>
                <td class="num"><?= $pct ?>%</td>
                <td><div class="prog-wrap"><div class="prog-bar" style="width:<?= $pct ?>%"></div></div></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row"><td>Total</td><td class="num">₱<?= number_format($total_c, 2) ?></td><td colspan="2"></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Grade breakdown -->
    <div class="section-label">
        Per Grade Level
        <button class="btn-export no-print" onclick="exportGrade()">📥 Export</button>
    </div>
    <div class="table-wrap">
        <table id="gradeTable">
            <thead><tr><th>Grade</th><th class="num">Students</th><th class="num">Assessed</th><th class="num">Collected</th><th class="num">Outstanding</th><th>Collection Rate</th></tr></thead>
            <tbody>
            <?php foreach ($gradeRows as $gr):
                $rate = $gr['assessed'] > 0 ? round(($gr['collected']/$gr['assessed'])*100,1) : 0;
            ?>
            <tr>
                <td>Grade <?= htmlspecialchars($gr['grade_level']) ?></td>
                <td class="num"><?= $gr['student_count'] ?></td>
                <td class="num">₱<?= number_format($gr['assessed'],2) ?></td>
                <td class="num">₱<?= number_format($gr['collected'],2) ?></td>
                <td class="num" style="color:<?= $gr['outstanding']>0?'#dc2626':'#198754' ?>">₱<?= number_format(max(0,$gr['outstanding']),2) ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div class="prog-wrap" style="flex:1"><div class="prog-bar" style="width:<?= $rate ?>%;background:<?= $rate>=80?'#198754':($rate>=50?'#d97706':'#dc2626') ?>"></div></div>
                        <span style="font-size:12px;min-width:36px"><?= $rate ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Monthly collections -->
    <div class="section-label">Monthly Collections (SY <?= htmlspecialchars($syName) ?>)</div>
    <div class="table-wrap">
        <table id="monthlyTable">
            <thead><tr><th>Month</th><th class="num">Transactions</th><th class="num">Amount Collected</th></tr></thead>
            <tbody>
            <?php foreach ($monthlyRows as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['month_label']) ?></td>
                <td class="num"><?= $m['tx_count'] ?></td>
                <td class="num">₱<?= number_format($m['total'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($monthlyRows)): ?>
            <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:24px;">No payment data for this school year.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Students with outstanding balance -->
    <div class="section-label">
        Students with Outstanding Balance (Top 15)
        <button class="btn-export no-print" onclick="exportPending()">📥 Export</button>
    </div>
    <div class="table-wrap">
        <table id="pendingTable">
            <thead><tr><th>Student ID</th><th>Name</th><th>Grade</th><th>Section</th><th class="num">Balance Due</th></tr></thead>
            <tbody>
            <?php foreach ($pendingRows as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['student_number']??'—') ?></td>
                <td><?= htmlspecialchars($p['full_name']) ?></td>
                <td><?= htmlspecialchars($p['grade_level']) ?></td>
                <td><?= htmlspecialchars($p['section']) ?></td>
                <td class="num" style="color:#dc2626;font-weight:600;">₱<?= number_format($p['balance'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($pendingRows)): ?>
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:24px;">All students are fully paid! 🎉</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function tableToArray(tableId, includeHeader = true) {
    const rows = document.querySelectorAll('#' + tableId + ' tr');
    const data = [];
    rows.forEach((row, i) => {
        if (i === 0 && !includeHeader) return;
        data.push(Array.from(row.querySelectorAll('th,td')).map(c => c.textContent.trim()));
    });
    return data;
}

function exportSheet(data, sheetName, fileName) {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    XLSX.writeFile(wb, fileName + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

function exportGrade()   { exportSheet(tableToArray('gradeTable'),   'By Grade',   'CATMIS_GradeReport'); }
function exportPending() { exportSheet(tableToArray('pendingTable'),  'Outstanding','CATMIS_Outstanding'); }

function exportFull() {
    const wb = XLSX.utils.book_new();
    [
        ['By Grade',    tableToArray('gradeTable')],
        ['Monthly',     tableToArray('monthlyTable')],
        ['Outstanding', tableToArray('pendingTable')],
    ].forEach(([name, data]) => XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(data), name));
    XLSX.writeFile(wb, 'CATMIS_FinancialReport_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
</body>
</html>