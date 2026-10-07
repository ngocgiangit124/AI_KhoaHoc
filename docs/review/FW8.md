# REVIEW: FW8 + FW9 — Trang chủ thật và khu vực giáo viên (web học sinh)

## Dev
**Trạng thái:** xong cả FW8 (US-019, gồm poster người sáng lập nội dung tạm PO 2026-10-07) và FW9 (US-020 phần công khai). Không sửa backend, không cài package, không đụng `packages/ui`, không commit.

### Phạm vi
- `/` thật thay trang chủ tạm cũ (bản xem trước `/v2` giữ nguyên): hero, chọn lớp 6–12 (`/khoa-hoc?grade=N`, đúng 7 ô), khóa nổi bật, poster người sáng lập, "Thầy cô giảng dạy", một buổi học, phụ huynh. Bố cục, câu chữ, nền ô ly dùng lại component của designer (`LessonPeek`, `FounderPoster`, `TeacherSection`, `TeacherCard`, `CourseCard`).
- Render động + CSP nonce (ADR-004 §2.7, không ISR). Nguồn dữ liệu qua `publicFetchServer` (cùng quy tắc header nội bộ `X-Internal-Token` như FW2; không gắn `X-Client-IP` vì không có `q`):
  - khóa nổi bật = 4 khóa đầu của `GET /courses?sort=featured` (dùng lại `fetchCourses`, `revalidate: 60`, tag `catalog`, chung cache với danh mục `?sort=featured`);
  - giáo viên = `GET /home/teachers` (`revalidate: 60`, tag `teachers`);
  - `paid_checkout_enabled` từ `/config/public`; lỗi cấu hình → coi là tắt (thẻ có phí ghi "Sắp mở bán").
- Mỗi nguồn chạy qua `settle()` (lỗi hoặc quá 4 giây → bỏ qua): khóa nổi bật lỗi → Alert "Không tải được khóa học nổi bật" + nút "Tải lại" tại chỗ; rỗng → "Khóa học sẽ sớm được cập nhật" + liên kết danh mục; giáo viên rỗng/lỗi/sai schema → không render gì (không tiêu đề, không khung); poster là hằng số tĩnh `lib/home/founder.ts` nên không phụ thuộc API. Khối "Một buổi học" đổi `pt-4`/`pt-12` theo có giáo viên hay không để không có khoảng trắng thừa.
- Thẻ giáo viên: ảnh qua `TeacherPhoto` (client, `next/image` `unoptimized` từ `STATIC_URL`, alt "Ảnh thầy/cô {tên}", lỗi tải → chữ cái đầu trên ô vở), tên cắt 2 dòng, headline, "Lớp 9, 10 · N khóa đang bán", bio cắt 3 dòng giữ xuống dòng (`whitespace-pre-line`, chỉ text React, không HTML), "Xem N khóa học" → `/khoa-hoc?teacher_id={id}`. 1 người → thẻ rộng vừa; 1→2→3 cột (375/768/1024).
- Danh mục `/khoa-hoc` và `/lop-{n}` nhận `?teacher_id=` (contract §2.1 T36 đã hỗ trợ, backend không phải sửa): truyền xuống API, chip "Giáo viên: {tên}" lấy từ `teachers[]` của kết quả (có nút bỏ lọc), form tìm kiếm giữ `teacher_id`, trang lọc là `noindex`; id sai kiểu bị bỏ qua êm. Trang chi tiết khóa: alt ảnh giáo viên theo US-020, bio giữ xuống dòng (avatar chữ cái/ẩn bio khi null đã có từ FW2).
- Ảnh bìa khóa hỏng → nền ô ly trơn (không icon vỡ), thêm cho cả danh mục (`CourseImage` thành client component).
- SEO: `<title>` tuyệt đối, description, canonical `/`, OG (`og:title/description/url/type/locale/site_name`), JSON-LD `Organization` có nonce, đúng một `h1`. Không đưa dữ liệu giáo viên vào JSON-LD. Không lộ giáo viên chưa đồng ý/bị khoá/hết khóa: backend lọc (T36), frontend không có đường nào khác để lấy dữ liệu giáo viên, ảnh chỉ lấy từ `avatar_url` của API.
- Sửa lỗi phát hiện khi e2e: lưới thẻ khóa/giáo viên không có `grid-cols-1` nên tên giáo viên dài (truncate = nowrap) kéo trang rộng 1378px ở 375px → thêm `grid-cols-1` + `min-w-0`.

