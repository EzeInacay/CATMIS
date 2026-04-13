<?php
include 'config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];

    // Optional student fields
    $grade = $_POST['grade_level'] ?? null;
    $section = $_POST['section'] ?? null;
    $section_id = $_POST['section_id'] ?? null;

    // 🔥 START TRANSACTION (VERY IMPORTANT)
    $conn->begin_transaction();

    try {

        // =========================
        // 1. INSERT USER
        // =========================
        $stmt = $conn->prepare("INSERT INTO users (email, password, full_name, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $email, $password, $full_name, $role);
        $stmt->execute();

        $user_id = $conn->insert_id;

        // =========================
        // 2. IF STUDENT → CREATE STUDENT + ACCOUNT
        // =========================
        if ($role === "student") {

            // INSERT STUDENT
            $stmt = $conn->prepare("
                INSERT INTO students (user_id, section_id, grade_level, section) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("iiss", $user_id, $section_id, $grade, $section);
            $stmt->execute();

            $student_id = $conn->insert_id;

            // INSERT TUITION ACCOUNT
            $base_fee = 20000;
            $misc_fee = 5000;
            $balance = $base_fee + $misc_fee;

            $stmt = $conn->prepare("
                INSERT INTO tuition_accounts (student_id, base_fee, misc_fee, balance)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("iddd", $student_id, $base_fee, $misc_fee, $balance);
            $stmt->execute();
        }

        // ✅ COMMIT if all success
        $conn->commit();

        echo "Registration successful!";

    } catch (Exception $e) {
        // ❌ ROLLBACK if error
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
?>