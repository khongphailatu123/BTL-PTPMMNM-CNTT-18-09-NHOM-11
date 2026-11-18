<?php
// admin.php - Bảng Quản Trị Hệ Thống Pocket Saver (Tách trang độc lập, STT chuẩn, đồng bộ Light/Dark Mode)
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Kiểm tra đăng nhập & quyền Admin (RBAC)
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$current_user_id = (int)$_SESSION['user_id'];
$stmtAdmin = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
$stmtAdmin->bind_param("i", $current_user_id);
$stmtAdmin->execute();
$adminInfo = $stmtAdmin->get_result()->fetch_assoc();
$stmtAdmin->close();

if (!$adminInfo || ($adminInfo['role'] ?? 'user') !== 'admin') {
    header("Location: index.php");
    exit();
}

// Xác định Tab hiện tại (mặc định là dashboard)
$allowedTabs = ['dashboard', 'users', 'articles', 'shares', 'errors'];
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}

// Hàm hỗ trợ xóa sạch thư mục ảnh của 1 bài viết trên server
function cleanArticleAssets(int $articleId): void {
    foreach (glob('assets/item_' . $articleId . '*') ?: [] as $folder) {
        if (is_dir($folder)) {
            foreach (glob($folder . '/*') ?: [] as $file) {
                if (is_file($file)) @unlink($file);
            }
            @rmdir($folder);
        }
    }
    foreach (glob('assets/covers/cover_' . $articleId . '.*') ?: [] as $cover) {
        if (is_file($cover)) @unlink($cover);
    }
}

// 2. Xử lý các hành động POST từ Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectTab = $_POST['redirect_tab'] ?? $tab;

    if ($action === 'add_user') {
        $newUsername = trim($_POST['username'] ?? '');
        $newEmail    = trim($_POST['email'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $newRole     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($newUsername === '' || $newPassword === '') {
            $_SESSION['admin_msg'] = ['type' => 'danger', 'text' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!'];
        } else {
            $stmtCheck = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmtCheck->bind_param("s", $newUsername);
            $stmtCheck->execute();
            if ($stmtCheck->get_result()->num_rows > 0) {
                $_SESSION['admin_msg'] = ['type' => 'danger', 'text' => 'Tên đăng nhập "' . htmlspecialchars($newUsername) . '" đã tồn tại!'];
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $emailVal = $newEmail !== '' ? $newEmail : null;
                $stmtIns = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
                $stmtIns->bind_param("ssss", $newUsername, $emailVal, $hashed, $newRole);
                $stmtIns->execute();
                $stmtIns->close();
                $_SESSION['admin_msg'] = ['type' => 'success', 'text' => 'Đã tạo tài khoản "' . htmlspecialchars($newUsername) . '" thành công!'];
            }
            $stmtCheck->close();
        }
    } elseif ($action === 'edit_user') {
        $targetId     = (int)($_POST['user_id'] ?? 0);
        $editUsername = trim($_POST['username'] ?? '');
        $editEmail    = trim($_POST['email'] ?? '');
        $editPassword = $_POST['password'] ?? '';
        $editRole     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        // Không cho tự hạ quyền chính mình
        if ($targetId === $current_user_id) {
            $editRole = 'admin';
        }

        if ($targetId > 0 && $editUsername !== '') {
            $stmtDup = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmtDup->bind_param("si", $editUsername, $targetId);
            $stmtDup->execute();
            if ($stmtDup->get_result()->num_rows > 0) {
                $_SESSION['admin_msg'] = ['type' => 'danger', 'text' => 'Tên đăng nhập này đã được tài khoản khác sử dụng!'];
            } else {
                $emailVal = $editEmail !== '' ? $editEmail : null;
                if ($editPassword !== '') {
                    $hashed = password_hash($editPassword, PASSWORD_DEFAULT);
                    $stmtUpd = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ? WHERE id = ?");
                    $stmtUpd->bind_param("ssssi", $editUsername, $emailVal, $hashed, $editRole, $targetId);
                } else {
                    $stmtUpd = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                    $stmtUpd->bind_param("sssi", $editUsername, $emailVal, $editRole, $targetId);
                }
                $stmtUpd->execute();
                $stmtUpd->close();

                if ($targetId === $current_user_id) {
                    $_SESSION['username'] = $editUsername;
                }
                $_SESSION['admin_msg'] = ['type' => 'success', 'text' => 'Cập nhật thông tin tài khoản thành công!'];
            }
            $stmtDup->close();
        }
    } elseif ($action === 'delete_user') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId > 0 && $targetId !== $current_user_id) {
            $resArt = $conn->query("SELECT id FROM articles WHERE user_id = $targetId");
            if ($resArt) {
                while ($a = $resArt->fetch_assoc()) {
                    cleanArticleAssets((int)$a['id']);
                }
            }
            $conn->query("DELETE FROM article_shares WHERE sender_id = $targetId OR receiver_id = $targetId");
            $conn->query("DELETE s FROM snapshots s JOIN articles a ON s.article_id = a.id WHERE a.user_id = $targetId");
            $conn->query("DELETE FROM articles WHERE user_id = $targetId");

            $stmtDel = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmtDel->bind_param("i", $targetId);
            $stmtDel->execute();
            $stmtDel->close();

            $_SESSION['admin_msg'] = ['type' => 'success', 'text' => 'Đã xóa tài khoản cùng toàn bộ dữ liệu liên quan!'];
        }
    } elseif ($action === 'delete_article') {
        $articleId = (int)($_POST['article_id'] ?? 0);
        if ($articleId > 0) {
            cleanArticleAssets($articleId);
            $conn->query("DELETE FROM article_shares WHERE article_id = $articleId");
            $conn->query("DELETE FROM snapshots WHERE article_id = $articleId");
            $conn->query("DELETE FROM articles WHERE id = $articleId");
            $_SESSION['admin_msg'] = ['type' => 'success', 'text' => 'Đã xóa bài viết và dọn sạch tệp ảnh trên máy chủ!'];
        }
    } elseif ($action === 'delete_share') {
        $shareId = (int)($_POST['share_id'] ?? 0);
        if ($shareId > 0) {
            $stmtDelShare = $conn->prepare("DELETE FROM article_shares WHERE id = ?");
            $stmtDelShare->bind_param("i", $shareId);
            $stmtDelShare->execute();
            $stmtDelShare->close();
            $_SESSION['admin_msg'] = ['type' => 'success', 'text' => 'Đã thu hồi lượt chia sẻ nội bộ!'];
        }
    }

    header("Location: admin.php?tab=" . urlencode($redirectTab));
    exit();
}

