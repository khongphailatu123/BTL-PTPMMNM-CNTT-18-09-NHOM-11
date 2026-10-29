<?php
// actions/note.php
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $pageId = (int)$_POST['page_id'];
    $noteContent = trim($_POST['note']);

    if (!empty($noteContent)) {
        $stmtSelect = $conn->prepare("SELECT notes FROM articles WHERE id = ?");
        $stmtSelect->bind_param("i", $pageId);
        $stmtSelect->execute();
        $res = $stmtSelect->get_result();

        if ($row = $res->fetch_assoc()) {
            $existingNotes = $row['notes'] ? json_decode($row['notes'], true) : [];
            $existingNotes[] = "Quân: " . $noteContent;
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