# Đặc tả UX — US-006: Xem bài giảng video

## 1. Luồng người dùng
```
/khoa-hoc/{slug} "Vào học" → /hoc/{course}/bai/{lesson}
Trong trang học: chọn bài khác trong outline / bấm "Bài tiếp theo" → chuyển bài
Video đạt ≥90% → tự động đánh dấu hoàn thành → outline cập nhật icon ✔
Phiên bị vô hiệu hoá do đăng nhập thiết bị khác (US-014) → chặn ngay, hiện màn thông báo bắt đăng nhập lại
```

## 2. Màn hình

### 2.1 `/hoc/{course}/bai/{lesson}` — Trang học video
**Bố cục mobile:** video player full-width trên cùng (giữ tỉ lệ 16:9) → tên bài học + nút "Bài tiếp theo" → tabs "Nội dung khóa học" (outline, mặc định mở) — outline hiển thị dạng accordion theo chương, cuộn riêng.

**Bố cục desktop:** 2 cột — trái 70% player + tên bài + mô tả ngắn, phải 30% outline luôn hiển thị (sticky), cuộn độc lập, tự động cuộn tới bài đang xem, highlight bài hiện tại nền `indigo-50`.

**Outline mỗi bài hiển thị:** tên bài, thời lượng, icon: ✔ (emerald, đã hoàn thành), ▶ (đang xem, viền indigo), trống (chưa học). Không hiện icon khoá vì toàn bộ đã enrolled.

**Thanh tiến độ tổng khóa học:** nhỏ phía trên outline, `<ProgressBar>` "Tiến độ: 6/20 bài (30%)".

**Hành động:** bấm bài khác trong outline → chuyển route, giữ vị trí cuộn outline; nút "◀ Bài trước" / "Bài tiếp theo ▶" dưới player.

**Ghi chú kỹ thuật (ADR-002 §4–5, api-contract §2.4):** player dùng `hls.js` phát link HLS lấy từ `GET /learn/lessons/{lesson}/playback` (TTL 15 phút, ràng theo IP với bài không preview). Cứ ~15 phút hoặc khi gặp lỗi phát (đặc biệt HTTP 403 do IP đổi/token hết hạn — rủi ro R7 ở README) player tự gọi lại endpoint `playback` để lấy link mới mà **không làm mất vị trí đang xem** (`resume_at_seconds`), không có màn lỗi gián đoạn cho trường hợp này. Vị trí xem + % hoàn thành gửi định kỳ qua `POST /learn/lessons/{lesson}/heartbeat` (tối đa 6 lần/phút/bài — throttle `heartbeat`), không phải tính toán phía client.

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Hiển thị |
|---|---|
| Đang tải video | Spinner giữa khung player (giữ khung 16:9, không đơ layout) |
| Lỗi tải video (AC5) | Trong khung player: icon cảnh báo + "Không tải được video, vui lòng thử lại." + nút "Thử lại" |
| Chưa mua, cố truy cập URL trực tiếp (AC3) | Redirect `/khoa-hoc/{slug}` + `<Alert variant="warning">` "Bạn cần mua khóa học để xem bài học này" |
| Đã hoàn thành ≥90% (AC2) | Toast nhỏ không chặn: "🎉 Bạn đã hoàn thành bài học này!" + icon outline chuyển ✔ ngay lập tức |
| Enrollment bị thu hồi giữa chừng (hoàn tiền) | Chặn tải bài tiếp theo, chuyển về `/khoa-hoc/{slug}` kèm `<Alert variant="danger">` "Quyền truy cập khóa học của bạn đã bị thu hồi." |
| Phiên bị vô hiệu hoá do đăng nhập thiết bị khác (US-014) | Toàn trang chặn bằng `<ForcedLogoutOverlay>` không thể đóng, video tạm dừng ngay: xem chi tiết ở `docs/design/US-014-gioi-han-mot-thiet-bi-mot-phien.md` |
| Link HLS hết hạn/bị chặn IP giữa lúc xem (403) | Không hiện lỗi cho học sinh nếu lấy lại link thành công trong ≤1–2 giây (retry ngầm); chỉ hiện "Không tải được video, vui lòng thử lại." nếu retry cũng thất bại |
| Mạng yếu | Player hiện buffering spinner chuẩn của `hls.js`/thẻ `<video>`, không có xử lý riêng ngoài loading rõ ràng |

## 4. Component React dùng/tạo mới
- `<CourseOutline>` (mới — `apps/web`, dùng chung với US-003, thêm biến thể "đã enrolled" hiện icon hoàn thành)
- `<ProgressBar>` (dùng lại — `packages/ui`, cũng dùng ở US-008)
- `<VideoPlayer>` (mới — `apps/web`) — wrapper quanh `hls.js` (nguồn nội bộ) hoặc iframe sandbox (`youtube-nocookie`/Vimeo `dnt=1`, chỉ bài preview — US-009), xử lý loading/error UI thống nhất, tự lấy lại link khi gặp 403, gửi heartbeat định kỳ, nhận `lessonId`, `onEnded`

## 5. Điểm cần PO duyệt
- Không phát sinh điểm mới cho story này ngoài phối hợp US-014 (xem file riêng).
