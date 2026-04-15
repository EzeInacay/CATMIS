<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];
$msg = '';
$msgType = '';

// ── Handle backup download ───────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    // Get DB credentials from config
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $db   = 'catmis';

    $tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
    $sql = "-- CATMIS Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $tableRow) {
        $table = $tableRow[0];

        // Structure
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        // Data
        $rows = $conn->query("SELECT * FROM `$table`");
        if ($rows->num_rows > 0) {
            while ($row = $rows->fetch_assoc()) {
                $values = array_map(function($v) use ($conn) {
                    return is_null($v) ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Log it
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, 'Downloaded database backup')");
    $log->bind_param('i', $admin_id);
    $log->execute();

    $filename = 'catmis_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// ── Handle CSV exports per table ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'csv') {
    $allowed = ['users','students','payments','tuition_accounts','student_ledgers','audit_logs','sections','school_years','tuition_fees'];
    $table   = $_GET['table'] ?? '';
    if (!in_array($table, $allowed)) { header('Location: backup.php'); exit; }

    $rows = $conn->query("SELECT * FROM `$table`");
    $filename = 'catmis_' . $table . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    $first = true;
    while ($row = $rows->fetch_assoc()) {
        if ($first) { fputcsv($out, array_keys($row)); $first = false; }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── Recent backup log ────────────────────────────────────────────
$backupLogs = $conn->query("
    SELECT al.log_id, al.action, al.timestamp, u.full_name
    FROM audit_logs al JOIN users u ON al.user_id = u.user_id
    WHERE al.action LIKE '%backup%'
    ORDER BY al.timestamp DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── Table sizes for info ─────────────────────────────────────────
$tableInfo = $conn->query("
    SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024,1) AS size_kb
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_ROWS DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backup & Recovery | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="css/admind.css" rel="stylesheet">
<style>
.backup-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px; }
@media(max-width:700px){ .backup-grid { grid-template-columns:1fr; } }
.panel { background:white; border-radius:12px; padding:24px; box-shadow:0 4px 10px rgba(0,0,0,0.05); }
.panel h3 { margin:0 0 16px; font-size:16px; color:#0f2027; border-bottom:1px solid #f1f5f9; padding-bottom:10px; }
.big-btn { display:flex; align-items:center; gap:12px; padding:16px 20px; border:1.5px solid #e2e8f0; border-radius:10px; text-decoration:none; color:#0f2027; margin-bottom:10px; transition:all 0.18s; background:white; cursor:pointer; width:100%; font-family:inherit; font-size:14px; }
.big-btn:hover { border-color:#0077b6; background:#f0f9ff; }
.big-btn .icon { font-size:24px; flex-shrink:0; }
.big-btn .label { font-weight:600; font-size:14px; }
.big-btn .sublabel { font-size:12px; color:#64748b; margin-top:2px; }
.table-list { width:100%; border-collapse:collapse; }
.table-list th { font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; }
.table-list td { padding:8px 10px; font-size:13px; border-bottom:1px solid #f1f5f9; }
.table-list tr:last-child td { border-bottom:none; }
.export-link { color:#0077b6; text-decoration:none; font-size:12px; font-weight:600; }
.export-link:hover { text-decoration:underline; }
.log-item { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
.log-item:last-child { border-bottom:none; }
.warn-box { background:#fef3c7; border-left:4px solid #d97706; border-radius:0 8px 8px 0; padding:12px 16px; font-size:13px; color:#92400e; margin-bottom:20px; }
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
        <a href="financial_report.php">📊 Reports</a>
        <a href="backup.php" class="active">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <h2 style="font-size:24px;font-weight:bold;color:#0f2027;margin-bottom:8px;">💾 Data Backup & Recovery</h2>
    <p style="color:#64748b;font-size:13px;margin-bottom:24px;">Export your database or individual tables. For full recovery, restore the SQL file using phpMyAdmin or the MySQL command line.</p>

    <div class="warn-box">
        ⚠ <strong>Recovery note:</strong> CATMIS does not handle automatic restore. To recover from a backup, import the downloaded .sql file into phpMyAdmin → your database → Import tab. Make sure to backup regularly, especially before making bulk changes.
    </div>

    <div class="backup-grid">
        <!-- Full backup -->
        <div class="panel">
            <h3>💾 Full Database Backup</h3>
            <p style="font-size:13px;color:#64748b;margin-bottom:16px;">Downloads a complete .sql file of all tables and data. Use this for full system recovery.</p>
            <a href="backup.php?action=download" class="big-btn" style="text-decoration:none;">
                <span class="icon">📦</span>
                <div>
                    <div class="label">Download Full SQL Backup</div>
                    <div class="sublabel">All tables · Generated on demand</div>
                </div>
            </a>
        </div>

        <!-- Export tables -->
        <div class="panel">
            <h3>📋 Export Individual Tables</h3>
            <table class="table-list">
                <thead><tr><th>Table</th><th>~Rows</th><th>Size</th><th>CSV</th></tr></thead>
                <tbody>
                <?php
                $exportable = ['users','students','payments','tuition_accounts','student_ledgers','audit_logs','sections','school_years','tuition_fees'];
                foreach ($tableInfo as $ti):
                    if (!in_array($ti['TABLE_NAME'], $exportable)) continue;
                ?>
                <tr>
                    <td><?= htmlspecialchars($ti['TABLE_NAME']) ?></td>
                    <td style="color:#64748b"><?= number_format($ti['TABLE_ROWS']) ?></td>
                    <td style="color:#64748b"><?= $ti['size_kb'] ?> KB</td>
                    <td><a href="backup.php?action=csv&table=<?= $ti['TABLE_NAME'] ?>" class="export-link">↓ CSV</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent backup log -->
        <div class="panel">
            <h3>🕒 Backup History</h3>
            <?php if (empty($backupLogs)): ?>
            <p style="color:#94a3b8;font-size:13px;">No backups recorded yet.</p>
            <?php else: ?>
            <?php foreach ($backupLogs as $bl): ?>
            <div class="log-item">
                <span><?= htmlspecialchars($bl['full_name']) ?></span>
                <span style="color:#94a3b8;"><?= date('M d, Y g:i A', strtotime($bl['timestamp'])) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recovery instructions -->
        <div class="panel">
            <h3>🔄 How to Restore</h3>
            <ol style="font-size:13px;color:#374151;line-height:2;padding-left:18px;margin:0;">
                <li>Download the full SQL backup above</li>
                <li>Open <strong>phpMyAdmin</strong> in your browser</li>
                <li>Select the <strong>catmis</strong> database</li>
                <li>Click the <strong>Import</strong> tab at the top</li>
                <li>Choose your downloaded .sql file</li>
                <li>Click <strong>Go</strong> to restore</li>
            </ol>
            <div style="margin-top:16px;padding:10px 14px;background:#f0fdf4;border-radius:8px;font-size:12px;color:#166534;">
                ✅ Tip: Schedule a manual backup every end of month and before any major data changes.
            </div>
        </div>
    </div>
</div>
</body>
</html>