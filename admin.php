<?php
// admin.php - Trang quản trị dành riêng cho Admin
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra nếu chưa đăng nhập hoặc không phải Admin thì đẩy về index.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$stmtAdmin = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmtAdmin->bind_param("i", $current_user_id);
$stmtAdmin->execute();
$userRole = $stmtAdmin->get_result()->fetch_assoc()['role'] ?? 'user';

if ($userRole !== 'admin') {
    header("Location: index.php");
    exit();
}

// Xử lý đổi quyền hoặc xóa User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_role') {
        $target_id = (int)$_POST['user_id'];
        $new_role = $_POST['new_role'] === 'admin' ? 'admin' : 'user';
        if ($target_id !== $current_user_id) { // Không tự hạ quyền chính mình
            $stmtRole = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmtRole->bind_param("si", $new_role, $target_id);
            $stmtRole->execute();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $target_id = (int)$_POST['user_id'];
        if ($target_id !== $current_user_id) {
            $stmtDel = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmtDel->bind_param("i", $target_id);
            $stmtDel->execute();
        }
    }
    header("Location: admin.php");
    exit();
}

// Lấy danh sách tất cả người dùng và số bài viết của họ
$usersRes = $conn->query("
    SELECT u.id, u.username, u.role, COUNT(a.id) as total_articles 
    FROM users u 
    LEFT JOIN articles a ON u.id = a.user_id 
    GROUP BY u.id
");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Trang Quản Trị Hệ Thống - Pocket Saver</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0b132b; color: #f8fafc; font-family: sans-serif; }
        .admin-card { background: #1c2541; border: 1px solid #334155; border-radius: 16px; padding: 24px; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-danger m-0"><i class="fa-solid fa-user-shield me-2"></i>Bảng Quản Trị Hệ Thống (Admin Dashboard)</h3>
        <a href="index.php" class="btn btn-outline-light btn-sm fw-bold"><i class="fa-solid fa-house me-1"></i> Quay lại App</a>
    </div>

    <div class="admin-card">
        <h5 class="fw-bold mb-3 text-white">Danh sách người dùng hệ thống</h5>
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên đăng nhập</th>
                        <th>Vai trò (Role)</th>
                        <th>Số bài đã cào</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($u = $usersRes->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $u['id']; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['role'] === 'admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo strtoupper($u['role']); ?>
                                </span>
                            </td>
                            <td><?php echo $u['total_articles']; ?> bài</td>
                            <td>
                                <?php if ($u['id'] !== $current_user_id): ?>
                                    <form action="admin.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_role">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="new_role" value="<?php echo $u['role'] === 'admin' ? 'user' : 'admin'; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm fw-bold me-1">
                                            Chuyển thành <?php echo $u['role'] === 'admin' ? 'User' : 'Admin'; ?>
                                        </button>
                                    </form>

                                    <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Xóa tài khoản này sẽ xóa toàn bộ bài viết của họ?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm fw-bold">Xóa User</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted extra-small">Tài khoản hiện tại</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>