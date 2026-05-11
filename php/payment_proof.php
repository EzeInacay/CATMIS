<?php
/**
 * php/payment_proof.php
 * Handles student payment proof upload.
 * Students upload their GCash/bank transfer screenshots here.
 * Admin can review and confirm/reject in payment_history.php.
 *
 * SETUP REQUIRED:
 *   1. Create folder: /uploads/payment_proofs/ (writable by web server)
 *   2. Add to your .htaccess or server config to block direct PHP execution in uploads/
 *   3. Run this SQL migration:
 *      ALTER TABLE payments ADD COLUMN proof_path VARCHAR(255) DEFAULT NULL;
 *      ALTER TABLE payments ADD COLUMN proof_status ENUM('pending','confirmed','rejected') DEFAULT NULL;
 *      ALTER TABLE payments ADD COLUMN proof_note VARCHAR(255) DEFAULT NULL;
 *
 *      CREATE TABLE IF NOT EXISTS payment_proofs (
 *          proof_id    INT AUTO_INCREMENT PRIMARY KEY,
 *          student_id  INT NOT NULL,
 *          account_id  INT NOT NULL,
 *          file_path   VARCHAR(255) NOT NULL,
 *          file_name   VARCHAR(255) NOT NULL,
 *          amount      DECIMAL(10,2) NOT NULL,
 *          method      ENUM('GCash','Bank Transfer') NOT NULL,
 *          reference   VARCHAR(100) DEFAULT NULL,
 *          note        TEXT DEFAULT NULL,
 *          status      ENUM('pending','confirmed','rejected') DEFAULT 'pending',
 *          submitted_at DATETIME DEFAULT NOW(),
 *          reviewed_by  INT DEFAULT NULL,
 *          reviewed_at  DATETIME DEFAULT NULL,
 *          admin_note   VARCHAR(255) DEFAULT NULL,
 *          FOREIGN KEY (student_id) REFERENCES students(student_id),
 *          FOREIGN KEY (account_id) REFERENCES tuition_accounts(account_id)
 *      );
 */

