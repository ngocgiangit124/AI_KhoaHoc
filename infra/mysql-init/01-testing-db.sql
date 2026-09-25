-- Database riêng cho test (Pest) — cùng user với DB chính, collation MySQL 8.4 mặc định của instance.
CREATE DATABASE IF NOT EXISTS vitaminvui_testing;
GRANT ALL PRIVILEGES ON vitaminvui_testing.* TO 'vitaminvui'@'%';
FLUSH PRIVILEGES;
