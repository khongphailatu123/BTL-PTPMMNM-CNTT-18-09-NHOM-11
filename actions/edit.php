<?php
// actions/edit.php - Cập nhật thông tin bài viết & Thay ảnh bìa tùy chỉnh
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_page') {
    $user_id = (int)$_SESSION['user_id'];
    $page_id = (int)($_POST['page_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $collection_name = trim($_POST['collection_name'] ?? 'Chung');
    if ($collection_name === '')$collection_name = 'Chung';
    $tags = trim($_POST['tags'] ?? '');

    if ($page_id > 0 && $title !== '' &&$url !== '') {
        // Kiểm tra quyền sở hữu bài viết
        $checkStmt =$conn->prepare("SELECT id FROM articles WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $page_id, $user_id);$checkStmt->execute();
        $checkRes =$checkStmt->get_result();

        if ($checkRes &&$checkRes->num_rows > 0) {
            // Cập nhật thông tin cơ bản
            $stmt =$conn->prepare("UPDATE articles SET title = ?, url = ?, collection_name = ?, tags = ? WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ssssii", $title,$url, $collection_name,$tags, $page_id,$user_id);
            $stmt->execute();$stmt->close();

            // Xử lý upload ảnh bìa mới nếu người dùng có chọn file
            if (isset($_FILES['custom_cover']) &&$_FILES['custom_cover']['error'] === UPLOAD_ERR_OK) {
                $tmpName =$_FILES['custom_cover']['tmp_name'];
                $imgInfo = @getimagesize($tmpName);

                if ($imgInfo !== false) {$allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                        'image/gif'  => 'gif'
                    ];
                    $mime =$imgInfo['mime'] ?? '';

                    if (isset($allowedMimes[$mime])) {
                        $ext =$allowedMimes[$mime];$coverDir = '../assets/covers';
                        if (!is_dir($coverDir)) {
                            mkdir($coverDir, 0777, true);
                        }

                        // Xóa ảnh bìa tùy chỉnh cũ của bài viết này (nếu có)
                        $oldCovers = glob($coverDir . '/cover_' .$page_id . '.*');
                        if (!empty($oldCovers)) {
                            foreach ($oldCovers as$oldFile) {
                                @unlink($oldFile);
                            }
                        }

                        $destPath =$coverDir . '/cover_' . $page_id . '.' .$ext;
                        move_uploaded_file($tmpName,$destPath);
                    }
                }
            }
        }
        $checkStmt->close();
    }
}

header("Location: ../index.php");
exit();