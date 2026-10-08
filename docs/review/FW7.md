# FW7 — Quyền dữ liệu cá nhân, chấp nhận lại chính sách, huỷ nhận thông báo phụ huynh (apps/web)

Phạm vi theo ADR-006 (không còn phụ huynh đồng ý/từ chối/rút lại) và định nghĩa FW7 trong `docs/architecture/tasks.md`. Backend T29 + T34 đã có.

## Dev (nextjs-dev) — 2026-10-08: XONG, e2e thật 12/12

### Đã làm
1. `/tai-khoan/quyen-du-lieu-ca-nhan` (link từ `/tai-khoan`, mục "Dữ liệu cá nhân"), 4 khối:
   - Đồng ý (`GET /me/consents`): loại, phiên bản, ngày, kênh; badge Đang hiệu lực/Bản cũ/Đã rút; bỏ loại `parent_consent`; không có ip/ua (server không trả, FE không hiển thị).
   - Thông tin phụ huynh (`GET|PUT /me/parent-contact`): bản che, trạng thái (Đang nhận / Phụ huynh đã ngừng nhận / Chưa có email / Tạm chưa gửi), giải thích phụ huynh nhận thư gì và huỷ nhận được; form sửa (ô trống = giữ, nút "Xoá" = `null`, bắt buộc mật khẩu hiện tại, lỗi 422 dưới đúng ô, 429 khoá nút).
   - Tải dữ liệu (`GET|POST /me/data-export`): "Còn N lượt hôm nay"; hộp thoại mật khẩu; POST dùng fetch riêng (CSRF + Device-Id, retry 419) -> blob -> `<a download>`, tên file từ `Content-Disposition` (chỉ nhận `[A-Za-z0-9._-]+.json`, không thì tên mặc định theo ngày VN); 429 `DATA_EXPORT_LIMIT` khoá nút + "Bạn đã tải 2 lần hôm nay. Thử lại sau 00:00, dd/mm/yyyy." (`errors.resets_at`, giờ VN); 500 hiện `request_id`.
   - Xoá tài khoản: cảnh báo hậu quả -> gửi OTP -> nhập mã (không tự gửi khi nhập đủ) -> xoá. 403 `ACCOUNT_NOT_VERIFIED` (link Xác thực email), 409 `ACCOUNT_HAS_PENDING_PAYMENT` (`errors.retry_after_at`, giờ VN; cũng xử lý khi nhận ở bước xác nhận), 422 `OTP_INVALID`/`OTP_EXPIRED`, 429 hết 5 lượt (phải gửi lại), 429 throttle, `ResendCode` theo `resend_available_at`. Xong: bỏ cache CSRF, cờ flash `account-deleted`, tải cứng `/` + toast "Tài khoản của bạn đã được xoá.".
2. Banner "Điều khoản đã cập nhật" (`PolicyAcceptanceBanner`, trong `SiteShell`, không chặn học/mua; không gắn vào layout học `(learn)` cho yên tĩnh): khi `/auth/me.needs_policy_acceptance`; lấy phiên bản từ `GET /me/consents` meta; 2 ô không tick sẵn; 409 `CONSENT_VERSION_CHANGED` dùng `errors.current_version`, bỏ tick, nhắc xem lại.
3. `/phu-huynh/huy-nhan-thong-bao?t=` (nhóm route `(public)`, khung tối giản, không `/auth/me`): đọc token rồi `history.replaceState` gỡ `?t=`; không tự POST; một nút, chặn bấm kép (ref); thông điệp chung duy nhất; `robots noindex`; `Referrer-Policy: no-referrer` đặt ở `proxy.ts` (+ `metadata.referrer`); `publicFetch` (credentials omit, không CSRF). Token chỉ giữ trong state: F5 sau khi gỡ `?t=` -> "Liên kết không dùng được" (mở lại link từ thư).
4. `/dieu-khoan`, `/chinh-sach-du-lieu`: nội dung TẠM, nhãn "Bản tạm — chờ pháp chế", phiên bản từ `/config/public`. Nội dung viết trong `components/policy/*Content.tsx` (không đặt `content/policies/<version>/` như tasks.md vì BA/PO chưa giao văn bản; khi có thì thay hai file này).

