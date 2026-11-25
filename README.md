# Sổ thu chi hằng ngày

Ứng dụng PHP 8.3+ và MySQL 8.4, không cần Composer. Người quản trị tạo tài khoản. Dùng dữ liệu giả cho bài thực hành.

## Chạy cục bộ

1. Bật MySQL và phần mở rộng PHP pdo_mysql.
2. Trong MySQL client, dùng quyền quản trị để tạo CSDL và người dùng. Thay mật khẩu ví dụ bằng giá trị riêng. Cấp quyền tương tự cho 'expense_app'@'127.0.0.1' nếu dùng TCP loopback và máy chủ yêu cầu đúng host:

```sql
CREATE DATABASE expense_book CHARACTER SET utf8mb4;
USE expense_book;
SOURCE database/schema.sql;
CREATE USER 'expense_app'@'localhost' IDENTIFIED BY 'REPLACE_LOCAL_PASSWORD';
GRANT SELECT, INSERT ON expense_book.users TO 'expense_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON expense_book.entries TO 'expense_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON expense_book.app_sessions TO 'expense_app'@'localhost';
```

Mở MySQL client ở thư mục dự án hoặc chỉ định đường dẫn đầy đủ cho SOURCE. Chỉ nhập schema một lần.

3. Sao chép config/database.example.json thành config/database.json. Điền host 127.0.0.1, database expense_book, username expense_app, password riêng. Giữ tệp ngoài public và ngoài Git.
4. Tạo tài khoản từ Bash ở thư mục gốc, mật khẩu 12–72 byte:
```bash
read -rs -p 'Mật khẩu ứng dụng: ' PW; printf '\n'
printf '%s\n' "$PW" | php scripts/create-user.php student@example.test
unset PW
php -S 127.0.0.1:8080 -t public
```
5. Mở http://127.0.0.1:8080. Không dùng máy chủ tích hợp để phục vụ Internet. Health check 503 trên máy cục bộ nếu chưa có runtime/ready là hành vi dự kiến.

## Triển khai AWS theo bài lab

Đọc Chương 3–4 của báo cáo Word cùng bài Lab_PHP_Auto_Scaling_Group_CloudWatch.docx. Dùng thông số và tên của đồ án dưới đây, thay cho tên của ứng dụng quản lý công việc trong lab:

- Repository: so-thu-chi-aws. Nội dung gốc là các tệp trong thư mục này; không đưa config/database.json hoặc thông tin AWS lên GitHub. User Data giả định URL Git HTTPS đọc được không cần xác thực.
- VPC hai AZ; ALB/EC2 trong hai public subnet, RDS trong DB subnet group gồm hai private subnet. ALB chỉ nhận nguồn IP người thử; EC2 chỉ nhận HTTP từ ALB; RDS chỉ nhận MySQL từ EC2.
- SecureString Standard: /lab/so-thu-chi/database. Value lấy cấu trúc từ config/database.example.json; host là endpoint thật của RDS; không dùng IP. Không in giá trị vào nhật ký.
- deploy/parameter-read-policy.json: thay REGION và ACCOUNT_ID. Gắn inline policy này cùng AmazonSSMManagedInstanceCore và CloudWatchAgentServerPolicy vào role EC2 của bài lab.
- deploy/user-data.sh: thay REGION, REPO_URL, COMMIT_SHA. Dán vào Launch Template, không chạy trên máy cá nhân. Dùng Ubuntu 24.04 LTS x86_64. Tạo EC2 tạm từ template để thiết lập DB trước khi mở ASG.
- Target Group: HTTP 80, /health.php, mã 200; tắt stickiness. ASG: Min 2, Desired 2, Max 3, bật ELB health checks và group metrics. Detailed monitoring bật trong Launch Template. Target tracking CPU 50%.
- CloudWatch Agent đã có cấu hình thu RAM, ổ đĩa và log Apache vào /lab/so-thu-chi/access và /lab/so-thu-chi/error, giữ 3 ngày. Dashboard, alarm và SNS thực hiện theo báo cáo.

### Khởi tạo RDS một lần trên EC2 tạm

Kết nối qua Session Manager. Sau khi cloud-init xong, dùng CA đã được User Data tải:

```bash
sudo cloud-init status --wait
MYSQL_HISTFILE=/dev/null mysql -h RDS_ENDPOINT -u dbadmin -p \
 --ssl-ca=/etc/ssl/certs/aws-rds-global-bundle.pem --ssl-mode=VERIFY_IDENTITY expense_book
```

Trong MySQL client (mật khẩu phải trùng SecureString):
```sql
SOURCE /var/www/so-thu-chi/database/schema.sql;
CREATE USER 'expense_app'@'%' IDENTIFIED BY 'REPLACE_PRIVATE_PASSWORD' REQUIRE SSL;
GRANT SELECT, INSERT ON expense_book.users TO 'expense_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON expense_book.entries TO 'expense_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON expense_book.app_sessions TO 'expense_app'@'%';
EXIT;
```
Từ Bash trên máy tạm, tạo tài khoản demo qua kết nối PDO dùng cấu hình đã tải từ SSM:
```bash
read -rs -p 'Mật khẩu ứng dụng: ' PW; printf '\n'
printf '%s\n' "$PW" | sudo -u www-data php /var/www/so-thu-chi/scripts/create-user.php student@example.test
unset PW
curl -fsS http://127.0.0.1/health.php
```
Chạy lại bước tạo tài khoản với email khác để kiểm thử cách ly. Sau đó tạo ASG, xác minh login/CRUD qua ALB, rồi terminate máy tạm. Không chạy lại schema khi có EC2 mới.

### An toàn và xác minh

Bản HTTP của lab giới hạn IP và chỉ dùng mật khẩu demo, dữ liệu giả. Khi chuyển sang HTTPS, thêm `SetEnv EXPENSE_SECURE_COOKIE 1` trong VirtualHost (mod_env), bật listener HTTPS với ACM, chuyển hướng HTTP sang HTTPS và tạo template mới. Không để truy cập HTTP trực tiếp vào EC2. Giới hạn đăng nhập hiện tại theo phiên, chưa phải cơ chế chống dò mật khẩu ở quy mô vận hành.

Kiem_thu_cuc_bo.json ghi nhận kết quả HTTP trên bản sao SQLite với khóa MySQL được giả lập. Chưa chạy MySQL thật hoặc triển khai AWS. Tiến hành mục nghiệm thu của báo cáo trong tài khoản học phần; thu hồi tài nguyên sau buổi thử.
