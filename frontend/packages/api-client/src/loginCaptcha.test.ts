import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiError, isStaleDocument, loginNeedsCaptcha } from "./index";

describe("loginNeedsCaptcha (GL-A2)", () => {
  it("cờ captcha_required hoặc code CAPTCHA_* ở 422 -> true", () => {
    expect(loginNeedsCaptcha(new ApiError(422, { message: "m", captcha_required: true }))).toBe(true);
    expect(loginNeedsCaptcha(new ApiError(422, { message: "m", code: "CAPTCHA_REQUIRED" }))).toBe(true);
    expect(loginNeedsCaptcha(new ApiError(422, { message: "m", code: "CAPTCHA_INVALID" }))).toBe(true);
  });
  it("thiếu cờ = false; lỗi khác không tính", () => {
    expect(new ApiError(422, { message: "m" }).captchaRequired).toBe(false);
    expect(loginNeedsCaptcha(new ApiError(422, { message: "m", code: "VALIDATION_ERROR", captcha_required: false }))).toBe(false);
    expect(loginNeedsCaptcha(new ApiError(429, { message: "m", captcha_required: true }))).toBe(false);
    expect(loginNeedsCaptcha(new Error("x"))).toBe(false);
  });
});

describe("isStaleDocument (GL-A2)", () => {
  const nav = (name: string) => vi.spyOn(performance, "getEntriesByType").mockReturnValue([{ name }] as unknown as PerformanceEntryList);
  afterEach(() => vi.restoreAllMocks());

  it("tài liệu gốc khác đường dẫn hiện tại (điều hướng mềm) -> true", () => {
    nav("http://localhost:3000/quan-tri/khoa-hoc");
    expect(isStaleDocument()).toBe(window.location.pathname !== "/quan-tri/khoa-hoc");
    nav(`http://localhost:3000${window.location.pathname}?x=1`);
    expect(isStaleDocument()).toBe(false);
  });
  it("không có Navigation Timing -> false", () => {
    vi.spyOn(performance, "getEntriesByType").mockReturnValue([]);
    expect(isStaleDocument()).toBe(false);
  });
});
