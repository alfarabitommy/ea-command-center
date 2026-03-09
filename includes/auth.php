<?php
session_start();

// Jika session user_id tidak ada, tendang ke login.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
?>