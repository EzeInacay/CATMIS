<?php
/**
 * php/add_student.php  — admin-facing student creation endpoint
 *
 * Creates user → student → tuition_account in one transaction.
 * Posts one CHARGE ledger row per fee in tuition_fees, each
 * linked by fee_id so the full breakdown is permanently recorded.
 *
 * Expected POST fields:
 *   student_number, full_name, email, password,
 *   section_id (FK → sections), grade_level, section,
 *   strand (Grade 11-12 only: STEM | ABM | HUMSS)
 */

include 'config.php';
session_start();

// ── Auth guard ───────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

// ── Collect & validate inputs ────────────────────────────────────
$student_number = trim($_POST['student_number'] ?? '') ?: null;
$full_name      = trim($_POST['full_name']      ?? '');
$email          = trim($_POST['email']          ?? '');
$raw_password   = $_POST['password']            ?? '';
$section_id     = intval($_POST['section_id']   ?? 0);
$grade_level    = trim($_POST['grade_level']    ?? '');
$section        = trim($_POST['section']        ?? '');
$strand         = trim($_POST['strand']         ?? '');

if (!$full_name || !$email || !$raw_password || !$section_id || !$grade_level) {
    echo json_encode(['error' => 'Missing required fields.']);
    exit;
}

$password  = password_hash($raw_password, PASSWORD_DEFAULT);
$posted_by = $_SESSION['user_id'];

$conn->begin_transaction();

try {
    // ── 1. Get active school year ────────────────────────────────
    $syRes = $conn->query("SELECT sy_id, name FROM school_years WHERE status = 'active' LIMIT 1");
    if ($syRes->num_rows === 0) {
        throw new Exception('No active school year found.');
    }
    $sy      = $syRes->fetch_assoc();
    $sy_id   = $sy['sy_id'];
    $sy_name = $sy['name'];

    // ── 2. Determine grade group ─────────────────────────────────
    $grade = intval($grade_level);
    if      ($grade >= 1  && $grade <= 3)  $grade_group = '1-3';
    elseif  ($grade >= 4  && $grade <= 6)  $grade_group = '4-6';
    elseif  ($grade >= 7  && $grade <= 10) $grade_group = '7-10';
    elseif  ($grade >= 11 && $grade <= 12) $grade_group = '11-12';
    else throw new Exception("Invalid grade level: {$grade_level}");

    // ── 3. Fetch individual fee rows ─────────────────────────────
    if ($grade_group === '11-12') {
        if (empty($strand)) {
            throw new Exception('Strand is required for Grade 11-12.');
        }
        $feeStmt = $conn->prepare("
            SELECT fee_id, label, amount
            FROM tuition_fees
            WHERE sy_id = ? AND grade_group = ? AND strand = ?
            ORDER BY sort_order ASC
        ");
        $feeStmt->bind_param('iss', $sy_id, $grade_group, $strand);
    } else {
        $feeStmt = $conn->prepare("
            SELECT fee_id, label, amount
            FROM tuition_fees
            WHERE sy_id = ? AND grade_group = ? AND (strand IS NULL OR strand = '')
            ORDER BY sort_order ASC
        ");
        $feeStmt->bind_param('is', $sy_id, $grade_group);
    }
    $feeStmt->execute();
    $fees = $feeStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($fees)) {
        throw new Exception(
            "No fees configured for grade group '{$grade_group}'" .
            ($grade_group === '11-12' ? " / strand '{$strand}'" : '') . '.'
        );
    }

    // ── 4. Compute base/misc totals ──────────────────────────────
    $base_fee = 0;
    $misc_fee = 0;
    foreach ($fees as $fee) {
        if ($fee['label'] === 'Tuition Fee') {
            $base_fee += $fee['amount'];
        } else {
            $misc_fee += $fee['amount'];
        }
    }
    $total_balance = $base_fee + $misc_fee;

    // ── 5. Insert user ───────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO users (student_number, email, password, full_name, role, status)
        VALUES (?, ?, ?, ?, 'student', 'active')
    ");
    $stmt->bind_param('ssss', $student_number, $email, $password, $full_name);
    $stmt->execute();
    $user_id = $conn->insert_id;

    // ── 6. Insert student ────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO students (user_id, section_id, grade_level, section)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iiss', $user_id, $section_id, $grade_level, $section);
    $stmt->execute();
    $student_id = $conn->insert_id;

    // ── 7. Insert tuition account ────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO tuition_accounts (student_id, sy_id, base_fee, misc_fee, balance)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iiddd', $student_id, $sy_id, $base_fee, $misc_fee, $total_balance);
    $stmt->execute();
    $account_id = $conn->insert_id;

    // ── 8. Post one CHARGE per fee row (fee_id linked) ───────────
    $ledgerStmt = $conn->prepare("
        INSERT INTO student_ledgers
            (account_id, fee_id, entry_type, amount, remarks, posted_by)
        VALUES
            (?, ?, 'CHARGE', ?, ?, ?)
    ");
    foreach ($fees as $fee) {
        $remarks = "SY {$sy_name} — {$fee['label']}";
        $ledgerStmt->bind_param('iidsi',
            $account_id,
            $fee['fee_id'],
            $fee['amount'],
            $remarks,
            $posted_by
        );
        $ledgerStmt->execute();
    }

    // ── 9. Audit log ─────────────────────────────────────────────
    $action = "Created student account for {$full_name} (ID: {$student_number})";
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $stmt->bind_param('is', $posted_by, $action);
    $stmt->execute();

    $conn->commit();
    echo json_encode([
        'success'    => true,
        'student_id' => $student_id,
        'account_id' => $account_id,
        'fees_posted' => count($fees),
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['error' => $e->getMessage()]);
}
?>