# FW3 (US-022) — Giỏ hàng, thanh toán "Liên hệ Quản trị viên", Đơn đã gửi, Đơn của tôi + huỷ (apps/web)

Phạm vi theo mục "FW3 (US-022)" trong `docs/architecture/tasks.md`, api-contract §2.1, §2.3, §2.3.1, story US-022 (mục "Quyết định PO 2026-10-09") và design `docs/design/US-022-*.md`. Phần MoMo (FW3-MoMo: `/checkout/ket-qua`, poll, "Kiểm tra lại", kiểm host `pay_url`) KHÔNG làm.

## Dev (nextjs-dev) — 2026-10-09: XONG, e2e thật 18/18

### Đã làm
1. Route thật trong nhóm `(site)` (tất cả `noindex`, `force-dynamic`, dữ liệu theo người dùng tải ở trình duyệt bằng `authFetch`, không cache chung):
   - `/gio-hang`: `GET /cart`; xoá khóa (`DELETE /cart/items/{id}`), áp/gỡ mã (`PUT|DELETE /cart/coupon`), lỗi mã dưới ô nhập (thông điệp server; 429 kèm thời gian chờ); mọi số tiền lấy từ `pricing` server trả về sau mỗi thao tác; `notices` hiện nguyên văn; khóa không còn bán có nhãn, không tính tiền; giỏ trống/lỗi tải/khách có trạng thái riêng. Mỗi lần một thao tác (khoá bằng ref). Mobile: thanh dính đáy tổng + nút ≥ 44px.
   - `/thanh-toan`: `GET /checkout/preview`; radio card render từ `payment_methods` của server (hiện 1 lựa chọn, chọn sẵn `default_payment_method`; thêm cổng thì tự có thêm lựa chọn, ghi chú chỉ hiện cho `manual`); khối "Quản trị viên sẽ liên hệ bạn qua" (email/SĐT tài khoản + link sửa); ô ghi chú ≤ 500 (đếm ký tự, nhắc không ghi mật khẩu/OTP, chặn `<`/`>` và ký tự điều khiển như server); báo trước khi có `pending_order`; liệt kê `removed_items`; `POST /checkout {expected_total, payment_method, customer_note, replace_pending}` (đơn 0đ bỏ `payment_method`/`customer_note`); chặn bấm kép bằng ref (giữ khoá trong lúc chuyển trang). Xử lý lỗi theo `errors.*`: 409 `PENDING_ORDER_EXISTS` (hộp "Giữ đơn cũ" mở đơn cũ / "Huỷ đơn cũ, đặt đơn mới" gửi lại với `replace_pending: true`), 409 `CHECKOUT_CHANGED` (thay bằng `errors.preview`, nêu lý do, KHÔNG tự gửi lại), 429 `MANUAL_ORDER_LIMIT` (message server + `errors.resets_at` giờ VN), 429 khác, 503 `PAYMENT_DISABLED` (khoá nút, không tự thử lại), 422 `CART_EMPTY` (về giỏ), 403 `ACCOUNT_NOT_VERIFIED` (preview: thông báo + "Xác thực ngay"; POST: sang `/can-xac-thuc`), 422 `customer_note` dưới ô. Gửi lại cùng giỏ (server trả 200 `reused`) → `?dung-lai=1` hiện thông báo "Đơn này bạn đã gửi trước đó".
   - `/thanh-toan/da-gui/{code}` ("Đơn đã gửi", đích link trong thư): mã đơn to + sao chép, "Khi chuyển khoản, vui lòng ghi mã đơn…", "chưa phải trả tiền", kênh liên hệ từ `/config/public.manual_payment.contact` (kênh `null` ẩn; `tel:` từ chữ số; Zalo chỉ nhận `https://zalo.me/…`, tab mới `noopener noreferrer`; mailto kèm mã đơn; nút ≥ 44px), giờ hỗ trợ, câu "VitaminVui không đăng số tài khoản trên website…", hạn chờ giờ VN + "còn N giờ", danh sách khóa, "Huỷ đơn". Đơn không còn `pending` → chỉ trạng thái + link chi tiết (không hướng dẫn liên hệ). Không hiện STK/QR.
   - `/tai-khoan/don-hang` (`?trang=`, 10/trang, `Pagination`) và `/tai-khoan/don-hang/{code}`: nhãn trạng thái tiếng Việt theo bảng US-022, hạn chờ chỉ với `pending`, lịch sử phía học sinh (không ghi chú nội bộ), `cancel_reason` (khi `admin_cancelled`), `replaced_by_code` có link, `coupon_code`, `customer_note` và lý do hiển thị `whitespace-pre-line` (không `dangerouslySetInnerHTML`), "Huỷ đơn" khi `can_cancel` (hộp xác nhận nhắc "đã chuyển khoản thì đừng huỷ"; 409 → tải lại đơn + thông báo "đơn vừa được duyệt", không thử lại). Mã đơn sai định dạng → 404 thật (không gọi API); đơn người khác/không có → "Không tìm thấy đơn hàng" (cùng thông điệp).
