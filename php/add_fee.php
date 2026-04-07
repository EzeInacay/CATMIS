<?php
include 'config.php';

$account_id = $_POST['account_id'];
$amount = $_POST['amount'];

$conn->query("
INSERT INTO student_ledgers 
(account_id, entry_type, amount, remarks, posted_by)
VALUES ($account_id, 'CHARGE', $amount, 'Additional Fee', 1)
");
?>