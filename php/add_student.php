<?php
include 'config.php';

$name = $_POST['name'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

// 1. create user
$conn->query("
INSERT INTO users (email, password, full_name, role)
VALUES ('$email', '$password', '$name', 'student')
");

$user_id = $conn->insert_id;

// 2. create student
$conn->query("
INSERT INTO students (user_id)
VALUES ($user_id)
");

$student_id = $conn->insert_id;

// 3. create tuition account
$conn->query("
INSERT INTO tuition_accounts (student_id)
VALUES ($student_id)
");

echo "Student created!";
?>