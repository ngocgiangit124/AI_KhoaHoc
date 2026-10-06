# Dữ liệu tĩnh của ứng dụng

## common-passwords.txt

Danh sách mật khẩu phổ biến để chặn khi đăng ký, đặt lại, đổi mật khẩu (`App\Rules\NotCommonPassword`, M1 Bảo mật cụm 1).
Cục bộ hoàn toàn, KHÔNG gọi dịch vụ ngoài (PO không cho chuyển dữ liệu ra nước ngoài, nên không dùng HIBP/`uncompromised`).

- **Nguồn:** danh sách mật khẩu xếp hạng của `zxcvbn` (Dropbox, giấy phép MIT: https://github.com/dropbox/zxcvbn), lấy từ
  bản `passwords.txt` (30.000 mục) đi kèm dữ liệu zxcvbn của Chrome. Lấy ngày 2026-10-06. Giữ thông báo bản quyền MIT của zxcvbn.
- **Phần tự bổ sung:** mẫu tiếng Việt và tên hệ thống (`matkhau123`, `vitaminvui2026`, `anhyeuem`, `giaovien123`...) cùng
  các biến thể ghép gốc phổ biến (`password`, `matkhau`, `admin`, `qwerty`, `vitaminvui`...) với hậu tố thường gặp
  (`123`, `1234`, `@123`, `!`, năm 2024-2026), viết thường và viết hoa chữ đầu.
- **Tiêu chí chọn mục:** chữ thường, không trùng, dài 8-64 ký tự (học sinh tối thiểu 8 nên mục ngắn hơn vô nghĩa), một mục một dòng,
  LF. So khớp không phân biệt hoa/thường sau `trim()`. Hiện 11.799 mục (~111 KB), nạp lười một lần mỗi tiến trình.
- **Cập nhật:** nạp lại danh sách nguồn, bổ sung mẫu mới, lọc theo tiêu chí trên, loại trùng, ghi đè file, cập nhật ngày ở trên.
  Chạy `T28/StaffPasswordPolicyTest` (kiểm có > 10.000 mục và các mẫu mẫu). Không xoá mục chỉ vì một test dùng mật khẩu đó:
  đổi mật khẩu trong test.
