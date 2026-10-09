# FA8 (US-022) — Đơn hàng quản trị + duyệt thủ công

## Dev

**Ngày:** 2026-10-09 · **App:** `frontend/apps/admin` · **Phụ thuộc:** T24-V1 (đã commit), T39 (code đã chạy trên dev, đang QA), Design US-022 (`c353b88`)

### Đã làm
- Route thật `/quan-tri/don-hang` (danh sách) và `/quan-tri/don-hang/[code]` (chi tiết). Menu "Đơn hàng" (nhóm Bán hàng) bật `ready`, chỉ hiện khi `permissions.view_orders ?? role !== giao_vien`; giáo viên vào URL -> `ForbiddenView`, không gọi API nào.
- Số đơn chờ trên menu: `PendingOrdersProvider` gọi `GET /admin/orders/pending-count` (KHÔNG dùng `/admin/auth/me`) khi tải layout, mỗi lần chuyển trang, khi quay lại tab (cách lần trước > 30 giây), sau mỗi thao tác (`refresh`) và mỗi 60 giây khi tab hiện (đúng contract; sửa theo R1). Lỗi/429 bỏ qua, giữ số cũ. Câu đọc cho trình đọc màn hình: "N đơn chờ duyệt" (`countLabel`, không sửa `packages/ui`).
- Danh sách: 5 tab trên URL (`tab`): Chờ duyệt (mặc định: `status[]=pending&payment_method=manual&sort=oldest`, KHÔNG gửi khoảng ngày, nhãn "Sắp hết hạn" theo `expiring_soon` của server, "Còn N giờ" tính từ `expires_at`), Đã thanh toán, Đã huỷ (gồm `failed`), Đã hoàn tiền, Tất cả (có thêm lọc trạng thái). Tab khác: từ/đến ngày bắt buộc (mặc định 30 ngày giờ VN, kiểm <= 366 ngày và from <= to ở client trước khi gọi), `q`, phương thức, "Chỉ đơn Cần xem lại", phân trang cursor "Trang trước/Trang sau" (cursor nằm trên URL, F5 giữ), "Khoảng N đơn". Tìm kiếm chỉ chạy khi bấm Tìm/Lọc (không gõ tới đâu gọi tới đó) vì tìm email/SĐT bị giới hạn 30/phút; 429 hiện câu giải thích kèm số giây chờ + nút Tải lại; 422 cursor hỏng -> "quay lại trang đầu".
- Chi tiết: gọi `GET /admin/orders/{code}` ĐÚNG 1 lần khi mở (gộp lời gọi trùng đang bay do StrictMode, không poll, không cache, không lưu storage). Sau thao tác thành công dùng chính phản hồi 200 (không GET lại, không ghi thêm audit `view_pii`). Hiện: email/SĐT đầy đủ + nhãn "Chưa xác thực", Gọi `tel:` / Gửi email `mailto:` (qua `encodeURIComponent`), "Tài khoản đang bị khoá", "Tài khoản đã xoá", ghi chú của học sinh, khóa (giá chốt, giảm, thành tiền, badge Ngừng bán/Đã xoá/Bản nháp, giá hiện tại nếu khác), mã giảm giá, cảnh báo `approval.warnings` (khóa ngừng bán/đã xoá/đã sở hữu), "Cần xem lại" + lý do, lịch sử trạng thái dạng câu ("Đã duyệt bởi X lúc HH:mm dd/mm/yyyy", "Đã duyệt muộn bởi ...", "Tự huỷ do hết hạn chờ lúc ...") kèm lý do gửi học sinh ở dòng huỷ, ghi chú nội bộ, lần thanh toán qua cổng (đơn MoMo).
- Hộp thoại (nút hiện theo `approval.can_approve / can_approve_late / can_cancel`; hoàn tiền khi `paid` và không phải 0đ): Duyệt (tick bắt buộc "Đã nhận đủ {tổng}", nút khoá + câu giải thích, mã giao dịch <= 100, ghi chú <= 1000), Duyệt muộn 2 bước (nền cảnh báo: giờ huỷ, lý do đã gửi học sinh, hạn duyệt muộn, `late_approval_warnings` TRƯỚC khi bấm; "Quay lại" giữ dữ liệu), Huỷ (lý do gửi học sinh 5-500 bắt buộc, câu mẫu, ghi chú nội bộ tách riêng bằng đường kẻ; thiếu lý do -> lỗi dưới ô + focus), Đánh dấu hoàn tiền (tick bắt buộc), thêm ghi chú nội bộ. Chống bấm kép bằng ref + `busy`; hộp không đóng được khi đang gửi; sau thành công xoá hết ô nhập. Hộp huỷ/hoàn tiền focus đầu vào nút an toàn ("Không huỷ"/"Không hoàn tiền") bằng focus tường minh (autoFocus của React không chạy trên `<dialog>` đang đóng).
- 409 (ALREADY_PROCESSED, ORDER_STATUS_CHANGED, ORDER_APPROVAL_WINDOW_PASSED, COURSE_UNAVAILABLE, ORDER_NOT_MANUAL): đóng hộp, tải lại đơn 1 lần, Alert `role=alert` ở đầu trang nêu rõ ai/lúc nào ("Đỗ Thị Mai đã duyệt lúc 19:59 08/10/2026", "Học sinh đã tự huỷ đơn lúc ...", liệt kê khóa đã xoá), nút Duyệt muộn hiện ngay nếu `can_approve_late`. Không tự thử lại. 422 -> dưới đúng ô (`reason`, `note`, `payment_reference`, `body`, `confirm`); 403 -> trang không có quyền; 404 -> "Không tìm thấy đơn hàng"; 429 -> câu chờ có số giây.
- Bảo mật hiển thị (T24-V1 S5): `customer_note`, `notes[].body`, `refund_note`, `cancel_reason`, `attempts[].result_message`, tên học sinh/khóa chỉ render dạng text (`whitespace-pre-line break-words`), không `dangerouslySetInnerHTML`, không tự linkify. Test đơn vị + e2e kiểm bằng ghi chú chứa `<img onerror>` và URL.
- Phản hồi API parse bằng zod (`lib/orders/schemas.ts`); sai hợp đồng -> `ContractError` ("Dữ liệu ... không đúng định dạng") thay vì render `undefined`. Mã lỗi nghiệp vụ đọc từ `errors.*` (object, qua zod), không phải `context.*`.
- 375px + 1280px không cuộn ngang (e2e), nút hành động >= 44px trên mobile, bảng danh sách ẩn cột phụ dưới md/lg và đưa hạn chờ/trạng thái vào dòng mã đơn.

