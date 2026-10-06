# Bảng theo dõi VitaminVui

Cập nhật: 2026-10-05. Phiên tiếp theo (kể cả Claude Code on the web) đọc file này trước tiên.

## Trạng thái task

Danh sách task, phụ thuộc và định nghĩa "xong": `docs/architecture/tasks.md`. Báo cáo tổng hợp đã xong / đang làm / chưa làm: `docs/bao-cao-task.md`.

| Task | Tên | Bước hiện tại | Trạng thái | Chặn bởi | Cập nhật |
|---|---|---|---|---|---|
| T01 | Khởi tạo backend Laravel 13 + Docker | Xong | ✅ Review, Security (PASS có điều kiện), QA (PASS) | — | 2026-09-28 |
| T02 | Users, audit_logs, vai trò, staff:* | Xong | ✅ như T01 | — | 2026-09-28 |
| FE0 | Khởi tạo frontend Next.js 16 | Xong | ✅ Review; 64 unit + e2e backend thật | — | 2026-09-25 |
| T03 | Đăng ký/đăng nhập học sinh | Xong | ✅ Review, QA; nợ bảo mật ở backlog-v2 | — | 2026-10-05 |
| FW1 (phần 1 + OTP) | Đăng ký, đăng nhập, đăng xuất, OTP (apps/web) | Xong một phần | ✅ Review, QA; còn màn quên/đổi mật khẩu (API T27 đã có) | — | 2026-10-05 |
| T04 | OTP + GET /auth/me | Xong | ✅ Review, QA (316 test, e2e OTP 11/11) | — | 2026-10-05 |
| T05 | Một phiên học sinh | Xong | ✅ Review, QA (e2e 2 thiết bị 7/7) | — | 2026-10-05 |
| T06 | Chuyên đề CRUD (admin-api) | Xong | ✅ Review, QA (59 test T06+T07, race thật); Minor: lỗi per_page tiếng Anh | — | 2026-10-05 |
| T07 | Schema nội dung + ghi danh | Xong | ✅ Review (kiêm DBA), QA; FK enrollments.order_id làm ở T18 | — | 2026-10-05 |
| T17 | Thanh toán: abstraction + MoMo | Xong (chưa kiểm sandbox) | ✅ Review, QA (141 test); PHẢI kiểm sandbox MoMo trước T20 (backlog T17-1) | — | 2026-10-05 |
| T27 | Quên/đổi mật khẩu học sinh | Xong | ✅ Review, QA (race 8 tiến trình); đã sửa timing dummy hash (ảnh hưởng cả login T03/T28) | — | 2026-10-05 |
| T28 | Đăng nhập quản trị | Xong | ✅ Review, QA (e2e admin thật 15 pass); Minor BUG-1 (csrf 419 sau khi phiên bị huỷ), BUG-2 (thiếu Accept JSON → 500) | — | 2026-10-05 |
| FA1 | Layout quản trị, đăng nhập, MFA, đổi mật khẩu lần đầu | Xong | ✅ Review, QA cùng T28; Minor N1 (từ ngữ MFA sai mã) | — | 2026-10-05 |
| T08 | Quản trị khóa học | Xong | ✅ Review, QA (upload: không lỗ thực thi/XSS); Minor: lỗi validate tiếng Anh (chung toàn dự án) | — | 2026-10-05 |
| T10 | Danh mục công khai + chi tiết | Xong | ✅ Review, QA (320 khóa thật, 0,05–0,09 s) | — | 2026-10-05 |
| T14 | EnrollmentService (xin học, duyệt, grantPurchase) | Xong | ✅ Review, QA (race xoá khóa ↔ xin học đã sửa); chưa có email báo duyệt/từ chối (AC2/AC3) | — | 2026-10-05 |
| FA2 | Màn chuyên đề admin | Xong | ✅ Review, QA (khoá giữa phiên thật, hai tab, 31 chuyên đề); Minor: ẩn/hiện gặp 404 không tải lại, overlay khoá còn lộ khung phía sau | — | 2026-10-05 |
| T09 | Chương/bài (CRUD, sắp xếp, link ngoài) | Xong | ✅ Review (M1 khóa published không xoá được bài cuối), QA (69 test, có race) | — | 2026-10-05 |
| T15 | Mã giảm giá quản trị | Xong | ✅ Review (thêm CHECK DB), QA (48 test, race tạo trùng mã). T16/T18 dùng `Coupon::state()`, `normalizeCode()`; `coupon_usages` do T18 tạo | — | 2026-10-05 |
| T11 | Contract video (upload TUS, webhook, đồng bộ, dọn mồ côi) | Xong | ✅ Review (R1 gọi provider ngoài transaction), QA (35 test, race hạn mức). Chưa có adapter thật: VideoLab ở T12, Bunny chờ tài khoản | — | 2026-10-05 |
| T21 | Soạn quiz (admin) | Xong | ✅ Review, QA (32 test, race). `x<y` bị chặn, dùng `\lt` (ghi vào hướng dẫn FA5) | — | 2026-10-05 |
| Sửa lỗi nhỏ 1 | validation tiếng Việt, T28 BUG-1/2, OTP_INVALID/OTP_EXPIRED, nới OTP e2e, email duyệt đăng ký | Xong | ✅ Review, QA (456 test) | — | 2026-10-05 |
| T16 | Giỏ hàng | Xong | ✅ Review (M1 theo US-013 BR6), QA (59 test, race). T18 phải xử lý đơn 0đ | — | 2026-10-05 |
| T26 | Vận hành queue/scheduler + throttle catalog SSR | Xong | ✅ Review, QA (commit 1ed4c44). Kiểm Nginx xoá header nội bộ trên staging: T31 | — | 2026-10-06 |
| T33 | Tài khoản staff + nhật ký thao tác | Xong | ✅ Review, QA (commit 49d867e). Race test cần chạy lại khi máy rảnh (sửa assert `others`) | — | 2026-10-06 |
| T13 | Học & tiến độ | Xong | ✅ Review, QA (commit b8a5ddb, sửa PHPStan + deadlock heartbeat↔revoke ở 7c72589) | — | 2026-10-06 |
| T12 | VideoLab | Xong | ✅ Review, QA e2e (upload TUS → transcode → webhook → HLS, sandbox 5/5), sửa BUG-1 xoá giữa transcode. Commit 78f8986 | — | 2026-10-06 |
| T31 | Checklist production & mẫu cấu hình | Xong (chờ giá trị thật) | ✅ Review 2 vòng, QA (Nginx dựng thật, grants.sql). Commit a0ebac1. Còn điền tên miền/SMTP/Turnstile/IP khi dựng staging | — | 2026-10-06 |
| Sửa lỗi nhỏ 2 | T03 M2/M3 (bộ đếm đăng nhập nguyên tử), T27-5 (reset không lộ tài khoản), timeout race 180s | Xong | ✅ Review (R1 khoá oan NAT), QA (race 15 tiến trình 12/12 xanh). Commit 0701cff | — | 2026-10-06 |
| T18 | Checkout | Xong | ✅ Review, QA (commit 297a527 + 744c8be Pint). CI worktree sạch: test + race xanh | — | 2026-10-06 |
| Khoá thanh toán + T30 (phần lẻ) | Cờ `FEATURE_PAID_CHECKOUT` (tắt, 503 PAYMENT_DISABLED), `audit:purge` 24 tháng, `users:purge-unverified` 7 ngày | Xong | ✅ Review (M1 khoá ứng viên, M2 cô lập user lỗi), QA (60 test + 5 race). Commit edca8ad | — | 2026-10-06 |
| T23 | Khóa học của tôi + tiến độ | Xong | ✅ Review APPROVE (R1 quiz xoá mềm, R2 test quyền), QA (27 test T23, IDOR). Commit 9ad3765 | — | 2026-10-06 |
| T22 | Làm quiz | Xong | ✅ Review, QA (commit 7919cab) | — | 2026-10-06 |

