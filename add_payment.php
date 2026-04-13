<?php
session_start();
include 'php/config.php';

// Guard: must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$account_id = intval($_POST['account_id'] ?? 0);
$student_id = intval($_POST['student_id'] ?? 0);
$amount     = floatval($_POST['amount']     ?? 0);
$method     = trim($_POST['method']         ?? '');
$or_number  = trim($_POST['or_number']      ?? '');
$posted_by  = $_SESSION['user_id'];

// Basic validation
if ($account_id === 0 || $student_id === 0 || $amount <= 0 || empty($method) || empty($or_number)) {
    header("Location: payment_form.php?account_id={$account_id}&error=Please+fill+in+all+fields.");
    exit;
}

// Valid methods
$valid_methods = ['Cash', 'GCash', 'Bank Transfer'];
if (!in_array($method, $valid_methods)) {
    header("Location: payment_form.php?account_id={$account_id}&error=Invalid+payment+method.");
    exit;
}

// Check OR number is unique
$check = $conn->prepare("SELECT payment_id FROM payments WHERE or_number = ?");
$check->bind_param('s', $or_number);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    header("Location: payment_form.php?account_id={$account_id}&error=OR+number+already+exists.+Please+use+a+different+one.");
    exit;
}

// ── INSERT into payments ──
$stmt1 = $conn->prepare("
    INSERT INTO payments (account_id, student_id, amount, method, or_number, posted_by)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt1->bind_param('iidssi', $account_id, $student_id, $amount, $method, $or_number, $posted_by);

// ── INSERT into student_ledgers ──
$remarks = "OR#{$or_number} - {$method}";
$stmt2 = $conn->prepare("
    INSERT INTO student_ledgers (account_id, entry_type, amount, remarks, posted_by)
    VALUES (?, 'PAYMENT', ?, ?, ?)
");
$stmt2->bind_param('idsi', $account_id, $amount, $remarks, $posted_by);

// ── Audit log ──
$action = "Posted payment {$or_number} of ₱" . number_format($amount, 2) . " for account #{$account_id} via {$method}";
$stmt3 = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
$stmt3->bind_param('is', $posted_by, $action);

// Run all three
if ($stmt1->execute() && $stmt2->execute() && $stmt3->execute()) {
    header("Location: payment_form.php?account_id={$account_id}&success=1");
} else {
    header("Location: payment_form.php?account_id={$account_id}&error=Something+went+wrong.+Please+try+again.");
}
exit;
?>