### File
- Mới: `lib/orders/{schemas,query,format,errors,api,permissions,fixtures}.ts`, `lib/orders/PendingOrders.tsx`, `lib/orders/orders.test.ts`, `lib/orders/PendingOrders.test.tsx`; `components/orders/{OrdersScreen,OrderDetailScreen,OrderActions,InternalNotes,OrderBadges}.tsx` + `OrdersScreen.test.tsx`, `OrderDetailScreen.test.tsx`; `app/quan-tri/don-hang/page.tsx`, `app/quan-tri/don-hang/[code]/page.tsx`; `e2e/seed-e2e-fa8.sh`, `e2e/don-hang-real.spec.ts`.
- Sửa: `lib/nav.ts` (Đơn hàng `ready: true`), `components/shell/AdminShell.tsx` (provider + `countLabel`; `navGroups` nhận `pendingOrders`), `components/shell/shell.test.tsx`.
- Không đụng `packages/ui`, `packages/api-client`, `apps/web`, backend, lockfile, `.env*`. Bản xem trước `(v2-preview)/v2/quan-tri/don-hang/**`, `components/v2/orders/**`, `lib/mock/v2/orders.ts` giữ nguyên (designer sở hữu).
- Biến môi trường mới: không.

### Kiểm
- Vitest app admin: 42 file / 518 test xanh (mới: ~60 test cho query/format/errors/schemas/api dedupe, provider số đơn chờ, màn danh sách, màn chi tiết gồm duyệt/bấm kép/409 x3/duyệt muộn 2 bước/huỷ/hoàn tiền/ghi chú/XSS/tài khoản đã xoá/403/404). ESLint toàn app sạch. `tsc --noEmit` sạch (dùng tsconfig tạm trỏ `.next-fa8/types` vì `.next/dev/types` dùng chung đang hỏng cú pháp). `next build` (NEXT_DIST_DIR=.next-fa8) thành công, đã xoá thư mục build và tsconfig tạm.
- E2E thật `e2e/don-hang-real.spec.ts` (18 test, Docker Playwright, dev server :3001 + backend :8000, MFA qua Mailpit): 18/18 xanh. Seed `e2e/seed-e2e-fa8.sh [--reset|--clean]` (học sinh `fa8-*`, staff `e2e-fa8-{admin1,qlt1,gv1}`, khóa `E2E FA8 ...`, 7 đơn chờ + 3 đơn huỷ + 1 đơn đã duyệt + 27 đơn đã duyệt để thử cursor). Chạy `--reset` TRƯỚC mỗi lần (spec duyệt/huỷ/hoàn tiền); đã `--clean` sau cùng. Đợi >= 45 giây giữa các lần chạy (giới hạn đăng nhập MFA), `--workers=1`.

