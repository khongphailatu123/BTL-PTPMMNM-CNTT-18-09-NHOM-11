<?php
// actions/delete.php - Xử lý xóa bài viết chính chủ hoặc gỡ bài viết được chia sẻ
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$current_user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_page') {
    $page_id = isset($_POST['page_id']) ? (int)$_POST['page_id'] : 0;

    if ($page_id > 0) {
        // Kiểm tra xem bài viết là do chính chủ cào hay do người khác chia sẻ
        $stmtCheck = $conn->prepare("SELECT id, user_id FROM articles WHERE id = ?");
        $stmtCheck->bind_param("i", $page_id);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck->num_rows > 0) {
            $article = $resCheck->fetch_assoc();
            $owner_id = (int)$article['user_id'];

            if ($owner_id === $current_user_id) {
                // TRƯỜNG HỢP 1: Chính chủ xóa bài
                // 1. Dọn dẹp thư mục chứa ảnh local trên server
                $articleFolders = glob('../assets/item_' . $page_id . '*');
                if (!empty($articleFolders)) {
                    foreach ($articleFolders as $folder) {
                        if (is_dir($folder)) {
                            $files = glob($folder . '/*');
                            foreach ($files as $file) {
                                if (is_file($file)) @unlink($file);
                            }
                            @rmdir($folder);
                        }
                    }
                }

                // 2. Xóa các bản ghi liên quan trong CSDL
                $stmtDelShares = $conn->prepare("DELETE FROM article_shares WHERE article_id = ?");
                $stmtDelShares->bind_param("i", $page_id);
                $stmtDelShares->execute();

                $stmtDelSnaps = $conn->prepare("DELETE FROM snapshots WHERE article_id = ?");
                $stmtDelSnaps->bind_param("i", $page_id);
                $stmtDelSnaps->execute();

                $stmtDelArticle = $conn->prepare("DELETE FROM articles WHERE id = ? AND user_id = ?");
                $stmtDelArticle->bind_param("ii", $page_id, $current_user_id);
                $stmtDelArticle->execute();

            } else {
                // TRƯỜNG HỢP 2: Người nhận gỡ bài viết được chia sẻ khỏi danh sách của mình
                $stmtUnshare = $conn->prepare("DELETE FROM article_shares WHERE article_id = ? AND receiver_id = ?");
                $stmtUnshare->bind_param("ii", $page_id, $current_user_id);
                $stmtUnshare->execute();
            }
        }
    }
}

header("Location: ../index.php");
exit();
?>