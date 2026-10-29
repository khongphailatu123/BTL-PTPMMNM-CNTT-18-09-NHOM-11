<?php
session_start();
// config/db.php - File kết nối MySQL
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "pocket_db2";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Kết nối CSDL thất bại: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ==========================================
// DANH SÁCH MIỀN ĐƯỢC DUYỆT
// ==========================================
$enable_whitelist = False; // Tắt chặn Whitelist để cào thoải mái

$allowed_domains = [
    'localhost',
    'vnexpress.net',
    'viblo.asia',
    'truyenqqko.com',
    'dantri.com.vn',
];
?>