2. Header: biểu tượng giỏ kèm số lượng (`cart_count` của `/auth/me`) khi đã đăng nhập VÀ `/config/public.paid_checkout_enabled`; số được làm mới (`AuthProvider.refresh`) sau thêm/xoá. `/tai-khoan`: nút "Đơn hàng của tôi".
3. Trang chi tiết khóa có phí: hết "Sắp mở bán" khi `paid_checkout_enabled`; `can_buy` → "Thêm vào giỏ" (`POST /cart/items`), `in_cart` → liên kết "Xem giỏ hàng" (409 `ALREADY_IN_CART` coi như đã trong giỏ, `ALREADY_OWNED` → tải lại trạng thái). Khách vẫn thấy "Mua khóa học" → đăng nhập.
4. FW7 (AC28): `ACCOUNT_HAS_PENDING_PAYMENT` có `errors.pending_order_code` → dùng thông điệp server + nút "Xem đơn {mã}" (cả ở bước gửi OTP và bước xác nhận).

### File
- Mới: `lib/orders/{schemas,api,errors,format,note,usePaymentConfig,fixtures}.ts` (+ test `schemas`, `errors`, `format`, `note`); `components/orders/{CartScreen,CheckoutScreen,OrderSentScreen,OrderDetailScreen,OrdersScreen,CancelOrderButton,OrderParts,OrdersNotice,useOrderLoad}.tsx` (+ test `CheckoutScreen`, `OrdersScreens`); `components/shell/ShellHeader.test.tsx`; `app/(site)/{gio-hang,thanh-toan,thanh-toan/da-gui/[code],tai-khoan/don-hang,tai-khoan/don-hang/[code]}/page.tsx`; `e2e/{gio-hang-thanh-toan-real.spec.ts,seed-e2e-fw3.sh,run-fw3-real.sh}`, `playwright.fw3.config.ts`.
- Sửa: `lib/routes.ts` (cart, checkout, orderSent, myOrders, myOrder), `lib/auth/api.ts` (`cart_count`), `components/shell/ShellHeader.tsx`, `components/account/AccountView.tsx`, `lib/catalog/cta.ts` (+test: `add_to_cart`, `in_cart` có `href`), `components/catalog/{CourseAction,CourseCtaProvider}.tsx` (+`CourseCta.test`), `lib/privacy/errors.ts` + `components/privacy/DeleteAccountSection.tsx` (+test).
- Thiết kế (designer) giữ nguyên: `components/v2/orders/*`, `app/(v2-preview)/v2/*`, `lib/mock/v2/orders.ts` vẫn là bản xem trước; các khối thật ở `components/orders/*` dựng lại từ đó (cùng class/token, đổi sang dữ liệu thật; không đổi màu/font/khoảng cách).
- Biến môi trường mới: không. `packages/ui`, `packages/api-client`: không sửa.

