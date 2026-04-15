<?php
session_start();
include __DIR__ . '/config.php';
include __DIR__ . '/mailer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$admin_id = $_SESSION['user_id'];

// ── AJAX: approve or reject ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action     = $_POST['action'];
    $request_id = intval($_POST['request_id'] ?? 0);

    // Load the request
    $rStmt = $conn->prepare("
        SELECT er.*, u.full_name, u.email, u.user_id,
               s.student_id, s.section_id
        FROM edit_requests er
        JOIN users u ON er.user_id = u.user_id
        LEFT JOIN students s ON u.user_id = s.user_id
        WHERE er.request_id = ?
    ");
    $rStmt->bind_param('i', $request_id);
    $rStmt->execute();
    $req = $rStmt->get_result()->fetch_assoc();

    if (!$req) {
        echo json_encode(['error' => 'Request not found.']); exit;
    }
    if ($req['status'] !== 'pending') {
        echo json_encode(['error' => 'This request has already been reviewed.']); exit;
    }

    if ($action === 'approve') {
        // Apply the change to the right table/column
        $field     = $req['field_name'];
        $new_value = $req['new_value'];
        $applied   = false;

        $userFields    = ['full_name', 'email', 'student_number'];
        $studentFields = ['grade_level', 'section'];

        if (in_array($field, $userFields)) {
            $upd = $conn->prepare("UPDATE users SET {$field} = ? WHERE user_id = ?");
            $upd->bind_param('si', $new_value, $req['user_id']);
            $applied = $upd->execute();
        } elseif (in_array($field, $studentFields)) {
            $upd = $conn->prepare("UPDATE students SET {$field} = ? WHERE user_id = ?");
            $upd->bind_param('si', $new_value, $req['user_id']);
            $applied = $upd->execute();
        } elseif ($field === 'strand') {
            // Strand change: find matching section in sections table
            $grade = $conn->query("SELECT grade_level FROM students WHERE user_id = {$req['user_id']}")->fetch_assoc()['grade_level'] ?? '';
            $syRes = $conn->query("SELECT sy_id FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
            $sy_id = $syRes['sy_id'] ?? 0;
            $secRow = $conn->prepare("SELECT section_id FROM sections WHERE grade_level=? AND section_name LIKE ? AND sy_id=? LIMIT 1");
            $like = "%{$new_value}%";
            $secRow->bind_param('ssi', $grade, $like, $sy_id);
            $secRow->execute();
            $sec = $secRow->get_result()->fetch_assoc();
            if ($sec) {
                $upd = $conn->prepare("UPDATE students SET section_id=? WHERE user_id=?");
                $upd->bind_param('ii', $sec['section_id'], $req['user_id']);
                $applied = $upd->execute();
            }
        }

        // Mark approved
        $upd2 = $conn->prepare("UPDATE edit_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE request_id=?");
        $upd2->bind_param('ii', $admin_id, $request_id);
        $upd2->execute();

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Approved edit request #{$request_id}: {$field} → {$new_value} for user #{$req['user_id']}";
        $log->bind_param('is', $admin_id, $act);
        $log->execute();

        // Email student
        mailEditRequestReviewed($req['email'], $req['full_name'], $field, $new_value, 'approved');

        echo json_encode(['success' => true, 'applied' => $applied]);

    } elseif ($action === 'reject') {
        $reject_note = trim($_POST['reject_note'] ?? '');

        $upd = $conn->prepare("UPDATE edit_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), reject_note=? WHERE request_id=?");
        $upd->bind_param('isi', $admin_id, $reject_note, $request_id);
        $upd->execute();

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Rejected edit request #{$request_id} for user #{$req['user_id']}";
        $log->bind_param('is', $admin_id, $act);
        $log->execute();

        // Email student
        mailEditRequestReviewed($req['email'], $req['full_name'], $req['field_name'], $req['new_value'], 'rejected', $reject_note);

        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['error' => 'Unknown action.']);
    }
    exit;
}
?>