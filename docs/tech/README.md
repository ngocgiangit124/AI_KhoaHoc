# Thiết kế kỹ thuật — chỉ mục theo story

Thiết kế MVP (US-001 → US-014) được viết tập trung ở `docs/architecture/` vì các story dùng chung mô hình dữ liệu. Bảng dưới cho biết mỗi story cần đọc mục nào.

| Story | Đọc | Task |
|---|---|---|
| US-001 Đăng ký/đăng nhập + OTP | architecture/README §3.1; data-model §3.1; api-contract §2.2; ADR-004 | T03, T04 |
| US-002 Danh mục | data-model §3.2; api-contract §2.1; README §6 | T10 |
| US-003 Chi tiết khóa | api-contract §2.1 (`viewer_state`); data-model §3.2–3.3 | T10 |
| US-004 Giỏ hàng | data-model §3.5; api-contract §2.3; api-contract §3 (`PricingCalculator`) | T16 |
| US-005 Thanh toán | **ADR-001**; data-model §3.5, §4; api-contract §2.3, §2.6 | T17–T20 |
| US-006 Xem video | **ADR-002**; api-contract §2.4 | T11–T13 |
| US-007 Quiz | README §3.3; data-model §3.4; api-contract §2.4 | T21, T22 |
| US-008 Tiến độ | data-model §3.3; api-contract §2.4 | T23 |
| US-009 Quản trị khóa học | api-contract §2.5; ADR-004 (ma trận quyền); ADR-002 (upload) | T07–T09 |
| US-010 Quản lý đơn | README §3.4; ADR-001 §9; api-contract §2.5 | T24, T25 |
| US-011 Chuyên đề | data-model §3.2; api-contract §2.5 | T06 |
| US-012 Học miễn phí + duyệt | README §3.2; data-model §3.3 | T14 |
| US-013 Mã giảm giá | ADR-001 §6; data-model §3.5 | T15 |
| US-014 1 thiết bị/1 phiên | **ADR-003** | T05 |

| US-015 Quên/đổi mật khẩu (chờ BA) | api-contract §2.2; ADR-003 | T27 |
| US-016 Quản lý tài khoản staff + MFA (chờ BA) | ADR-004 §3; api-contract §2.5 | T28, T33 |
| US-017 Đồng ý dữ liệu & xác nhận phụ huynh (chờ BA, chờ pháp chế) | data-model `consents`; api-contract §2.8 | T03, T29 |
| US-018 Quyền dữ liệu cá nhân (chờ BA) | data-model §7; api-contract §2.8 | T30, T34 |

Danh sách task đầy đủ (kể cả T01, T02, FE0 chi tiết): `docs/architecture/tasks.md`. Truy vết review Security/DBA: `docs/architecture/review-traceability.md`.
