<?php
// auth/forgot_password.php - Khôi phục mật khẩu qua Tên đăng nhập & Email đã đăng ký
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Đảm bảo cột email tồn tại
$checkEmailCol = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
if ($checkEmailCol && $checkEmailCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER username");
}

$error = '';
$step = 1; // Bước 1: Xác minh Username + Email | Bước 2: Đặt mật khẩu mới

// Giữ trạng thái đã xác minh nếu người dùng đang ở bước 2
if (isset($_SESSION['reset_user_id'], $_SESSION['reset_username'])) {
    $step = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_account') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($username === '' || $email === '') {
            $error = 'Vui lòng nhập đầy đủ Tên đăng nhập và Email đã đăng ký!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Định dạng Email không hợp lệ!';
        } else {
            $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? AND LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows === 1) {
                $user = $res->fetch_assoc();
                $_SESSION['reset_user_id'] = (int)$user['id'];
                $_SESSION['reset_username'] = $user['username'];
                $step = 2;
            } else {
                $error = 'Tên đăng nhập và Email không khớp với bất kỳ tài khoản nào!';
            }
            $stmt->close();
        }
    } elseif ($action === 'reset_password') {
        if (!isset($_SESSION['reset_user_id'])) {
            $step = 1;
            $error = 'Phiên khôi phục đã hết hạn, vui lòng xác minh lại!';
        } else {
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($newPassword === '' || $confirmPassword === '') {
                $error = 'Vui lòng nhập đầy đủ mật khẩu mới!';
                $step = 2;
            } elseif (strlen($newPassword) < 4) {
                $error = 'Mật khẩu mới phải có ít nhất 4 ký tự!';
                $step = 2;
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Mật khẩu xác nhận không khớp!';
                $step = 2;
            } else {
                $resetUserId = (int)$_SESSION['reset_user_id'];
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmtUpdate = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtUpdate->bind_param("si", $hashedPassword, $resetUserId);

                if ($stmtUpdate->execute()) {
                    unset($_SESSION['reset_user_id'], $_SESSION['reset_username']);
                    $_SESSION['auth_success'] = 'Đặt lại mật khẩu thành công! Vui lòng đăng nhập bằng mật khẩu mới.';
                    header("Location: login.php");
                    exit();
                } else {
                    $error = 'Không thể cập nhật mật khẩu: ' . $conn->error;
                    $step = 2;
                }
                $stmtUpdate->close();
            }
        }
    } elseif ($action === 'cancel_reset') {
        unset($_SESSION['reset_user_id'], $_SESSION['reset_username']);
        $step = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quên mật khẩu - Pocket Saver</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background-color: #0b1120;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .auth-card {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 18px;
            width: 100%;
            max-width: 430px;
            padding: 36px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.55);
        }
        .brand-logo {
            color: #ef4056;
            font-size: 1.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
            text-decoration: none;
        }
        .input-group-text {
            background-color: #0f172a;
            border-color: #334155;
            color: #94a3b8;
        }
        .form-control {
            background-color: #0f172a;
            border-color: #334155;
            color: #f8fafc;
            padding: 11px 14px;
        }
        .form-control:focus {
            background-color: #0f172a;
            border-color: #ef4056;
            color: #ffffff;
            box-shadow: 0 0 0 0.2rem rgba(239, 64, 86, 0.2);
        }
        .form-control::placeholder {
            color: #94a3b8;
            opacity: 0.45;
        }
        .btn-pocket {
            background-color: #ef4056;
            border: none;
            color: #ffffff;
            font-weight: 600;
            padding: 11px;
            border-radius: 10px;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .btn-pocket:hover {
            background-color: #dc2626;
            color: #ffffff;
            transform: translateY(-1px);
        }
        .link-accent {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 600;
        }
        .link-accent:hover {
            text-decoration: underline;
            color: #7dd3fc;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <a href="login.php" class="brand-logo">
            <i class="fa-solid fa-bookmark"></i> pocket
        </a>
        <h5 class="text-center fw-bold mb-2">Khôi phục mật khẩu</h5>
        <p class="text-center small text-secondary mb-4">
            <?php echo $step === 1 ? 'Nhập tên đăng nhập và email đã đăng ký để xác minh' : 'Tạo mật khẩu mới cho tài khoản <strong class="text-info">' . htmlspecialchars($_SESSION['reset_username'] ?? '') . '</strong>'; ?>
        </p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 small rounded-3">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="action" value="verify_account">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-light">Tên đăng nhập</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Nhập tên đăng nhập..." value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-light">Email đã đăng ký</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="Nhập email khớp với tài khoản..." value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-pocket w-100 mb-3">Xác minh tài khoản</button>
            </form>
        <?php else: ?>
            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="action" value="reset_password">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-light">Mật khẩu mới</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="new_password" class="form-control" placeholder="Nhập mật khẩu mới..." required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-light">Xác nhận mật khẩu mới</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-shield-halved"></i></span>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu mới..." required>
                    </div>
                </div>

                <button type="submit" class="btn btn-pocket w-100 mb-2">Đặt lại mật khẩu</button>
            </form>

            <form method="POST" action="forgot_password.php" class="mb-3">
                <input type="hidden" name="action" value="cancel_reset">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Nhập lại tài khoản khác</button>
            </form>
        <?php endif; ?>

        <div class="text-center small text-secondary">
            <a href="login.php" class="link-accent"><i class="fa-solid fa-arrow-left me-1"></i>Quay lại đăng nhập</a>
        </div>
    </div>
</body>
</html>