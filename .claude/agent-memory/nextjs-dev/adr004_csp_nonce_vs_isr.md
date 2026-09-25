---
name: adr004-csp-nonce-vs-isr
description: ADR-004 yêu cầu CSP nonce cho VitaminVui, xung đột với ISR trang công khai — Architect đã chốt phương án ở ADR-004 §2.7 (2026-09-25)
metadata:
  type: project
---

`docs/adr/ADR-004-...md` §2.6 yêu cầu CSP có nonce (`script-src 'self' 'nonce-{n}'
'strict-dynamic'`). Theo tài liệu Next.js 16, nonce buộc trang phải render động (không
static/ISR/PPR). FE0 phát hiện xung đột này khi build trang `/` (không prerender được nếu
backend không chạy lúc build) — báo cáo lại cho Architect trong bàn giao FE0
(2026-09-25).

**ĐÃ CHỐT ở ADR-004 §2.7 (2026-09-25, cùng ngày):** phương án **(a)** — giữ nonce cho
MỌI trang HTML cả 2 app, chấp nhận mất Full Route Cache, cache ở tầng Next Data Cache
(`publicFetch(url, { next: { revalidate, tags } })`). Lý do loại các phương án khác: (b)
bỏ nonce cho route công khai — yếu vì trang chi tiết khóa hiển thị mô tả HTML do GV nhập
(nguồn XSS lưu trữ), cùng origin với trang học sinh đã đăng nhập; (c) PPR/cacheComponents
— không tương thích nonce (đã xác nhận qua tài liệu Next.js).

Ngưỡng hiệu năng chấp nhận (đo ở FW2 bằng k6/autocannon): p95 TTFB ≤ 500ms ở 50 req/s
(cache ấm), ≤ 1,2s (cache lạnh). Không đạt → mở rộng instance Next.js trước; vẫn không đạt
→ Architect xem lại bằng ADR sửa đổi (không tự ý chuyển sang phương án (b)).

`proxy.ts` matcher (cả 2 app) loại trừ đúng theo §2.7: `_next/static`, `_next/image`,
`favicon.ico`, `robots.txt`, `sitemap.xml`, file tĩnh `public/` (không phải HTML, không
cần nonce).

**Why quan trọng:** đây là ví dụ về việc nêu câu hỏi kiến trúc rõ ràng trong báo cáo bàn
giao (kèm phân tích trade-off cụ thể) giúp Architect chốt nhanh trong cùng ngày, thay vì
tự ý chọn 1 phương án khi implement.

**How to apply:** Khi làm FW2 (danh mục `/lop-{grade}`, `/khoa-hoc/{slug}`), dùng đúng mẫu
đã áp dụng ở FE0 (`apps/web/app/page.tsx`): `export const dynamic = 'force-dynamic'` +
`publicFetch(path, { revalidate: <giây>, tags: [...] })`. Đo TTFB thật trước khi báo cáo
"đạt"/"không đạt" ngưỡng — đừng giả định.
