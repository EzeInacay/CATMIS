<?php
session_start();
include 'php/config.php';
include 'php/mailer.php';

    // Send email with backup as attachment
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/php/email_config.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;


if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$admin_id = $_SESSION['user_id'];

// ── Fetch current admin info (for password verification) ──────────
$adminInfo = $conn->prepare("SELECT full_name, email, password FROM users WHERE user_id = ?");
$adminInfo->bind_param('i', $admin_id);
$adminInfo->execute();
$adminUser = $adminInfo->get_result()->fetch_assoc();

// Unread notifications
$_nRes = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE admin_id=? AND is_read=0");
$_nRes->bind_param('i', $admin_id);
$_nRes->execute();
$unreadNotifs = $_nRes->get_result()->fetch_assoc()['cnt'] ?? 0;

$pendingEdits = $conn->query("SELECT COUNT(*) AS cnt FROM edit_requests WHERE status='pending'")->fetch_assoc()['cnt'] ?? 0;

// ── Ensure backup_settings table exists ───────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS backup_settings (
        id INT PRIMARY KEY DEFAULT 1,
        auto_enabled TINYINT(1) DEFAULT 0,
        backup_email VARCHAR(255) DEFAULT '',
        backup_day TINYINT DEFAULT 1,
        backup_hour TINYINT DEFAULT 2,
        last_auto_backup DATETIME DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL
    )
");
$conn->query("INSERT IGNORE INTO backup_settings (id) VALUES (1)");

// ── AJAX: generate SQL backup string ─────────────────────────────
function generateBackupSQL($conn) {
    $tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
    $sql = "-- CATMIS Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Server: " . gethostname() . "\n\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tables as $tableRow) {
        $table  = $tableRow[0];
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $sql   .= "-- --------------------------------------------------------\n";
        $sql   .= "-- Table structure for `$table`\n\n";
        $sql   .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql   .= $create['Create Table'] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `$table`");
        if ($rows->num_rows > 0) {
            $sql .= "-- Dumping data for `$table`\n\n";
            while ($row = $rows->fetch_assoc()) {
                $values = array_map(function ($v) use ($conn) {
                    return is_null($v) ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($row));
                $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

// ── Handle: Manual SQL download ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    // Verify password
    $raw = $_POST['confirm_password'] ?? '';
    if (!password_verify($raw, $adminUser['password'])) {
        $msg     = 'Incorrect password. Backup cancelled.';
        $msgType = 'error';
        goto render;
    }

    $sql      = generateBackupSQL($conn);
    $filename = 'catmis_backup_' . date('Ymd_His') . '.sql';

    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, 'Downloaded manual database backup')");
    $log->bind_param('i', $admin_id);
    $log->execute();

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

// ── Handle: CSV per-table export ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'csv') {
    $allowed = ['users','students','payments','tuition_accounts','student_ledgers','audit_logs','sections','school_years','tuition_fees'];
    $table   = $_GET['table'] ?? '';
    if (!in_array($table, $allowed)) { header('Location: backup.php'); exit; }

    $rows     = $conn->query("SELECT * FROM `$table`");
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

// ── Handle: Save auto-backup settings ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_auto_settings') {
    header('Content-Type: application/json');

    // Verify admin password before saving
    $raw = $_POST['confirm_password'] ?? '';
    if (!password_verify($raw, $adminUser['password'])) {
        echo json_encode(['error' => 'Incorrect password. Settings not saved.']);
        exit;
    }

    $enabled   = isset($_POST['auto_enabled']) ? 1 : 0;
    $email     = trim($_POST['backup_email']   ?? '');
    $day       = intval($_POST['backup_day']   ?? 1);   // 1=Mon…7=Sun
    $hour      = intval($_POST['backup_hour']  ?? 2);   // 0-23
    $day       = max(1, min(7, $day));
    $hour      = max(0, min(23, $hour));

    if ($enabled && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Please enter a valid backup email address.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE backup_settings
        SET auto_enabled=?, backup_email=?, backup_day=?, backup_hour=?, updated_by=?, updated_at=NOW()
        WHERE id=1
    ");
    $stmt->bind_param('isiii', $enabled, $email, $day, $hour, $admin_id);
    $stmt->execute();

    $act = $enabled
        ? "Enabled auto-backup: every " . ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$day] . " at {$hour}:00 → {$email}"
        : "Disabled auto-backup";
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $log->bind_param('is', $admin_id, $act);
    $log->execute();

    echo json_encode(['success' => true, 'message' => 'Auto-backup settings saved.']);
    exit;
}

// ── Handle: Run auto-backup now (manual trigger for testing) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_backup_email') {
    header('Content-Type: application/json');

    $raw = $_POST['confirm_password'] ?? '';
    if (!password_verify($raw, $adminUser['password'])) {
        echo json_encode(['error' => 'Incorrect password.']);
        exit;
    }

    $settings = $conn->query("SELECT * FROM backup_settings WHERE id=1")->fetch_assoc();
    $toEmail  = trim($_POST['override_email'] ?? $settings['backup_email'] ?? '');

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'No valid backup email configured.']);
        exit;
    }

    $sql      = generateBackupSQL($conn);
    $filename = 'catmis_backup_' . date('Ymd_His') . '.sql';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 60;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->Subject = 'CATMIS Database Backup — ' . date('Y-m-d H:i');
        $mail->isHTML(true);
        $mail->Body = "
            <div style='font-family:Segoe UI,Arial,sans-serif;padding:24px;'>
                <h2 style='color:#0f2027;'>CATMIS Scheduled Backup</h2>
                <p>Please find the attached database backup generated on <strong>" . date('F d, Y \a\t h:i A') . "</strong>.</p>
                <p style='color:#64748b;font-size:13px;'>This is an automated backup from the CATMIS portal. Keep this file in a secure location.</p>
            </div>";
        $mail->AltBody = "CATMIS Database Backup — " . date('Y-m-d H:i') . ". See attachment.";

        // Attach the SQL as a file
        $mail->addStringAttachment($sql, $filename, 'base64', 'application/sql');
        $mail->send();

        // Update last_auto_backup timestamp
        $conn->query("UPDATE backup_settings SET last_auto_backup=NOW() WHERE id=1");

        $act = "Sent database backup email to {$toEmail}";
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $log->bind_param('is', $admin_id, $act);
        $log->execute();

        echo json_encode(['success' => true, 'message' => "Backup sent to {$toEmail} successfully."]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Mail error: ' . $mail->ErrorInfo]);
    }
    exit;
}

