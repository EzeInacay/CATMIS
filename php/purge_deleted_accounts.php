<?php
/**
 * php/purge_deleted_accounts.php
 *
 * Permanently deletes any user account that has been sitting in the
 * Bin (deleted_at IS NOT NULL) for 30 days or more.
 *
 * SETUP — run this automatically once a day via cron:
 *   0 2 * * *  php /path/to/Website/php/purge_deleted_accounts.php >> /path/to/Website/php/purge.log 2>&1
 *
 * It can also be opened manually in a browser by a superadmin (it will
 * check the session in that case), or run from the command line (CLI
 * has no session, so the check is skipped for cron).
 */

session_start();
include __DIR__ . '/config.php';
include __DIR__ . '/account_deletion.php';

// If accessed via a browser, only a superadmin may trigger it manually.
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
        http_response_code(403);
        echo "Access denied.";
        exit;
    }
}

// Find accounts older than 30 days in the bin
$stmt = $conn->prepare("
    SELECT user_id, full_name, email, deleted_at
    FROM users
    WHERE deleted_at IS NOT NULL
      AND deleted_at <= (NOW() - INTERVAL 30 DAY)
");
$stmt->execute();
$expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$count  = 0;
$failed = 0;
foreach ($expired as $row) {
    $result = purgeUserAccount($conn, (int)$row['user_id']);

    if ($result['success']) {
        // Only write an audit log entry when a real admin user triggered this
        // (cron has no session user to attribute the action to, and audit_logs.user_id
        // has a foreign key constraint against users).
        if (!empty($_SESSION['user_id'])) {
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
            $act = "Auto-purged account #{$row['user_id']} ({$row['full_name']}) — 30 days in Bin";
            $log->bind_param('is', $_SESSION['user_id'], $act);
            $log->execute();
        }
        $count++;
    } else {
        error_log("purge_deleted_accounts: failed to purge user #{$row['user_id']}: " . $result['error']);
        $failed++;
    }
}

echo "Purged {$count} expired account(s) from the Bin.\n";
if ($failed > 0) {
    echo "{$failed} account(s) could not be purged — see error log.\n";
}
