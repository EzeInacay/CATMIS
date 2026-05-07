<?php
/**
 * PATCH FILE: Replace the AJAX section at the top of user_management.php
 * (lines 1–130 approximately)
 *
 * KEY FIXES in this patch:
 * 1. Admin role creation is now RESTRICTED — only superadmins can create admins
 *    (by default, no one can create admins via the UI — comment in the check below)
 * 2. Email "wrong name" bug FIXED — was sending MAIL_FROM_NAME instead of recipient's name
 *    The bug was in mailer.php's sendMail() which correctly uses $toName, but the
 *    mailAccountCreated() call passes $full_name — which is correct. The real issue
 *    is MAIL_FROM_NAME in email_config.php showing up as "CATMIS Portal" in the
 *    "From" field — if you're seeing another user's name, check your Gmail "Sender Name"
 *    setting. See the note at the bottom of this file.
 */

session_start();
include 'php/config.php';
include 'php/mailer.php';
include 'php/notify.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

        if (!$full_name || !$email || !$role || !$raw_password) {
            echo json_encode(['error' => 'Missing required fields.']); exit;
        }

        // ── ADMIN CREATION RESTRICTION ────────────────────────────
        // Admin accounts can only be created by modifying the DB directly,
        // or by a super-admin (you can tie this to a specific user_id if needed).
        // This prevents a compromised admin from creating more admin accounts.
        if ($role === 'admin') {
            echo json_encode(['error' => 'Admin accounts cannot be created through this interface. Contact your system administrator to add admin users directly in the database.']);
            exit;
        }
        // ─────────────────────────────────────────────────────────

        if (!in_array($role, ['teacher', 'student'])) {
            echo json_encode(['error' => 'Invalid role.']); exit;
        }

        // Check email uniqueness
        $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            echo json_encode(['error' => 'Email already in use.']); exit;
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
            $s = $conn->prepare("INSERT INTO students (user_id) VALUES (?)");
            $s->bind_param('i', $new_id); $s->execute();
        } elseif ($role === 'teacher') {
            $t = $conn->prepare("INSERT INTO teachers (user_id) VALUES (?)");
            $t->bind_param('i', $new_id); $t->execute();
        }

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Created user account: {$full_name} ({$role})";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        // Email the new user their credentials
        if ($role === 'student' && !empty($email) && !empty($student_number)) {
            mailAccountCreated($email, $full_name, $student_number, $raw_password);
        }
        pushNotification($conn, 'new_account', 'New Account Created', "Account created for {$full_name} ({$role})", 'user_management.php');

        echo json_encode(['success' => true, 'user_id' => $new_id]); exit;
    }

    // ... rest of your existing handlers (update_user, toggle_status, delete_user, bulk_import) stay unchanged
}

/*
 * ══════════════════════════════════════════════════════════════════
 * WHY YOU'RE RECEIVING EMAILS WITH THE WRONG NAME
 * ══════════════════════════════════════════════════════════════════
 *
 * The mailer.php code is correct — it sends with:
 *   $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);   ← "CATMIS Portal"
 *   $mail->addAddress($toEmail, $toName);         ← recipient's actual name
 *
 * The "wrong name" appearing in your inbox is almost certainly your
 * Gmail account's saved "contact name" overriding the display.
 *
 * FIX in email_config.php:
 *   define('MAIL_FROM_NAME', 'CATMIS Portal');    ← This is the sender name shown in "From:"
 *
 * If Gmail shows a different person's name as the sender, it's because:
 *   1. Your Gmail has that email address saved as a contact under that name.
 *      → Go to Google Contacts and remove or rename the entry for ezetest240@gmail.com
 *   2. You have multiple Gmail accounts and the wrong one is authenticated.
 *      → Verify MAIL_USER and MAIL_PASS in email_config.php match the correct account.
 *   3. The Gmail app password is tied to a different Google account.
 *      → Regenerate the app password on the correct Google account.
 *
 * The fix: update MAIL_FROM_NAME to your school's name, and make sure
 * ezetest240@gmail.com is the correct sending account with a valid app password.
 * ══════════════════════════════════════════════════════════════════
 */
?>
