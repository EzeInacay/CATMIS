<?php
session_start();
include 'php/config.php';
include 'php/get_balance.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacher_user_id = $_SESSION['user_id'];

// ── Get teacher record & name ────────────────────────────────────
$tStmt = $conn->prepare("
    SELECT t.teacher_id, u.full_name, u.email
    FROM teachers t JOIN users u ON t.user_id = u.user_id
    WHERE t.user_id = ?
");
$tStmt->bind_param('i', $teacher_user_id);
$tStmt->execute();
$teacher = $tStmt->get_result()->fetch_assoc();
$teacher_id   = $teacher['teacher_id'] ?? 0;
$teacher_name = $teacher['full_name']  ?? 'Teacher';

// ── Get active school year ───────────────────────────────────────
$syRow = $conn->query("SELECT sy_id, name FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
$sy_id   = $syRow['sy_id']  ?? 0;
$sy_name = $syRow['name']   ?? '—';

// ── Get all sections assigned to this teacher ────────────────────
$secStmt = $conn->prepare("
    SELECT section_id, section_name, grade_level
    FROM sections
    WHERE teacher_id = ? AND sy_id = ?
    ORDER BY grade_level ASC, section_name ASC
");
$secStmt->bind_param('ii', $teacher_id, $sy_id);
$secStmt->execute();
$sections = $secStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Build section_ids list for queries ───────────────────────────
$sectionIds = array_column($sections, 'section_id');

// ── Load all students across teacher's sections ──────────────────
$students = [];
$totalStudents = 0;
$totalPaid     = 0;
$totalPending  = 0;
$totalBalance  = 0;

if (!empty($sectionIds)) {
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $types        = str_repeat('i', count($sectionIds));

    $sStmt = $conn->prepare("
        SELECT
            s.student_id, s.section_id, s.grade_level, s.section,
            u.full_name, u.student_number, u.email,
            ta.account_id
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        JOIN tuition_accounts ta ON s.student_id = ta.student_id
        WHERE s.section_id IN ($placeholders)
        ORDER BY s.section_id, u.full_name ASC
    ");
    $sStmt->bind_param($types, ...$sectionIds);
    $sStmt->execute();
    $rawStudents = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rawStudents as $row) {
        $bal = getBalance($conn, $row['account_id']);
        $row['balance'] = $bal;
        $row['status']  = $bal <= 0 ? 'Paid' : 'Pending';
        $students[]     = $row;
        $totalStudents++;
        $totalBalance += $bal;
        if ($bal <= 0) $totalPaid++;
        else           $totalPending++;
    }
}

// ── AJAX: fetch ledger for a student ────────────────────────────
if (isset($_GET['ledger_for'])) {
    header('Content-Type: application/json');
    $account_id = intval($_GET['ledger_for']);

    // Security: verify this account belongs to one of teacher's sections
    if (!empty($sectionIds)) {
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $types        = str_repeat('i', count($sectionIds));
        $chk = $conn->prepare("
            SELECT ta.account_id FROM tuition_accounts ta
            JOIN students s ON ta.student_id = s.student_id
            WHERE ta.account_id = ? AND s.section_id IN ($placeholders)
        ");
        $chk->bind_param('i' . $types, $account_id, ...$sectionIds);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            echo json_encode(['error' => 'Access denied.']); exit;
        }
    }

    $lStmt = $conn->prepare("
        SELECT sl.entry_type, sl.amount, sl.remarks, sl.created_at,
               tf.label AS fee_label
        FROM student_ledgers sl
        LEFT JOIN tuition_fees tf ON sl.fee_id = tf.fee_id
        WHERE sl.account_id = ?
        ORDER BY sl.created_at ASC
    ");
    $lStmt->bind_param('i', $account_id);
    $lStmt->execute();
    $ledger = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Compute running balance
    $running = 0;
    foreach ($ledger as &$entry) {
        if (in_array($entry['entry_type'], ['CHARGE', 'PENALTY'])) $running += $entry['amount'];
        else $running -= $entry['amount'];
        $entry['running'] = max(0, round($running, 2));
    }
    echo json_encode(['ledger' => $ledger, 'balance' => getBalance($conn, $account_id)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<link href="css/teacherd.css" rel="stylesheet">
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav class="navbar">
    <a href="teacher_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="teacher_dashboard.php" class="active">🏠 My Dashboard</a>
        <a href="teacher_attendance.php">📋 Attendance</a>
        <a href="teacher_class_report.php">📊 Class Report</a>
    </div>
    <div class="navbar-right">
        <span class="teacher-badge">👤 <?= htmlspecialchars($teacher_name) ?></span>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="main">
    <div class="page-title">Teacher Dashboard</div>
    <div class="page-subtitle">
        SY <?= htmlspecialchars($sy_name) ?> &nbsp;·&nbsp;
        <?= count($sections) ?> section<?= count($sections) !== 1 ? 's' : '' ?> assigned
        &nbsp;·&nbsp; Read-only view
    </div>

    <?php if (empty($sections)): ?>
    <div style="background:white;border-radius:12px;padding:40px;text-align:center;box-shadow:0 4px 10px rgba(0,0,0,0.05);">
        <p style="color:#94a3b8;font-size:15px;">No sections have been assigned to you yet.<br>Please contact your administrator.</p>
    </div>
    <?php else: ?>

    <!-- Summary cards -->
    <div class="cards">
        <div class="card">
            <h4>Total Students</h4>
            <p><?= $totalStudents ?></p>
        </div>
        <div class="card blue">
            <h4>Sections</h4>
            <p><?= count($sections) ?></p>
        </div>
        <div class="card">
            <h4>Fully Paid</h4>
            <p><?= $totalPaid ?></p>
        </div>
        <div class="card red">
            <h4>With Balance</h4>
            <p><?= $totalPending ?></p>
        </div>
        <div class="card amber">
            <h4>Total Outstanding</h4>
            <p style="font-size:18px;">₱<?= number_format($totalBalance, 2) ?></p>
        </div>
    </div>

    <!-- Section tabs -->
    <div class="section-tabs">
        <button class="tab-btn active" onclick="switchSection('all', this)">All Sections</button>
        <?php foreach ($sections as $sec): ?>
        <button class="tab-btn" onclick="switchSection('<?= $sec['section_id'] ?>', this)">
            Grade <?= htmlspecialchars($sec['grade_level']) ?> – <?= htmlspecialchars($sec['section_name']) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <input type="text" class="search-box" id="searchInput" placeholder="🔍 Search student name or ID…" oninput="applyFilters()">
        <button class="btn-export" onclick="exportExcel()">📥 Export Excel</button>
    </div>

    <!-- Student table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Grade</th>
                    <th>Section</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Ledger</th>
                </tr>
            </thead>
            <tbody id="studentTable">
            <?php if (empty($students)): ?>
                <tr><td colspan="7" class="empty-msg">No students found in your sections.</td></tr>
            <?php else: ?>
                <?php foreach ($students as $s): ?>
                <tr
                    data-section="<?= $s['section_id'] ?>"
                    data-search="<?= htmlspecialchars(strtolower($s['full_name'] . ' ' . ($s['student_number'] ?? ''))) ?>"
                >
                    <td><?= htmlspecialchars($s['student_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= htmlspecialchars($s['grade_level']) ?></td>
                    <td><?= htmlspecialchars($s['section']) ?></td>
                    <td>₱<?= number_format($s['balance'], 2) ?></td>
                    <td><span class="badge-<?= strtolower($s['status']) ?>"><?= $s['status'] ?></span></td>
                    <td>
                        <button
                            style="background:#e0f2fe;color:#0369a1;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;"
                            onclick="viewLedger(<?= $s['account_id'] ?>, '<?= htmlspecialchars(addslashes($s['full_name'])) ?>')">
                            📋 View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Student ledger drill-down panel -->
    <div class="ledger-panel" id="ledgerPanel">
        <div class="ledger-header">
            <h3 id="ledgerTitle">Student Ledger</h3>
            <button class="ledger-close" onclick="closeLedger()">×</button>
        </div>
        <div class="ledger-body">
            <table id="ledgerTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th style="text-align:right">Amount</th>
                        <th style="text-align:right">Running Balance</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <tr><td colspan="5" class="empty-msg">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const allRows = Array.from(document.querySelectorAll('#studentTable tr[data-section]'));
let currentSection = 'all';

function switchSection(secId, btn) {
    currentSection = secId;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
    closeLedger();
}

function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    allRows.forEach(row => {
        const secOk    = currentSection === 'all' || row.dataset.section === currentSection;
        const searchOk = !q || row.dataset.search.includes(q);
        row.style.display = secOk && searchOk ? '' : 'none';
    });
}

async function viewLedger(accountId, name) {
    document.getElementById('ledgerTitle').textContent = 'Ledger — ' + name;
    document.getElementById('ledgerBody').innerHTML = '<tr><td colspan="5" class="empty-msg">Loading…</td></tr>';
    document.getElementById('ledgerPanel').classList.add('open');
    document.getElementById('ledgerPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    const res  = await fetch('teacher_dashboard.php?ledger_for=' + accountId);
    const data = await res.json();

    if (data.error) {
        document.getElementById('ledgerBody').innerHTML = `<tr><td colspan="5" class="empty-msg">${data.error}</td></tr>`;
        return;
    }

    const typeColors = {
        CHARGE: 'entry-CHARGE', PAYMENT: 'entry-PAYMENT',
        DISCOUNT: 'entry-DISCOUNT', PENALTY: 'entry-PENALTY', ADJUSTMENT: 'entry-ADJUSTMENT'
    };

    let html = '';
    data.ledger.forEach(e => {
        const sign  = ['PAYMENT','DISCOUNT'].includes(e.entry_type) ? '−' : '+';
        const color = ['PAYMENT','DISCOUNT'].includes(e.entry_type) ? '#198754'
                    : e.entry_type === 'PENALTY' ? '#dc2626' : '#0f2027';
        const desc  = e.fee_label || e.remarks || '—';
        const date  = new Date(e.created_at).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
        html += `<tr>
            <td style="color:#94a3b8;font-size:12px;white-space:nowrap;">${date}</td>
            <td>${desc}</td>
            <td><span class="entry-badge ${typeColors[e.entry_type] || ''}">${e.entry_type}</span></td>
            <td style="text-align:right;color:${color}">${sign}₱${parseFloat(e.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td style="text-align:right;font-weight:600;">₱${parseFloat(e.running).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
        </tr>`;
    });

    if (!html) html = '<tr><td colspan="5" class="empty-msg">No ledger entries found.</td></tr>';
    document.getElementById('ledgerBody').innerHTML = html;
}

function closeLedger() {
    document.getElementById('ledgerPanel').classList.remove('open');
}

function exportExcel() {
    const headers = ['Student ID', 'Name', 'Grade', 'Section', 'Balance', 'Status'];
    const data    = [headers];
    allRows.forEach(row => {
        if (row.style.display === 'none') return;
        const c = row.querySelectorAll('td');
        data.push([c[0].textContent.trim(), c[1].textContent.trim(),
                   c[2].textContent.trim(), c[3].textContent.trim(),
                   c[4].textContent.trim(), c[5].textContent.trim()]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:14},{wch:28},{wch:8},{wch:16},{wch:14},{wch:10}];
    XLSX.utils.book_append_sheet(wb, ws, 'My Students');
    XLSX.writeFile(wb, `CATMIS_MyStudents_${new Date().toISOString().slice(0,10)}.xlsx`);
}
</script>
</body>
</html>