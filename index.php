<?php
// index.php - Giao diện chính Pocket Saver v2 (Tích hợp Quả chuông Thông báo & Duyệt chia sẻ)
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
            if ($row['status'] == 404) {
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

        * { font-family: 'Inter', sans-serif; }
        body { background-color: #ffffff; color: #1a1a1a; font-size: 0.93rem; }

        .pocket-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; height: 60px; padding: 0 40px; }
        .pocket-logo { color: #ef4056; font-weight: 700; font-size: 1.3rem; text-decoration: none; display: flex; align-items: center; gap: 8px; }

        .top-nav-link { color: #4b5563; font-weight: 600; text-decoration: none; padding: 18px 12px; border-bottom: 3px solid transparent; cursor: pointer; }
        .top-nav-link.active { color: #000000; border-bottom-color: #008080; }

        .header-icon-btn { background: none; border: none; color: #374151; font-size: 1.1rem; padding: 8px; border-radius: 50%; position: relative; }
        .header-icon-btn:hover { background: #f3f4f6; }

        .pocket-sidebar { width: 230px; padding: 20px 0; }
        .side-link { display: flex; align-items: center; gap: 12px; padding: 8px 16px; border-radius: 8px; color: #374151; font-weight: 500; text-decoration: none; margin-bottom: 2px; cursor: pointer; }
        .side-link:hover, .side-link.active { background: #e6f4f1; color: #008080; font-weight: 600; }

        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        .pocket-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; height: 340px; }
        .pocket-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08); }
        .card-img { height: 150px; width: 100%; object-fit: cover; background: #f3f4f6; }
        .card-body-content { padding: 14px; flex: 1; display: flex; flex-direction: column; }

        .tag-badge { background-color: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; display: inline-flex; align-items: center; gap: 4px; }
        
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
        <div class="d-flex align-items-center gap-4">
            <a href="index.php" class="pocket-logo"><i class="fa-solid fa-bookmark"></i> pocket</a>
            <nav class="d-flex gap-2">
                <span id="tabSaves" class="top-nav-link active" onclick="switchMainTab('all')">Saves</span>
                <span id="tabCollections" class="top-nav-link" onclick="switchMainTab('collections')">Collections</span>
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

            <button class="header-icon-btn" title="Tìm kiếm" data-bs-toggle="collapse" data-bs-target="#searchBox">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
            <button class="header-icon-btn text-danger" title="Lưu bài mới" data-bs-toggle="modal" data-bs-target="#addModal">
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

    <!-- THANH TÌM KIẾM -->
    <div class="collapse bg-light border-bottom p-3" id="searchBox">
        <div class="container" style="max-width: 650px;">
            <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Gõ từ khóa tìm kiếm (tiêu đề, nhãn, nội dung bài viết)..." onkeyup="handleSearch()">
                <button class="btn btn-dark fw-bold px-4" onclick="handleSearch()">Tìm kiếm</button>
            </div>
        </div>
    </div>

    <!-- 2. BỐ CỤC CHÍNH -->
    <div class="container-fluid px-5 py-4">
        <div class="d-flex gap-4">

            <!-- SIDEBAR TRÁI -->
            <aside class="pocket-sidebar">
                <div id="linkAllSaves" class="side-link active" onclick="switchMainTab('all')">
                    <i class="fa-solid fa-bookmark"></i> Saves
                </div>
                <div id="linkCollections" class="side-link" onclick="switchMainTab('collections')">
                    <i class="fa-solid fa-layer-group"></i> Thư viện
                </div>

                <div class="text-uppercase text-muted fw-bold extra-small px-3 mt-4 mb-2" style="font-size: 0.72rem;">FILTERS & TAGS</div>
                <div id="sidebarTagsList"></div>
                
                <div class="text-uppercase text-muted fw-bold extra-small px-3 mt-4 mb-2" style="font-size: 0.72rem;">BỘ SƯU TẬP (COLLECTIONS)</div>
                <div id="sidebarCollectionsList"></div>
                
                <div class="text-uppercase text-muted fw-bold extra-small px-3 mt-4 mb-2" style="font-size: 0.72rem;">TRẠNG THÁI HỆ THỐNG</div>
                <div id="linkErrorPages" class="side-link text-danger" onclick="filterCategory('error')">
                    <i class="fa-solid fa-triangle-exclamation"></i> Trang lỗi / 404 <span id="errorBadge" class="badge bg-danger ms-auto">0</span>
                </div>
            </aside>

            <!-- CARD GRID -->
            <main class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 id="viewTitle" class="fw-bold m-0">Tất cả bài viết đã lưu</h5>
                    <span id="itemCount" class="text-muted fs-7">0 bài viết</span>
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
                                                <i class="fa-solid fa-check"></i> Chấp nhận
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

    <!-- 4. MODAL ĐỌC BÀI -->
    <div class="modal fade" id="readerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content p-4">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <span id="modalStatus" class="badge bg-success mb-2">HTTP 200 OK</span>
                        <h4 id="modalTitle" class="fw-bold mb-1"></h4>
                        <small id="modalSource" class="text-muted"></small>
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

    <!-- MODAL LƯU BÀI MỚI -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3">
                <div class="modal-header border-0">
                    <h5 class="fw-bold m-0"><i class="fa-solid fa-link text-danger me-2"></i>Lưu trang web mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/save.php" method="POST">
                    <input type="hidden" name="action" value="save_page">
                    <div class="modal-body">
                        <label class="form-label fw-semibold fs-7">URL trang gốc</label>
                        <input type="text" name="url" class="form-control mb-3" placeholder="Dán URL trang web / Light Novel / Truyện..." required>
                        
                        <label class="form-label fw-semibold fs-7">Bộ sưu tập</label>
                        <input type="text" name="collection_name" class="form-control mb-3" list="collectionsList" placeholder="VD: Light Novel, Truyện tranh...">
                        <datalist id="collectionsList">
                            <option value="Chung">
                            <option value="Light Novel">
                            <option value="Truyện tranh">
                            <option value="Tin tức">
                        </datalist>

                        <label class="form-label fw-semibold fs-7">Nhãn (Tags)</label>
                        <input type="text" name="tags" class="form-control mb-3" placeholder="VD: Novel, Isekai...">

                        <button type="submit" class="btn btn-success w-100 fw-bold">Cào bài & Lưu MySQL</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL SỬA BÀI -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3">
                <div class="modal-header border-0">
                    <h5 class="fw-bold m-0"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Chỉnh sửa bài viết</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="actions/edit.php" method="POST">
                    <input type="hidden" name="action" value="edit_page">
                    <input type="hidden" id="editPageId" name="page_id" value="">
                    <div class="modal-body">
                        <label class="form-label fw-semibold fs-7">Tiêu đề bài viết</label>
                        <input type="text" id="editTitleInput" name="title" class="form-control mb-3" required>

                        <label class="form-label fw-semibold fs-7">URL nguồn trang web</label>
                        <input type="text" id="editUrlInput" name="url" class="form-control mb-3" placeholder="https://..." required>

                        <label class="form-label fw-semibold fs-7">Bộ sưu tập</label>
                        <input type="text" id="editCollectionInput" name="collection_name" class="form-control mb-3" list="collectionsList" placeholder="VD: Light Novel...">
                        
                        <label class="form-label fw-semibold fs-7">Nhãn (Tags)</label>
                        <input type="text" id="editTagInput" name="tags" class="form-control mb-3">

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
        let database = <?php echo json_encode($db_articles, JSON_UNESCAPED_UNICODE); ?>;
        let activePageId = null;
        let activeVersion = 1;

        const itemsPerPage = 8;
        let currentData = [];

        function escapeHTML(str) {
            return str ? str.toString().replace(/[&<>'"]/g, tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[tag])) : '';
        }

        function renderCards(data, page = 1) {
            currentData = data;
            const grid = document.getElementById('cardGrid');
            grid.innerHTML = '';
            document.getElementById('itemCount').innerText = `${data.length} bài viết`;

            if (data.length === 0) {
                grid.innerHTML = `<div class="text-muted p-4 text-center w-100">Chưa có bài viết nào hoặc không tìm thấy kết quả.</div>`;
                const pagContainer = document.getElementById('paginationContainer');
                if (pagContainer) pagContainer.innerHTML = '';
                return;
            }

            const startIndex = (page - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedItems = data.slice(startIndex, endIndex);

            paginatedItems.forEach(item => {
                let tagsHtml = item.tags.map(t => `<span class="tag-badge">#${t}</span>`).join(' ');

                let snippetHtml = '';
                if (item.searchSnippet) {
                    snippetHtml = `
                        <div class="mt-2 p-2 bg-light rounded text-muted extra-small border-start border-3 border-warning">
                            <i class="fa-solid fa-quote-left me-1 text-warning"></i> ...${item.searchSnippet}...
                        </div>
                    `;
                }

                let sharedBadgeHtml = '';
                if (item.shared_by) {
                    sharedBadgeHtml = `
                        <div class="mt-1">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.7rem;">
                                <i class="fa-solid fa-share me-1"></i>Được chia sẻ bởi ${escapeHTML(item.shared_by)}
                            </span>
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
                const firstLetter = safeTitle ? safeTitle.trim().charAt(0).toUpperCase() : 'P';

                const fallbackGradientHtml = `
                    <div class="card-img position-relative overflow-hidden d-flex align-items-center justify-content-center text-white" style="background: ${bgStyle};">
                        <div class="position-absolute rounded-circle" style="width: 160px; height: 160px; background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0)); top: -40px; right: -40px;"></div>
                        <div class="position-absolute rounded-circle" style="width: 80px; height: 80px; background: rgba(255,255,255,0.08); bottom: 10px; left: -20px;"></div>
                        <div class="position-absolute fw-bolder" style="font-size: 8.5rem; opacity: 0.15; right: 8px; bottom: -30px; line-height: 1; user-select: none; font-family: sans-serif;">${firstLetter}</div>
                        
                        <div class="text-center position-relative p-3 w-100" style="z-index: 2;">
                            <div class="bg-white bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 44px; height: 44px; backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.35);">
                                <i class="fa-solid fa-newspaper fs-5 text-white"></i>
                            </div>
                            <div class="fw-bold px-1 text-truncate" style="font-size: 0.9rem; text-shadow: 0 2px 6px rgba(0,0,0,0.35); letter-spacing: 0.2px;">${safeTitle}</div>
                        </div>
                    </div>
                `;

                let coverHtml = '';
                if (item.image === '404') {
                    coverHtml = `
                        <div class="card-img d-flex align-items-center justify-content-center text-white" style="background: linear-gradient(135deg, #ef4444, #991b1b);">
                            <div class="text-center">
                                <i class="fa-solid fa-triangle-exclamation display-6 mb-1"></i>
                                <div class="fw-bold fs-7">Trang nguồn bị lỗi 404</div>
                            </div>
                        </div>
                    `;
                } else if (item.image) {
                    coverHtml = `<img src="${item.image}" class="card-img" alt="thumb" onerror="this.onerror=null; this.outerHTML=\`${fallbackGradientHtml.replace(/"/g, '&quot;')}\`;">`;
                } else {
                    coverHtml = fallbackGradientHtml;
                }
                
                const cardHtml = `
                    <div class="pocket-card position-relative">
                        <div class="position-absolute top-0 end-0 p-2 d-flex gap-1" style="z-index: 10;">
                            <button class="btn btn-sm btn-light border shadow-sm px-2 py-0" title="Sửa bài" onclick="event.stopPropagation(); openEditModal(${item.id})">
                                <i class="fa-solid fa-pen text-secondary fs-7"></i>
                            </button>
                            <button class="btn btn-sm btn-light border shadow-sm px-2 py-0" title="Xóa bài" onclick="event.stopPropagation(); confirmDelete(${item.id})">
                                <i class="fa-solid fa-trash text-danger fs-7"></i>
                            </button>
                        </div>
                        <div onclick="openReader(${item.id})" class="h-100 d-flex flex-column">
                            ${coverHtml}
                            <div class="card-body-content">
                                <h6 class="fw-bold mb-1 line-clamp-2 me-4">${safeTitle}</h6>
                                <p class="text-muted extra-small mb-1 text-truncate">${item.url}</p>
                                <div class="mb-1">${tagsHtml}</div>
                                ${sharedBadgeHtml}
                                ${snippetHtml}
                                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge ${item.status === 200 ? 'bg-success' : 'bg-danger'}">HTTP ${item.status}</span>
                                        <small class="text-muted" style="font-size: 0.75rem;" title="Thời gian cập nhật">
                                            <i class="fa-regular fa-clock me-1"></i>${item.created_at ? new Date(item.created_at.replace(/-/g, "/")).toLocaleString('vi-VN', {hour: '2-digit', minute:'2-digit', day:'2-digit', month:'2-digit', year:'numeric'}) : 'Vừa xong'}
                                        </small>
                                    </div>
                                    <span class="badge bg-light text-dark border">v${item.currentVersion}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                grid.innerHTML += cardHtml;
            });

            renderPagination(data.length, page);
            renderSidebarTags();
            updateErrorBadgeCount();
        }

        function renderPagination(totalItems, currentPage) {
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            const container = document.getElementById('paginationContainer');
            if (!container) return;
            
            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = `<nav><ul class="pagination justify-content-center">`;
            
            html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                        <button class="page-link text-dark fw-semibold" onclick="renderCards(currentData, ${currentPage - 1})">Trước</button>
                     </li>`;

            for (let i = 1; i <= totalPages; i++) {
                const activeStyle = currentPage === i ? 'background-color: #008080; color: white; border-color: #008080;' : 'color: #374151;';
                html += `<li class="page-item ${currentPage === i ? 'active' : ''}">
                            <button class="page-link fw-semibold" style="${activeStyle}" onclick="renderCards(currentData, ${i})">${i}</button>
                         </li>`;
            }

            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <button class="page-link text-dark fw-semibold" onclick="renderCards(currentData, ${currentPage + 1})">Sau</button>
                     </li>`;
            
            html += `</ul></nav>`;
            container.innerHTML = html;
        }

        function updateErrorBadgeCount() {
            document.getElementById('errorBadge').innerText = database.filter(i => i.status === 404).length;
        }

        function renderSidebarTags() {
            const container = document.getElementById('sidebarTagsList');
            let allTags = new Set();
            database.forEach(item => item.tags.forEach(t => allTags.add(t)));
            container.innerHTML = '';
            allTags.forEach(tag => {
                container.innerHTML += `<div class="side-link" onclick="filterTag('${tag}')"><i class="fa-solid fa-tag"></i> #${tag}</div>`;
            });
        }

        function openReader(id) {
            const item = database.find(p => p.id === id);
            if (!item) return;

            activePageId = id;
            activeVersion = item.currentVersion;
    
            document.getElementById('notePageId').value = id;
            document.getElementById('sharePageId').value = id;
            document.getElementById('modalTitle').innerText = item.title;
            document.getElementById('modalSource').innerText = "Trang nguồn gốc: " + item.url;
            document.getElementById('modalStatus').innerText = "HTTP " + item.status + " " + (item.status === 200 ? "OK" : "Error");
            document.getElementById('modalStatus').className = `badge ${item.status === 200 ? 'bg-success' : 'bg-danger'}`;
            document.getElementById('modalTagsContainer').innerHTML = item.tags.map(t => `<span class="tag-badge">#${t}</span>`).join(' ');

            const versionButtonsContainer = document.getElementById('versionButtonsContainer'); 
            if (versionButtonsContainer) {
                versionButtonsContainer.innerHTML = '<span class="fw-semibold fs-7 me-2">Lịch sử Phiên bản:</span>';

                Object.keys(item.snapshots).forEach(v => {
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
            const snap = item.snapshots[activeVersion];

            Object.keys(item.snapshots).forEach(v => {
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

        function openEditModal(id) {
            const item = database.find(p => p.id === id);
            if (!item) return;

            document.getElementById('editPageId').value = item.id;
            document.getElementById('editTitleInput').value = item.title;
            document.getElementById('editUrlInput').value = item.url;
            document.getElementById('editCollectionInput').value = item.collection_name || 'Chung';
            document.getElementById('editTagInput').value = item.tags.join(', ');
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
            document.querySelectorAll('.top-nav-link, .side-link').forEach(el => el.classList.remove('active'));
            if (type === 'all') {
                document.getElementById('tabSaves').classList.add('active');
                document.getElementById('linkAllSaves').classList.add('active');
                document.getElementById('viewTitle').innerText = "Tất cả bài viết đã lưu";
                renderCards(database);
            } else if (type === 'collections') {
                document.getElementById('tabCollections').classList.add('active');
                document.getElementById('linkCollections').classList.add('active');
                document.getElementById('viewTitle').innerText = "Thư viện Bộ sưu tập";
                renderFolderView();
            }
        }

        function renderFolderView() {
            const grid = document.getElementById('cardGrid');
            grid.innerHTML = '';

            let collectionsMap = {};
            database.forEach(item => {
                const colName = item.collection_name || 'Chung';
                if (!collectionsMap[colName]) {
                    collectionsMap[colName] = [];
                }
                collectionsMap[colName].push(item);
            });

            const keys = Object.keys(collectionsMap);
            document.getElementById('itemCount').innerText = `${keys.length} bộ sưu tập`;

            if (keys.length === 0) {
                grid.innerHTML = `<div class="text-muted p-5 text-center w-100 bg-white rounded-4 border shadow-sm">Chưa có bộ sưu tập nào được tạo.</div>`;
                return;
            }

            keys.forEach(col => {
                const count = collectionsMap[col].length;
                const folderHtml = `
                    <div class="folder-card" onclick="filterCollection('${col}')">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="folder-icon-box">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <span class="folder-count-badge">
                                ${count} bài viết
                            </span>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-1" style="font-size: 1.15rem; letter-spacing: -0.2px;">${col}</h5>
                            <p class="text-muted m-0" style="font-size: 0.82rem;">
                                Khám phá nội dung <i class="fa-solid fa-arrow-right-long ms-1" style="color: #0d9488;"></i>
                            </p>
                        </div>
                    </div>
                `;
                grid.innerHTML += folderHtml;
            });

            const pagContainer = document.getElementById('paginationContainer');
            if (pagContainer) pagContainer.innerHTML = '';
        }

        function filterCategory(cat) {
            document.querySelectorAll('.side-link').forEach(el => el.classList.remove('active'));
            if (cat === 'error') {
                document.getElementById('linkErrorPages').classList.add('active');
                document.getElementById('viewTitle').innerText = "Danh sách Trang lỗi / 404";
                renderCards(database.filter(i => i.status === 404));
            }
        }

        function filterTag(tag) {
            document.getElementById('viewTitle').innerText = `Bài viết đính kèm nhãn #${tag}`;
            renderCards(database.filter(i => i.tags.includes(tag)));
        }

        function stripHtml(html) {
            let tmp = document.createElement("DIV");
            tmp.innerHTML = html;
            return tmp.textContent || tmp.innerText || "";
        }

        function handleSearch() {
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
                const matchTags = item.tags.some(t => t.toLowerCase().includes(query));

                if (matchTitle || matchUrl || matchTags) {
                    matched = true;
                }

                Object.keys(item.snapshots).forEach(ver => {
                    const rawContent = item.snapshots[ver].content || '';
                    const textContent = stripHtml(rawContent);
                    const lowerText = textContent.toLowerCase();
                    const index = lowerText.indexOf(query);

                    if (index !== -1 && !snippet) {
                        matched = true;
                        const start = Math.max(0, index - 40);
                        const end = Math.min(textContent.length, index + query.length + 40);
                        let rawSnippet = textContent.substring(start, end);
                        const regex = new RegExp(`(${query})`, 'gi');
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

        function renderSidebarCollections() {
            const container = document.getElementById('sidebarCollectionsList');
            if (!container) return;

            let collections = [...new Set(database.map(item => item.collection_name || 'Chung'))];
            
            let html = '';
            collections.forEach(col => {
                html += `
                    <div class="side-link text-truncate" onclick="filterCollection('${col}')">
                        <i class="fa-solid fa-folder me-2 text-warning"></i> ${col}
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function filterCollection(colName) {
            document.querySelectorAll('.side-link').forEach(el => el.classList.remove('active'));
            document.getElementById('viewTitle').innerText = `Bộ sưu tập: ${colName}`;
            renderCards(database.filter(i => i.collection_name === colName));
        }

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
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

        renderCards(database);
        renderSidebarCollections();
    </script>
</body>
</html>