<?php
// login.php - Đăng nhập hệ thống Pocket Saver (Hỗ trợ tài khoản thường & Google Account)
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
$success = '';

if (isset($_SESSION['auth_success'])) {
    $success = $_SESSION['auth_success'];
    unset($_SESSION['auth_success']);
}

if (isset($_SESSION['auth_error'])) {
    $error = $_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu!';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'user';

                header("Location: index.php");
                exit();
            } else {
                $error = 'Mật khẩu không chính xác!';
            }
        } else {
            $error = 'Tên đăng nhập không tồn tại!';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Pocket Saver</title>
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
            max-width: 420px;
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
        .divider-or {
            display: flex;
            align-items: center;
            text-align: center;
            color: #64748b;
            font-size: 0.8rem;
            margin: 16px 0;
        }
        .divider-or::before,
        .divider-or::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #334155;
        }
        .divider-or:not(:empty)::before {
            margin-right: 12px;
        }
        .divider-or:not(:empty)::after {
            margin-left: 12px;
        }
        .btn-google {
            background-color: #0f172a;
            border: 1px solid #334155;
            color: #f8fafc;
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
        }
        .btn-google:hover {
            background-color: #162036;
            border-color: #475569;
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
        <h5 class="text-center fw-bold mb-4">Đăng nhập tài khoản</h5>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success py-2 small rounded-3">
                <i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 small rounded-3">
                <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-light">Tên đăng nhập</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Nhập tên đăng nhập..." value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-light">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" class="form-control" placeholder="Nhập mật khẩu..." required>
                </div>
                <div class="text-end mt-2">
                    <a href="forgot_password.php" class="small link-accent" style="font-size: 0.82rem;">Quên mật khẩu?</a>
                </div>
            </div>

            <button type="submit" class="btn btn-pocket w-100">Đăng nhập</button>
        </form>

        <div class="divider-or">hoặc</div>

        <a href="google_callback.php" class="btn-google w-100 mb-3">
            <svg width="18" height="18" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            <span>Tiếp tục với Google</span>
        </a>

        <div class="text-center small text-secondary">
            Chưa có tài khoản? <a href="register.php" class="link-accent">Đăng ký ngay</a>
        </div>
    </div>
</body>
</html>