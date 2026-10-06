# ADR-005: Hồ sơ giáo viên công khai: bảng riêng `teacher_profiles`, một chốt đồng ý, mutex giới hạn trang chủ

**Trạng thái:** Accepted (PO duyệt ngày 2026-10-06 ở cổng #2 của US-020)
**Liên quan:** US-020, `docs/tech/US-020.md`, data-model §3.1, api-contract §2.9, ADR-004 §2.7 (cache), data-model §4 (thứ tự khoá)

## Bối cảnh
US-020 thêm cho giáo viên: dòng chuyên môn (`headline`), trạng thái đồng ý công khai (thời điểm, phiên bản câu chữ, thời điểm rút), cờ và thứ tự hiển thị trang chủ, người sửa gần nhất. `users` đã có `bio`, `avatar_path` nhưng chưa có đường ghi nào, nên mọi giá trị hiện là NULL. PO quyết định:
- quy tắc đồng ý áp dụng ở **mọi** API công khai;
- tối đa 6 giáo viên ở trang chủ, kể cả khi hai admin bật cùng lúc.

## Các phương án

### 1. Nơi lưu dữ liệu
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Thêm ~8 cột vào `users` | Không join; tái dùng `bio`, `avatar_path` sẵn có | `users` (~150k dòng sau 3 năm, gần như toàn học sinh) mang cột chỉ dùng cho vài chục giáo viên. `users` là dòng nóng của xác thực (khoá khi OTP, đổi vai trò, khoá tài khoản), thêm thao tác hồ sơ vào làm tăng tranh chấp. Mở rộng bề mặt mass-assignment của model nhạy cảm nhất. Mutex giới hạn 6 phải khoá dòng `users` |
| **B. Bảng 1-1 `teacher_profiles` (PK `user_id`)** | Tách đúng miền. Bảng nhỏ (≤ vài trăm dòng) nên khoá toàn bảng làm mutex là rẻ. Ẩn danh hoá chỉ cần xoá 1 dòng. Không đụng luồng xác thực | Thêm 1 join/eager load. Phải chuyển `bio`/`avatar_path` và xoá cột cũ ở release sau |

### 2. Chốt đồng ý
- Kiểm ở UI: loại, vì PO yêu cầu kiểm ở backend.
- Kiểm rải rác ở từng Resource: dễ sót khi có API công khai mới.
- **Một hàm duy nhất `App\Support\PublicTeacher`**, có test kiến trúc cấm đọc thẳng `avatar_path`/`bio` trong Resource công khai.

### 3. Bằng chứng đồng ý
- Chỉ dùng `audit_logs`: bị xoá sau 24 tháng, không đủ làm bằng chứng suốt thời gian công khai.
- Bảng lịch sử mới: trùng chức năng với `consents`.
- **Ghi vào `consents` đã có** (type `teacher_public_profile`), `teacher_profiles` giữ trạng thái hiện tại để truy vấn nhanh.

### 4. Giới hạn 6 khi hai admin bật đồng thời
| Phương án | Đánh giá |
|---|---|
| `SELECT ... WHERE show_on_homepage=1 FOR UPDATE` rồi đếm | **Sai ở READ COMMITTED**: dòng vừa được bật bởi transaction kia có thể nằm trước con trỏ quét của index phụ, nên không được thấy. Khi chưa có dòng nào bật thì không khoá được gì |
| Khoá Redis (`Cache::lock`) | Dự án chưa dùng. Bất biến dữ liệu nên được giữ trong DB |
| Cột "slot" 1..6 + unique | DB tự chặn, nhưng số 6 bị đóng vào schema (BR3 yêu cầu là hằng cấu hình) và khó đọc |
| **Khoá mọi dòng `teacher_profiles` theo PK tăng dần, rồi đếm** | Đúng ở READ COMMITTED: dòng không đổi vị trí PK, transaction thứ hai chờ ngay dòng đầu tiên. Cùng kiểu với `StaffAccountService` (khoá mọi admin theo id). Rẻ vì bảng nhỏ |

## Quyết định
1. Tạo bảng `teacher_profiles` (phương án B). Ngừng dùng `users.bio`/`users.avatar_path` từ T36, xoá hai cột ở release sau (backlog T36-1).
2. Mọi API công khai chỉ xuất ảnh/bio giáo viên qua `PublicTeacher`: trả `null` khi chưa đồng ý hoặc user không còn là `giao_vien`. Key luôn có mặt, tương thích hợp đồng v1.
3. Mỗi lần đồng ý ghi một dòng `consents`; rút đồng ý thì set `revoked_at`. Audit ghi đủ 5 action của AC20.
4. Bật hiển thị trang chủ: `INSERT IGNORE` dòng hồ sơ ngoài transaction, sau đó trong transaction khoá toàn bộ `teacher_profiles` `ORDER BY user_id FOR UPDATE`, đếm, rồi ghi. Thứ tự khoá chuẩn của miền này: `users → teacher_profiles → consents`.
5. Laravel không cache các endpoint công khai có ảnh/bio giáo viên. Độ trễ ≤ 60 s chỉ đến từ Next Data Cache (ADR-004 §2.7).

## Hệ quả
- Thêm `docs/architecture/data-model.md` §3.1 `teacher_profiles`, §4 đoạn khoá mới. Thêm `api-contract.md` §2.9.
- Test T10 về `teachers[].bio` phải sửa: giáo viên chưa đồng ý thì `null`.
- T34 (US-018) gọi `TeacherProfileService::erase()` khi ẩn danh hoá.
- Thêm API công khai về giáo viên sau này thì phải dùng `PublicTeacher`. Reviewer kiểm điều này, và test kiến trúc chặn vi phạm.
- Nếu sau này cần bắt đồng ý lại khi đổi câu chữ, chỉ cần thêm điều kiện phiên bản vào `PublicTeacher` và query trang chủ. Không đổi schema.

## Quyết định bổ sung của PO (2026-10-06)
- Chấp nhận cache phía Next khoảng 60 giây (`revalidate: 60`); Laravel không cache.
- Đổi câu chữ đồng ý (`consent_version`) thì các đồng ý cũ VẪN hiệu lực.
- Giáo viên bị khoá hoặc đã đổi vai trò mà vẫn bật cờ thì vẫn tính vào giới hạn 6; Admin tự tắt, màn admin liệt kê những người này để tắt được.
