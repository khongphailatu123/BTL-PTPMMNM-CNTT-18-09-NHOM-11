<?php
// actions/share_internal.php - Gửi yêu cầu chia sẻ bài viết (Trạng thái Pending)
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $sender_id = $_SESSION['user_id'];
    $article_id = (int)($_POST['article_id'] ?? 0);
    $receiver_username = trim($_POST['receiver_username'] ?? '');

    if ($article_id > 0 && !empty($receiver_username)) {
        $stmtUser = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmtUser->bind_param("s", $receiver_username);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();

        if ($resUser->num_rows > 0) {
            $receiver = $resUser->fetch_assoc();
            $receiver_id = $receiver['id'];

            if ($receiver_id === $sender_id) {
                $_SESSION['share_msg'] = "error_self";
            } else {
                $stmtCheck = $conn->prepare("SELECT id FROM article_shares WHERE article_id = ? AND receiver_id = ?");
                $stmtCheck->bind_param("ii", $article_id, $receiver_id);
                $stmtCheck->execute();

                if ($stmtCheck->get_result()->num_rows === 0) {
                    $stmtShare = $conn->prepare("INSERT INTO article_shares (article_id, sender_id, receiver_id, status) VALUES (?, ?, ?, 'pending')");
                    $stmtShare->bind_param("iii", $article_id, $sender_id, $receiver_id);
                    $stmtShare->execute();
                }
                $_SESSION['share_msg'] = "success";
            }
        } else {
            $_SESSION['share_msg'] = "error_not_found";
        }
    }
}

header("Location: ../index.php");
exit();
?>