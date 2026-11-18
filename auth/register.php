<?php
// register.php - Đăng ký tài khoản kèm Email khôi phục mật khẩu
require_once 'config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Tự động thêm cột email vào bảng users nếu chưa có
$checkEmailCol = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
if ($checkEmailCol && $checkEmailCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER username");
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Vui lòng điền đầy đủ tất cả thông tin!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Địa chỉ Email không hợp lệ!';
    } elseif (strlen($password) < 4) {
        $error = 'Mật khẩu phải có ít nhất 4 ký tự!';
    } elseif ($password !== $confirmPassword) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } else {
        // Kiểm tra trùng tên đăng nhập hoặc email
        $stmtCheck = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheck->bind_param("ss", $username, $email);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();

        if ($resCheck && $resCheck->num_rows > 0) {
            $dup = $resCheck->fetch_assoc();
            if (strcasecmp($dup['username'], $username) === 0) {
                $error = 'Tên đăng nhập này đã được sử dụng!';
            } else {
                $error = 'Email này đã được đăng ký cho tài khoản khác!';
            }
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';

            $stmtInsert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
            $stmtInsert->bind_param("ssss", $username, $email, $hashedPassword, $role);

            if ($stmtInsert->execute()) {
                $_SESSION['auth_success'] = 'Đăng ký thành công! Bạn có thể đăng nhập ngay.';
                header("Location: login.php");
                exit();
            } else {
                $error = 'Có lỗi xảy ra khi tạo tài khoản: ' . $conn->error;
            }
            $stmtInsert->close();
        }
        $stmtCheck->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký - Pocket Saver</title>
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
            max-width: 440px;
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
        <h5 class="text-center fw-bold mb-4">Đăng ký tài khoản mới</h5>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 small rounded-3">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-light">Tên đăng nhập</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Nhập tên đăng nhập..." value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-light">Email khôi phục mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="Nhập địa chỉ email của bạn..." value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-light">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" placeholder="Tạo mật khẩu..." required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-light">Xác nhận mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-shield-halved"></i></span>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu..." required>
                </div>
            </div>

            <button type="submit" class="btn btn-pocket w-100 mb-3">Đăng ký</button>
        </form>

        <div class="text-center small text-secondary">
            Đã có tài khoản? <a href="login.php" class="link-accent">Đăng nhập ngay</a>
        </div>
    </div>
</body>
</html>