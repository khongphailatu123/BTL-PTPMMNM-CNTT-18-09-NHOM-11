<?php
// index.php - Giao diện chính Pocket Saver v2 (Poster Card Full-viền ảnh, Đổi ảnh bìa, Trang Lọc riêng, Phân trang 3 chấm & Liên kết Library)
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_username = $_SESSION['username'];
$current_role = $_SESSION['role'] ?? 'user';

// 1. Lấy danh sách thông báo lời mời chia sẻ ĐANG CHỜ DUYỆT (pending)
$sqlPending = "SELECT sh.id as share_id, a.title, u.username as sender_name, sh.shared_at 
               FROM article_shares sh 
               JOIN articles a ON sh.article_id = a.id 
               JOIN users u ON sh.sender_id = u.id 
               WHERE sh.receiver_id = $current_user_id AND sh.status = 'pending'
               ORDER BY sh.id DESC";
$resPending = $conn->query($sqlPending);
$pendingShares = [];
if ($resPending && $resPending->num_rows > 0) {
    while ($pRow = $resPending->fetch_assoc()) {
        $pendingShares[] = $pRow;
    }
}
$pendingCount = count($pendingShares);

// 2. Truy vấn bài viết thuộc sở hữu HOẶC ĐÃ ĐƯỢC CHẤP NHẬN CHIA SẺ (accepted)
$sql = "SELECT DISTINCT a.*, s.id as snapshot_id, s.version, s.content, s.fetched_at, u.username as shared_by
        FROM articles a 
        LEFT JOIN snapshots s ON a.id = s.article_id 
        LEFT JOIN article_shares sh ON a.id = sh.article_id AND sh.status = 'accepted'
        LEFT JOIN users u ON sh.sender_id = u.id
        WHERE a.user_id = $current_user_id OR (sh.receiver_id = $current_user_id AND sh.status = 'accepted')
        ORDER BY a.id DESC, s.version ASC";

