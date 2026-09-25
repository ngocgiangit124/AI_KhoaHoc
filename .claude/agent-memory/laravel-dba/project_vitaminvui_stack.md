---
name: project-vitaminvui-stack
description: VitaminVui dùng MySQL 8/InnoDB (KHÔNG phải SQL Server) — điều chỉnh mọi khuyến nghị DBA theo MySQL cho dự án này
metadata:
  type: project
---

Dự án **VitaminVui** (web bán khoá học Toán lớp 6–12, Laravel 11/PHP 8.3) dùng **MySQL 8.0.16+ (khuyến nghị 8.4 LTS), InnoDB** làm database chính — không phải SQL Server dù persona mặc định của agent này là DBA SQL Server. `docs/architecture/README.md` ghi rõ: "agent DBA hiện viết cho SQL Server — cần review theo MySQL 8/InnoDB".

**Why:** CLAUDE.md của dự án xác định driver là MySQL; toàn bộ kiến thức T-SQL/gap lock kiểu SQL Server, `WITH (ROWLOCK, UPDLOCK)`, filtered index, `OFFSET...FETCH` không áp dụng trực tiếp — phải map sang khái niệm MySQL/InnoDB tương ứng (xem [[mysql-vitaminvui-patterns]]).

**How to apply:** Mỗi khi làm việc trong `/home/ngocgiang/TestAI_Agent` (VitaminVui), luôn dùng thuật ngữ/cơ chế MySQL: InnoDB MVCC (REPEATABLE READ/READ COMMITTED, gap lock/next-key lock, locking read luôn đọc bản mới nhất bất kể isolation), generated column STORED/VIRTUAL thay filtered index, `JSON_SET`/CHECK constraint MySQL 8.0.16+, `cursorPaginate()`/keyset pagination thay OFFSET-FETCH, collation `utf8mb4_unicode_ci` vs `utf8mb4_0900_ai_ci` cho tiếng Việt. Không đề xuất cú pháp SQL Server.