## Việc tiếp theo (theo thứ tự)

1. **Frontend TẠM DỪNG (PO 2026-10-05) chờ design mới.** Code dở chưa commit, giữ nguyên trong working tree: FW2 (dev xong, e2e 24/24, chưa review; load test cache ấm chưa đạt) và FA3 (dev dở). Khi có design mới: rà lại FW2/FA3 theo design rồi mới review/QA.
2. **Backend làm xong toàn bộ API trước (PO 2026-10-05)**, sau đó PO gửi design cho frontend. Chạy tối đa 4 dev backend song song, mỗi dev một DB test riêng `backend/phpunit.local-a|b|c|d.xml`. Thứ tự:
   - **Backend MVP đã xong toàn bộ** (2026-10-06). Còn: V2 (thanh toán, pháp lý) và điền giá trị thật cho T31 khi dựng staging (xem `docs/ops/production-checklist.md` mục cuối).
   - Production cần: user DB có quyền DELETE trên `audit_logs` (cho `audit:purge`); pháp chế (V2) xác nhận giữ audit 24 tháng và xoá `consents` khi xoá tài khoản chưa xác thực. T20 (V2): `/orders/{code}/pay` phải kiểm cờ `paid_checkout`.
3. Gom sửa lỗi nhỏ (một task riêng): file `lang/vi/validation.php` cho toàn dự án; T28 BUG-1 (csrf 419 sau khi phiên bị huỷ) và BUG-2 (thiếu Accept JSON → 500); mã lỗi OTP riêng `OTP_INVALID`/`OTP_EXPIRED`; nới hạn mức OTP cho môi trường e2e; email báo duyệt/từ chối đăng ký (US-012 AC2/AC3).
4. Frontend (sau khi có design mới): FW1 còn lại, FW2, FA3, FA4, FA6, FA7 và các màn còn lại.
5. **Trước T20**: kiểm phản hồi query của sandbox MoMo (cần tài khoản sandbox từ PO).
6. Mỗi task: dev → `laravel-reviewer` → sửa → `laravel-qa` → tự commit (không push).

