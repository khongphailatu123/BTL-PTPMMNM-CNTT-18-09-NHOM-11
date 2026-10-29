<?php
// register.php - Trang đăng ký tài khoản mới cho Pocket Saver
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (!empty($username) && !empty($password) && !empty($confirm_password)) {
        if ($password !== $confirm_password) {
            $error_msg = "Mật khẩu xác nhận không trùng khớp!";
        } else {
            // Kiểm tra tên đăng nhập đã tồn tại chưa
            $stmtCheck = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmtCheck->bind_param("s", $username);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();

            if ($resCheck->num_rows > 0) {
                $error_msg = "Tên đăng nhập này đã được sử dụng!";
            } else {
                // Mã hóa mật khẩu an toàn
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmtInsert = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmtInsert->bind_param("ss", $username, $hashed_password);

                if ($stmtInsert->execute()) {
                    $success_msg = "Tạo tài khoản thành công! Đang chuyển hướng đăng nhập...";
                } else {
                    $error_msg = "Có lỗi xảy ra trong quá trình tạo tài khoản!";
                }
            }
        }
    } else {
        $error_msg = "Vui lòng điền đầy đủ các thông tin!";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * { font-family: 'Inter', sans-serif; }
        body {
            background-color: #0b132b;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-card {
            background: #1c2541;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
        }

        .brand-logo {
            color: #ef4056;
            font-weight: 800;
            font-size: 2rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .form-control-dark {
            background-color: #0b132b;
            border: 1px solid #334155;
            color: #f8fafc;
            border-radius: 10px;
            padding: 12px 16px;
        }

        .form-control-dark:focus {
            background-color: #0b132b;
            border-color: #38bdf8;
            color: #ffffff;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }

        .btn-pocket {
            background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            color: #ffffff;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            padding: 12px;
            transition: all 0.3s ease;
        }

        .btn-pocket:hover {
            background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .auth-footer-link {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="auth-card">
        <div class="brand-logo">
            <i class="fa-solid fa-bookmark"></i> pocket
        </div>

        <h5 class="fw-bold text-center mb-4 text-slate-200">Đăng ký tài khoản mới</h5>

        <?php if (!empty($error_msg)): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi đăng ký',
                    text: '<?php echo $error_msg; ?>',
                    confirmButtonColor: '#ef4056',
                    customClass: { popup: 'rounded-4' }
                });
            </script>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Thành công',
                    text: '<?php echo $success_msg; ?>',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = 'login.php';
                });
            </script>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <div class="mb-3">
                <label class="form-label fs-7 fw-semibold text-slate-300">Tên đăng nhập</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-slate-400" style="background-color: #0b132b !important; border-color: #334155 !important;"><i class="fa-solid fa-user"></i></span>
                    <input type="text" name="username" class="form-control form-control-dark" placeholder="Tạo tên đăng nhập..." required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fs-7 fw-semibold text-slate-300">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-slate-400" style="background-color: #0b132b !important; border-color: #334155 !important;"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" class="form-control form-control-dark" placeholder="Tạo mật khẩu..." required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fs-7 fw-semibold text-slate-300">Xác nhận mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark border-secondary text-slate-400" style="background-color: #0b132b !important; border-color: #334155 !important;"><i class="fa-solid fa-shield-halved"></i></span>
                    <input type="password" name="confirm_password" class="form-control form-control-dark" placeholder="Nhập lại mật khẩu..." required>
                </div>
            </div>

            <button type="submit" class="btn btn-pocket w-100 mb-3 fs-6">Đăng ký tài khoản</button>
        </form>

        <div class="text-center mt-3 fs-7 text-slate-400">
            Đã có tài khoản? <a href="login.php" class="auth-footer-link">Đăng nhập</a>
        </div>
    </div>

</body>
</html>