### File
- Mới: `lib/privacy/{schemas,api,errors,format,download,parentContact,unsubscribe}.ts` (+ test `download`, `errors`, `parentContact`), `components/privacy/*` (ConsentsSection, ParentContactSection, DataExportSection, DeleteAccountSection, PrivacyDataView, PolicyAcceptanceBanner, UnsubscribeView, SectionState, useLoad, + test Banner/Unsubscribe), `components/policy/*`, `app/(site)/tai-khoan/quyen-du-lieu-ca-nhan/page.tsx`, `app/(public)/{layout.tsx,phu-huynh/huy-nhan-thong-bao/page.tsx}`, `e2e/{quyen-du-lieu-real.spec.ts,seed-e2e-fw7.sh,run-fw7-real.sh}`, `playwright.fw7.config.ts`.
- Sửa: `lib/routes.ts` (`privacyData`, `parentUnsubscribe`), `lib/auth/flash.ts` + `AccountBanner(.test)` (flash `account-deleted`), `AccountView.tsx` (link thay "Sắp có"), `SiteShell.tsx`, `proxy.ts` (+test), 2 trang chính sách, `next.config.ts`.
- `next.config.ts` KHÔNG đổi (đã bỏ `NEXT_TSCONFIG`/`tsconfig.e2e.json` theo review M1). `.next/dev/types/validator.ts` dùng chung vẫn import `cho-phu-huynh/page` đã gỡ ở FW1 nên `next build`/`tsc` mặc định cục bộ lỗi cho tới khi `next typegen` (CI) sinh lại; e2e né bằng cách `e2e/run-fw7-real.sh` chồng một tsconfig tạm (bỏ dòng `.next/dev/types`) lên `tsconfig.json` CHỈ trong container.
- Biến môi trường mới: không (`NEXT_TSCONFIG` chỉ dùng khi build kiểm tra).

### Kiểm tra
- `tsc --noEmit`: chỉ lỗi 2 dòng `.next/dev/types/validator.ts` nêu trên (không thuộc code FW7); build trong container e2e (tsconfig tạm) thành công.
- `eslint .`: sạch.
- `vitest run` toàn app: 58 file, 458 test pass (mới: download, errors, parentContact, Banner, Unsubscribe, proxy no-referrer, flash, và sau review: `lib/privacy/api` (downloadDataExport), `DeleteAccountSection`, `DataExportSection`).
- e2e thật: `e2e/seed-e2e-fw7.sh --reset` rồi `E2E_FW7="unsub=.. unsubmain=.." e2e/run-fw7-real.sh` (`--workers=1 --trace=off`): 12/12 pass (chính sách tạm x2, huỷ nhận x4 gồm no-referrer/noindex/không tự POST/bấm kép/token sai cùng thông điệp, đồng ý + phụ huynh che/sửa/xoá + 375px không tràn ngang, banner chấp nhận lại, tải file thật + hết lượt, xoá chưa xác thực 403, xoá thật qua OTP Mailpit + không đăng nhập lại được). Seed đã `--clean` (kể cả tài khoản đã ẩn danh).

### Sửa sau review (APPROVE) — cùng story
- M1: bỏ `NEXT_TSCONFIG` khỏi `next.config.ts` (không còn diff), xoá `tsconfig.e2e.json`; workaround chỉ ở `e2e/run-fw7-real.sh` (tsconfig tạm mount trong container) và bỏ khỏi `playwright.fw7.config.ts`.
- L1: `proxy.ts` chuẩn hoá `/` cuối trước khi so khớp, thêm case test `/phu-huynh/huy-nhan-thong-bao/?t=`.
- L2: `clearCsrfToken` chỉ còn trong `confirmAccountDeletion` (`lib/privacy/api.ts`).
- L3: thông điệp thiếu token thêm "tải lại trang sẽ làm mất mã … mở lại liên kết trong thư".
- M3: thêm test `downloadDataExport` (header CSRF + Device-Id, 419 retry đúng 1 lần và không lặp vô hạn, tên file hợp lệ/không hợp lệ, 429 DATA_EXPORT_LIMIT), `DeleteAccountSection` (xoá xong: `clearCsrfToken` 1 lần + flash + `assign("/")`; 409 ở bước xác nhận; 429 khoá nút), `DataExportSection` (429 khoá nút + giờ đặt lại).
- Chạy lại: vitest toàn app 458/458, eslint sạch, e2e thật 12/12 (vì chạm luồng xoá), seed đã `--clean`.
- Backlog (không làm ở story này): M2 token trong access log nginx (cần `$uri`/che query ở `infra/`), L4 sửa type `ApiError.errors` ở api-client.

