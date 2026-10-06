-- VitaminVui — user MySQL production/staging (MẪU, T31). Chạy bằng tài khoản quản trị DB, KHÔNG commit mật khẩu thật.
-- Thay <DB>, <APP_HOST>, <WORKER_HOST>, <MIGRATE_HOST> và mật khẩu (openssl rand -base64 32). Tên bảng kiểm lại bằng
-- `SHOW TABLES` sau khi migrate (bảng vl_* của VideoLab bắt đầu bằng `vl_`).

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

-- 3) User worker-video (T12-6, T12-10): CHỈ bảng vl_videos (+ failed_jobs nếu QUEUE_FAILED_DRIVER=database-uuids,
--    để ghi job thất bại). Queue/cache nằm ở Redis nên không cần quyền bảng jobs/cache.
--    Worker KHÔNG đọc được users, orders, enrollments...
CREATE USER IF NOT EXISTS 'vv_worker_video'@'<WORKER_HOST>' IDENTIFIED BY '<MAT_KHAU_WORKER>';
GRANT SELECT, INSERT, UPDATE ON `<DB>`.`vl_videos` TO 'vv_worker_video'@'<WORKER_HOST>';
GRANT INSERT ON `<DB>`.`failed_jobs` TO 'vv_worker_video'@'<WORKER_HOST>';
-- Đã xác minh (review T31): TranscodeVideoJob/TranscodeService không DELETE vl_videos nên worker không cần quyền DELETE.
-- `videolab:cleanup` (scheduler) chạy bằng vv_app nên vv_app cần DELETE trên vl_videos (đã có qua `<DB>`.*).

-- Kiểm sau khi cấp (đều phải bị từ chối khi đăng nhập bằng vv_worker_video):
--   SELECT * FROM `<DB>`.`users` LIMIT 1;   -- ERROR 1142
FLUSH PRIVILEGES;
