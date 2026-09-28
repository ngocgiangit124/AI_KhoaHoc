import { afterEach, describe, expect, it } from "vitest";
import { __setIsProductionBuildForTest, isProductionBuild } from "./isProductionBuild";

describe("isProductionBuild", () => {
  afterEach(() => {
    // Vitest chạy với NODE_ENV=test — khôi phục lại false để không rò rỉ sang test khác.
    __setIsProductionBuildForTest(false);
  });

  it("mặc định false khi chạy test (NODE_ENV=test, không phải production)", () => {
    expect(isProductionBuild).toBe(false);
  });

  it("__setIsProductionBuildForTest thay đổi được giá trị cho test", () => {
    __setIsProductionBuildForTest(true);
    // Import lại module (cùng instance trong 1 lần chạy test file) phải thấy giá trị mới —
    // đọc trực tiếp biến đã import ở đầu file vì đây là live binding của ES module.
    expect(isProductionBuild).toBe(true);
  });
});