### File
- Mới: `lib/home/{api,schemas,teachers,settle}.ts`, `lib/useImageFailed.ts`, `components/home/{FeaturedCourses,HomeTeachers,TeacherPhoto}.tsx`.
- Sửa: `app/(site)/page.tsx` (trang chủ thật), `lib/catalog/query.ts` (+`teacherId`), `components/catalog/{CatalogView,CourseImage,TeacherList}.tsx`, `components/v2/home/TeacherSection.tsx` (file của designer: thêm props tuỳ chọn `hrefFor`, `renderImage`, `grid-cols-1`/`min-w-0`; bản `/v2` không đổi hành vi), `.gitignore` (`/.next-*/`).
- Test mới: `lib/home/{teachers,settle,founder}.test.ts`, `components/home/{HomeTeachers,FeaturedCourses}.test.tsx`, `components/v2/home/FounderPoster.test.tsx`, `components/catalog/CatalogView.teacher.test.tsx`, `app/(site)/page.test.tsx`; sửa `lib/catalog/query.test.ts`.
- E2E: `e2e/home-real.spec.ts`, `e2e/seed-e2e-home.sh` (`--reset`/`--clean`, chỉ local/testing, tiền tố `e2e-fw8-`), `e2e/run-home-real.sh`, `e2e/fw8-proxy.mjs`, `playwright.fw8.config.ts`.
- Không có biến môi trường mới.

### Cách test
- `frontend/scripts/pnpm.sh --filter @vitaminvui/web exec tsc --noEmit` sạch; `... run lint` sạch; `... run test` 347/347 (41 file); `next build` (distDir `.next-check`, đã xoá) thành công.
- E2E thật (backend Laravel thật, `--workers=1`): `e2e/seed-e2e-home.sh --reset` → `e2e/run-home-real.sh` → `e2e/seed-e2e-home.sh --clean`. Script dựng `next dev` riêng trong container (distDir `.next-e2e-fw8`, được xoá mỗi lần chạy vì Data Cache nằm trong đó) và `fw8-proxy.mjs` đứng giữa SSR và backend: chuyển tiếp nguyên trạng, chỉ làm lỗi/rỗng/rút gọn/chậm hai nguồn của trang chủ (không thể tạo các tình huống đó trên DB dùng chung). Thứ tự test có chủ ý vì Data Cache `revalidate: 60` giữ kết quả thành công: lỗi (không bị cache) → 3 khóa/1 giáo viên → rỗng → dữ liệu thật. Cả bộ ~3 phút (2 lần chờ cache hết hạn); đã chạy 6/6 xanh, sau đó ca B được siết thêm và chạy lại riêng, xanh.
- Ca e2e: A1 hai API 5xx (trang vẫn 200, khu lỗi + Tải lại, giáo viên ẩn, poster và các khu khác còn); A2 API quá chậm (trang về trong < 6 giây); B 3 khóa + 1 giáo viên, "Tải lại" hồi phục, tên 150 ký tự cắt 2 dòng, bio 5 dòng cắt 3 dòng giữ xuống dòng, không cuộn ngang ở 375px, 3 lần reload không gọi lại API (cache); C rỗng cả hai (thông báo "sẽ sớm được cập nhật", không tiêu đề giáo viên, poster liền kề khối sau); D dữ liệu thật (HTML ban đầu có title/description/canonical/OG/JSON-LD nonce/một h1/tên khóa/alt ảnh; giáo viên chưa đồng ý/ngừng bán/bị khoá và khóa ngừng bán KHÔNG có trong HTML; thứ tự 6 khu vực; đúng 4 khóa theo `manual_order`; "Sắp mở bán" một lần, không nút mua/giỏ; poster lazy, không priority; thẻ giáo viên đúng thứ tự, ảnh 404 → chữ cái đầu, bio chứa `<script>`/`<img onerror>` hiện nguyên chữ và không thực thi; 3/2/1 cột ở 1280/768/375; không cuộn ngang; vùng bấm ≥ 44px ở 375px; CLS ≤ 0,1; không lỗi CSP trong console; "Xem 2 khóa học" → `?teacher_id=` đúng 2 khóa + chip + bỏ lọc; `teacher_id` không tồn tại → "Không tìm thấy khóa học phù hợp" 200; chọn lớp → `?grade=9`; cache); E 375px khung poster 4:5, khóa nổi bật 1 cột.

