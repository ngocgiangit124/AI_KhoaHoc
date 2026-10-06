/** Danh sách màn quản trị xem trước v2 (dùng cho mục lục `/v2` của admin và `/v2/muc-luc` của web). */
export const ADMIN_SCREENS: Array<{ title: string; path: string; story: string; note: string }> = [
  { title: "Danh sách khóa học", path: "/v2/quan-tri/khoa-hoc", story: "US-009", note: "Lọc, bảng, menu theo vai trò (?vai-tro=quan_ly_trang|giao_vien)." },
  { title: "Tạo khóa học", path: "/v2/quan-tri/khoa-hoc/tao", story: "US-009", note: "Form thông tin chung, khóa mới là nháp." },
  { title: "Sửa khóa học — thông tin chung", path: "/v2/quan-tri/khoa-hoc/101/sua", story: "US-009", note: "Ảnh bìa, giáo viên, xoá/ngừng bán." },
  { title: "Sửa khóa học — chương & bài", path: "/v2/quan-tri/khoa-hoc/101/sua?tab=chuong-bai&bai=310", story: "US-009", note: "Cây chương/bài, trạng thái video, VIDEO_INVALID." },
  { title: "Chuyên đề", path: "/v2/quan-tri/chuyen-de", story: "US-011", note: "Tạo/sửa, ẩn/hiện, xoá có chặn khi đang gán. Giáo viên: chỉ xem." },
  { title: "Duyệt đăng ký khóa miễn phí", path: "/v2/quan-tri/duyet-dang-ky", story: "US-012", note: "Duyệt, từ chối kèm lý do; tab đã duyệt/đã từ chối." },
  { title: "Giáo viên trên trang chủ", path: "/v2/quan-tri/giao-vien", story: "US-020", note: "Bật/tắt, thứ tự, giới hạn 6, nhãn chưa đồng ý." },
  { title: "Sửa hộ hồ sơ giáo viên", path: "/v2/quan-tri/giao-vien/17", story: "US-020", note: "Admin/QLT sửa nội dung, không đồng ý thay." },
  { title: "Hồ sơ của tôi (giáo viên)", path: "/v2/quan-tri/ho-so", story: "US-020", note: "Ảnh cắt vuông, giới thiệu, đồng ý/rút đồng ý." },
  { title: "Mã giảm giá", path: "/v2/quan-tri/ma-giam-gia", story: "US-013", note: "Lọc theo trạng thái, lượt dùng." },
  { title: "Tạo mã giảm giá", path: "/v2/quan-tri/ma-giam-gia/tao", story: "US-013", note: "Phạm vi, quy tắc mã giảm hết giá trị đơn." },
  { title: "Sửa mã đã dùng", path: "/v2/quan-tri/ma-giam-gia/32", story: "US-013", note: "Khoá mã/loại/giá trị, tắt/bật lại, không xoá được." },
  { title: "Tài khoản staff", path: "/v2/quan-tri/tai-khoan", story: "US-016", note: "Tạo, mật khẩu một lần, khoá, đặt lại, đổi vai trò (released_course_ids)." },
  { title: "Nhật ký thao tác", path: "/v2/quan-tri/nhat-ky", story: "US-016", note: "Chỉ đọc, lọc ngày/hành động/người, trang trước/sau." },
  { title: "Đơn hàng", path: "/v2/quan-tri/don-hang", story: "V2", note: "Menu khoá; trang giữ chỗ “Sẽ có ở V2”." },
];
