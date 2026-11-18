<?php
// settings.php - Trang Cài đặt Hồ sơ, Avatar, ID Tài khoản & Bảo mật Pocket Saver
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Tự động bổ sung các cột hồ sơ mở rộng vào bảng users nếu chưa có
$extraCols = [
    'email'     => "ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER username",
    'full_name' => "ALTER TABLE users ADD COLUMN full_name VARCHAR(120) NULL AFTER email",
    'phone'     => "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER full_name",
    'bio'       => "ALTER TABLE users ADD COLUMN bio VARCHAR(255) NULL AFTER phone",
    'avatar'    => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL AFTER bio"
];
foreach ($extraCols as $col => $alterSql) {
    $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query($alterSql);
    }
}

$activeTab = $_GET['tab'] ?? 'profile';
if (!in_array($activeTab, ['profile', 'security', 'account'], true)) {
    $activeTab = 'profile';
}

$successMsg = '';
$errorMsg   = '';

// Lấy thông tin tài khoản hiện tại
$stmtUser = $conn->prepare("SELECT id, username, email, full_name, phone, bio, avatar, password, role FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$userData = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$userData) {
    header("Location: auth/logout.php");
    exit();
}

// Mã ID định danh 6 chữ số dễ nhớ (VD: id = 1 -> 100001)
$accountCode = 100000 + (int)$userData['id'];

// Xử lý lưu cập nhật
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'update_avatar') {
        $activeTab = 'profile';
        if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['avatar_file']['tmp_name'];
            $fileSize = $_FILES['avatar_file']['size'];
            $imgInfo = @getimagesize($tmpName);

            if ($imgInfo === false) {
                $errorMsg = 'Tệp tải lên không phải hình ảnh hợp lệ!';
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $errorMsg = 'Kích thước ảnh đại diện tối đa 5MB!';
            } else {
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif'
                ];
                $mime = $imgInfo['mime'] ?? '';
                if (!isset($allowedMimes[$mime])) {
                    $errorMsg = 'Chỉ hỗ trợ định dạng JPG, PNG, WEBP hoặc GIF!';
                } else {
                    $avatarDir = 'assets/avatars';
                    if (!is_dir($avatarDir)) {
                        mkdir($avatarDir, 0777, true);
                    }

                    // Xóa ảnh đại diện cũ nếu có
                    if (!empty($userData['avatar']) && file_exists($userData['avatar'])) {
                        @unlink($userData['avatar']);
                    }

                    $ext = $allowedMimes[$mime];
                    $newPath = $avatarDir . '/avatar_' . $user_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($tmpName, $newPath)) {
                        $stmtAvt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmtAvt->bind_param("si", $newPath, $user_id);
                        $stmtAvt->execute();
                        $stmtAvt->close();

                        $userData['avatar'] = $newPath;
                        $_SESSION['avatar'] = $newPath;
                        $successMsg = 'Đã cập nhật ảnh đại diện thành công!';
                    } else {
                        $errorMsg = 'Không thể lưu tệp ảnh lên máy chủ!';
                    }
                }
            }
        } else {
            $errorMsg = 'Vui lòng chọn một tệp ảnh trước khi tải lên!';
        }
    } elseif ($formType === 'update_profile') {
        $activeTab   = 'profile';
        $newUsername = trim($_POST['username'] ?? '');
        $fullName    = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $bio         = trim($_POST['bio'] ?? '');

        if ($newUsername === '') {
            $errorMsg = 'Tên đăng nhập không được để trống!';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $newUsername)) {
            $errorMsg = 'Tên đăng nhập từ 3–30 ký tự, chỉ gồm chữ cái, số và dấu gạch dưới (_), chấm (.), gạch ngang (-).';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Địa chỉ email không đúng định dạng!';
        } elseif ($phone !== '' && !preg_match('/^[0-9+\-\s]{8,15}$/', $phone)) {
            $errorMsg = 'Số điện thoại không hợp lệ (8–15 chữ số)!';
        } else {
            $stmtDup = $conn->prepare("SELECT id FROM users WHERE (username = ? OR (email IS NOT NULL AND email != '' AND email = ?)) AND id != ? LIMIT 1");
            $stmtDup->bind_param("ssi", $newUsername, $email, $user_id);
            $stmtDup->execute();
            if ($stmtDup->get_result()->num_rows > 0) {
                $errorMsg = 'Tên đăng nhập hoặc Email này đã được tài khoản khác sử dụng!';
            } else {
                $emailVal    = $email !== '' ? $email : null;
                $fullNameVal = $fullName !== '' ? $fullName : null;
                $phoneVal    = $phone !== '' ? $phone : null;
                $bioVal      = $bio !== '' ? $bio : null;

                $stmtUpd = $conn->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, bio = ? WHERE id = ?");
                $stmtUpd->bind_param("sssssi", $newUsername, $emailVal, $fullNameVal, $phoneVal, $bioVal, $user_id);
                if ($stmtUpd->execute()) {
                    $_SESSION['username']  = $newUsername;
                    $userData['username']  = $newUsername;
                    $userData['email']     = $emailVal;
                    $userData['full_name'] = $fullNameVal;
                    $userData['phone']     = $phoneVal;
                    $userData['bio']       = $bioVal;
                    $successMsg = 'Đã cập nhật hồ sơ cá nhân thành công!';
                } else {
                    $errorMsg = 'Không thể lưu thay đổi, vui lòng thử lại!';
                }
                $stmtUpd->close();
            }
            $stmtDup->close();
        }
    } elseif ($formType === 'change_password') {
        $activeTab   = 'security';
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            $errorMsg = 'Vui lòng điền đầy đủ cả 3 ô mật khẩu!';
        } elseif (!password_verify($currentPass, $userData['password']) && $currentPass !== $userData['password']) {
            $errorMsg = 'Mật khẩu hiện tại không chính xác!';
        } elseif (strlen($newPass) < 6) {
            $errorMsg = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
        } elseif ($newPass !== $confirmPass) {
            $errorMsg = 'Xác nhận mật khẩu mới không khớp!';
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtPass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmtPass->bind_param("si", $hashed, $user_id);
            if ($stmtPass->execute()) {
                $userData['password'] = $hashed;
                $successMsg = 'Đổi mật khẩu thành công!';
            } else {
                $errorMsg = 'Lỗi cập nhật mật khẩu!';
            }
            $stmtPass->close();
        }
    }
}

