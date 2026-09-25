# Đặc tả UX — US-007: Làm bài tập/đề kiểm tra trắc nghiệm

## 1. Luồng người dùng
```
Trang học (US-006) hoặc outline chương → bấm "Làm bài kiểm tra" → /hoc/{course}/quiz/{quiz}
Chọn đáp án từng câu (autosave ngay) → cuộn hết câu → "Nộp bài"
  → nếu còn câu chưa trả lời → modal cảnh báo xác nhận → xác nhận vẫn nộp được
  → hết giờ (nếu có time_limit) → tự động nộp
→ /hoc/{course}/quiz/{quiz}/ket-qua → xem điểm + đáp án đúng/sai từng câu
→ có thể bấm "Làm lại" → tạo attempt mới
```

## 2. Màn hình

### 2.1 `/hoc/{course}/quiz/{quiz}` — Làm bài
**Bố cục mobile:** header dính (sticky) trên cùng gồm: tên quiz (rút gọn) + `⚠ Chờ PO xác nhận: mọi quiz có bắt buộc giới hạn thời gian` đồng hồ đếm ngược `<Countdown>` nổi bật màu `warning`, đổi màu `danger` khi còn < 1 phút + bộ đếm "Đã trả lời: 6/10". Bên dưới là danh sách câu hỏi cuộn dọc, mỗi câu 1 card: số thứ tự, nội dung câu hỏi, 4 lựa chọn dạng radio lớn dễ chạm (toàn bộ hàng là vùng chạm, không chỉ nút tròn). Nút "Nộp bài" sticky đáy màn hình.

**Bố cục desktop:** layout tương tự nhưng có thêm cột phải nhỏ "Bảng câu hỏi" dạng lưới số (1..10), câu đã trả lời tô `indigo-600`, câu chưa trả lời viền xám, bấm số nhảy nhanh tới câu đó (nice-to-have, có thể bỏ nếu Dev ưu tiên đơn giản).

**Nếu quiz không có `time_limit_minutes`:** ẩn hoàn toàn khối đồng hồ đếm ngược, chỉ còn bộ đếm số câu đã trả lời.

**Ghi chú kỹ thuật (api-contract §2.4, §4):** `<Countdown>` phải tính theo `expires_at` + `server_now` trả về từ `POST /learn/quizzes/{quiz}/attempts` (không dùng đồng hồ máy khách để tránh lệch giờ). Nội dung câu hỏi/đáp án là văn bản thuần có thể chứa đoạn LaTeX `$...$`; card câu hỏi tách phần LaTeX và render bằng `katex.renderToString(tex, { trust: false, strict: 'warn', maxSize: 10, maxExpand: 1000, throwOnError: false })`, phần còn lại render như text React mặc định (không `dangerouslySetInnerHTML`).

### 2.2 Modal cảnh báo chưa trả lời hết (AC2)
`<ConfirmModal>`: tiêu đề "Bạn còn 3 câu chưa trả lời", mô tả "Bạn có chắc muốn nộp bài không?", nút phụ "Quay lại làm tiếp", nút chính (variant `warning`) "Vẫn nộp bài".

### 2.3 `/hoc/{course}/quiz/{quiz}/ket-qua` — Kết quả
**Bố cục:** khối tổng kết trên cùng — vòng tròn điểm số lớn "7,5/10" + "Đúng 15/20 câu" + nhãn nếu tự động nộp do hết giờ ("Bài đã được tự động nộp khi hết giờ" — badge `warning`).
Bên dưới: danh sách từng câu hỏi dạng card:
- Nội dung câu hỏi
- 4 đáp án, đáp án học sinh chọn tô viền (xanh nếu đúng `emerald`, đỏ nếu sai `rose`), đáp án đúng luôn có icon ✔ dù học sinh không chọn (để học từ lỗi sai)
- Nếu học sinh không chọn đáp án nào (do hết giờ) → dòng "Bạn chưa trả lời câu này" (màu `gray-500`), đáp án đúng vẫn hiện

Nút cuối trang: "Làm lại bài kiểm tra" (outline) + "Quay lại khóa học" (primary).

## 3. Bảng trạng thái UI & thông điệp

| Trạng thái | Hiển thị |
|---|---|
| Quiz chưa có câu hỏi (AC6) | `<EmptyState>` "Bài kiểm tra chưa sẵn sàng", mô tả "Giáo viên đang chuẩn bị nội dung, vui lòng quay lại sau", nút "Quay lại khóa học" |
| Chưa mua khóa học (AC5) | Redirect `/khoa-hoc/{slug}` + `<Alert variant="warning">` "Bạn cần mua khóa học để làm bài kiểm tra này" |
| Đang lưu đáp án (autosave) | Icon nhỏ "Đã lưu ✓" thoáng qua cạnh câu vừa chọn, không chặn thao tác |
| Mất mạng khi đang làm bài | Banner nhỏ trên cùng `<Alert variant="warning">`: "Mất kết nối mạng, các câu trả lời sẽ được lưu khi có mạng trở lại" |
| Hết giờ (đồng hồ về 0) | Toàn màn hình khoá tương tác, toast "Đã hết giờ làm bài, hệ thống tự động nộp bài của bạn" → chuyển trang kết quả |
| Đang chấm/nộp bài | Nút "Nộp bài" chuyển `loading`, disable toàn form |
| Số câu lớn (tối đa 200 câu/quiz — data-model CHECK) | Vẫn cuộn dọc bình thường, không phân trang — đảm bảo card câu hỏi nhẹ (không load ảnh nặng) |

## 4. Component React dùng/tạo mới
- `<Countdown>` (dùng lại — `packages/ui`) — **cân nhắc `⚠ Chờ PO xác nhận` có bắt buộc mọi quiz đều dùng hay không, nhưng component thiết kế dùng chung được cho cả 2 trường hợp (ẩn nếu không có time_limit)**
- `<ConfirmModal>` cho cảnh báo nộp bài thiếu câu trả lời (dùng lại — `packages/ui`)
- `<ProgressBar>` dạng "đã trả lời x/y" (dùng lại — `packages/ui`, biến thể nhỏ, không phải % hoàn thành khóa học)
- `<QuizQuestionCard>` (mới — `apps/web`, dùng cả ở màn làm bài và màn kết quả với 2 biến thể prop `mode="taking" | "result"`, render LaTeX qua KaTeX như ghi chú kỹ thuật ở mục 2.1)

## 5. Điểm cần PO duyệt
- Quiz có bắt buộc luôn có `time_limit_minutes` hay tùy chọn (US-007 câu hỏi mở) — ảnh hưởng có cần đồng hồ đếm ngược mặc định hay không. Thiết kế hiện tại: đồng hồ chỉ hiện khi quiz có cấu hình giới hạn thời gian.