### Kiểm tra
- `tsc --noEmit` (tsconfig tạm bỏ `.next/dev/types`, `NEXT_DIST_DIR=.next-fw3`): sạch. `eslint .`: sạch. `vitest run` toàn app web: 65 file, 533 test pass (mới: schema/lỗi/định dạng/ghi chú, CheckoutForm 15, giỏ/Đơn đã gửi/chi tiết/danh sách/huỷ 16, ShellHeader 4, CTA thêm vào giỏ, DeleteAccountSection AC28).
- `next build` (trong container e2e, `.next-fw3`): thành công; thư mục đã xoá.
- e2e thật `e2e/seed-e2e-fw3.sh --reset` rồi `e2e/run-fw3-real.sh` (`--workers=1`): 18/18 — khách → đăng nhập kèm next (5 route) + mã sai định dạng 404; luồng chính (Thêm vào giỏ ở trang khóa → badge giỏ → áp mã sai/đúng → xoá khóa → thanh toán 1 radio, không MoMo → gửi đơn có ghi chú → Đơn đã gửi đủ kênh/Zalo tab mới/`tel:`/mailto/câu chống lừa đảo/hạn chờ/giá sau giảm → đơn của tôi → chi tiết → thư "Đã nhận đơn" trong Mailpit có link `/thanh-toan/da-gui/{code}`); 375px không cuộn ngang, bấm kép chỉ 1 POST, gửi lại cùng giỏ dùng lại đơn (1 đơn); 2 tab gửi đơn → 1 đơn; đơn chờ khác nội dung → hộp thay đơn (Esc, Giữ đơn cũ, Huỷ đơn cũ đặt đơn mới → đơn cũ "Đã thay bằng đơn mới" + link); 409 CHECKOUT_CHANGED (tab khác gỡ mã); danh sách 10 + trang 2, chi tiết admin_cancelled/superseded/paid, 375/1280 không cuộn ngang; đơn người khác → không tìm thấy; tự huỷ (Không huỷ/Huỷ, tải lại vẫn huỷ); huỷ gặp 409 (response giả lập bằng `page.route`, vì e2e không có tài khoản QTV); 429 MANUAL_ORDER_LIMIT; đơn 0đ (mã 100%) paid ngay; chưa xác thực → hướng dẫn xác thực. Seed đã `--clean`.

### Điểm lệch / lưu ý contract (chuyển laravel-dev / laravel-architect nếu cần)
- Không lệch shape ở các route đã gọi thật (cart, preview, checkout 409, orders, config/public).
- `GET /cart` không có thông tin đơn chờ (`pending_order` chỉ ở preview) nên cảnh báo "Đang có đơn chờ duyệt" của designer chỉ hiện ở `/thanh-toan`, chưa hiện ở giỏ. Muốn hiện ở giỏ cần thêm `pending_order` vào `GET /cart` (hoặc gọi preview từ giỏ — chưa làm).
- `ApiError.errors` (packages/api-client) khai báo `Record<string, string[]>` nhưng `errors.*` của đơn là chuỗi/số/object (`items_count`, `preview`, `reasons`): FE đọc qua `errorField(): unknown` + zod. Nên sửa type ở api-client sau (đã ghi ở FW7).
- "Hạn chờ" tính theo đồng hồ trình duyệt (`Date.now`), chỉ để hiển thị; hạn thật do server.
- Backlog: nút "Thêm vào giỏ" chưa có ở thẻ khóa trong danh mục/trang chủ (chỉ trang chi tiết); e2e chưa có tài khoản QTV nên chưa kiểm huỷ-vs-duyệt đua thật (T39/FA8).

### Gợi ý cho QA
- Gửi đơn từ 2 trình duyệt/tab khác nhau, bấm kép khi mạng chậm, ngắt mạng giữa lúc gửi rồi bấm lại (phải dùng lại đơn, không thêm thư).
- Đổi giỏ/mã từ tab khác trước khi gửi (CHECKOUT_CHANGED); mã hết lượt giữa chừng.
- Huỷ đơn khi QTV vừa duyệt (cần T39), đơn hết hạn (`orders:expire-manual`), link trong thư khi đơn đã đóng.
- Safari/Firefox chưa thử; 375px với bàn phím ảo ở ô ghi chú (thanh dính đáy).