## Chờ PO xác nhận (đã chọn mặc định để không chặn)

- T16: mã giảm tiền cố định lớn hơn tổng giá các khóa được áp dụng → giảm tối đa bằng tổng đó (theo US-013 BR6); riêng trường hợp đơn về 0đ thì mã phải có giới hạn lượt dùng và ngày hết hạn. Hết lượt dùng báo COUPON_EXPIRED. Giỏ hàng không yêu cầu xác thực OTP, chỉ chặn ở checkout.
- T21: một chương/bài gắn được nhiều quiz; chưa có API đổi thứ tự quiz/câu; xoá quiz không bị chặn dù đã có lượt làm; công thức dùng `\lt`, `\gt` thay cho `<`, `>` sát chữ.
- T09: xoá chương/bài chỉ bị chặn khi đã có tiến độ học (lesson_progress), không xét học sinh đã ghi danh; xoá bài/chương cuối của khóa đang xuất bản → 409 COURSE_LAST_LESSON. Link ngoài chỉ cho bài học thử, chỉ Vimeo công khai.
- T15: thêm thao tác bật lại mã; trần mã giảm tiền 100.000.000đ; phạm vi mã = khóa chọn ∪ khóa thuộc chuyên đề chọn; xoá chuyên đề thì mã tự thu hẹp; "giá khóa rẻ nhất" (ràng buộc mã 100%) tính trên mọi khóa đang bán.
- T14 R3: duyệt yêu cầu miễn phí khi khóa đã đổi sang có phí → 422 COURSE_NOT_FREE (mặc định).
- T08: xoá khóa bị chặn khi có đăng ký ở mọi trạng thái (kể cả bị từ chối); giáo viên không đổi được giá.
- T27: thông điệp khi thua race dùng mã reset; reset bằng đúng mật khẩu cũ có được không.
- FA2: giáo viên xem chuyên đề ở chế độ chỉ đọc (design ghi 403); bỏ xem trước slug trong form.

## Quy tắc làm việc đã thống nhất với PO

