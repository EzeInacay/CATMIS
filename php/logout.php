<?php
session_start();

// Log the logout before destroying the session
if (isset($_SESSION['user_id'])) {
    include __DIR__ . '/config.php';
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, 'Logged out')");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
}

// Wipe session data
$_SESSION = [];
session_unset();
session_destroy();

// Expire the session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

header('Location: ../login.php');
exit;
?>