$myArticlesCount = (int)($conn->query("SELECT COUNT(*) AS c FROM articles WHERE user_id = $user_id")->fetch_assoc()['c'] ?? 0);
$mySharedCount   = (int)($conn->query("SELECT COUNT(*) AS c FROM article_shares WHERE receiver_id = $user_id AND status = 'accepted'")->fetch_assoc()['c'] ?? 0);
$avatarLetter    = strtoupper(mb_substr($userData['full_name'] ?: $userData['username'], 0, 1, 'UTF-8'));
$hasAvatar       = !empty($userData['avatar']) && file_exists($userData['avatar']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt tài khoản - Pocket Saver</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script>
        (function() {
            const savedTheme = localStorage.getItem('pocket_theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', savedTheme || (prefersDark ? 'dark' : 'light'));
        })();
    </script>

    <style>
        :root, [data-theme="light"] {
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-input: #f1f5f9;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --nav-active-bg: rgba(239, 64, 86, 0.1);
        }
        [data-theme="dark"] {
            --bg-body: #0b1120;
            --bg-surface: #1e293b;
            --bg-input: #0f172a;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --nav-active-bg: rgba(239, 64, 86, 0.18);
        }

        * { font-family: 'Inter', sans-serif; }
        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .top-navbar {
            background-color: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 14px 28px;
        }
        .brand-logo {
            color: #ef4056;
            font-size: 1.4rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .settings-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px -6px rgba(0, 0, 0, 0.08);
        }

        .avatar-wrapper {
            position: relative;
            width: 84px;
            height: 84px;
            margin: 0 auto 12px auto;
        }
        .avatar-circle {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4056 0%, #f97316 100%);
            color: #ffffff;
            font-size: 2rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 3px solid var(--border-color);
        }
        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar-edit-badge {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background-color: #2563eb;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            border: 2px solid var(--bg-surface);
            transition: transform 0.15s ease;
        }
        .avatar-edit-badge:hover {
            transform: scale(1.1);
        }

        .id-pill-box {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: var(--bg-input);
            border: 1px dashed var(--border-color);
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-primary);
            cursor: pointer;
        }
        .id-pill-box:hover {
            border-color: #ef4056;
        }

        .settings-nav-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            margin-bottom: 6px;
            transition: all 0.18s ease;
        }
        .settings-nav-btn:hover {
            background-color: var(--bg-input);
            color: var(--text-primary);
        }
        .settings-nav-btn.active {
            background-color: var(--nav-active-bg);
            color: #ef4056;
        }

        .form-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
        }
        .form-control {
            background-color: var(--bg-input);
            border-color: var(--border-color);
            color: var(--text-primary);
            padding: 10px 14px;
            border-radius: 10px;
        }
        .form-control:focus {
            background-color: var(--bg-input);
            color: var(--text-primary);
            border-color: #ef4056;
            box-shadow: 0 0 0 0.2rem rgba(239, 64, 86, 0.18);
        }
        .btn-pocket {
            background-color: #ef4056;
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 10px 22px;
            border-radius: 10px;
        }
        .btn-pocket:hover {
            background-color: #dc2626;
            color: #ffffff;
        }
    </style>
