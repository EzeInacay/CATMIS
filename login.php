<?php
session_start();
include 'php/config.php';

$mode  = isset($_GET['role']) && $_GET['role'] === 'admin' ? 'admin' : 'user';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = trim($_POST['password']   ?? '');
    $loginMode  = $_POST['mode'] ?? 'user';

    if ($loginMode === 'admin') {
        // Admin logs in with their admin ID (student_number field or email)
        $stmt = $conn->prepare("
            SELECT user_id, full_name, password, role
            FROM users
            WHERE (email = ? OR student_number = ?) AND role = 'admin' AND status = 'active'
        ");
        $stmt->bind_param('ss', $identifier, $identifier);
    } else {
        // Teacher logs in with email; student logs in with student_number or email
        $stmt = $conn->prepare("
            SELECT user_id, full_name, password, role
            FROM users
            WHERE (email = ? OR student_number = ?) AND role IN ('teacher','student') AND status = 'active'
        ");
        $stmt->bind_param('ss', $identifier, $identifier);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    if ($user) {
        // Support both bcrypt hashed passwords and plain-text (for dev/testing)
        $valid = password_verify($password, $user['password']) || $password === $user['password'];

        if ($valid) {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // Log the login action
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, 'Logged in')");
            $log->bind_param('i', $user['user_id']);
            $log->execute();

            // Redirect by role
            if ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($user['role'] === 'teacher') {
                header('Location: teacher_dashboard.php');
            } else {
                header('Location: student_dashboard.php');
            }
            exit;
        }
    }

    $error = 'Invalid credentials. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link href="css/login.css" rel="stylesheet" />
</head>
<body>

<!-- ── LEFT PANEL ── -->
<div class="left-panel">
    <a href="index.php" class="back-link">← Back</a>
    <div class="left-content">
        <span class="tag">CCS Portal</span>
        <h1>CATMIS</h1>
        <p>Centralized Assessment and Tuition Management Information System</p>
    </div>
</div>

<!-- ── RIGHT PANEL ── -->
<div class="right-panel">
<div class="login-card">

    <?php if ($mode === 'admin'): ?>
        <div class="mode-badge admin">🛡️ &nbsp;Administrator</div>
        <h2>Admin Login</h2>
        <p class="subtitle">Sign in with your admin email or ID.</p>
    <?php else: ?>
        <div class="mode-badge user">🎓 &nbsp;Teacher / Student</div>
        <h2>Welcome back</h2>
        <p class="subtitle">Teachers use email &nbsp;·&nbsp; Students use their Student ID.</p>
    <?php endif; ?>

    <form method="POST" action="login.php?role=<?= htmlspecialchars($mode) ?>">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">

        <div class="field">
            <label>
                <?= $mode === 'admin' ? 'Admin Email or ID' : 'Email or Student ID' ?>
            </label>
            <input
                type="text"
                name="identifier"
                placeholder="<?= $mode === 'admin' ? 'admin@school.com' : 'email or 2025-00001' ?>"
                required
                autocomplete="username"
                value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label>Password</label>
            <input
                type="password"
                name="password"
                placeholder="••••••••"
                required
                autocomplete="current-password"
            >
        </div>

        <button type="submit" class="login-btn <?= $mode === 'admin' ? 'admin-btn' : 'user-btn' ?>">
            Sign In
        </button>
    </form>

    <?php if ($error): ?>
        <div class="error-msg">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Switch mode link -->
    <div class="switch-link">
        <?php if ($mode === 'admin'): ?>
            Not an admin? <a href="login.php">Teacher / Student Login →</a>
        <?php else: ?>
            Are you an admin? <a href="login.php?role=admin">Admin Login →</a>
        <?php endif; ?>
    </div>

</div>
</div>

</body>
</html>