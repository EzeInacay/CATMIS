<?php
session_start();
include 'php/config.php';

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

// ── Sections assigned to this teacher ────────────────────────────
$secStmt = $conn->prepare("
    SELECT section_id, section_name, grade_level
    FROM sections
    WHERE teacher_id = ? AND sy_id = ?
    ORDER BY grade_level ASC, section_name ASC
");
$secStmt->bind_param('ii', $teacher_id, $sy_id);
$secStmt->execute();
$sections   = $secStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sectionIds = array_column($sections, 'section_id');

// ── Selected section & date ───────────────────────────────────────
$selectedSection = intval($_GET['section'] ?? ($sectionIds[0] ?? 0));
$selectedDate    = $_GET['date'] ?? date('Y-m-d');

// Validate selected section belongs to teacher
if ($selectedSection && !in_array($selectedSection, $sectionIds)) {
    $selectedSection = $sectionIds[0] ?? 0;
}

// ── AJAX: Save attendance ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    header('Content-Type: application/json');

    $section_id = intval($_POST['section_id'] ?? 0);
    $date       = $_POST['date'] ?? '';
    $records    = $_POST['records'] ?? []; // [{student_id, status, remarks}]

    if (!in_array($section_id, $sectionIds)) {
        echo json_encode(['error' => 'Access denied.']); exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Invalid date.']); exit;
    }
    if (empty($records)) {
        echo json_encode(['error' => 'No records to save.']); exit;
    }

    $saved = 0;
    foreach ($records as $rec) {
        $student_id = intval($rec['student_id'] ?? 0);
        $status     = trim($rec['status'] ?? 'present');
        $remarks    = trim($rec['remarks'] ?? '');

        if (!in_array($status, ['present','absent','late','excused'])) $status = 'present';
        if (!$student_id) continue;

        // Upsert — update if already saved for this date
        $upsert = $conn->prepare("
            INSERT INTO attendance (student_id, section_id, teacher_id, date, status, remarks)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status=VALUES(status), remarks=VALUES(remarks)
        ");
        $upsert->bind_param('iiiiss', $student_id, $section_id, $teacher_id, $date, $status, $remarks);
        $upsert->execute();
        $saved++;
    }

    // Audit log
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $act = "Saved attendance for section #{$section_id} on {$date} ({$saved} students)";
    $log->bind_param('is', $teacher_user_id, $act);
    $log->execute();

    echo json_encode(['success' => true, 'saved' => $saved]);
    exit;
}

// ── AJAX: Get attendance summary for a section (calendar heatmap) ─
if (isset($_GET['summary_for'])) {
    header('Content-Type: application/json');
    $section_id = intval($_GET['summary_for']);
    if (!in_array($section_id, $sectionIds)) { echo json_encode([]); exit; }

    $rows = $conn->prepare("
        SELECT date,
               SUM(status='present') AS present,
               SUM(status='absent')  AS absent,
               SUM(status='late')    AS late,
               SUM(status='excused') AS excused,
               COUNT(*)              AS total
        FROM attendance
        WHERE section_id = ? AND teacher_id = ?
        GROUP BY date
        ORDER BY date DESC
        LIMIT 60
    ");
    $rows->bind_param('ii', $section_id, $teacher_id);
    $rows->execute();
    echo json_encode($rows->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// ── Load students for selected section ───────────────────────────
$students = [];
if ($selectedSection) {
    $sStmt = $conn->prepare("
        SELECT s.student_id, u.full_name, u.student_number
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.section_id = ?
        ORDER BY u.full_name ASC
    ");
    $sStmt->bind_param('i', $selectedSection);
    $sStmt->execute();
    $students = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Load existing attendance for selected section + date ──────────
$existing = [];
if ($selectedSection && $selectedDate) {
    $aStmt = $conn->prepare("
        SELECT student_id, status, remarks
        FROM attendance
        WHERE section_id = ? AND date = ? AND teacher_id = ?
    ");
    $aStmt->bind_param('isi', $selectedSection, $selectedDate, $teacher_id);
    $aStmt->execute();
    foreach ($aStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $existing[$row['student_id']] = $row;
    }
}

// ── Get section name for display ──────────────────────────────────
$currentSection = null;
foreach ($sections as $sec) {
    if ($sec['section_id'] == $selectedSection) { $currentSection = $sec; break; }
}

// ── Recent attendance log (last 10 sessions for selected section) ─
$recentLog = [];
if ($selectedSection) {
    $rStmt = $conn->prepare("
        SELECT date,
               SUM(status='present') AS present,
               SUM(status='absent')  AS absent,
               SUM(status='late')    AS late,
               COUNT(*)              AS total
        FROM attendance
        WHERE section_id = ? AND teacher_id = ?
        GROUP BY date ORDER BY date DESC LIMIT 10
    ");
    $rStmt->bind_param('ii', $selectedSection, $teacher_id);
    $rStmt->execute();
    $recentLog = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<link href="css/teacherd.css" rel="stylesheet">
<style>
.date-bar { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.date-bar label { font-size:13px; color:#64748b; font-weight:600; }
.date-input { padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:7px; font-size:14px; font-family:inherit; cursor:pointer; }
.date-input:focus { outline:none; border-color:#0f6e56; box-shadow:0 0 0 3px rgba(15,110,86,0.1); }
.btn-save { padding:9px 22px; background:#0f6e56; color:white; border:none; border-radius:7px; cursor:pointer; font-size:14px; font-family:inherit; font-weight:600; transition:background 0.18s; }
.btn-save:hover { background:#0a5242; }
.btn-save:disabled { background:#94a3b8; cursor:not-allowed; }

.att-table { width:100%; border-collapse:collapse; }
.att-table th { background:#f2f4f7; padding:11px 14px; text-align:left; font-size:13px; font-weight:600; color:#374151; position:sticky; top:0; z-index:1; }
.att-table td { padding:10px 14px; font-size:14px; border-bottom:1px solid #f1f5f9; color:#0f2027; }
.att-table tr:last-child td { border-bottom:none; }
.att-table tr.row-absent  td { background:#fff5f5; }
.att-table tr.row-late    td { background:#fffbeb; }
.att-table tr.row-excused td { background:#f0f9ff; }

.status-btns { display:flex; gap:6px; flex-wrap:wrap; }
.stn { padding:5px 12px; border:1.5px solid #cbd5e1; border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; background:white; font-family:inherit; transition:all 0.15s; }
.stn:hover { border-color:#64748b; }
.stn.active-present { background:#d1fae5; color:#065f46; border-color:#6ee7b7; }
.stn.active-absent  { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
.stn.active-late    { background:#fef3c7; color:#92400e; border-color:#fcd34d; }
.stn.active-excused { background:#e0f2fe; color:#0369a1; border-color:#7dd3fc; }

.remarks-input { padding:5px 10px; border:1px solid #e2e8f0; border-radius:5px; font-size:12px; font-family:inherit; width:160px; }
.remarks-input:focus { outline:none; border-color:#0f6e56; }

.summary-strip { display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
.ss-card { flex:1; min-width:100px; background:white; border-radius:8px; padding:12px 14px; box-shadow:0 2px 6px rgba(0,0,0,0.05); text-align:center; border-top:3px solid #e2e8f0; }
.ss-card.green  { border-color:#198754; }
.ss-card.red    { border-color:#dc2626; }
.ss-card.amber  { border-color:#d97706; }
.ss-card.blue   { border-color:#0077b6; }
.ss-card .ss-num  { font-size:22px; font-weight:700; color:#0f2027; }
.ss-card .ss-lbl  { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; margin-top:2px; }

.quick-mark { display:flex; gap:8px; margin-bottom:12px; align-items:center; flex-wrap:wrap; }
.quick-mark span { font-size:13px; color:#64748b; font-weight:600; }
.qbtn { padding:6px 14px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:white; font-family:inherit; transition:all 0.15s; }
.qbtn:hover { background:#f8fafc; }

.log-table { width:100%; border-collapse:collapse; }
.log-table th { background:#f8fafc; padding:9px 12px; font-size:12px; font-weight:600; color:#374151; text-align:left; }
.log-table td { padding:9px 12px; font-size:13px; border-bottom:1px solid #f1f5f9; }
.log-table tr:last-child td { border-bottom:none; }

.toast { position:fixed; bottom:28px; right:28px; background:#0f2027; color:white; padding:12px 22px; border-radius:10px; font-size:14px; z-index:999; opacity:0; transform:translateY(10px); transition:all 0.3s; pointer-events:none; }
.toast.show { opacity:1; transform:translateY(0); }

.two-col { display:grid; grid-template-columns:1fr 340px; gap:20px; align-items:start; }
.panel { background:white; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.05); overflow:hidden; }
.panel-head { background:#0f2027; color:white; padding:14px 18px; font-size:14px; font-weight:600; }

@media (max-width:900px) { .two-col { grid-template-columns:1fr; } }
</style>
</head>
<body>

<nav class="navbar">
    <a href="teacher_dashboard.php" class="navbar-brand"><h2>CATMIS</h2><span>CCS Portal</span></a>
    <div class="navbar-links">
        <a href="teacher_dashboard.php">🏠 My Dashboard</a>
        <a href="teacher_attendance.php" class="active">📋 Attendance</a>
        <a href="teacher_class_report.php">📊 Class Report</a>
    </div>
    <div class="navbar-right">
        <span class="teacher-badge">👤 <?= htmlspecialchars($teacher_name) ?></span>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="page-title">📋 Attendance</div>
    <div class="page-subtitle">SY <?= htmlspecialchars($sy_name) ?> &nbsp;·&nbsp; Mark and review daily attendance</div>

    <?php if (empty($sections)): ?>
    <div style="background:white;border-radius:12px;padding:48px;text-align:center;box-shadow:0 4px 10px rgba(0,0,0,0.05);">
        <p style="color:#94a3b8;font-size:15px;">No sections assigned to you yet. Contact your administrator.</p>
    </div>
    <?php else: ?>

    <!-- Section tabs -->
    <div class="section-tabs">
        <?php foreach ($sections as $sec): ?>
        <a href="?section=<?= $sec['section_id'] ?>&date=<?= htmlspecialchars($selectedDate) ?>"
           class="tab-btn <?= $sec['section_id'] == $selectedSection ? 'active' : '' ?>">
            Grade <?= htmlspecialchars($sec['grade_level']) ?> – <?= htmlspecialchars($sec['section_name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Date bar -->
    <div class="date-bar">
        <label>📅 Date:</label>
        <input type="date" class="date-input" id="dateInput"
               value="<?= htmlspecialchars($selectedDate) ?>"
               max="<?= date('Y-m-d') ?>"
               onchange="changeDate(this.value)">
        <span style="font-size:13px;color:#94a3b8;">
            <?= date('l, F j, Y', strtotime($selectedDate)) ?>
        </span>
        <?php if (!empty($existing)): ?>
        <span style="background:#d1fae5;color:#065f46;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;">
            ✓ Already saved
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($students)): ?>
    <div style="background:white;border-radius:12px;padding:40px;text-align:center;">
        <p style="color:#94a3b8;">No students in this section yet.</p>
    </div>
    <?php else: ?>

    <div class="two-col">
        <!-- LEFT: Attendance sheet -->
        <div>
            <!-- Live summary strip -->
            <div class="summary-strip">
                <div class="ss-card green"><div class="ss-num" id="cntPresent">0</div><div class="ss-lbl">Present</div></div>
                <div class="ss-card red"><div class="ss-num" id="cntAbsent">0</div><div class="ss-lbl">Absent</div></div>
                <div class="ss-card amber"><div class="ss-num" id="cntLate">0</div><div class="ss-lbl">Late</div></div>
                <div class="ss-card blue"><div class="ss-num" id="cntExcused">0</div><div class="ss-lbl">Excused</div></div>
                <div class="ss-card"><div class="ss-num"><?= count($students) ?></div><div class="ss-lbl">Total</div></div>
            </div>

            <!-- Quick mark all -->
            <div class="quick-mark">
                <span>Mark all:</span>
                <button class="qbtn" onclick="markAll('present')">✅ All Present</button>
                <button class="qbtn" onclick="markAll('absent')">❌ All Absent</button>
                <button class="qbtn" onclick="resetAll()">↺ Reset</button>
                <button class="btn-export" onclick="exportExcel()" style="margin-left:auto;">📥 Export</button>
            </div>

            <!-- Attendance table -->
            <div class="table-container" style="max-height:520px;">
                <table class="att-table" id="attTable">
                    <thead>
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>Student Name</th>
                            <th>ID</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $i => $s):
                        $saved  = $existing[$s['student_id']] ?? null;
                        $status = $saved['status']  ?? 'present';
                        $rem    = $saved['remarks'] ?? '';
                    ?>
                    <tr id="row-<?= $s['student_id'] ?>" class="row-<?= $status !== 'present' ? $status : '' ?>">
                        <td style="color:#94a3b8;font-size:12px;"><?= $i + 1 ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($s['student_number'] ?? '—') ?></td>
                        <td>
                            <div class="status-btns" data-student="<?= $s['student_id'] ?>">
                                <button class="stn <?= $status === 'present' ? 'active-present' : '' ?>" onclick="setStatus(<?= $s['student_id'] ?>, 'present', this)">Present</button>
                                <button class="stn <?= $status === 'absent'  ? 'active-absent'  : '' ?>" onclick="setStatus(<?= $s['student_id'] ?>, 'absent',  this)">Absent</button>
                                <button class="stn <?= $status === 'late'    ? 'active-late'    : '' ?>" onclick="setStatus(<?= $s['student_id'] ?>, 'late',    this)">Late</button>
                                <button class="stn <?= $status === 'excused' ? 'active-excused' : '' ?>" onclick="setStatus(<?= $s['student_id'] ?>, 'excused', this)">Excused</button>
                            </div>
                        </td>
                        <td>
                            <input class="remarks-input" type="text"
                                   id="rem-<?= $s['student_id'] ?>"
                                   value="<?= htmlspecialchars($rem) ?>"
                                   placeholder="optional note…"
                                   data-student="<?= $s['student_id'] ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
                <button class="btn-save" id="saveBtn" onclick="saveAttendance()">💾 Save Attendance</button>
                <span id="saveStatus" style="font-size:13px;color:#64748b;"></span>
            </div>
        </div>

        <!-- RIGHT: Recent log -->
        <div>
            <div class="panel">
                <div class="panel-head">📅 Recent Sessions — <?= htmlspecialchars($currentSection['section_name'] ?? '') ?></div>
                <div style="padding:14px;max-height:520px;overflow-y:auto;">
                <?php if (empty($recentLog)): ?>
                    <p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px 0;">No attendance recorded yet.</p>
                <?php else: ?>
                    <table class="log-table">
                        <thead><tr><th>Date</th><th>P</th><th>A</th><th>L</th><th>Rate</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentLog as $log):
                            $rate = $log['total'] > 0 ? round(($log['present'] / $log['total']) * 100) : 0;
                            $rateColor = $rate >= 80 ? '#198754' : ($rate >= 60 ? '#d97706' : '#dc2626');
                        ?>
                        <tr>
                            <td>
                                <a href="?section=<?= $selectedSection ?>&date=<?= $log['date'] ?>"
                                   style="color:#0369a1;text-decoration:none;font-size:12px;">
                                   <?= date('M d, Y', strtotime($log['date'])) ?>
                                   <?= $log['date'] === $selectedDate ? '<span style="background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:10px;font-size:10px;margin-left:4px;">today</span>' : '' ?>
                                </a>
                            </td>
                            <td style="color:#198754;font-weight:600;"><?= $log['present'] ?></td>
                            <td style="color:#dc2626;font-weight:600;"><?= $log['absent'] ?></td>
                            <td style="color:#d97706;font-weight:600;"><?= $log['late'] ?></td>
                            <td><span style="font-weight:700;color:<?= $rateColor ?>;"><?= $rate ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </div>
            </div>

            <!-- Student attendance stats for this section -->
            <div class="panel" style="margin-top:16px;">
                <div class="panel-head">📊 Student Totals (This Section)</div>
                <div style="padding:14px;max-height:300px;overflow-y:auto;">
                <?php
                if ($selectedSection) {
                    $totStmt = $conn->prepare("
                        SELECT u.full_name,
                               SUM(a.status='present') AS present,
                               SUM(a.status='absent')  AS absent,
                               SUM(a.status='late')    AS late,
                               COUNT(*)                AS total
                        FROM attendance a
                        JOIN students s ON a.student_id = s.student_id
                        JOIN users u ON s.user_id = u.user_id
                        WHERE a.section_id = ? AND a.teacher_id = ?
                        GROUP BY a.student_id, u.full_name
                        ORDER BY absent DESC, u.full_name ASC
                    ");
                    $totStmt->bind_param('ii', $selectedSection, $teacher_id);
                    $totStmt->execute();
                    $studentTotals = $totStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                ?>
                <?php if (empty($studentTotals)): ?>
                    <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px 0;">No data yet.</p>
                <?php else: ?>
                    <table class="log-table">
                        <thead><tr><th>Student</th><th>P</th><th>A</th><th>L</th></tr></thead>
                        <tbody>
                        <?php foreach ($studentTotals as $st):
                            $absColor = $st['absent'] >= 3 ? '#dc2626' : '#0f2027';
                        ?>
                        <tr>
                            <td style="font-size:12px;"><?= htmlspecialchars($st['full_name']) ?></td>
                            <td style="color:#198754;font-weight:600;"><?= $st['present'] ?></td>
                            <td style="color:<?= $absColor ?>;font-weight:600;"><?= $st['absent'] ?></td>
                            <td style="color:#d97706;font-weight:600;"><?= $st['late'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
const studentStatus = {};

// ── Init from PHP ─────────────────────────────────────────────────
<?php foreach ($students as $s):
    $saved  = $existing[$s['student_id']] ?? null;
    $status = $saved['status'] ?? 'present';
?>
studentStatus[<?= $s['student_id'] ?>] = '<?= $status ?>';
<?php endforeach; ?>

updateCounts();

function setStatus(studentId, status, btn) {
    studentStatus[studentId] = status;

    // Update button styles
    const wrap = btn.closest('.status-btns');
    wrap.querySelectorAll('.stn').forEach(b => b.className = 'stn');
    btn.classList.add('active-' + status);

    // Update row color
    const row = document.getElementById('row-' + studentId);
    row.className = status !== 'present' ? 'row-' + status : '';

    updateCounts();
}

function markAll(status) {
    document.querySelectorAll('.status-btns').forEach(wrap => {
        const studentId = parseInt(wrap.dataset.student);
        const btn = wrap.querySelector('.stn:nth-child(' +
            ({present:1, absent:2, late:3, excused:4}[status]) + ')');
        if (btn) setStatus(studentId, status, btn);
    });
}

function resetAll() {
    document.querySelectorAll('.status-btns').forEach(wrap => {
        const studentId = parseInt(wrap.dataset.student);
        const btn = wrap.querySelector('.stn:first-child');
        if (btn) setStatus(studentId, 'present', btn);
    });
    document.querySelectorAll('.remarks-input').forEach(i => i.value = '');
}

function updateCounts() {
    const counts = { present:0, absent:0, late:0, excused:0 };
    Object.values(studentStatus).forEach(s => { if (counts[s] !== undefined) counts[s]++; });
    document.getElementById('cntPresent').textContent = counts.present;
    document.getElementById('cntAbsent').textContent  = counts.absent;
    document.getElementById('cntLate').textContent    = counts.late;
    document.getElementById('cntExcused').textContent = counts.excused;
}

async function saveAttendance() {
    const btn = document.getElementById('saveBtn');
    btn.textContent = '⏳ Saving…'; btn.disabled = true;

    const records = Object.entries(studentStatus).map(([id, status]) => ({
        student_id: id,
        status,
        remarks: document.getElementById('rem-' + id)?.value || ''
    }));

    const body = new FormData();
    body.append('action', 'save_attendance');
    body.append('section_id', '<?= $selectedSection ?>');
    body.append('date', document.getElementById('dateInput').value);
    records.forEach((r, i) => {
        body.append('records[' + i + '][student_id]', r.student_id);
        body.append('records[' + i + '][status]', r.status);
        body.append('records[' + i + '][remarks]', r.remarks);
    });

    const res  = await fetch('teacher_attendance.php', { method:'POST', body });
    const data = await res.json();
    btn.textContent = '💾 Save Attendance'; btn.disabled = false;

    if (data.error) showToast('⚠ ' + data.error, true);
    else {
        showToast('✓ Attendance saved for ' + data.saved + ' students!');
        document.getElementById('saveStatus').textContent = 'Last saved: ' + new Date().toLocaleTimeString();
    }
}

function changeDate(val) {
    window.location.href = '?section=<?= $selectedSection ?>&date=' + val;
}

function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = isError ? '#dc2626' : '#0f2027';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

function exportExcel() {
    const headers = ['#', 'Student Name', 'Student ID', 'Status', 'Remarks'];
    const data = [headers];
    document.querySelectorAll('#attTable tbody tr').forEach((row, i) => {
        const id = parseInt(row.id.replace('row-', ''));
        const cells = row.querySelectorAll('td');
        data.push([
            i + 1,
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            studentStatus[id] || 'present',
            document.getElementById('rem-' + id)?.value || ''
        ]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:4},{wch:28},{wch:14},{wch:10},{wch:24}];
    XLSX.utils.book_append_sheet(wb, ws, 'Attendance');
    XLSX.writeFile(wb, 'Attendance_<?= addslashes($currentSection['section_name'] ?? '') ?>_<?= $selectedDate ?>.xlsx');
}
</script>
</body>
</html>