- **2026-10-06 · PO mở lại cổng `laravel-security`** sau khi backend MVP xong: audit theo 4 cụm (1 xác thực/phiên; 2 nội dung/upload/video/học tập; 3 thanh toán T17–T18; 4 T31 + cấu hình tổng thể), chạy 2 cụm một lúc. Critical/High báo PO ngay rồi giao dev sửa; Medium/Low ghi `docs/security/backlog-v2.md`. Song song: "Sửa lỗi nhỏ 3" sửa các mục Low còn mở (trừ thanh toán, pháp lý, mục đã hoãn V2).
- **2026-10-05 · Tạm dừng cổng `laravel-security`** (và việc sửa lỗi bảo mật không nghiêm trọng) để đẩy tiến độ; quy trình mỗi task tạm thời là dev → `laravel-reviewer` → `laravel-qa` → PO duyệt. Lỗi bảo mật đã biết được ghi ở `docs/security/backlog-v2.md`, review lại và sửa ở v2, bắt buộc trước go-live. Lỗi Critical/High vẫn phải báo PO ngay.

- Chỉ commit/push khi PO đồng ý. `main` chỉ chứa code đã qua review.
- **2026-10-05 · PO cho phép commit tự động (không push)** sau khi task qua dev → reviewer → QA PASS, mỗi task một commit; xong task nào thì commit rồi tự chạy task kế tiếp theo thứ tự "Việc tiếp theo", không cần hỏi lại. Chỉ dừng khi gặp quyết định của PO, lỗi Critical/High, hoặc lỗi môi trường không tự xử lý được. Push vẫn do PO tự làm.
- Dev chạy mọi thứ trong Docker ở máy local. Trên cloud, `scripts/cloud-setup.sh` cài trực tiếp (xem CLAUDE.md).
- Không cài package ngoài danh sách đã duyệt trong tasks.md (cổng G2) mà không hỏi PO.
- Không tự bịa field ngoài api-contract. Thấy thiếu hoặc mâu thuẫn thì dừng và hỏi Architect.

## Việc đã hoãn (có người phụ trách)

- **V2 — Thanh toán (PO quyết định 2026-10-06, chờ PO kết nối MoMo):** T19 IPN & fulfillment, T20 đối soát/`/pay`/đơn của tôi, T24 admin đơn hàng, T25 xuất file đơn, phần `payments:purge-webhook-events` của T30, FW3, FA8, FA9. Checkout có tính tiền bị khoá bằng cờ `FEATURE_PAID_CHECKOUT` (mặc định tắt); giỏ hàng, preview, đơn 0đ và đăng ký khóa miễn phí vẫn chạy. Code T18 giữ nguyên, bật cờ khi làm V2.

- Môi trường (2026-10-06): máy host 16 GB thiếu RAM (swap 5/6 GB, VM Docker 8 GB) → load 100–200, agent treo, race test timeout. Chạy tuần tự tối đa 1–2 việc test cùng lúc cho tới khi PO giảm tải máy.
- Nợ kỹ thuật test: các race test dùng tiến trình con có timeout 60s/tiến trình, fail khi máy quá tải (T16 CartRaceTest ở load ~240). Nâng timeout (vd 180s) và chạy nhóm `race` tách riêng trong CI.

- V2 (PO quyết định 2026-10-05): cách khoá đăng nhập chống khoá tài khoản người khác (M1 backlog), ngưỡng AC6; ý nghĩa đổi SĐT qua `/auth/contact`; trang `/dieu-khoan`, `/chinh-sach-du-lieu`; pháp chế (thời hạn lưu IP/UA trong `consents`, quy tắc tuổi/phụ huynh của T29).

- Quyền MySQL/trigger chặn sửa `audit_logs` (DBA, trước staging).
- Header bảo mật cho response do Nginx tự trả (N2), cấu hình log production (L5), giới hạn IP cho `/up`: T31.
- L2: route domain dạng tham số, khi có route như vậy.

## Chờ PO trả lời

