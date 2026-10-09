-- VitaminVui — user MySQL production/staging, PHẦN 1 (MẪU, T31; tách đôi ở T35-1/D4). Chạy MỘT lần bằng root TRƯỚC deploy đầu tiên.
-- Phần 2 (`grants-worker.sql`: quyền bảng của vv_worker_video) do `deploy.sh` tự chạy sau mỗi lần migrate (bảng vl_videos/failed_jobs
-- chưa tồn tại trước migrate nên GRANT bảng đó lỗi 1146).
-- Thay <DB>, <APP_HOST>, <MIGRATE_HOST>, <WORKER_HOST> và mật khẩu (openssl rand -hex 32; KHÔNG dùng ký tự ' hoặc \). Điền file trong /dev/shm hoặc
-- qua stdin rồi xoá/shred ngay; không để file đã điền mật khẩu trên đĩa. KHÔNG commit mật khẩu thật.
-- Đổi mật khẩu về sau: `ALTER USER ... IDENTIFIED BY ...` (CREATE USER IF NOT EXISTS KHÔNG đổi mật khẩu của user đã có).

-- 1) User ứng dụng (php-fpm, queue, scheduler): DML trên mọi bảng, KHÔNG có DDL (CREATE/ALTER/DROP).
--    DELETE trên audit_logs là CỐ Ý: `audit:purge` (T30) xoá bản ghi quá 24 tháng. Không thu hồi nếu không đổi chính sách.
--    Dù vậy `audit_logs` vẫn bất biến ở tầng DB nhờ trigger (L2, migration 2026_10_16_100000): UPDATE luôn lỗi, DELETE chỉ
--    xoá được dòng quá 24 tháng. vv_app KHÔNG có quyền TRIGGER nên không gỡ/sửa được trigger.
CREATE USER IF NOT EXISTS 'vv_app'@'<APP_HOST>' IDENTIFIED BY '<MAT_KHAU_APP>';
GRANT SELECT, INSERT, UPDATE, DELETE ON `<DB>`.* TO 'vv_app'@'<APP_HOST>';
-- Phương án cấp từng bảng KHÔNG khuyến nghị (dễ gãy khi thêm tính năng). Nếu vẫn làm, DELETE phải có tối thiểu cho:
-- audit_logs (audit:purge), users + consents + dữ liệu con của user (users:purge-unverified), otp_codes (otp:prune),
-- failed_jobs (queue:prune-failed), vl_videos (videolab:cleanup), bảng video_assets (videos:prune-orphans),
-- giỏ hàng, giữ chỗ mã giảm giá và mọi bảng mà code gọi delete()/forceDelete(). Đối chiếu bằng `grep -rn "delete()" app`.
-- Kiểm: SHOW GRANTS FOR 'vv_app'@'<APP_HOST>';  rồi chạy thử `php artisan audit:purge --dry-run`.

-- 2) User migrate (chỉ dùng lúc deploy, tách khỏi user app): DDL + DML.
CREATE USER IF NOT EXISTS 'vv_migrate'@'<MIGRATE_HOST>' IDENTIFIED BY '<MAT_KHAU_MIGRATE>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER ON `<DB>`.* TO 'vv_migrate'@'<MIGRATE_HOST>';
-- TRIGGER: migration audit_logs_immutability_triggers tạo/gỡ trigger. Nếu bật binary log, `SET GLOBAL log_bin_trust_function_creators=1`
-- chỉ trong lúc migrate rồi trả về 0 (không ghi vào my.cnf), nếu không CREATE TRIGGER lỗi 1419. Trigger mang DEFINER=vv_migrate:
-- không xoá user này (lỗi 1449 trên mọi UPDATE/DELETE audit_logs).

-- 3) User worker-video (T12-6, T12-10): CHỈ khi bật VideoLab (VV_VIDEOLAB=1; V1 dùng Bunny nên MẶC ĐỊNH KHÔNG TẠO). Bỏ tiền tố `-- VIDEOLAB: ` ở dòng
--    CREATE USER bên dưới khi bật. Quyền bảng (vl_videos, failed_jobs) cấp bởi grants-worker.sql sau migrate. Worker KHÔNG đọc được users, orders...
-- VIDEOLAB: CREATE USER IF NOT EXISTS 'vv_worker_video'@'<WORKER_HOST>' IDENTIFIED BY '<MAT_KHAU_WORKER>';

-- D5 (DBA T35): trigger audit_logs (migration 2026_10_16_100000) mang DEFINER = vv_migrate@'<MIGRATE_HOST>'. KHÔNG xoá, đổi tên (RENAME USER),
-- đổi host hay thu hồi TRIGGER của user này: xoá/đổi tên -> mọi UPDATE/DELETE audit_logs lỗi 1449; thu hồi TRIGGER -> lỗi 1142 (audit:purge hỏng).
-- Đổi dải mạng Docker (VV_SUBNET_APP) = đổi host của user này VÀ tạo lại trigger. deploy.sh kiểm tình trạng này sau mỗi migrate.
FLUSH PRIVILEGES;
