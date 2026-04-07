<?php
$conn = new mysqli("localhost", "root", "", "catmis");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>