### Sửa sau review (R1–R6)
- R1 `PENDING_POLL_MS` = 60 giây. R2 thêm test fake timers: gọi lại mỗi 60 giây khi tab hiện, tab ẩn không gọi, `visibilitychange` trong 30 giây sau lần gọi trước không gọi lại.
- R3 `seed-e2e-fa8.sh` thêm chốt chặn thứ hai (DB_DATABASE phải là `vitaminvui` và APP_URL chứa `localhost`) và in nhắc chạy `--clean` khi xong.
- R4 hộp Duyệt hiện lỗi 422 `confirm` (và lỗi tick) ngay dưới ô tick. R6 nhánh 409 gọi `resetForm()`.
- R5 mốc `now`/`today` được chụp lại mỗi lần lấy dữ liệu (danh sách: lưu `at` theo từng lần tải, tick khi lọc/tải lại; chi tiết: sau thao tác, sau 409, khi tải lại), không có đồng hồ chạy từng giây.
- Kiểm lại: vitest admin 42 file / 519 test xanh, eslint và tsc sạch (tsconfig tạm đã xoá), e2e 18/18 (seed --reset rồi --clean).

### Chênh lệch contract / lưu ý cho laravel-dev, QA, PO
1. Không có API thiếu hay lệch contract ở các route đã dùng: list, pending-count, detail, approve (thường + `late`), cancel, notes, refund đều chạy thật, kể cả 409 ALREADY_PROCESSED / ORDER_STATUS_CHANGED / COURSE_UNAVAILABLE và `late_approval_warnings`.
2. Server từ chối thẻ HTML trong ghi chú nội bộ bằng 422 `errors.body` (PlainText) -> UI hiện lỗi dưới ô và giữ nội dung (đã e2e). Hai lớp phòng thủ cùng tồn tại (server từ chối + FE render text).
3. Tài khoản e2e: dùng 3 tài khoản `e2e-fa8-*` do seed tạo (mật khẩu `Password123!`) thay vì tài khoản demo `admin@vitaminvui.test` để không phụ thuộc mật khẩu demo; mọi thao tác ghi chỉ chạm đơn của học sinh `fa8-*`.
4. (R1) Chu kỳ làm mới badge đã về 60 giây theo contract.
5. Hoàn tiền: nút hiện cho cả Quản lý trang theo `OrderPolicy`; việc QLT có được hoàn tiền hay không (S3) vẫn chờ PO — UI không tự chặn, 403 từ API hiện ở trang không có quyền.
6. Dev server Docker/macOS: tạo thư mục route mới khi server đang chạy có thể 404 tới khi `touch` file (đã gặp ở `app/quan-tri/don-hang`); không cần restart.
7. Backlog nhỏ: tab "Đã huỷ" gộp cả `failed` (nhãn "Thất bại" trong cột trạng thái); danh sách chưa có xuất file (FA9); chưa test Safari/Firefox.