// ── Fetch backup settings ─────────────────────────────────────────
$settings = $conn->query("SELECT * FROM backup_settings WHERE id=1")->fetch_assoc();

// ── Recent backup log ─────────────────────────────────────────────
$backupLogs = $conn->query("
    SELECT al.log_id, al.action, al.timestamp, u.full_name
    FROM audit_logs al JOIN users u ON al.user_id = u.user_id
    WHERE al.action LIKE '%backup%'
    ORDER BY al.timestamp DESC LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

// ── Table info ────────────────────────────────────────────────────
$tableInfo = $conn->query("
    SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024,1) AS size_kb
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_ROWS DESC
")->fetch_all(MYSQLI_ASSOC);

$msg = ''; $msgType = '';

render:
$days = ['1' => 'Monday','2' => 'Tuesday','3' => 'Wednesday','4' => 'Thursday','5' => 'Friday','6' => 'Saturday','7' => 'Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backup & Recovery | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="css/admind.css" rel="stylesheet">
<style>
.section-block { background:white; border-radius:12px; padding:28px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:24px; }
.section-block h3 { margin:0 0 6px; font-size:17px; color:#0f2027; }
.section-block .sub { font-size:13px; color:#64748b; margin:0 0 20px; }
.divider { border:none; border-top:1px solid #f1f5f9; margin:20px 0; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.form-row.single { grid-template-columns:1fr; }
.form-group label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.4px; }
.form-group input, .form-group select { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; font-family:inherit; box-sizing:border-box; }
.form-group input:focus, .form-group select:focus { outline:none; border-color:#0077b6; box-shadow:0 0 0 3px rgba(0,119,182,0.12); }
.form-group input[type=password] { letter-spacing:2px; }

.btn-primary { padding:10px 22px; background:#0077b6; color:white; border:none; border-radius:7px; cursor:pointer; font-size:14px; font-family:inherit; font-weight:600; transition:background 0.18s; }
.btn-primary:hover { background:#005f99; }
.btn-danger  { padding:10px 22px; background:#dc2626; color:white; border:none; border-radius:7px; cursor:pointer; font-size:14px; font-family:inherit; font-weight:600; transition:background 0.18s; }
.btn-danger:hover { background:#b91c1c; }
.btn-success { padding:10px 22px; background:#198754; color:white; border:none; border-radius:7px; cursor:pointer; font-size:14px; font-family:inherit; font-weight:600; transition:background 0.18s; }
.btn-success:hover { background:#157347; }
.btn-outline { padding:9px 18px; background:transparent; color:#374151; border:1.5px solid #cbd5e1; border-radius:7px; cursor:pointer; font-size:13px; font-family:inherit; transition:all 0.18s; }
.btn-outline:hover { border-color:#0077b6; color:#0077b6; }

.table-list { width:100%; border-collapse:collapse; }
.table-list th { padding:10px 14px; background:#f8fafc; font-size:12px; color:#374151; font-weight:600; text-align:left; }
.table-list td { padding:10px 14px; font-size:13px; border-bottom:1px solid #f1f5f9; }
.table-list tr:last-child td { border-bottom:none; }

.toggle-wrap { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
.toggle { position:relative; display:inline-block; width:48px; height:26px; }
.toggle input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:999px; transition:0.3s; }
.slider::before { content:''; position:absolute; width:20px; height:20px; left:3px; bottom:3px; background:white; border-radius:50%; transition:0.3s; }
.toggle input:checked + .slider { background:#0077b6; }
.toggle input:checked + .slider::before { transform:translateX(22px); }

.last-backup-info { background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; font-size:13px; color:#0369a1; display:flex; align-items:center; gap:10px; margin-bottom:16px; }

.alert { padding:12px 16px; border-radius:8px; font-size:14px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.alert-success { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }

.restore-steps { list-style:none; padding:0; margin:0; counter-reset:steps; }
.restore-steps li { counter-increment:steps; padding:10px 14px 10px 44px; position:relative; border-bottom:1px solid #f1f5f9; font-size:14px; }
.restore-steps li:last-child { border-bottom:none; }
.restore-steps li::before { content:counter(steps); position:absolute; left:12px; top:10px; background:#0f2027; color:white; border-radius:50%; width:22px; height:22px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; }
.restore-steps li code { background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; }

#toast { position:fixed; bottom:28px; right:28px; background:#0f2027; color:white; padding:13px 22px; border-radius:10px; font-size:14px; z-index:999; opacity:0; transform:translateY(10px); transition:all 0.3s; pointer-events:none; }
#toast.show { opacity:1; transform:translateY(0); }
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
        <?php if ($pendingEdits > 0): ?>
        <a href="edit_requests_admin.php" style="text-decoration:none;">
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

<div class="main">
    <div class="title">Backup &amp; Recovery</div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>">
        <?= $msgType === 'error' ? '⚠' : '✓' ?> <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <!-- LEFT COLUMN -->
        <div>
            <!-- Manual Backup -->
            <div class="section-block">
                <h3>💾 Manual Backup</h3>
                <p class="sub">Download a full SQL backup of the CATMIS database. You must confirm your password before downloading.</p>

                <form method="POST" action="backup.php?action=download" id="manualBackupForm">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>Confirm Your Password</label>
                        <input type="password" name="confirm_password" id="manualPw" placeholder="Enter your admin password" required autocomplete="current-password">
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="btn-primary">📥 Download Full SQL Backup</button>
                    </div>
                </form>

                <hr class="divider">

                <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 10px;">Export individual tables as CSV:</p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php
                    $exportTables = ['users','students','payments','tuition_accounts','student_ledgers','audit_logs','sections','school_years','tuition_fees'];
                    foreach ($exportTables as $t):
                    ?>
                    <a href="backup.php?action=csv&table=<?= $t ?>" class="btn-outline" style="font-size:12px;padding:6px 12px;">📄 <?= $t ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Auto Backup Settings -->
            <div class="section-block">
                <h3>⏰ Automated Weekly Backup</h3>
                <p class="sub">Set a schedule for automatic database backups. The backup will be sent to the specified email.</p>

                <?php if ($settings['last_auto_backup']): ?>
                <div class="last-backup-info">
                    🕒 Last auto-backup: <strong><?= date('M d, Y \a\t h:i A', strtotime($settings['last_auto_backup'])) ?></strong>
                </div>
                <?php endif; ?>

                <div class="toggle-wrap">
                    <label class="toggle">
                        <input type="checkbox" id="autoToggle" <?= $settings['auto_enabled'] ? 'checked' : '' ?> onchange="toggleAutoFields()">
                        <span class="slider"></span>
                    </label>
                    <span style="font-size:14px;font-weight:600;color:#374151;">Enable automatic weekly backup</span>
                </div>

                <div id="autoFields" style="<?= $settings['auto_enabled'] ? '' : 'display:none;' ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Backup Delivery Email</label>
                            <input type="email" id="backupEmail" value="<?= htmlspecialchars($settings['backup_email'] ?? '') ?>" placeholder="backups@yourdomain.com">
                        </div>
                        <div class="form-group">
                            <label>Day of Week</label>
                            <select id="backupDay">
                                <?php foreach ($days as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $settings['backup_day'] == $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Time (24h)</label>
                            <select id="backupHour">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                <option value="<?= $h ?>" <?= $settings['backup_hour'] == $h ? 'selected' : '' ?>>
                                    <?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:00 <?= $h < 12 ? 'AM' : 'PM' ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Confirm Password to Save</label>
                            <input type="password" id="autoSavePw" placeholder="Your admin password" autocomplete="current-password">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
                        <button class="btn-primary" onclick="saveAutoSettings()">💾 Save Schedule</button>
                        <button class="btn-success" onclick="sendTestBackup()">📧 Send Backup Now</button>
                    </div>
                </div>

                <?php if (!$settings['auto_enabled']): ?>
                <p style="font-size:13px;color:#94a3b8;margin:8px 0 0;">Toggle on to configure the schedule and backup email.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
            <!-- Database Tables Info -->
            <div class="section-block">
                <h3>🗄️ Database Tables</h3>
                <p class="sub">Current row counts and sizes for each table.</p>
                <table class="table-list">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th style="text-align:right;">Rows</th>
                            <th style="text-align:right;">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tableInfo as $t): ?>
                    <tr>
                        <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px;"><?= htmlspecialchars($t['TABLE_NAME']) ?></code></td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($t['TABLE_ROWS'] ?? 0) ?></td>
                        <td style="text-align:right;color:#64748b;"><?= $t['size_kb'] ?? 0 ?> KB</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Restore Instructions -->
            <div class="section-block">
                <h3>♻️ How to Restore a Backup</h3>
                <p class="sub">Use your downloaded SQL file to restore the database if needed.</p>
                <ol class="restore-steps">
                    <li>Open <strong>phpMyAdmin</strong> on your server (usually at <code>localhost/phpmyadmin</code>).</li>
                    <li>Select (or create) the <code>catmis</code> database from the left panel.</li>
                    <li>Click the <strong>Import</strong> tab at the top.</li>
                    <li>Click <strong>Choose File</strong> and select your <code>.sql</code> backup file.</li>
                    <li>Click <strong>Go</strong> to run the import. Wait for the success message.</li>
                    <li>Verify your data by checking a few tables in the Browse view.</li>
                </ol>
                <p style="font-size:12px;color:#94a3b8;margin:12px 0 0;">Alternatively via terminal: <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">mysql -u root catmis &lt; backup.sql</code></p>
            </div>

            <!-- Backup History -->
            <div class="section-block">
                <h3>📋 Backup Activity Log</h3>
                <p class="sub">Recent backup-related actions from all admins.</p>
                <?php if (empty($backupLogs)): ?>
                <p style="color:#94a3b8;font-size:13px;">No backup activity yet.</p>
                <?php else: ?>
                <table class="table-list">
                    <thead><tr><th>Admin</th><th>Action</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($backupLogs as $log): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($log['full_name']) ?></td>
                        <td style="font-size:12px;color:#374151;"><?= htmlspecialchars($log['action']) ?></td>
                        <td style="font-size:12px;color:#94a3b8;white-space:nowrap;"><?= date('M d, Y H:i', strtotime($log['timestamp'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="toast"></div>

<!-- Send-now modal (separate email override) -->
<div id="sendNowModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:200;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:14px;width:420px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 8px;font-size:17px;color:#0f2027;">📧 Send Backup Now</h3>
        <p style="font-size:13px;color:#64748b;margin:0 0 18px;">Send the full SQL backup to an email address immediately.</p>
        <div class="form-group" style="margin-bottom:12px;">
            <label>Recipient Email</label>
            <input type="email" id="sendNowEmail" value="<?= htmlspecialchars($settings['backup_email'] ?? '') ?>" placeholder="backup@example.com">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
            <label>Confirm Your Password</label>
            <input type="password" id="sendNowPw" placeholder="Your admin password">
        </div>
        <div style="display:flex;gap:10px;">
            <button class="btn-success" style="flex:1;" onclick="confirmSendNow()">📤 Send</button>
            <button class="btn-outline" onclick="document.getElementById('sendNowModal').style.display='none'">Cancel</button>
        </div>
    </div>
</div>

<script>
function showToast(msg, isError=false) {
    const t = document.getElementById('toast');
    t.textContent = (isError ? '⚠ ' : '✓ ') + msg;
    t.style.background = isError ? '#dc2626' : '#0f2027';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 4000);
}

function toggleAutoFields() {
    const on = document.getElementById('autoToggle').checked;
    document.getElementById('autoFields').style.display = on ? '' : 'none';
}

async function saveAutoSettings() {
    const pw = document.getElementById('autoSavePw').value;
    if (!pw) { showToast('Please enter your password to save settings.', true); return; }

    const body = new FormData();
    body.append('action', 'save_auto_settings');
    body.append('confirm_password', pw);
    if (document.getElementById('autoToggle').checked) body.append('auto_enabled', '1');
    body.append('backup_email', document.getElementById('backupEmail').value);
    body.append('backup_day',   document.getElementById('backupDay').value);
    body.append('backup_hour',  document.getElementById('backupHour').value);

    const res  = await fetch('backup.php', { method:'POST', body });
    const data = await res.json();
    if (data.error)   showToast(data.error, true);
    else              showToast(data.message || 'Settings saved!');
}

function sendTestBackup() {
    document.getElementById('sendNowEmail').value = document.getElementById('backupEmail').value || '';
    document.getElementById('sendNowPw').value = '';
    document.getElementById('sendNowModal').style.display = 'flex';
}

async function confirmSendNow() {
    const email = document.getElementById('sendNowEmail').value;
    const pw    = document.getElementById('sendNowPw').value;
    if (!email || !pw) { showToast('Email and password are required.', true); return; }

    const btn = event.target;
    btn.textContent = '⏳ Sending…'; btn.disabled = true;

    const body = new FormData();
    body.append('action', 'send_backup_email');
    body.append('confirm_password', pw);
    body.append('override_email', email);

    const res  = await fetch('backup.php', { method:'POST', body });
    const data = await res.json();
    btn.textContent = '📤 Send'; btn.disabled = false;
    document.getElementById('sendNowModal').style.display = 'none';
    if (data.error)   showToast(data.error, true);
    else              showToast(data.message || 'Backup sent!');
}

// Close send-now modal on overlay click
document.getElementById('sendNowModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

</body>
</html>
