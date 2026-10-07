# REVIEW: FA-V2
**Kết luận (vòng 2): APPROVE** (vòng 1: REQUEST CHANGES; các phát hiện bên dưới đã được dev sửa)
**Phạm vi:** phần chưa commit của `frontend/apps/admin` (FA1/FA2 làm lại theo design v2, gate `/v2`, các sửa admin trước đó) + `packages/ui/src/v2/layout/AdminFrame.tsx` + `frontend/README.md` (mục V2_PREVIEW) · ~43 file (đọc toàn bộ diff, đọc đầy đủ các file auth/shell/subjects)

**Chưa chạy được typecheck/lint/test:** lúc review load average của máy là 21 rồi vọt lên 95 (Docker đang chạy web/admin/mysql/php), vượt ngưỡng 40 nên đã dừng lượt chạy `pnpm --filter @vitaminvui/admin run typecheck|lint|test` (không có kết quả). Dev/QA tự chạy lại và gửi kết quả trước khi chuyển QA.

## Tổng quan
Phần đổi giao diện sạch: logic xác thực/MFA/đổi mật khẩu/idle/khoá tài khoản không bị đổi ngầm (diff LoginForm/MfaForm/ForcePasswordChangeForm/SessionWatcher chỉ là component v2, `Field`, toast v2, overlay đặc). Mật khẩu staff min 12 + `minLength` + gợi ý + test đúng tasks.md FA1; không có `dangerouslySetInnerHTML`; không token trong client; `localStorage` chỉ dùng cho mốc idle như cũ. Điểm yếu chính là gate `/v2` ở `proxy.ts` mở được bằng header, và vài chỗ a11y/phạm vi.

## Phát hiện

### R1 [High] Gate `/v2` trong `proxy.ts` bị vượt qua bằng header prefetch
- Vị trí: `apps/admin/proxy.ts:9-11, 24-27` (hàm `isBlockedPreview`) và `config.matcher` (khoảng dòng 55-66, khối `missing`).
- Vấn đề: matcher có `missing: next-router-prefetch` / `purpose: prefetch` nên request mang các header này KHÔNG chạy `proxy()` → không rewrite 404. Chỉ còn lớp thứ hai là `notFound()` trong `(v2-preview)/v2/layout.tsx`; chính comment trong proxy nói `notFound()` ở layout dưới `app/loading.tsx` gốc trả 200 và payload RSC vẫn chứa nội dung trang xem trước. Tức `curl -H 'next-router-prefetch: 1' https://admin…/v2/...` vẫn có thể lấy được 200 (và có thể có nội dung). Yêu cầu "không mở được bằng header" chưa đạt. Nội dung hiện là dữ liệu mẫu nên không lộ dữ liệu thật, nhưng mục đích gate là không để màn chưa duyệt ra production.
- Đề xuất: thêm một entry matcher riêng không có `missing` cho `/v2`:
  ~~~ts
  export const config = {
    matcher: [
      { source: "/v2" },
      { source: "/v2/:path*" },
      { source: "/((?!_next/static|...).*)", missing: [/* như cũ */] },
    ],
  };
  ~~~
  (request khớp một trong các entry là chạy proxy). Hoặc bỏ `missing` cho mọi route nếu chấp nhận proxy chạy cả prefetch. Sau khi sửa, kiểm bằng `curl -i -H 'next-router-prefetch: 1' -H 'purpose: prefetch' …/v2/quan-tri/khoa-hoc` trên bản `next start` (QA) phải ra 404. Cũng thử đường dẫn mã hoá phần trăm (`/%76%32`) vì `nextUrl.pathname` không chuẩn hoá — chưa kiểm được ở đây.

### R2 [Medium] Không có test cho gate `/v2`
- Vị trí: `apps/admin/proxy.ts` (không có `proxy.test.ts`), `(v2-preview)/v2/layout.tsx:20-29`.
- Vấn đề: đây là kiểm soát an toàn mới nhưng không có test nào (production không `V2_PREVIEW` → rewrite; `V2_PREVIEW=1` → qua; dev → qua; `/v2x`, `/v2/`, `/quan-tri` không bị chặn nhầm; CSP header vẫn có với route thường). Dev tách `isBlockedPreview` ra file riêng và test bằng vitest + `vi.stubEnv`; thêm 1 e2e/curl trong checklist QA.