</head>
<body>

<nav class="top-navbar d-flex justify-content-between align-items-center">
    <a href="index.php" class="brand-logo">
        <i class="fa-solid fa-bookmark"></i> pocket
    </a>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="toggleSettingsTheme()">
            <i id="settingsThemeIcon" class="fa-solid fa-moon me-1"></i> Giao diện
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> Quay lại thư viện
        </a>
    </div>
</nav>

<div class="container py-4" style="max-width: 980px;">
    <div class="mb-4">
        <h3 class="fw-bold mb-1">Cài đặt tài khoản</h3>
        <p class="small m-0" style="color: var(--text-muted);">Chỉnh sửa ảnh đại diện, quản lý ID nhận chia sẻ nhanh và cập nhật thông tin bảo mật</p>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success rounded-3 py-2 small">
            <i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($successMsg); ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger rounded-3 py-2 small">
            <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Cột trái: Avatar + ID tài khoản + Menu -->
        <div class="col-md-4">
            <div class="settings-card mb-3 text-center">
                <form id="avatarUploadForm" method="POST" action="settings.php?tab=profile" enctype="multipart/form-data">
                    <input type="hidden" name="form_type" value="update_avatar">
                    <input type="file" name="avatar_file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none" onchange="document.getElementById('avatarUploadForm').submit();">
                    <div class="avatar-wrapper">
                        <div class="avatar-circle">
                            <?php if ($hasAvatar): ?>
                                <img src="<?php echo htmlspecialchars($userData['avatar']); ?>" alt="Avatar">
                            <?php else: ?>
                                <?php echo htmlspecialchars($avatarLetter); ?>
                            <?php endif; ?>
                        </div>
                        <label for="avatarInput" class="avatar-edit-badge" title="Đổi ảnh đại diện">
                            <i class="fa-solid fa-camera"></i>
                        </label>
                    </div>
                </form>

                <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($userData['full_name'] ?: $userData['username']); ?></h6>
                <div class="small mb-2" style="color: var(--text-muted);">@<?php echo htmlspecialchars($userData['username']); ?></div>

                <!-- Mã ID định danh dùng để gửi nhận chia sẻ -->
                <div class="mb-2">
                    <span class="id-pill-box" onclick="copyAccountId('<?php echo $accountCode; ?>')" title="Bấm để sao chép ID tài khoản">
                        <i class="fa-solid fa-fingerprint text-primary"></i>
                        <span>ID: #<?php echo $accountCode; ?></span>
                        <i class="fa-regular fa-copy text-secondary"></i>
                    </span>
                </div>

                <span class="badge <?php echo $userData['role'] === 'admin' ? 'bg-danger' : 'bg-primary'; ?> rounded-pill px-3">
                    <?php echo strtoupper($userData['role']); ?>
                </span>
            </div>

            <div class="settings-card p-3">
                <a href="settings.php?tab=profile" class="settings-nav-btn <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-id-card"></i> Hồ sơ & Ảnh đại diện
                </a>
                <a href="settings.php?tab=security" class="settings-nav-btn <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-shield-halved"></i> Bảo mật & Mật khẩu
                </a>
                <a href="settings.php?tab=account" class="settings-nav-btn <?php echo $activeTab === 'account' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-pie"></i> Tổng quan dữ liệu lưu
                </a>
            </div>
        </div>

        <!-- Cột phải -->
        <div class="col-md-8">
            <?php if ($activeTab === 'profile'): ?>
                <div class="settings-card">
                    <h5 class="fw-bold mb-1">Thông tin hồ sơ cá nhân</h5>
                    <p class="small mb-4" style="color: var(--text-muted);">Bạn có thể chia sẻ <strong>ID #<?php echo $accountCode; ?></strong> hoặc <strong>Username</strong> để người khác gửi bài viết cho bạn.</p>

                    <form method="POST" action="settings.php?tab=profile">
                        <input type="hidden" name="form_type" value="update_profile">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Mã ID Tài Khoản (Cố định)</label>
                                <input type="text" class="form-control fw-bold text-primary" value="#<?php echo $accountCode; ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tên đăng nhập (Username) *</label>
                                <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($userData['username']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Họ và tên hiển thị</label>
                                <input type="text" name="full_name" class="form-control" placeholder="VD: Hà Minh Quân" value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Số điện thoại</label>
                                <input type="text" name="phone" class="form-control" placeholder="VD: 0912345678" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Địa chỉ Email</label>
                                <input type="email" name="email" class="form-control" placeholder="email@gmail.com" value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Giới thiệu ngắn (Bio)</label>
                                <input type="text" name="bio" class="form-control" maxlength="255" placeholder="Sở thích đọc truyện, lưu tài liệu công nghệ..." value="<?php echo htmlspecialchars($userData['bio'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-end">
                            <button type="submit" class="btn btn-pocket">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Lưu thay đổi hồ sơ
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ($activeTab === 'security'): ?>
                <div class="settings-card">
                    <h5 class="fw-bold mb-1">Đổi mật khẩu đăng nhập</h5>
                    <p class="small mb-4" style="color: var(--text-muted);">Nên dùng mật khẩu từ 6 ký tự trở lên để bảo vệ kho lưu trữ cá nhân.</p>

                    <form method="POST" action="settings.php?tab=security">
                        <input type="hidden" name="form_type" value="change_password">

                        <div class="mb-3">
                            <label class="form-label">Mật khẩu hiện tại *</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Nhập mật khẩu đang dùng..." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mật khẩu mới *</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Tối thiểu 6 ký tự..." required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Xác nhận mật khẩu mới *</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu mới..." required>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-pocket">
                                <i class="fa-solid fa-key me-1"></i> Cập nhật mật khẩu
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ($activeTab === 'account'): ?>
                <div class="settings-card">
                    <h5 class="fw-bold mb-1">Tổng quan tài khoản & Dữ liệu</h5>
                    <p class="small mb-4" style="color: var(--text-muted);">Thống kê hoạt động lưu trữ offline của tài khoản này.</p>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3 border" style="border-color: var(--border-color) !important; background: var(--bg-input);">
                                <div class="small" style="color: var(--text-muted);">Bài viết tự cào & lưu trữ</div>
                                <div class="fs-3 fw-bold text-danger"><?php echo $myArticlesCount; ?> <span class="fs-6 fw-normal">bài</span></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3 border" style="border-color: var(--border-color) !important; background: var(--bg-input);">
                                <div class="small" style="color: var(--text-muted);">Bài được chia sẻ từ bạn bè</div>
                                <div class="fs-3 fw-bold text-primary"><?php echo $mySharedCount; ?> <span class="fs-6 fw-normal">bài</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 rounded-3 border d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-color: var(--border-color) !important;">
                        <div>
                            <div class="fw-semibold small">Liên kết đăng nhập Google OAuth 2.0</div>
                            <div class="small" style="color: var(--text-muted);">
                                <?php echo !empty($userData['email']) ? 'Email hiện tại: ' . htmlspecialchars($userData['email']) : 'Chưa gắn email (cập nhật email trùng Gmail để đăng nhập nhanh bằng Google)'; ?>
                            </div>
                        </div>
                        <a href="settings.php?tab=profile" class="btn btn-sm btn-outline-secondary">Cập nhật Email</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function updateSettingsThemeIcon(theme) {
        const icon = document.getElementById('settingsThemeIcon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fa-solid fa-sun text-warning me-1' : 'fa-solid fa-moon me-1';
        }
    }
    function toggleSettingsTheme() {
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('pocket_theme', next);
        updateSettingsThemeIcon(next);
    }
    function copyAccountId(code) {
        navigator.clipboard.writeText(code).then(() => {
            alert('Đã sao chép ID tài khoản: #' + code);
        });
    }
    updateSettingsThemeIcon(document.documentElement.getAttribute('data-theme') || 'light');
</script>
</body>
</html>