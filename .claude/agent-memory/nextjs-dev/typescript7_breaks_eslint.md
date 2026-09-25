---
name: typescript7-breaks-eslint
description: TypeScript 7 (bản viết lại native/Go, đã lên npm dist-tag latest) chưa được typescript-eslint hỗ trợ — cài "latest" sẽ vỡ eslint-config-next
metadata:
  type: reference
---

Tính tới 2026-09-25, `npm view typescript version` trả về `7.0.2` (bản native/Go rewrite)
là dist-tag `latest`, nhưng `typescript-eslint` (phụ thuộc trong của `eslint-config-next`)
có `peerDependencies.typescript: ">=4.8.4 <6.1.0"` — KHÔNG hỗ trợ TS 7.x. Nếu cài
`typescript@latest` không ghim version cho project Next.js dùng `eslint-config-next`, lint
sẽ lỗi peer dependency hoặc hành vi type-aware lint sai.

**Why:** Phát hiện khi setup FE0 cho VitaminVui — ban đầu định dùng `typescript@latest`
theo thói quen "luôn lấy bản mới nhất", nhưng phải ghim lại `5.9.3` (nhánh 5.x mới nhất,
nằm trong range `typescript-eslint` hỗ trợ) mới lint chạy được.

**How to apply:** Trước khi ghim version `typescript` cho bất kỳ project Next.js/ESLint
nào, kiểm `npm view typescript-eslint peerDependencies` (hoặc package tương đương) để biết
range hỗ trợ thật, đừng mặc định dist-tag `latest` của riêng `typescript` là an toàn. Luôn
kiểm lại theo thời gian — `typescript-eslint` có thể đã hỗ trợ TS 7 ở tương lai, memory
này có thể đã lỗi thời.
