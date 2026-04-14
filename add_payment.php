<?php
/**
 * add_payment.php
 *
 * Processes a payment form submission.
 * Inserts into: payments, student_ledgers, audit_logs.
 *
 * PAYMENT/DISCOUNT/PENALTY ledger entries do NOT link to a
 * specific fee row — fee_id is left NULL for these entry types.
 * Only CHARGE rows (posted at registration) carry a fee_id.
 */

session_start();
include 'php/config.php';

// ── Auth guard ───────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$account_id = intval($_POST['account_id'] ?? 0);
$student_id = intval($_POST['student_id'] ?? 0);
$amount     = floatval($_POST['amount']   ?? 0);
$method     = trim($_POST['method']       ?? '');
$or_number  = trim($_POST['or_number']    ?? '');
$posted_by  = $_SESSION['user_id'];

// ── Basic validation ─────────────────────────────────────────────
if ($account_id === 0 || $student_id === 0 || $amount <= 0 || empty($method) || empty($or_number)) {
    header("Location: payment_form.php?account_id={$account_id}&error=Please+fill+in+all+fields.");
    exit;
}

$valid_methods = ['Cash', 'GCash', 'Bank Transfer'];
if (!in_array($method, $valid_methods)) {
    header("Location: payment_form.php?account_id={$account_id}&error=Invalid+payment+method.");
    exit;
}

// ── Check OR number uniqueness ───────────────────────────────────
$check = $conn->prepare("SELECT payment_id FROM payments WHERE or_number = ?");
$check->bind_param('s', $or_number);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    header("Location: payment_form.php?account_id={$account_id}&error=OR+number+already+exists.+Please+use+a+different+one.");
    exit;
}

$conn->begin_transaction();

try {
    // ── 1. Insert into payments ──────────────────────────────────
    $stmt1 = $conn->prepare("
        INSERT INTO payments (account_id, student_id, amount, method, or_number, posted_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt1->bind_param('iidssi', $account_id, $student_id, $amount, $method, $or_number, $posted_by);
    $stmt1->execute();

    // ── 2. Insert PAYMENT into student_ledgers (fee_id = NULL) ───
    //    PAYMENT entries are not tied to a specific fee row —
    //    they offset the total balance across all charges.
    $remarks = "OR#{$or_number} - {$method}";
    $stmt2 = $conn->prepare("
        INSERT INTO student_ledgers
            (account_id, fee_id, entry_type, amount, remarks, posted_by)
        VALUES
            (?, NULL, 'PAYMENT', ?, ?, ?)
    ");
    $stmt2->bind_param('idsi', $account_id, $amount, $remarks, $posted_by);
    $stmt2->execute();

    // ── 3. Audit log ─────────────────────────────────────────────
    $action = "Posted payment {$or_number} of ₱" . number_format($amount, 2) . " for account #{$account_id} via {$method}";
    $stmt3  = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $stmt3->bind_param('is', $posted_by, $action);
    $stmt3->execute();

    $conn->commit();
    header("Location: payment_form.php?account_id={$account_id}&success=1");

} catch (Exception $e) {
    $conn->rollback();
    header("Location: payment_form.php?account_id={$account_id}&error=Something+went+wrong.+Please+try+again.");
}
exit;
?>