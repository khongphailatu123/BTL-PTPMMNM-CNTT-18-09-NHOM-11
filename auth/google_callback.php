<?php
// auth/google_callback.php - Xử lý luồng đăng nhập & callback từ Google OAuth 2.0
require_once '../config/db.php';
require_once '../config/google.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Đảm bảo bảng users có cột email
$checkEmailCol = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
if ($checkEmailCol && $checkEmailCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER username");
}

// Kiểm tra xem đã điền cấu hình Google Client ID chưa
if (
    GOOGLE_CLIENT_ID === '' ||
    GOOGLE_CLIENT_ID === 'DIEN_GOOGLE_CLIENT_ID_CUA_BAN_VAO_DAY' ||
    GOOGLE_CLIENT_SECRET === '' ||
    GOOGLE_CLIENT_SECRET === 'DIEN_GOOGLE_CLIENT_SECRET_CUA_BAN_VAO_DAY'
) {
    $_SESSION['auth_error'] = 'Chưa cấu hình Google Client ID / Client Secret trong file config/google.php!';
    header("Location: login.php");
    exit();
}

// BƯỚC 1: Nếu chưa có tham số ?code từ Google -> Chuyển hướng người dùng sang trang đăng nhập của Google
if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        $_SESSION['auth_error'] = 'Bạn đã hủy đăng nhập bằng Google.';
        header("Location: login.php");
        exit();
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account'
    ];

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header("Location: " . $authUrl);
    exit();
}

// BƯỚC 2: Nhận code trả về từ Google -> Đổi lấy access_token
$state = $_GET['state'] ?? '';
if (empty($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    unset($_SESSION['google_oauth_state']);
    $_SESSION['auth_error'] = 'Phiên xác thực Google không hợp lệ (State mismatch). Vui lòng thử lại!';
    header("Location: login.php");
    exit();
}
unset($_SESSION['google_oauth_state']);

$code = $_GET['code'];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15
]);
$tokenResponse = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($tokenResponse === false) {
    $_SESSION['auth_error'] = 'Lỗi kết nối tới Google OAuth: ' . $curlErr;
    header("Location: login.php");
    exit();
}

$tokenData = json_decode($tokenResponse, true);
if (empty($tokenData['access_token'])) {
    $_SESSION['auth_error'] = 'Không lấy được mã truy cập (Access Token) từ Google!';
    header("Location: login.php");
    exit();
}

// BƯỚC 3: Lấy thông tin Email & Tên tài khoản Google
$chUser = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($chUser, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15
]);
$userResponse = curl_exec($chUser);
curl_close($chUser);

$googleUser = json_decode($userResponse, true);
$googleEmail = trim($googleUser['email'] ?? '');
$googleName  = trim($googleUser['name'] ?? '');

if ($googleEmail === '') {
    $_SESSION['auth_error'] = 'Không thể lấy địa chỉ Email từ tài khoản Google!';
    header("Location: login.php");
    exit();
}

// BƯỚC 4: Kiểm tra Email trong bảng users -> Có rồi thì đăng nhập, chưa có thì tự tạo tài khoản mới
$stmt = $conn->prepare("SELECT id, username, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->bind_param("s", $googleEmail);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows === 1) {
    $existingUser = $res->fetch_assoc();
    $_SESSION['user_id']  = (int)$existingUser['id'];
    $_SESSION['username'] = $existingUser['username'];
    $_SESSION['role']     = $existingUser['role'] ?? 'user';
    $stmt->close();

    header("Location: ../index.php");
    exit();
}
$stmt->close();

// Tạo username duy nhất từ phần trước @ của Gmail nếu chưa từng đăng ký
$baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $googleEmail)[0]);
if ($baseUsername === '') {
    $baseUsername = 'google_user';
}

$finalUsername = $baseUsername;
$suffix = 1;
while (true) {
    $stmtCheck = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmtCheck->bind_param("s", $finalUsername);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $exists = ($resCheck && $resCheck->num_rows > 0);
    $stmtCheck->close();

    if (!$exists) {
        break;
    }
    $finalUsername = $baseUsername . $suffix;
    $suffix++;
}

$randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$role = 'user';

$stmtInsert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
$stmtInsert->bind_param("ssss", $finalUsername, $googleEmail, $randomPassword, $role);

if ($stmtInsert->execute()) {
    $_SESSION['user_id']  = (int)$stmtInsert->insert_id;
    $_SESSION['username'] = $finalUsername;
    $_SESSION['role']     = $role;
    $stmtInsert->close();

    header("Location: ../index.php");
    exit();
}

$stmtInsert->close();
$_SESSION['auth_error'] = 'Không thể khởi tạo tài khoản Google vào hệ thống: ' . $conn->error;
header("Location: login.php");
exit();