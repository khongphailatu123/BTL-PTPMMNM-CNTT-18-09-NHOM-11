<?php
// share.php - Trang xem bài viết công khai dành cho người được chia sẻ
require_once 'config/db.php';

$article_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$version = isset($_GET['v']) ? (int)$_GET['v'] : 1;

if ($article_id <= 0) {
    die("Liên kết chia sẻ không hợp lệ!");
}

// Truy vấn bài viết và phiên bản tương ứng
$stmt = $conn->prepare("SELECT a.title, a.url, a.created_at, s.content 
                        FROM articles a 
                        LEFT JOIN snapshots s ON a.id = s.article_id AND s.version = ? 
                        WHERE a.id = ?");
$stmt->bind_param("ii", $version, $article_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die("Bài viết không tồn tại hoặc đã bị xóa!");
}

$article = $res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($article['title']); ?> - Chia sẻ từ Pocket Saver</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background-color: #0b132b; color: #e2e8f0; min-height: 100vh; padding-bottom: 50px; }
        .share-container { max-width: 900px; margin: 30px auto; padding: 0 20px; }
        .share-header { background: #1c2541; border: 1px solid #334155; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
        .share-body { background: #1c2541; border: 1px solid #334155; border-radius: 16px; padding: 30px; }
        
        /* Ép hiển thị mượt mà cho cả Light Novel lẫn Truyện tranh */
        #contentArea p, #contentArea span, #contentArea div {
            color: #e2e8f0 !important;
            font-size: 1.05rem !important;
            line-height: 1.8 !important;
            background: transparent !important;
        }
        #contentArea img {
            max-width: 100% !important;
            height: auto !important;
            display: block !important;
            margin: 15px auto !important;
            border-radius: 8px;
        }
    </style>
</head>
<body>

<div class="share-container">
    <div class="share-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="text-danger fw-bold fs-7 mb-1"><i class="fa-solid fa-bookmark me-1"></i> POCKET SAVER - BẢN CHIA SẺ</div>
            <h4 class="fw-bold m-0 text-white"><?php echo htmlspecialchars($article['title']); ?></h4>
            <small class="text-muted">Nguồn gốc: <a href="<?php echo htmlspecialchars($article['url']); ?>" target="_blank" class="text-info"><?php echo htmlspecialchars($article['url']); ?></a></small>
        </div>
        <a href="index.php" class="btn btn-outline-light btn-sm fw-semibold"><i class="fa-solid fa-house me-1"></i> Về trang chủ</a>
    </div>

    <div class="share-body">
        <div id="contentArea">
            <?php echo $article['content']; ?>
        </div>
    </div>
</div>

</body>
</html>