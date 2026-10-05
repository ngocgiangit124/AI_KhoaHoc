import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import {
  ACCOUNT_LOCKED_MESSAGE,
  CAPTCHA_FAILED_MESSAGE,
  LOGIN_GENERIC_ERROR,
  classifyRegisterError,
  loginErrorMessage,
} from "./errors";

describe("loginErrorMessage", () => {
  it("422 → thông điệp chung, không lộ field", () => {
    const err = new ApiError(422, { message: "x", errors: { login: ["Email không tồn tại"] } });
    expect(loginErrorMessage(err)).toBe(LOGIN_GENERIC_ERROR);
  });
  it("403 ACCOUNT_LOCKED", () => {
    expect(loginErrorMessage(new ApiError(403, { message: "m", code: "ACCOUNT_LOCKED" }))).toBe(ACCOUNT_LOCKED_MESSAGE);
  });
  it("WRONG_PORTAL dùng message của server", () => {
    expect(loginErrorMessage(new ApiError(403, { message: "Sai cổng", code: "WRONG_PORTAL" }))).toBe("Sai cổng");
  });
  it("429 dùng message server", () => {
    expect(loginErrorMessage(new ApiError(429, { message: "Thử lại sau 5 phút", code: "TOO_MANY_ATTEMPTS" }))).toBe(
      "Thử lại sau 5 phút",
    );
  });
  it("lỗi mạng", () => {
    const err = new NetworkError(new Error("x"));
    expect(loginErrorMessage(err)).toBe(err.message);
  });
});

describe("classifyRegisterError", () => {
  it("422 → lấy thông điệp đầu tiên mỗi field", () => {
    const err = new ApiError(422, {
      message: "x",
      code: "VALIDATION_ERROR",
      errors: { email: ["Email đã được sử dụng", "khác"], phone: ["Số điện thoại đã được sử dụng"] },
    });
    expect(classifyRegisterError(err)).toEqual({
      kind: "fields",
      errors: { email: "Email đã được sử dụng", phone: "Số điện thoại đã được sử dụng" },
    });
  });
  it("CAPTCHA_FAILED", () => {
    expect(classifyRegisterError(new ApiError(422, { message: "m", code: "CAPTCHA_FAILED" }))).toEqual({
      kind: "captcha",
      message: CAPTCHA_FAILED_MESSAGE,
    });
  });
  it("5xx → banner", () => {
    expect(classifyRegisterError(new ApiError(500, { message: "Lỗi hệ thống" }))).toEqual({
      kind: "banner",
      message: "Lỗi hệ thống",
    });
  });
});