### Điểm lệch / lưu ý contract
- `ApiError.errors` (packages/api-client) khai báo `Record<string, string[]>` nhưng `errors.resets_at`/`retry_after_at`/`current_version` là chuỗi; FE đọc qua `domainValue()` (chấp nhận chuỗi hoặc mảng). Nên sửa type ở api-client sau (ngoài phạm vi).
- Không phát hiện lệch shape ở các route §2.8 (đã gọi thật). `GET /me/parent-contact` phẳng (không bọc `data`) đúng contract.
- Chưa e2e được: 409 `ACCOUNT_HAS_PENDING_PAYMENT` (`paid_checkout_enabled=false` ở dev nên không tạo được), 429 `DATA_EXPORT_LIMIT` qua POST thứ 3 (UI đã khoá nút từ trước nên chỉ có unit test phân loại lỗi), 409 `CONSENT_VERSION_CHANGED` (unit test).

### Nên QA kỹ
- Xoá tài khoản: bấm kép nút xác nhận, 2 tab, thiết bị thứ hai nhận `SESSION_REVOKED`, đăng ký lại bằng email cũ.
- Tải dữ liệu: header `Content-Disposition` qua CORS thật (trên staging/prod; nếu không expose thì FE dùng tên mặc định), file lớn, 500 không tốn lượt.
- Trang huỷ nhận: mở link từ thư thật (Referer, quét link không tự huỷ), One-click `List-Unsubscribe` phía server.
- Banner chấp nhận lại trên mobile 375px; thay `policy_version` giữa lúc banner đang mở.
- Hai bản văn bản chính sách là TẠM: cần pháp chế thay trước khi mở chính thức.

## Review (laravel-reviewer) — 2026-10-08

**Kết luận: APPROVE** (0 Critical, 0 High, 3 Medium, 4 Low). Phạm vi: diff chưa commit trong `frontend/apps/web` (11 file sửa + file mới privacy/policy/(public)/e2e). Đã chạy lại: vitest (components/privacy, lib/privacy, proxy, components/auth) 11 file / 99 test pass; tsc `-p tsconfig.e2e.json` và eslint không có lỗi.

### Tổng quan
Làm chắc tay: token huỷ nhận chỉ nằm trong state, URL được dọn bằng `replaceState`, không tự POST, `publicFetch` (credentials omit, không CSRF), thông điệp thành công duy nhất, no-referrer cả ở header lẫn meta. Lỗi đọc đúng `errors.*`, có `domainValue()`. POST export gắn CSRF + Device-Id, retry 419 một lần, tên file lọc whitelist. Không hiển thị ip/ua. Sau xoá tài khoản: bỏ cache CSRF, tải cứng `/`, cờ flash chỉ là chuỗi cố định.

### Phát hiện
**M1 [Medium] `NEXT_TSCONFIG` + `tsconfig.e2e.json`: nên bỏ** — `next.config.ts:12`, `tsconfig.e2e.json`.
Đây là workaround cho một artifact cục bộ (`.next/dev/types/validator.ts` còn import `cho-phu-huynh/page` đã gỡ), không phải lỗi của code. Hại: thêm một cổng cấu hình đọc từ env vào `next.config.ts` (chạy cả production), thêm tsconfig thứ hai dễ lệch với `tsconfig.json`, và che lỗi thật (typecheck của CI `next typegen && tsc` đã tự sinh lại `.next/dev/types`, nên CI sạch không cần). Đề xuất: xoá hai thứ này; chỗ cần né thì chạy `typecheck` (đã có `next typegen`) hoặc dọn `.next` cục bộ; e2e đã có `NEXT_DIST_DIR` riêng là đủ. Nếu muốn giữ để tiện: chỉ đặt trong `playwright.fw7.config.ts`/script, không đặt ở `next.config.ts`.

**M2 [Medium] Token nằm trong access log** — `app/(public)/phu-huynh/huy-nhan-thong-bao/page.tsx` (route), nginx `infra/` (ngoài phạm vi diff).
`replaceState` chỉ dọn lịch sử trình duyệt; request đầu tiên `GET ...?t=<token>` vẫn vào log Nginx/Next/CDN và Referer (đã chặn). Token là quyền huỷ nhận, rủi ro thấp (tác động chỉ là ngừng nhận thư) nhưng nên ghi vào backlog: log nginx cho location này dùng `$uri` thay `$request_uri` (hoặc `log_format` che query), và xác nhận token trong thư có hạn/one-way ở backend. Không chặn merge.

