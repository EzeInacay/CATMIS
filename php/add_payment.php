<?php
include 'config.php';

$account_id = $_POST['account_id'];
$student_id = $_POST['student_id'];
$amount = $_POST['amount'];

$conn->query("
INSERT INTO payments 
(account_id, student_id, amount, method, or_number, posted_by)
VALUES ($account_id, $student_id, $amount, 'Cash', 'OR123', 1)
");

$conn->query("
INSERT INTO student_ledgers 
(account_id, entry_type, amount, remarks, posted_by)
VALUES ($account_id, 'PAYMENT', $amount, 'Payment', 1)
");
?>