session_start();
include __DIR__ . '/config.php';
include __DIR__ . '/notify.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$action  = $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// ── STUDENT: Submit payment proof ────────────────────────────────
if ($action === 'submit_proof' && $role === 'student') {

    $account_id = intval($_POST['account_id'] ?? 0);
    $amount     = floatval($_POST['amount']   ?? 0);
    $method     = trim($_POST['method']       ?? '');
    $reference  = trim($_POST['reference']    ?? '');
    $note       = trim($_POST['note']         ?? '');

    if ($account_id <= 0 || $amount <= 0 || !in_array($method, ['GCash', 'Bank Transfer'])) {
        echo json_encode(['error' => 'Invalid submission data.']); exit;
    }

    // Verify this account belongs to the student
    $chk = $conn->prepare("
        SELECT s.student_id FROM students s
        JOIN tuition_accounts ta ON s.student_id = ta.student_id
        WHERE s.user_id = ? AND ta.account_id = ?
    ");
    $chk->bind_param('ii', $user_id, $account_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['error' => 'Access denied.']); exit; }
    $student_id = $row['student_id'];

    // Handle file upload
    if (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'File upload failed. Please attach your payment screenshot.']); exit;
    }

    $file     = $_FILES['proof_file'];
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $maxSize  = 5 * 1024 * 1024; // 5MB

    // Validate type by MIME (not extension — extension can be faked)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed)) {
        echo json_encode(['error' => 'Only JPG, PNG, WebP, or PDF files are allowed.']); exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['error' => 'File too large. Maximum 5MB.']); exit;
    }

    // Save file
    $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = 'proof_' . $student_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $savePath = $uploadDir . $safeName;
    $dbPath   = 'uploads/payment_proofs/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        echo json_encode(['error' => 'Could not save file. Check server permissions.']); exit;
    }

    // Insert into payment_proofs table
    $stmt = $conn->prepare("
        INSERT INTO payment_proofs (student_id, account_id, file_path, file_name, amount, method, reference, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $origName = htmlspecialchars(basename($file['name']));
    $stmt->bind_param('iissdss s', $student_id, $account_id, $dbPath, $origName, $amount, $method, $reference, $note);

    // Fix bind_param — no spaces allowed
    $stmt = $conn->prepare("
        INSERT INTO payment_proofs (student_id, account_id, file_path, file_name, amount, method, reference, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iissdss s', $student_id, $account_id, $dbPath, $origName, $amount, $method, $reference, $note);

    $stmt = $conn->prepare("
        INSERT INTO payment_proofs (student_id, account_id, file_path, file_name, amount, method, reference, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iissdsss', $student_id, $account_id, $dbPath, $origName, $amount, $method, $reference, $note);
    $stmt->execute();
    $proof_id = $conn->insert_id;

    // Notify all admins
    pushNotification($conn, 'payment',
        'Payment Proof Submitted',
        "A student submitted payment proof of ₱" . number_format($amount, 2) . " via {$method}.",
        'payment_history.php'
    );

    // Audit log
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
    $act = "Submitted payment proof #{$proof_id}: ₱{$amount} via {$method}";
    $log->bind_param('is', $user_id, $act);
    $log->execute();

    echo json_encode(['success' => true, 'proof_id' => $proof_id,
        'message' => 'Payment proof submitted. An admin will verify and post your payment soon.']);
    exit;
}

// ── ADMIN: Review proof (confirm or reject) ───────────────────────
if ($action === 'review_proof' && $role === 'admin') {
    include __DIR__ . '/get_balance.php';
    include __DIR__ . '/mailer.php';

    $proof_id  = intval($_POST['proof_id'] ?? 0);
    $decision  = trim($_POST['decision']   ?? ''); // 'confirmed' or 'rejected'
    $admin_note = trim($_POST['admin_note'] ?? '');

    if (!in_array($decision, ['confirmed', 'rejected'])) {
        echo json_encode(['error' => 'Invalid decision.']); exit;
    }

    // Fetch proof
    $pStmt = $conn->prepare("
        SELECT pp.*, u.full_name, u.email, u.user_id AS student_user_id
        FROM payment_proofs pp
        JOIN students s ON pp.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        WHERE pp.proof_id = ? AND pp.status = 'pending'
    ");
    $pStmt->bind_param('i', $proof_id);
    $pStmt->execute();
    $proof = $pStmt->get_result()->fetch_assoc();
    if (!$proof) { echo json_encode(['error' => 'Proof not found or already reviewed.']); exit; }

    $conn->begin_transaction();
    try {
        // Update proof status
        $upd = $conn->prepare("UPDATE payment_proofs SET status=?, reviewed_by=?, reviewed_at=NOW(), admin_note=? WHERE proof_id=?");
        $upd->bind_param('sisi', $decision, $user_id, $admin_note, $proof_id);
        $upd->execute();

        if ($decision === 'confirmed') {
            // Auto-generate OR number
            $orNum = 'OR-' . strtoupper(date('ymd')) . '-' . str_pad($proof_id, 4, '0', STR_PAD_LEFT);

            // Insert into payments table
            $pay = $conn->prepare("
                INSERT INTO payments (account_id, student_id, amount, method, or_number, payment_date, posted_by)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?)
            ");
            $pay->bind_param('iidssi', $proof['account_id'], $proof['student_id'],
                $proof['amount'], $proof['method'], $orNum, $user_id);
            $pay->execute();
            $payment_id = $conn->insert_id;

            // Post to ledger
            $led = $conn->prepare("
                INSERT INTO student_ledgers (account_id, entry_type, amount, remarks, posted_by)
                VALUES (?, 'PAYMENT', ?, ?, ?)
            ");
            $remarks = "Online payment via {$proof['method']} — OR# {$orNum}";
            $led->bind_param('idsi', $proof['account_id'], $proof['amount'], $remarks, $user_id);
            $led->execute();

            // Get new balance
            $newBal = getBalance($conn, $proof['account_id']);

            // Email receipt to student
            mailPaymentPosted($proof['email'], $proof['full_name'], $orNum,
                $proof['amount'], $proof['method'], $newBal);
        } else {
            // Rejected — email student
            $subject = "Payment Proof Not Accepted";
            $body    = "<p>Dear <strong>{$proof['full_name']}</strong>,</p>
                <p>Your submitted payment proof of <strong>₱" . number_format($proof['amount'],2) . "</strong> via {$proof['method']} could not be verified.</p>"
                . ($admin_note ? "<div class='info-box'><strong>Reason</strong>{$admin_note}</div>" : '')
                . "<p>Please visit the school finance office or resubmit with a clearer proof of payment.</p>";
            sendMail($proof['email'], $proof['full_name'], $subject, $body);
        }

        $conn->commit();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = ucfirst($decision) . " payment proof #{$proof_id} for {$proof['full_name']}";
        $log->bind_param('is', $user_id, $act);
        $log->execute();

        echo json_encode(['success' => true, 'decision' => $decision,
            'message' => "Proof #{$proof_id} marked as {$decision}."]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── ADMIN: Get pending proofs list ────────────────────────────────
if ($action === 'get_pending_proofs' && $role === 'admin') {
    $proofs = $conn->query("
        SELECT pp.*, u.full_name, u.student_number, s.grade_level, s.section
        FROM payment_proofs pp
        JOIN students s ON pp.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        WHERE pp.status = 'pending'
        ORDER BY pp.submitted_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['proofs' => $proofs]);
    exit;
}

echo json_encode(['error' => 'Invalid action.']);
?>