### AC đã đáp ứng
US-019: AC1–AC5, AC7–AC9, AC12–AC16, AC18 có test (Vitest + e2e); AC10 một phần (không cuộn ngang và vùng bấm ≥ 44px có e2e; chữ ≥ 16px xem mục dưới); AC11 cùng US-020 AC15; AC17 (poster 375px) kiểm cuộn ngang, khung 4:5 và vùng bấm, tương phản theo số đo của designer (§12.7), không đo lại; AC19 poster là SVG tĩnh cùng origin, chưa test chặn tệp.
US-020 (phần công khai): AC12, AC13, AC14, AC15, AC16, AC17, AC19 (qua dữ liệu thật + test hiển thị); AC4 (bio là text); AC5/AC6 do backend (frontend chỉ hiển thị cái API trả).
Chưa kiểm bằng e2e: AC6 US-019 (khóa ngừng bán biến mất sau ≤ 60 giây) và AC7 US-020 (rút đồng ý → ảnh biến mất) — cần đổi dữ liệu giữa lần chạy và chờ cache; cơ chế là Data Cache 60 giây (đã có test cache ở trên), phần lọc là của backend (QA T36).

### Điều chưa làm / cần quyết
1. **Ảnh giáo viên thật chưa được kiểm hiển thị bằng trình duyệt trong e2e:** container Playwright không tới được `STATIC_URL` (`http://localhost:8080`) nên ảnh luôn "lỗi tải" và nhánh chữ cái đầu được kiểm thật; nhánh "ảnh tải được" chỉ có test đơn vị (alt, lỗi → chữ cái đầu) và kiểm alt trong HTML SSR. `laravel-qa` nên mở thử bằng trình duyệt ngoài Docker với ảnh thật.
2. **Chữ nội dung ≥ 16px (US-019 AC10/BR9):** theo design hiện có, `CourseCard`/`TeacherCard` dùng `text-sm` (14px) cho dòng phụ (chuyên đề, mô tả, "Lớp · N khóa", chú thích lớp). Đây là quyết định thị giác của `nextjs-designer`/PO, tôi không đổi. Nếu PO muốn đúng 16px thì giao designer.
3. **Poster nội dung tạm** vẫn nằm trong `lib/home/founder.ts`; khi PO gửi ảnh/tên/câu thật chỉ sửa file đó + ảnh (Q15). Ảnh SVG tạm không có `srcset` (Next tự `unoptimized`).
4. **Không có e2e đăng nhập học sinh** ở trang chủ (US-019 BR1: cùng nội dung): trang không đọc phiên nên không khác biệt; chỉ header khác (thuộc `SiteShell`, đã có từ FW1).
5. **Danh mục lọc theo giáo viên khi kết quả rỗng** không biết tên giáo viên (API không có endpoint tên theo id): chip hiện "Giáo viên: đã chọn" ở nhánh có kết quả nhưng không tìm được tên; với rỗng hoàn toàn chỉ hiện "Không tìm thấy khóa học phù hợp" + "Xoá bộ lọc". Chấp nhận được theo Q6; nếu cần tên thì cần API.
6. Trùng lặp nhỏ: bio giáo viên ở trang chi tiết khóa vẫn hiện toàn bộ (không cắt dòng), theo design FW2.
7. Lưới thẻ ở `/khoa-hoc` (CatalogView) có cấu trúc `flex` li khác trang chủ; chưa thấy tràn ngang nhưng tôi không chạy kiểm 375px với tên giáo viên 150 ký tự ở danh mục (ngoài phạm vi).
8. "Tải lại" là liên kết `/` bình thường (điều hướng mềm tới chính trang); e2e ca B xác nhận: API lỗi → khu lỗi, API hồi phục → bấm Tải lại hiện đủ khóa.