**M3 [Medium] Thiếu test cho các luồng nhạy cảm** — `components/privacy/DeleteAccountSection.tsx`, `DataExportSection.tsx`, `ParentContactSection.tsx`, `lib/privacy/api.ts:70-96`.
Chỉ có unit cho classifier/parentContact/download/banner/unsubscribe; e2e thật bù được (12/12) nhưng không chạy trong `vitest`/CI. Chưa có test: `downloadDataExport` (419 retry đúng 1 lần, lỗi -> `ApiError`, header CSRF), nhánh 409 `ACCOUNT_HAS_PENDING_PAYMENT` ở bước xác nhận, 429 `DATA_EXPORT_LIMIT` khoá nút, xoá xong gọi `assign("/")` + `clearCsrfToken`. Nên bổ sung ít nhất test `downloadDataExport` (mock `fetch`) và DeleteFlow (QA có thể làm).

**L1 [Low] `proxy.ts:59` so khớp đường dẫn chính xác** — `request.nextUrl.pathname === routes.parentUnsubscribe`. Biến thể có `/` cuối hoặc hoa/thường khác có thể trượt header (meta `no-referrer` vẫn bù). Nên dùng `pathname.replace(/\/+$/, "")` hoặc `startsWith`, thêm 1 case test.

**L2 [Low] `clearCsrfToken` gọi hai lần** — `lib/privacy/api.ts:103` và `DeleteAccountSection.tsx:~87`. Giữ một chỗ (trong `confirmAccountDeletion`).

**L3 [Low] Thông điệp "đã được mở lại"** — `UnsubscribeView.tsx` nhánh cảnh báo: F5 sau khi gỡ `?t=` cũng rơi vào đây; chấp nhận được (đã ghi trong Dev), nhưng nếu muốn đỡ bối rối có thể thêm "Vui lòng mở lại liên kết trong thư". Không bắt buộc.

**L4 [Low] `ApiError.errors` kiểu sai** — `packages/api-client` khai `Record<string, string[]>` nhưng thực tế có chuỗi; `domainValue()` bù bằng ép kiểu. Dev đã ghi nhận; nên sửa type ở story riêng.

### Đối chiếu trọng tâm
| Hạng mục | Kết quả |
|---|---|
| Token không lọt Referer/history | Đạt (no-referrer header + meta, replaceState); log server xem M2 |
| Không tự POST khi mở trang | Đạt (có test) |
| Bấm kép | Đạt (ref `inflight`; nút xoá/tải có `pending`) |
| CSRF POST export/xoá | Đạt (export gắn `X-CSRF-TOKEN`, retry 419; xoá qua `authFetch`) |
| Tên file Content-Disposition | Đạt (whitelist `[A-Za-z0-9._-]+.json`, chặn `..`, fallback ngày VN) |
| Không hiện ip/ua | Đạt |
| Lỗi theo contract (`errors.*`, 429 TOO_MANY_ATTEMPTS, DATA_EXPORT_LIMIT, 409) | Đạt |
| Rò state sau xoá | Đạt (tải cứng `/`, bỏ CSRF cache; sessionStorage chỉ giữ cờ cố định) |
| Mật khẩu | Xoá khỏi state khi lỗi; form unmount khi xong |
| a11y / 375px | Field/label đầy đủ, Dialog `dismissible={!busy}`, `role="alert"`; e2e 375px không tràn ngang đạt. Chưa tự kiểm tay |
| Banner chính sách | Không chặn học/mua, checkbox không tick sẵn, 409 reset tick |

### Gợi ý cho QA
- Xoá: bấm kép xác nhận, 2 tab, thiết bị thứ hai nhận SESSION_REVOKED, đăng ký lại email cũ.
- Tải dữ liệu: CORS thật có expose `Content-Disposition` không (không thì tên mặc định), file lớn, 500 không tốn lượt, lần thứ 3 -> 429.
- Huỷ nhận: mở từ mail thật, trình quét link không tự huỷ, kiểm tra Referer ở Network, F5 sau khi gỡ token.
- Banner: 375px, đổi `policy_version` giữa chừng, 409 CONSENT_VERSION_CHANGED.
- Hai văn bản chính sách vẫn là bản tạm: cần pháp chế thay trước khi mở chính thức.

