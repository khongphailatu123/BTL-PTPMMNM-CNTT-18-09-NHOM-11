<?php
// actions/edit.php - Cập nhật Tiêu đề, URL nguồn, Collection và Tags
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_page') {
    $page_id = (int)($_POST['page_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $collection_name = trim($_POST['collection_name'] ?? 'Chung');
    $tags = trim($_POST['tags'] ?? '');

    if ($page_id > 0 && !empty($title) && !empty($url)) {
        $stmt = $conn->prepare("UPDATE articles SET title = ?, url = ?, collection_name = ?, tags = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $url, $collection_name, $tags, $page_id);
        $stmt->execute();
    }
}

header("Location: ../index.php");
exit();
?>