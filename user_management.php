<?php
session_start();
include 'php/config.php';
include 'php/mailer.php';
include 'php/notify.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    header('Location: login.php');
    exit;
}

// ── AJAX handlers ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // CREATE USER
    if ($action === 'create_user') {
        $student_number = trim($_POST['student_number'] ?? '') ?: null;
        $full_name      = trim($_POST['full_name']      ?? '');
        $email          = trim($_POST['email']          ?? '');
        $role           = trim($_POST['role']           ?? '');
        $raw_password   = $_POST['password']            ?? '';
        $status         = trim($_POST['status']         ?? 'active');
        $contact_number = trim($_POST['contact_number'] ?? '') ?: null;
        $address        = trim($_POST['address']        ?? '') ?: null;

        if (!$full_name || !$email || !$role || !$raw_password) {
            echo json_encode(['error' => 'Missing required fields.']); exit;
        }
        if ($student_number !== null && strlen($student_number) > 12) {
            echo json_encode(['error' => 'Student number must be at most 12 characters.']); exit;
        }
        $allowedRoles = $_SESSION['role'] === 'superadmin'
            ? ['teacher', 'student', 'admin']
            : ['teacher', 'student'];

        if (!in_array($role, $allowedRoles)) {
            if ($role === 'admin') {
                echo json_encode(['error' => 'Only a super admin can create admin accounts.']); exit;
            }
            echo json_encode(['error' => 'Invalid role.']); exit;
        }

        // Check email/student_number uniqueness
        if ($student_number !== null) {
            $chk = $conn->prepare("SELECT user_id, email, student_number FROM users WHERE email = ? OR student_number = ?");
            $chk->bind_param('ss', $email, $student_number);
        } else {
            $chk = $conn->prepare("SELECT user_id, email, student_number FROM users WHERE email = ?");
            $chk->bind_param('s', $email);
        }
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if ($existing) {
            if ($existing['email'] === $email) {
                echo json_encode(['error' => 'Email already in use.']); exit;
            }
            if ($student_number !== null && $existing['student_number'] === $student_number) {
                echo json_encode(['error' => 'Student number already in use.']); exit;
            }
        }

        $password = password_hash($raw_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO users (student_number, email, password, full_name, role, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssss', $student_number, $email, $password, $full_name, $role, $status);
        $stmt->execute();
        $new_id = $conn->insert_id;

        // Insert into role table
        if ($role === 'student') {
            $s = $conn->prepare("INSERT INTO students (user_id, contact_number, address) VALUES (?, ?, ?)");
            $s->bind_param('iss', $new_id, $contact_number, $address); $s->execute();
        } elseif ($role === 'teacher') {
            $t = $conn->prepare("INSERT INTO teachers (user_id) VALUES (?)");
            $t->bind_param('i', $new_id); $t->execute();
        }

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Created user account: {$full_name} ({$role})";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        // Email the new user their credentials (students only — they need to know their ID + password)
        if ($role === 'student' && !empty($email) && !empty($student_number)) {
            try {
                mailAccountCreated($email, $full_name, $student_number, $raw_password);
            } catch (\Throwable $e) {
                error_log('Mail send failed: ' . $e->getMessage());
            }
        }
        pushNotification($conn, 'new_account', 'New Account Created', "Account created for {$full_name} ({$role})", 'user_management.php');

        echo json_encode(['success' => true, 'user_id' => $new_id]); exit;
    }

    // UPDATE USER
    if ($action === 'update_user') {
        $user_id        = intval($_POST['user_id']       ?? 0);
        $full_name      = trim($_POST['full_name']       ?? '');
        $email          = trim($_POST['email']           ?? '');
        $student_number = trim($_POST['student_number']  ?? '') ?: null;
        $status         = trim($_POST['status']          ?? 'active');
        $raw_password   = trim($_POST['password']        ?? '');
        $contact_number = trim($_POST['contact_number']  ?? '') ?: null;
        $address        = trim($_POST['address']         ?? '') ?: null;

        if (!$user_id || !$full_name || !$email) {
            echo json_encode(['error' => 'Missing fields.']); exit;
        }
        if ($student_number !== null && strlen($student_number) > 12) {
            echo json_encode(['error' => 'Student number must be at most 12 characters.']); exit;
        }

        // Check email/student_number uniqueness (excluding this user)
        if ($student_number !== null) {
            $chk = $conn->prepare("SELECT user_id, email, student_number FROM users WHERE (email = ? OR student_number = ?) AND user_id != ?");
            $chk->bind_param('ssi', $email, $student_number, $user_id);
        } else {
            $chk = $conn->prepare("SELECT user_id, email, student_number FROM users WHERE email = ? AND user_id != ?");
            $chk->bind_param('si', $email, $user_id);
        }
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if ($existing) {
            if ($existing['email'] === $email) {
                echo json_encode(['error' => 'Email already in use.']); exit;
            }
            if ($student_number !== null && $existing['student_number'] === $student_number) {
                echo json_encode(['error' => 'Student number already in use.']); exit;
            }
        }

        if ($raw_password) {
            $password = password_hash($raw_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, student_number=?, status=?, password=? WHERE user_id=?");
            $stmt->bind_param('sssssi', $full_name, $email, $student_number, $status, $password, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, student_number=?, status=? WHERE user_id=?");
            $stmt->bind_param('ssssi', $full_name, $email, $student_number, $status, $user_id);
        }
        $stmt->execute();

        // Update student-specific contact/address (no-op if user isn't a student)
        $sUpd = $conn->prepare("UPDATE students SET contact_number=?, address=? WHERE user_id=?");
        $sUpd->bind_param('ssi', $contact_number, $address, $user_id);
        $sUpd->execute();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Updated user account ID #{$user_id}: {$full_name}";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        echo json_encode(['success' => true]); exit;
    }

    // TOGGLE STATUS
    if ($action === 'toggle_status') {
        $user_id  = intval($_POST['user_id'] ?? 0);
        $raw      = $_POST['confirm_password'] ?? '';
        $adminRow = $conn->prepare("SELECT password FROM users WHERE user_id=?");
        $adminRow->bind_param('i', $_SESSION['user_id']); $adminRow->execute();
        $adminPw  = $adminRow->get_result()->fetch_assoc()['password'];
        if (!password_verify($raw, $adminPw)) {
            echo json_encode(['error' => 'Incorrect password.']); exit;
        }
        $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE user_id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        // Return new status
        $res = $conn->prepare("SELECT status FROM users WHERE user_id=?");
        $res->bind_param('i', $user_id); $res->execute();
        $newStatus = $res->get_result()->fetch_assoc()['status'];
        echo json_encode(['success' => true, 'status' => $newStatus]); exit;
    }

    // DELETE USER (superadmin only)
    if ($action === 'delete_user') {
        if ($_SESSION['role'] !== 'superadmin') {
            echo json_encode(['error' => 'Only a super admin can delete accounts.']); exit;
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $raw     = $_POST['confirm_password'] ?? '';
        $adminRow = $conn->prepare("SELECT password FROM users WHERE user_id=?");
        $adminRow->bind_param('i', $_SESSION['user_id']); $adminRow->execute();
        $adminPw  = $adminRow->get_result()->fetch_assoc()['password'];
        if (!password_verify($raw, $adminPw)) {
            echo json_encode(['error' => 'Incorrect password.']); exit;
        }
        // Prevent self-deletion
        if ($user_id === $_SESSION['user_id']) {
            echo json_encode(['error' => 'You cannot delete your own account.']); exit;
        }
        // Soft delete — move to bin. Permanently purged after 30 days.
        $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW() WHERE user_id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Moved user account ID #{$user_id} to Bin";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        echo json_encode(['success' => true]); exit;
    }

    // ── RESTORE ACCOUNT FROM BIN (superadmin only) ────────────────
    if ($action === 'restore_user') {
        if ($_SESSION['role'] !== 'superadmin') {
            echo json_encode(['error' => 'Only a super admin can restore accounts.']); exit;
        }
        $user_id = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET deleted_at = NULL WHERE user_id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Restored user account ID #{$user_id} from Bin";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        echo json_encode(['success' => true]); exit;
    }

    // ── PERMANENTLY DELETE FROM BIN (superadmin only) ─────────────
    if ($action === 'permanent_delete_user') {
        if ($_SESSION['role'] !== 'superadmin') {
            echo json_encode(['error' => 'Only a super admin can permanently delete accounts.']); exit;
        }
        include __DIR__ . '/php/account_deletion.php';

        $user_id = intval($_POST['user_id'] ?? 0);
        $raw     = $_POST['confirm_password'] ?? '';
        $adminRow = $conn->prepare("SELECT password FROM users WHERE user_id=?");
        $adminRow->bind_param('i', $_SESSION['user_id']); $adminRow->execute();
        $adminPw  = $adminRow->get_result()->fetch_assoc()['password'];
        if (!password_verify($raw, $adminPw)) {
            echo json_encode(['error' => 'Incorrect password.']); exit;
        }
        // Only permanently delete accounts that are already in the bin
        $chk = $conn->prepare("SELECT deleted_at, full_name FROM users WHERE user_id=?");
        $chk->bind_param('i', $user_id); $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if (!$row || $row['deleted_at'] === null) {
            echo json_encode(['error' => 'Account must be in the Bin before permanent deletion.']); exit;
        }

        $result = purgeUserAccount($conn, $user_id);
        if (!$result['success']) {
            echo json_encode(['error' => $result['error']]); exit;
        }

        // The user row is already gone, so log against the admin performing the action
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Permanently deleted user account: {$row['full_name']} (ID #{$user_id})";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        echo json_encode(['success' => true]); exit;
    }

    // ── BULK IMPORT STUDENTS ─────────────────────────────────────
    if ($action === 'bulk_import') {
        $rows = json_decode($_POST['rows'], true);
        if (!$rows) { echo json_encode(['error' => 'No data received.']); exit; }

        // Get active school year
        $sy = $conn->query("SELECT sy_id, name FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
        if (!$sy) { echo json_encode(['error' => 'No active school year found.']); exit; }
        $sy_id   = $sy['sy_id'];
        $sy_name = $sy['name'];

        $created = 0; $skipped = 0; $results = [];

        foreach ($rows as $row) {
            $student_number = trim($row['student_number'] ?? '');
            $full_name      = trim($row['full_name']      ?? '');
            $email          = trim($row['email']          ?? '');
            $grade_level    = trim($row['grade_level']    ?? ''); // e.g. "11"
            $section_name   = trim($row['section']        ?? ''); // e.g. "STEM-A"
            $strand         = trim($row['strand']         ?? ''); // e.g. "STEM", "ABM", "HUMSS"

            if (!$student_number || !$full_name || !$email || !$grade_level) {
                $skipped++; continue;
            }

            // Check duplicate email or student number
            $chk = $conn->prepare("SELECT user_id FROM users WHERE email=? OR student_number=?");
            $chk->bind_param('ss', $email, $student_number);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) { $skipped++; continue; }

            // Resolve section_id from sections table
            $section_id = null;
            if ($section_name) {
                $sec = $conn->prepare("SELECT section_id FROM sections WHERE section_name=? AND grade_level=? AND sy_id=? LIMIT 1");
                $sec->bind_param('ssi', $section_name, $grade_level, $sy_id);
                $sec->execute();
                $sec_row    = $sec->get_result()->fetch_assoc();
                $section_id = $sec_row['section_id'] ?? null;
            }

            // Determine grade_group for tuition_fees lookup
            $gl = intval($grade_level);
            if      ($gl >= 1  && $gl <= 3)  $grade_group = '1-3';
            elseif  ($gl >= 4  && $gl <= 6)  $grade_group = '4-6';
            elseif  ($gl >= 7  && $gl <= 10) $grade_group = '7-10';
            elseif  ($gl >= 11 && $gl <= 12) $grade_group = '11-12';
            else { $skipped++; continue; }

            // Fetch fee line items
            if ($grade_group === '11-12' && $strand) {
                $fee_stmt = $conn->prepare("SELECT label, amount FROM tuition_fees WHERE sy_id=? AND grade_group=? AND strand=? ORDER BY sort_order");
                $fee_stmt->bind_param('iss', $sy_id, $grade_group, $strand);
            } else {
                $fee_stmt = $conn->prepare("SELECT label, amount FROM tuition_fees WHERE sy_id=? AND grade_group=? AND strand IS NULL ORDER BY sort_order");
                $fee_stmt->bind_param('is', $sy_id, $grade_group);
            }
            $fee_stmt->execute();
            $fees = $fee_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($fees)) { $skipped++; continue; } // No fee config = skip

            // Split into base_fee (Tuition Fee line) and misc_fee (everything else)
            $base_fee = 0.00; $misc_fee = 0.00;
            foreach ($fees as $fee) {
                if ($fee['label'] === 'Tuition Fee') $base_fee += $fee['amount'];
                else                                 $misc_fee += $fee['amount'];
            }
            $total = $base_fee + $misc_fee;

            // Generate random password
            $raw_password = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
            $hashed = password_hash($raw_password, PASSWORD_DEFAULT);
            $role   = 'student';
            $status = 'active';

            // Insert user
            $u = $conn->prepare("INSERT INTO users (student_number, email, password, full_name, role, status) VALUES (?,?,?,?,?,?)");
            $u->bind_param('ssssss', $student_number, $email, $hashed, $full_name, $role, $status);
            $u->execute();
            $new_user_id = $conn->insert_id;

            // Insert student record
            $s = $conn->prepare("INSERT INTO students (user_id, section_id, grade_level, section) VALUES (?,?,?,?)");
            $s->bind_param('iiss', $new_user_id, $section_id, $grade_level, $section_name);
            $s->execute();
            $new_student_id = $conn->insert_id;

            // Create tuition_account
            $zero = 0.00;
            $ta = $conn->prepare("INSERT INTO tuition_accounts (student_id, sy_id, base_fee, misc_fee, discount, penalties, balance) VALUES (?,?,?,?,?,?,?)");
            $ta->bind_param('iiddddd', $new_student_id, $sy_id, $base_fee, $misc_fee, $zero, $zero, $total);
            $ta->execute();
            $account_id = $conn->insert_id;

            // Insert CHARGE ledger entry
            $remarks = "SY {$sy_name} Total Assessment";
            $ltype   = 'CHARGE';
            $admin   = $_SESSION['user_id'];
            $l = $conn->prepare("INSERT INTO student_ledgers (account_id, entry_type, amount, remarks, posted_by) VALUES (?,?,?,?,?)");
            $l->bind_param('isdsi', $account_id, $ltype, $total, $remarks, $admin);
            $l->execute();

            // Audit log
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?,?)");
            $act = "Bulk import created student: {$full_name} ({$student_number}) — Grade {$grade_level} {$section_name}";
            $log->bind_param('is', $admin, $act); $log->execute();

            // Email student their credentials
            if (!empty($email)) {
                try {
                    mailAccountCreated($email, $full_name, $student_number, $raw_password);
                } catch (\Throwable $e) {
                    error_log('Mail send failed: ' . $e->getMessage());
                }
            }

            $results[] = [
                'name'           => $full_name,
                'student_number' => $student_number,
                'password'       => $raw_password,
                'grade'          => "Grade {$grade_level} {$section_name}",
                'tuition'        => $total,
            ];
            $created++;
        }

        echo json_encode(['success' => true, 'created' => $created, 'skipped' => $skipped, 'results' => $results]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action.']); exit;
}

// ── Unread notifications count ───────────────────────────────────
$_uid = $_SESSION['user_id'];
$_nRes = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE admin_id=? AND is_read=0");
$_nRes->bind_param('i', $_uid);
$_nRes->execute();
$unreadNotifs = $_nRes->get_result()->fetch_assoc()['cnt'] ?? 0;

// ── Load users (excluding deleted/bin) ────────────────────────────
$users = $conn->query("
    SELECT u.user_id, u.student_number, u.full_name, u.email, u.role, u.status, u.created_at,
           s.contact_number, s.address
    FROM users u
    LEFT JOIN students s ON s.user_id = u.user_id
    WHERE u.deleted_at IS NULL
    ORDER BY u.role ASC, u.full_name ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Load bin (soft-deleted accounts, auto-purged after 30 days) ───
$binUsers = $conn->query("
    SELECT user_id, student_number, full_name, email, role, deleted_at,
           DATEDIFF(NOW(), deleted_at) AS days_in_bin
    FROM users
    WHERE deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #eef1f4; }

/* ===== TOP NAVBAR ===== */
.navbar {
    position: fixed; top: 0; left: 0; right: 0; height: 60px;
    background: linear-gradient(90deg, #0f2027, #203a43);
    display: flex; align-items: center; padding: 0 24px;
    z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.navbar-brand { display: flex; align-items: baseline; gap: 10px; text-decoration: none; margin-right: 32px; flex-shrink: 0; }
.navbar-brand h2 { margin: 0; color: #fff; font-size: 20px; letter-spacing: -0.5px; }
.navbar-brand span { font-size: 11px; color: rgba(255,255,255,0.45); letter-spacing: 1px; }
.navbar-links { display: flex; align-items: center; gap: 2px; flex: 1; }
.navbar-links a {
    color: rgba(255,255,255,0.7); text-decoration: none; padding: 8px 13px;
    border-radius: 6px; font-size: 13.5px; white-space: nowrap; transition: background 0.18s, color 0.18s;
}
.navbar-links a:hover { background: rgba(255,255,255,0.1); color: #fff; }
.navbar-links a.active { background: rgba(255,255,255,0.15); color: #fff; }
.navbar-right { margin-left: auto; flex-shrink: 0; display: flex; align-items: center; gap: 14px; }
.logout-btn {
    background: #ff3b30; border: none; color: white; padding: 7px 16px;
    border-radius: 6px; cursor: pointer; font-size: 13px;
    font-family: 'Segoe UI', Arial, sans-serif; transition: background 0.18s;
}
.logout-btn:hover { background: #d0302a; }

/* ===== MAIN ===== */
.main { margin-top: 60px; padding: 30px; }

.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; flex-wrap: wrap; gap: 12px; }
.page-header h2 { margin: 0; font-size: 24px; color: #0f2027; }
.page-header-btns { display: flex; gap: 8px; flex-wrap: wrap; }

.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.search-box {
    padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; width: 260px; outline: none;
}
.search-box:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }

.btn { padding: 9px 15px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: 'Segoe UI', Arial, sans-serif; display: inline-flex; align-items: center; gap: 5px; transition: background 0.18s; white-space: nowrap; }
.btn-primary  { background: #0077b6; color: white; }
.btn-primary:hover  { background: #005f8e; }
.btn-success  { background: #198754; color: white; }
.btn-success:hover  { background: #157347; }
.btn-outline  { background: white; color: #374151; border: 1px solid #cbd5e1; }
.btn-outline:hover  { background: #f8fafc; }
.btn-teal     { background: #0e7490; color: white; }
.btn-teal:hover     { background: #0c6478; }
.btn-outline.active-filter { background: #0f2027; color: white; border-color: #0f2027; }

.filter-group { display: flex; gap: 6px; flex-wrap: wrap; }

/* ===== TABLE ===== */
.table-wrap { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { background: #f2f4f7; padding: 13px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #374151; }
td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }

.role-badge {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600; text-transform: capitalize;
}
.role-admin   { background: #fff3e0; color: #b45309; }
.role-teacher { background: #e0f2fe; color: #0369a1; }
.role-student { background: #f0fdf4; color: #166534; }

.status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.status-active   { background: #d1fae5; color: #065f46; }
.status-inactive { background: #fee2e2; color: #991b1b; }

.action-btn { padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; font-family: 'Segoe UI', Arial, sans-serif; transition: background 0.18s; }
.btn-edit   { background: #e0f2fe; color: #0369a1; }
.btn-edit:hover   { background: #bae6fd; }
.btn-toggle { background: #fef3c7; color: #92400e; }
.btn-toggle:hover { background: #fde68a; }
.btn-del    { background: #fee2e2; color: #991b1b; }
.btn-del:hover    { background: #fecaca; }

/* ===== MODAL ===== */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 500;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: white; border-radius: 14px; padding: 32px 36px;
    width: 100%; max-width: 460px; box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    max-height: 90vh; overflow-y: auto;
}
.modal h3 { margin: 0 0 22px; font-size: 18px; color: #0f2027; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-field { margin-bottom: 0; }
.form-field.full { grid-column: 1 / -1; }
.form-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 5px; }
.form-field input, .form-field select {
    width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: 14px; color: #0f2027; font-family: 'Segoe UI', Arial, sans-serif;
    outline: none; transition: border-color 0.2s;
}
.form-field input:focus, .form-field select:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }
.modal-actions { display: flex; gap: 10px; margin-top: 24px; }
.btn-save  { flex: 1; padding: 11px; background: #0077b6; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-save:hover { background: #005f8e; }
.btn-cancel { padding: 11px 20px; background: #f1f5f9; color: #64748b; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
.btn-cancel:hover { background: #e2e8f0; }

/* ===== IMPORT MODALS ===== */
.import-modal {
    background: white; border-radius: 14px; padding: 28px 32px;
    width: 100%; max-width: 660px; box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    max-height: 90vh; overflow-y: auto;
}
.import-modal h3 { margin: 0 0 6px; font-size: 18px; color: #0f2027; }
.import-modal .sub { font-size: 13px; color: #64748b; margin-bottom: 18px; }
.preview-scroll { max-height: 280px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 18px; }
.preview-scroll table { width: 100%; border-collapse: collapse; font-size: 13px; }
.preview-scroll thead { position: sticky; top: 0; background: #f2f4f7; }
.preview-scroll th { padding: 9px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; }
.preview-scroll td { padding: 7px 12px; border-top: 1px solid #f1f5f9; color: #0f2027; }
.summary-cards { display: flex; gap: 16px; margin-bottom: 18px; }
.summary-card { flex: 1; border-radius: 8px; padding: 14px; text-align: center; }
.summary-card .num { font-size: 28px; font-weight: 700; }
.summary-card .lbl { font-size: 12px; margin-top: 2px; }
.card-green { background: #f0fdf4; }
.card-green .num, .card-green .lbl { color: #166534; }
.card-amber { background: #fef3c7; }
.card-amber .num, .card-amber .lbl { color: #92400e; }
.pw-warning { font-size: 12px; color: #94a3b8; margin-bottom: 10px; }

.toast { position: fixed; bottom: 24px; right: 24px; background: #0f2027; color: white; padding: 12px 20px; border-radius: 8px; font-size: 14px; z-index: 999; transform: translateY(20px); opacity: 0; transition: all 0.3s; pointer-events: none; }
.toast.show { transform: translateY(0); opacity: 1; }

.empty-row td { text-align: center; color: #94a3b8; padding: 32px; font-size: 14px; }
</style>
</head>
<body>

<!-- ===== TOP NAVBAR ===== -->
<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php" class="active">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="financial_report.php">📊 Reports</a>
        <?php if ($_SESSION['role'] === 'superadmin'): ?>
        <a href="backup.php">💾 Backup</a>
        <?php endif; ?>
    </div>
    <div class="navbar-right">
        <a href="notifications.php" style="text-decoration:none;position:relative;display:flex;align-items:center;">
            <span style="font-size:20px;">🔔</span>
            <?php if ($unreadNotifs > 0): ?>
            <span style="position:absolute;top:-6px;right:-6px;background:#ff3b30;color:white;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?= min($unreadNotifs,99) ?></span>
            <?php endif; ?>
        </a>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="main">
    <div class="page-header">
        <h2>👥 User Management</h2>
        <div class="page-header-btns">
            <a href="edit_requests_admin.php" class="btn btn-outline" id="editReqBtn">📝 Edit Requests<?php
                $er = $conn->query("SELECT COUNT(*) AS cnt FROM edit_requests WHERE status='pending'")->fetch_assoc();
                if (($er['cnt'] ?? 0) > 0) echo ' <span style="background:#dc2626;color:white;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;">' . $er['cnt'] . '</span>';
            ?></a>
            <button class="btn btn-outline" onclick="downloadTemplate()">⬇ Export Template</button>
            <button class="btn btn-teal" onclick="document.getElementById('importFileInput').click()">📤 Import Excel</button>
            <input type="file" id="importFileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleImport(this)">
            <button class="btn btn-primary" onclick="openCreate()">＋ Create Account</button>
            <?php if ($_SESSION['role'] === 'superadmin'): ?>
            <button class="btn btn-outline" id="binToggleBtn" onclick="toggleBin()">🗑 Bin (<?= count($binUsers) ?>)</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar" id="mainToolbar">
        <input type="text" class="search-box" id="searchInput" placeholder="🔍 Search name, email, or ID…" oninput="applyFilters()">
        <div class="filter-group">
            <button class="btn btn-outline active-filter" onclick="setFilter('all', this)">All</button>
            <button class="btn btn-outline" onclick="setFilter('admin', this)">Admins</button>
            <button class="btn btn-outline" onclick="setFilter('teacher', this)">Teachers</button>
            <button class="btn btn-outline" onclick="setFilter('student', this)">Students</button>
        </div>
        <button class="btn btn-success" onclick="exportExcel()">📥 Export Excel</button>
    </div>

    <!-- Table -->
    <div class="table-wrap" id="mainTableWrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student No.</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="userTable">
            <?php if (empty($users)): ?>
                <tr class="empty-row"><td colspan="8">No users found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr
                    data-role="<?= htmlspecialchars($u['role']) ?>"
                    data-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>"
                    data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>"
                    data-sn="<?= htmlspecialchars(strtolower($u['student_number'] ?? '')) ?>"
                    id="row-<?= $u['user_id'] ?>"
                >
                    <td><?= $u['user_id'] ?></td>
                    <td><?= htmlspecialchars($u['student_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td id="status-<?= $u['user_id'] ?>">
                        <span class="status-badge status-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span>
                    </td>
                    <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                    <td style="white-space:nowrap;">
                        <button class="action-btn btn-edit" onclick='openEdit(<?= htmlspecialchars(json_encode($u)) ?>)'>✏️ Edit</button>
                        <button class="action-btn btn-toggle" id="toggle-<?= $u['user_id'] ?>" onclick="toggleStatus(<?= $u['user_id'] ?>)">
                            <?= $u['status'] === 'active' ? '🔒 Deactivate' : '✅ Activate' ?>
                        </button>
                        <?php if ($u['user_id'] !== $_SESSION['user_id'] && $_SESSION['role'] === 'superadmin'): ?>
                        <button class="action-btn btn-del" onclick="deleteUser(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')">🗑 Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($_SESSION['role'] === 'superadmin'): ?>
    <!-- ===== BIN (soft-deleted accounts) ===== -->
    <div class="table-wrap" id="binTableWrap" style="display:none;">
        <div style="padding:12px 16px;background:#fef3c7;color:#92400e;font-size:13px;border-radius:8px;margin-bottom:12px;">
            ⚠️ Accounts in the Bin are automatically and permanently deleted after <strong>30 days</strong>. You can restore them anytime before then.
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student No.</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Deleted</th>
                    <th>Days Remaining</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="binTable">
            <?php if (empty($binUsers)): ?>
                <tr class="empty-row"><td colspan="8">Bin is empty.</td></tr>
            <?php else: ?>
                <?php foreach ($binUsers as $b): ?>
                <?php $daysLeft = max(0, 30 - intval($b['days_in_bin'])); ?>
                <tr id="binrow-<?= $b['user_id'] ?>">
                    <td><?= $b['user_id'] ?></td>
                    <td><?= htmlspecialchars($b['student_number'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($b['full_name']) ?></td>
                    <td><?= htmlspecialchars($b['email']) ?></td>
                    <td><span class="role-badge role-<?= $b['role'] ?>"><?= ucfirst($b['role']) ?></span></td>
                    <td><?= date('M d, Y', strtotime($b['deleted_at'])) ?></td>
                    <td style="<?= $daysLeft <= 5 ? 'color:#dc2626;font-weight:600;' : '' ?>"><?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?></td>
                    <td style="white-space:nowrap;">
                        <button class="action-btn btn-edit" onclick="restoreUser(<?= $b['user_id'] ?>, '<?= htmlspecialchars(addslashes($b['full_name'])) ?>')">↩️ Restore</button>
                        <button class="action-btn btn-del" onclick="permanentDeleteUser(<?= $b['user_id'] ?>, '<?= htmlspecialchars(addslashes($b['full_name'])) ?>')">🗑 Delete Forever</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ===== CREATE / EDIT MODAL ===== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3 id="modalTitle">Create Account</h3>
        <input type="hidden" id="mUserId">

        <div class="form-grid">
            <div class="form-field">
                <label>First Name</label>
                <input type="text" id="mFirstName" placeholder="e.g. Juan" maxlength="50">
            </div>
            <div class="form-field">
                <label>Middle Name <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                <input type="text" id="mMiddleName" placeholder="e.g. Santos" maxlength="50">
            </div>
            <div class="form-field full">
                <label>Last Name</label>
                <input type="text" id="mLastName" placeholder="e.g. Dela Cruz" maxlength="50">
            </div>
            <div class="form-field">
                <label>Email</label>
                <input type="email" id="mEmail" placeholder="user@catmis.edu.ph">
            </div>
            <div class="form-field">
                <label>Student Number</label>
                <input type="text" id="mStudentNo" placeholder="2025-00001" maxlength="12" pattern="[0-9\-]{1,12}">
            </div>
            <div class="form-field">
                <label>Role</label>
                <select id="mRole">
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                    <?php if ($_SESSION['role'] === 'superadmin'): ?>
                    <option value="admin">Admin</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Status</label>
                <select id="mStatus">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="form-field">
                <label>Contact Number <span style="font-weight:400;color:#94a3b8;">(students)</span></label>
                <input type="text" id="mContactNumber" placeholder="09XXXXXXXXX" maxlength="20">
            </div>
            <div class="form-field full">
                <label>Address <span style="font-weight:400;color:#94a3b8;">(students)</span></label>
                <input type="text" id="mAddress" placeholder="House No., Street, Barangay, City" maxlength="255">
            </div>
            <div class="form-field full">
                <label>Password <span id="pwHint" style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">(leave blank to keep current)</span></label>
                <input type="password" id="mPassword" placeholder="••••••••" autocomplete="new-password">
            </div>
        </div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" id="modalSaveBtn" onclick="saveUser()">Create Account</button>
        </div>
    </div>
</div>

<!-- ===== PASSWORD CONFIRM MODAL ===== -->
<div class="modal-overlay" id="pwConfirmOverlay">
    <div class="modal" style="max-width:380px;">
        <h3 id="pwConfirmTitle">Confirm Action</h3>
        <p id="pwConfirmDesc" style="font-size:14px;color:#475569;margin:-10px 0 18px;"></p>
        <div class="form-field">
            <label>Your Admin Password</label>
            <input type="password" id="pwConfirmInput" placeholder="••••••••" autocomplete="current-password">
            <span id="pwConfirmError" style="color:#dc2626;font-size:12px;margin-top:5px;display:none;"></span>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closePwConfirm()">Cancel</button>
            <button class="btn-save" id="pwConfirmBtn" style="background:#dc2626;">Confirm</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<!-- SheetJS for Excel/CSV parsing -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="js/export_preview_modal.js"></script>

<script>
// ── Filter state ────────────────────────────────────────────────
let currentFilter = 'all';

function setFilter(role, btn) {
    currentFilter = role;
    document.querySelectorAll('.filter-group .btn').forEach(b => b.classList.remove('active-filter'));
    btn.classList.add('active-filter');
    applyFilters();
}

function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#userTable tr[data-role]').forEach(row => {
        const roleOk   = currentFilter === 'all' || row.dataset.role === currentFilter;
        const searchOk = !q || row.dataset.name.includes(q) || row.dataset.email.includes(q) || row.dataset.sn.includes(q);
        row.style.display = roleOk && searchOk ? '' : 'none';
    });
}

// ── Modal ───────────────────────────────────────────────────────
function openCreate() {
    document.getElementById('modalTitle').textContent    = 'Create Account';
    document.getElementById('modalSaveBtn').textContent  = 'Create Account';
    document.getElementById('mUserId').value      = '';
    document.getElementById('mFirstName').value   = '';
    document.getElementById('mMiddleName').value  = '';
    document.getElementById('mLastName').value    = '';
    document.getElementById('mEmail').value       = '';
    document.getElementById('mStudentNo').value   = '';
    document.getElementById('mRole').value        = 'student';
    document.getElementById('mStatus').value      = 'active';
    document.getElementById('mContactNumber').value = '';
    document.getElementById('mAddress').value       = '';
    document.getElementById('mPassword').value    = '';
    document.getElementById('pwHint').style.display = 'none';
    document.getElementById('mPassword').placeholder   = '••••••••';
    document.getElementById('modalOverlay').classList.add('open');
    document.getElementById('mFirstName').focus();
}

function openEdit(user) {
    document.getElementById('modalTitle').textContent    = 'Edit Account';
    document.getElementById('modalSaveBtn').textContent  = 'Save Changes';
    document.getElementById('mUserId').value      = user.user_id;
    // Split stored full_name back into parts for editing
    // Expected format: "Lastname, Firstname Middlename" or just the full_name as-is
    const parts = (user.full_name || '').split(',');
    const lastName  = parts[0] ? parts[0].trim() : '';
    const rest      = parts[1] ? parts[1].trim().split(' ') : [];
    const firstName = rest[0] || '';
    const middleName = rest.slice(1).join(' ');
    document.getElementById('mFirstName').value   = firstName;
    document.getElementById('mMiddleName').value  = middleName;
    document.getElementById('mLastName').value    = lastName;
    document.getElementById('mEmail').value       = user.email;
    document.getElementById('mStudentNo').value   = user.student_number || '';
    document.getElementById('mRole').value        = user.role;
    document.getElementById('mStatus').value      = user.status;
    document.getElementById('mContactNumber').value = user.contact_number || '';
    document.getElementById('mAddress').value       = user.address || '';
    document.getElementById('mPassword').value    = '';
    document.getElementById('pwHint').style.display = '';
    document.getElementById('mPassword').placeholder   = 'Leave blank to keep current';
    document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}

// ── Save (create or update) ─────────────────────────────────────
async function saveUser() {
    const user_id    = document.getElementById('mUserId').value;
    const action     = user_id ? 'update_user' : 'create_user';
    const firstName  = document.getElementById('mFirstName').value.trim();
    const middleName = document.getElementById('mMiddleName').value.trim();
    const lastName   = document.getElementById('mLastName').value.trim();
    const email      = document.getElementById('mEmail').value.trim();
    const role       = document.getElementById('mRole').value;
    const password   = document.getElementById('mPassword').value;

    if (!firstName || !lastName) {
        showToast('Error: First name and last name are required.');
        return;
    }
    if (!email) {
        showToast('Error: Email is required.');
        return;
    }
    if (!user_id && !password) {
        showToast('Error: Password is required for new accounts.');
        return;
    }
    const studentNo = document.getElementById('mStudentNo').value.trim();
    if (studentNo && !/^[0-9\-]{1,12}$/.test(studentNo)) {
        showToast('Error: Student number must be digits and dashes only (max 12 chars).');
        return;
    }

    // Compose full_name: "Lastname, Firstname Middlename" (middle optional)
    const full_name = lastName + ', ' + firstName + (middleName ? ' ' + middleName : '');

    // Confirmation before creating a new account
    if (!user_id) {
        const confirmed = confirm(`Create new ${role} account for "${full_name}" (${email})?`);
        if (!confirmed) return;
    }

    const body = new FormData();
    body.append('action',         action);
    body.append('user_id',        user_id);
    body.append('full_name',      full_name);
    body.append('email',          email);
    body.append('student_number', document.getElementById('mStudentNo').value.trim());
    body.append('role',           role);
    body.append('status',         document.getElementById('mStatus').value);
    body.append('contact_number', document.getElementById('mContactNumber').value.trim());
    body.append('address',        document.getElementById('mAddress').value.trim());
    body.append('password',       password);

    const res  = await fetch('user_management.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        closeModal();
        if (user_id) {
            showToast('✅ Account updated successfully!');
        } else {
            showToast(`✅ Account created successfully for ${full_name}!`);
        }
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast('❌ Error: ' + (data.error || 'Unknown error'));
    }
}

// ── Password Confirm Modal ───────────────────────────────────────
let _pwCallback = null;

function openPwConfirm(title, desc, callback) {
    document.getElementById('pwConfirmTitle').textContent  = title;
    document.getElementById('pwConfirmDesc').textContent   = desc;
    document.getElementById('pwConfirmInput').value        = '';
    document.getElementById('pwConfirmError').style.display = 'none';
    _pwCallback = callback;
    document.getElementById('pwConfirmOverlay').classList.add('open');
    setTimeout(() => document.getElementById('pwConfirmInput').focus(), 80);
}

function closePwConfirm() {
    document.getElementById('pwConfirmOverlay').classList.remove('open');
    _pwCallback = null;
}

document.getElementById('pwConfirmBtn').addEventListener('click', async function () {
    const pw = document.getElementById('pwConfirmInput').value;
    if (!pw) {
        const err = document.getElementById('pwConfirmError');
        err.textContent = 'Please enter your password.';
        err.style.display = 'block';
        return;
    }
    if (_pwCallback) await _pwCallback(pw);
});

document.getElementById('pwConfirmInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') document.getElementById('pwConfirmBtn').click();
});

document.getElementById('pwConfirmOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePwConfirm();
});

// ── Toggle status ───────────────────────────────────────────────
async function toggleStatus(user_id) {
    const currentStatus = document.getElementById('status-' + user_id)?.textContent.trim().toLowerCase();
    const action = currentStatus === 'active' ? 'Deactivate' : 'Activate';
    openPwConfirm(
        action + ' Account',
        `Enter your admin password to ${action.toLowerCase()} this account.`,
        async function(pw) {
            const body = new FormData();
            body.append('action',           'toggle_status');
            body.append('user_id',          user_id);
            body.append('confirm_password', pw);

            const res  = await fetch('user_management.php', { method: 'POST', body });
            const data = await res.json();

            if (data.error) {
                const err = document.getElementById('pwConfirmError');
                err.textContent = data.error;
                err.style.display = 'block';
                return;
            }

            closePwConfirm();
            const newStatus  = data.status;
            const statusCell = document.getElementById('status-' + user_id);
            const toggleBtn  = document.getElementById('toggle-' + user_id);
            statusCell.innerHTML = `<span class="status-badge status-${newStatus}">${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)}</span>`;
            toggleBtn.textContent = newStatus === 'active' ? '🔒 Deactivate' : '✅ Activate';
            showToast('Status updated to ' + newStatus + '.');
        }
    );
}

// ── Delete ──────────────────────────────────────────────────────
async function deleteUser(user_id, name) {
    openPwConfirm(
        'Move to Bin',
        `"${name}" will be moved to the Bin. It can be restored within 30 days, after which it will be permanently deleted.`,
        async function(pw) {
            const body = new FormData();
            body.append('action',           'delete_user');
            body.append('user_id',          user_id);
            body.append('confirm_password', pw);

            const res  = await fetch('user_management.php', { method: 'POST', body });
            const data = await res.json();

            if (data.error) {
                const err = document.getElementById('pwConfirmError');
                err.textContent = data.error;
                err.style.display = 'block';
                return;
            }

            closePwConfirm();
            showToast('Account moved to Bin.');
            setTimeout(() => location.reload(), 700);
        }
    );
}

// ── Bin: show/hide ────────────────────────────────────────────────
function toggleBin() {
    const binWrap   = document.getElementById('binTableWrap');
    const mainWrap  = document.getElementById('mainTableWrap');
    const mainTools = document.getElementById('mainToolbar');
    const btn       = document.getElementById('binToggleBtn');
    const showingBin = binWrap.style.display !== 'none';

    if (showingBin) {
        binWrap.style.display  = 'none';
        mainWrap.style.display = '';
        mainTools.style.display = '';
        btn.classList.remove('active-filter');
    } else {
        binWrap.style.display  = '';
        mainWrap.style.display = 'none';
        mainTools.style.display = 'none';
        btn.classList.add('active-filter');
    }
}

// ── Bin: restore account ────────────────────────────────────────
async function restoreUser(user_id, name) {
    if (!confirm(`Restore "${name}" from the Bin?`)) return;

    const body = new FormData();
    body.append('action',  'restore_user');
    body.append('user_id', user_id);

    const res  = await fetch('user_management.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        showToast('Account restored.');
        setTimeout(() => location.reload(), 700);
    } else {
        showToast('Error: ' + (data.error || 'Unknown error'));
    }
}

// ── Bin: permanently delete account ─────────────────────────────
async function permanentDeleteUser(user_id, name) {
    openPwConfirm(
        'Delete Forever',
        `"${name}" will be permanently deleted. This cannot be undone.`,
        async function(pw) {
            const body = new FormData();
            body.append('action',           'permanent_delete_user');
            body.append('user_id',          user_id);
            body.append('confirm_password', pw);

            const res  = await fetch('user_management.php', { method: 'POST', body });
            const data = await res.json();

            if (data.error) {
                const err = document.getElementById('pwConfirmError');
                err.textContent = data.error;
                err.style.display = 'block';
                return;
            }

            closePwConfirm();
            document.getElementById('binrow-' + user_id)?.remove();
            showToast('Account permanently deleted.');
        }
    );
}

// ── Export current table to CSV ─────────────────────────────────
function exportExcel() {
    const rows = [['ID', 'Student No.', 'Full Name', 'Email', 'Role', 'Status', 'Created']];
    document.querySelectorAll('#userTable tr[data-role]').forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        rows.push([
            cells[0].textContent.trim(),
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].textContent.trim(),
            cells[6].textContent.trim(),
        ]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:6},{wch:14},{wch:28},{wch:32},{wch:10},{wch:10},{wch:14}];
    XLSX.utils.book_append_sheet(wb, ws, 'Users');
    previewAndExport(wb, `CATMIS_Users_${new Date().toISOString().slice(0,10)}.xlsx`);
}
// ── Download import template (Excel with Instructions sheet) ─────
function downloadTemplate() {
    if (typeof XLSX === 'undefined') {
        alert('Excel library not loaded yet. Please wait a moment and try again.');
        return;
    }

    const wb = XLSX.utils.book_new();

    // ── Sheet 1: Import Data (fill this in) ──────────────────────
    const dataRows = [
        // Header row
        ['student_number', 'full_name', 'email', 'grade_level', 'section', 'strand'],
        // Sample rows
        ['2025-00011', 'Dela Cruz, Juan A.', 'juan.delacruz@catmis.edu.ph', '11', 'STEM-A', 'STEM'],
        ['2025-00012', 'Santos, Maria B.',   'maria.santos@catmis.edu.ph',   '7',  'Mabini', ''],
        ['2025-00013', 'Reyes, Carlo D.',    'carlo.reyes@catmis.edu.ph',    '10', 'Emerald',''],
    ];
    const ws1 = XLSX.utils.aoa_to_sheet(dataRows);
    ws1['!cols'] = [
        {wch:16}, {wch:28}, {wch:32}, {wch:12}, {wch:16}, {wch:12}
    ];
    XLSX.utils.book_append_sheet(wb, ws1, 'Import Data');

    // ── Sheet 2: Instructions ─────────────────────────────────────
    const instructions = [
        ['CATMIS Student Import Template — Instructions'],
        [''],
        ['COLUMN', 'REQUIRED?', 'FORMAT / NOTES'],
        ['student_number', 'Yes', 'Unique student ID. e.g. 2025-00001'],
        ['full_name',      'Yes', 'Last, First M. — use comma format'],
        ['email',          'Yes', 'Must be unique. e.g. s00001@catmis.edu.ph'],
        ['grade_level',    'Yes', 'Number only: 1 to 12'],
        ['section',        'Yes', 'Must match an existing section name exactly. e.g. Mabini, STEM-A'],
        ['strand',         'SHS only', 'Required for Grades 11-12. One of: STEM, ABM, HUMSS'],
        [''],
        ['IMPORTANT NOTES'],
        ['• Do NOT change the column headers in row 1.'],
        ['• Delete the 3 sample rows before importing.'],
        ['• Students are assigned tuition automatically based on their grade and section.'],
        ['• A random temporary password is generated and emailed to each student.'],
        ['• Duplicate student_number or email entries will be skipped.'],
        ['• Strand column can be left blank for Grades 1-10.'],
        [''],
        ['VALID STRANDS (Grade 11-12 only)'],
        ['STEM', '— Science, Technology, Engineering and Mathematics'],
        ['ABM',  '— Accountancy, Business and Management'],
        ['HUMSS','— Humanities and Social Sciences'],
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(instructions);
    ws2['!cols'] = [{wch:20}, {wch:14}, {wch:55}];
    XLSX.utils.book_append_sheet(wb, ws2, 'Instructions');

    previewAndExport(wb, 'CATMIS_Student_Import_Template.xlsx');
}

// ── Handle imported file (Excel or CSV) ────────────────────────
function handleImport(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const wb   = XLSX.read(data, { type: 'array' });
            const ws   = wb.Sheets[wb.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
            if (!rows.length) { showToast('File is empty or unreadable.'); return; }
            showImportPreview(rows);
        } catch(err) {
            showToast('Could not read file. Please use .xlsx or .csv format.');
        }
    };
    reader.readAsArrayBuffer(file);
    input.value = ''; // reset so same file can be re-selected
}

// ── Import preview modal ────────────────────────────────────────
function showImportPreview(rows) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay open';
    overlay.style.zIndex = 600;
    overlay.innerHTML = `
    <div class="import-modal">
        <h3>📤 Import Preview</h3>
        <p class="sub">${rows.length} row(s) found. Review before importing. Each student gets a random password and tuition assigned based on their grade.</p>
        <div class="preview-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Student No.</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Strand</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map(r => `<tr>
                        <td>${r.student_number || '—'}</td>
                        <td>${r.full_name       || '—'}</td>
                        <td>${r.email           || '—'}</td>
                        <td>${r.grade_level     || '—'}</td>
                        <td>${r.section         || '—'}</td>
                        <td>${r.strand          || '—'}</td>
                    </tr>`).join('')}
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn-save" id="importConfirmBtn" onclick="submitImport(this)">
                Import ${rows.length} Student(s)
            </button>
        </div>
    </div>`;
    // Store rows on the button for retrieval
    overlay.querySelector('#importConfirmBtn')._rows = rows;
    document.body.appendChild(overlay);
}

// ── Submit import to PHP ────────────────────────────────────────
async function submitImport(btn) {
    const rows    = btn._rows;
    const overlay = btn.closest('.modal-overlay');
    btn.textContent = 'Importing…';
    btn.disabled    = true;

    const body = new FormData();
    body.append('action', 'bulk_import');
    body.append('rows',   JSON.stringify(rows));

    try {
        const res  = await fetch('user_management.php', { method: 'POST', body });
        const data = await res.json();
        overlay.remove();
        if (data.error) { showToast('Error: ' + data.error); return; }
        showImportResults(data);
    } catch(err) {
        showToast('Server error during import.');
        overlay.remove();
    }
}

// ── Show results with generated passwords ───────────────────────
function showImportResults(data) {
    const fmt = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay open';
    overlay.style.zIndex = 600;
    overlay.innerHTML = `
    <div class="import-modal">
        <h3>✅ Import Complete</h3>
        <div class="summary-cards">
            <div class="summary-card card-green">
                <div class="num">${data.created}</div>
                <div class="lbl">Accounts Created</div>
            </div>
            <div class="summary-card card-amber">
                <div class="num">${data.skipped}</div>
                <div class="lbl">Skipped (duplicates / missing fees)</div>
            </div>
        </div>
        <p class="pw-warning">⚠️ Save or download these generated passwords now — they will not be shown again.</p>
        <div class="preview-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Student No.</th>
                        <th>Full Name</th>
                        <th>Temp Password</th>
                        <th>Grade / Section</th>
                        <th>Tuition Assessed</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.results.map(r => `<tr>
                        <td>${r.student_number}</td>
                        <td>${r.name}</td>
                        <td style="font-family:monospace;font-weight:600;color:#0077b6;">${r.password}</td>
                        <td>${r.grade}</td>
                        <td>${fmt(r.tuition)}</td>
                    </tr>`).join('')}
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="downloadPasswords(this._results)">⬇ Download Passwords CSV</button>
            <button class="btn-save" onclick="this.closest('.modal-overlay').remove(); location.reload();">Done</button>
        </div>
    </div>`;
    overlay.querySelector('.btn-cancel')._results = data.results;
    document.body.appendChild(overlay);
}

// ── Download generated passwords as CSV ────────────────────────
function downloadPasswords(results) {
    const rows = [['Student Number', 'Full Name', 'Temp Password', 'Grade / Section', 'Tuition Assessed']];
    results.forEach(r => rows.push([r.student_number, r.name, r.password, r.grade, r.tuition]));
    const csv  = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href     = URL.createObjectURL(blob);
    link.download = `CATMIS_Imported_Passwords_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}

// ── Toast ───────────────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

// ── Close modal on overlay click ────────────────────────────────
document.getElementById('modalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>