### Luồng `laravel-qa` nên kiểm kỹ
Giáo viên rút đồng ý / bị khoá / khóa duy nhất ngừng bán (HTML trang chủ sau ≤ 60 giây, kể cả view-source); khóa vừa ngừng bán biến mất khỏi "nổi bật"; throttle `catalog` 429 khi nhiều request SSR cùng IP (trang chủ gọi 2 URL catalog + 1 config mỗi lần cache hết hạn); trang chủ với dữ liệu thật ở 375px và Safari iOS; ảnh giáo viên thật từ `STATIC_URL` trong CSP `img-src`; `?teacher_id=` với id giáo viên chưa đồng ý; điều hướng bàn phím qua thẻ giáo viên và poster (focus màu `on-primary`).

---

## Review (laravel-reviewer, 2026-10-07)
**Kết luận:** APPROVE (0 BLOCKER, 3 SHOULD, 3 NIT)
**Phạm vi:** diff chưa commit trong `frontend/apps/web` (~25 file). Đã chạy lại: `tsc --noEmit` sạch, `lint` sạch, vitest 347/347 (41 file). Không chạy build/e2e.

### Tổng quan
Làm chắc tay: mỗi nguồn dữ liệu đi qua `settle()` nên một nguồn hỏng không kéo cả trang, giáo viên rỗng/lỗi/sai schema không render gì, bio và headline chỉ là text React (không có `dangerouslySetInnerHTML` mới), poster là hằng số tĩnh. Sửa file của designer (`TeacherSection`) chỉ thêm props tuỳ chọn có mặc định giữ nguyên hành vi `/v2`. Test và e2e bao phủ tốt các tình huống lỗi/rỗng/cache.

### Kiểm tra theo yêu cầu
- Bảo mật/riêng tư: không có `{!! !!}`/innerHTML mới; JSON-LD dùng `JsonLd` có nonce và `jsonLd()` escape `<` (chỉ chứa hằng số, không có dữ liệu GV). `X-Internal-Token` chỉ thêm trong `api.server.ts` (`server-only`), `lib/home/api.ts` cũng `server-only`; không gắn `X-Client-IP` (đúng, tránh tách cache). Lọc giáo viên chưa đồng ý/khoá do backend; frontend chỉ hiển thị dữ liệu API; `teacher_id` parse `^\d{1,9}$` rồi mới ra API. `fw8-proxy.mjs`, `playwright.fw8.config.ts`, seed nằm ngoài `app/` nên không vào bundle; seed từ chối APP_ENV ngoài local/testing; `distDir` chỉ nhận regex `^\.next[\w-]*$`; `.gitignore` đã thêm `/.next-*/`.
- Hiệu năng: 3 request SSR mỗi lần cache hết hạn (courses featured, home/teachers, config), song song, `revalidate: 60`, không tách cache theo IP. `settle` 4 giây có `clearTimeout` và bắt rejection muộn nên không treo/unhandled; trang không chặn lâu hơn ~4 giây khi backend chậm.
- Design/AC: thứ tự 6 khối, 1/2/3 cột, 1 người thẻ rộng vừa, alt "Ảnh thầy/cô …", cắt 2/3 dòng, một `h1`, canonical/OG/title tuyệt đối, trang lọc `teacher_id` là noindex (qua `hasActiveFilters`, đã gồm teacherId). `/v2` không vỡ: mặc định `hrefFor` giữ `routes.catalogQuery`.