### Luồng laravel-qa nên kiểm kỹ
- 2 tab cùng duyệt/huỷ (chỉ 1 thành công, tab kia thấy ai đã xử lý); học sinh tự huỷ đúng lúc QTV bấm Duyệt (bước này e2e chưa tự động hoá được vì cần phiên học sinh: chỉ có test đơn vị cho `user_cancelled`) -> hiện Duyệt muộn; Duyệt muộn với cảnh báo `ALREADY_OWNED` / mã vượt lượt; khóa bị xoá giữa chừng; tài khoản học sinh đã xoá (không liên hệ, không Duyệt muộn).
- Số đếm trên menu giảm sau duyệt/huỷ và đúng giữa 2 phiên; giáo viên không thấy menu, URL trực tiếp 403.
- Hộp thoại bằng bàn phím (Tab vòng trong hộp, Esc, focus trả về nút mở) trên 375px.
- Tìm theo email/SĐT 31 lần/phút -> 429 và câu chờ.

## Review

**Kết luận:** APPROVE (0 BLOCKER, 3 SHOULD, 4 NIT)
**Phạm vi:** thay đổi chưa commit trong `frontend/apps/admin` (route don-hang, `components/orders`, `lib/orders`, `AdminShell`, `nav.ts`, `e2e/*fa8*`) · ~25 file
**Đã chạy:** vitest `lib/orders components/orders components/shell` 5 file / 71 test xanh; eslint các thư mục trên sạch. Không chạy `tsc`/`next typegen` (đụng `.next` dùng chung) — tin kết quả của Dev.

### Tổng quan
Bám sát contract §2.5/§2.5.1: `errors.*` đọc qua zod (`lib/orders/errors.ts:33`), tab Chờ duyệt không gửi khoảng ngày, 409 tải lại đúng 1 lần và không tự thử lại, dùng phản hồi 200 sau thao tác (không GET lại, không thêm `view_pii`). S5 đạt: không `dangerouslySetInnerHTML`/linkify/storage (grep sạch), `tel:`/`mailto:` qua `encodeURIComponent` (`lib/orders/format.ts:121-126`), chi tiết gọi 1 lần có gộp lời gọi trùng (`lib/orders/api.ts:43-57`), không poll chi tiết. Tìm kiếm chỉ chạy khi bấm Tìm/Lọc, 429 có số giây.

### Phát hiện
**R1 [SHOULD] Chu kỳ poll `pending-count` 180s lệch contract/tasks (60s) mà không sửa tài liệu**
- Vị trí: `lib/orders/PendingOrders.tsx:19`; contract `api-contract.md:593`, `tasks.md:844`.
- Vấn đề: tự đổi 60 → 180 theo diễn giải "không poll dày", không có quyết định PO/Architect. Rate limit `admin-order-read` 120/phút nên 60s không gây hại; badge cũ tới 3 phút làm yếu mục tiêu Q14 (QTV thấy đơn mới sớm). Làm mới khi đổi trang/quay lại tab đã bù một phần.
- Đề xuất: hoặc trả về 60_000, hoặc Architect/PO xác nhận 180s và sửa contract §2.5.1 + tasks.md:844 cùng lúc để QA không báo lệch.

**R2 [SHOULD] Thiếu test cho phần poll/visibility của provider**
- Vị trí: `lib/orders/PendingOrders.test.tsx` (chỉ 2 test).
- Vấn đề: không có test cho interval (fake timers), chỉ poll khi tab hiện, `visibilitychange` cách < 30s không gọi lại, huỷ lời gọi cũ. Đây là hành vi lệch contract nên cần khoá bằng test.
- Đề xuất: 2–3 test với `vi.useFakeTimers()` + mock `document.visibilityState`.

**R3 [SHOULD] Seed `e2e-fa8-*` tạo tài khoản Admin mật khẩu cố định; chặn môi trường còn lỏng**
- Vị trí: `e2e/seed-e2e-fa8.sh:19-20`, `:48-53`.
- Điểm tốt: có chặn `APP_ENV` ∉ {local, testing}, chạy trong `infra` compose, chỉ dùng tinker, nhận diện theo tiền tố, có `--clean`; spec tự bỏ qua nếu không có `E2E_REAL_BACKEND=1` (`don-hang-real.spec.ts:15`).
- Rủi ro còn lại: `APP_ENV` chỉ đọc từ container; nếu docker context/compose trỏ vào máy dùng chung có `APP_ENV=local` (staging cấu hình sai) thì sinh Admin với `Password123!`. Tài khoản Admin này cũng còn lại nếu quên `--clean`.
- Đề xuất: thêm điều kiện thứ hai (vd `APP_URL` chứa `localhost`/`.test`, hoặc `DB_HOST` đúng service `mysql` của compose); in nhắc `--clean` ở cuối lệnh seed; ghi vào README e2e rằng tài khoản này tuyệt đối không dùng ngoài local.

