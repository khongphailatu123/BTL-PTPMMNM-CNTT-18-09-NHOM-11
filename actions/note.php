<?php
// actions/note.php
require_once '../config/db.php';

// 1. Bật session để lấy được tên người dùng đang đăng nhập
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $pageId = (int)$_POST['page_id'];
    $noteContent = trim($_POST['note']);

    // 2. Lấy tên username từ Session (nếu không có thì để mặc định là 'Người dùng')
    $currentUsername = $_SESSION['username'] ?? 'Người dùng';

    if (!empty($noteContent)) {
        $stmtSelect = $conn->prepare("SELECT notes FROM articles WHERE id = ?");
        $stmtSelect->bind_param("i", $pageId);
        $stmtSelect->execute();
        $res = $stmtSelect->get_result();

        if ($row = $res->fetch_assoc()) {
            $existingNotes = $row['notes'] ? json_decode($row['notes'], true) : [];
            
            // 3. Thay chữ "Quân: " cứng bằng tên tài khoản động, in đậm tên cho dễ nhìn và chống XSS
            $formattedNote = "<strong>" . htmlspecialchars($currentUsername) . ":</strong> " . htmlspecialchars($noteContent);
            $existingNotes[] = $formattedNote;
            
            $updatedNotesJson = json_encode($existingNotes, JSON_UNESCAPED_UNICODE);

            $stmt = $conn->prepare("UPDATE articles SET notes = ? WHERE id = ?");
            $stmt->bind_param("si", $updatedNotesJson, $pageId);
            $stmt->execute();
        }
    }
}

header("Location: ../index.php");
exit();
?>