<?php
// actions/export.php - Tải bài viết xuống dưới dạng file HTML offline
require_once '../config/db.php';

if (isset($_GET['id']) && isset($_GET['v'])) {
    $pageId = (int)$_GET['id'];
    $version = (int)$_GET['v'];

    // Lấy thông tin bài viết và nội dung của phiên bản được chọn
    $sql = "SELECT a.title, a.url, a.tags, a.category, s.content, s.fetched_at, a.notes 
            FROM articles a 
            JOIN snapshots s ON a.id = s.article_id 
            WHERE a.id = ? AND s.version = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $pageId, $version);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $title = htmlspecialchars($row['title']);
        $url = htmlspecialchars($row['url']);
        $tags = htmlspecialchars($row['tags'] ?: 'Không có nhãn');
        $fetched_at = $row['fetched_at'];
        $rawContent = $row['content'];

        // ==============================================================
        // NHÚNG ẢNH TRỰC TIẾP VÀO HTML (BASE64)
        // ==============================================================
        $content = preg_replace_callback('/src=["\'](assets\/[^"\']+)["\']/', function($matches) {
            $imgPath = '../' . $matches[1]; // Đường dẫn tới file ảnh trong localhost
            if (file_exists($imgPath)) {
                $mime = mime_content_type($imgPath);
                $b64Data = base64_encode(file_get_contents($imgPath));
                // Trả về ảnh đã mã hóa nhúng thẳng vào mã HTML
                return 'src="data:' . $mime . ';base64,' . $b64Data . '"';
            }
            return $matches[0]; // Nếu không tìm thấy file thì giữ nguyên đường dẫn cũ
        }, $rawContent);
        // ==============================================================
        
        // Tạo một giao diện HTML đơn giản cho file tải về
        $htmlExport = "
        <!DOCTYPE html>
        <html lang='vi'>
        <head>
            <meta charset='UTF-8'>
            <title>{$title} - Bản lưu Offline</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; max-width: 800px; margin: 0 auto; padding: 20px; color: #333; }
                .header { background: #f4f4f4; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
                .header h1 { margin-top: 0; font-size: 24px; }
                .meta { font-size: 14px; color: #555; }
                .content img { max-width: 100%; height: auto; display: block; margin: 10px auto; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>{$title}</h1>
                <div class='meta'>
                    <p><strong>Nguồn gốc:</strong> <a href='{$url}'>{$url}</a></p>
                    <p><strong>Thời gian lưu (v{$version}):</strong> {$fetched_at}</p>
                    <p><strong>Nhãn:</strong> {$tags}</p>
                </div>
            </div>
            <div class='content'>
                {$content}
            </div>
        </body>
        </html>
        ";

        // Thiết lập header để ép trình duyệt tải file xuống
        $filename = "pocket_export_id{$pageId}_v{$version}.html";
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo $htmlExport;
        exit();
    } else {
        die("Không tìm thấy dữ liệu bài viết hoặc phiên bản này.");
    }
} else {
    die("Thiếu tham số ID hoặc Version.");
}
?>