**R4 [NIT] 422 field `confirm` không có nơi hiển thị**
- Vị trí: `components/orders/OrderActions.tsx:119-123`. `errors.confirm` được đưa vào `FIELD_KEYS` nhưng không ô nào render; message rỗng → không thấy gì, hộp không báo. FE luôn gửi `confirm:true` nên khó xảy ra; nên rơi về banner chung.

**R5 [NIT] Mốc thời gian đóng băng lúc mở trang**
- Vị trí: `OrderDetailScreen.tsx:58`, `OrdersScreen.tsx:76-77` (`now`, `today` qua `useState`). Mở tab lâu thì "Còn N giờ"/"Sắp hết hạn" ở chi tiết và "ngày hôm nay" mặc định lệch (danh sách có `expiring_soon` từ server nên ổn). Chi tiết tính `expiringSoon` ở client thay vì dùng cờ server nếu có.

**R6 [NIT] 409 giữ dữ liệu nhập trong state**
- Vị trí: `OrderActions.tsx:114-117` đóng hộp nhưng không `resetForm()`; mã giao dịch/ghi chú nội bộ ở lại tới lần mở sau (có `open()` reset nên chỉ là bộ nhớ). Gọi `resetForm()` cho nhất quán với nhánh thành công (dòng 110).

**R7 [NIT] Trùng mã với bản xem trước của designer**
- `components/v2/orders/{OrderBadges,OrderActions,InternalNotes}.tsx` còn `adminStatus`, `DeadlineCell`, `REASON_SAMPLES`... song song bản thật. Chấp nhận vì designer sở hữu; ghi backlog xoá/đổi preview sang import `lib/orders/format` khi dọn v2-preview.

### Đối chiếu trọng tâm
| Mục | Kết quả |
|---|---|
| Contract + `errors.*` | Đạt (zod `conflictPayloadSchema`, field map 422) |
| S5 | Đạt: text thuần, không linkify, mã hoá `tel/mailto`, không storage, GET chi tiết 1 lần, test XSS có |
| Chống bấm kép / hộp không đóng khi gửi | Đạt: `lock` ref + `busy`, `dismissible={!busy}`, `close()` chặn khi busy; có test bấm kép |
| 409 | Đạt: đóng hộp, tải lại 1 lần, thông báo ai/lúc nào, hiện Duyệt muộn theo `can_approve_late`, không tự thử lại |
| Quyền menu + 403 GV | Đạt: `canViewOrders`, `ForbiddenView`, không gọi API; provider `enabled` |
| Poll pending-count | 180s lệch contract: R1/R2 |
| Tìm kiếm + 429 | Đạt: chỉ khi submit, nhắc hạn mức 30/phút, 429 kèm giây + Tải lại |
| a11y / 375px | Đạt theo code (nút >=44px mobile, label, `role=alert`, focus nút an toàn); e2e 375px do Dev báo, chưa tự chạy lại |
| Seed e2e | Có chặn APP_ENV; xem R3 |

### Gợi ý cho QA
- 2 tab cùng duyệt/huỷ; học sinh tự huỷ đúng lúc bấm Duyệt (chỉ có test đơn vị).
- Badge sau duyệt/huỷ ở 2 phiên; đổi tab ẩn/hiện; chờ > 3 phút (vì 180s) hoặc theo quyết định R1.
- Tìm email/SĐT 31 lần/phút -> 429; cursor sửa tay -> thông báo quay lại trang đầu.
- Bàn phím trong hộp thoại ở 375px (Esc khi đang gửi không đóng), focus trả về nút mở.
- Đơn tài khoản đã xoá, khóa bị xoá giữa chừng, `ALREADY_OWNED`/mã vượt lượt khi duyệt muộn.
- Chạy `tsc` sau khi `.next` được dọn (R: Reviewer không chạy).

