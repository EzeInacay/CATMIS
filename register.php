<?php
/**
 * register.php  — public self-registration endpoint
 *
 * Each fee from tuition_fees is posted as its own CHARGE row in
 * student_ledgers, linked by fee_id for a full audit trail.
 *
 * Expected POST fields:
 *   student_number (students only), full_name, email, password,
 *   role, grade_level, section, section_id,
 *   strand (Grade 11-12 only: STEM | ABM | HUMSS)
 */

include 'php/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// ── Collect & sanitise inputs ────────────────────────────────────
$student_number = trim($_POST['student_number'] ?? '') ?: null;
$full_name      = trim($_POST['full_name']      ?? '');
$email          = trim($_POST['email']          ?? '');
$raw_password   = $_POST['password']            ?? '';
$role           = $_POST['role']                ?? '';
$grade_level    = trim($_POST['grade_level']    ?? '');
$section        = trim($_POST['section']        ?? '');
$section_id     = intval($_POST['section_id']   ?? 0);
$strand         = trim($_POST['strand']         ?? '');

// ── Basic validation ─────────────────────────────────────────────
if (!$full_name || !$email || !$raw_password || !in_array($role, ['student', 'teacher'])) {
    echo 'Error: Missing or invalid required fields.';
    exit;
}

$password = password_hash($raw_password, PASSWORD_DEFAULT);

$conn->begin_transaction();

try {
    // ── 1. Insert user ───────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO users (student_number, email, password, full_name, role, status)
        VALUES (?, ?, ?, ?, ?, 'active')
    ");
    $stmt->bind_param('sssss', $student_number, $email, $password, $full_name, $role);
    $stmt->execute();
    $user_id = $conn->insert_id;

    // ── 2. Role-specific inserts ─────────────────────────────────
    if ($role === 'student') {

        if (!$grade_level || !$section_id) {
            throw new Exception('Grade level and section are required for students.');
        }

        // 2a. Insert student row
        $stmt = $conn->prepare("
            INSERT INTO students (user_id, section_id, grade_level, section)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('iiss', $user_id, $section_id, $grade_level, $section);
        $stmt->execute();
        $student_id = $conn->insert_id;

        // 2b. Get active school year
        $syRes = $conn->query("SELECT sy_id, name FROM school_years WHERE status = 'active' LIMIT 1");
        if ($syRes->num_rows === 0) {
            throw new Exception('No active school year configured.');
        }
        $sy = $syRes->fetch_assoc();
        $sy_id   = $sy['sy_id'];
        $sy_name = $sy['name'];

        // 2c. Determine grade group
        $grade = intval($grade_level);
        if      ($grade >= 1  && $grade <= 3)  $grade_group = '1-3';
        elseif  ($grade >= 4  && $grade <= 6)  $grade_group = '4-6';
        elseif  ($grade >= 7  && $grade <= 10) $grade_group = '7-10';
        elseif  ($grade >= 11 && $grade <= 12) $grade_group = '11-12';
        else throw new Exception("Invalid grade level: {$grade_level}");

        // 2d. Fetch individual fee rows from tuition_fees
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
            throw new Exception("No fees configured for this grade/strand. Contact your administrator.");
        }

        // 2e. Compute totals
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

        // 2f. Insert tuition account
        $stmt = $conn->prepare("
            INSERT INTO tuition_accounts (student_id, sy_id, base_fee, misc_fee, balance)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiddd', $student_id, $sy_id, $base_fee, $misc_fee, $total_balance);
        $stmt->execute();
        $account_id = $conn->insert_id;

        // 2g. Post one CHARGE ledger entry PER fee row (linked by fee_id)
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
                $user_id
            );
            $ledgerStmt->execute();
        }

    } elseif ($role === 'teacher') {

        // 2h. Insert teacher row
        $stmt = $conn->prepare("INSERT INTO teachers (user_id) VALUES (?)");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }

    $conn->commit();
    echo 'Registration successful!';

} catch (Exception $e) {
    $conn->rollback();
    echo 'Error: ' . $e->getMessage();
}
?>