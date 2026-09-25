import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";
import "@testing-library/jest-dom/vitest";

// Không dùng vitest "globals: true" (import tường minh từ "vitest") nên
// @testing-library/react không tự đăng ký afterEach(cleanup) — phải gọi tay, nếu không
// DOM giữa các test trong cùng file sẽ chồng lên nhau.
afterEach(() => {
  cleanup();
});