**Bước tiếp theo:** có thể chuyển `laravel-qa`; Dev xử lý R1–R3 (nên) trong cùng task, R4–R7 tuỳ chọn.

## QA

**Kết quả:** PASS (0 Critical, 0 Major, 2 Minor ghi nhận)
**Ngày:** 2026-10-09 · **Phạm vi:** `/quan-tri/don-hang`, `/quan-tri/don-hang/[code]`, hộp Duyệt / Duyệt muộn / Huỷ / Hoàn tiền, ghi chú nội bộ, số đơn chờ trên menu. Backend thật (:8000), admin dev :3001, web dev :3000, thư qua Mailpit.
**Đã chạy:** e2e thật mới `frontend/apps/admin/e2e/qa-fa8-flow-real.spec.ts` (11 test, `--workers=1`, 2 phiên QTV: admin1 và qlt1, học sinh `fa8-qa-*` trên web): 11/11 xanh (L1-L7 một lần chạy liền; L8-L10 chạy lại sau khi sửa lỗi của chính test, xem dưới). Không chạy lại 18 test của Dev (không đổi code ứng dụng). Không chạy tsc/vitest (không sửa code ứng dụng; Dev đã báo xanh).

### Độ phủ
| Yêu cầu | Test | Kết quả |
|---|---|---|
| AC1/AC15 Luồng trọn: HS thêm khóa vào giỏ, áp mã FA8QAA, thanh toán, "Đơn đã gửi" (270.000đ); QTV thấy ở tab Chờ duyệt (email/SĐT che `***`), số menu phiên khác +1 sau chuyển trang | L1 | PASS |
| AC17 Duyệt: ghi chú HS và mã giảm giá hiện ở chi tiết, "Đã duyệt bởi E2E FA8 Admin lúc HH:mm dd/mm/yyyy", mã giao dịch hiện; số menu phiên Q giảm về mức cũ sau chuyển trang | L2 | PASS |
| AC13 HS sau duyệt: Đơn của tôi "Đã thanh toán", "Các khóa trong đơn đã mở", Khóa học của tôi có khóa, `/hoc/{id}` vào được (200, không về đăng nhập), giỏ không còn khóa, thư "Đơn #… đã được xác nhận thanh toán" trong Mailpit | L3 | PASS |
| AC20/AC14 QTV huỷ kèm lý do: HS thấy "Quản trị viên đã huỷ đơn" + lý do; ghi chú nội bộ KHÔNG lộ ở web và thư; thư "Đơn #… đã bị huỷ" kèm lý do | L4 | PASS |
| Số menu giảm khi KHÔNG chuyển trang (poll) | L4 | PASS, giảm sau 58 giây (khớp chu kỳ 60 giây) |
| Mã giảm giá được nhả khi huỷ (max 1): HS khác áp được FA8QAMAX, đặt đơn, được duyệt | L5 | PASS |
| AC22 Duyệt muộn khi mã vượt lượt: hộp bước 1 cảnh báo TRƯỚC khi bấm ("Mã giảm giá FA8QAMAX đã hết lượt: đơn sẽ gắn cờ Cần xem lại", giờ huỷ, lý do đã gửi HS, hạn 08/11/2026); sau duyệt "Cần xem lại" + "Mã giảm giá đã vượt số lượt cho phép" | L6 | PASS |
| AC21 HS tự huỷ đúng lúc QTV mở hộp Duyệt: bấm Duyệt ra 409, Alert `role=alert` "Chưa duyệt: đơn vừa đổi trạng thái. Học sinh đã tự huỷ đơn lúc 14:21 09/10/2026 …", nút Duyệt muộn hiện, nút Duyệt biến mất | L7 | PASS |
| Hộp thoại bằng bàn phím 375px và 1280px (Duyệt, Huỷ): Tab / Shift+Tab 14 lần không rời hộp, Esc đóng, focus về nút mở, không cuộn ngang, hộp nằm trong khung nhìn, nút >= 44px ở 375px | L8 | PASS |
| Ghi chú nội bộ chứa `<script>`/`<img onerror>`: 422 hiện dưới ô, giữ nguyên nội dung, không thêm ghi chú, không có script chạy | L9 | PASS |
| Tìm email/SĐT: lần thứ 31 trong phút bị 429; UI hiện "… giới hạn 30 lần/phút. Vui lòng thử lại sau 57 giây" + nút Tải lại | L10 | PASS |
| Số liệu DB sau chạy | tinker | FA8QAA used_count=1; 3 đơn paid đều có 1 enrollment + 1 `coupon_usages`; đơn duyệt muộn `needs_review=true`; đơn HS tự huỷ `user_cancelled`, 0 enrollment; audit `order.manual_approve/manual_cancel/note_add` có. |
| Chưa dựng được | Duyệt muộn khi HS đã sở hữu khóa từ đơn khác ngay trên đơn pending (Dev đã phủ bằng đơn hs9); tab thứ 2 cùng tài khoản cùng duyệt (Dev đã phủ 409 ALREADY_PROCESSED) | Dựa vào e2e của Dev |

