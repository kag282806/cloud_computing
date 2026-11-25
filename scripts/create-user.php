<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$email = strtolower(trim($argv[1] ?? ''));
$password = rtrim((string)fgets(STDIN), "\r\n");
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>254 || strlen($password)<12 || strlen($password)>72) {
    fwrite(STDERR,"Email hợp lệ và mật khẩu 12 đến 72 byte là bắt buộc.\n"); exit(1);
}
try {
    $db=require dirname(__DIR__).'/src/database.php';
    $db->prepare('INSERT INTO users(email,password_hash) VALUES (?,?)')->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
    echo "Đã tạo tài khoản.\n";
} catch (Throwable $e) { fwrite(STDERR,"Không tạo được tài khoản. Kiểm tra kết nối hoặc email trùng.\n"); exit(1); }
