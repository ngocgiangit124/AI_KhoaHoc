import { ApiError } from "@vitaminvui/api-client";
import { describe, expect, it, vi } from "vitest";
import { applyApiErrorToForm } from "./mapApiError";

interface FormValues {
  email: string;
  phone: string;
}

describe("applyApiErrorToForm", () => {
  it("gắn lỗi field đã biết bằng setError, không cần banner", () => {
    const err = new ApiError(422, {
      message: "Dữ liệu không hợp lệ",
      code: "VALIDATION_ERROR",
      errors: { email: ["Email đã được sử dụng"] },
    });
    const setError = vi.fn();

    const result = applyApiErrorToForm<FormValues>(err, setError, ["email", "phone"]);

    expect(setError).toHaveBeenCalledWith("email", { type: "server", message: "Email đã được sử dụng" });
    expect(result.bannerMessage).toBeNull();
    expect(result.bannerVariant).toBe("danger");
  });

    it("field lạ (không có trong form) rơi vào banner thay vì bị bỏ qua", () => {
    const err = new ApiError(422, {
      message: "Dữ liệu không hợp lệ",
      errors: { captcha_token: ["Xác minh chống spam thất bại"] },
    });
    const setError = vi.fn();

    const result = applyApiErrorToForm<FormValues>(err, setError, ["email", "phone"]);

    expect(setError).not.toHaveBeenCalled();
    expect(result.bannerMessage).toBe("Xác minh chống spam thất bại");
  });

  it("không có errors object -> dùng message chung làm banner (vd sai thông tin đăng nhập)", () => {
    const err = new ApiError(422, { message: "Thông tin đăng nhập hoặc mật khẩu không đúng" });
    const setError = vi.fn();

    const result = applyApiErrorToForm<FormValues>(err, setError, ["email", "phone"]);

    expect(setError).not.toHaveBeenCalled();
    expect(result.bannerMessage).toBe("Thông tin đăng nhập hoặc mật khẩu không đúng");
    expect(result.bannerVariant).toBe("danger");
  });

  it("429 TOO_MANY_ATTEMPTS -> banner variant warning", () => {
    const err = new ApiError(429, { message: "Bạn thao tác quá nhanh, vui lòng thử lại sau.", code: "TOO_MANY_ATTEMPTS" });
    const setError = vi.fn();

    const result = applyApiErrorToForm<FormValues>(err, setError, ["email", "phone"]);

    expect(result.bannerVariant).toBe("warning");
  });
});