### Bước tiếp theo
Không có blocker: chuyển `laravel-qa`. Dev nên xử lý M1 (bỏ NEXT_TSCONFIG) và L1 trong cùng story; M2/M3 ghi backlog hoặc xử lý nếu kịp.

## QA (laravel-qa) — 2026-10-08

**Kết luận: PASS** (0 Critical, 0 High, 0 Medium, 3 Low/Info). e2e thật bổ sung 8/8 (`e2e/qa-fw7-extra.spec.ts`, chạy bằng `e2e/run-qa-fw7.sh` + `playwright.qa-fw7.config.ts`, build riêng `.next-qa-fw7` đã xoá). Đã `seed-e2e-fw7.sh --clean` (không còn dòng bỏ qua), tài khoản tự tạo `fw7-qa-*@example.com` bị dọn cùng.

### Kết quả theo ca
| Ca | Kết quả |
|---|---|
| Xoá: bấm kép "Gửi mã" | Đúng 1 POST `/account/delete/otp` (nút khoá khi pending) |
| Xoá: bấm kép "Xoá tài khoản vĩnh viễn" | Đúng 1 POST `/account/delete` (200); F5 sau đó không còn toast (cờ flash một lần); localStorage/sessionStorage không còn tên/email tài khoản |
| Xoá: tab khác trong cùng trình duyệt (cookie đã thành khách) | Thao tác API -> chuyển `/dang-nhap?next=...&trang-thai=het-phien` |
| Xoá: thiết bị khác dùng CÙNG phiên (sao chép cookie + device id) | `/auth/me` 401 `SESSION_REVOKED` ("Tài khoản đã được xoá."); thao tác trên UI cũ -> hiện lớp phủ "Bạn cần đăng nhập lại" |
| Xoá: thiết bị khác đăng nhập khác phiên | Backend chỉ cho 1 phiên/học sinh: đăng nhập B đá A (`SESSION_REPLACED`), nên "phiên khác" thực tế chỉ còn là bản sao cùng phiên (ca trên) |
| Xoá: đăng ký lại bằng email cũ | Thành công, `/tai-khoan` hiện email, có đúng 2 đồng ý hiệu lực (không thừa hưởng dữ liệu cũ) |
| Tải dữ liệu: 4 POST song song | Đúng 2 x 200 + 2 x 429 `DATA_EXPORT_LIMIT`; `used_today=2` khớp số 200 (không vượt, không tốn lượt oan). Lặp 2 lần chạy kết quả như nhau |
| Tải dữ liệu: tab cũ còn hiện "Còn 2 lượt" bấm tải sau khi đã hết | Hiện "Bạn đã tải 2 lần hôm nay. Thử lại sau 00:00, dd/mm/yyyy.", nút khoá, không tràn ngang (429 thật qua POST thứ 3, bù chỗ dev chỉ có unit test) |
| Tải dữ liệu: sai mật khẩu không tốn lượt | Đã có ở e2e dev. Lỗi 500 không tốn lượt: KHÔNG mô phỏng được ở e2e (cần ép lỗi server), vẫn dựa vào contract/test backend T34 |
| Huỷ nhận: link THẬT trong thư Mailpit | Tạo bằng đăng ký + đặt email phụ huynh + OTP email; đúng 1 thư; link `/phu-huynh/huy-nhan-thong-bao?t=`; mở link không POST gì, học sinh vẫn "Đang nhận"; bấm -> thông điệp chung, học sinh thấy "Phụ huynh đã ngừng nhận" |
| Huỷ nhận: header `List-Unsubscribe` + One-Click | Có `List-Unsubscribe: <.../api/v1/parent-notices/unsubscribe?t=...>` và `List-Unsubscribe-Post: List-Unsubscribe=One-Click`; curl POST form one-click -> 200; GET trực tiếp API -> 405 (trình quét link không huỷ được) |
| Huỷ nhận: Referer/cookie/CSRF | POST không có `Referer`, `Cookie`, `X-CSRF-TOKEN`; `Referrer-Policy: no-referrer` cả khi có `/` cuối; `Cache-Control: no-store` |
| Huỷ nhận: GET không tự huỷ, F5 | Chờ 3 giây không có request ghi nào; F5 sau khi gỡ `?t=` -> "Liên kết không dùng được" |
| Huỷ nhận: token sai / dị dạng | `abc`, `1.short`, `<script>..`, `1.%00%00`: cùng một thông điệp chung, không echo token (lỗi hiển thị an toàn, không XSS); token 3000 ký tự -> "Liên kết không dùng được" (chặn ở FE > 512) |
| Banner 375px | Không tràn ngang; nút Đồng ý nằm trong khung; thao tác hoàn toàn bằng bàn phím (Space, Tab, Enter) |
| Banner 409 `CONSENT_VERSION_CHANGED` THẬT | Sửa `policy_version` trong request thành `1999-01` (route.continue) -> backend trả 409 thật; FE bỏ tick cả hai ô, hiện "Điều khoản vừa được cập nhật lại", hiển thị phiên bản hiện hành lấy từ `errors.current_version`; tick lại + gửi -> 200, banner biến mất |
| a11y bàn phím, 375px | Mọi điều khiển hiển thị đều có tên truy cập; 40 lần Tab không phần tử nào mất chỉ báo focus; hộp thoại (tải dữ liệu, xoá) giữ focus bên trong, Escape đóng và trả focus về nút mở; hộp thoại và mọi khối không tràn ngang ở 375px; bấm kép "Lưu thay đổi" ở form phụ huynh chỉ gửi 1 PUT; `/dieu-khoan`, `/chinh-sach-du-lieu`, trang huỷ nhận không tràn, mỗi trang đúng 1 `<h1>` |

