<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── Fetch all audit logs ─────────────────────────────────────────
$result = $conn->query("
    SELECT
        al.log_id,
        al.timestamp,
        u.full_name,
        u.role,
        al.action
    FROM audit_logs al
    JOIN users u ON al.user_id = u.user_id
    ORDER BY al.timestamp DESC
");

$logs       = [];
$totalCount = 0;
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
    $totalCount++;
}

// ── Count by role ────────────────────────────────────────────────
$adminActions   = count(array_filter($logs, fn($l) => $l['role'] === 'admin'));
$studentActions = count(array_filter($logs, fn($l) => $l['role'] === 'student'));
$teacherActions = count(array_filter($logs, fn($l) => $l['role'] === 'teacher'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #eef1f4; }

/* ===== TOP NAVBAR ===== */
.navbar {
    position: fixed; top: 0; left: 0; right: 0; height: 60px;
    background: linear-gradient(90deg, #0f2027, #203a43);
    display: flex; align-items: center; padding: 0 24px;
    z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.navbar-brand { display: flex; align-items: baseline; gap: 10px; text-decoration: none; margin-right: 32px; flex-shrink: 0; }
.navbar-brand h2 { margin: 0; color: #fff; font-size: 20px; letter-spacing: -0.5px; }
.navbar-brand span { font-size: 11px; color: rgba(255,255,255,0.45); letter-spacing: 1px; }
.navbar-links { display: flex; align-items: center; gap: 2px; flex: 1; }
.navbar-links a {
    color: rgba(255,255,255,0.7); text-decoration: none; padding: 8px 13px;
    border-radius: 6px; font-size: 13.5px; white-space: nowrap;
    transition: background 0.18s, color 0.18s;
}
.navbar-links a:hover { background: rgba(255,255,255,0.1); color: #fff; }
.navbar-links a.active { background: rgba(255,255,255,0.15); color: #fff; }
.navbar-right { margin-left: auto; flex-shrink: 0; }
.logout-btn {
    background: #ff3b30; border: none; color: white; padding: 7px 16px;
    border-radius: 6px; cursor: pointer; font-size: 13px;
    font-family: 'Segoe UI', Arial, sans-serif; transition: background 0.18s;
}
.logout-btn:hover { background: #d0302a; }

/* ===== MAIN ===== */
.main { margin-top: 60px; padding: 30px; }

.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; flex-wrap: wrap; gap: 12px; }
.page-header h2 { margin: 0; font-size: 24px; color: #0f2027; }

/* ===== SUMMARY CARDS ===== */
.summary-cards { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.sum-card { flex: 1; min-width: 140px; background: white; border-radius: 10px; padding: 16px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-left: 5px solid #0077b6; }
.sum-card.amber  { border-color: #d97706; }
.sum-card.green  { border-color: #198754; }
.sum-card.purple { border-color: #7c3aed; }
.sum-card h4 { margin: 0 0 5px; font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.sum-card p  { margin: 0; font-size: 22px; font-weight: 700; color: #0f2027; }

/* ===== TOOLBAR ===== */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.search-box {
    padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; width: 260px; outline: none; font-family: inherit;
}
.search-box:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }
.filter-select {
    padding: 9px 32px 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
    appearance: none; font-size: 13px; color: #0f2027; cursor: pointer; outline: none; font-family: inherit;
}
.filter-select:focus { border-color: #0077b6; }
.date-input {
    padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; color: #0f2027; outline: none; cursor: pointer; font-family: inherit;
}
.date-input:focus { border-color: #0077b6; }
.btn { padding: 9px 15px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: 'Segoe UI', Arial, sans-serif; display: inline-flex; align-items: center; gap: 5px; transition: background 0.18s; white-space: nowrap; }
.btn-success { background: #198754; color: white; }
.btn-success:hover { background: #157347; }
.btn-outline { background: white; color: #374151; border: 1px solid #cbd5e1; }
.btn-outline:hover { background: #f8fafc; }

/* ===== TABLE ===== */
.table-wrap {
    background: white; border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden;
}
table { width: 100%; border-collapse: collapse; }
th {
    background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600;
    color: #64748b; padding: 12px 16px; border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }

.role-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
.role-admin   { background: #fff3e0; color: #b45309; }
.role-teacher { background: #e0f2fe; color: #0369a1; }
.role-student { background: #f0fdf4; color: #166534; }

.action-text { color: #374151; line-height: 1.5; }
.action-text mark { background: #fef9c3; padding: 0 2px; border-radius: 2px; }

.log-id { color: #94a3b8; font-size: 12px; font-variant-numeric: tabular-nums; }

.empty-msg { text-align: center; color: #94a3b8; padding: 40px; font-size: 14px; }

/* ===== COUNTER ===== */
.results-count { font-size: 13px; color: #64748b; margin-bottom: 8px; }
</style>
</head>
<body>

<!-- ===== TOP NAVBAR ===== -->
<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php" class="active">🕒 Audit Logs</a>
        <a href="#">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="main">
    <div class="page-header">
        <h2>🕒 Audit Logs</h2>
    </div>

    <!-- Summary cards -->
    <div class="summary-cards">
        <div class="sum-card">
            <h4>Total Events</h4>
            <p id="cardTotal"><?= $totalCount ?></p>
        </div>
        <div class="sum-card amber">
            <h4>Admin Actions</h4>
            <p><?= $adminActions ?></p>
        </div>
        <div class="sum-card green">
            <h4>Student Events</h4>
            <p><?= $studentActions ?></p>
        </div>
        <div class="sum-card purple">
            <h4>Teacher Events</h4>
            <p><?= $teacherActions ?></p>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <input  type="text" class="search-box" id="searchInput"
                placeholder="🔍 Search user or action…" oninput="applyFilters()">
        <select class="filter-select" id="roleFilter" onchange="applyFilters()">
            <option value="all">All Roles</option>
            <option value="admin">Admin</option>
            <option value="teacher">Teacher</option>
            <option value="student">Student</option>
        </select>
        <input type="date" class="date-input" id="dateFrom" onchange="applyFilters()" title="From date">
        <input type="date" class="date-input" id="dateTo"   onchange="applyFilters()" title="To date">
        <button class="btn btn-outline" onclick="clearFilters()">✕ Clear</button>
        <button class="btn btn-success" onclick="exportExcel()">📥 Export Excel</button>
    </div>

    <div class="results-count" id="resultsCount">
        Showing <?= $totalCount ?> of <?= $totalCount ?> events
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="logTable">
            <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="empty-msg">No audit log entries found.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <?php
                    $dateISO = date('Y-m-d', strtotime($log['timestamp']));
                    $role    = htmlspecialchars($log['role']);
                ?>
                <tr
                    data-date="<?= $dateISO ?>"
                    data-role="<?= $role ?>"
                    data-search="<?= htmlspecialchars(strtolower($log['full_name'] . ' ' . $log['action'])) ?>"
                >
                    <td class="log-id"><?= $log['log_id'] ?></td>
                    <td style="white-space:nowrap;">
                        <?= date('M d, Y', strtotime($log['timestamp'])) ?><br>
                        <span style="font-size:12px;color:#94a3b8;"><?= date('h:i A', strtotime($log['timestamp'])) ?></span>
                    </td>
                    <td><?= htmlspecialchars($log['full_name']) ?></td>
                    <td><span class="role-badge role-<?= $role ?>"><?= ucfirst($role) ?></span></td>
                    <td class="action-text"><?= htmlspecialchars($log['action']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const allRows   = Array.from(document.querySelectorAll('#logTable tr[data-date]'));
const totalAll  = allRows.length;

function applyFilters() {
    const q        = document.getElementById('searchInput').value.toLowerCase();
    const role     = document.getElementById('roleFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo   = document.getElementById('dateTo').value;

    let visible = 0;
    allRows.forEach(row => {
        const roleOk   = role === 'all' || row.dataset.role === role;
        const searchOk = !q        || row.dataset.search.includes(q);
        const fromOk   = !dateFrom || row.dataset.date >= dateFrom;
        const toOk     = !dateTo   || row.dataset.date <= dateTo;
        const show     = roleOk && searchOk && fromOk && toOk;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('resultsCount').textContent =
        `Showing ${visible} of ${totalAll} events`;
}

function clearFilters() {
    document.getElementById('searchInput').value  = '';
    document.getElementById('roleFilter').value   = 'all';
    document.getElementById('dateFrom').value     = '';
    document.getElementById('dateTo').value       = '';
    applyFilters();
}

function exportExcel() {
    const headers = ['Log ID', 'Timestamp', 'User', 'Role', 'Action'];
    const data    = [headers];

    allRows.forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        data.push([
            cells[0].textContent.trim(),
            cells[1].textContent.trim().replace(/\s+/g, ' '),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
        ]);
    });

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{ wch: 8 }, { wch: 20 }, { wch: 24 }, { wch: 12 }, { wch: 60 }];
    XLSX.utils.book_append_sheet(wb, ws, 'Audit Logs');
    XLSX.writeFile(wb, `CATMIS_AuditLogs_${new Date().toISOString().slice(0, 10)}.xlsx`);
}
</script>
</body>
</html>