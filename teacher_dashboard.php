<?php
session_start();
include 'php/config.php';

// Guard: must be logged in as teacher
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$teacher_id = $_SESSION['user_id'];

// Get teacher info + assigned section
$stmt = $conn->prepare("
    SELECT 
        u.full_name, 
        t.teacher_id, 
        s.section_id, 
        s.section_name, 
        s.grade_level, 
        sy.name AS academic_year
    FROM users u
    JOIN teachers t ON t.user_id = u.user_id
    LEFT JOIN sections s ON s.teacher_id = t.teacher_id
    LEFT JOIN school_years sy ON s.sy_id = sy.sy_id
    WHERE u.user_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();

// Get students in the section with their tuition status
$students = [];
if (!empty($teacher['section_id'])) {
$stmt2 = $conn->prepare("
    SELECT
        u.full_name,
        s.student_id,
        u.student_number,
        ta.account_id,

        (ta.base_fee + ta.misc_fee - ta.discount + ta.penalties) AS total_tuition,

        COALESCE(
            SUM(CASE WHEN sl.entry_type = 'CHARGE'   THEN sl.amount ELSE 0 END) -
            SUM(CASE WHEN sl.entry_type = 'PAYMENT'  THEN sl.amount ELSE 0 END) -
            SUM(CASE WHEN sl.entry_type = 'DISCOUNT' THEN sl.amount ELSE 0 END) +
            SUM(CASE WHEN sl.entry_type = 'PENALTY'  THEN sl.amount ELSE 0 END),
        0) AS balance

    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN tuition_accounts ta ON ta.student_id = s.student_id
    LEFT JOIN student_ledgers sl ON sl.account_id = ta.account_id

    WHERE s.section_id = ?
    GROUP BY s.student_id, u.full_name, u.student_number, ta.account_id, total_tuition
    ORDER BY u.full_name ASC
");
    $stmt2->bind_param('i', $teacher['section_id']);
    $stmt2->execute();
    $students = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Summary counts
$total    = count($students);
$cleared  = 0;
$pending  = 0;
$partial  = 0;

foreach ($students as $st) {
    $bal = floatval($st['balance']);
    $tot = floatval($st['total_tuition']);
    if ($bal <= 0)          $cleared++;
    elseif ($bal >= $tot)   $pending++;
    else                    $partial++;
}

// Search filter (client-side, but also PHP for URL state)
$search = trim($_GET['search'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teacher Portal | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* ── Reset & Base ──────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #eef1f4;
    min-height: 100vh;
    display: flex;
}

/* ── Sidebar ───────────────────────────────────── */
.sidebar {
    width: 220px;
    min-height: 100vh;
    background: #0f2027;
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    z-index: 100;
}

.sidebar-brand {
    padding: 28px 24px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}

.sidebar-brand .logo {
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    letter-spacing: 1px;
}

.sidebar-brand .sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 3px;
}

.sidebar-nav {
    flex: 1;
    padding: 20px 0;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 24px;
    color: #94a3b8;
    font-size: 13.5px;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    cursor: pointer;
}

.nav-item.active,
.nav-item:hover {
    background: rgba(255,255,255,0.07);
    color: #fff;
}

.nav-item svg { flex-shrink: 0; }

.sidebar-footer {
    padding: 16px 24px;
    border-top: 1px solid rgba(255,255,255,0.07);
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    text-decoration: none;
    transition: background 0.2s;
}
.logout-btn:hover { background: #b91c1c; }

/* ── Main Content ──────────────────────────────── */
.main {
    margin-left: 220px;
    flex: 1;
    padding: 32px 36px;
    min-height: 100vh;
}

/* ── Top Bar ───────────────────────────────────── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
}

.topbar-title h1 {
    font-size: 22px;
    color: #0f2027;
    font-weight: 700;
}

.topbar-title p {
    font-size: 13px;
    color: #64748b;
    margin-top: 3px;
}

.topbar-user {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border-radius: 10px;
    padding: 8px 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.topbar-user .avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: #0077b6;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    display: flex; align-items: center; justify-content: center;
}

.topbar-user .name { font-size: 13px; font-weight: 600; color: #0f2027; }
.topbar-user .role { font-size: 11px; color: #64748b; }

/* ── Section Banner ────────────────────────────── */
.section-banner {
    background: linear-gradient(135deg, #0077b6 0%, #0f2027 100%);
    border-radius: 14px;
    padding: 24px 30px;
    color: #fff;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.section-banner .sec-info h2 {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 0.5px;
}

.section-banner .sec-info p {
    font-size: 13px;
    opacity: 0.8;
    margin-top: 4px;
}

.section-banner .sec-badge {
    background: rgba(255,255,255,0.15);
    border-radius: 10px;
    padding: 12px 20px;
    text-align: center;
}

.section-banner .sec-badge .num {
    font-size: 30px;
    font-weight: 800;
}

.section-banner .sec-badge .lbl {
    font-size: 11px;
    opacity: 0.8;
    margin-top: 2px;
}

/* ── Summary Cards ─────────────────────────────── */
.summary-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.summary-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border-left: 4px solid transparent;
}

.summary-card.cleared  { border-left-color: #10b981; }
.summary-card.partial  { border-left-color: #f59e0b; }
.summary-card.pending  { border-left-color: #ef4444; }

.summary-card .label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 8px;
}

.summary-card .count {
    font-size: 28px;
    font-weight: 800;
    color: #0f2027;
}

.summary-card .desc {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
}

/* ── Search ────────────────────────────────────── */
.toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.search-wrap {
    position: relative;
    flex: 1;
    max-width: 340px;
}

.search-wrap svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.search-wrap input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    font-size: 13.5px;
    color: #0f2027;
    background: #fff;
    outline: none;
    transition: border-color 0.2s;
}

.search-wrap input:focus { border-color: #0077b6; }

.result-count {
    font-size: 13px;
    color: #64748b;
    margin-left: auto;
}

/* ── Table ─────────────────────────────────────── */
.table-wrap {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}

table {
    width: 100%;
    border-collapse: collapse;
}

thead th {
    background: #f8fafc;
    padding: 13px 20px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    text-align: left;
    border-bottom: 1px solid #f1f5f9;
}

tbody tr {
    border-bottom: 1px solid #f8fafc;
    transition: background 0.12s;
}

tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #f8fafc; }

tbody td {
    padding: 14px 20px;
    font-size: 13.5px;
    color: #0f2027;
    vertical-align: middle;
}

.student-name {
    font-weight: 600;
}

.student-num {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 2px;
}

.balance-cell {
    font-weight: 700;
    font-size: 14px;
}

.balance-cell.cleared { color: #10b981; }
.balance-cell.partial  { color: #f59e0b; }
.balance-cell.overdue  { color: #ef4444; }

/* Status pill */
.pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.pill.cleared  { background: #d1fae5; color: #065f46; }
.pill.partial  { background: #fef3c7; color: #92400e; }
.pill.pending  { background: #fef2f2; color: #991b1b; }

.pill-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
}

.pill.cleared .pill-dot  { background: #10b981; }
.pill.partial .pill-dot  { background: #f59e0b; }
.pill.pending .pill-dot  { background: #ef4444; }

/* ── No section / no students ──────────────────── */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
}

.empty-state svg { margin-bottom: 16px; }
.empty-state p { font-size: 14px; }

/* ── Notice banner ─────────────────────────────── */
.notice {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 12px 18px;
    font-size: 13px;
    color: #1e40af;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 900px) {
    .sidebar { width: 64px; }
    .sidebar-brand .sub, .nav-item span, .sidebar-brand .logo { display: none; }
    .main { margin-left: 64px; padding: 20px 16px; }
    .summary-row { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ── Sidebar ─────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo">CATMIS</div>
        <div class="sub">CCS Portal</div>
    </div>

    <nav class="sidebar-nav">
        <a class="nav-item active" href="teacher_dashboard.php">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            <span>Teacher Portal</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="php/logout.php" class="logout-btn">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- ── Main ────────────────────────────────────── -->
<main class="main">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="topbar-title">
            <h1>Teacher / Instructor Portal</h1>
            <p>View-only access to your section's tuition status</p>
        </div>
        <div class="topbar-user">
            <div class="avatar"><?= strtoupper(substr($teacher['full_name'] ?? 'T', 0, 1)) ?></div>
            <div>
                <div class="name"><?= htmlspecialchars($teacher['full_name'] ?? 'Teacher') ?></div>
                <div class="role">Teacher &nbsp;·&nbsp; <?= htmlspecialchars($teacher['employee_number'] ?? '') ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($teacher['section_id'])): ?>

        <!-- No section assigned -->
        <div class="empty-state">
            <svg width="60" height="60" fill="none" stroke="#cbd5e1" stroke-width="1.5" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>You have not been assigned to a section yet.<br>Please contact your administrator.</p>
        </div>

    <?php else: ?>

        <!-- Section Banner -->
        <div class="section-banner">
            <div class="sec-info">
                <h2><?= htmlspecialchars($teacher['section_name']) ?></h2>
                <p>
                    Grade <?= htmlspecialchars($teacher['grade_level']) ?>
                    &nbsp;·&nbsp;
                    A.Y. <?= htmlspecialchars($teacher['academic_year']) ?>
                </p>
            </div>
            <div class="sec-badge">
                <div class="num"><?= $total ?></div>
                <div class="lbl">Students</div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-card cleared">
                <div class="label">Cleared</div>
                <div class="count"><?= $cleared ?></div>
                <div class="desc">Fully paid students</div>
            </div>
            <div class="summary-card partial">
                <div class="label">Partial</div>
                <div class="count"><?= $partial ?></div>
                <div class="desc">Have remaining balance</div>
            </div>
            <div class="summary-card pending">
                <div class="label">Not Paid</div>
                <div class="count"><?= $pending ?></div>
                <div class="desc">No payments recorded</div>
            </div>
        </div>

        <!-- Read-only Notice -->
        <div class="notice">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            You have view-only access. Payment processing and record editing are restricted to accounting staff.
        </div>

        <!-- Search & Table -->
        <div class="toolbar">
            <div class="search-wrap">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input
                    type="text"
                    id="searchInput"
                    placeholder="Search student name or ID…"
                    value="<?= htmlspecialchars($search) ?>"
                    oninput="filterTable()"
                >
            </div>
            <span class="result-count" id="resultCount">
                Showing <?= $total ?> student<?= $total !== 1 ? 's' : '' ?>
            </span>
        </div>

        <div class="table-wrap">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <svg width="50" height="50" fill="none" stroke="#cbd5e1" stroke-width="1.5" viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                    </svg>
                    <p>No students found in this section.</p>
                </div>
            <?php else: ?>
                <table id="studentTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Total Tuition</th>
                            <th>Remaining Balance</th>
                            <th>Payment Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $st):
                            $bal = floatval($st['balance']);
                            $tot = floatval($st['total_tuition']);

                            if ($bal <= 0) {
                                $statusClass = 'cleared';
                                $statusLabel = 'Cleared';
                                $balClass    = 'cleared';
                            } elseif ($bal >= $tot) {
                                $statusClass = 'pending';
                                $statusLabel = 'Not Paid';
                                $balClass    = 'overdue';
                            } else {
                                $statusClass = 'partial';
                                $statusLabel = 'Partial';
                                $balClass    = 'partial';
                            }
                        ?>
                        <tr class="student-row">
                            <td style="color:#94a3b8; font-size:12px;"><?= $i + 1 ?></td>
                            <td>
                                <div class="student-name"><?= htmlspecialchars($st['full_name']) ?></div>
                                <div class="student-num"><?= htmlspecialchars($st['student_number']) ?></div>
                            </td>
                            <td>₱<?= number_format($tot, 2) ?></td>
                            <td>
                                <span class="balance-cell <?= $balClass ?>">
                                    <?= $bal <= 0 ? '₱0.00' : '₱' . number_format($bal, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="pill <?= $statusClass ?>">
                                    <span class="pill-dot"></span>
                                    <?= $statusLabel ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</main>

<script>
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.student-row');
    let visible = 0;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('resultCount').textContent =
        `Showing ${visible} student${visible !== 1 ? 's' : ''}`;
}
</script>

</body>
</html>