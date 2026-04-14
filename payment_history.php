<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── Fetch all payments ───────────────────────────────────────────
$result = $conn->query("
    SELECT
        p.payment_id,
        p.payment_date,
        u_student.full_name  AS student_name,
        s.grade_level,
        s.section,
        p.amount,
        p.method,
        p.or_number,
        u_admin.full_name    AS processed_by
    FROM payments p
    JOIN students s        ON p.student_id  = s.student_id
    JOIN users u_student   ON s.user_id     = u_student.user_id
    JOIN users u_admin     ON p.posted_by   = u_admin.user_id
    ORDER BY p.payment_date DESC
");

$payments = [];
$total    = 0;
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
    $total += $row['amount'];
}
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
    border-radius: 6px; font-size: 13.5px; white-space: nowrap; transition: background 0.18s, color 0.18s;
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
.sum-card { flex: 1; min-width: 160px; background: white; border-radius: 10px; padding: 18px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-left: 5px solid #0077b6; }
.sum-card.green  { border-color: #198754; }
.sum-card.amber  { border-color: #d97706; }
.sum-card.purple { border-color: #7c3aed; }
.sum-card h4 { margin: 0 0 6px; font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.sum-card p  { margin: 0; font-size: 22px; font-weight: 700; color: #0f2027; }

/* ===== TOOLBAR ===== */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.search-box {
    padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; width: 240px; outline: none;
}
.search-box:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }

.filter-select {
    padding: 9px 32px 9px 12px;
    border: 1px solid #cbd5e1; border-radius: 6px;
    background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
    appearance: none; font-size: 13px; color: #0f2027; cursor: pointer; outline: none;
}
.filter-select:focus { border-color: #0077b6; }

.date-input {
    padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; color: #0f2027; outline: none; cursor: pointer;
}
.date-input:focus { border-color: #0077b6; }

.btn { padding: 9px 15px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: 'Segoe UI', Arial, sans-serif; display: inline-flex; align-items: center; gap: 5px; transition: background 0.18s; white-space: nowrap; }
.btn-success { background: #198754; color: white; }
.btn-success:hover { background: #157347; }
.btn-outline { background: white; color: #374151; border: 1px solid #cbd5e1; }
.btn-outline:hover { background: #f8fafc; }

/* ===== TABLE ===== */
.table-wrap { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th {
    background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600;
    color: #64748b; padding: 12px 16px; border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase; letter-spacing: 0.5px;
}
td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }

.method-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.method-Cash          { background: #d1fae5; color: #065f46; }
.method-GCash         { background: #e0f2fe; color: #0369a1; }
.method-Bank-Transfer { background: #f3e8ff; color: #6b21a8; }

.total-row td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; }

.empty-msg { text-align: center; color: #94a3b8; padding: 40px; font-size: 14px; }
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
        <a href="payment_history.php" class="active">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="#">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="main">
    <div class="page-header">
        <h2>📄 Payment History</h2>
    </div>

    <!-- Summary cards -->
    <div class="summary-cards">
        <div class="sum-card">
            <h4>Total Collected</h4>
            <p id="cardTotal">₱<?= number_format($total, 2) ?></p>
        </div>
        <div class="sum-card green">
            <h4>Total Transactions</h4>
            <p id="cardCount"><?= count($payments) ?></p>
        </div>
        <div class="sum-card amber">
            <h4>Cash</h4>
            <p id="cardCash">₱<?= number_format(array_sum(array_column(array_filter($payments, fn($p) => $p['method'] === 'Cash'), 'amount')), 2) ?></p>
        </div>
        <div class="sum-card purple">
            <h4>GCash / Bank</h4>
            <p id="cardDigital">₱<?= number_format(array_sum(array_column(array_filter($payments, fn($p) => $p['method'] !== 'Cash'), 'amount')), 2) ?></p>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <input type="text" class="search-box" id="searchInput" placeholder="🔍 Search student, OR number…" oninput="applyFilters()">
        <select class="filter-select" id="methodFilter" onchange="applyFilters()">
            <option value="all">All Methods</option>
            <option value="Cash">Cash</option>
            <option value="GCash">GCash</option>
            <option value="Bank Transfer">Bank Transfer</option>
        </select>
        <input type="date" class="date-input" id="dateFrom" onchange="applyFilters()" title="From date">
        <input type="date" class="date-input" id="dateTo"   onchange="applyFilters()" title="To date">
        <button class="btn btn-outline" onclick="clearFilters()">✕ Clear</button>
        <button class="btn btn-success" onclick="exportExcel()">📥 Export Excel</button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Grade & Section</th>
                    <th class="amount">Amount</th>
                    <th>Method</th>
                    <th>OR Number</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody id="paymentTable">
            <?php if (empty($payments)): ?>
                <tr><td colspan="7" class="empty-msg">No payments found.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $row): ?>
                <?php
                    $dateISO = date('Y-m-d', strtotime($row['payment_date']));
                    $methodClass = 'method-' . str_replace(' ', '-', $row['method']);
                ?>
                <tr
                    data-date="<?= $dateISO ?>"
                    data-method="<?= htmlspecialchars($row['method']) ?>"
                    data-search="<?= htmlspecialchars(strtolower($row['student_name'] . ' ' . $row['or_number'] . ' ' . $row['processed_by'])) ?>"
                    data-amount="<?= $row['amount'] ?>"
                >
                    <td><?= date('M d, Y', strtotime($row['payment_date'])) ?></td>
                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                    <td>Grade <?= htmlspecialchars($row['grade_level']) ?> – <?= htmlspecialchars($row['section']) ?></td>
                    <td class="amount">₱<?= number_format($row['amount'], 2) ?></td>
                    <td><span class="method-badge <?= $methodClass ?>"><?= htmlspecialchars($row['method']) ?></span></td>
                    <td><?= htmlspecialchars($row['or_number']) ?></td>
                    <td><?= htmlspecialchars($row['processed_by']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row" id="totalRow">
                    <td colspan="3"><strong>Showing Total</strong></td>
                    <td class="amount" id="filteredTotal"><strong>₱<?= number_format($total, 2) ?></strong></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
const allRows = Array.from(document.querySelectorAll('#paymentTable tr[data-date]'));

function applyFilters() {
    const q       = document.getElementById('searchInput').value.toLowerCase();
    const method  = document.getElementById('methodFilter').value;
    const dateFrom= document.getElementById('dateFrom').value;
    const dateTo  = document.getElementById('dateTo').value;

    let visibleTotal = 0;
    let visibleCount = 0;

    allRows.forEach(row => {
        const searchOk = !q      || row.dataset.search.includes(q);
        const methodOk = method === 'all' || row.dataset.method === method;
        const fromOk   = !dateFrom || row.dataset.date >= dateFrom;
        const toOk     = !dateTo   || row.dataset.date <= dateTo;
        const show     = searchOk && methodOk && fromOk && toOk;
        row.style.display = show ? '' : 'none';
        if (show) {
            visibleTotal += parseFloat(row.dataset.amount);
            visibleCount++;
        }
    });

    document.getElementById('filteredTotal').innerHTML =
        '<strong>₱' + visibleTotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</strong>';
}

function clearFilters() {
    document.getElementById('searchInput').value  = '';
    document.getElementById('methodFilter').value = 'all';
    document.getElementById('dateFrom').value     = '';
    document.getElementById('dateTo').value       = '';
    applyFilters();
}

function exportExcel() {
    const headers = ['Date', 'Student', 'Grade & Section', 'Amount', 'Method', 'OR Number', 'Processed By'];
    const data    = [headers];

    allRows.forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        data.push([
            cells[0].textContent.trim(),
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].textContent.trim(),
            cells[6].textContent.trim(),
        ]);
    });

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{ wch: 14 }, { wch: 26 }, { wch: 18 }, { wch: 14 }, { wch: 16 }, { wch: 18 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, ws, 'Payment History');
    XLSX.writeFile(wb, `CATMIS_Payments_${new Date().toISOString().slice(0, 10)}.xlsx`);
}
</script>
</body>
</html>