### R3 [Medium] SessionWatcher `alertdialog` không quản lý focus
- Vị trí: `components/shell/SessionWatcher.tsx:104-114` (overlay `role="alertdialog" aria-modal`).
- Vấn đề: overlay tự dựng, không `showModal()`/`inert`, không chuyển focus vào trong khi mở. Người dùng bàn phím vẫn Tab được vào menu/nội dung phía sau (đã bị che bằng mắt nhưng chưa bị khoá), trình đọc màn hình không được đưa tới nội dung. Spec yêu cầu dialog gốc + focus.
- Đề xuất: dùng `<dialog>` + `showModal()` (như `Dialog` v2) hoặc tối thiểu `autoFocus` vào liên kết "Về trang đăng nhập" + `inert` cho phần còn lại; thêm test focus.

### R4 [Medium] Đổi trang đích mặc định sang `/quan-tri/khoa-hoc` và phụ thuộc FA3 chưa commit
- Vị trí: `lib/nav.ts` (`DEFAULT_LANDING`, `ready: true` của "Khóa học"), test `logic.test.ts`, `LoginForm.test.tsx`, các e2e.
- Vấn đề: sau đăng nhập/MFA/đổi mật khẩu người dùng không còn vào Tổng quan mà vào Khóa học. Không có trong tasks.md/board mô tả FA-V2; là đổi hành vi toàn cục. Đồng thời route này nằm ở FA3 (untracked: `app/quan-tri/khoa-hoc`, `components/courses`) — nếu FA-V2 commit trước FA3 thì menu và landing dẫn tới 404 (đúng lỗi "404 menu" vừa sửa).
- Đề xuất: ghi quyết định (PO/board) hoặc giữ `/quan-tri`; và commit FA3 cùng/trước FA-V2 (hoặc để `ready:false` tới khi FA3 vào).

### R5 [Low] Response chặn `/v2` bỏ qua header bảo mật/CSP
- Vị trí: `proxy.ts:24-27` (`return NextResponse.rewrite(...)` trước khi sinh nonce/đặt header).
- Vấn đề: trang 404 do rewrite không có CSP, `X-Content-Type-Options`, HSTS… Nên đi qua cùng đường đặt header (rewrite nhưng vẫn set header).

### R6 [Low] Nút Đăng xuất 36px trên mobile
- Vị trí: `components/shell/LogoutButton.tsx:28` (`size="sm"`), được dùng trong ngăn kéo mobile của `AdminFrame`.
- Thêm `className="max-sm:h-11"` (hoặc `lg:` ngược lại) theo quy ước 44px ở mobile như `SubjectsScreen` đã làm. Kiểm thêm chiều cao link menu trong `NavDrawer`/`Switch` ở mobile khi QA.

### R7 [Low] Hai lớp "khoá tài khoản" cùng dựng
- Vị trí: `lib/auth/SessionProvider.tsx` (listener `ACCOUNT_LOCKED` → state `locked`) + `SessionWatcher` overlay.
- Giữa phiên `/quan-tri` vừa hiện `AuthGate`→`LockedNotice` vừa hiện overlay `LockedNotice` — trùng nội dung. Chấp nhận được (overlay vẫn cần cho trang ngoài provider) nhưng nên ghi test cho listener mới (có `SessionProvider.test.tsx` untracked — kiểm có ca ACCOUNT_LOCKED) và cân nhắc chỉ giữ một lớp.

### R8 [NIT]
- `proxy.ts:3-11`: JSDoc mô tả `proxy` giờ nằm trên `isBlockedPreview`; chuyển lại xuống trên `export function proxy`.
- `AppProviders.tsx`: toast v1 giữ cho FA3 đúng yêu cầu — thêm TODO có mã task để gỡ.

