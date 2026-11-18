<?php
// actions/share_internal.php - Gửi yêu cầu chia sẻ bài viết qua ID tài khoản hoặc Username
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $sender_id = (int)$_SESSION['user_id'];
    $article_id = (int)($_POST['article_id'] ?? 0);
    $receiver_input = trim($_POST['receiver_username'] ?? '');

    if ($article_id > 0 && $receiver_input !== '') {
        // Loại bỏ dấu # nếu người dùng nhập dạng #100002
        $cleanInput = ltrim($receiver_input, '#');
        $receiver_id = 0;

        if (ctype_digit($cleanInput)) {
            $numId = (int)$cleanInput;
            // Nếu nhập mã 6 chữ số (100001 -> id = 1) thì quy đổi về id thật
            $realId = ($numId > 100000) ? ($numId - 100000) : $numId;

            $stmtUser = $conn->prepare("SELECT id FROM users WHERE id = ? OR username = ? LIMIT 1");
            $stmtUser->bind_param("is", $realId, $cleanInput);
        } else {
            $stmtUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmtUser->bind_param("s", $receiver_input);
        }

        $stmtUser->execute();
        $resUser = $stmtUser->get_result();

        if ($resUser && $resUser->num_rows > 0) {
            $receiver = $resUser->fetch_assoc();
            $receiver_id = (int)$receiver['id'];

            if ($receiver_id === $sender_id) {
                $_SESSION['share_msg'] = "error_self";
            } else {
                $stmtCheck = $conn->prepare("SELECT id, status FROM article_shares WHERE article_id = ? AND receiver_id = ? LIMIT 1");
                $stmtCheck->bind_param("ii", $article_id, $receiver_id);
                $stmtCheck->execute();
                $resCheck = $stmtCheck->get_result();

                if ($resCheck->num_rows === 0) {
                    $stmtShare = $conn->prepare("INSERT INTO article_shares (article_id, sender_id, receiver_id, status) VALUES (?, ?, ?, 'pending')");
                    $stmtShare->bind_param("iii", $article_id, $sender_id, $receiver_id);
                    $stmtShare->execute();
                    $stmtShare->close();
                } else {
                    // Nếu trước đó từng bị từ chối hoặc thu hồi, đặt lại thành pending để hiện thông báo lại
                    $existing = $resCheck->fetch_assoc();
                    if ($existing['status'] !== 'accepted') {
                        $stmtRe = $conn->prepare("UPDATE article_shares SET status = 'pending', sender_id = ? WHERE id = ?");
                        $stmtRe->bind_param("ii", $sender_id, $existing['id']);
                        $stmtRe->execute();
                        $stmtRe->close();
                    }
                }
                $stmtCheck->close();
                $_SESSION['share_msg'] = "success";
            }
        } else {
            $_SESSION['share_msg'] = "error_not_found";
        }
        $stmtUser->close();
    }
}

header("Location: ../index.php");
exit();
?>