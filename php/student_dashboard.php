<?php
include 'config.php';
session_start();

$user_id = $_SESSION['user_id'];

$result = $conn->query("
SELECT s.student_id, ta.account_id
FROM students s
JOIN tuition_accounts ta ON s.student_id = ta.student_id
WHERE s.user_id = $user_id
");

$data = $result->fetch_assoc();

echo "Student ID: " . $data['student_id'];
echo "<br>Account ID: " . $data['account_id'];
?>

<?php
include 'config.php';
include 'get_balance.php';

$account_id = 1; // replace with actual

$balance = getBalance($conn, $account_id);

echo "Current Balance: ₱" . number_format($balance, 2);
?>