1. Throttle `csrf` đang 30 lần/phút/IP. Có nâng lên 120 cho lớp học dùng chung NAT không? nâng lên 120 phút
2. Các mặc định an toàn ở `docs/architecture/README.md` §8 (MFA staff, che PII khi xuất file, TTL link HLS...). tạm thời bỏ qua nếu ko cần thiết
3. LB/CDN production có chuyển tiếp `X-Forwarded-Host` từ client không (chốt ở T31). chưa hiểu câu hỏi
4. Pháp chế: Luật BVDLCN 2025, ngưỡng tuổi cần phụ huynh đồng ý, thời hạn lưu log có IP, chuyển dữ liệu ra nước ngoài., ngưỡng tuổi cần phụ huynh đồng ý tạm thời bỏ qua, thời hạn lưu log có IP bạn tự quyết định,chuyển dữ liệu ra nước ngoài thì ko đc

## Nhật ký

- 2026-09-25 · US-001..018 · BA/Designer/Architect/Security/DBA · Xong tài liệu thiết kế
- 2026-09-25 · T01, T02, FE0 · Dev + Reviewer · Xong, đã sửa R1–R5
- 2026-09-28 · T01, T02 · Security + QA · Security FAIL (H1) → sửa → PASS có điều kiện; QA PASS; 114 test; commit 58fa34d
- 2026-09-28 · T03, FW1 · Dev · Tạm dừng trước khi code, bàn giao cho Claude Code on the web
- 2026-10-05 · T03, FW1 · Dev + Reviewer + Security + QA · Review APPROVE; Security PASS có điều kiện (hoãn v2); QA tìm BUG-1..5, đã sửa, PASS có điều kiện; host local đổi thành api.localhost:3000 và admin-api.localhost:3001; csrf throttle 120/phút
- 2026-10-05 · T04, FW1 (OTP) · Dev + Reviewer + QA · Review APPROVE; QA tìm BUG-1..4 (mã OTP rò vào log, Retry-After chưa expose CORS...), đã sửa, PASS
- 2026-10-05 · T05 · Dev + Reviewer · Một phiên học sinh (ADR-003): bind/tombstone/middleware thật; login bỏ `guest`, register `guest.student`; Review APPROVE, đã sửa R1 (không destroy trước bind), R2 (register 201 không phiên khi bind lỗi), R3, R4, R6; R5 ghi vào T27; chờ QA
- 2026-10-06 · T12, T31, Sửa lỗi nhỏ 2 · Dev + Reviewer + QA · PASS; commit 78f8986, a0ebac1, 0701cff (CI worktree sạch, gồm race)
- 2026-10-06 · T18, khoá thanh toán + T30, T23 · Dev + Reviewer + QA · PASS; commit 297a527, 744c8be, edca8ad, 9ad3765 (CI worktree sạch)
- 2026-10-05 · T21, sửa lỗi nhỏ 1, T16 · Dev + Reviewer + QA · PASS; commit cc14b43, 3366d60, b17c69b (mỗi commit chỉ phần của task)
- 2026-10-05 · T11 · Dev + Reviewer + QA · PASS; commit d9575f7 (chỉ phần T11, CI chạy trên worktree sạch: 982 test, 0 fail)
- 2026-10-05 · Frontend · PO · Tạm dừng đội frontend chờ design mới; dừng FA3 (dev dở), review FW2, e2e web
- 2026-10-05 · T09, T15 · Dev + Reviewer + QA · PASS; composer ci 945 test xanh; thêm DB test riêng cho từng dev (vitaminvui_testing_a|b|c)
- 2026-10-05 · Hạ tầng · Orchestrator · Push origin/main đến 0367da2; sửa frontend/scripts/pnpm.sh và playwright.sh chạy được trên macOS
- 2026-10-05 · T06, T07, T17, T27, T28, FA1 · Dev + Reviewer + QA (chạy song song) · tất cả PASS; composer ci 667 test xanh; queue worker thêm restart: unless-stopped
- 2026-10-05 · T08, T10, T14 · Dev + Reviewer + QA · PASS; sửa race xoá khóa ↔ đăng ký; composer ci 828 test xanh
