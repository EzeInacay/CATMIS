<?php
include 'config.php';

function getBalance($conn, $account_id) {

    $result = $conn->query("
        SELECT 
        COALESCE(SUM(CASE WHEN entry_type='CHARGE' THEN amount END),0) -
        COALESCE(SUM(CASE WHEN entry_type='PAYMENT' THEN amount END),0)
        AS balance
        FROM student_ledgers
        WHERE account_id = $account_id
    ");

    $row = $result->fetch_assoc();
    return $row['balance'];
}
?>