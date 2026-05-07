<?php
/**
 * add_fee.php — Adds a fee charge to a student's ledger
 * Fixed: was vulnerable to SQL injection. Now uses prepared statements.
 */
session_start();
include __DIR__ . '/../php/config.php';

// Auth guard — only admins can post charges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');

$account_id = intval($_POST['account_id'] ?? 0);
$amount     = floatval($_POST['amount']     ?? 0);
$remarks    = trim($_POST['remarks']        ?? 'Additional Fee');
$posted_by  = $_SESSION['user_id'];

if ($account_id <= 0 || $amount <= 0) {
    echo json_encode(['error' => 'Invalid account or amount.']);
    exit;
}

// Verify account exists
$chk = $conn->prepare("SELECT account_id FROM tuition_accounts WHERE account_id = ?");
$chk->bind_param('i', $account_id);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    echo json_encode(['error' => 'Account not found.']);
    exit;
}

// Insert using prepared statement (no injection possible)
$stmt = $conn->prepare("
    INSERT INTO student_ledgers (account_id, entry_type, amount, remarks, posted_by)
    VALUES (?, 'CHARGE', ?, ?, ?)
");
$stmt->bind_param('idsi', $account_id, $amount, $remarks, $posted_by);

if ($stmt->execute()) {
    // Audit log
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $act = "Added fee charge of ₱{$amount} to account #{$account_id}: {$remarks}";
    $log->bind_param('is', $posted_by, $act);
    $log->execute();

    echo json_encode(['success' => true, 'message' => 'Fee charge posted successfully.']);
} else {
    echo json_encode(['error' => 'Database error: ' . $conn->error]);
}
?>