### Sửa sau review (APPROVE) — cùng story, 2026-10-09
- R1: `AuthProvider.refresh()` giữ nguyên state `user` (và trả về nó) khi làm mới gặp lỗi tạm thời; chưa có user thì vẫn `error` (nút Thử lại). Test mới `lib/auth/AuthProvider.test.tsx` (3 ca).
- R2: bỏ ca AC28 trùng nằm sai describe trong `lib/privacy/errors.test.ts` (còn 1 ca ở `classifyDeleteSendError`).
- R3: `mailto:` chỉ tạo khi email khớp regex đơn giản (không khoảng trắng, `? & # < > " '`): `safeEmail()` trong `lib/orders/format.ts`, dùng ở `OrderParts` và `hasContactChannel`.
- R4: Alert `limit` (429 MANUAL_ORDER_LIMIT) thêm `role="alert"`.
- R5: test component Zalo sai định dạng (`javascript:`) bị ẩn + email có `?bcc=` không thành mailto (`OrdersScreens.test.tsx`).
- Sửa e2e: lấy thư Mailpit theo ĐÚNG mã đơn (thư của lần chạy trước cùng người nhận làm test lúc đỏ lúc xanh).
- Kiểm tra lại: eslint sạch, tsc sạch, vitest toàn app 66 file / 539 test pass (lần chạy đầu 4 test quá 5 giây do máy tải; chạy riêng 3 file đó đều pass), e2e thật 18/18; seed đã `--clean`, `.next-fw3` và tsconfig tạm đã xoá.

## Review (laravel-reviewer) — 2026-10-09: **APPROVE** (0 BLOCKER, 2 SHOULD, 4 NIT)

**Phạm vi:** thay đổi chưa commit trong `frontend/apps/web` (lib/orders, components/orders, 5 route, ShellHeader, CTA khóa có phí, FW7 AC28, e2e). Đã chạy: `vitest run` toàn app web 65 file / 536 test pass; eslint các thư mục đổi sạch. Không chạy lại e2e thật (cần backend/Docker), tin kết quả 18/18 của Dev.

### Tổng quan
Đúng contract (errors.* đọc qua `errorField` + zod, không dùng `context.*`), chặn bấm kép bằng ref, 409 PENDING_ORDER_EXISTS/CHECKOUT_CHANGED/429 MANUAL_ORDER_LIMIT/503/403 xử lý đúng và KHÔNG tự gửi lại. Văn bản người dùng (`customer_note`, `cancel_reason`) hiển thị text (`whitespace-pre-line`), không `dangerouslySetInnerHTML`. Zalo chỉ nhận `https://zalo.me/…` + `noopener noreferrer`; `tel:` dựng từ chữ số; kênh `null` ẩn; không có STK/QR. Mã đơn sai định dạng -> 404, đơn người khác/không có -> cùng thông điệp. Dữ liệu theo người dùng tải ở trình duyệt bằng `authFetch`, route `force-dynamic` + noindex; chỉ `/config/public` (không theo người dùng) được cache 60 giây ở module.

### Phát hiện
**R1 [SHOULD] `refresh()` lỗi tạm thời làm sập trang giỏ sau một thao tác đã thành công**
- Vị trí: `components/orders/CartScreen.tsx:89` (`void refresh()`), `components/catalog/CourseCtaProvider.tsx` (`addToCart`), gốc ở `lib/auth/AuthProvider.tsx:28-33`.
- Vấn đề: `refresh()` ghi thẳng `{status:"error"}` vào state toàn cục khi `/auth/me` lỗi mạng/5xx/429. `RequireUser` (`components/my/RequireUser.tsx:23`) khi đó thay cả giỏ bằng "Không tải được thông tin tài khoản" (mất state vừa cập nhật), và header hiện nút Đăng nhập/Đăng ký dù việc xoá khóa vừa thành công. Chỉ để cập nhật số trên icon giỏ mà đánh đổi như vậy là quá đắt.
- Đề xuất: trong `AuthProvider.refresh`, nếu đang là `user` mà kết quả là `error` thì giữ nguyên state cũ:
  ~~~ts
  const next = toState(await fetchCurrentUser());
  setState((prev) => (next.status === "error" && prev.status === "user" ? prev : next));
  ~~~
  (kèm test: refresh lỗi không làm mất `user`). Không ảnh hưởng nút "Thử lại" ở trạng thái error.