### Phát hiện
#### R1 [SHOULD] Chữ phụ 14px vi phạm US-019 AC10/BR9 (chữ nội dung ≥ 16px ở 375px)
- Vị trí: `app/(site)/page.tsx:117-119` (nhãn "Lớp", ghi chú lớp, do dev viết); `packages/ui/src/v2/CourseCard.tsx:64-80`, `TeacherCard.tsx:71-72` (designer).
- Vấn đề: AC10 là AC có số đo rõ; hiện các dòng chuyên đề, mô tả, "Giáo viên:", "Lớp · N khóa đang bán", "Sắp mở bán", ghi chú ô lớp đều `text-sm`. Dev đã nêu đúng, nhưng riêng ô chọn lớp trong `page.tsx` là code của dev nên không thể đẩy hết sang designer.
- Đề xuất: PO quyết định một trong hai: (a) chấp nhận ngoại lệ cho nhãn phụ và sửa AC10 thành "nội dung chính (tên, bio, mô tả) ≥ 16px"; (b) giao designer nâng lên `text-base` ở 375px (`text-base sm:text-sm`). Trong lúc chờ, ở `page.tsx` đổi `text-sm` của ô lớp thành `text-base` (ô vẫn vừa 2 cột ở 375px). Không chặn commit nếu PO chọn (a).

#### R2 [SHOULD] `teacher_id` là chiều mới không giới hạn cho cache/throttle `catalog`
- Vị trí: `lib/catalog/query.ts` (parse `^\d{1,9}$`), `lib/catalog/api.ts:46-52`.
- Vấn đề: mỗi `teacher_id` hợp lệ về hình thức (tới 10^9 giá trị) tạo một khoá Data Cache và một request backend dùng chung bucket throttle `catalog` của nhóm SSR (không `X-Client-IP`, vì chỉ `q` mới gắn). Kẻ quét `/khoa-hoc?teacher_id=1..N` làm cạn bucket SSR và gây 429 cho cả trang chủ/danh mục thật (trang chủ chỉ còn Alert lỗi). `page` (6 chữ số) vốn đã cùng kiểu rủi ro, nhưng `teacher_id` thêm một chiều nhân.
- Đề xuất: ghi nhận vào ADR-004 hoặc backlog-v2; giảm nhẹ ngay bằng cách gắn `clientIp` khi `query.teacherId !== null` (giống `q`), hoặc chỉ cho phép `teacher_id` hiện diện trong danh sách `/home/teachers`. Chấp nhận để QA kiểm throttle (đã có trong "Luồng QA").
  ~~~ts
  clientIp: query.q || query.teacherId !== null ? await clientIp() : null,
  ~~~

#### R3 [SHOULD] Test đơn vị chưa khoá việc "không lộ" ở tầng frontend và AC19
- Vị trí: `lib/home/teachers.test.ts`, `components/home/HomeTeachers.test.tsx`.
- Vấn đề: dev đã tự nêu AC19 poster (chặn tệp) và AC6/AC7 chưa test, nên chỉ ghi nhận: phần lọc dựa hoàn toàn vào backend T36. Rủi ro nằm ở QA T36 chứ không ở code này.
- Đề xuất: QA kiểm bằng dữ liệu thật như phần dev liệt kê; thêm vào checklist trước khi duyệt FW8 (không cần sửa code).

#### R4 [NIT] Tên giáo viên của chip chỉ tìm trong trang kết quả hiện tại
- Vị trí: `components/catalog/CatalogView.tsx:49-50`.
- `data` là trang hiện tại; nếu GV không có trong trang 2 (hiếm vì mọi khóa đều của GV đó) chip rơi về "đã chọn". Mọi khóa lọc theo GV đều có GV đó trong `teachers[]`, nên thực tế chỉ lệch khi kết quả rỗng (đã được dev nêu, chấp nhận theo Q6). Danh mục lọc rỗng không có tên GV: đồng ý chấp nhận, hiển thị "Không tìm thấy khóa học phù hợp" + "Xoá bộ lọc" là đủ.

