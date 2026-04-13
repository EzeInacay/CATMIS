<?php
session_start();
include 'php/config.php';

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
        if (!in_array($role, ['admin', 'teacher', 'student'])) {
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

        if (!$user_id || !$full_name || !$email) {
            echo json_encode(['error' => 'Missing fields.']); exit;
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

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Updated user account ID #{$user_id}: {$full_name}";
        $log->bind_param('is', $_SESSION['user_id'], $act); $log->execute();

        echo json_encode(['success' => true]); exit;
    }

    // TOGGLE STATUS
    if ($action === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE user_id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        // Return new status
        $res = $conn->prepare("SELECT status FROM users WHERE user_id=?");
        $res->bind_param('i', $user_id); $res->execute();
        $newStatus = $res->get_result()->fetch_assoc()['status'];
        echo json_encode(['success' => true, 'status' => $newStatus]); exit;
    }

    // DELETE USER
    if ($action === 'delete_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        // Prevent self-deletion
        if ($user_id === $_SESSION['user_id']) {
            echo json_encode(['error' => 'You cannot delete your own account.']); exit;
        }
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
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

// ── Load users ───────────────────────────────────────────────────
$users = $conn->query("
    SELECT user_id, student_number, full_name, email, role, status, created_at
    FROM users
    ORDER BY role ASC, full_name ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management | CATMIS</title>
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
.navbar-right { margin-left: auto; flex-shrink: 0; }
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
        <a href="#">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='logout.php'">Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="main">
    <div class="page-header">
        <h2>👥 User Management</h2>
        <div class="page-header-btns">
            <button class="btn btn-outline" onclick="downloadTemplate()">⬇ Export Template</button>
            <button class="btn btn-teal" onclick="document.getElementById('importFileInput').click()">📤 Import Excel</button>
            <input type="file" id="importFileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="handleImport(this)">
            <button class="btn btn-primary" onclick="openCreate()">＋ Create Account</button>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
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
    <div class="table-wrap">
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
                        <?php if ($u['user_id'] !== $_SESSION['user_id']): ?>
                        <button class="action-btn btn-del" onclick="deleteUser(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')">🗑 Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== CREATE / EDIT MODAL ===== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3 id="modalTitle">Create Account</h3>
        <input type="hidden" id="mUserId">

        <div class="form-grid">
            <div class="form-field full">
                <label>Full Name</label>
                <input type="text" id="mFullName" placeholder="Last, First M.">
            </div>
            <div class="form-field">
                <label>Email</label>
                <input type="email" id="mEmail" placeholder="user@catmis.edu.ph">
            </div>
            <div class="form-field">
                <label>Student Number</label>
                <input type="text" id="mStudentNo" placeholder="2025-00001">
            </div>
            <div class="form-field">
                <label>Role</label>
                <select id="mRole">
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-field">
                <label>Status</label>
                <select id="mStatus">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
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

<div class="toast" id="toast"></div>

<!-- SheetJS for Excel/CSV parsing -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

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
    document.getElementById('mUserId').value    = '';
    document.getElementById('mFullName').value  = '';
    document.getElementById('mEmail').value     = '';
    document.getElementById('mStudentNo').value = '';
    document.getElementById('mRole').value      = 'student';
    document.getElementById('mStatus').value    = 'active';
    document.getElementById('mPassword').value  = '';
    document.getElementById('pwHint').style.display = 'none';
    document.getElementById('mPassword').placeholder   = '••••••••';
    document.getElementById('modalOverlay').classList.add('open');
    document.getElementById('mFullName').focus();
}

function openEdit(user) {
    document.getElementById('modalTitle').textContent    = 'Edit Account';
    document.getElementById('modalSaveBtn').textContent  = 'Save Changes';
    document.getElementById('mUserId').value    = user.user_id;
    document.getElementById('mFullName').value  = user.full_name;
    document.getElementById('mEmail').value     = user.email;
    document.getElementById('mStudentNo').value = user.student_number || '';
    document.getElementById('mRole').value      = user.role;
    document.getElementById('mStatus').value    = user.status;
    document.getElementById('mPassword').value  = '';
    document.getElementById('pwHint').style.display = '';
    document.getElementById('mPassword').placeholder   = 'Leave blank to keep current';
    document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}

// ── Save (create or update) ─────────────────────────────────────
async function saveUser() {
    const user_id = document.getElementById('mUserId').value;
    const action  = user_id ? 'update_user' : 'create_user';

    const body = new FormData();
    body.append('action',         action);
    body.append('user_id',        user_id);
    body.append('full_name',      document.getElementById('mFullName').value.trim());
    body.append('email',          document.getElementById('mEmail').value.trim());
    body.append('student_number', document.getElementById('mStudentNo').value.trim());
    body.append('role',           document.getElementById('mRole').value);
    body.append('status',         document.getElementById('mStatus').value);
    body.append('password',       document.getElementById('mPassword').value);

    const res  = await fetch('user_management.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        showToast(user_id ? 'Account updated!' : 'Account created!');
        closeModal();
        setTimeout(() => location.reload(), 700);
    } else {
        showToast('Error: ' + (data.error || 'Unknown error'));
    }
}

// ── Toggle status ───────────────────────────────────────────────
async function toggleStatus(user_id) {
    const body = new FormData();
    body.append('action',  'toggle_status');
    body.append('user_id', user_id);

    const res  = await fetch('user_management.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        const newStatus  = data.status;
        const statusCell = document.getElementById('status-' + user_id);
        const toggleBtn  = document.getElementById('toggle-' + user_id);
        statusCell.innerHTML = `<span class="status-badge status-${newStatus}">${newStatus.charAt(0).toUpperCase() + newStatus.slice(1)}</span>`;
        toggleBtn.textContent = newStatus === 'active' ? '🔒 Deactivate' : '✅ Activate';
        showToast('Status updated to ' + newStatus + '.');
    } else {
        showToast('Could not update status.');
    }
}

// ── Delete ──────────────────────────────────────────────────────
async function deleteUser(user_id, name) {
    if (!confirm(`Delete account for "${name}"? This cannot be undone.`)) return;

    const body = new FormData();
    body.append('action',  'delete_user');
    body.append('user_id', user_id);

    const res  = await fetch('user_management.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        document.getElementById('row-' + user_id)?.remove();
        showToast('Account deleted.');
    } else {
        showToast('Error: ' + (data.error || 'Could not delete.'));
    }
}

// ── Export current table to CSV ─────────────────────────────────
function exportExcel() {
    const rows  = [['ID', 'Student No.', 'Full Name', 'Email', 'Role', 'Status', 'Created']];
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
    const csv  = rows.map(r => r.map(c => `"${c}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href     = URL.createObjectURL(blob);
    link.download = `CATMIS_Users_${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}

// ── Download blank import template ──────────────────────────────
function downloadTemplate() {
    const csv = [
        'student_number,full_name,email,grade_level,section,strand',
        '2025-00011,Dela Cruz Juan A.,juan.delacruz@catmis.edu.ph,11,STEM-A,STEM',
        '2025-00012,Santos Maria B.,maria.santos@catmis.edu.ph,7,Mabini,',
        '2025-00013,Reyes Carlo D.,carlo.reyes@catmis.edu.ph,10,Emerald,',
    ].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href     = URL.createObjectURL(blob);
    link.download = 'CATMIS_Student_Import_Template.csv';
    link.click();
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