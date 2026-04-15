<?php
/**
 * get_balance.php
 *
 * Returns the real-time balance for a tuition account by summing
 * all ledger entries. Include this file then call getBalance().
 *
 * Formula:
 *   Balance = (CHARGE + PENALTY + ADJUSTMENT+) - (PAYMENT + DISCOUNT + ADJUSTMENT-)
 *
 * ADJUSTMENT entries are signed — positive adjustments add to the
 * balance, negative adjustments reduce it. All other types are
 * stored as positive amounts and their direction is determined by
 * entry_type.
 *
 * Returns 0 if no ledger entries exist (never negative).
 */

function getBalance($conn, $account_id) {
    $account_id = intval($account_id);

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN entry_type = 'CHARGE'     THEN amount ELSE 0 END), 0) AS total_charges,
            COALESCE(SUM(CASE WHEN entry_type = 'PENALTY'    THEN amount ELSE 0 END), 0) AS total_penalties,
            COALESCE(SUM(CASE WHEN entry_type = 'PAYMENT'    THEN amount ELSE 0 END), 0) AS total_payments,
            COALESCE(SUM(CASE WHEN entry_type = 'DISCOUNT'   THEN amount ELSE 0 END), 0) AS total_discounts,
            COALESCE(SUM(CASE WHEN entry_type = 'ADJUSTMENT' THEN amount ELSE 0 END), 0) AS total_adjustments
        FROM student_ledgers
        WHERE account_id = ?
    ");
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $balance =
        ($row['total_charges'] + $row['total_penalties'])
        - ($row['total_payments'] + $row['total_discounts'])
        + $row['total_adjustments'];  // adjustments are signed, can be + or -

    return max(0, round($balance, 2));
}
?>