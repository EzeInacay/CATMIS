<?php
/**
 * php/account_deletion.php
 *
 * Shared helper for permanently deleting a user account (used by the
 * "Delete Forever" button in the Bin, and by the 30-day auto-purge cron).
 *
 * Handles foreign key dependencies safely:
 *  - Ledger/payment entries the user *posted* (as admin/teacher) are
 *    reassigned to the system admin account instead of being deleted,
 *    so OTHER students' financial records are preserved.
 *  - If the deleted account is a student, their own ledger, payments,
 *    payment proofs, and tuition account are removed along with them.
 *  - Audit log entries belonging to this user are removed.
 *
 * Returns ['success' => true] or ['success' => false, 'error' => '...']
 */

function purgeUserAccount($conn, int $user_id): array {
    // Runs a prepared statement and throws if it fails (so the transaction rolls back)
    $run = function($conn, string $sql, string $types = '', array $params = []) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception($conn->error);
        if ($types) $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception($stmt->error ?: $conn->error);
        return $stmt;
    };

    // Fetch the user first
    $u = $conn->prepare("SELECT user_id, role, full_name FROM users WHERE user_id = ?");
    $u->bind_param('i', $user_id);
    $u->execute();
    $user = $u->get_result()->fetch_assoc();
    if (!$user) {
        return ['success' => false, 'error' => 'Account not found.'];
    }

    // Find a "system admin" account to re-attribute ledger entries to
    // (the lowest-ID admin/superadmin that isn't the account being deleted)
    $sysRes = $conn->prepare("
        SELECT user_id FROM users
        WHERE role IN ('admin','superadmin') AND user_id != ?
        ORDER BY user_id ASC LIMIT 1
    ");
    $sysRes->bind_param('i', $user_id);
    $sysRes->execute();
    $sysRow = $sysRes->get_result()->fetch_assoc();
    $systemAdminId = $sysRow['user_id'] ?? null;

    $conn->begin_transaction();
    try {
        // Re-attribute ledger/payment entries this user posted (as staff)
        // so other students' financial records remain intact.
        if ($systemAdminId) {
            $run($conn, "UPDATE student_ledgers SET posted_by = ? WHERE posted_by = ?", 'ii', [$systemAdminId, $user_id]);
            $run($conn, "UPDATE payments SET posted_by = ? WHERE posted_by = ?", 'ii', [$systemAdminId, $user_id]);
        }

        // Role-specific cleanup
        if ($user['role'] === 'student') {
            $sRes = $conn->prepare("SELECT student_id FROM students WHERE user_id = ?");
            $sRes->bind_param('i', $user_id);
            $sRes->execute();
            $student = $sRes->get_result()->fetch_assoc();

            if ($student) {
                $student_id = $student['student_id'];

                // Get this student's tuition accounts
                $accRes = $conn->prepare("SELECT account_id FROM tuition_accounts WHERE student_id = ?");
                $accRes->bind_param('i', $student_id);
                $accRes->execute();
                $accounts = $accRes->get_result()->fetch_all(MYSQLI_ASSOC);

                foreach ($accounts as $acc) {
                    $aid = $acc['account_id'];
                    $run($conn, "DELETE FROM student_ledgers WHERE account_id = ?", 'i', [$aid]);
                    $run($conn, "DELETE FROM payments WHERE account_id = ?", 'i', [$aid]);
                }

                $run($conn, "DELETE FROM tuition_accounts WHERE student_id = ?", 'i', [$student_id]);

                // Payment proofs table (created via migration in payment_proof.php docs)
                $tblChk = $conn->query("SHOW TABLES LIKE 'payment_proofs'");
                if ($tblChk && $tblChk->num_rows > 0) {
                    $run($conn, "DELETE FROM payment_proofs WHERE student_id = ?", 'i', [$student_id]);
                }

                $run($conn, "DELETE FROM students WHERE user_id = ?", 'i', [$user_id]);
            }
        } elseif ($user['role'] === 'teacher') {
            $run($conn, "DELETE FROM teachers WHERE user_id = ?", 'i', [$user_id]);
        }

        // Remove this user's own audit log entries
        $run($conn, "DELETE FROM audit_logs WHERE user_id = ?", 'i', [$user_id]);

        // Finally, remove the user account itself
        $run($conn, "DELETE FROM users WHERE user_id = ?", 'i', [$user_id]);

        $conn->commit();
        return ['success' => true];

    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Could not permanently delete this account: ' . $e->getMessage()];
    }
}