$flashMsg = $_SESSION['admin_msg'] ?? null;
unset($_SESSION['admin_msg']);

// 3. Thống kê tổng quan dùng chung
$totalUsers     = (int)($conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'] ?? 0);
$totalArticles  = (int)($conn->query("SELECT COUNT(*) AS c FROM articles")->fetch_assoc()['c'] ?? 0);
$totalSnapshots = (int)($conn->query("SELECT COUNT(*) AS c FROM snapshots")->fetch_assoc()['c'] ?? 0);
$totalShares    = (int)($conn->query("SELECT COUNT(*) AS c FROM article_shares")->fetch_assoc()['c'] ?? 0);
$totalError404  = (int)($conn->query("SELECT COUNT(*) AS c FROM articles WHERE status = 404")->fetch_assoc()['c'] ?? 0);

// 4. Truy vấn dữ liệu riêng cho từng trang (tab)
$searchQuery  = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if ($tab === 'dashboard') {
    $recentArticles = $conn->query("
        SELECT a.id, a.title, a.status, a.created_at, u.username,
               (SELECT COUNT(*) FROM snapshots s WHERE s.article_id = a.id) AS ver_count
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.id DESC
        LIMIT 6
    ");
    $recentErrors = $conn->query("
        SELECT a.id, a.url, a.created_at, u.username
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.status = 404
        ORDER BY a.id DESC
        LIMIT 5
    ");
} elseif ($tab === 'users') {
    if ($searchQuery !== '') {
        $like = '%' . $searchQuery . '%';
        $stmtU = $conn->prepare("
            SELECT u.id, u.username, u.email, u.role, COUNT(a.id) AS article_count
            FROM users u
            LEFT JOIN articles a ON u.id = a.user_id
            WHERE u.username LIKE ? OR u.email LIKE ?
            GROUP BY u.id
            ORDER BY u.id ASC
        ");
        $stmtU->bind_param("ss", $like, $like);
        $stmtU->execute();
        $usersList = $stmtU->get_result();
    } else {
        $usersList = $conn->query("
            SELECT u.id, u.username, u.email, u.role, COUNT(a.id) AS article_count
            FROM users u
            LEFT JOIN articles a ON u.id = a.user_id
            GROUP BY u.id
            ORDER BY u.id ASC
        ");
    }
} elseif ($tab === 'articles') {
    $whereClauses = [];
    $params = [];
    $types = '';

    if ($searchQuery !== '') {
        $whereClauses[] = "(a.title LIKE ? OR a.url LIKE ? OR u.username LIKE ?)";
        $like = '%' . $searchQuery . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }
    if ($statusFilter === '200') {
        $whereClauses[] = "a.status = 200";
    } elseif ($statusFilter === '404') {
        $whereClauses[] = "a.status = 404";
    }

    $whereSql = !empty($whereClauses) ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';
    $sqlArt = "
        SELECT a.id, a.title, a.url, a.collection_name, a.status, a.created_at, u.username,
               (SELECT MAX(version) FROM snapshots s WHERE s.article_id = a.id) AS latest_ver,
               (SELECT COUNT(*) FROM snapshots s WHERE s.article_id = a.id) AS ver_count
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        $whereSql
        ORDER BY a.id DESC
    ";
    if (!empty($params)) {
        $stmtA = $conn->prepare($sqlArt);
        $stmtA->bind_param($types, ...$params);
        $stmtA->execute();
        $articlesList = $stmtA->get_result();
    } else {
        $articlesList = $conn->query($sqlArt);
    }
} elseif ($tab === 'shares') {
    $sharesList = $conn->query("
        SELECT sh.id, sh.status,
               a.id AS article_id, a.title AS article_title,
               u_send.username AS sender_name,
               u_recv.username AS receiver_name
        FROM article_shares sh
        LEFT JOIN articles a ON sh.article_id = a.id
        LEFT JOIN users u_send ON sh.sender_id = u_send.id
        LEFT JOIN users u_recv ON sh.receiver_id = u_recv.id
        ORDER BY sh.id DESC
    ");
} elseif ($tab === 'errors') {
    $errorsList = $conn->query("
        SELECT a.id, a.title, a.url, a.created_at, u.username
        FROM articles a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.status = 404
        ORDER BY a.id DESC
    ");
}

$tabTitles = [
    'dashboard' => 'Dashboard',
    'users'     => 'Quản Lý Người Dùng',
    'articles'  => 'Tất Cả Bài Viết Đã Cào',
    'shares'    => 'Lịch Sử Chia Sẻ Nội Bộ',
    'errors'    => 'Quản Lý Trang Lỗi (HTTP 404)'
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tabTitles[$tab]; ?> - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Đồng bộ Theme Sáng / Tối với index.php -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pocket_theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', savedTheme || (prefersDark ? 'dark' : 'light'));
        })();
    </script>

    <style>
        :root, [data-theme="light"] {
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-topbar: #ffffff;
            --bg-input: #f8fafc;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --sidebar-bg: #2c3e50;
            --sidebar-hover: rgba(255, 255, 255, 0.08);
            --sidebar-active: rgba(255, 255, 255, 0.16);
        }
        [data-theme="dark"] {
            --bg-body: #0b1120;
            --bg-surface: #1e293b;
            --bg-topbar: #162032;
            --bg-input: #0f172a;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255, 255, 255, 0.06);
            --sidebar-active: rgba(239, 64, 86, 0.2);
        }

        * { font-family: 'Inter', sans-serif; }
        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            margin: 0;
            min-height: 100vh;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar trái */
        .admin-sidebar {
            width: 245px;
            flex-shrink: 0;
            background-color: var(--sidebar-bg);
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            padding: 22px 14px;
            border-right: 1px solid rgba(255,255,255,0.08);
        }
        .sidebar-brand {
            font-size: 1.3rem;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 12px 18px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            margin-bottom: 16px;
            text-decoration: none;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 14px;
            border-radius: 8px;
            color: #e2e8f0;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 500;
            margin-bottom: 5px;
            transition: all 0.18s ease;
        }
        .sidebar-link:hover {
            background-color: var(--sidebar-hover);
            color: #ffffff;
        }
        .sidebar-link.active {
            background-color: var(--sidebar-active);
            color: #ffffff;
            font-weight: 700;
            border-left: 3px solid #38bdf8;
        }

        /* Khu vực nội dung phải */
        .admin-content-area {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .admin-topbar {
            background-color: var(--bg-topbar);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .admin-main {
            padding: 26px 30px;
            flex-grow: 1;
        }

        /* 4 Thẻ thống kê Gradient */
        .stat-card {
            border-radius: 14px;
            padding: 20px 22px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 20px -6px rgba(0, 0, 0, 0.22);
            text-decoration: none;
            display: block;
            transition: transform 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-3px); color: #ffffff; }
        .stat-card .stat-label { font-size: 0.88rem; font-weight: 600; opacity: 0.92; margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 2rem; font-weight: 800; line-height: 1.1; }
        .stat-card .stat-icon {
            position: absolute;
            right: 16px;
            bottom: 12px;
            font-size: 2.8rem;
            opacity: 0.18;
        }
        .grad-purple { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); }
        .grad-green  { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .grad-pink   { background: linear-gradient(135deg, #ec4899 0%, #f43f5e 100%); }
        .grad-blue   { background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); }

        /* Card & Table */
        .panel-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border-color);
        }
        .form-control, .form-select {
            background-color: var(--bg-input);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bg-input);
            color: var(--text-primary);
            border-color: #3b82f6;
            box-shadow: none;
        }
        .table-custom {
            color: var(--text-primary) !important;
            margin-bottom: 0;
        }
        .table-custom th {
            color: var(--text-secondary) !important;
            font-size: 0.84rem;
            font-weight: 700;
            border-bottom-color: var(--border-color) !important;
            background: transparent !important;
            padding: 12px 10px;
        }
        .table-custom td {
            color: var(--text-primary) !important;
            border-bottom-color: var(--border-color) !important;
            background: transparent !important;
            font-size: 0.9rem;
            padding: 12px 10px;
        }
        .modal-content {
            background-color: var(--bg-surface);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        @media (max-width: 992px) {
            .admin-wrapper { flex-direction: column; }
            .admin-sidebar { width: 100%; }
        }
    </style>
</head>
<body>

<div class="admin-wrapper">
    <!-- SIDEBAR TRÁI -->
    <aside class="admin-sidebar">
        <a href="admin.php?tab=dashboard" class="sidebar-brand">
            <i class="fa-solid fa-bookmark text-danger"></i>
            <span>Admin Panel</span>
        </a>

        <a href="admin.php?tab=dashboard" class="sidebar-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="admin.php?tab=users" class="sidebar-link <?php echo $tab === 'users' ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i> Quản Lý Người Dùng
        </a>
        <a href="admin.php?tab=articles" class="sidebar-link <?php echo $tab === 'articles' ? 'active' : ''; ?>">
            <i class="fa-solid fa-newspaper"></i> Bài Viết Đã Cào
        </a>
        <a href="admin.php?tab=shares" class="sidebar-link <?php echo $tab === 'shares' ? 'active' : ''; ?>">
            <i class="fa-solid fa-share-nodes"></i> Chia Sẻ Nội Bộ
        </a>
        <a href="admin.php?tab=errors" class="sidebar-link <?php echo $tab === 'errors' ? 'active' : ''; ?>">
            <i class="fa-solid fa-triangle-exclamation text-warning"></i> Trang Lỗi 404
            <?php if ($totalError404 > 0): ?>
                <span class="badge bg-danger ms-auto"><?php echo $totalError404; ?></span>
            <?php endif; ?>
        </a>

        <hr style="border-color: rgba(255,255,255,0.14); margin: 16px 0;">

        <a href="index.php" class="sidebar-link">
            <i class="fa-solid fa-house"></i> Về Trang Chủ
        </a>
        <a href="auth/logout.php" class="sidebar-link text-danger">
            <i class="fa-solid fa-right-from-bracket"></i> Đăng Xuất
        </a>
    </aside>

    <!-- KHU VỰC BÊN PHẢI -->
    <div class="admin-content-area">
        <!-- Topbar -->
        <header class="admin-topbar">
            <h4 class="fw-bold m-0"><?php echo $tabTitles[$tab]; ?></h4>
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="toggleAdminTheme()">
                    <i id="adminThemeIcon" class="fa-solid fa-moon me-1"></i> Giao diện
                </button>
                <span class="fw-semibold small" style="color: var(--text-secondary);">
                    <i class="fa-solid fa-user-shield text-primary me-1"></i>
                    <?php echo htmlspecialchars($adminInfo['email'] ?: $adminInfo['username']); ?>
                </span>
            </div>
        </header>

        <main class="admin-main">
            <?php if ($flashMsg): ?>
                <div class="alert alert-<?php echo $flashMsg['type']; ?> alert-dismissible fade show rounded-3" role="alert">
                    <?php echo $flashMsg['text']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- =========================================================
                 TRANG 1: DASHBOARD (TỔNG QUAN)
                 ========================================================= -->
            <?php if ($tab === 'dashboard'): ?>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-xl-3">
                        <a href="admin.php?tab=users" class="stat-card grad-purple">
                            <div class="stat-label">Tổng Người Dùng</div>
                            <div class="stat-value"><?php echo $totalUsers; ?></div>
                            <i class="fa-solid fa-users stat-icon"></i>
                        </a>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <a href="admin.php?tab=articles" class="stat-card grad-green">
                            <div class="stat-label">Tổng Bài Viết Đã Cào</div>
                            <div class="stat-value"><?php echo $totalArticles; ?></div>
                            <i class="fa-solid fa-book-bookmark stat-icon"></i>
                        </a>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <a href="admin.php?tab=articles" class="stat-card grad-pink">
                            <div class="stat-label">Tổng Phiên Bản Snapshot</div>
                            <div class="stat-value"><?php echo $totalSnapshots; ?></div>
                            <i class="fa-solid fa-layer-group stat-icon"></i>
                        </a>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <a href="admin.php?tab=shares" class="stat-card grad-blue">
                            <div class="stat-label">Lượt Chia Sẻ Nội Bộ</div>
                            <div class="stat-value"><?php echo $totalShares; ?></div>
                            <i class="fa-solid fa-share-nodes stat-icon"></i>
                        </a>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="panel-card h-100">
                            <div class="panel-header">
                                <h6 class="fw-bold m-0">Bài Viết Mới Cào Gần Đây</h6>
                                <a href="admin.php?tab=articles" class="btn btn-primary btn-sm">Xem Tất Cả</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>STT</th>
                                            <th>Tiêu đề bài viết</th>
                                            <th>Người lưu</th>
                                            <th>Phiên bản</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($recentArticles && $recentArticles->num_rows > 0): ?>
                                            <?php $stt = 1; while ($art = $recentArticles->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="fw-bold"><?php echo $stt++; ?></td>
                                                    <td class="text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($art['title']); ?>">
                                                        <?php echo htmlspecialchars($art['title']); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($art['username'] ?? 'N/A'); ?></td>
                                                    <td><span class="badge bg-secondary">v<?php echo (int)$art['ver_count']; ?></span></td>
                                                    <td>
                                                        <?php if ((int)$art['status'] === 200): ?>
                                                            <span class="badge bg-success">Thành công</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Lỗi <?php echo (int)$art['status']; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center py-4" style="color: var(--text-muted);">Chưa có bài viết nào.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="panel-card h-100">
                            <div class="panel-header">
                                <h6 class="fw-bold m-0">Cảnh Báo Trang Lỗi 404</h6>
                                <a href="admin.php?tab=errors" class="btn btn-primary btn-sm">Xem Tất Cả</a>
                            </div>
                            <?php if ($recentErrors && $recentErrors->num_rows > 0): ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php while ($err = $recentErrors->fetch_assoc()): ?>
                                        <div class="p-2 rounded border" style="border-color: var(--border-color) !important;">
                                            <div class="small fw-semibold text-truncate text-danger"><?php echo htmlspecialchars($err['url']); ?></div>
                                            <small style="color: var(--text-muted);">Người cào: <?php echo htmlspecialchars($err['username'] ?? 'N/A'); ?></small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5" style="color: var(--text-muted);">
                                    Tất cả bài viết đều hoạt động bình thường
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <!-- =========================================================
                 TRANG 2: QUẢN LÝ NGƯỜI DÙNG
                 ========================================================= -->
            <?php elseif ($tab === 'users'): ?>
                <div class="panel-card">
                    <div class="panel-header">
                        <h6 class="fw-bold m-0">Danh Sách Người Dùng</h6>
                        <button type="button" class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fa-solid fa-plus me-1"></i> Thêm Người Dùng
                        </button>
                    </div>

                    <!-- Thanh tìm kiếm -->
                    <form method="GET" action="admin.php" class="row g-2 mb-4">
                        <input type="hidden" name="tab" value="users">
                        <div class="col-md-6 col-lg-5">
                            <input type="text" name="q" class="form-control" placeholder="Tìm kiếm theo tên đăng nhập, email..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary px-4">Tìm Kiếm</button>
                            <?php if ($searchQuery !== ''): ?>
                                <a href="admin.php?tab=users" class="btn btn-outline-secondary ms-1">Xóa lọc</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">STT</th>
                                    <th>Tên đăng nhập</th>
                                    <th>Email</th>
                                    <th>Số bài đã cào</th>
                                    <th>Vai Trò</th>
                                    <th style="width: 160px;">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($usersList && $usersList->num_rows > 0): ?>
                                    <?php $stt = 1; while ($u = $usersList->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo $stt++; ?></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($u['email'] ?: 'Chưa cập nhật'); ?></td>
                                            <td><?php echo (int)$u['article_count']; ?> bài</td>
                                            <td>
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <span class="badge bg-danger px-2 py-1">Admin</span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary px-2 py-1">User</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-warning btn-sm fw-semibold text-dark me-1"
                                                    onclick="openEditUserModal(
                                                        <?php echo (int)$u['id']; ?>,
                                                        '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>',
                                                        '<?php echo htmlspecialchars($u['email'] ?? '', ENT_QUOTES); ?>',
                                                        '<?php echo htmlspecialchars($u['role'], ENT_QUOTES); ?>',
                                                        <?php echo ((int)$u['id'] === $current_user_id) ? 'true' : 'false'; ?>
                                                    )">
                                                    Sửa
                                                </button>

                                                <?php if ((int)$u['id'] !== $current_user_id): ?>
                                                    <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Xóa tài khoản này sẽ xóa toàn bộ bài viết và ảnh của họ?');">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="redirect_tab" value="users">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm fw-semibold">Xóa</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-4" style="color: var(--text-muted);">Không tìm thấy người dùng nào phù hợp.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Modal Thêm Người Dùng -->
                <div class="modal fade" id="addUserModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form action="admin.php" method="POST">
                                <input type="hidden" name="action" value="add_user">
                                <input type="hidden" name="redirect_tab" value="users">
                                <div class="modal-header border-bottom-0">
                                    <h5 class="modal-title fw-bold">Thêm Người Dùng Mới</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Tên đăng nhập *</label>
                                        <input type="text" name="username" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Email (tùy chọn)</label>
                                        <input type="email" name="email" class="form-control" placeholder="example@gmail.com">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Mật khẩu *</label>
                                        <input type="password" name="password" class="form-control" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold">Vai trò (Role)</label>
                                        <select name="role" class="form-select">
                                            <option value="user">User (Người dùng thường)</option>
                                            <option value="admin">Admin (Quản trị viên)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer border-top-0">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                                    <button type="submit" class="btn btn-primary btn-sm px-4">Tạo Tài Khoản</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal Sửa Người Dùng -->
                <div class="modal fade" id="editUserModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form action="admin.php" method="POST">
                                <input type="hidden" name="action" value="edit_user">
                                <input type="hidden" name="redirect_tab" value="users">
                                <input type="hidden" name="user_id" id="edit_user_id">
                                <div class="modal-header border-bottom-0">
                                    <h5 class="modal-title fw-bold">Sửa Thông Tin Người Dùng</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Tên đăng nhập *</label>
                                        <input type="text" name="username" id="edit_username" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Email</label>
                                        <input type="email" name="email" id="edit_email" class="form-control">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Mật khẩu mới (để trống nếu không đổi)</label>
                                        <input type="password" name="password" class="form-control" placeholder="Nhập mật khẩu mới...">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold">Vai trò (Role)</label>
                                        <select name="role" id="edit_role" class="form-select">
                                            <option value="user">User (Người dùng thường)</option>
                                            <option value="admin">Admin (Quản trị viên)</option>
                                        </select>
                                        <small id="selfRoleNote" class="text-muted d-none">Bạn không thể tự hạ quyền tài khoản Admin đang đăng nhập.</small>
                                    </div>
                                </div>
                                <div class="modal-footer border-top-0">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                                    <button type="submit" class="btn btn-warning btn-sm fw-bold px-4">Lưu Thay Đổi</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            <!-- =========================================================
                 TRANG 3: TẤT CẢ BÀI VIẾT ĐÃ CÀO TRÊN HỆ THỐNG
                 ========================================================= -->
            <?php elseif ($tab === 'articles'): ?>
                <div class="panel-card">
                    <div class="panel-header">
                        <h6 class="fw-bold m-0">Danh Sách Toàn Bộ Bài Viết Đã Cào (<?php echo $totalArticles; ?> bài)</h6>
                    </div>

                    <form method="GET" action="admin.php" class="row g-2 mb-4">
                        <input type="hidden" name="tab" value="articles">
                        <div class="col-md-5">
                            <input type="text" name="q" class="form-control" placeholder="Tìm theo tiêu đề, URL hoặc người cào..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                                <option value="200" <?php echo $statusFilter === '200' ? 'selected' : ''; ?>>HTTP 200 (Thành công)</option>
                                <option value="404" <?php echo $statusFilter === '404' ? 'selected' : ''; ?>>HTTP 404 (Lỗi nguồn)</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary px-4">Lọc Dữ Liệu</button>
                            <?php if ($searchQuery !== '' || $statusFilter !== 'all'): ?>
                                <a href="admin.php?tab=articles" class="btn btn-outline-secondary ms-1">Đặt lại</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">STT</th>
                                    <th>Tiêu đề bài viết & URL gốc</th>
                                    <th>Người cào</th>
                                    <th>Thư viện</th>
                                    <th>Snapshot</th>
                                    <th>Trạng thái</th>
                                    <th style="width: 140px;">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($articlesList && $articlesList->num_rows > 0): ?>
                                    <?php $stt = 1; while ($art = $articlesList->fetch_assoc()): ?>
                                        <?php $latestVer = max(1, (int)$art['latest_ver']); ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo $stt++; ?></td>
                                            <td style="max-width: 300px;">
                                                <div class="fw-semibold text-truncate" title="<?php echo htmlspecialchars($art['title']); ?>">
                                                    <?php echo htmlspecialchars($art['title']); ?>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($art['url']); ?>" target="_blank" class="small text-decoration-none d-block text-truncate" style="color: var(--text-muted);">
                                                    <?php echo htmlspecialchars($art['url']); ?>
                                                </a>
                                            </td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($art['username'] ?? 'N/A'); ?></td>
                                            <td><span class="badge bg-info-subtle text-info border border-info-subtle"><?php echo htmlspecialchars($art['collection_name'] ?: 'Chung'); ?></span></td>
                                            <td><span class="badge bg-secondary"><?php echo (int)$art['ver_count']; ?> bản (v<?php echo $latestVer; ?>)</span></td>
                                            <td>
                                                <?php if ((int)$art['status'] === 200): ?>
                                                    <span class="badge bg-success">HTTP 200</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Lỗi <?php echo (int)$art['status']; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="share.php?id=<?php echo $art['id']; ?>&v=<?php echo $latestVer; ?>" target="_blank" class="btn btn-info btn-sm text-white fw-semibold me-1" title="Xem bài đã cào">
                                                    Xem
                                                </a>
                                                <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Xóa vĩnh viễn bài viết này và toàn bộ ảnh đã tải?');">
                                                    <input type="hidden" name="action" value="delete_article">
                                                    <input type="hidden" name="redirect_tab" value="articles">
                                                    <input type="hidden" name="article_id" value="<?php echo $art['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Xóa</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center py-4" style="color: var(--text-muted);">Không có bài viết nào phù hợp.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- =========================================================
                 TRANG 4: LỊCH SỬ CHIA SẺ NỘI BỘ (ĐÃ FIX LỖI CỘT CREATED_AT)
                 ========================================================= -->
            <?php elseif ($tab === 'shares'): ?>
                <div class="panel-card">
                    <div class="panel-header">
                        <h6 class="fw-bold m-0">Lịch Sử Chia Sẻ Bài Viết Nội Bộ (<?php echo $totalShares; ?> lượt)</h6>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">STT</th>
                                    <th>Bài viết được chia sẻ</th>
                                    <th>Người gửi</th>
                                    <th>Người nhận</th>
                                    <th>Trạng thái</th>
                                    <th style="width: 110px;">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($sharesList && $sharesList->num_rows > 0): ?>
                                    <?php $stt = 1; while ($sh = $sharesList->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo $stt++; ?></td>
                                            <td class="fw-semibold text-truncate" style="max-width: 280px;">
                                                <?php echo htmlspecialchars($sh['article_title'] ?? 'Bài viết đã bị xóa'); ?>
                                            </td>
                                            <td><span class="badge bg-primary-subtle text-primary"><?php echo htmlspecialchars($sh['sender_name'] ?? 'N/A'); ?></span></td>
                                            <td><span class="badge bg-info-subtle text-info"><?php echo htmlspecialchars($sh['receiver_name'] ?? 'N/A'); ?></span></td>
                                            <td>
                                                <?php if ($sh['status'] === 'accepted'): ?>
                                                    <span class="badge bg-success">Đã chấp nhận</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Đang chờ duyệt</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Thu hồi lượt chia sẻ này?');">
                                                    <input type="hidden" name="action" value="delete_share">
                                                    <input type="hidden" name="redirect_tab" value="shares">
                                                    <input type="hidden" name="share_id" value="<?php echo $sh['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Thu hồi</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-4" style="color: var(--text-muted);">Chưa có lượt chia sẻ nội bộ nào trong hệ thống.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- =========================================================
                 TRANG 5: QUẢN LÝ TRANG LỖI HTTP 404
                 ========================================================= -->
            <?php elseif ($tab === 'errors'): ?>
                <div class="panel-card">
                    <div class="panel-header">
                        <h6 class="fw-bold m-0">Danh Sách Đường Dẫn Cào Lỗi / HTTP 404 (<?php echo $totalError404; ?> mục)</h6>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">STT</th>
                                    <th>Đường dẫn (URL) bị lỗi</th>
                                    <th>Người thực hiện</th>
                                    <th>Thời gian lưu</th>
                                    <th style="width: 120px;">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($errorsList && $errorsList->num_rows > 0): ?>
                                    <?php $stt = 1; while ($err = $errorsList->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo $stt++; ?></td>
                                            <td style="max-width: 380px;">
                                                <a href="<?php echo htmlspecialchars($err['url']); ?>" target="_blank" class="text-danger fw-semibold text-decoration-none d-block text-truncate">
                                                    <?php echo htmlspecialchars($err['url']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($err['username'] ?? 'N/A'); ?></td>
                                            <td class="small" style="color: var(--text-muted);"><?php echo htmlspecialchars($err['created_at']); ?></td>
                                            <td>
                                                <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Xóa bản ghi lỗi 404 này?');">
                                                    <input type="hidden" name="action" value="delete_article">
                                                    <input type="hidden" name="redirect_tab" value="errors">
                                                    <input type="hidden" name="article_id" value="<?php echo $err['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Xóa lỗi</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center py-5" style="color: var(--text-muted);">Không có đường dẫn lỗi 404 nào trên hệ thống!</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateAdminThemeIcon(theme) {
        const icon = document.getElementById('adminThemeIcon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fa-solid fa-sun text-warning me-1' : 'fa-solid fa-moon me-1';
        }
    }
    function toggleAdminTheme() {
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('pocket_theme', next);
        updateAdminThemeIcon(next);
    }
    updateAdminThemeIcon(document.documentElement.getAttribute('data-theme') || 'light');

    function openEditUserModal(id, username, email, role, isSelf) {
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_username').value = username;
        document.getElementById('edit_email').value = email;
        const roleSelect = document.getElementById('edit_role');
        const selfNote = document.getElementById('selfRoleNote');
        roleSelect.value = role;

        if (isSelf) {
            roleSelect.disabled = true;
            selfNote.classList.remove('d-none');
        } else {
            roleSelect.disabled = false;
            selfNote.classList.add('d-none');
        }

        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }
</script>
</body>
</html>