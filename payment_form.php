<?php
session_start();
include 'config.php';

// Guard: must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$account_id = intval($_GET['account_id'] ?? 0);

if ($account_id === 0) {
    die('Invalid account.');
}

// Get student info + current balance
$stmt = $conn->prepare("
    SELECT
        u.full_name,
        s.student_id,
        s.grade_level,
        s.section,
        ta.account_id,
        (
            SELECT COALESCE(
                SUM(CASE WHEN entry_type='CHARGE'  THEN amount ELSE 0 END) -
                SUM(CASE WHEN entry_type='PAYMENT' THEN amount ELSE 0 END) -
                SUM(CASE WHEN entry_type='DISCOUNT' THEN amount ELSE 0 END) +
                SUM(CASE WHEN entry_type='PENALTY'  THEN amount ELSE 0 END),
            0)
            FROM student_ledgers
            WHERE account_id = ta.account_id
        ) AS balance
    FROM tuition_accounts ta
    JOIN students s ON ta.student_id = s.student_id
    JOIN users u    ON s.user_id = u.user_id
    WHERE ta.account_id = ?
");
$stmt->bind_param('i', $account_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die('Account not found.');
}

$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Post Payment | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #eef1f4;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px 16px;
}

.card {
    background: white;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    padding: 40px 44px;
    width: 100%;
    max-width: 480px;
}

.card-header {
    margin-bottom: 28px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f1f5f9;
}

.card-header h2 {
    font-size: 22px;
    color: #0f2027;
    margin-bottom: 16px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #64748b;
    margin-bottom: 6px;
}

.info-row span:last-child {
    font-weight: 600;
    color: #0f2027;
}

.balance-row span:last-child {
    color: #dc2626;
    font-size: 18px;
}

/* Form */
.field { margin-bottom: 20px; }

.field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 6px;
}

.field input,
.field select {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 14px;
    color: #0f2027;
    background: #f8fafc;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.field input:focus,
.field select:focus {
    border-color: #0077b6;
    background: white;
    box-shadow: 0 0 0 3px rgba(0,119,182,0.10);
}

.btn-row {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.btn-submit {
    flex: 1;
    padding: 13px;
    background: #0077b6;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-submit:hover { background: #005f8e; }

.btn-cancel {
    padding: 13px 20px;
    background: #f1f5f9;
    color: #64748b;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    transition: background 0.2s;
}
.btn-cancel:hover { background: #e2e8f0; }

/* Success banner */
.success-banner {
    background: #d1fae5;
    border-left: 4px solid #10b981;
    color: #065f46;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 24px;
}

/* Error banner */
.error-banner {
    background: #fef2f2;
    border-left: 4px solid #ef4444;
    color: #b91c1c;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 24px;
}
</style>
</head>
<body>

<div class="card">

    <div class="card-header">
        <h2>Post Payment</h2>

        <div class="info-row">
            <span>Student Name</span>
            <span><?= htmlspecialchars($data['full_name']) ?></span>
        </div>
        <div class="info-row">
            <span>Grade & Section</span>
            <span>Grade <?= htmlspecialchars($data['grade_level']) ?> – <?= htmlspecialchars($data['section']) ?></span>
        </div>
        <div class="info-row balance-row" style="margin-top:10px;">
            <span>Remaining Balance</span>
            <span>₱<?= number_format($data['balance'], 2) ?></span>
        </div>
    </div>

    <?php if ($success === '1'): ?>
        <div class="success-banner">✅ Payment posted successfully!</div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="error-banner">⚠ <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <form method="POST" action="add_payment.php">
        <input type="hidden" name="account_id" value="<?= $data['account_id'] ?>">
        <input type="hidden" name="student_id" value="<?= $data['student_id'] ?>">

        <div class="field">
            <label>Amount (₱)</label>
            <input
                type="number"
                name="amount"
                min="1"
                max="<?= $data['balance'] ?>"
                step="0.01"
                placeholder="0.00"
                required
            >
        </div>

        <div class="field">
            <label>Payment Method</label>
            <select name="method" required>
                <option value="">— Select method —</option>
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
                <option value="Bank Transfer">Bank Transfer</option>
            </select>
        </div>

        <div class="field">
            <label>OR Number</label>
            <input
                type="text"
                name="or_number"
                placeholder="e.g. OR-2026-0001"
                required
            >
        </div>

        <div class="btn-row">
            <a href="admin_dashboard.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-submit">Post Payment</button>
        </div>
    </form>

</div>

</body>
</html>