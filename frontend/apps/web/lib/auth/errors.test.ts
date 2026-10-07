import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import {
  ACCOUNT_LOCKED_MESSAGE,
  CAPTCHA_FAILED_MESSAGE,
  LOGIN_GENERIC_ERROR,
  OTP_CODE_LOCKED_MESSAGE,
  OTP_INVALID_MESSAGE,
  classifyChangePasswordError,
  classifyForgotError,
  classifyOtpSendError,
  classifyOtpVerifyError,
  classifyRegisterError,
  classifyResetError,
  loginErrorMessage,
  retryAfterText,
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

describe("classifyOtpVerifyError", () => {
  it("OTP_INVALID → invalid; có errors.code thì dùng thông điệp server", () => {
    expect(classifyOtpVerifyError(new ApiError(422, { message: "x", code: "OTP_INVALID" }))).toEqual({ kind: "invalid", message: OTP_INVALID_MESSAGE });
    expect(classifyOtpVerifyError(new ApiError(422, { message: "x", code: "OTP_INVALID", errors: { code: ["Sai rồi."] } }))).toEqual({ kind: "invalid", message: "Sai rồi." });
  });
  it("lỗi cũ không có code riêng (chỉ errors.code) → coi là sai mã", () => {
    expect(classifyOtpVerifyError(new ApiError(422, { message: "x", errors: { code: ["Mã không đúng"] } }))).toEqual({ kind: "invalid", message: "Mã không đúng" });
  });
  it("OTP_EXPIRED → must-resend", () => {
    expect(classifyOtpVerifyError(new ApiError(422, { message: "x", code: "OTP_EXPIRED" })).kind).toBe("must-resend");
  });
  it("429 không Retry-After (mã hết lượt) → must-resend; có Retry-After (throttle) → throttled", () => {
    expect(classifyOtpVerifyError(new ApiError(429, { message: "m", code: "TOO_MANY_ATTEMPTS" }))).toEqual({ kind: "must-resend", message: OTP_CODE_LOCKED_MESSAGE });
    const t = classifyOtpVerifyError(new ApiError(429, { message: "Chậm lại.", code: "TOO_MANY_ATTEMPTS" }, 300));
    expect(t).toMatchObject({ kind: "throttled", retryAfterSeconds: 300 });
    expect(t.message).toContain("5 phút");
  });
});

describe("classifyOtpSendError", () => {
  it("503 OTP_DELIVERY_FAILED → delivery", () => {
    expect(classifyOtpSendError(new ApiError(503, { message: "m", code: "OTP_DELIVERY_FAILED" })).kind).toBe("delivery");
  });
  it("429 Retry-After ngắn → wait; không có hoặc rất dài (hết lượt ngày) → locked", () => {
    expect(classifyOtpSendError(new ApiError(429, { message: "m" }, 42))).toEqual({ kind: "wait", seconds: 42, message: "m" });
    expect(classifyOtpSendError(new ApiError(429, { message: "Hết lượt." })).kind).toBe("locked");
    expect(classifyOtpSendError(new ApiError(429, { message: "Hết lượt." }, 80_000)).kind).toBe("locked");
  });
});

describe("classifyResetError (T27-5: mọi lỗi mã là OTP_EXPIRED)", () => {
  it("OTP_EXPIRED hoặc field code → code", () => {
    expect(classifyResetError(new ApiError(422, { message: "x", code: "OTP_EXPIRED", errors: { code: ["Mã OTP đã hết hạn."] } }))).toEqual({ kind: "code" });
    expect(classifyResetError(new ApiError(422, { message: "x" }))).toEqual({ kind: "code" });
  });
  it("mật khẩu phổ biến → fields.password nguyên văn", () => {
    expect(classifyResetError(new ApiError(422, { message: "x", errors: { password: ["Mật khẩu quá phổ biến."] } }))).toEqual({
      kind: "fields",
      errors: { password: "Mật khẩu quá phổ biến." },
    });
  });
  it("429 → throttled; 5xx → banner", () => {
    expect(classifyResetError(new ApiError(429, { message: "m" }, 480)).kind).toBe("throttled");
    expect(classifyResetError(new ApiError(500, { message: "Lỗi" }))).toEqual({ kind: "banner", message: "Lỗi" });
  });
});

describe("classifyChangePasswordError / classifyForgotError", () => {
  it("đổi mật khẩu: 422 theo field, 429 throttled", () => {
    expect(
      classifyChangePasswordError(new ApiError(422, { message: "x", errors: { current_password: ["Mật khẩu hiện tại không đúng."] } })),
    ).toEqual({ kind: "fields", errors: { current_password: "Mật khẩu hiện tại không đúng." } });
    expect(classifyChangePasswordError(new ApiError(429, { message: "m" }, 900)).kind).toBe("throttled");
  });
  it("quên mật khẩu: captcha, 429, 422 field login", () => {
    expect(classifyForgotError(new ApiError(422, { message: "m", code: "CAPTCHA_FAILED" })).kind).toBe("captcha");
    expect(classifyForgotError(new ApiError(429, { message: "m" }, 60)).kind).toBe("throttled");
    expect(classifyForgotError(new ApiError(422, { message: "m", errors: { login: ["Nhập sai"] } }))).toEqual({ kind: "field", message: "Nhập sai" });
  });
});

describe("retryAfterText", () => {
  it("làm tròn lên phút/giờ, thiếu → câu chung", () => {
    expect(retryAfterText(120)).toBe("Vui lòng thử lại sau 2 phút.");
    expect(retryAfterText(61)).toBe("Vui lòng thử lại sau 2 phút.");
    expect(retryAfterText(7200)).toBe("Vui lòng thử lại sau 2 giờ.");
    expect(retryAfterText(undefined)).toBe("Vui lòng thử lại sau ít phút.");
    expect(retryAfterText(20)).toBe("Vui lòng thử lại sau 20 giây.");
  });
});
