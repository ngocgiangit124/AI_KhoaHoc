---
name: vitest-testing-library-cleanup
description: Vitest không dùng test.globals=true thì @testing-library/react không tự cleanup DOM giữa các test — phải tự gọi trong setupFiles
metadata:
  type: feedback
---

Khi cấu hình Vitest với import tường minh (`import { describe, it, expect } from
"vitest"`, KHÔNG bật `test.globals: true`), `@testing-library/react` không tự đăng ký
`afterEach(cleanup)` (nó dựa vào phát hiện global `afterEach`/`vi` sẵn có). Hậu quả: mỗi
`render()` trong cùng 1 file test CHỒNG lên `document.body` của lần render trước, gây lỗi
khó hiểu như "Found multiple elements with role X" hoặc `toBeDisabled()` fail sai (vì
`getByRole` vô tình khớp phần tử của lần render TRƯỚC, không phải lần hiện tại).

**Why:** Gặp lỗi này ở VitaminVui khi viết test cho `<Button loading>` và
`<ForcedLogoutOverlay>` (packages/ui) — test đầu tiên trong file luôn pass, test thứ 2 trở
đi fail vì DOM cũ chưa bị dọn.

**How to apply:** Luôn thêm vào `setupFiles`:
```ts
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";
afterEach(() => cleanup());
```
Áp dụng cho mọi package/app dùng Vitest + `@testing-library/react` mà không bật
`test.globals: true`. Xem [[vitaminvui-fe0-workspace]] — đã áp dụng ở
`packages/ui/src/setupTests.ts`, `apps/web/vitest.setup.ts`, `apps/admin/vitest.setup.ts`.
