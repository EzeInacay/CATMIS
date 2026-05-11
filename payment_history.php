<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// ── AJAX: Review a proof (confirm or reject) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    include 'php/get_balance.php';
    include 'php/mailer.php';

    $action    = $_POST['action'];
    $proof_id  = intval($_POST['proof_id']   ?? 0);
    $decision  = trim($_POST['decision']     ?? '');
    $admin_note = trim($_POST['admin_note']  ?? '');

    if ($action !== 'review_proof' || !in_array($decision, ['confirmed','rejected']) || !$proof_id) {
        echo json_encode(['error' => 'Invalid request.']); exit;
    }

    // Fetch proof + student info
    $pStmt = $conn->prepare("
        SELECT pp.*, u.full_name, u.email, u.student_number
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
        $upd = $conn->prepare("
            UPDATE payment_proofs
            SET status=?, reviewed_by=?, reviewed_at=NOW(), admin_note=?
            WHERE proof_id=?
        ");
        $upd->bind_param('sisi', $decision, $admin_id, $admin_note, $proof_id);
        $upd->execute();

        if ($decision === 'confirmed') {
            // Auto-generate OR number
            $orNum = 'OR-' . strtoupper(date('ymd')) . '-' . str_pad($proof_id, 4, '0', STR_PAD_LEFT);

            // Insert payment record
            $pay = $conn->prepare("
                INSERT INTO payments (account_id, student_id, amount, method, or_number, payment_date, posted_by)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?)
            ");
            $pay->bind_param('iidssi',
                $proof['account_id'], $proof['student_id'],
                $proof['amount'], $proof['method'], $orNum, $admin_id);
            $pay->execute();

            // Post to ledger
            $led = $conn->prepare("
                INSERT INTO student_ledgers (account_id, entry_type, amount, remarks, posted_by)
                VALUES (?, 'PAYMENT', ?, ?, ?)
            ");
            $remarks = "Online payment via {$proof['method']} — OR# {$orNum}";
            $led->bind_param('idsi', $proof['account_id'], $proof['amount'], $remarks, $admin_id);
            $led->execute();

            $newBal = getBalance($conn, $proof['account_id']);

            // Email student receipt
            mailPaymentPosted(
                $proof['email'], $proof['full_name'],
                $orNum, $proof['amount'], $proof['method'], $newBal
            );

            // Audit log
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
            $act = "Confirmed payment proof #{$proof_id} for {$proof['full_name']} — ₱{$proof['amount']} via {$proof['method']} (OR# {$orNum})";
            $log->bind_param('is', $admin_id, $act); $log->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'decision' => 'confirmed', 'or_number' => $orNum,
                'message' => "Payment confirmed. OR# {$orNum} posted to ledger. Receipt emailed."]);

        } else {
            // Email student rejection
            $subj = 'Payment Proof Not Accepted — CATMIS';
            $body = "<p>Dear <strong>{$proof['full_name']}</strong>,</p>
                <p>Your submitted payment proof of <strong>₱" . number_format($proof['amount'], 2) . "</strong>
                via {$proof['method']} could not be verified.</p>"
                . ($admin_note ? "<p><strong>Reason:</strong> {$admin_note}</p>" : '')
                . "<p>Please resubmit with a clearer screenshot or visit the school finance office.</p>";
            sendMail($proof['email'], $proof['full_name'], $subj, $body);

            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
            $act = "Rejected payment proof #{$proof_id} for {$proof['full_name']}";
            $log->bind_param('is', $admin_id, $act); $log->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'decision' => 'rejected',
                'message' => 'Proof rejected. Student has been notified by email.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── Fetch confirmed payments ──────────────────────────────────────
$result = $conn->query("
    SELECT
        p.payment_id, p.payment_date,
        u_student.full_name AS student_name,
        s.grade_level, s.section,
        p.amount, p.method, p.or_number,
        u_admin.full_name AS processed_by
    FROM payments p
    JOIN students s      ON p.student_id = s.student_id
    JOIN users u_student ON s.user_id    = u_student.user_id
    JOIN users u_admin   ON p.posted_by  = u_admin.user_id
    ORDER BY p.payment_date DESC
");
$payments = [];
$total    = 0;
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
    $total += $row['amount'];
}

// ── Fetch pending proofs ──────────────────────────────────────────
$pendingProofs = $conn->query("
    SELECT
        pp.proof_id, pp.amount, pp.method, pp.reference, pp.note,
        pp.file_path, pp.submitted_at,
        u.full_name AS student_name, u.student_number, u.email,
        s.grade_level, s.section
    FROM payment_proofs pp
    JOIN students st ON pp.student_id = st.student_id
    JOIN users u ON st.user_id = u.user_id
    JOIN students s ON pp.student_id = s.student_id
    WHERE pp.status = 'pending'
    ORDER BY pp.submitted_at ASC
")->fetch_all(MYSQLI_ASSOC);

$pendingCount = count($pendingProofs);

// Unread notifications for bell
$_nRes = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE admin_id=? AND is_read=0");
$_nRes->bind_param('i', $admin_id);
$_nRes->execute();
$unreadNotifs = $_nRes->get_result()->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment History | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
.navbar-right { margin-left: auto; display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.logout-btn { background: #ff3b30; border: none; color: white; padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit; }
.logout-btn:hover { background: #d0302a; }

.main { margin-top: 60px; padding: 30px; }

.summary-cards { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
.sum-card { flex: 1; min-width: 160px; background: white; border-radius: 10px; padding: 18px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-left: 5px solid #0077b6; }
.sum-card.green  { border-color: #198754; }
.sum-card.amber  { border-color: #d97706; }
.sum-card.purple { border-color: #7c3aed; }
.sum-card.red    { border-color: #dc2626; }
.sum-card h4 { margin: 0 0 6px; font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.sum-card p  { margin: 0; font-size: 22px; font-weight: 700; color: #0f2027; }

.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.search-box { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; width: 240px; outline: none; }
.search-box:focus { border-color: #0077b6; box-shadow: 0 0 0 3px rgba(0,119,182,0.1); }
.filter-select { padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; color: #0f2027; outline: none; background: white; }
.date-input { padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; outline: none; }
.btn { padding: 9px 15px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-family: inherit; display: inline-flex; align-items: center; gap: 5px; transition: background 0.18s; white-space: nowrap; }
.btn-success { background: #198754; color: white; }
.btn-success:hover { background: #157347; }
.btn-outline { background: white; color: #374151; border: 1px solid #cbd5e1; }
.btn-outline:hover { background: #f8fafc; }

.table-wrap { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 28px; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8fafc; text-align: left; font-size: 12px; font-weight: 600; color: #64748b; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.5px; }
td { padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f2027; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8faff; }
.amount { text-align: right; font-variant-numeric: tabular-nums; }
.method-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.method-Cash { background: #d1fae5; color: #065f46; }
.method-GCash { background: #e0f2fe; color: #0369a1; }
.method-Bank-Transfer { background: #f3e8ff; color: #6b21a8; }
.total-row td { font-weight: 700; background: #f8fafc; border-top: 2px solid #e2e8f0; }
.empty-msg { text-align: center; color: #94a3b8; padding: 40px; font-size: 14px; }

/* ── Proof review cards ── */
.proof-queue { margin-bottom: 28px; }
.proof-queue-head {
    display: flex; align-items: center; gap: 12px; margin-bottom: 14px;
}
.proof-queue-head h2 { margin: 0; font-size: 20px; color: #0f2027; }
.proof-badge { background: #dc2626; color: white; border-radius: 20px; padding: 2px 10px; font-size: 13px; font-weight: 700; }

.proof-card {
    background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.06);
    border-left: 5px solid #d97706; margin-bottom: 14px; overflow: hidden;
}
.proof-card-head { padding: 16px 20px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; border-bottom: 1px solid #f1f5f9; }
.proof-student { font-size: 15px; font-weight: 700; color: #0f2027; }
.proof-meta { font-size: 13px; color: #64748b; }
.proof-amount { font-size: 20px; font-weight: 700; color: #0f2027; margin-left: auto; }
.proof-card-body { padding: 16px 20px; display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
.proof-img-wrap { flex-shrink: 0; }
.proof-img-wrap img { width: 180px; height: 180px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0; cursor: pointer; transition: transform 0.2s; }
.proof-img-wrap img:hover { transform: scale(1.03); }
.proof-img-wrap .pdf-icon { width: 180px; height: 180px; background: #f1f5f9; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 40px; color: #94a3b8; gap: 8px; font-size: 12px; }
.proof-details { flex: 1; min-width: 220px; }
.proof-detail-row { display: flex; gap: 8px; margin-bottom: 8px; font-size: 13px; }
.proof-detail-row label { color: #94a3b8; font-weight: 600; min-width: 90px; text-transform: uppercase; font-size: 11px; letter-spacing: 0.4px; margin-top: 2px; }
.proof-detail-row span { color: #0f2027; }
.proof-actions { flex: 1; min-width: 240px; }
.note-input { width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 13px; font-family: inherit; margin-bottom: 10px; resize: vertical; min-height: 60px; }
.note-input:focus { outline: none; border-color: #0f6e56; }
.action-btns { display: flex; gap: 10px; }
.btn-confirm { flex: 1; padding: 10px; background: #198754; color: white; border: none; border-radius: 7px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.18s; }
.btn-confirm:hover { background: #157347; }
.btn-reject  { flex: 1; padding: 10px; background: #dc2626; color: white; border: none; border-radius: 7px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.18s; }
.btn-reject:hover { background: #b91c1c; }
.btn-confirm:disabled, .btn-reject:disabled { background: #94a3b8; cursor: not-allowed; }

/* Image lightbox */
.lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 500; align-items: center; justify-content: center; }
.lightbox.open { display: flex; }
.lightbox img { max-width: 90vw; max-height: 90vh; border-radius: 10px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.lightbox-close { position: absolute; top: 20px; right: 28px; color: white; font-size: 36px; cursor: pointer; line-height: 1; }

.toast { position: fixed; bottom: 28px; right: 28px; background: #0f2027; color: white; padding: 13px 22px; border-radius: 10px; font-size: 14px; z-index: 999; opacity: 0; transform: translateY(10px); transition: all 0.3s; pointer-events: none; }
.toast.show { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand">
        <h2>CATMIS</h2><span>CCS Portal</span>
    </a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php" class="active">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="financial_report.php">📊 Reports</a>
        <a href="backup.php">💾 Backup</a>
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

<div class="main">

    <!-- ===== PENDING PROOF REVIEW ===== -->
    <?php if (!empty($pendingProofs)): ?>
    <div class="proof-queue">
        <div class="proof-queue-head">
            <h2>📥 Payment Proofs Awaiting Review</h2>
            <span class="proof-badge"><?= $pendingCount ?> pending</span>
        </div>

        <?php foreach ($pendingProofs as $proof): ?>
        <?php
            $isPdf    = str_ends_with(strtolower($proof['file_path']), '.pdf');
            $fileUrl  = htmlspecialchars($proof['file_path']);
            $submitted = date('M d, Y h:i A', strtotime($proof['submitted_at']));
        ?>
        <div class="proof-card" id="proofCard-<?= $proof['proof_id'] ?>">
            <div class="proof-card-head">
                <div>
                    <div class="proof-student"><?= htmlspecialchars($proof['student_name']) ?></div>
                    <div class="proof-meta">
                        <?= htmlspecialchars($proof['student_number'] ?? '') ?>
                        · Grade <?= htmlspecialchars($proof['grade_level']) ?> – <?= htmlspecialchars($proof['section']) ?>
                        · <?= htmlspecialchars($proof['email']) ?>
                    </div>
                </div>
                <div class="proof-amount">₱<?= number_format($proof['amount'], 2) ?></div>
            </div>
            <div class="proof-card-body">
                <!-- Screenshot / PDF -->
                <div class="proof-img-wrap">
                    <?php if ($isPdf): ?>
                    <div class="pdf-icon">
                        <div style="font-size:36px;">📄</div>
                        <div>PDF Attachment</div>
                        <a href="<?= $fileUrl ?>" target="_blank" style="color:#0369a1;font-size:12px;">Open PDF</a>
                    </div>
                    <?php else: ?>
                    <img src="<?= $fileUrl ?>" alt="Payment proof"
                         onclick="openLightbox('<?= $fileUrl ?>')"
                         title="Click to enlarge">
                    <?php endif; ?>
                </div>

                <!-- Details -->
                <div class="proof-details">
                    <div class="proof-detail-row"><label>Method</label><span><?= htmlspecialchars($proof['method']) ?></span></div>
                    <div class="proof-detail-row"><label>Reference</label><span><?= htmlspecialchars($proof['reference'] ?: '—') ?></span></div>
                    <div class="proof-detail-row"><label>Submitted</label><span><?= $submitted ?></span></div>
                    <?php if ($proof['note']): ?>
                    <div class="proof-detail-row"><label>Student Note</label><span><?= htmlspecialchars($proof['note']) ?></span></div>
                    <?php endif; ?>
                </div>

                <!-- Action buttons -->
                <div class="proof-actions">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.4px;">
                        Admin Note <span style="font-weight:400;color:#94a3b8;">(optional for reject)</span>
                    </label>
                    <textarea class="note-input" id="note-<?= $proof['proof_id'] ?>"
                              placeholder="Reason for rejection or any note…"></textarea>
                    <div class="action-btns">
                        <button class="btn-confirm" onclick="reviewProof(<?= $proof['proof_id'] ?>, 'confirmed')">
                            ✅ Confirm Payment
                        </button>
                        <button class="btn-reject" onclick="reviewProof(<?= $proof['proof_id'] ?>, 'rejected')">
                            ❌ Reject
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ===== SUMMARY CARDS ===== -->
    <div class="summary-cards">
        <div class="sum-card">
            <h4>Total Collected</h4>
            <p id="cardTotal">₱<?= number_format($total, 2) ?></p>
        </div>
        <div class="sum-card green">
            <h4>Total Transactions</h4>
            <p id="cardCount"><?= count($payments) ?></p>
        </div>
        <div class="sum-card amber">
            <h4>Cash</h4>
            <p>₱<?= number_format(array_sum(array_column(array_filter($payments, fn($p) => $p['method'] === 'Cash'), 'amount')), 2) ?></p>
        </div>
        <div class="sum-card purple">
            <h4>GCash / Bank</h4>
            <p>₱<?= number_format(array_sum(array_column(array_filter($payments, fn($p) => $p['method'] !== 'Cash'), 'amount')), 2) ?></p>
        </div>
        <?php if ($pendingCount > 0): ?>
        <div class="sum-card red">
            <h4>Pending Proofs</h4>
            <p><?= $pendingCount ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="toolbar">
        <input type="text" class="search-box" id="searchInput" placeholder="🔍 Search student, OR number…" oninput="applyFilters()">
        <select class="filter-select" id="methodFilter" onchange="applyFilters()">
            <option value="all">All Methods</option>
            <option value="Cash">Cash</option>
            <option value="GCash">GCash</option>
            <option value="Bank Transfer">Bank Transfer</option>
        </select>
        <input type="date" class="date-input" id="dateFrom" onchange="applyFilters()" title="From date">
        <input type="date" class="date-input" id="dateTo"   onchange="applyFilters()" title="To date">
        <button class="btn btn-outline" onclick="clearFilters()">✕ Clear</button>
        <button class="btn btn-success" onclick="exportExcel()">📥 Export Excel</button>
    </div>

    <!-- ===== PAYMENT TABLE ===== -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Grade & Section</th>
                    <th class="amount">Amount</th>
                    <th>Method</th>
                    <th>OR Number</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody id="paymentTable">
            <?php if (empty($payments)): ?>
                <tr><td colspan="7" class="empty-msg">No payments found.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $row): ?>
                <?php
                    $dateISO     = date('Y-m-d', strtotime($row['payment_date']));
                    $methodClass = 'method-' . str_replace(' ', '-', $row['method']);
                ?>
                <tr
                    data-date="<?= $dateISO ?>"
                    data-method="<?= htmlspecialchars($row['method']) ?>"
                    data-search="<?= htmlspecialchars(strtolower($row['student_name'] . ' ' . $row['or_number'] . ' ' . $row['processed_by'])) ?>"
                    data-amount="<?= $row['amount'] ?>">
                    <td><?= date('M d, Y', strtotime($row['payment_date'])) ?></td>
                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                    <td>Grade <?= htmlspecialchars($row['grade_level']) ?> – <?= htmlspecialchars($row['section']) ?></td>
                    <td class="amount">₱<?= number_format($row['amount'], 2) ?></td>
                    <td><span class="method-badge <?= $methodClass ?>"><?= htmlspecialchars($row['method']) ?></span></td>
                    <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($row['or_number']) ?></td>
                    <td style="color:#64748b;"><?= htmlspecialchars($row['processed_by']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3"><strong>Showing Total</strong></td>
                    <td class="amount" id="filteredTotal"><strong>₱<?= number_format($total, 2) ?></strong></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close" onclick="closeLightbox()">×</span>
    <img id="lightboxImg" src="" alt="Payment proof">
</div>

<div class="toast" id="toast"></div>

<script>
// ── Proof review ──────────────────────────────────────────────────
async function reviewProof(proofId, decision) {
    const note    = document.getElementById('note-' + proofId)?.value || '';
    const card    = document.getElementById('proofCard-' + proofId);
    const buttons = card.querySelectorAll('button');
    buttons.forEach(b => b.disabled = true);

    const body = new FormData();
    body.append('action',     'review_proof');
    body.append('proof_id',   proofId);
    body.append('decision',   decision);
    body.append('admin_note', note);

    const res  = await fetch('payment_history.php', { method: 'POST', body });
    const data = await res.json();

    if (data.error) {
        showToast('⚠ ' + data.error, true);
        buttons.forEach(b => b.disabled = false);
        return;
    }

    showToast('✓ ' + data.message);

    // Animate card out
    card.style.transition = 'opacity 0.4s, transform 0.4s';
    card.style.opacity    = '0';
    card.style.transform  = 'translateY(-10px)';
    setTimeout(() => {
        card.remove();
        // If no more proof cards, hide the section header
        if (!document.querySelector('.proof-card')) {
            document.querySelector('.proof-queue')?.remove();
        }
    }, 420);
}

// ── Filters ───────────────────────────────────────────────────────
const allRows = Array.from(document.querySelectorAll('#paymentTable tr[data-date]'));

function applyFilters() {
    const q       = document.getElementById('searchInput').value.toLowerCase();
    const method  = document.getElementById('methodFilter').value;
    const dateFrom= document.getElementById('dateFrom').value;
    const dateTo  = document.getElementById('dateTo').value;
    let visibleTotal = 0;

    allRows.forEach(row => {
        const ok = (!q      || row.dataset.search.includes(q))
                && (method === 'all' || row.dataset.method === method)
                && (!dateFrom || row.dataset.date >= dateFrom)
                && (!dateTo   || row.dataset.date <= dateTo);
        row.style.display = ok ? '' : 'none';
        if (ok) visibleTotal += parseFloat(row.dataset.amount);
    });

    document.getElementById('filteredTotal').innerHTML =
        '<strong>₱' + visibleTotal.toLocaleString('en-PH', { minimumFractionDigits: 2 }) + '</strong>';
}

function clearFilters() {
    document.getElementById('searchInput').value  = '';
    document.getElementById('methodFilter').value = 'all';
    document.getElementById('dateFrom').value     = '';
    document.getElementById('dateTo').value       = '';
    applyFilters();
}

function exportExcel() {
    const headers = ['Date','Student','Grade & Section','Amount','Method','OR Number','Processed By'];
    const data    = [headers];
    allRows.forEach(row => {
        if (row.style.display === 'none') return;
        const c = row.querySelectorAll('td');
        data.push([c[0].textContent.trim(), c[1].textContent.trim(), c[2].textContent.trim(),
                   c[3].textContent.trim(), c[4].textContent.trim(), c[5].textContent.trim(), c[6].textContent.trim()]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:14},{wch:26},{wch:18},{wch:14},{wch:16},{wch:18},{wch:22}];
    XLSX.utils.book_append_sheet(wb, ws, 'Payment History');
    XLSX.writeFile(wb, `CATMIS_Payments_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// ── Lightbox ──────────────────────────────────────────────────────
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
}

function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = isError ? '#dc2626' : '#0f2027';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 4500);
}
</script>
</body>
</html>
