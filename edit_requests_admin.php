<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── Load all requests ────────────────────────────────────────────
$requests = $conn->query("
    SELECT
        er.request_id, er.field_name, er.old_value, er.new_value,
        er.reason, er.status, er.reject_note,
        er.created_at, er.reviewed_at,
        u.full_name AS student_name, u.email AS student_email,
        u.student_number,
        rv.full_name AS reviewed_by_name
    FROM edit_requests er
    JOIN users u ON er.user_id = u.user_id
    LEFT JOIN users rv ON er.reviewed_by = rv.user_id
    ORDER BY
        CASE er.status WHEN 'pending' THEN 0 ELSE 1 END,
        er.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');
$pendingCount = count($pending);

$fieldLabels = [
    'full_name'      => 'Full Name',
    'email'          => 'Email Address',
    'student_number' => 'Student Number',
    'grade_level'    => 'Grade Level',
    'section'        => 'Section',
    'strand'         => 'Strand',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Requests | CATMIS</title>
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
.navbar-brand { display: flex; align-items: baseline; gap: 10px; text-decoration: none; margin-right: 32px; flex-shrink: 0; }
.navbar-brand h2 { margin: 0; color: #fff; font-size: 20px; letter-spacing: -0.5px; }
.navbar-brand span { font-size: 11px; color: rgba(255,255,255,0.45); letter-spacing: 1px; }
.navbar-links { display: flex; align-items: center; gap: 2px; flex: 1; }
.navbar-links a { color: rgba(255,255,255,0.7); text-decoration: none; padding: 8px 13px; border-radius: 6px; font-size: 13.5px; white-space: nowrap; transition: background 0.18s, color 0.18s; }
.navbar-links a:hover { background: rgba(255,255,255,0.1); color: #fff; }
.navbar-links a.active { background: rgba(255,255,255,0.15); color: #fff; }
.navbar-right { margin-left: auto; flex-shrink: 0; }
.logout-btn { background: #ff3b30; border: none; color: white; padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit; }
.logout-btn:hover { background: #d0302a; }

.main { margin-top: 60px; padding: 30px; }

.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px; flex-wrap: wrap; gap: 12px; }
.page-header h2 { margin: 0; font-size: 24px; color: #0f2027; }
.pending-badge { background: #fee2e2; color: #991b1b; padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }

.filter-toolbar { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.btn { padding: 8px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit; transition: background 0.18s; white-space: nowrap; }
.btn-outline { background: white; color: #374151; border: 1px solid #cbd5e1; }
.btn-outline:hover { background: #f8fafc; }
.btn-outline.active-filter { background: #0f2027; color: white; border-color: #0f2027; }

.table-wrap { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.5px; }
td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; vertical-align: top; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }

.status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.s-pending  { background: #fef3c7; color: #92400e; }
.s-approved { background: #d1fae5; color: #065f46; }
.s-rejected { background: #fee2e2; color: #991b1b; }

.field-badge { background: #e0f2fe; color: #0369a1; display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; }

.action-btn { padding: 5px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-family: inherit; transition: background 0.18s; }
.btn-approve { background: #d1fae5; color: #065f46; }
.btn-approve:hover { background: #a7f3d0; }
.btn-reject  { background: #fee2e2; color: #991b1b; }
.btn-reject:hover  { background: #fecaca; }

.old-val { color: #94a3b8; font-size: 12px; text-decoration: line-through; }
.new-val { color: #0f2027; font-weight: 600; }

.empty-msg { text-align: center; color: #94a3b8; padding: 40px; font-size: 14px; }

/* Reject modal */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 500; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: white; border-radius: 14px; padding: 28px 32px; width: 100%; max-width: 400px; box-shadow: 0 12px 40px rgba(0,0,0,0.18); }
.modal h3 { margin: 0 0 16px; font-size: 17px; color: #0f2027; }
.modal textarea { width: 100%; padding: 10px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; outline: none; resize: vertical; min-height: 90px; }
.modal textarea:focus { border-color: #0077b6; }
.modal-actions { display: flex; gap: 10px; margin-top: 16px; }
.btn-modal-confirm { flex: 1; padding: 10px; background: #dc2626; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn-modal-confirm:hover { background: #b91c1c; }
.btn-modal-cancel { padding: 10px 18px; background: #f1f5f9; color: #64748b; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; font-family: inherit; }

.toast { position: fixed; bottom: 24px; right: 24px; background: #0f2027; color: white; padding: 12px 20px; border-radius: 8px; font-size: 14px; z-index: 999; transform: translateY(20px); opacity: 0; transition: all 0.3s; pointer-events: none; }
.toast.show { transform: translateY(0); opacity: 1; }
</style>
</head>
<body>

<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2>
        <span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="edit_requests_admin.php" class="active">📝 Edit Requests</a>
        <a href="#">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="page-header">
        <h2>📝 Student Edit Requests</h2>
        <?php if ($pendingCount > 0): ?>
        <span class="pending-badge">⚠ <?= $pendingCount ?> Pending</span>
        <?php endif; ?>
    </div>

    <div class="filter-toolbar">
        <button class="btn btn-outline active-filter" onclick="filterStatus('all', this)">All</button>
        <button class="btn btn-outline" onclick="filterStatus('pending', this)">⏳ Pending</button>
        <button class="btn btn-outline" onclick="filterStatus('approved', this)">✓ Approved</button>
        <button class="btn btn-outline" onclick="filterStatus('rejected', this)">✗ Rejected</button>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Field</th>
                    <th>Change</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="reqTable">
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" class="empty-msg">No edit requests yet.</td></tr>
            <?php else: ?>
                <?php foreach ($requests as $r): ?>
                <tr data-status="<?= $r['status'] ?>" id="row-<?= $r['request_id'] ?>">
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($r['student_name']) ?></div>
                        <div style="font-size:12px;color:#94a3b8;"><?= htmlspecialchars($r['student_number'] ?? '') ?></div>
                    </td>
                    <td><span class="field-badge"><?= htmlspecialchars($fieldLabels[$r['field_name']] ?? $r['field_name']) ?></span></td>
                    <td>
                        <div class="old-val"><?= htmlspecialchars($r['old_value'] ?? '(none)') ?></div>
                        <div class="new-val">→ <?= htmlspecialchars($r['new_value']) ?></div>
                    </td>
                    <td style="color:#64748b;font-size:13px;max-width:200px;"><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
                    <td>
                        <span class="status-badge s-<?= $r['status'] ?>" id="badge-<?= $r['request_id'] ?>">
                            <?= ucfirst($r['status']) ?>
                        </span>
                        <?php if ($r['status'] === 'rejected' && $r['reject_note']): ?>
                        <div style="font-size:11px;color:#991b1b;margin-top:3px;"><?= htmlspecialchars($r['reject_note']) ?></div>
                        <?php endif; ?>
                        <?php if ($r['status'] !== 'pending' && $r['reviewed_by_name']): ?>
                        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">by <?= htmlspecialchars($r['reviewed_by_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#94a3b8;font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                    <td id="actions-<?= $r['request_id'] ?>">
                        <?php if ($r['status'] === 'pending'): ?>
                        <button class="action-btn btn-approve" onclick="approve(<?= $r['request_id'] ?>)">✓ Approve</button>
                        <button class="action-btn btn-reject"  onclick="openReject(<?= $r['request_id'] ?>)">✗ Reject</button>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">Reviewed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <h3>Reject Request</h3>
        <p style="font-size:13px;color:#64748b;margin:0 0 12px;">Optionally explain why the request is being rejected. This note will be emailed to the student.</p>
        <textarea id="rejectNote" placeholder="e.g. Please contact the registrar's office to update your student number."></textarea>
        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeReject()">Cancel</button>
            <button class="btn-modal-confirm" onclick="confirmReject()">Reject Request</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let rejectingId = null;
const allRows   = Array.from(document.querySelectorAll('#reqTable tr[data-status]'));

function filterStatus(status, btn) {
    document.querySelectorAll('.filter-toolbar .btn').forEach(b => b.classList.remove('active-filter'));
    btn.classList.add('active-filter');
    allRows.forEach(row => {
        row.style.display = status === 'all' || row.dataset.status === status ? '' : 'none';
    });
}

async function approve(id) {
    if (!confirm('Approve this request and apply the change to the student account?')) return;
    const body = new FormData();
    body.append('action', 'approve');
    body.append('request_id', id);
    const res  = await fetch('php/edit_requests.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
        updateRow(id, 'approved');
        showToast('Request approved and change applied.');
    } else {
        showToast('Error: ' + (data.error || 'Unknown'));
    }
}

function openReject(id) {
    rejectingId = id;
    document.getElementById('rejectNote').value = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() {
    document.getElementById('rejectModal').classList.remove('open');
    rejectingId = null;
}
async function confirmReject() {
    const note = document.getElementById('rejectNote').value.trim();
    const body = new FormData();
    body.append('action', 'reject');
    body.append('request_id', rejectingId);
    body.append('reject_note', note);
    const res  = await fetch('php/edit_requests.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
        updateRow(rejectingId, 'rejected', note);
        closeReject();
        showToast('Request rejected. Student has been notified.');
    } else {
        showToast('Error: ' + (data.error || 'Unknown'));
    }
}

function updateRow(id, status, note = '') {
    const badge   = document.getElementById('badge-' + id);
    const actions = document.getElementById('actions-' + id);
    const row     = document.getElementById('row-' + id);
    if (badge)   { badge.className = 'status-badge s-' + status; badge.textContent = status.charAt(0).toUpperCase() + status.slice(1); }
    if (actions) actions.innerHTML = '<span style="color:#94a3b8;font-size:12px;">Reviewed</span>';
    if (row)     row.dataset.status = status;
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeReject();
});
</script>
</body>
</html>