---
name: fa2-subjects-screen
description: FA2 màn Chuyên đề admin - cấu trúc, bẫy contract (object đơn phẳng), bẫy Modal onClose, giới hạn OTP khi chạy e2e thật
metadata:
  type: project
---

Code: apps/admin/lib/subjects/{api,query,errors,types}.ts, components/subjects/*, app/quan-tri/chuyen-de/page.tsx. Bộ lọc q/status/page/per_page trên URL; tải ở client (effect + AbortController, loading suy từ key).
Bẫy: backend gọi JsonResource::withoutWrapping() nên object đơn (POST/PUT/PATCH) trả PHẲNG, không `{data}` (list phân trang vẫn data/meta/links). Unit test mock che lỗi này, chỉ e2e thật bắt được.
Bẫy: `Modal` (packages/ui) focus lại hộp thoại mỗi khi `onClose` đổi identity -> luôn truyền callback ổn định; input autofocus phải setTimeout 0.
E2E thật: OTP giới hạn 1/phút + theo giờ theo tài khoản, đăng nhập MFA lặp nhiều lần bị 429 "thao tác quá nhanh" -> gộp nhiều kiểm tra vào một phiên. Lấy mã OTP theo ID mail mới (không theo đồng hồ), click ô OTP đầu rồi mới gõ.
Chạy e2e: docker run ... mcr.microsoft.com/playwright:v1.63.0-noble --network host --add-host (như scripts/playwright.sh) nhưng truyền được tên spec; không chạy song song với `next build` (cùng .next).
