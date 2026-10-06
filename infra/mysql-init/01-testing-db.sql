-- Database riêng cho test (Pest) — cùng user với DB chính, collation MySQL 8.4 mặc định của instance.
CREATE DATABASE IF NOT EXISTS vitaminvui_testing;
GRANT ALL PRIVILEGES ON vitaminvui_testing.* TO 'vitaminvui'@'%';
FLUSH PRIVILEGES;

-- DB test riêng cho từng dev chạy song song (backend/phpunit.local-a|b|c.xml).
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_a;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_b;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_c;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_d;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_e;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_f;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_g;
CREATE DATABASE IF NOT EXISTS vitaminvui_testing_h;
GRANT ALL PRIVILEGES ON `vitaminvui_testing\_%`.* TO 'vitaminvui'@'%';
FLUSH PRIVILEGES;
