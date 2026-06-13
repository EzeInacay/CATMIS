<?php
/**
 * php/run_scheduled_backup.php
 * Cron job script — runs hourly, sends backup email if schedule matches.
 *
 * Add to crontab:
 *   0 * * * * /usr/bin/php /var/www/html/catmis/php/run_scheduled_backup.php >> /var/log/catmis_backup.log 2>&1
 *
 * On XAMPP (Windows), use Task Scheduler to run:
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\catmis\php\run_scheduled_backup.php"
 *   Trigger: Daily, repeat every 1 hour
 */

// Run from CLI or web — restrict to CLI for security in production
// if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$now     = new DateTime();
$today   = (int)$now->format('N'); // 1=Mon, 7=Sun
$hour    = (int)$now->format('G'); // 0-23

// Load settings
$settings = $conn->query("SELECT * FROM backup_settings WHERE id=1")->fetch_assoc();

if (!$settings || !$settings['auto_enabled']) {
    echo "[" . date('Y-m-d H:i') . "] Auto-backup is disabled. Skipping.\n";
    exit;
}

$configDay  = (int)$settings['backup_day'];
$configHour = (int)$settings['backup_hour'];
$toEmail    = trim($settings['backup_email'] ?? '');

if ($today !== $configDay || $hour !== $configHour) {
    echo "[" . date('Y-m-d H:i') . "] Not scheduled time (today={$today}, now={$hour}h, configured={$configDay}/{$configHour}h). Skipping.\n";
    exit;
}

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    echo "[" . date('Y-m-d H:i') . "] No valid backup email configured. Skipping.\n";
    exit;
}

// Generate SQL dump
echo "[" . date('Y-m-d H:i') . "] Generating backup SQL...\n";

$tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
$sql    = "-- CATMIS Auto Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $tableRow) {
    $table  = $tableRow[0];
    $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
    $sql   .= "DROP TABLE IF EXISTS `$table`;\n";
    $sql   .= $create['Create Table'] . ";\n\n";

    $rows = $conn->query("SELECT * FROM `$table`");
    if ($rows && $rows->num_rows > 0) {
        while ($row = $rows->fetch_assoc()) {
            $values = array_map(fn($v) => is_null($v) ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", array_values($row));
            $sql   .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }
}
$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

$filename = 'catmis_auto_backup_' . date('Ymd_Hi') . '.sql';

// Send via email
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->Timeout    = 120;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($toEmail);
    $mail->Subject = 'CATMIS Weekly Auto-Backup — ' . date('Y-m-d');
    $mail->isHTML(true);
    $mail->Body    = "<div style='font-family:Segoe UI,Arial,sans-serif;padding:24px;'><h2>CATMIS Scheduled Backup</h2><p>Attached is your automatic weekly database backup generated on <strong>" . date('F d, Y \a\t h:i A') . "</strong>.</p><p style='color:#64748b;font-size:13px;'>Store this file securely.</p></div>";
    $mail->AltBody = "CATMIS Weekly Auto-Backup — " . date('Y-m-d H:i');
    $mail->addStringAttachment($sql, $filename, 'base64', 'application/sql');
    $mail->send();

    // Update last backup timestamp
    $conn->query("UPDATE backup_settings SET last_auto_backup=NOW() WHERE id=1");

    // Log it
    $conn->query("INSERT INTO audit_logs (user_id, action) VALUES (NULL, 'Automated weekly backup sent to {$toEmail}')");

    echo "[" . date('Y-m-d H:i') . "] ✓ Backup sent to {$toEmail} ({$filename})\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i') . "] ✗ Mail error: " . $mail->ErrorInfo . "\n";
    file_put_contents(__DIR__ . '/mail_errors.log',
        date('Y-m-d H:i:s') . " | AUTO-BACKUP FAILED | " . $mail->ErrorInfo . "\n",
        FILE_APPEND);
}
?>
