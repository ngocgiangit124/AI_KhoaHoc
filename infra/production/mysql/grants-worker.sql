-- VitaminVui — quyền BẢNG của vv_worker_video (T35-1/D4). `deploy.sh` chạy file này bằng root sau mỗi lần migrate thành công (GRANT idempotent).
-- Yêu cầu: user đã được tạo bởi grants-users.sql. Placeholder do deploy.sh điền: <DB> (MYSQL_DATABASE), <WORKER_HOST> (VV_WORKER_DB_HOST, mặc định 10.231.12.%).
-- Chỉ bảng vl_videos (+ failed_jobs để ghi job thất bại). Queue/cache nằm ở Redis. TranscodeJob không DELETE vl_videos nên không cần quyền DELETE.
GRANT SELECT, INSERT, UPDATE ON `<DB>`.`vl_videos` TO 'vv_worker_video'@'<WORKER_HOST>';
GRANT INSERT ON `<DB>`.`failed_jobs` TO 'vv_worker_video'@'<WORKER_HOST>';
FLUSH PRIVILEGES;
