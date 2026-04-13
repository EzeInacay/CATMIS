<?php
include 'php/config.php';
include 'php/get_balance.php';

// ── Dynamic: count pending payments (students with balance > 0) ──
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

// ── Dynamic: total receivables ──
$totalRes = $conn->query("
    SELECT
        SUM(CASE WHEN entry_type='CHARGE'  THEN amount ELSE 0 END) -
        SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) AS total_receivables
    FROM student_ledgers
");
$totalData = $totalRes->fetch_assoc();
$totalReceivables = $totalData['total_receivables'] ?? 0;

// ── Dynamic: active debtors (same as pending count) ──
$activeDebtors = $pendingCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Finance & Assessment Dashboard | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
/* ===== RESET & BASE ===== */
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #eef1f4;
}

/* ===== SIDEBAR ===== */
.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    background: linear-gradient(to bottom, #0f2027, #203a43);
    color: white;
    display: flex;
    flex-direction: column;
    z-index: 100;
}
.sidebar-header {
    padding: 25px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sidebar-header h2 { margin: 0; }
.sidebar-header p { margin: 5px 0 0; font-size: 14px; opacity: 0.8; }

.sidebar ul { list-style: none; padding: 0; margin: 20px 0; }
.sidebar ul li { padding: 12px 25px; }
.sidebar ul li a { color: white; text-decoration: none; display: block; }
.sidebar ul li:hover { background: rgba(255,255,255,0.1); cursor: pointer; }
.active { background: rgba(255,255,255,0.15); }

.system-notif {
    margin-top: auto;
    padding: 20px 25px;
    font-size: 14px;
}
.system-notif span { color: #ff3b30; }

.logout-btn {
    background: #ff3b30;
    border: none;
    color: white;
    padding: 12px;
    width: 100%;
    cursor: pointer;
    font-size: 15px;
}

/* ===== MAIN ===== */
.main { margin-left: 250px; padding: 30px; }
.title { font-size: 26px; font-weight: bold; }

/* ===== CARDS ===== */
.cards { display: flex; gap: 20px; margin-top: 20px; }
.card {
    flex: 1;
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    border-left: 6px solid #0077b6;
}
.card h4 { margin: 0 0 8px; color: #6c757d; font-size: 14px; }
.card p { font-size: 28px; margin: 0; font-weight: bold; color: #0f2027; }

/* ===== SECTION TITLE ===== */
.section-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 40px;
    margin-bottom: 10px;
}
.section-title-row h3 { margin: 0; }

/* ===== SEARCH + EXPORT ROW ===== */
.search-export-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 20px;
}
.search-export-row input {
    flex: 1;
    max-width: 500px;
    padding: 10px 14px;
    border-radius: 6px;
    border: 1px solid #ccc;
    font-size: 14px;
}
.btn-export {
    padding: 10px 18px;
    background: #198754;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-export:hover { background: #157347; }

/* ===== GRADE BUTTONS ===== */
.grade-buttons { margin-top: 15px; display: flex; flex-wrap: wrap; gap: 8px; }
.grade-buttons button {
    padding: 8px 14px;
    border: none;
    background: #0077b6;
    color: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.2s;
}
.grade-buttons button:hover, .grade-buttons button.active-btn { background: #005f8e; }

/* ===== SECTION BUTTONS ===== */
.section-buttons { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; min-height: 36px; }
.section-buttons button {
    padding: 7px 12px;
    border: none;
    background: #6c757d;
    color: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.2s;
}
.section-buttons button:hover, .section-buttons button.active-sec { background: #495057; }

/* ===== TABLE ===== */
.table-container {
    background: white;
    padding: 10px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    max-height: 420px;
    overflow-y: auto;
    margin-top: 12px;
}
table { width: 100%; border-collapse: collapse; }
th, td { padding: 13px 15px; text-align: left; font-size: 14px; }
th { background: #f2f4f7; position: sticky; top: 0; z-index: 1; }
tr:nth-child(even) { background: #fafafa; }
tr:hover { background: #f0f4ff; }

.btn-payment {
    background: #0077b6;
    border: none;
    color: white;
    padding: 7px 13px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}
.btn-payment:hover { background: #005f8e; }

.badge-paid    { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.badge-pending { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

/* ===== POPUP ===== */
.popup {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    padding: 16px 20px;
    border-left: 5px solid #ff3b30;
    box-shadow: 0 4px 16px rgba(0,0,0,0.13);
    width: 280px;
    border-radius: 0 8px 8px 0;
    z-index: 999;
    transition: opacity 0.4s;
}
.popup strong { display: block; margin-bottom: 6px; }
.popup a { color: #0077b6; cursor: pointer; font-size: 13px; }
#popupBox.hidden { opacity: 0; pointer-events: none; }
</style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>CATMIS</h2>
        <p>CCS Portal</p>
    </div>
    <ul>
        <li class="active"><a href="#">🏠 Admin Dashboard</a></li>
        <li><a href="tuition_assessment.php">📂 Tuition Assessment</a></li>
        <li><a href="user_management.php">👥 User Management</a></li>
        <li><a href="payment_history.php">📄 Payment History</a></li>
        <li><a href="audit_logs.php">🕒 Audit Logs</a></li>
        <li><a href="#">💾 Backup System</a></li>
    </ul>
    <div class="system-notif">
        <strong>System Notifications</strong><br>
        <span>• <?= $pendingCount ?> Pending Payment<?= $pendingCount !== 1 ? 's' : '' ?></span>
    </div>
    <button class="logout-btn" onclick="logout()">Logout</button>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="main">
    <div class="title">Finance &amp; Assessment Dashboard</div>

    <!-- Cards -->
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

    <!-- Title + Export -->
    <div class="section-title-row">
        <h3>Tuition Ledger Overview</h3>
    </div>

    <!-- Search + Export button -->
    <div class="search-export-row">
        <input type="text" id="searchInput" placeholder="🔍  Search student name or section…" onkeyup="searchTable()">
        <button class="btn-export" onclick="exportToExcel()">
            📥 Export to Excel
        </button>
    </div>

    <!-- Grade filter buttons (all grades 1–12) -->
    <div class="grade-buttons" id="gradeButtons">
        <button class="active-btn" onclick="filterGrade('all', this)">All</button>
        <button onclick="filterGrade('1',  this)">Grade 1</button>
        <button onclick="filterGrade('2',  this)">Grade 2</button>
        <button onclick="filterGrade('3',  this)">Grade 3</button>
        <button onclick="filterGrade('4',  this)">Grade 4</button>
        <button onclick="filterGrade('5',  this)">Grade 5</button>
        <button onclick="filterGrade('6',  this)">Grade 6</button>
        <button onclick="filterGrade('7',  this)">Grade 7</button>
        <button onclick="filterGrade('8',  this)">Grade 8</button>
        <button onclick="filterGrade('9',  this)">Grade 9</button>
        <button onclick="filterGrade('10', this)">Grade 10</button>
        <button onclick="filterGrade('11', this)">Grade 11</button>
        <button onclick="filterGrade('12', this)">Grade 12</button>
    </div>

    <!-- Section buttons appear here after grade click -->
    <div class="section-buttons" id="sectionButtons"></div>

    <!-- Student Table -->
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
    SELECT
        s.student_id,
        u.full_name,
        s.grade_level,
        s.section,
        ta.account_id
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
    echo "
    <tr data-grade='{$row['grade_level']}' data-section='{$row['section']}'>
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

<!-- ===== POPUP NOTIFICATION ===== -->
<?php if ($pendingCount > 0): ?>
<div class="popup" id="popupBox">
    <strong>⚠ Pending Payments</strong>
    <?= $pendingCount ?> overdue account<?= $pendingCount !== 1 ? 's' : '' ?> detected.<br><br>
    <a onclick="dismissPopup()">Dismiss</a>
</div>
<?php endif; ?>

<script>
/* ── Globals ── */
const table       = document.getElementById('studentTable');
const allRows     = Array.from(table.querySelectorAll('tbody tr'));
let currentGrade   = 'all';
let currentSection = 'all';

/* ── Alphabetical sort (visible rows only) ── */
function sortAlphabetically() {
    const tbody = table.querySelector('tbody');
    const visible = allRows.filter(r => r.style.display !== 'none');
    visible.sort((a, b) =>
        a.cells[1].textContent.localeCompare(b.cells[1].textContent)
    );
    visible.forEach(r => tbody.appendChild(r));
}

/* ── Search ── */
function searchTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    allRows.forEach(row => {
        const name    = row.cells[1].textContent.toLowerCase();
        const section = row.cells[3].textContent.toLowerCase();
        const gradeOk = currentGrade === 'all' || row.dataset.grade === currentGrade;
        const secOk   = currentSection === 'all' || row.dataset.section === currentSection;
        row.style.display = (name.includes(q) || section.includes(q)) && gradeOk && secOk ? '' : 'none';
    });
}

/* ── Grade filter ── */
function filterGrade(grade, btn) {
    currentGrade   = grade;
    currentSection = 'all';

    // highlight active grade button
    document.querySelectorAll('#gradeButtons button').forEach(b => b.classList.remove('active-btn'));
    if (btn) btn.classList.add('active-btn');

    const sections = new Set();
    allRows.forEach(row => {
        const match = grade === 'all' || row.dataset.grade === grade;
        row.style.display = match ? '' : 'none';
        if (match) sections.add(row.dataset.section);
    });

    generateSectionButtons(sections);
    sortAlphabetically();
}

/* ── Section button generator ── */
function generateSectionButtons(sections) {
    const container = document.getElementById('sectionButtons');
    container.innerHTML = '';
    if (sections.size === 0) return;

    const allBtn = document.createElement('button');
    allBtn.textContent = 'All Sections';
    allBtn.classList.add('active-sec');
    allBtn.onclick = () => { filterSection('all', allBtn); };
    container.appendChild(allBtn);

    [...sections].sort().forEach(sec => {
        const btn = document.createElement('button');
        btn.textContent = sec;
        btn.onclick = () => filterSection(sec, btn);
        container.appendChild(btn);
    });
}

/* ── Section filter ── */
function filterSection(section, btn) {
    currentSection = section;

    document.querySelectorAll('#sectionButtons button').forEach(b => b.classList.remove('active-sec'));
    if (btn) btn.classList.add('active-sec');

    allRows.forEach(row => {
        const gradeOk = currentGrade === 'all' || row.dataset.grade === currentGrade;
        const secOk   = section === 'all' || row.dataset.section === section;
        row.style.display = gradeOk && secOk ? '' : 'none';
    });
    sortAlphabetically();
}

/* ── Export to Excel ── */
function exportToExcel() {
    // Build data array from ALL rows (not just visible) for a full export,
    // OR only visible rows — using visible here to respect active filter.
    const headers = ['Student ID', 'Student Name', 'Grade', 'Section', 'Remaining Balance', 'Status'];
    const data = [headers];

    allRows
        .filter(r => r.style.display !== 'none')
        .forEach(r => {
            data.push([
                r.cells[0].textContent.trim(),
                r.cells[1].textContent.trim(),
                r.cells[2].textContent.trim(),
                r.cells[3].textContent.trim(),
                r.cells[4].textContent.trim(),
                r.cells[5].textContent.trim(),
            ]);
        });

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);

    // Column widths
    ws['!cols'] = [
        { wch: 12 }, { wch: 28 }, { wch: 10 },
        { wch: 16 }, { wch: 20 }, { wch: 12 }
    ];

    XLSX.utils.book_append_sheet(wb, ws, 'Student Ledger');

    const date = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, `CATMIS_StudentLedger_${date}.xlsx`);
}

/* ── Popup dismiss ── */
function dismissPopup() {
    const box = document.getElementById('popupBox');
    if (box) box.classList.add('hidden');
}

/* ── Post payment ── */
function pay(account_id) {
    window.location.href = 'payment_form.php?account_id=' + account_id;
}

/* ── Logout ── */
function logout() {
    window.location.href = 'logout.php';
}

/* ── Initial sort on load ── */
window.onload = () => sortAlphabetically();
</script>
</body>
</html>