**R2 [SHOULD] Test nhân đôi / đặt sai nhóm ở FW7**
- Vị trí: `lib/privacy/errors.test.ts` — case "ACCOUNT_HAS_PENDING_PAYMENT có errors.pending_order_code…" xuất hiện 2 lần, một lần nằm trong `describe("classifyExportError")` nhưng gọi `classifyDeleteSendError`.
- Đề xuất: xoá bản trong `classifyExportError`. (Mức SHOULD vì làm bộ test gây hiểu nhầm; logic AC28 đúng, không phá FW7: `pendingPaymentMessage` chỉ đổi nhánh khi có `pending_order_code` hợp lệ, còn lại giữ câu cũ.)

**R3 [NIT] `mailto:` không chặn ký tự lạ trong email cấu hình**
- `components/orders/OrderParts.tsx:187`: `mailto:${contact.email}${subject}`. Giá trị do QTV cấu hình nên rủi ro thấp, nhưng `a@b.c?bcc=x` sẽ chèn tham số. Gợi ý: chỉ dựng link khi khớp `/^[^\s@?&#]+@[^\s@?&#]+$/`, nếu không thì hiện chữ không link.

**R4 [NIT] Cảnh báo hạn mức không có `role`**
- `CheckoutScreen.tsx:187` (`kind: "limit"`) thiếu `role="alert"` như các Alert khác; vẫn được focus qua `alertRef` nên đọc được, chỉ không đồng nhất.

**R5 [NIT] Chưa có test cấp component cho Zalo URL lạ**
- Đã có test `safeZaloUrl` (unit) nhưng `OrdersScreens.test.tsx` chỉ kiểm URL hợp lệ; thêm 1 case `zalo_url: "javascript:…"`/`http://zalo.me` -> không có link.

**R6 [NIT] Trùng lặp với `components/v2/orders` (bản preview designer)**
- Chấp nhận được: khối thật dựng lại cùng class/token, đổi sang dữ liệu thật, preview còn dùng làm mẫu thiết kế. Ghi backlog: khi designer đổi giao diện phải sửa 2 nơi; cân nhắc cho preview import `OrderParts` thật sau khi ổn định.

### Đánh giá 2 điểm Dev nêu
1. **Thiếu `pending_order` trong `GET /cart`:** chấp nhận, không chặn. Contract §2.3 chỉ định `pending_order` ở preview; cảnh báo vẫn xuất hiện ở `/thanh-toan` và 409 `PENDING_ORDER_EXISTS` có hộp quyết định, nên không có đường nào đặt đơn trùng mà không được báo. Chỉ thiệt UX ở giỏ -> backlog (đề xuất cho architect: thêm `pending_order` vào `GET /cart` hoặc để giỏ gọi preview).
2. **Type `ApiError.errors`:** cách xử lý hiện tại (`errorField(): unknown` + zod) an toàn, không dùng `any`. Nhưng hiện có 2 helper đọc tương tự (`lib/privacy/errors.ts domainValue`, `lib/orders/errors.ts errorField`). Nên sửa `packages/api-client` thành `Record<string, unknown>` (và `types.ts:9`) trong một task riêng rồi gộp helper; không làm trong FW3.

