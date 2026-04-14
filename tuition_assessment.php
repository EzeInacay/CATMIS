<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// ── Handle AJAX actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // Add or update a fee row
    if ($action === 'save_fee') {
        $fee_id     = intval($_POST['fee_id'] ?? 0);
        $sy_id      = intval($_POST['sy_id']     ?? 0);
        $grade_group = trim($_POST['grade_group'] ?? '');
        $strand     = trim($_POST['strand']       ?? '') ?: null;
        $label      = trim($_POST['label']        ?? '');
        $amount     = floatval($_POST['amount']   ?? 0);
        $sort_order = intval($_POST['sort_order'] ?? 99);

        if (!$grade_group || !$label || !$sy_id) {
            echo json_encode(['error' => 'Missing fields.']); exit;
        }

        if ($fee_id > 0) {
            $stmt = $conn->prepare("UPDATE tuition_fees SET label=?, amount=?, sort_order=? WHERE fee_id=?");
            $stmt->bind_param('sdii', $label, $amount, $sort_order, $fee_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO tuition_fees (sy_id, grade_group, strand, label, amount, sort_order) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('isssdi', $sy_id, $grade_group, $strand, $label, $amount, $sort_order);
        }
        $stmt->execute();
        echo json_encode(['success' => true, 'fee_id' => $fee_id > 0 ? $fee_id : $conn->insert_id]);
        exit;
    }

    // Delete a fee row
    if ($action === 'delete_fee') {
        $fee_id = intval($_POST['fee_id'] ?? 0);
        $stmt   = $conn->prepare("DELETE FROM tuition_fees WHERE fee_id=?");
        $stmt->bind_param('i', $fee_id);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action.']); exit;
}

// ── Load school years ────────────────────────────────────────────
$syResult = $conn->query("SELECT sy_id, name, status FROM school_years ORDER BY sy_id DESC");
$schoolYears = [];
$activeSyId  = null;
while ($sy = $syResult->fetch_assoc()) {
    $schoolYears[] = $sy;
    if ($sy['status'] === 'active') $activeSyId = $sy['sy_id'];
}

// ── Load all fees for active SY ───────────────────────────────────
$selectedSyId = intval($_GET['sy_id'] ?? $activeSyId);
$feesResult = $conn->prepare("SELECT * FROM tuition_fees WHERE sy_id=? ORDER BY grade_group, strand, sort_order");
$feesResult->bind_param('i', $selectedSyId);
$feesResult->execute();
$allFees = $feesResult->get_result()->fetch_all(MYSQLI_ASSOC);

// Group fees: [grade_group][strand] => [fees]
$grouped = [];
foreach ($allFees as $fee) {
    $g = $fee['grade_group'];
    $s = $fee['strand'] ?? '';
    $grouped[$g][$s][] = $fee;
}

$gradeGroups = ['1-3', '4-6', '7-10', '11-12'];
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
.navbar-right { display: flex; align-items: center; gap: 12px; margin-left: auto; flex-shrink: 0; }
.logout-btn {
    background: #ff3b30; border: none; color: white; padding: 7px 16px;
    border-radius: 6px; cursor: pointer; font-size: 13px; font-family: 'Segoe UI', Arial, sans-serif;
    transition: background 0.18s;
}
.logout-btn:hover { background: #d0302a; }

/* ===== MAIN ===== */
.main { margin-top: 60px; padding: 30px; }

.page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-header h2 { margin: 0; font-size: 24px; color: #0f2027; }

.sy-selector { display: flex; align-items: center; gap: 10px; }
.sy-selector label { font-size: 13px; color: #64748b; font-weight: 600; }
.sy-selector select {
    padding: 8px 32px 8px 12px;
    border: 1px solid #cbd5e1; border-radius: 6px;
    background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat right 10px center;
    appearance: none; font-size: 13px; color: #0f2027; cursor: pointer;
}

/* ===== GRADE GROUP CARDS ===== */
.group-card {
    background: white; border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    margin-bottom: 28px; overflow: hidden;
}
.group-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
.group-header h3 { margin: 0; font-size: 16px; color: #0f2027; }
.grade-label {
    font-size: 11px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase;
    background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 20px;
}

.btn-add {
    background: #0077b6; color: white; border: none; padding: 7px 14px;
    border-radius: 6px; cursor: pointer; font-size: 13px; display: flex;
    align-items: center; gap: 5px; transition: background 0.18s;
}
.btn-add:hover { background: #005f8e; }

/* ===== FEE TABLE ===== */
.strand-section { padding: 0 20px 20px; }
.strand-title {
    font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
    color: #64748b; padding: 14px 0 8px; border-top: 1px solid #f1f5f9; margin-top: 4px;
}
.strand-title:first-child { border-top: none; }

.fee-table { width: 100%; border-collapse: collapse; }
.fee-table th {
    text-align: left; font-size: 12px; font-weight: 600; color: #64748b;
    padding: 9px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.fee-table td { padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
.fee-table tr:last-child td { border-bottom: none; }
.fee-table .total-row td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; color: #0f2027; }

.fee-amount { text-align: right; font-variant-numeric: tabular-nums; }
.fee-actions { text-align: center; width: 80px; }

.btn-edit { background: none; border: none; cursor: pointer; color: #0077b6; font-size: 14px; padding: 3px 6px; border-radius: 4px; }
.btn-edit:hover { background: #e0f2fe; }
.btn-del  { background: none; border: none; cursor: pointer; color: #dc2626; font-size: 14px; padding: 3px 6px; border-radius: 4px; }
.btn-del:hover  { background: #fee2e2; }

/* ===== MODAL ===== */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 500;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: white; border-radius: 14px; padding: 32px 36px;
    width: 100%; max-width: 420px; box-shadow: 0 12px 40px rgba(0,0,0,0.15);
}
.modal h3 { margin: 0 0 22px; font-size: 18px; color: #0f2027; }

.form-field { margin-bottom: 16px; }
.form-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 6px; }
.form-field input, .form-field select {
    width: 100%; padding: 10px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-size: 14px; color: #0f2027; font-family: 'Segoe UI', Arial, sans-serif;
    outline: none; transition: border-color 0.2s;
}
.form-field input:focus, .form-field select:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }

.modal-actions { display: flex; gap: 10px; margin-top: 24px; }
.btn-save { flex: 1; padding: 11px; background: #0077b6; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.18s; }
.btn-save:hover { background: #005f8e; }
.btn-close { padding: 11px 20px; background: #f1f5f9; color: #64748b; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
.btn-close:hover { background: #e2e8f0; }

.toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #0f2027; color: white; padding: 12px 20px;
    border-radius: 8px; font-size: 14px; z-index: 999;
    transform: translateY(20px); opacity: 0; transition: all 0.3s;
    pointer-events: none;
}
.toast.show { transform: translateY(0); opacity: 1; }
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
        <a href="tuition_assessment.php" class="active">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
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
        <h2>📂 Tuition Assessment</h2>
        <div class="sy-selector">
            <label for="sySelect">School Year:</label>
            <select id="sySelect" onchange="window.location.href='tuition_assessment.php?sy_id='+this.value">
                <?php foreach ($schoolYears as $sy): ?>
                <option value="<?= $sy['sy_id'] ?>" <?= $sy['sy_id'] == $selectedSyId ? 'selected' : '' ?>>
                    SY <?= htmlspecialchars($sy['name']) ?><?= $sy['status'] === 'active' ? ' ✓' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php foreach ($gradeGroups as $group): ?>
    <?php
        $groupFees = $grouped[$group] ?? [];
        // For non-SHS groups there's only one strand key (empty string)
        $isSHS = $group === '11-12';
        $strands = $isSHS ? ['STEM', 'ABM', 'HUMSS'] : [''];
    ?>
    <div class="group-card" id="group-<?= str_replace('-','_',$group) ?>">
        <div class="group-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <h3>Grade <?= htmlspecialchars($group) ?></h3>
                <span class="grade-label"><?= $isSHS ? 'Senior High' : ($group === '1-3' ? 'Primary' : ($group === '4-6' ? 'Intermediate' : 'Junior High')) ?></span>
            </div>
            <button class="btn-add" onclick="openModal('<?= $group ?>','')">＋ Add Fee</button>
        </div>

        <div class="strand-section">
        <?php foreach ($strands as $strand): ?>
            <?php
                $fees = $groupFees[$strand] ?? [];
                $total = array_sum(array_column($fees, 'amount'));
            ?>
            <?php if ($isSHS): ?>
            <div class="strand-title"><?= $strand ?> Strand</div>
            <?php endif; ?>

            <?php if (empty($fees)): ?>
                <p style="color:#94a3b8;font-size:13px;padding:8px 0;">No fees configured yet.
                    <a href="#" onclick="openModal('<?= $group ?>','<?= $strand ?>')" style="color:#0077b6;">Add one →</a>
                </p>
            <?php else: ?>
            <table class="fee-table">
                <thead>
                    <tr>
                        <th style="width:50%">Fee Type</th>
                        <th class="fee-amount">Amount (₱)</th>
                        <th class="fee-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fees as $fee): ?>
                <tr id="fee-row-<?= $fee['fee_id'] ?>">
                    <td><?= htmlspecialchars($fee['label']) ?></td>
                    <td class="fee-amount">₱<?= number_format($fee['amount'], 2) ?></td>
                    <td class="fee-actions">
                        <button class="btn-edit" title="Edit" onclick="openModal('<?= $group ?>','<?= $strand ?>',<?= htmlspecialchars(json_encode($fee)) ?>)">✏️</button>
                        <button class="btn-del"  title="Delete" onclick="deleteFee(<?= $fee['fee_id'] ?>, this)">🗑</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>Total Assessment</td>
                    <td class="fee-amount" id="total-<?= str_replace(['-',' '],'_',$group.'_'.$strand) ?>">₱<?= number_format($total, 2) ?></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
            <?php if ($isSHS): ?>
            <div style="margin-bottom:8px;"></div>
            <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ===== MODAL ===== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3 id="modalTitle">Add Fee</h3>
        <input type="hidden" id="mFeeId">
        <input type="hidden" id="mGradeGroup">
        <input type="hidden" id="mStrand">

        <div class="form-field">
            <label>Fee Name</label>
            <input type="text" id="mLabel" placeholder="e.g. Tuition Fee">
        </div>
        <div class="form-field">
            <label>Amount (₱)</label>
            <input type="number" id="mAmount" min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="form-field">
            <label>Sort Order</label>
            <input type="number" id="mSort" min="0" value="99">
        </div>

        <div class="modal-actions">
            <button class="btn-close" onclick="closeModal()">Cancel</button>
            <button class="btn-save" onclick="saveFee()">Save Fee</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const SY_ID = <?= $selectedSyId ?>;

function openModal(group, strand, fee = null) {
    document.getElementById('mGradeGroup').value = group;
    document.getElementById('mStrand').value     = strand;

    if (fee) {
        document.getElementById('modalTitle').textContent = 'Edit Fee';
        document.getElementById('mFeeId').value  = fee.fee_id;
        document.getElementById('mLabel').value  = fee.label;
        document.getElementById('mAmount').value = fee.amount;
        document.getElementById('mSort').value   = fee.sort_order;
    } else {
        document.getElementById('modalTitle').textContent = 'Add Fee — Grade ' + group + (strand ? ' (' + strand + ')' : '');
        document.getElementById('mFeeId').value  = '';
        document.getElementById('mLabel').value  = '';
        document.getElementById('mAmount').value = '';
        document.getElementById('mSort').value   = '99';
    }

    document.getElementById('modalOverlay').classList.add('open');
    document.getElementById('mLabel').focus();
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}

async function saveFee() {
    const label  = document.getElementById('mLabel').value.trim();
    const amount = document.getElementById('mAmount').value;
    if (!label || amount === '') { showToast('Please fill in all fields.'); return; }

    const body = new FormData();
    body.append('action',      'save_fee');
    body.append('sy_id',       SY_ID);
    body.append('grade_group', document.getElementById('mGradeGroup').value);
    body.append('strand',      document.getElementById('mStrand').value);
    body.append('fee_id',      document.getElementById('mFeeId').value || '0');
    body.append('label',       label);
    body.append('amount',      amount);
    body.append('sort_order',  document.getElementById('mSort').value);

    const res  = await fetch('tuition_assessment.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        showToast('Fee saved! Reloading…');
        closeModal();
        setTimeout(() => location.reload(), 700);
    } else {
        showToast('Error: ' + (data.error || 'Unknown'));
    }
}

async function deleteFee(fee_id, btn) {
    if (!confirm('Delete this fee?')) return;

    const body = new FormData();
    body.append('action',  'delete_fee');
    body.append('fee_id',  fee_id);

    const res  = await fetch('tuition_assessment.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
        const row = document.getElementById('fee-row-' + fee_id);
        if (row) row.remove();
        showToast('Fee deleted.');
    } else {
        showToast('Could not delete fee.');
    }
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

document.getElementById('modalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>