## Đối chiếu yêu cầu
| Yêu cầu | Kết quả | Ghi chú |
|---|---|---|
| FA1 mật khẩu staff min 12, `errors.password[0]` | Đạt | `STAFF_PASSWORD_MIN_LENGTH`, `minLength`, hint, test; `classifyPasswordError` giữ nguyên |
| Logic MFA/idle/khoá không đổi ngầm | Đạt | chỉ UI; thêm listener khoá trong SessionProvider (R7) |
| a11y: `<dialog>` gốc ở form/xoá/in-use | Đạt | dùng `Dialog`/`ConfirmDialog` v2, trả focus về nút gốc; alertdialog watcher chưa (R3) |
| aria-label nút icon, 44px mobile | Phần lớn | Subjects có `aria-label` + `max-sm:size-11`; LogoutButton chưa (R6) |
| Không `dangerouslySetInnerHTML` | Đạt | grep sạch |
| Toast v1 giữ cho FA3 | Đạt | `AppProviders` |
| 2 Minor FA2 (404 khi ẩn/sửa → reload) | Đạt | `isSubjectGone` + 2 test mới |
| Chặn `/v2` ở production | Chưa đạt | R1, R2; layout `connection()` + env đọc lúc chạy là đúng, README/.env.example đã ghi |
| typecheck/lint/test | Chưa chạy | tải máy 95 |

## Gợi ý cho QA
- `next start` production: `/v2`, `/v2/...` có và không có header `next-router-prefetch`/`purpose: prefetch`, có/không `V2_PREVIEW=1` (đặt lúc chạy, không build lại); `/quan-tri`, `/dang-nhap` không bị ảnh hưởng.
- Khoá tài khoản giữa phiên: Tab bàn phím có lọt ra sau overlay không.
- Luồng đăng nhập → MFA → đổi mật khẩu → landing; `next=` ngoài site bị chặn.
- Mobile 375px: ngăn kéo menu, Đăng xuất, bảng chuyên đề (Switch, Sửa/Xoá), hộp thoại.
- Idle 120 phút nhiều tab, mật khẩu 11 vs 12 ký tự và 422 `errors.password`.

