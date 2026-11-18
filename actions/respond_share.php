<?php
// actions/respond_share.php - Xử lý Chấp nhận hoặc Từ chối lời mời chia sẻ
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $current_user_id = (int)$_SESSION['user_id'];
    $share_id = (int)($_POST['share_id'] ?? 0);
    $action_type = $_POST['action_type'] ?? '';

    if ($share_id > 0) {
        if ($action_type === 'accept') {
            $stmt = $conn->prepare("UPDATE article_shares SET status = 'accepted' WHERE id = ? AND receiver_id = ?");
            $stmt->bind_param("ii", $share_id, $current_user_id);
            $stmt->execute();
        } elseif ($action_type === 'reject') {
            $stmt = $conn->prepare("DELETE FROM article_shares WHERE id = ? AND receiver_id = ?");
            $stmt->bind_param("ii", $share_id, $current_user_id);
            $stmt->execute();
        }
    }
}

header("Location: ../index.php");
exit();
?>