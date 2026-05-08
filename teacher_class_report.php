<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacher_user_id = $_SESSION['user_id'];

// ── Get teacher record ────────────────────────────────────────────
$tStmt = $conn->prepare("
    SELECT t.teacher_id, u.full_name
    FROM teachers t JOIN users u ON t.user_id = u.user_id
    WHERE t.user_id = ?
");
$tStmt->bind_param('i', $teacher_user_id);
$tStmt->execute();
$teacher      = $tStmt->get_result()->fetch_assoc();
$teacher_id   = $teacher['teacher_id'] ?? 0;
$teacher_name = $teacher['full_name']  ?? 'Teacher';

// ── Active school year ────────────────────────────────────────────
$syRow   = $conn->query("SELECT sy_id, name FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
$sy_id   = $syRow['sy_id']  ?? 0;
$sy_name = $syRow['name']   ?? '—';

// ── Sections ──────────────────────────────────────────────────────
$secStmt = $conn->prepare("
    SELECT section_id, section_name, grade_level
    FROM sections WHERE teacher_id = ? AND sy_id = ?
    ORDER BY grade_level ASC, section_name ASC
");
$secStmt->bind_param('ii', $teacher_id, $sy_id);
$secStmt->execute();
$sections   = $secStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sectionIds = array_column($sections, 'section_id');

// ── Selected section ──────────────────────────────────────────────
$selectedSection = intval($_GET['section'] ?? ($sectionIds[0] ?? 0));
if ($selectedSection && !in_array($selectedSection, $sectionIds)) {
    $selectedSection = $sectionIds[0] ?? 0;
}

$currentSection = null;
foreach ($sections as $sec) {
    if ($sec['section_id'] == $selectedSection) { $currentSection = $sec; break; }
}

// ── Students + financial data ─────────────────────────────────────
$students        = [];
$totalStudents   = $totalPaid = $totalPending = 0;
$totalAssessed   = $totalCollected = $totalBalance = 0.0;

if ($selectedSection) {
    $sStmt = $conn->prepare("
        SELECT s.student_id, u.full_name, u.student_number, u.email,
               ta.account_id,
               ta.discount, ta.penalties
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN tuition_accounts ta ON s.student_id = ta.student_id AND ta.sy_id = ?
        WHERE s.section_id = ?
        ORDER BY u.full_name ASC
    ");
    $sStmt->bind_param('ii', $sy_id, $selectedSection);
    $sStmt->execute();
    $rawStudents = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rawStudents as $row) {
        $bal = $row['account_id'] ? getBalance($conn, $row['account_id']) : 0;

        // Get total assessed and paid for this student
        $assessed = 0; $paid = 0;
        if ($row['account_id']) {
            $ledStmt = $conn->prepare("
                SELECT entry_type, SUM(amount) AS total
                FROM student_ledgers WHERE account_id = ?
                GROUP BY entry_type
            ");
            $ledStmt->bind_param('i', $row['account_id']);
            $ledStmt->execute();
            foreach ($ledStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
                if (in_array($l['entry_type'], ['CHARGE', 'PENALTY'])) $assessed += $l['total'];
                if (in_array($l['entry_type'], ['PAYMENT', 'DISCOUNT'])) $paid += $l['total'];
            }
        }

        $row['balance']  = $bal;
        $row['assessed'] = $assessed;
        $row['paid']     = $paid;
        $row['status']   = $bal <= 0 ? 'Paid' : 'Pending';
        $students[]      = $row;

        $totalStudents++;
        $totalAssessed   += $assessed;
        $totalCollected  += $paid;
        $totalBalance    += $bal;
        if ($bal <= 0) $totalPaid++;
        else           $totalPending++;
    }
}

// ── Attendance summary for this section ──────────────────────────
$attSummary = null;
if ($selectedSection) {
    $aStmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT date)          AS total_days,
            SUM(status='present')         AS present,
            SUM(status='absent')          AS absent,
            SUM(status='late')            AS late,
            SUM(status='excused')         AS excused,
            COUNT(*)                      AS total_records
        FROM attendance
        WHERE section_id = ? AND teacher_id = ?
    ");
    $aStmt->bind_param('ii', $selectedSection, $teacher_id);
    $aStmt->execute();
    $attSummary = $aStmt->get_result()->fetch_assoc();
}

// ── Payment trend (last 6 months for teacher's section) ──────────
$trend = [];
if ($selectedSection) {
    $trStmt = $conn->prepare("
        SELECT DATE_FORMAT(p.payment_date, '%b %Y') AS month_label,
               DATE_FORMAT(p.payment_date, '%Y-%m') AS month_sort,
               SUM(p.amount) AS total
        FROM payments p
        JOIN tuition_accounts ta ON p.account_id = ta.account_id
        JOIN students s ON ta.student_id = s.student_id
        WHERE s.section_id = ? AND ta.sy_id = ?
        GROUP BY month_label, month_sort
        ORDER BY month_sort DESC LIMIT 6
    ");
    $trStmt->bind_param('ii', $selectedSection, $sy_id);
    $trStmt->execute();
    $trend = array_reverse($trStmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

$collectionRate = $totalAssessed > 0 ? round(($totalCollected / $totalAssessed) * 100, 1) : 0;
$attendanceRate = ($attSummary && $attSummary['total_records'] > 0)
    ? round(($attSummary['present'] / $attSummary['total_records']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Class Report | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link href="css/teacherd.css" rel="stylesheet">
<style>
.report-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:24px; }
.rcard { background:white; border-radius:10px; padding:18px 20px; box-shadow:0 2px 8px rgba(0,0,0,0.05); border-left:5px solid #e2e8f0; }
.rcard.green  { border-color:#198754; }
.rcard.blue   { border-color:#0077b6; }
.rcard.amber  { border-color:#d97706; }
.rcard.red    { border-color:#dc2626; }
.rcard.purple { border-color:#7c3aed; }
.rcard h4 { margin:0 0 4px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; }
.rcard p  { margin:0; font-size:24px; font-weight:700; color:#0f2027; }
.rcard small { font-size:11px; color:#94a3b8; }
.rate-bar { background:#e2e8f0; border-radius:999px; height:6px; margin-top:7px; overflow:hidden; }
.rate-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#198754,#0f6e56); }

.chart-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
.chart-box { background:white; border-radius:12px; padding:18px; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
.chart-box h4 { margin:0 0 14px; font-size:14px; font-weight:600; color:#374151; }

.report-table { width:100%; border-collapse:collapse; }
.report-table th { background:#f2f4f7; padding:11px 14px; text-align:left; font-size:12px; font-weight:600; color:#374151; position:sticky; top:0; }
.report-table td { padding:11px 14px; font-size:13px; border-bottom:1px solid #f1f5f9; }
.report-table tr:last-child td { border-bottom:none; }
.report-table tr:hover td { background:#f0fdf4; }

.badge-paid    { background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-pending { background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }

.toolbar { display:flex; gap:10px; margin-bottom:14px; align-items:center; flex-wrap:wrap; }
.toolbar input { flex:1; min-width:200px; padding:8px 13px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit; }
.toolbar input:focus { outline:none; border-color:#0f6e56; }
.btn-print { padding:8px 16px; background:#0077b6; color:white; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-family:inherit; font-weight:600; }
.btn-print:hover { background:#005f99; }

.concern-flag { color:#dc2626; font-weight:700; font-size:12px; }

@media print {
    .navbar, .section-tabs, .toolbar, .chart-row { display:none !important; }
    .main { margin-top:0 !important; padding:12px !important; }
    .table-container { max-height:none !important; box-shadow:none !important; }
}
@media (max-width:768px) { .chart-row { grid-template-columns:1fr; } }
</style>
</head>
<body>

<nav class="navbar">
    <a href="teacher_dashboard.php" class="navbar-brand"><h2>CATMIS</h2><span>CCS Portal</span></a>
    <div class="navbar-links">
        <a href="teacher_dashboard.php">🏠 My Dashboard</a>
        <a href="teacher_attendance.php">📋 Attendance</a>
        <a href="teacher_class_report.php" class="active">📊 Class Report</a>
    </div>
    <div class="navbar-right">
        <span class="teacher-badge">👤 <?= htmlspecialchars($teacher_name) ?></span>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="page-title">📊 Class Report</div>
    <div class="page-subtitle">SY <?= htmlspecialchars($sy_name) ?> &nbsp;·&nbsp; Financial &amp; attendance overview per section</div>

    <?php if (empty($sections)): ?>
    <div style="background:white;border-radius:12px;padding:48px;text-align:center;">
        <p style="color:#94a3b8;">No sections assigned to you yet.</p>
    </div>
    <?php else: ?>

    <!-- Section tabs -->
    <div class="section-tabs">
        <?php foreach ($sections as $sec): ?>
        <a href="?section=<?= $sec['section_id'] ?>"
           class="tab-btn <?= $sec['section_id'] == $selectedSection ? 'active' : '' ?>">
            Grade <?= htmlspecialchars($sec['grade_level']) ?> – <?= htmlspecialchars($sec['section_name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$selectedSection || empty($students)): ?>
    <div style="background:white;border-radius:12px;padding:40px;text-align:center;">
        <p style="color:#94a3b8;">No students found in this section.</p>
    </div>
    <?php else: ?>

    <!-- ── Summary cards ── -->
    <div class="report-grid">
        <div class="rcard blue">
            <h4>Total Students</h4>
            <p><?= $totalStudents ?></p>
            <small>Grade <?= htmlspecialchars($currentSection['grade_level']) ?> – <?= htmlspecialchars($currentSection['section_name']) ?></small>
        </div>
        <div class="rcard green">
            <h4>Fully Paid</h4>
            <p><?= $totalPaid ?></p>
            <small><?= $totalStudents > 0 ? round(($totalPaid/$totalStudents)*100,1) : 0 ?>% of section</small>
        </div>
        <div class="rcard red">
            <h4>With Balance</h4>
            <p><?= $totalPending ?></p>
            <small>₱<?= number_format($totalBalance, 2) ?> total due</small>
        </div>
        <div class="rcard amber">
            <h4>Collection Rate</h4>
            <p><?= $collectionRate ?>%</p>
            <div class="rate-bar"><div class="rate-fill" style="width:<?= $collectionRate ?>%"></div></div>
        </div>
        <div class="rcard purple">
            <h4>Attendance Rate</h4>
            <p><?= $attendanceRate ?>%</p>
            <small><?= $attSummary['total_days'] ?? 0 ?> sessions recorded</small>
        </div>
        <div class="rcard green">
            <h4>Total Collected</h4>
            <p style="font-size:16px;">₱<?= number_format($totalCollected, 2) ?></p>
            <small>of ₱<?= number_format($totalAssessed, 2) ?> assessed</small>
        </div>
    </div>

    <!-- ── Charts ── -->
    <div class="chart-row">
        <div class="chart-box">
            <h4>Payment Status</h4>
            <div style="height:200px;position:relative;">
                <canvas id="statusChart" role="img" aria-label="Paid vs pending donut chart"></canvas>
            </div>
        </div>
        <div class="chart-box">
            <h4>Monthly Collections</h4>
            <div style="height:200px;position:relative;">
                <canvas id="trendChart" role="img" aria-label="Monthly collection bar chart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── Student table ── -->
    <div class="toolbar">
        <input type="text" id="searchInput" placeholder="🔍 Search student…" oninput="filterTable()">
        <select id="statusFilter" onchange="filterTable()" style="padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;">
            <option value="all">All Status</option>
            <option value="paid">Fully Paid</option>
            <option value="pending">With Balance</option>
        </select>
        <button class="btn-export" onclick="exportExcel()">📥 Export Excel</button>
        <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="table-container" style="max-height:500px;">
        <table class="report-table" id="reportTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th style="text-align:right;">Assessed</th>
                    <th style="text-align:right;">Paid</th>
                    <th style="text-align:right;">Balance</th>
                    <th>Payment</th>
                    <th>Attendance</th>
                </tr>
            </thead>
            <tbody id="reportTbody">
            <?php foreach ($students as $i => $s):
                // Get per-student attendance
                $attStmt = $conn->prepare("
                    SELECT SUM(status='present') AS p, SUM(status='absent') AS a,
                           SUM(status='late') AS l, COUNT(*) AS total
                    FROM attendance WHERE student_id = ? AND section_id = ? AND teacher_id = ?
                ");
                $attStmt->bind_param('iii', $s['student_id'], $selectedSection, $teacher_id);
                $attStmt->execute();
                $sAtt = $attStmt->get_result()->fetch_assoc();
                $sRate = ($sAtt['total'] > 0) ? round(($sAtt['p'] / $sAtt['total']) * 100) : null;
                $attColor = $sRate === null ? '#94a3b8' : ($sRate >= 80 ? '#198754' : ($sRate >= 60 ? '#d97706' : '#dc2626'));
                $isConcern = ($s['balance'] > 0 && $s['balance'] > ($s['assessed'] * 0.5));
            ?>
            <tr data-status="<?= strtolower($s['status']) ?>"
                data-search="<?= htmlspecialchars(strtolower($s['full_name'] . ' ' . ($s['student_number'] ?? ''))) ?>">
                <td style="color:#94a3b8;font-size:12px;"><?= $i + 1 ?></td>
                <td style="font-weight:600;">
                    <?= htmlspecialchars($s['full_name']) ?>
                    <?php if ($isConcern): ?><span class="concern-flag" title="More than 50% balance remaining"> !</span><?php endif; ?>
                </td>
                <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($s['student_number'] ?? '—') ?></td>
                <td style="text-align:right;">₱<?= number_format($s['assessed'], 2) ?></td>
                <td style="text-align:right;color:#198754;">₱<?= number_format($s['paid'], 2) ?></td>
                <td style="text-align:right;font-weight:700;color:<?= $s['balance'] > 0 ? '#dc2626' : '#198754' ?>;">
                    ₱<?= number_format($s['balance'], 2) ?>
                </td>
                <td><span class="badge-<?= strtolower($s['status']) ?>"><?= $s['status'] ?></span></td>
                <td>
                    <?php if ($sRate !== null): ?>
                    <span style="font-weight:700;color:<?= $attColor ?>;"><?= $sRate ?>%</span>
                    <span style="font-size:11px;color:#94a3b8;margin-left:4px;">(<?= $sAtt['p'] ?>/<?= $sAtt['total'] ?>)</span>
                    <?php else: ?>
                    <span style="font-size:12px;color:#94a3b8;">No data</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div id="tableCount" style="padding:8px 14px;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;"></div>
    </div>

    <!-- ── Concern students callout ── -->
    <?php
    $concerns = array_filter($students, fn($s) => $s['balance'] > 0 && $s['assessed'] > 0 && ($s['balance'] / $s['assessed']) > 0.5);
    ?>
    <?php if (!empty($concerns)): ?>
    <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:16px 20px;margin-top:20px;">
        <div style="font-size:14px;font-weight:700;color:#991b1b;margin-bottom:8px;">
            ⚠ <?= count($concerns) ?> student<?= count($concerns) !== 1 ? 's' : '' ?> with more than 50% outstanding balance
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($concerns as $c): ?>
        <span style="background:#fee2e2;color:#991b1b;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            <?= htmlspecialchars($c['full_name']) ?> — ₱<?= number_format($c['balance'], 2) ?>
        </span>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// ── Filter ────────────────────────────────────────────────────────
const rows = Array.from(document.querySelectorAll('#reportTbody tr'));
function filterTable() {
    const q      = document.getElementById('searchInput').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    let visible  = 0;
    rows.forEach(r => {
        const ok = (status === 'all' || r.dataset.status === status)
                && (!q || r.dataset.search.includes(q));
        r.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    const el = document.getElementById('tableCount');
    if (el) el.textContent = `Showing ${visible} student${visible !== 1 ? 's' : ''}`;
}
filterTable();

// ── Charts ────────────────────────────────────────────────────────
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Fully Paid', 'With Balance'],
        datasets: [{ data: [<?= $totalPaid ?>, <?= $totalPending ?>],
            backgroundColor: ['#198754', '#dc2626'], borderWidth: 0 }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: { legend: { position: 'bottom', labels: { font: { size: 12 } } } }
    }
});

new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($trend, 'month_label')) ?>,
        datasets: [{ label: 'Collections (₱)',
            data: <?= json_encode(array_column($trend, 'total')) ?>,
            backgroundColor: '#0f6e56', borderRadius: 5 }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 30 } }
        }
    }
});

// ── Export Excel ──────────────────────────────────────────────────
function exportExcel() {
    const headers = ['#','Student Name','Student ID','Assessed','Paid','Balance','Payment Status','Attendance Rate'];
    const data    = [headers];
    rows.forEach((r, i) => {
        if (r.style.display === 'none') return;
        const c = r.querySelectorAll('td');
        data.push([
            i + 1,
            c[1].textContent.replace('!','').trim(),
            c[2].textContent.trim(),
            c[3].textContent.trim(),
            c[4].textContent.trim(),
            c[5].textContent.trim(),
            c[6].textContent.trim(),
            c[7].textContent.trim()
        ]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:4},{wch:28},{wch:14},{wch:14},{wch:14},{wch:14},{wch:14},{wch:14}];
    XLSX.utils.book_append_sheet(wb, ws, 'Class Report');
    XLSX.writeFile(wb, 'ClassReport_<?= addslashes($currentSection['section_name'] ?? 'Section') ?>_<?= date('Ymd') ?>.xlsx');
}
</script>
</body>
</html>