$result = $conn->query($sql);
$db_articles_temp = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $articleId = (int)$row['id'];
        
        if (!isset($db_articles_temp[$articleId])) {
            $tagsArray = !empty($row['tags']) ? array_map('trim', explode(',', $row['tags'])) : ['Chưa phân loại'];
            $notesArray = !empty($row['notes']) ? json_decode($row['notes'], true) : [];

            $thumbImage = '';
            // Ưu tiên ảnh bìa tùy chỉnh người dùng đã tải lên trong assets/covers/
            $customCovers = glob('assets/covers/cover_' . $articleId . '.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
            if (!empty($customCovers) && filesize($customCovers[0]) > 200) {
                $thumbImage = $customCovers[0] . '?v=' . filemtime($customCovers[0]);
            } elseif ($row['status'] == 404) {
                $thumbImage = '404';
            } else {
                $articleFolders = glob('assets/item_' . $articleId . '*');

                if (empty($articleFolders) && !empty($row['content'])) {
                    preg_match_all('/assets\/(item_[^\/"]+)\//i', $row['content'], $matches);
                    if (!empty($matches[1])) {
                        $targetFolder = 'assets/' . $matches[1][0];
                        if (is_dir($targetFolder)) {
                            $articleFolders = [$targetFolder];
                        }
                    }
                }

                if (!empty($articleFolders)) {
                    foreach ($articleFolders as $folder) {
                        $foundImages = glob($folder . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
                        if (!empty($foundImages)) {
                            foreach ($foundImages as $img) {
                                if (filesize($img) > 500) { 
                                    if (@getimagesize($img)) {
                                        $thumbImage = $img;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $db_articles_temp[$articleId] = [
                'id' => $articleId,
                'title' => $row['title'],
                'url' => $row['url'],
                'image' => $thumbImage,
                'tags' => $tagsArray,
                'category' => $row['category'],
                'collection_name' => !empty($row['collection_name']) ? $row['collection_name'] : 'Chung',
                'status' => (int)$row['status'],
                'created_at' => $row['created_at'],
                'shared_by' => $row['shared_by'] ?? null,
                'currentVersion' => 1,
                'snapshots' => []
            ];
        }

        if ($row['snapshot_id']) {
            $ver = (int)$row['version'];
            $db_articles_temp[$articleId]['snapshots'][$ver] = [
                'fetched_at' => $row['fetched_at'],
                'content' => $row['content'],
                'notes' => json_decode($row['notes'] ?? '[]', true) ?: []
            ];
            
            if ($ver > $db_articles_temp[$articleId]['currentVersion']) {
                $db_articles_temp[$articleId]['currentVersion'] = $ver;
            }
        }
    }
}

$db_articles = array_values($db_articles_temp);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    
    <title>Pocket Saver - Đề tài 16</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        .folder-card {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 185px;
            box-shadow: 0 4px 15px -2px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
        }

        .folder-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 4px;
            background: linear-gradient(90deg, #0d9488, #14b8a6);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .folder-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 30px -10px rgba(13, 148, 136, 0.15);
            border-color: #99f6e4;
        }

        .folder-card:hover::before { opacity: 1; }

        .folder-icon-box {
            width: 50px; height: 50px;
            background: #fef3c7;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: #d97706;
            transition: all 0.3s ease;
        }

        .folder-card:hover .folder-icon-box {
            background: #0d9488; color: #ffffff;
            transform: scale(1.08) rotate(-4deg);
        }

        .folder-count-badge {
            background: #0f172a; color: #ffffff;
            font-weight: 600; font-size: 0.75rem;
            padding: 5px 12px; border-radius: 20px;
            transition: all 0.2s ease;
        }

        .folder-card:hover .folder-count-badge { background: #0d9488; }

        .folder-add-card {
            border: 2px dashed #cbd5e1;
            border-radius: 20px;
            padding: 24px;
            cursor: pointer;
            height: 185px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #64748b;
            background: rgba(248, 250, 252, 0.45);
            transition: all 0.25s ease;
        }
        .folder-add-card:hover {
            border-color: #0d9488;
            color: #0d9488;
            transform: translateY(-4px);
            background: rgba(13, 148, 136, 0.06);
        }

        * { font-family: 'Inter', sans-serif; }
        body { background-color: #ffffff; color: #1a1a1a; font-size: 0.93rem; }

        /* Làm mờ chữ gợi ý (placeholder) bên trong các ô nhập liệu */
        .form-control::placeholder,
        textarea.form-control::placeholder {
            color: #9ca3af !important;
            opacity: 0.42 !important;
        }

        .pocket-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; height: 60px; padding: 0 28px; }
        .pocket-logo { color: #ef4056; font-weight: 700; font-size: 1.3rem; text-decoration: none; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .top-nav-link { color: #4b5563; font-weight: 600; text-decoration: none; padding: 18px 12px; border-bottom: 3px solid transparent; cursor: pointer; white-space: nowrap; flex-shrink: 0; }
        .top-nav-link.active { color: #000000; border-bottom-color: #008080; }

        .header-icon-btn { background: none; border: none; color: #374151; font-size: 1.1rem; padding: 8px; border-radius: 50%; position: relative; flex-shrink: 0; }
        .header-icon-btn:hover { background: #f3f4f6; }
        .header-icon-btn.active-btn { background: #e0f2fe; color: #0284c7 !important; }

        .pocket-sidebar { width: 230px; flex-shrink: 0; padding: 12px 0 40px 0; }
        .side-link { display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-radius: 8px; color: #374151; font-weight: 500; text-decoration: none; margin-bottom: 2px; cursor: pointer; }
        .side-link:hover, .side-link.active { background: #e6f4f1; color: #008080; font-weight: 600; }

        .sidebar-section-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.15s ease, color 0.15s ease;
        }
        .sidebar-section-toggle:hover {
            background-color: #f3f4f6;
            color: #111827;
        }
        .sidebar-section-toggle .chevron-icon {
            transition: transform 0.2s ease;
            font-size: 0.7rem;
        }
        .sidebar-section-toggle.collapsed .chevron-icon {
            transform: rotate(-90deg);
        }

        /* Dropdown gợi ý Library liên kết trực tiếp trong modal New & Edit */
        .library-combo-wrapper {
            position: relative;
        }
        .library-dropdown-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 1065;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.18);
            max-height: 190px;
            overflow-y: auto;
            display: none;
        }
        .library-dropdown-item {
            padding: 8px 12px;
            font-size: 0.88rem;
            color: #1e293b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
        }
        .library-dropdown-item:last-child {
            border-bottom: none;
        }
        .library-dropdown-item:hover {
            background: #e6f4f1;
            color: #008080;
            font-weight: 600;
        }

        /* POSTER CARD FULL-VIỀN ẢNH */
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 22px; }
        .pocket-card {
            background: #0f172a;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
            height: 310px;
            position: relative;
            contain: content;
        }
        .pocket-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 24px -6px rgba(0, 0, 0, 0.22);
        }
        .poster-full-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.35s ease;
        }
        .pocket-card:hover .poster-full-img {
            transform: scale(1.04);
        }
        .poster-title-overlay {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 44px 14px 14px 14px;
            background: linear-gradient(to top, rgba(2, 6, 23, 0.96) 0%, rgba(2, 6, 23, 0.78) 55%, rgba(2, 6, 23, 0) 100%);
            color: #ffffff;
            z-index: 2;
            pointer-events: none;
        }
        .poster-title-text {
            font-weight: 700;
            font-size: 0.96rem;
            line-height: 1.38;
            color: #ffffff;
            text-shadow: 0 1px 4px rgba(0,0,0,0.65);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin: 0;
        }
        .poster-snippet-box {
            margin-top: 6px;
            padding: 4px 8px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 0.75rem;
            color: #f8fafc;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .card-action-btns {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
            display: flex;
            gap: 6px;
            opacity: 0.92;
        }
        .card-action-btns .btn {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 8px;
            padding: 3px 8px;
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .card-action-btns .btn:hover {
            background: #ffffff;
            transform: scale(1.06);
        }

        /* Ô nhập số trang khi bấm vào dấu ... trên thanh phân trang */
        .page-ellipsis-input {
            width: 58px;
            height: 100%;
            border: 1px solid #008080;
            text-align: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            padding: 6px 4px;
            -moz-appearance: textfield;
        }
        .page-ellipsis-input::-webkit-outer-spin-button,
        .page-ellipsis-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .tag-badge { background-color: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; display: inline-flex; align-items: center; gap: 4px; }

        #searchBox {
            will-change: height;
            transform: translateZ(0);
        }
        
        #modalBody {
            width: 100% !important;
            overflow-x: hidden !important;
            background-color: #0b132b !important;
            color: #e2e8f0 !important;
            padding: 24px !important;
            border-radius: 12px;
        }

        #modalBody p, 
        #modalBody span, 
        #modalBody div, 
        #modalBody font,
        #modalBody h1, #modalBody h2, #modalBody h3, #modalBody h4 {
            color: #e2e8f0 !important;
            font-size: 1.05rem !important;
            line-height: 1.8 !important;
            background: transparent !important;
        }

        #modalBody *, 
        #modalBody section,
        #modalBody .chapter-content,
        #modalBody .reading-detail,
        #modalBody .reading-loc-content {
            max-width: 100% !important;
            width: 100% !important;
            box-sizing: border-box !important;
            margin-left: auto !important;
            margin-right: auto !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            background: transparent !important;
        }

        #modalBody img {
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
            width: 100% !important;
            max-width: 800px !important;
            height: auto !important;
            margin: 0 auto 10px auto !important;
            object-fit: contain !important;
        }

        #modalBody a, 
        #modalBody button, 
        #modalBody select, 
        #modalBody .breadcrumb, 
        #modalBody .chapter-nav,
        #modalBody .server-control {
            display: none !important;
        }
    </style>
</head>
<body>

    <!-- THÔNG BÁO CHIA SẺ NỘI BỘ -->
    <?php if (isset($_SESSION['share_msg'])): ?>
        <script>
            <?php if ($_SESSION['share_msg'] === 'success'): ?>
                Swal.fire({ icon: 'success', title: 'Thành công!', text: 'Đã gửi lời mời chia sẻ. Đang chờ người nhận chấp nhận!', timer: 2500, showConfirmButton: false, customClass: { popup: 'rounded-4' } });
            <?php elseif ($_SESSION['share_msg'] === 'error_self'): ?>
                Swal.fire({ icon: 'warning', title: 'Không thể thực hiện', text: 'Bạn không thể tự chia sẻ bài viết cho chính mình!', confirmButtonColor: '#008080', customClass: { popup: 'rounded-4' } });
            <?php elseif ($_SESSION['share_msg'] === 'error_not_found'): ?>
                Swal.fire({ icon: 'error', title: 'Lỗi', text: 'Tài khoản người nhận không tồn tại!', confirmButtonColor: '#dc3545', customClass: { popup: 'rounded-4' } });
            <?php endif; ?>
        </script>
        <?php unset($_SESSION['share_msg']); ?>
    <?php endif; ?>

    <!-- 1. HEADER CHÍNH -->
    <header class="pocket-header d-flex align-items-center justify-content-between sticky-top">
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="pocket-logo"><i class="fa-solid fa-bookmark"></i> pocket</a>
            <nav class="d-flex gap-1">
                <span id="tabSaves" class="top-nav-link active" onclick="switchMainTab('all')">Saves</span>
                <span id="tabCollections" class="top-nav-link" onclick="switchMainTab('collections')">Library</span>
            </nav>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- NÚT THÔNG BÁO QUẢ CHUÔNG 🔔 -->
            <button class="header-icon-btn me-1" title="Thông báo chia sẻ" data-bs-toggle="modal" data-bs-target="#notificationModal">
                <i class="fa-solid fa-bell fs-5"></i>
                <?php if ($pendingCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                        <?php echo $pendingCount; ?>
                    </span>
                <?php endif; ?>
            </button>

            <button class="header-icon-btn" title="Tìm kiếm nhanh" data-bs-toggle="collapse" data-bs-target="#searchBox">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
            <button id="btnToggleAdvFilterPage" class="header-icon-btn text-primary" title="Trang Tìm kiếm & Lọc nâng cao" onclick="toggleAdvancedFilterPage()">
                <i class="fa-solid fa-sliders"></i>
            </button>
            <button id="headerPlusBtn" class="header-icon-btn text-danger" title="New" onclick="handleHeaderPlusClick()">
                <i class="fa-solid fa-plus fs-5"></i>
            </button>
            
            <div class="dropdown ms-2">
                <button class="btn btn-light border rounded-pill fw-semibold dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 30px; height: 30px; font-size: 0.8rem;">
                        <?php echo strtoupper(substr($current_username, 0, 1)); ?>
                    </div>
                    <?php echo htmlspecialchars($current_username); ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <?php if ($current_role === 'admin'): ?>
                        <li><a class="dropdown-item text-primary fw-bold" href="admin.php"><i class="fa-solid fa-user-gear me-2"></i>Trang quản trị (Admin)</a></li>
                        <li><hr class="dropdown-divider"></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item text-danger fw-bold" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- THANH TÌM KIẾM NHANH -->
    <div class="collapse bg-light border-bottom p-3" id="searchBox">
        <div class="container" style="max-width: 650px;">
            <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Gõ từ khóa tìm kiếm (tiêu đề, nhãn, nội dung bài viết)..." onkeyup="handleSearch()">
                <button class="btn btn-dark fw-bold px-4" onclick="handleSearch()">Tìm kiếm</button>
            </div>
        </div>
    </div>

    <!-- TRANG TÌM KIẾM & LỌC NÂNG CAO (HIỂN THỊ RIÊNG BIỆT) -->
    <section id="advancedFilterPage" style="display: none; background-color: #0f172a; color: #e2e8f0; min-height: calc(100vh - 60px);">
        <div class="container py-4 py-md-5" style="max-width: 1050px;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 pb-3 border-bottom border-secondary">
                <div>
                    <h4 class="fw-bold m-0 text-white"><i class="fa-solid fa-filter text-info me-2"></i>Tìm kiếm & Lọc nâng cao</h4>
                    <small class="text-secondary">Chọn các điều kiện bên dưới rồi bấm "Áp dụng bộ lọc" để xem danh sách kết quả</small>
                </div>
                <button type="button" class="btn btn-outline-light btn-sm px-3" onclick="closeAdvancedFilterPage()">
                    <i class="fa-solid fa-arrow-left me-1"></i> Quay lại danh sách
                </button>
            </div>

            <!-- Từ khóa, Library & Trạng thái -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label small text-secondary fw-semibold">Từ khóa tìm kiếm</label>
                    <input type="text" id="advKeyword" class="form-control bg-dark text-white border-secondary" placeholder="Nhập tiêu đề, URL hoặc nội dung bài viết...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-secondary fw-semibold">Library</label>
                    <select id="advCollection" class="form-select bg-dark text-white border-secondary">
                        <option value="">-- Tất cả Library --</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-secondary fw-semibold">Trạng thái HTTP</label>
                    <select id="advStatus" class="form-select bg-dark text-white border-secondary">
                        <option value="">-- Tất cả Trạng thái --</option>
                        <option value="200">HTTP 200 (Hoạt động tốt)</option>
                        <option value="404">HTTP 404 (Trang lỗi)</option>
                    </select>
                </div>
            </div>

            <!-- Nhãn bao gồm (Multi-Tag A-Z) -->
            <div class="p-3 p-md-4 rounded-3 border border-secondary mb-4" style="background-color: #1e293b;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div>
                        <span class="fw-bold text-info"><i class="fa-solid fa-tags me-1"></i> Nhãn bao gồm (Chọn nhiều nhãn cùng thỏa mãn):</span>
                        <small class="text-secondary d-block">Gõ chữ cái để lọc nhanh nhãn trong danh sách bên dưới</small>
                    </div>
                    <input type="text" id="tagQuickSearch" class="form-control form-control-sm bg-dark text-white border-secondary" style="width: 240px;" placeholder="Gõ tên nhãn để tìm nhanh..." onkeyup="filterTagCheckboxes()">
                </div>
                
                <div id="advIncludeTagsContainer" class="mt-3" style="max-height: 280px; overflow-y: auto; padding-right: 8px;"></div>
            </div>

            <!-- Nhãn loại trừ -->
            <div class="mb-4">
                <button class="btn btn-sm btn-outline-danger fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#excludeTagsCollapse">
                    <i class="fa-solid fa-ban me-1"></i> Nhãn loại trừ (Ẩn bài có chứa nhãn này)
                </button>
                <div class="collapse mt-2" id="excludeTagsCollapse">
                    <div id="advExcludeTagsContainer" class="p-3 rounded-3 border border-danger border-opacity-50 d-flex flex-wrap gap-3" style="background-color: #1e293b; max-height: 160px; overflow-y: auto;"></div>
                </div>
            </div>

            <!-- Nút điều khiển -->
            <div class="d-flex justify-content-between align-items-center pt-3 border-top border-secondary">
                <button type="button" class="btn btn-outline-light px-3" onclick="resetAdvancedFilter()">
                    <i class="fa-solid fa-rotate-left me-1"></i> Đặt lại bộ lọc
                </button>
                <button type="button" class="btn fw-bold px-5 py-2 text-white" style="background: linear-gradient(90deg, #8b5cf6, #ec4899);" onclick="applyAdvancedFilter()">
                    <i class="fa-solid fa-magnifying-glass me-2"></i> Áp dụng bộ lọc
                </button>
            </div>
        </div>
    </section>

    <!-- 2. BỐ CỤC CHÍNH (DANH SÁCH BÀI VIẾT) -->
    <div id="mainContentContainer" class="container-fluid px-4 px-md-5 py-4">
        <div class="d-flex gap-4">

            <!-- SIDEBAR TRÁI -->
            <aside class="pocket-sidebar">
                <!-- Cụm FILTERS & TAGS mặc định thu gọn, bấm vào mới xổ ra -->
                <div id="tagsSectionHeader" class="sidebar-section-toggle collapsed mb-1" onclick="toggleSidebarTagsSection()" title="Bấm để ẩn/hiện danh sách nhãn">
                    <span><i class="fa-solid fa-tags me-1 text-secondary"></i> FILTERS & TAGS <span id="totalTagsBadge" class="text-muted fw-normal"></span></span>
                    <i class="fa-solid fa-chevron-down chevron-icon"></i>
                </div>
                <div id="sidebarTagsWrapper" class="mb-3" style="display: none;">
                    <div id="sidebarTagsList"></div>
                </div>
                
                <div class="text-uppercase text-muted fw-bold extra-small px-3 mt-3 mb-2" style="font-size: 0.72rem;">TRẠNG THÁI HỆ THỐNG</div>
                <div id="linkErrorPages" class="side-link text-danger" onclick="filterCategory('error')">
                    <i class="fa-solid fa-triangle-exclamation"></i> Trang lỗi / 404 <span id="errorBadge" class="badge bg-danger ms-auto">0</span>
                </div>
            </aside>

            <!-- CARD GRID -->
            <main id="resultsMainArea" class="flex-grow-1">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <button id="backToLibraryBtn" type="button" class="btn btn-sm btn-outline-secondary" style="display: none;" onclick="switchMainTab('collections')" title="Quay lại tất cả Library">
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <h5 id="viewTitle" class="fw-bold m-0">Tất cả bài viết đã lưu</h5>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <button id="addInCurrentLibraryBtn" type="button" class="btn btn-sm btn-success fw-semibold" style="display: none;" onclick="openNewModal(activeLibraryFolder)">
                            <i class="fa-solid fa-plus me-1"></i> Thêm vào Library này
                        </button>
                        <span id="itemCount" class="text-muted fs-7">0 bài viết</span>
                    </div>
                </div>
                <div id="cardGrid" class="card-grid"></div>

                <div id="paginationContainer" class="mt-4"></div>
            </main>

        </div>
    </div>

    <!-- 3. MODAL XEM & DUYỆT THÔNG BÁO CHIA SẺ 🔔 -->
    <div class="modal fade" id="notificationModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3">
                <div class="modal-header border-0">
                    <h5 class="fw-bold m-0"><i class="fa-solid fa-bell text-warning me-2"></i>Lời mời chia sẻ bài viết</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($pendingCount > 0): ?>
                        <div class="list-group">
                            <?php foreach ($pendingShares as $item): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3 mb-2 rounded border">
                                    <div>
                                        <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($item['title']); ?></div>
                                        <small class="text-muted">
                                            Gửi bởi: <strong class="text-primary"><?php echo htmlspecialchars($item['sender_name']); ?></strong>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-1 ms-2">
                                        <form action="actions/respond_share.php" method="POST" class="d-inline">
                                            <input type="hidden" name="share_id" value="<?php echo $item['share_id']; ?>">
                                            <input type="hidden" name="action_type" value="accept">
                                            <button type="submit" class="btn btn-sm btn-success fw-bold" title="Chấp nhận">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                        </form>

                                        <form action="actions/respond_share.php" method="POST" class="d-inline">
                                            <input type="hidden" name="share_id" value="<?php echo $item['share_id']; ?>">
                                            <input type="hidden" name="action_type" value="reject">
                                            <button type="submit" class="btn btn-sm btn-outline-danger fw-bold" title="Từ chối">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fa-regular fa-bell-slash fs-2 mb-2 d-block"></i>
                            Bạn không có lời mời chia sẻ nào mới.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. MODAL ĐỌC BÀI (Chứa đầy đủ thông tin URL nguồn, Tags, Trạng thái, Phiên bản...) -->
    <div class="modal fade" id="readerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content p-4">
                <div class="modal-header border-0 pb-2">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span id="modalStatus" class="badge bg-success">HTTP 200 OK</span>
                            <span id="modalCollectionBadge" class="badge bg-secondary"></span>
                            <span id="modalSharedBadge" class="badge bg-primary-subtle text-primary border border-primary-subtle" style="display: none;"></span>
                        </div>
                        <h4 id="modalTitle" class="fw-bold mb-1"></h4>
                        <div class="text-muted small">
                            <i class="fa-solid fa-link me-1"></i>
                            <a id="modalSourceLink" href="#" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary fw-medium"></a>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-8 border-end pe-4">
                            <div class="d-flex align-items-center gap-2 mb-3 p-2 bg-light rounded text-dark">
                                <span class="fw-semibold fs-7"><i class="fa-solid fa-tags text-secondary"></i> Nhãn:</span>
                                <div id="modalTagsContainer" class="d-flex flex-wrap gap-1"></div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between mb-3 p-2 bg-light rounded text-dark">
                                <div id="versionButtonsContainer" class="d-flex align-items-center gap-1 flex-wrap">
                                    <span class="fw-semibold fs-7 me-2">Lịch sử Phiên bản:</span>
                                </div>

                                <button class="btn btn-sm btn-outline-primary fw-semibold" onclick="triggerRefetch()">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> Cập nhật bản mới
                                </button>
                            </div>

                            <div id="modalBody" class="border p-4 rounded"></div>
                        </div>

                        <div class="col-md-4">
                            <h6 class="fw-bold mb-3"><i class="fa-solid fa-note-sticky text-warning me-2"></i>Ghi chú cho Phiên bản này</h6>
                            <div id="notesContainer" class="mb-3"></div>

                            <form action="actions/note.php" method="POST">
                                <input type="hidden" name="action" value="add_note">
                                <input type="hidden" id="notePageId" name="page_id" value="">
                                <textarea name="note" class="form-control fs-7 mb-2" rows="3" placeholder="Nhập ghi chú riêng..." required></textarea>
                                <button type="submit" class="btn btn-warning btn-sm w-100 fw-bold mb-4">Thêm ghi chú</button>
                            </form>

                            <hr>

                            <!-- Form chia sẻ bài viết cho User khác trong hệ thống -->
                            <form action="actions/share_internal.php" method="POST" class="mb-3">
                                <input type="hidden" id="sharePageId" name="article_id" value="">
                                <label class="form-label fs-7 fw-bold text-dark"><i class="fa-solid fa-user-plus text-primary me-1"></i>Gửi bài cho tài khoản khác:</label>
                                <div class="input-group">
                                    <input type="text" name="receiver_username" class="form-control form-control-sm" placeholder="Nhập username người nhận..." required>
                                    <button type="submit" class="btn btn-primary btn-sm fw-bold">Gửi</button>
                                </div>
                            </form>
                            
                            <!-- Nút Chia sẻ liên kết công khai -->
                            <button class="btn btn-info text-white btn-sm w-100 fw-bold mb-2" onclick="shareArticle()">
                                <i class="fa-solid fa-share-nodes me-1"></i> Sao chép link chia sẻ
                            </button>

                            <button class="btn btn-outline-dark btn-sm w-100 fw-semibold" onclick="exportArticle()">
                                <i class="fa-solid fa-file-export me-1"></i> Xuất bài viết (HTML)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL LƯU BÀI MỚI (NEW) - LIÊN KẾT TRỰC TIẾP VỚI LIBRARY -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3">
                <div class="modal-header border-0">
                    <h5 class="fw-bold m-0"><i class="fa-solid fa-link text-danger me-2"></i>New</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/save.php" method="POST">
                    <input type="hidden" name="action" value="save_page">
                    <div class="modal-body">
                        <label class="form-label fw-semibold fs-7">URL trang gốc</label>
                        <input type="text" name="url" id="newUrlInput" class="form-control mb-3" placeholder="Dán URL trang web / Light Novel / Truyện..." required>
                        
                        <label class="form-label fw-semibold fs-7 d-flex justify-content-between align-items-center">
                            <span>Library</span>
                            <small class="text-muted" style="font-size:0.75rem;">(Chọn từ thư viện hoặc nhập tên mới)</small>
                        </label>
                        <div class="library-combo-wrapper mb-3">
                            <div class="input-group">
                                <input type="text" name="collection_name" id="newCollectionInput" class="form-control" autocomplete="off" placeholder="Chọn hoặc nhập tên Library..."
                                    onfocus="showLibraryDropdown('newCollectionInput', 'newLibraryDropdown')"
                                    oninput="filterLibraryDropdown('newCollectionInput', 'newLibraryDropdown')">
                                <button type="button" class="btn btn-outline-secondary" title="Hiện danh sách Library" onclick="toggleLibraryDropdown('newCollectionInput', 'newLibraryDropdown')">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div id="newLibraryDropdown" class="library-dropdown-menu"></div>
                        </div>

                        <label class="form-label fw-semibold fs-7 d-flex justify-content-between align-items-center">
                            <span>Nhãn (Tags)</span>
                            <small class="text-muted" style="font-size:0.75rem;">(Nhiều nhãn cách nhau bởi dấu phẩy)</small>
                        </label>
                        <input type="text" name="tags" id="newTagsInput" class="form-control mb-3" placeholder="VD: Novel, Isekai, Action...">

                        <button type="submit" class="btn btn-success w-100 fw-bold">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL SỬA BÀI & ĐỔI ẢNH BÌA -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3">
                <div class="modal-header border-0">
                    <h5 class="fw-bold m-0"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Chỉnh sửa bài viết & Ảnh bìa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/edit.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_page">
                    <input type="hidden" id="editPageId" name="page_id" value="">
                    <div class="modal-body">
                        <!-- Xem trước & Chọn ảnh bìa mới -->
                        <div class="mb-3 p-2 bg-light border rounded-3">
                            <label class="form-label fw-semibold fs-7 mb-1"><i class="fa-regular fa-image text-primary me-1"></i>Ảnh bìa hiển thị (Giữ nguyên tên bài viết)</label>
                            <div class="d-flex align-items-center gap-3 mt-1">
                                <img id="editCoverPreview" src="" alt="Preview" style="width: 68px; height: 86px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; display: none;">
                                <div class="flex-grow-1">
                                    <input type="file" name="custom_cover" id="editCoverInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewEditCover(this)">
                                    <small class="text-muted d-block mt-1" style="font-size: 0.74rem;">Chọn ảnh từ máy để thay thế banner quảng cáo nếu có.</small>
                                </div>
                            </div>
                        </div>

                        <label class="form-label fw-semibold fs-7">Tiêu đề bài viết</label>
                        <input type="text" id="editTitleInput" name="title" class="form-control mb-3" required>

                        <label class="form-label fw-semibold fs-7">URL nguồn trang web</label>
                        <input type="text" id="editUrlInput" name="url" class="form-control mb-3" placeholder="https://..." required>

                        <label class="form-label fw-semibold fs-7">Library</label>
                        <div class="library-combo-wrapper mb-3">
                            <div class="input-group">
                                <input type="text" id="editCollectionInput" name="collection_name" class="form-control" autocomplete="off" placeholder="Chọn hoặc nhập tên Library..."
                                    onfocus="showLibraryDropdown('editCollectionInput', 'editLibraryDropdown')"
                                    oninput="filterLibraryDropdown('editCollectionInput', 'editLibraryDropdown')">
                                <button type="button" class="btn btn-outline-secondary" title="Hiện danh sách Library" onclick="toggleLibraryDropdown('editCollectionInput', 'editLibraryDropdown')">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div id="editLibraryDropdown" class="library-dropdown-menu"></div>
                        </div>
                        
                        <label class="form-label fw-semibold fs-7 d-flex justify-content-between align-items-center">
                            <span>Nhãn (Tags)</span>
                            <small class="text-muted" style="font-size:0.75rem;">(Nhiều nhãn cách nhau bởi dấu phẩy)</small>
                        </label>
                        <input type="text" id="editTagInput" name="tags" class="form-control mb-3" placeholder="VD: Báo, Thể thao, Thế giới...">

                        <button type="submit" class="btn btn-primary w-100 fw-bold">Cập nhật thay đổi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- FORM XÓA & CÀO LẠI -->
    <form id="deleteForm" action="actions/delete.php" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_page">
        <input type="hidden" id="deletePageId" name="page_id" value="">
    </form>

    <form id="refetchForm" action="actions/refetch.php" method="POST" style="display:none;">
        <input type="hidden" name="action" value="refetch_page">
        <input type="hidden" id="refetchPageId" name="page_id" value="">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let database = <?php echo json_encode($db_articles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
        let activePageId = null;
        let activeVersion = 1;
        let isAdvFilterInitialized = false;
        let showAllSidebarTags = false;

        // Trạng thái điều hướng Library
        let currentMainMode = 'saves'; // 'saves' | 'library-root' | 'library-folder'
        let activeLibraryFolder = null;
        const customLibrariesStorageKey = 'pocket_custom_libraries_<?php echo (int)$current_user_id; ?>';

        const itemsPerPage = 10;
        let currentData = [];

        function escapeHTML(str) {
            return str ? str.toString().replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag])) : '';
        }

        function escapeAttr(str) {
            return str ? str.toString().replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;') : '';
        }

        // Quản lý danh sách Library thực tế (kết hợp bài viết trong MySQL + thư mục trống vừa tạo)
        function getSavedCustomLibraries() {
            try {
                const raw = localStorage.getItem(customLibrariesStorageKey);
                const parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function saveCustomLibraryName(name) {
            const clean = (name || '').trim();
            if (!clean) return;
            const existing = getSavedCustomLibraries();
            if (!existing.some(lib => lib.toLowerCase() === clean.toLowerCase())) {
                existing.push(clean);
                try {
                    localStorage.setItem(customLibrariesStorageKey, JSON.stringify(existing));
                } catch (e) {}
            }
        }

        function getAllLibrariesWithCounts() {
            const libMap = new Map();

            // 1. Lấy tất cả Library hiện có từ database
            database.forEach(item => {
                const name = (item.collection_name || 'Chung').trim() || 'Chung';
                const key = name.toLowerCase();
                if (!libMap.has(key)) {
                    libMap.set(key, { name: name, count: 1 });
                } else {
                    libMap.get(key).count += 1;
                }
            });

            // 2. Bổ sung các Library trống mới tạo (0 bài viết)
            getSavedCustomLibraries().forEach(name => {
                const clean = (name || '').trim();
                if (!clean) return;
                const key = clean.toLowerCase();
                if (!libMap.has(key)) {
                    libMap.set(key, { name: clean, count: 0 });
                }
            });

            return Array.from(libMap.values());
        }

        function renderLibraryDropdownOptions(inputId, dropdownId, filterText = '') {
            const dropdown = document.getElementById(dropdownId);
            if (!dropdown) return;

            const libraries = getAllLibrariesWithCounts();
            const q = (filterText || '').trim().toLowerCase();
            const matched = libraries.filter(lib => lib.name.toLowerCase().includes(q));

            let html = '';
            if (matched.length > 0) {
                matched.forEach(lib => {
                    html += `
                        <div class="library-dropdown-item" onmousedown="selectLibraryOption('${inputId}', '${dropdownId}', '${escapeAttr(lib.name)}')">
                            <span><i class="fa-solid fa-folder text-warning me-2"></i>${escapeHTML(lib.name)}</span>
                            <span class="badge bg-light text-secondary border">${lib.count} bài</span>
                        </div>
                    `;
                });
            }

            const exactMatch = libraries.some(lib => lib.name.toLowerCase() === q);
            if (q && !exactMatch) {
                const rawTyped = document.getElementById(inputId)?.value.trim() || '';
                html += `
                    <div class="library-dropdown-item text-success fw-semibold" onmousedown="selectLibraryOption('${inputId}', '${dropdownId}', '${escapeAttr(rawTyped)}')">
                        <span><i class="fa-solid fa-folder-plus me-2"></i>Tạo Library mới: "${escapeHTML(rawTyped)}"</span>
                    </div>
                `;
            }

            if (!html) {
                html = `<div class="p-2 text-muted small text-center">Chưa có Library nào — gõ tên để tạo mới</div>`;
            }

            dropdown.innerHTML = html;
        }

        function showLibraryDropdown(inputId, dropdownId) {
            renderLibraryDropdownOptions(inputId, dropdownId, '');
            const dropdown = document.getElementById(dropdownId);
            if (dropdown) dropdown.style.display = 'block';
        }

        function filterLibraryDropdown(inputId, dropdownId) {
            const input = document.getElementById(inputId);
            const val = input ? input.value : '';
            renderLibraryDropdownOptions(inputId, dropdownId, val);
            const dropdown = document.getElementById(dropdownId);
            if (dropdown) dropdown.style.display = 'block';
        }

        function toggleLibraryDropdown(inputId, dropdownId) {
            const dropdown = document.getElementById(dropdownId);
            const input = document.getElementById(inputId);
            if (!dropdown) return;
            if (dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            } else {
                showLibraryDropdown(inputId, dropdownId);
                if (input) input.focus();
            }
        }

        function selectLibraryOption(inputId, dropdownId, value) {
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            if (input) input.value = value;
            if (dropdown) dropdown.style.display = 'none';
        }

        // Đóng dropdown Library khi nhấp ra ngoài
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.library-combo-wrapper')) {
                document.querySelectorAll('.library-dropdown-menu').forEach(el => el.style.display = 'none');
            }
        });

        // Cập nhật giao diện thanh công cụ theo ngữ cảnh (Saves / Library gốc / Trong 1 folder Library)
        function updateContextToolbar() {
            const plusBtn = document.getElementById('headerPlusBtn');
            const backBtn = document.getElementById('backToLibraryBtn');
            const addInLibBtn = document.getElementById('addInCurrentLibraryBtn');

            if (plusBtn) {
                if (currentMainMode === 'library-root') {
                    plusBtn.title = 'Tạo Library mới';
                } else if (currentMainMode === 'library-folder' && activeLibraryFolder) {
                    plusBtn.title = `New (Lưu vào ${activeLibraryFolder})`;
                } else {
                    plusBtn.title = 'New';
                }
            }

            if (backBtn) {
                backBtn.style.display = (currentMainMode === 'library-folder') ? 'inline-block' : 'none';
            }
            if (addInLibBtn) {
                addInLibBtn.style.display = (currentMainMode === 'library-folder') ? 'inline-block' : 'none';
            }
        }

        // Bấm dấu + trên Header: ở trang tổng Library thì tạo folder mới; ở trang khác thì mở modal New
        function handleHeaderPlusClick() {
            if (currentMainMode === 'library-root') {
                promptCreateLibrary();
            } else if (currentMainMode === 'library-folder' && activeLibraryFolder) {
                openNewModal(activeLibraryFolder);
            } else {
                openNewModal('');
            }
        }

        function openNewModal(defaultLibrary = '') {
            const urlInput = document.getElementById('newUrlInput');
            const colInput = document.getElementById('newCollectionInput');
            const tagsInput = document.getElementById('newTagsInput');

            if (urlInput) urlInput.value = '';
            if (tagsInput) tagsInput.value = '';
            if (colInput) {
                colInput.value = defaultLibrary || '';
            }

            const modalEl = document.getElementById('addModal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        function promptCreateLibrary() {
            Swal.fire({
                title: 'Tạo Library mới',
                input: 'text',
                inputPlaceholder: 'Nhập tên thư mục Library (VD: Manhwa, Lập trình...)',
                showCancelButton: true,
                confirmButtonColor: '#008080',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-folder-plus me-1"></i> Tạo Library',
                cancelButtonText: 'Hủy',
                reverseButtons: true,
                customClass: { popup: 'rounded-4' },
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'Vui lòng nhập tên Library!';
                    }
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    const newLibName = result.value.trim();
                    saveCustomLibraryName(newLibName);
                    renderFolderView();
                    Swal.fire({
                        icon: 'success',
                        title: 'Đã tạo Library!',
                        text: `Thư mục "${newLibName}" đã sẵn sàng để lưu bài.`,
                        timer: 1600,
                        showConfirmButton: false,
                        customClass: { popup: 'rounded-4' }
                    });
                }
            });
        }

        // Mở / Đóng trang Tìm kiếm & Lọc nâng cao riêng biệt
        function toggleAdvancedFilterPage() {
            const filterPage = document.getElementById('advancedFilterPage');
            if (!filterPage) return;
            const isOpen = filterPage.style.display === 'block';
            if (isOpen) {
                closeAdvancedFilterPage();
            } else {
                openAdvancedFilterPage();
            }
        }

        function openAdvancedFilterPage() {
            initAdvancedFilterUI();
            const filterPage = document.getElementById('advancedFilterPage');
            const mainContent = document.getElementById('mainContentContainer');
            const btn = document.getElementById('btnToggleAdvFilterPage');

            if (filterPage) filterPage.style.display = 'block';
            if (mainContent) mainContent.style.display = 'none';
            if (btn) btn.classList.add('active-btn');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeAdvancedFilterPage() {
            const filterPage = document.getElementById('advancedFilterPage');
            const mainContent = document.getElementById('mainContentContainer');
            const btn = document.getElementById('btnToggleAdvFilterPage');

            if (filterPage) filterPage.style.display = 'none';
            if (mainContent) mainContent.style.display = 'block';
            if (btn) btn.classList.remove('active-btn');
        }

        function buildGradientCover(title, bgStyle) {
            const safeTitle = escapeHTML(title);
            const firstLetter = safeTitle ? safeTitle.trim().charAt(0).toUpperCase() : 'P';
            return `
                <div class="w-100 h-100 position-relative overflow-hidden d-flex align-items-center justify-content-center text-white" style="background: ${bgStyle};">
                    <div class="position-absolute rounded-circle" style="width: 190px; height: 190px; background: linear-gradient(135deg, rgba(255,255,255,0.22), rgba(255,255,255,0)); top: -45px; right: -45px;"></div>
                    <div class="position-absolute rounded-circle" style="width: 100px; height: 100px; background: rgba(255,255,255,0.08); bottom: 55px; left: -25px;"></div>
                    <div class="position-absolute fw-bolder" style="font-size: 11rem; opacity: 0.14; right: 10px; top: 10px; line-height: 1; user-select: none; font-family: sans-serif;">${firstLetter}</div>
                    <div class="bg-white bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 56px; height: 56px; border: 1px solid rgba(255,255,255,0.35);">
                        <i class="fa-solid fa-book-open fs-4 text-white"></i>
                    </div>
                </div>
            `;
        }

        function handleImgError(img) {
            img.onerror = null;
            const title = img.getAttribute('data-title') || '';
            const bg = img.getAttribute('data-bg') || 'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)';
            img.outerHTML = buildGradientCover(title, bg);
        }

        function renderCards(data, page = 1) {
            currentData = data;
            const grid = document.getElementById('cardGrid');
            if (!grid) return;
            grid.innerHTML = '';
            document.getElementById('itemCount').innerText = `${data.length} bài viết`;

            if (data.length === 0) {
                grid.innerHTML = `<div class="text-muted p-4 text-center w-100">Chưa có bài viết nào trong mục này.</div>`;
                const pagContainer = document.getElementById('paginationContainer');
                if (pagContainer) pagContainer.innerHTML = '';
                renderSidebarTags();
                updateErrorBadgeCount();
                return;
            }

            const totalPages = Math.ceil(data.length / itemsPerPage);
            const safePage = Math.min(Math.max(1, page), totalPages);

            const startIndex = (safePage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedItems = data.slice(startIndex, endIndex);

            paginatedItems.forEach(item => {
                let snippetHtml = '';
                if (item.searchSnippet) {
                    snippetHtml = `
                        <div class="poster-snippet-box">
                            <i class="fa-solid fa-quote-left me-1 text-warning"></i>...${item.searchSnippet}...
                        </div>
                    `;
                }
                
                const gradients = [
                    'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)',
                    'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
                    'linear-gradient(135deg, #f59e0b 0%, #b45309 100%)',
                    'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
                    'linear-gradient(135deg, #ec4899 0%, #be185d 100%)',
                    'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)'
                ];
                const bgStyle = gradients[item.id % gradients.length];
                const safeTitle = escapeHTML(item.title);

                let coverHtml = '';
                if (item.image === '404') {
                    coverHtml = `
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-white" style="background: linear-gradient(135deg, #ef4444, #991b1b);">
                            <div class="text-center mb-4">
                                <i class="fa-solid fa-triangle-exclamation display-5 mb-2"></i>
                                <div class="fw-bold fs-7">Lỗi 404</div>
                            </div>
                        </div>
                    `;
                } else if (item.image) {
                    coverHtml = `<img src="${escapeHTML(item.image)}" class="poster-full-img" alt="${safeTitle}" data-title="${safeTitle}" data-bg="${bgStyle}" onerror="handleImgError(this)">`;
                } else {
                    coverHtml = buildGradientCover(item.title, bgStyle);
                }
                
                const cardHtml = `
                    <div class="pocket-card" onclick="openReader(${item.id})" title="${safeTitle}">
                        <div class="card-action-btns">
                            <button class="btn btn-sm shadow-sm" title="Sửa thông tin & Ảnh bìa" onclick="event.stopPropagation(); openEditModal(${item.id})">
                                <i class="fa-solid fa-pen text-dark fs-7"></i>
                            </button>
                            <button class="btn btn-sm shadow-sm" title="Xóa bài" onclick="event.stopPropagation(); confirmDelete(${item.id})">
                                <i class="fa-solid fa-trash text-danger fs-7"></i>
                            </button>
                        </div>
                        ${coverHtml}
                        <div class="poster-title-overlay">
                            <h6 class="poster-title-text">${safeTitle}</h6>
                            ${snippetHtml}
                        </div>
                    </div>
                `;
                grid.innerHTML += cardHtml;
            });

            renderPagination(data.length, safePage);
            renderSidebarTags();
            updateErrorBadgeCount();
        }

        // Biến nút "..." thành ô nhập số trang ngay tại chỗ
        function activateEllipsisInput(liEl, totalPages, currentPage) {
            if (!liEl) return;
            liEl.innerHTML = `
                <input type="number" class="page-ellipsis-input" min="1" max="${totalPages}" placeholder="..."
                    onkeydown="if(event.key==='Enter'){ event.preventDefault(); submitEllipsisPage(this, ${totalPages}, ${currentPage}); } else if(event.key==='Escape'){ renderCards(currentData, ${currentPage}); }"
                    onblur="submitEllipsisPage(this, ${totalPages}, ${currentPage})">
            `;
            const input = liEl.querySelector('input');
            if (input) {
                input.focus();
            }
        }

        function submitEllipsisPage(inputEl, totalPages, currentPage) {
            if (!inputEl) return;
            const rawVal = inputEl.value.trim();
            if (!rawVal) {
                renderCards(currentData, currentPage);
                return;
            }
            const targetPage = parseInt(rawVal, 10);
            if (isNaN(targetPage) || targetPage < 1 || targetPage > totalPages) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Số trang không hợp lệ',
                    text: `Vui lòng nhập số trang từ 1 đến ${totalPages}.`,
                    timer: 1600,
                    showConfirmButton: false,
                    customClass: { popup: 'rounded-4' }
                });
                renderCards(currentData, currentPage);
                return;
            }
            renderCards(currentData, targetPage);
            const resultsArea = document.getElementById('resultsMainArea');
            if (resultsArea) resultsArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function renderPagination(totalItems, currentPage) {
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            const container = document.getElementById('paginationContainer');
            if (!container) return;
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            // Xây dựng danh sách các phần tử trang: [Trước | 1 | 2 | ... | Trang cuối | Sau]
            let tokens = [];
            if (totalPages === 2) {
                tokens = [1, 2];
            } else if (currentPage <= 2) {
                tokens = [1, 2, '...', totalPages];
            } else if (currentPage >= totalPages - 1) {
                tokens = [1, '...', totalPages - 1, totalPages];
            } else {
                tokens = [1, '...', currentPage, '...', totalPages];
            }

            let html = `<nav><ul class="pagination justify-content-center mb-0">`;
            html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <button class="page-link text-dark fw-semibold" onclick="renderCards(currentData, ${currentPage - 1})">Trước</button>
                     </li>`;

            tokens.forEach(tok => {
                if (tok === '...') {
                    html += `<li class="page-item">
                                <button type="button" class="page-link text-dark fw-bold" title="Bấm để nhập số trang muốn đến" onclick="activateEllipsisInput(this.parentElement, ${totalPages}, ${currentPage})">...</button>
                             </li>`;
                } else {
                    const activeStyle = currentPage === tok ? 'background-color: #008080; color: white; border-color: #008080;' : 'color: #374151;';
                    html += `<li class="page-item ${currentPage === tok ? 'active' : ''}">
                                <button class="page-link fw-semibold" style="${activeStyle}" onclick="renderCards(currentData, ${tok})">${tok}</button>
                             </li>`;
                }
            });

            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <button class="page-link text-dark fw-semibold" onclick="renderCards(currentData, ${currentPage + 1})">Sau</button>
                     </li>`;
            html += `</ul></nav>`;
            container.innerHTML = html;
        }

        function updateErrorBadgeCount() {
            const badge = document.getElementById('errorBadge');
            if (badge) badge.innerText = database.filter(i => i.status === 404).length;
        }

        // Bật/tắt toàn bộ cụm FILTERS & TAGS ở Sidebar
        function toggleSidebarTagsSection() {
            const header = document.getElementById('tagsSectionHeader');
            const wrapper = document.getElementById('sidebarTagsWrapper');
            if (!header || !wrapper) return;

            const isHidden = wrapper.style.display === 'none';
            wrapper.style.display = isHidden ? 'block' : 'none';
            header.classList.toggle('collapsed', !isHidden);
        }

        // Mở rộng / thu gọn danh sách nhãn khi có hơn 5 nhãn
        function toggleMoreSidebarTags() {
            showAllSidebarTags = !showAllSidebarTags;
            renderSidebarTags();
        }

        function renderSidebarTags() {
            const container = document.getElementById('sidebarTagsList');
            const totalBadge = document.getElementById('totalTagsBadge');
            if (!container) return;

            let tagCounts = new Map();
            database.forEach(item => {
                (item.tags || []).forEach(t => {
                    const clean = t.trim();
                    if (!clean) return;
                    const key = clean.toLowerCase();
                    if (!tagCounts.has(key)) {
                        tagCounts.set(key, { label: clean, count: 1 });
                    } else {
                        tagCounts.get(key).count += 1;
                    }
                });
            });

            const sortedTags = Array.from(tagCounts.values()).sort((a, b) => b.count - a.count || a.label.localeCompare(b.label, 'vi'));
            if (totalBadge) totalBadge.innerText = `(${sortedTags.length})`;

            const maxVisible = 5;
            const visibleTags = showAllSidebarTags ? sortedTags : sortedTags.slice(0, maxVisible);

            let html = '';
            visibleTags.forEach(tObj => {
                html += `
                    <div class="side-link justify-content-between" onclick="filterTag('${escapeAttr(tObj.label)}')">
                        <span class="text-truncate"><i class="fa-solid fa-tag me-2 text-secondary"></i>#${escapeHTML(tObj.label)}</span>
                        <span class="badge bg-light text-secondary border" style="font-size: 0.7rem;">${tObj.count}</span>
                    </div>
                `;
            });

            if (sortedTags.length > maxVisible) {
                const remain = sortedTags.length - maxVisible;
                html += `
                    <div class="side-link text-primary fw-semibold mt-1" style="font-size: 0.82rem;" onclick="toggleMoreSidebarTags()">
                        <i class="fa-solid ${showAllSidebarTags ? 'fa-chevron-up' : 'fa-chevron-down'}"></i>
                        <span>${showAllSidebarTags ? 'Thu gọn nhãn' : `Xem thêm (+${remain} nhãn)`}</span>
                    </div>
                `;
            }

            container.innerHTML = html;
        }

        function openReader(id) {
            const item = database.find(p => p.id === id);
            if (!item) return;

            activePageId = id;
            activeVersion = item.currentVersion;
    
            document.getElementById('notePageId').value = id;
            document.getElementById('sharePageId').value = id;
            document.getElementById('modalTitle').innerText = item.title;

            // Hiển thị URL trang web nguồn bên trong Modal đọc bài
            const sourceLinkEl = document.getElementById('modalSourceLink');
            if (sourceLinkEl) {
                sourceLinkEl.href = item.url;
                sourceLinkEl.innerText = item.url;
            }

            document.getElementById('modalStatus').innerText = "HTTP " + item.status + " " + (item.status === 200 ? "OK" : "Error");
            document.getElementById('modalStatus').className = `badge ${item.status === 200 ? 'bg-success' : 'bg-danger'}`;

            const colBadge = document.getElementById('modalCollectionBadge');
            if (colBadge) colBadge.innerText = "Library: " + (item.collection_name || 'Chung');

            const sharedBadge = document.getElementById('modalSharedBadge');
            if (sharedBadge) {
                if (item.shared_by) {
                    sharedBadge.style.display = 'inline-block';
                    sharedBadge.innerHTML = `<i class="fa-solid fa-share me-1"></i>Chia sẻ bởi ${escapeHTML(item.shared_by)}`;
                } else {
                    sharedBadge.style.display = 'none';
                }
            }

            document.getElementById('modalTagsContainer').innerHTML = (item.tags || []).map(t => `<span class="tag-badge">#${escapeHTML(t)}</span>`).join(' ');

            const versionButtonsContainer = document.getElementById('versionButtonsContainer'); 
            if (versionButtonsContainer) {
                versionButtonsContainer.innerHTML = '<span class="fw-semibold fs-7 me-2">Lịch sử Phiên bản:</span>';

                Object.keys(item.snapshots || {}).forEach(v => {
                    const snap = item.snapshots[v];
                    let timeStr = '';
                    if (snap && snap.fetched_at) {
                        const d = new Date(snap.fetched_at.replace(/-/g, "/"));
                        timeStr = d.toLocaleString('vi-VN', {hour: '2-digit', minute:'2-digit', day:'2-digit', month:'2-digit', year:'numeric'});
                    }

                    const btnClass = (parseInt(v) === activeVersion) ? 'btn-success' : 'btn-outline-secondary';
                    versionButtonsContainer.innerHTML += `
                        <button id="btnV${v}" class="btn btn-sm ${btnClass} me-1 mb-1" title="Lưu lúc: ${timeStr}" onclick="switchVersion(${v})">
                            v${v} ${timeStr ? `<small style="font-size:0.7rem; opacity:0.85;">(${timeStr})</small>` : ''}
                        </button>
                    `;
                });
            }
            loadVersionData();
            new bootstrap.Modal(document.getElementById('readerModal')).show();
        }

        function loadVersionData() {
            const item = database.find(p => p.id === activePageId);
            const snap = item && item.snapshots ? item.snapshots[activeVersion] : null;

            Object.keys(item.snapshots || {}).forEach(v => {
                const btn = document.getElementById(`btnV${v}`);
                if (btn) {
                    if (parseInt(v) === activeVersion) {
                        btn.classList.remove('btn-outline-secondary');
                        btn.classList.add('btn-success');
                    } else {
                        btn.classList.remove('btn-success');
                        btn.classList.add('btn-outline-secondary');
                    }
                }
            });

            if (snap) {
                document.getElementById('modalBody').innerHTML = snap.content;
                const notesDiv = document.getElementById('notesContainer');
                notesDiv.innerHTML = '';
                if (snap.notes && snap.notes.length > 0) {
                    snap.notes.forEach(note => {
                        notesDiv.innerHTML += `<div class="p-2 bg-light border rounded mb-2 fs-7 text-dark">${note}</div>`;
                    });
                } else {
                    notesDiv.innerHTML = '<div class="text-muted fs-7 italic">Chưa có ghi chú nào.</div>';
                }
            } else {
                document.getElementById('modalBody').innerHTML = '<p class="text-danger">Không tìm thấy nội dung cho phiên bản này.</p>';
            }
        }

        function previewEditCover(input) {
            const previewImg = document.getElementById('editCoverPreview');
            if (!previewImg) return;
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewImg.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function openEditModal(id) {
            const item = database.find(p => p.id === id);
            if (!item) return;

            document.getElementById('editPageId').value = item.id;
            document.getElementById('editTitleInput').value = item.title;
            document.getElementById('editUrlInput').value = item.url;
            document.getElementById('editCollectionInput').value = item.collection_name || 'Chung';
            document.getElementById('editTagInput').value = (item.tags || []).join(', ');

            const coverInput = document.getElementById('editCoverInput');
            if (coverInput) coverInput.value = '';

            const previewImg = document.getElementById('editCoverPreview');
            if (previewImg) {
                if (item.image && item.image !== '404') {
                    previewImg.src = item.image;
                    previewImg.style.display = 'block';
                } else {
                    previewImg.src = '';
                    previewImg.style.display = 'none';
                }
            }

            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        function confirmDelete(id) {
            Swal.fire({
                title: 'Xác nhận xóa?',
                text: "Bài viết và toàn bộ ảnh lưu trữ liên quan sẽ bị xóa vĩnh viễn!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-trash me-1"></i> Đồng ý xóa',
                cancelButtonText: 'Hủy bỏ',
                reverseButtons: true,
                customClass: { popup: 'rounded-4 border-0 shadow-lg' }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deletePageId').value = id;
                    document.getElementById('deleteForm').submit();
                }
            });
        }

        function switchVersion(v) {
            activeVersion = v;
            loadVersionData();
        }

        function switchMainTab(type) {
            closeAdvancedFilterPage();
            document.querySelectorAll('.top-nav-link, .side-link').forEach(el => el.classList.remove('active'));
            if (type === 'all') {
                currentMainMode = 'saves';
                activeLibraryFolder = null;
                updateContextToolbar();
                document.getElementById('tabSaves').classList.add('active');
                document.getElementById('viewTitle').innerText = "Tất cả bài viết đã lưu";
                renderCards(database);
            } else if (type === 'collections') {
                currentMainMode = 'library-root';
                activeLibraryFolder = null;
                updateContextToolbar();
                document.getElementById('tabCollections').classList.add('active');
                document.getElementById('viewTitle').innerText = "Library";
                renderFolderView();
            }
        }

        function renderFolderView() {
            const grid = document.getElementById('cardGrid');
            grid.innerHTML = '';

            const allLibraries = getAllLibrariesWithCounts();
            document.getElementById('itemCount').innerText = `${allLibraries.length} mục`;

            allLibraries.forEach(lib => {
                const folderHtml = `
                    <div class="folder-card" onclick="filterCollection('${escapeAttr(lib.name)}')">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="folder-icon-box">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <span class="folder-count-badge">
                                ${lib.count} bài viết
                            </span>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-1" style="font-size: 1.15rem; letter-spacing: -0.2px;">${escapeHTML(lib.name)}</h5>
                            <p class="text-muted m-0" style="font-size: 0.82rem;">
                                Khám phá nội dung <i class="fa-solid fa-arrow-right-long ms-1" style="color: #0d9488;"></i>
                            </p>
                        </div>
                    </div>
                `;
                grid.innerHTML += folderHtml;
            });

            // Thẻ "+ Tạo Library mới" ở cuối lưới thư mục
            grid.innerHTML += `
                <div class="folder-add-card" onclick="promptCreateLibrary()">
                    <i class="fa-solid fa-folder-plus fs-2"></i>
                    <span class="fw-bold">Tạo Library mới</span>
                </div>
            `;

            const pagContainer = document.getElementById('paginationContainer');
            if (pagContainer) pagContainer.innerHTML = '';
        }

        function filterCategory(cat) {
            closeAdvancedFilterPage();
            currentMainMode = 'saves';
            activeLibraryFolder = null;
            updateContextToolbar();
            document.querySelectorAll('.side-link').forEach(el => el.classList.remove('active'));
            if (cat === 'error') {
                document.getElementById('linkErrorPages').classList.add('active');
                document.getElementById('viewTitle').innerText = "Danh sách Trang lỗi / 404";
                renderCards(database.filter(i => i.status === 404));
            }
        }

        function filterTag(tag) {
            closeAdvancedFilterPage();
            currentMainMode = 'saves';
            activeLibraryFolder = null;
            updateContextToolbar();
            document.querySelectorAll('.side-link').forEach(el => el.classList.remove('active'));
            const targetLower = tag.trim().toLowerCase();
            document.getElementById('viewTitle').innerText = `Bài viết đính kèm nhãn #${tag}`;
            renderCards(database.filter(i => (i.tags || []).some(t => t.trim().toLowerCase() === targetLower)));
        }

        function stripHtml(html) {
            let tmp = document.createElement("DIV");
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || "";
        }

        function handleSearch() {
            closeAdvancedFilterPage();
            currentMainMode = 'saves';
            activeLibraryFolder = null;
            updateContextToolbar();
            const query = document.getElementById('searchInput').value.trim().toLowerCase();
            
            if (!query) {
                const cleanData = database.map(item => {
                    delete item.searchSnippet;
                    return item;
                });
                renderCards(cleanData);
                return;
            }

            const searchResults = [];

            database.forEach(item => {
                let matched = false;
                let snippet = '';

                const matchTitle = item.title.toLowerCase().includes(query);
                const matchUrl = item.url.toLowerCase().includes(query);
                const matchTags = (item.tags || []).some(t => t.toLowerCase().includes(query));

                if (matchTitle || matchUrl || matchTags) {
                    matched = true;
                }

                Object.keys(item.snapshots || {}).forEach(ver => {
                    const rawContent = item.snapshots[ver].content || '';
                    const textContent = stripHtml(rawContent);
                    const lowerText = textContent.toLowerCase();
                    const index = lowerText.indexOf(query);

                    if (index !== -1 && !snippet) {
                        matched = true;
                        const start = Math.max(0, index - 40);
                        const end = Math.min(textContent.length, index + query.length + 40);
                        let rawSnippet = textContent.substring(start, end);
                        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                        snippet = `[v${ver}] ` + rawSnippet.replace(regex, '<mark class="p-0 bg-warning fw-bold text-dark">$1</mark>');
                    }
                });

                if (matched) {
                    searchResults.push({
                        ...item,
                        searchSnippet: snippet
                    });
                }
            });

            renderCards(searchResults);
        }

        function triggerRefetch() {
            if (!activePageId) return;
            
            Swal.fire({
                title: 'Cập nhật phiên bản mới?',
                text: "Hệ thống sẽ cào lại trang gốc để tạo thêm một bản snapshot mới (v2, v3...).",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#008080',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-arrows-rotate me-1"></i> Cào bản mới',
                cancelButtonText: 'Hủy bỏ',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Đang cào lại dữ liệu...',
                        text: 'Vui lòng chờ trong giây lát',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    document.getElementById('refetchPageId').value = activePageId;
                    document.getElementById('refetchForm').submit();
                }
            });
        }

        function shareArticle() {
            if (!activePageId || !activeVersion) return;
            const shareUrl = `${window.location.origin}${window.location.pathname.replace('index.php', '')}share.php?id=${activePageId}&v=${activeVersion}`;
            navigator.clipboard.writeText(shareUrl).then(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Đã sao chép link chia sẻ!',
                    text: 'Bạn có thể gửi liên kết này cho bất kỳ ai để họ xem trực tiếp bài viết.',
                    timer: 2000,
                    showConfirmButton: false,
                    customClass: { popup: 'rounded-4' }
                });
            }).catch(() => {
                prompt("Sao chép liên kết dưới đây để chia sẻ:", shareUrl);
            });
        }

        function exportArticle() {
            if (!activePageId || !activeVersion) return;
            window.location.href = `actions/export.php?id=${activePageId}&v=${activeVersion}`;
        }

        function filterCollection(colName) {
            closeAdvancedFilterPage();
            currentMainMode = 'library-folder';
            activeLibraryFolder = colName;
            updateContextToolbar();

            document.querySelectorAll('.top-nav-link, .side-link').forEach(el => el.classList.remove('active'));
            const libTab = document.getElementById('tabCollections');
            if (libTab) libTab.classList.add('active');

            document.getElementById('viewTitle').innerText = `Library: ${colName}`;
            renderCards(database.filter(i => (i.collection_name || 'Chung').toLowerCase() === colName.toLowerCase()));
        }

        // Khởi tạo giao diện Bộ lọc nâng cao (chỉ dựng 1 lần để tránh lag)
        function initAdvancedFilterUI() {
            if (isAdvFilterInitialized) return;
            isAdvFilterInitialized = true;

            const colSelect = document.getElementById('advCollection');
            if (colSelect && colSelect.options.length <= 1) {
                getAllLibrariesWithCounts().forEach(lib => {
                    colSelect.innerHTML += `<option value="${escapeHTML(lib.name)}">${escapeHTML(lib.name)}</option>`;
                });
            }

            let uniqueTagsMap = new Map();
            database.forEach(item => {
                (item.tags || []).forEach(t => {
                    const cleanTag = t.trim();
                    if (cleanTag && !uniqueTagsMap.has(cleanTag.toLowerCase())) {
                        uniqueTagsMap.set(cleanTag.toLowerCase(), cleanTag);
                    }
                });
            });

            const sortedTags = Array.from(uniqueTagsMap.values()).sort((a, b) => a.localeCompare(b, 'vi'));

            let groupedTags = {};
            sortedTags.forEach(tag => {
                let firstChar = tag.charAt(0).toUpperCase();
                if (!/[A-ZÀ-Ỹ]/.test(firstChar)) firstChar = '#';
                if (!groupedTags[firstChar]) groupedTags[firstChar] = [];
                groupedTags[firstChar].push(tag);
            });

            const incContainer = document.getElementById('advIncludeTagsContainer');
            if (incContainer) {
                incContainer.innerHTML = '';
                Object.keys(groupedTags).sort().forEach(letter => {
                    let groupHtml = `<div class="tag-letter-group mb-3">
                        <div class="fw-bold text-danger small mb-2 border-bottom border-secondary pb-1">${letter}</div>
                        <div class="row g-2">`;
                    
                    groupedTags[letter].forEach((tag, idx) => {
                        const safeId = `inc_${letter}_${idx}`;
                        groupHtml += `
                            <div class="col-6 col-md-3 tag-check-item" data-tag-name="${escapeHTML(tag.toLowerCase())}">
                                <div class="form-check">
                                    <input class="form-check-input adv-inc-tag" type="checkbox" value="${escapeHTML(tag)}" id="${safeId}">
                                    <label class="form-check-label small text-light" style="cursor:pointer;" for="${safeId}">${escapeHTML(tag)}</label>
                                </div>
                            </div>`;
                    });
                    groupHtml += `</div></div>`;
                    incContainer.innerHTML += groupHtml;
                });
            }

            const excContainer = document.getElementById('advExcludeTagsContainer');
            if (excContainer) {
                excContainer.innerHTML = '';
                sortedTags.forEach((tag, idx) => {
                    const safeId = `exc_${idx}`;
                    excContainer.innerHTML += `
                        <div class="form-check">
                            <input class="form-check-input adv-exc-tag" type="checkbox" value="${escapeHTML(tag)}" id="${safeId}">
                            <label class="form-check-label small text-light" style="cursor:pointer;" for="${safeId}">${escapeHTML(tag)}</label>
                        </div>`;
                });
            }
        }

        function filterTagCheckboxes() {
            const q = document.getElementById('tagQuickSearch').value.trim().toLowerCase();
            document.querySelectorAll('.tag-check-item').forEach(el => {
                const tagName = el.getAttribute('data-tag-name') || '';
                el.style.display = tagName.includes(q) ? 'block' : 'none';
            });
            document.querySelectorAll('.tag-letter-group').forEach(group => {
                const visibleItems = Array.from(group.querySelectorAll('.tag-check-item')).filter(el => el.style.display !== 'none');
                group.style.display = visibleItems.length > 0 ? 'block' : 'none';
            });
        }

        function applyAdvancedFilter() {
            const keyword = document.getElementById('advKeyword').value.trim().toLowerCase();
            const collection = document.getElementById('advCollection').value;
            const status = document.getElementById('advStatus').value;

            const includedTags = Array.from(document.querySelectorAll('.adv-inc-tag:checked')).map(cb => cb.value.toLowerCase());
            const excludedTags = Array.from(document.querySelectorAll('.adv-exc-tag:checked')).map(cb => cb.value.toLowerCase());

            const filtered = database.filter(item => {
                if (keyword) {
                    const matchTitle = item.title.toLowerCase().includes(keyword);
                    const matchUrl = item.url.toLowerCase().includes(keyword);
                    const matchContent = Object.values(item.snapshots || {}).some(s => stripHtml(s.content || '').toLowerCase().includes(keyword));
                    if (!matchTitle && !matchUrl && !matchContent) return false;
                }

                if (collection && (item.collection_name || 'Chung') !== collection) return false;
                if (status && item.status.toString() !== status) return false;

                const itemTagsLower = (item.tags || []).map(t => t.trim().toLowerCase());

                if (includedTags.length > 0) {
                    const hasAllIncluded = includedTags.every(reqTag => itemTagsLower.includes(reqTag));
                    if (!hasAllIncluded) return false;
                }

                if (excludedTags.length > 0) {
                    const hasAnyExcluded = excludedTags.some(excTag => itemTagsLower.includes(excTag));
                    if (hasAnyExcluded) return false;
                }

                return true;
            });

            closeAdvancedFilterPage();
            currentMainMode = 'saves';
            activeLibraryFolder = null;
            updateContextToolbar();

            document.querySelectorAll('.top-nav-link, .side-link').forEach(el => el.classList.remove('active'));
            document.getElementById('tabSaves').classList.add('active');
            document.getElementById('viewTitle').innerText = `Kết quả lọc nâng cao (${filtered.length} bài viết)`;
            renderCards(filtered);

            const resultsArea = document.getElementById('resultsMainArea');
            if (resultsArea) {
                resultsArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function resetAdvancedFilter() {
            document.getElementById('advKeyword').value = '';
            document.getElementById('advCollection').value = '';
            document.getElementById('advStatus').value = '';
            document.getElementById('tagQuickSearch').value = '';
            document.querySelectorAll('.adv-inc-tag, .adv-exc-tag').forEach(cb => cb.checked = false);
            filterTagCheckboxes();
            switchMainTab('all');
        }

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const colField = this.querySelector('input[name="collection_name"]');
                if (colField && colField.value.trim()) {
                    saveCustomLibraryName(colField.value.trim());
                }

                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = `
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Đang xử lý, vui lòng chờ...
                    `;
                    if (!this.checkValidity()) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }
                }
            });
        });

        updateContextToolbar();
        renderCards(database);
    </script>
</body>
</html>