### Đối chiếu trọng tâm
| Hạng mục | Kết quả |
|---|---|
| 409 PENDING_ORDER_EXISTS / replace_pending | Đúng; hộp Esc/“Giữ đơn cũ”/“Huỷ đơn cũ, đặt đơn mới”; khoá `dismissible` khi đang gửi |
| 409 CHECKOUT_CHANGED | Thay preview, nêu lý do, không tự gửi lại; `expected_total` mới ở lần bấm sau |
| 429 MANUAL_ORDER_LIMIT / 503 / 403 / 422 | Đúng theo `errors.*`; 503 khoá nút |
| Bấm kép | `lock` ref + giữ khoá trong lúc `router.push`; có test và e2e |
| IDOR/404 | Cùng thông điệp, mã sai định dạng không gọi API |
| XSS | Không `dangerouslySetInnerHTML`; ghi chú/lý do là text |
| Liên hệ | `tel:`, Zalo (https://zalo.me), `noopener noreferrer`, kênh null ẩn, không STK/QR |
| Cache/số giỏ | `cart_count` từ `/auth/me`, làm mới sau thêm/xoá (xem R1) |
| CTA khóa có phí | `paid_checkout_enabled` -> “Thêm vào giỏ”/“Xem giỏ hàng”; tắt -> “Sắp mở bán”; 409 ALREADY_IN_CART/OWNED có nhánh |
| FW7 AC28 | Đúng, link `Xem đơn {mã}` (mã được validate bằng regex) |
| a11y/375px | Nút ≥ 44px, thanh dính đáy, focus vào alert; e2e 375px không cuộn ngang |

### Gợi ý cho QA
- Làm `/auth/me` lỗi (tắt mạng/5xx) ngay sau khi xoá khóa trong giỏ để tái hiện R1.
- Hai trình duyệt: gửi đơn đồng thời, đổi mã ở tab khác (CHECKOUT_CHANGED), huỷ vs QTV duyệt (cần T39).
- Link trong thư tới đơn đã đóng/đã hết hạn; đơn người khác; đơn có `customer_note` chứa `<script>` bị server từ chối (422) và nội dung nhiều dòng.
- Safari/Firefox, bàn phím ảo 375px ở ô ghi chú (thanh dính đáy che ô nhập?).

## QA (laravel-qa) — 2026-10-09: **PASS** (0 bug Critical/Major; 0 Minor; 3 ghi nhận NIT/chưa kiểm)

**Cách kiểm:** e2e thật (backend Docker + Mailpit), `--workers=1`, build riêng `.next-qa-fw3` (đã xoá). File mới: `frontend/apps/web/e2e/qa-fw3-extra.spec.ts` (16 ca), `e2e/seed-qa-fw3.sh` (học sinh `fw3-qa-*`, chạy sau `seed-e2e-fw3.sh --reset`), `e2e/run-qa-fw3.sh`, `playwright.qa-fw3.config.ts`. Kết quả lần chạy cuối: **16/16 pass**. Đã `seed-e2e-fw3.sh --clean` (0 user `fw3-%`, 0 đơn `VVQA%` còn lại). Không sửa code ứng dụng, không migrate/seed Laravel.

### Độ phủ
| Yêu cầu | Test | Kết quả |
|---|---|---|
| Ngắt mạng SAU khi server đã tạo đơn, bấm lại | QA1 (route.fetch rồi abort): hiện lỗi mạng, nút mở lại; bấm lại -> `?dung-lai=1`, 2 POST, 1 đơn, đúng 1 thư (Mailpit, chờ thêm 5 giây) | PASS |
| Ngắt mạng TRƯỚC khi tới server, bấm lại | QA2: 1 đơn, 1 thư | PASS |
| 2 trình duyệt cùng giỏ gửi đồng thời | QA3 (context thứ 2 dùng chung phiên qua storageState): cùng mã đơn, 1 đơn, 1 thư | PASS |
| Đổi mã giảm giá ở tab khác -> CHECKOUT_CHANGED | QA4: đổi E2EFW3 -> E2EFW3B; hiện cảnh báo + 240.000đ; chỉ 1 POST sau 4 giây (không tự gửi); chưa có đơn; bấm lại tạo đơn 240.000đ | PASS |
| Hộp "Giữ đơn cũ" | QA5a: Tab không thoát hộp, Enter -> mở đơn cũ; vẫn 1 đơn chờ | PASS |
| Hộp "Huỷ đơn cũ, đặt đơn mới" | QA5b: Esc đóng và trả focus nút Gửi đơn; Enter -> đơn cũ "Đơn đã được thay bằng đơn mới" + link "Xem đơn {mã mới}"; danh sách 2 đơn, chỉ 1 "Chờ duyệt", 1 "Đã thay bằng đơn mới" | PASS |
| Vượt 5 đơn/ngày | QA6: thông báo + "Từ 00:00, 10/10/2026" đúng 00:00 ngày mai giờ VN | PASS |
| Link thư: đơn đã huỷ / hết hạn / của người khác / mã sai | QA7: đơn đóng chỉ hiện trạng thái (không Gọi/Zalo/Huỷ); đơn người khác và không có -> "Không tìm thấy đơn hàng"; `a..b`, `<script>`, `VV%00`, 80 ký tự, `VV-1`, ký tự RTL -> 404 thật ở cả 2 route; không `pageerror` | PASS |
| Ghi chú/lý do chứa `<script>`, `<b>`, `<img onerror>` | QA8 (chèn thẳng DB vì server chặn `<>` ở 422): hiện nguyên dạng chữ, không thẻ `b`/`script`/`img`, `window.__xss` vẫn 0, 2 dòng đúng | PASS |
| Kênh liên hệ | QA9: `tel:0915592224`, Zalo `https://zalo.me/0915592224` `_blank` `noopener noreferrer`, 3 link cao >=44px, "8h–17h", không STK/ngân hàng/QR, có câu "không đăng số tài khoản trên website", hạn "còn 72 giờ" | PASS |
| R1: `/auth/me` lỗi ngay sau khi xoá khóa | QA10: lần 1 trả 500, lần 2 abort (sau áp mã): giỏ giữ nguyên, không "Không tải được thông tin tài khoản", header vẫn tên người dùng + giỏ, không nút Đăng nhập/Đăng ký | PASS |
| 375px không cuộn ngang | QA11 (giỏ, thanh toán, đơn của tôi, tài khoản, Đơn đã gửi), QA15 (hộp thoại thay đơn vừa khung, 2 nút >=44px) | PASS |
| Thanh dính đáy không che ô ghi chú | QA11: viewport 375x380 mô phỏng bàn phím, ô ghi chú nằm trên thanh (chồng lấn 0px) | PASS |
| Bàn phím | QA11 Tab tới "Gửi đơn" + Enter gửi được; QA5a/5b/13 Tab/Enter/Esc trong hộp | PASS |
| Ghi chú: `<`/`>`, 500 ký tự, tiếng Việt | QA12: `<b>` -> `aria-invalid` + gợi ý "không dùng < hoặc >", 0 POST; dán 501 ký tự cắt còn 500 | PASS |
| Huỷ đơn: xác nhận + huỷ lần 2 | QA13: Esc trả focus nút; huỷ ở tab A; tab B (cũ) huỷ lại -> "Đơn đã đổi trạng thái. Đơn hàng đã được huỷ trước đó", hiện trạng thái huỷ, không chữ "duyệt", mất nút Huỷ | PASS |
| FW7 AC28 | QA14: đơn chờ -> "Gửi mã xác nhận" -> nút "Xem đơn VVQADEL0001" đúng href, bấm mở đơn | PASS |
| Huỷ khi QTV vừa duyệt (race thật) | **CHƯA KIỂM**: T39 (duyệt) chưa hoàn tất trên dev; Dev đã giả lập 409 bằng `page.route` (đạt trong 18/18) | Chưa kiểm |

### Bug phát hiện
Không có bug chặn. Ghi nhận:
- **NIT-1** Mã đơn chữ thường (`/thanh-toan/da-gui/vvqacanc001`, `/tai-khoan/don-hang/vvqacanc001`) được chấp nhận và hiện đúng đơn của chính người dùng (so khớp không phân biệt hoa/thường ở DB, regex FE cho phép). Không lộ dữ liệu người khác; chỉ lệch so với "mã sai định dạng -> 404". Dev có thể siết regex FE thành chữ hoa nếu muốn nhất quán.
- **NIT-2** Đăng nhập lần 2 cùng tài khoản đá phiên cũ (đúng thiết kế đăng nhập 1 nơi), nên "2 trình duyệt" phải dùng chung phiên mới cùng đồng thời gửi được; đã kiểm theo cách đó.
- **NIT-3 (đã loại trừ, không phải lỗi)** Hộp thoại dùng `<dialog>` gốc: Tab cuối vòng có thể ra khỏi trang (thanh trình duyệt) rồi quay lại, phần còn lại của trang `inert`, không có phần tử ngoài hộp nhận focus.

### Rủi ro và đề xuất
- Race "huỷ vs QTV duyệt" bằng backend thật phải kiểm sau khi T39/FA8 xong.
- Safari/Firefox, bàn phím ảo thật trên điện thoại chưa thử (chỉ mô phỏng viewport thấp).
- Hạn chờ hiển thị theo đồng hồ trình duyệt (Dev đã ghi); hạn thật do server.
- Máy dev tải cao: API có lúc chậm 10–30 giây, test dùng timeout dài; bài test mạng nên chờ `requestfailed` thay vì `role=alert` (Next route announcer cũng là `role=alert` rỗng).