## Dev đã sửa (vòng 2)
- **R1** `apps/admin/proxy.ts`: thêm 2 entry matcher riêng `{source:"/v2"}`, `{source:"/v2/:path*"}` không có `missing` → request mang `next-router-prefetch`/`purpose: prefetch` cũng chạy proxy. `isBlockedPreview` giải mã phần trăm (`/%76%32`), gộp `//`, không phân biệt hoa thường. Layout `notFound()` giữ làm lớp phụ.
- **R2** tách `lib/previewGate.ts` (`isPreviewPath`, `isBlockedPreview`) + `lib/previewGate.test.ts` (`vi.stubEnv`: production có/không `V2_PREVIEW`, giá trị khác `1`, dev, `/v2x`, `/quan-tri`, `/%76%32`).
- **R3** `SessionWatcher.tsx`: overlay khoá giờ là `<dialog role="alertdialog">` + `showModal()` (bẫy focus, phần còn lại `inert`, focus vào liên kết), Esc không đóng; test kiểm `DIALOG`, `open`, liên kết đăng nhập.
- **R4** theo quyết định orchestrator: `DEFAULT_LANDING = "/quan-tri"`; mục "Khóa học" trong `lib/nav.ts` về `ready:false` ("Sắp có", không phải link) kèm `TODO(FA3)` để tránh 404 khi FA3 chưa commit; test `logic.test`, `LoginForm.test`, `MfaForm.test`, `ForcePasswordChangeForm.test`, `shell.test` và 4 e2e (`admin-real`, `dang-nhap`, `chuyen-de-real`, `chuyen-de-qa-real`) trả về kỳ vọng `/quan-tri`. FA3 bật lại `ready:true` + đổi landing nếu muốn.
- **R5** response rewrite chặn `/v2` đi qua cùng đường đặt CSP/`X-Content-Type-Options`/Referrer/Permissions/HSTS (rewrite mang `response` chung).
- **R6** `LogoutButton` thêm `max-sm:h-11`.
- **R7** giữ cả hai lớp (overlay cần cho trang ngoài `SessionProvider`); `SessionProvider.test.tsx` đã có ca `ACCOUNT_LOCKED` → state `locked`.
- **R8** JSDoc `proxy` về đúng hàm; `AppProviders` có `TODO(FA3)` gỡ `LegacyToastProvider`.
- Kết quả (load < 40): `tsc --noEmit` sạch; lint chỉ còn 3 lỗi `no-explicit-any` ở `e2e/khoa-hoc-real.spec.ts` (FA3, ngoài phạm vi); vitest `lib`, `components/shell`, `components/auth` 123/123 pass (gồm `previewGate.test.ts` 6 ca); `next build` thành công.
- curl trên `next start` (production): không `V2_PREVIEW` → `/v2`, `/v2/quan-tri/khoa-hoc`, `/%76%32` đều 404, có và không có header `next-router-prefetch`/`purpose: prefetch`, 404 có CSP; `V2_PREVIEW=1` → `/v2...` 200; `/dang-nhap` luôn 200. (Request prefetch tới `/dang-nhap` không qua proxy nên không có CSP — hành vi matcher cũ, không đổi.)
- **Kiểm lại vòng 2 (load 4, RAM Docker ~3 GB):** `tsc --noEmit` sạch; lint 3 lỗi `no-explicit-any` ở `e2e/khoa-hoc-real.spec.ts` (FA3, ngoài phạm vi); vitest admin 14 file, 162/162 pass; `next build` (`--max-old-space-size=1536`) thành công. curl `next start`: không `V2_PREVIEW` → `/v2`, `/v2/quan-tri/khoa-hoc`, `/%76%32` đều 404 (có/không header `next-router-prefetch: 1` + `purpose: prefetch`), 404 có CSP; `V2_PREVIEW=1` → `/v2`, `/v2/quan-tri/khoa-hoc` 200; `/dang-nhap`, `/quan-tri/chuyen-de` luôn 200.

## Review lại vòng 2 (reviewer)
**Kết luận: APPROVE.** Không còn BLOCKER/High/Medium.

| Mục | Kết quả |
|---|---|
| R1 gate `/v2` | Đạt: 2 entry matcher không có `missing`; `isPreviewPath` giải mã phần trăm, gộp `//`, bỏ phân biệt hoa thường; env đọc lúc gọi; chỉ `V2_PREVIEW === "1"` mở; `/v2x`, `/xv2`, `/quan-tri/v2` không bị chặn nhầm. Layout `notFound()` còn là lớp phụ |
| R2 test | Đạt: `previewGate.test.ts` 6 ca có ý nghĩa |
| R3 overlay | Đạt: `<dialog>` + `showModal()`, Esc bị chặn, nền đặc, focus vào liên kết, phần còn lại `inert`; test kiểm `DIALOG`/`open` (jsdom không tự focus, QA kiểm tay) |
| R4 landing | Đạt: `DEFAULT_LANDING="/quan-tri"`, "Khóa học" `ready:false` + `TODO(FA3)`; test/e2e đồng bộ. Khi FA3 commit phải bật `ready:true` và sửa `shell.test` |
| R5 CSP khi rewrite | Đạt: rewrite dùng chung đường đặt header |
| R6, R8 | Đạt |
| R7 | Chấp nhận giữ hai lớp |

**Prefetch tới route thật không có CSP: không cần sửa.** Prefetch của router trả payload RSC, không phải tài liệu HTML nên CSP/nonce không có tác dụng; nonce chỉ cần ở lần tải HTML (điều hướng thật, luôn qua proxy). Header prefetch không mở được route bị chặn vì `/v2` đã có matcher riêng.

Còn lại ngoài phạm vi: 3 lỗi lint `no-explicit-any` ở `e2e/khoa-hoc-real.spec.ts` (FA3). QA nên kiểm tay focus overlay khoá và chạy lại curl gate `/v2` trên bản production.
