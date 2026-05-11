<?php
session_start();
include 'php/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// ── Handle mark-read / mark-all-read ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = intval($_POST['notif_id'] ?? 0);
        $conn->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=? AND admin_id=?")
             ->bind_param('ii', $id, $admin_id) && $conn->execute();
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=? AND admin_id=?");
        $stmt->bind_param('ii', $id, $admin_id);
        $stmt->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE admin_id=?");
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['notif_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notif_id=? AND admin_id=?");
        $stmt->bind_param('ii', $id, $admin_id);
        $stmt->execute();
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['error' => 'Unknown action.']); exit;
}

// ── Count unread ─────────────────────────────────────────────────
$unreadStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE admin_id=? AND is_read=0");
$unreadStmt->bind_param('i', $admin_id);
$unreadStmt->execute();
$unreadCount = $unreadStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

// ── Load notifications ───────────────────────────────────────────
$notifStmt = $conn->prepare("
    SELECT * FROM notifications
    WHERE admin_id = ?
    ORDER BY is_read ASC, created_at DESC
    LIMIT 100
");
$notifStmt->bind_param('i', $admin_id);
$notifStmt->execute();
$notifs = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$typeIcons = [
    'payment'      => '💰',
    'edit_request' => '✏️',
    'new_account'  => '👤',
    'balance_due'  => '⚠️',
    'system'       => '🔔',
];
$typeBg = [
    'payment'      => '#d1fae5',
    'edit_request' => '#fef3c7',
    'new_account'  => '#e0f2fe',
    'balance_due'  => '#fee2e2',
    'system'       => '#f3e8ff',
];
$typeColor = [
    'payment'      => '#065f46',
    'edit_request' => '#92400e',
    'new_account'  => '#0369a1',
    'balance_due'  => '#991b1b',
    'system'       => '#6b21a8',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="css/admind.css" rel="stylesheet">
<style>
.notif-toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.notif-toolbar h2 { margin:0; font-size:24px; color:#0f2027; }
.btn-mark-all { padding:8px 16px; background:#0077b6; color:white; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-family:inherit; }
.btn-mark-all:hover { background:#005f8e; }
.notif-list { display:flex; flex-direction:column; gap:10px; }
.notif-card {
    background:white; border-radius:12px; padding:16px 20px;
    box-shadow:0 2px 8px rgba(0,0,0,0.05);
    display:flex; align-items:flex-start; gap:14px;
    border-left:4px solid #e2e8f0;
    transition:opacity 0.3s;
}
.notif-card.unread { border-left-color:#0077b6; background:#f8fbff; }
.notif-icon { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.notif-body { flex:1; }
.notif-title { font-size:14px; font-weight:600; color:#0f2027; margin-bottom:3px; }
.notif-card.unread .notif-title::before { content:'● '; color:#0077b6; font-size:10px; }
.notif-msg { font-size:13px; color:#64748b; line-height:1.5; }
.notif-time { font-size:11px; color:#94a3b8; margin-top:5px; }
.notif-actions { display:flex; gap:6px; margin-left:auto; flex-shrink:0; }
.btn-read { padding:4px 10px; background:#e0f2fe; color:#0369a1; border:none; border-radius:6px; cursor:pointer; font-size:11px; font-weight:600; font-family:inherit; }
.btn-del  { padding:4px 10px; background:#fee2e2; color:#991b1b; border:none; border-radius:6px; cursor:pointer; font-size:11px; font-weight:600; font-family:inherit; }
.empty-box { background:white; border-radius:12px; padding:48px; text-align:center; box-shadow:0 4px 10px rgba(0,0,0,0.05); color:#94a3b8; font-size:15px; }
.filter-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.ftab { padding:7px 14px; border:1.5px solid #cbd5e1; border-radius:20px; background:white; color:#374151; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; transition:all 0.18s; }
.ftab:hover { border-color:#0077b6; color:#0077b6; }
.ftab.active { background:#0f2027; color:white; border-color:#0f2027; }
</style>
</head>
<body>
<nav class="navbar">
    <a href="admin_dashboard.php" class="navbar-brand"><h2>CATMIS</h2><span>CCS Portal</span></a>
    <div class="navbar-links">
        <a href="admin_dashboard.php">🏠 Dashboard</a>
        <a href="tuition_assessment.php">📂 Tuition</a>
        <a href="user_management.php">👥 Users</a>
        <a href="payment_history.php">📄 Payments</a>
        <a href="audit_logs.php">🕒 Audit Logs</a>
        <a href="financial_report.php">📊 Reports</a>
        <a href="backup.php">💾 Backup</a>
    </div>
    <div class="navbar-right">
        <a href="notifications.php" id="bellLink" style="position:relative;text-decoration:none;">
            <span style="font-size:20px;">🔔</span>
            <?php if ($unreadCount > 0): ?>
            <span style="position:absolute;top:-4px;right:-4px;background:#ff3b30;color:white;border-radius:50%;width:16px;height:16px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
    </div>
</nav>

<div class="main">
    <div class="notif-toolbar">
        <h2>🔔 Notifications <?php if ($unreadCount > 0): ?><span style="font-size:14px;color:#0077b6;font-weight:normal;">(<?= $unreadCount ?> unread)</span><?php endif; ?></h2>
        <?php if ($unreadCount > 0): ?>
        <button class="btn-mark-all" onclick="markAllRead()">✓ Mark all as read</button>
        <?php endif; ?>
    </div>

    <div class="filter-tabs">
        <button class="ftab active" onclick="filterType('all',this)">All</button>
        <button class="ftab" onclick="filterType('payment',this)">💰 Payments</button>
        <button class="ftab" onclick="filterType('edit_request',this)">✏️ Edit Requests</button>
        <button class="ftab" onclick="filterType('new_account',this)">👤 New Accounts</button>
        <button class="ftab" onclick="filterType('balance_due',this)">⚠️ Balance Alerts</button>
    </div>

    <?php if (empty($notifs)): ?>
    <div class="empty-box">🎉 You're all caught up! No notifications yet.</div>
    <?php else: ?>
    <div class="notif-list" id="notifList">
    <?php foreach ($notifs as $n):
        $icon  = $typeIcons[$n['type']] ?? '🔔';
        $bg    = $typeBg[$n['type']]    ?? '#f3e8ff';
        $color = $typeColor[$n['type']] ?? '#6b21a8';
        $unread = !$n['is_read'];
    ?>
    <div class="notif-card <?= $unread ? 'unread' : '' ?>" id="notif-<?= $n['notif_id'] ?>" data-type="<?= $n['type'] ?>">
        <div class="notif-icon" style="background:<?= $bg ?>;color:<?= $color ?>;"><?= $icon ?></div>
        <div class="notif-body">
            <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
            <div class="notif-time"><?= date('M d, Y · g:i A', strtotime($n['created_at'])) ?></div>
        </div>
        <div class="notif-actions">
            <?php if ($unread): ?>
            <button class="btn-read" onclick="markRead(<?= $n['notif_id'] ?>)">Mark read</button>
            <?php endif; ?>
            <button class="btn-del" onclick="deleteNotif(<?= $n['notif_id'] ?>)">Delete</button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function filterType(type, btn) {
    document.querySelectorAll('.ftab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.notif-card').forEach(card => {
        card.style.display = type === 'all' || card.dataset.type === type ? '' : 'none';
    });
}

function updateBellBadge(count) {
    // Update the bell badge count in the navbar
    let badge = document.getElementById('bellBadge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.id = 'bellBadge';
            badge.style.cssText = 'position:absolute;top:-6px;right:-6px;background:#ff3b30;color:white;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;';
            document.getElementById('bellLink')?.appendChild(badge);
        }
        badge.textContent = Math.min(count, 99);
    } else {
        badge?.remove();
        // Also hide the mark-all button
        document.querySelector('.btn-mark-all')?.remove();
    }
}

async function markRead(id) {
    const body = new FormData();
    body.append('action', 'mark_read');
    body.append('notif_id', id);
    const res  = await fetch('notifications.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
        const card = document.getElementById('notif-' + id);
        if (card) {
            card.classList.remove('unread');
            card.querySelector('.btn-read')?.remove();
        }
        updateBellBadge(data.unread ?? 0);
    }
}

async function markAllRead() {
    const body = new FormData();
    body.append('action', 'mark_all_read');
    const res  = await fetch('notifications.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
        document.querySelectorAll('.notif-card').forEach(c => {
            c.classList.remove('unread');
            c.querySelector('.btn-read')?.remove();
        });
        document.querySelector('.btn-mark-all')?.remove();
        updateBellBadge(0);
    }
}

async function deleteNotif(id) {
    const body = new FormData();
    body.append('action', 'delete');
    body.append('notif_id', id);
    const res  = await fetch('notifications.php', { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
        const card = document.getElementById('notif-' + id);
        if (card) { card.style.opacity = '0'; setTimeout(() => card.remove(), 300); }
        updateBellBadge(data.unread ?? 0);
    }
}

// Page is open = all notifications auto-marked read by server.
// Remove the red dot immediately on load.
document.addEventListener('DOMContentLoaded', () => updateBellBadge(0));
</script>
</body>
</html>