### Bug phát hiện
Không có bug chặn. Ghi nhận 2 điểm Minor (không chặn PASS):

**BUG-1 [Minor]: thông báo 422 ghi chú nội bộ lộ tên trường tiếng Anh**
- Bước tái hiện: chi tiết một đơn, ô "Thêm ghi chú" nhập `<script>alert(1)</script>`, bấm Lưu ghi chú.
- Mong đợi: câu tiếng Việt tự nhiên cho QTV (ví dụ "Ghi chú chỉ được chứa văn bản thuần …").
- Thực tế: "Trường body chỉ được chứa văn bản thuần, không có thẻ HTML, ký tự điều khiển, ký tự ẩn hay dấu kết hợp quá nhiều." (hiện đúng dưới ô, nội dung được giữ).
- Vị trí nghi ngờ: lang/validation message của rule PlainText cho field `body` ở backend (FE chỉ hiển thị lại `errors.body`); sửa bằng `attributes()` của `StoreOrderNoteRequest` (laravel-dev).

**BUG-2 [Minor / dev-env]: route web `/thanh-toan/da-gui/[code]` trả 404 trên dev server :3000 cho tới khi `touch page.tsx`**
- Đã gặp ở lần chạy đầu: sau "Gửi đơn" học sinh thấy "Không tìm thấy trang" dù đơn đã tạo. Đúng loại lỗi Dev ghi ở mục 6 (thư mục route mới khi server đang chạy). `touch` file trang xong thì 200, không ảnh hưởng production (build). Chỉ cần lưu ý cho ai chạy e2e trên dev server.

### Rủi ro và đề xuất
- Thời gian poll số đơn chờ đo được 58 giây, đúng 60 giây của contract; tab ẩn thì không poll (đã có test đơn vị).
- Hoàn tiền cho Quản lý trang (S3) vẫn chờ PO (ghi nhận ở mục Dev 5).
- Tìm email/SĐT 30 lần/phút dùng chung theo tài khoản QTV: người thử 31 lần sẽ bị chặn cả các tìm kiếm email/SĐT khác trong ~1 phút (đúng thiết kế, S1).
- `failed_jobs` có 2 dòng cũ `SendVideoLabWebhookJob` (HTTP 404, 08/10) không liên quan FA8.
- Chưa kiểm Safari/Firefox; chưa chạy `tsc` riêng (không sửa code).

### Dọn dẹp
Đã chạy `seed-e2e-fa8.sh --clean` và dọn dữ liệu `fa8-qa-*` (4 học sinh, giỏ, 2 mã `FA8QA*`); kiểm lại 0 user `fa8-%`, 0 khóa "E2E FA8", 0 mã `FA8QA*`. Đã xoá `test-results`. Không dùng `.next` chung. Thư test còn trong Mailpit (không ảnh hưởng). File giữ lại: `frontend/apps/admin/e2e/qa-fa8-flow-real.spec.ts`. Cách chạy lại: dọn mã `FA8QA*` + giỏ trước khi `seed-e2e-fa8.sh --reset` (FK `coupons.created_by` làm `--clean` thất bại nếu còn mã do admin e2e tạo), và chạy runner Docker có chuyển tiếp thêm cổng 3000.
