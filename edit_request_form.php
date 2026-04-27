<?php
session_start();
include 'php/config.php';
include 'php/mailer.php';
include 'php/notify.php';

// ── Auth: students only ──────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// ── Fetch current student info ───────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.user_id, u.full_name, u.email, u.student_number,
           s.grade_level, s.section,
           sec.section_name,
           sy.name AS sy_name
    FROM users u
    LEFT JOIN students s    ON u.user_id    = s.user_id
    LEFT JOIN sections sec  ON s.section_id = sec.section_id
    LEFT JOIN tuition_accounts ta ON s.student_id = ta.student_id
    LEFT JOIN school_years sy ON ta.sy_id = sy.sy_id
    WHERE u.user_id = ?
    ORDER BY ta.sy_id DESC LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// ── Fetch existing pending requests ──────────────────────────────
$pendStmt = $conn->prepare("
    SELECT field_name FROM edit_requests
    WHERE user_id = ? AND status = 'pending'
");
$pendStmt->bind_param('i', $user_id);
$pendStmt->execute();
$pendingFields = array_column($pendStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'field_name');

// ── Fetch request history ────────────────────────────────────────
$histStmt = $conn->prepare("
    SELECT field_name, new_value, status, reject_note, created_at, reviewed_at
    FROM edit_requests
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$histStmt->bind_param('i', $user_id);
$histStmt->execute();
$history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Handle form submission ───────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['field_name'])) {
    $field_name = trim($_POST['field_name'] ?? '');
    $new_value  = trim($_POST['new_value']  ?? '');
    $reason     = trim($_POST['reason']     ?? '');

    $allowed_fields = ['full_name', 'email', 'student_number', 'grade_level', 'section', 'strand'];

    if (!in_array($field_name, $allowed_fields)) {
        $error = 'Invalid field selected.';
    } elseif (empty($new_value)) {
        $error = 'Requested value cannot be empty.';
    } elseif (in_array($field_name, $pendingFields)) {
        $error = 'You already have a pending request for this field. Wait for it to be reviewed before submitting another.';
    } else {
        // Get current value
        $fieldMap = [
            'full_name'      => $student['full_name'],
            'email'          => $student['email'],
            'student_number' => $student['student_number'],
            'grade_level'    => $student['grade_level'],
            'section'        => $student['section'],
            'strand'         => '', // derive from section name if needed
        ];
        $old_value = $fieldMap[$field_name] ?? '';

        // Insert request
        $ins = $conn->prepare("
            INSERT INTO edit_requests (user_id, field_name, old_value, new_value, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->bind_param('issss', $user_id, $field_name, $old_value, $new_value, $reason);
        $ins->execute();

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $act = "Submitted edit request for: {$field_name} → {$new_value}";
        $log->bind_param('is', $user_id, $act);
        $log->execute();

        // Email all admins + push notification
        $admins = $conn->query("SELECT full_name, email FROM users WHERE role='admin' AND status='active'");
        while ($admin = $admins->fetch_assoc()) {
            mailEditRequestSubmitted(
                $admin['email'],
                $admin['full_name'],
                $student['full_name'],
                $field_name,
                $new_value
            );
        }
        pushNotification($conn, 'edit_request', 'Edit Request Submitted', "{$student['full_name']} requested to change their " . str_replace('_',' ',$field_name), 'edit_requests_admin.php');

        $success = 'Your request has been submitted. An admin will review it shortly.';

        // Refresh pending list
        $pendStmt->execute();
        $pendingFields = array_column($pendStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'field_name');

        // Refresh history
        $histStmt->execute();
        $history = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$fieldLabels = [
    'full_name'      => 'Full Name',
    'email'          => 'Email Address',
    'student_number' => 'Student Number',
    'grade_level'    => 'Grade Level',
    'section'        => 'Section',
    'strand'         => 'Strand (SHS only)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Request Info Edit | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #eef1f4; }

.navbar {
    position: fixed; top: 0; left: 0; right: 0; height: 60px;
    background: linear-gradient(90deg, #0f2027, #203a43);
    display: flex; align-items: center; padding: 0 24px;
    z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.navbar-brand { display: flex; align-items: baseline; gap: 10px; text-decoration: none; flex-shrink: 0; }
.navbar-brand h2 { margin: 0; color: #fff; font-size: 20px; letter-spacing: -0.5px; }
.navbar-brand span { font-size: 11px; color: rgba(255,255,255,0.45); letter-spacing: 1px; }
.navbar-right { display: flex; align-items: center; gap: 14px; margin-left: auto; }
.nav-back { color: rgba(255,255,255,0.7); text-decoration: none; font-size: 13px; padding: 7px 14px; border-radius: 6px; transition: background 0.18s; }
.nav-back:hover { background: rgba(255,255,255,0.1); color: #fff; }
.logout-btn { background: #ff3b30; border: none; color: white; padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit; }
.logout-btn:hover { background: #d0302a; }

.main { margin-top: 60px; padding: 30px; max-width: 760px; margin-left: auto; margin-right: auto; }

.page-title { font-size: 22px; font-weight: 700; color: #0f2027; margin-bottom: 22px; }

/* Current info card */
.info-card { background: white; border-radius: 12px; padding: 22px 26px; margin-bottom: 24px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.info-card h3 { margin: 0 0 16px; font-size: 15px; color: #0f2027; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.info-row { background: #f8fafc; border-radius: 8px; padding: 10px 14px; }
.info-row .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 2px; }
.info-row .val { font-size: 14px; color: #0f2027; font-weight: 500; }

/* Form card */
.form-card { background: white; border-radius: 12px; padding: 26px 28px; margin-bottom: 24px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.form-card h3 { margin: 0 0 20px; font-size: 15px; color: #0f2027; }
.form-field { margin-bottom: 18px; }
.form-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 6px; }
.form-field select, .form-field input, .form-field textarea {
    width: 100%; padding: 10px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: 14px; color: #0f2027; font-family: 'Segoe UI', Arial, sans-serif;
    outline: none; transition: border-color 0.2s; background: #f8fafc;
}
.form-field select:focus, .form-field input:focus, .form-field textarea:focus {
    border-color: #0077b6; background: white; box-shadow: 0 0 0 3px rgba(0,119,182,0.1);
}
.form-field textarea { resize: vertical; min-height: 80px; }
.pending-note { font-size: 12px; color: #d97706; background: #fef3c7; padding: 4px 10px; border-radius: 6px; display: inline-block; margin-top: 4px; }

.btn-submit { width: 100%; padding: 12px; background: #0077b6; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.18s; margin-top: 4px; }
.btn-submit:hover { background: #005f8e; }

.banner { padding: 13px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; }
.banner-success { background: #d1fae5; border-left: 4px solid #10b981; color: #065f46; }
.banner-error   { background: #fee2e2; border-left: 4px solid #ef4444; color: #991b1b; }

/* History table */
.history-card { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; }
.history-card h3 { margin: 0; font-size: 15px; color: #0f2027; padding: 20px 24px 16px; border-bottom: 1px solid #f1f5f9; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; padding: 10px 16px; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.5px; }
td { padding: 11px 16px; font-size: 13px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }

.status-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.s-pending  { background: #fef3c7; color: #92400e; }
.s-approved { background: #d1fae5; color: #065f46; }
.s-rejected { background: #fee2e2; color: #991b1b; }

.empty-msg { text-align: center; color: #94a3b8; padding: 28px; font-size: 14px; }
</style>
</head>
<body>

<nav class="navbar">
    <a href="student_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-right">
        <a href="student_dashboard.php" class="nav-back">← Back to Dashboard</a>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="page-title">Request Information Edit</div>

    <?php if ($success): ?>
    <div class="banner banner-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="banner banner-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Current info -->
    <div class="info-card">
        <h3>Your Current Information</h3>
        <div class="info-grid">
            <div class="info-row"><div class="lbl">Full Name</div><div class="val"><?= htmlspecialchars($student['full_name'] ?? '—') ?></div></div>
            <div class="info-row"><div class="lbl">Email</div><div class="val"><?= htmlspecialchars($student['email'] ?? '—') ?></div></div>
            <div class="info-row"><div class="lbl">Student Number</div><div class="val"><?= htmlspecialchars($student['student_number'] ?? '—') ?></div></div>
            <div class="info-row"><div class="lbl">Grade Level</div><div class="val"><?= htmlspecialchars($student['grade_level'] ?? '—') ?></div></div>
            <div class="info-row"><div class="lbl">Section</div><div class="val"><?= htmlspecialchars($student['section'] ?? '—') ?></div></div>
            <div class="info-row"><div class="lbl">School Year</div><div class="val"><?= htmlspecialchars($student['sy_name'] ?? '—') ?></div></div>
        </div>
    </div>

    <!-- Request form -->
    <div class="form-card">
        <h3>Submit an Edit Request</h3>
        <form method="POST" action="edit_request_form.php">
            <div class="form-field">
                <label>Field to Change</label>
                <select name="field_name" id="fieldSelect" required onchange="updateCurrentValue(this.value)">
                    <option value="">— Select a field —</option>
                    <?php foreach ($fieldLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= isset($_POST['field_name']) && $_POST['field_name'] === $key ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php foreach ($pendingFields as $pf): ?>
                <div class="pending-note" id="pending-<?= $pf ?>" style="display:none">
                    ⏳ You already have a pending request for this field.
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-field">
                <label>Requested New Value</label>
                <input type="text" name="new_value" id="newValue" placeholder="Enter the corrected value" required value="<?= htmlspecialchars($_POST['new_value'] ?? '') ?>">
            </div>

            <div class="form-field">
                <label>Reason for Change <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">(optional but recommended)</span></label>
                <textarea name="reason" placeholder="e.g. My name was misspelled during enrollment"><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-submit">Submit Request</button>
        </form>
    </div>

    <!-- History -->
    <div class="history-card">
        <h3>Request History</h3>
        <?php if (empty($history)): ?>
            <p class="empty-msg">No requests submitted yet.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Requested Value</th>
                    <th>Status</th>
                    <th>Note</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($fieldLabels[$h['field_name']] ?? $h['field_name']) ?></td>
                <td><?= htmlspecialchars($h['new_value']) ?></td>
                <td><span class="status-badge s-<?= $h['status'] ?>"><?= ucfirst($h['status']) ?></span></td>
                <td style="color:#64748b;font-size:12px;"><?= htmlspecialchars($h['reject_note'] ?? '—') ?></td>
                <td style="color:#94a3b8;font-size:12px;"><?= date('M d, Y', strtotime($h['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
const pendingFields = <?= json_encode($pendingFields) ?>;
const currentValues = {
    full_name:      '<?= addslashes($student['full_name'] ?? '') ?>',
    email:          '<?= addslashes($student['email'] ?? '') ?>',
    student_number: '<?= addslashes($student['student_number'] ?? '') ?>',
    grade_level:    '<?= addslashes($student['grade_level'] ?? '') ?>',
    section:        '<?= addslashes($student['section'] ?? '') ?>',
    strand:         '',
};

function updateCurrentValue(field) {
    // Show/hide pending warning
    document.querySelectorAll('[id^="pending-"]').forEach(el => el.style.display = 'none');
    if (pendingFields.includes(field)) {
        const el = document.getElementById('pending-' + field);
        if (el) el.style.display = 'inline-block';
    }
    // Pre-fill current value as placeholder
    const input = document.getElementById('newValue');
    input.placeholder = currentValues[field]
        ? 'Currently: ' + currentValues[field]
        : 'Enter the corrected value';
}
</script>
</body>
</html>