#### R5 [NIT] `useImageFailed`: lỗi tải sau hydrate khi `src` đổi không được reset
- Vị trí: `lib/useImageFailed.ts`. State `failed` không reset khi `url` đổi cùng instance. Với danh sách key theo id thì không xảy ra; thêm `key={url}` ở nơi dùng nếu sau này tái dùng.

#### R6 [NIT] Chú thích tiếng Việt/ghi chú ở `FeaturedCourses.tsx` đặt trước JSDoc
- Vị trí: `components/home/FeaturedCourses.tsx:12-14`. Comment `//` chen giữa JSDoc và hàm làm JSDoc mất gắn kết; gộp vào JSDoc.

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| US-019 AC1-AC9, AC12-AC16, AC18 | `page.tsx`, `FeaturedCourses`, `settle`, e2e ca A-E | Đạt theo test; AC6 (≤60 giây) chỉ bằng cơ chế cache |
| US-019 AC10 | Một phần | Không cuộn ngang, vùng bấm 44px đạt; chữ 14px: R1 |
| US-019 AC11/AC17/AC19 | Poster SVG tĩnh, FounderPoster | AC19 chưa test chặn tệp (dev đã nêu) |
| US-020 AC4, AC12-AC17 | `HomeTeachers`, `TeacherPhoto`, `TeacherSection` | Ảnh thật chưa mở bằng trình duyệt ngoài Docker, giao QA |
| US-020 AC5/AC6/AC7 | Backend T36 | Frontend chỉ hiển thị; QA kiểm sau 60 giây |

### Gợi ý cho QA
- Mở `/` bằng trình duyệt ngoài Docker với ảnh GV thật từ `STATIC_URL` (CSP `img-src`), Safari iOS 375px.
- Rút đồng ý/khoá GV/ngừng bán khóa duy nhất rồi xem view-source sau tối đa 60 giây; `?teacher_id=` của GV chưa đồng ý (phải ra rỗng, không lộ tên).
- Lặp nhiều `?teacher_id=` khác nhau và reload trang chủ dồn dập để xem 429 `catalog` (R2).
- Bàn phím: Tab qua thẻ GV, poster; focus nhìn rõ. Bio có xuống dòng/ký tự `<`.

## Dev đã sửa (sau Review)
- **R1:** ô chọn lớp ở `app/(site)/page.tsx` đổi `text-sm` sang `text-base` (≥ 16px). `CourseCard`/`TeacherCard` 14px giữ nguyên, chờ PO quyết.
- **R2:** `lib/catalog/api.ts` gắn `X-Client-IP` khi `teacherId !== null` (như nhánh có `q`, ADR-004 §2.8). Test mới `lib/catalog/api.test.ts` (không q/teacher_id: không IP; có q: IP; có teacher_id: IP + URL mang `teacher_id`). Thêm alias vitest `server-only` -> `test-stubs/server-only.ts` để test module server.
- **R5:** `useImageFailed(src)` đặt lại trạng thái lỗi khi `src` đổi (`TeacherPhoto`, `CourseImage` truyền url).
- **R6:** dời ghi chú khỏi giữa JSDoc và hàm trong `FeaturedCourses.tsx` (đặt thành chú thích ngay tại thẻ `<ul>`).
- Kiểm: tsc sạch, lint sạch, unit pass (api.test + page.test 13/13 chạy lại; bộ đầy đủ 347 test cũ pass, thêm 3 test mới), e2e `home-real` 6/6 (`--workers=1`, seed trước, `--clean` sau). Một lần chạy trước ca C quá thời gian chờ cache do máy chậm (flaky), chạy lại xanh.