### Phát hiện (không chặn)
- **L1 (Low) Thông điệp lớp phủ mất phiên sai ngữ cảnh khi tài khoản bị xoá ở thiết bị khác.** Lớp phủ chung "Bạn cần đăng nhập lại — Mật khẩu hoặc email của tài khoản vừa được thay đổi..." hiện cho cả `SESSION_REVOKED` do xoá tài khoản (hộp thoại bên dưới đã hiện đúng "Tài khoản đã được xoá."), còn nút "Đăng nhập lại" dẫn vào ngõ cụt vì tài khoản không còn. Lớp phủ là thành phần dùng chung (không thuộc FW7). Đề xuất: phân biệt theo `message`/tombstone khi có, hoặc ghi backlog. Tái hiện: 2 trình duyệt chung phiên, xoá ở một bên, thao tác "Tải dữ liệu" ở bên kia. Ảnh: không lưu (đã dọn `test-results`).
- **L2 (Low/Info) Token huỷ nhận nằm trong payload RSC nội tuyến của HTML ban đầu** (`"q":"?t=..."`, hành vi framework khi trang đọc query). `replaceState` chỉ dọn URL/lịch sử; DOM hiển thị, link, form không chứa token; HTML là `no-store` và cùng URL đã mang token nên rủi ro thấp. Cộng với M2 của reviewer (access log) nên gộp vào backlog "che query của route này". Trang cũng không có `X-Robots-Tag` (chỉ meta noindex): đủ.
- **L3 (Info) Một học sinh chỉ có 1 phiên** (đăng nhập thiết bị mới đá thiết bị cũ bằng `SESSION_REPLACED`), nên ca "thiết bị thứ hai nhận SESSION_REVOKED khi xoá" chỉ xảy ra với bản sao cùng phiên; trường hợp thường gặp là thiết bị cũ đã nhận `SESSION_REPLACED` trước đó. Không phải lỗi.
- Ghi chú: List-Unsubscribe trong thư dev trỏ `http://api.localhost/...` (không cổng) do APP_URL local; kiểm lại biến này ở staging/prod để one-click chạy được.

### Chưa kiểm được / lưu ý
- 409 `ACCOUNT_HAS_PENDING_PAYMENT` (cờ thanh toán tắt ở dev) — chỉ có unit test của dev.
- Lỗi 500 khi xuất dữ liệu không tốn lượt — không ép được lỗi server ở e2e.
- `Content-Disposition` qua CORS: trình duyệt thấy header `attachment; filename="vitaminvui-du-lieu-ca-nhan-YYYYMMDD.json"` ở fetch trực tiếp nhưng FE vẫn dùng tên an toàn; không phát hiện sai khác.
- Hai văn bản chính sách vẫn là bản TẠM (chờ pháp chế).
- Dev server dùng chung không bị đụng; Playwright `--workers=1`, build riêng đã xoá.
