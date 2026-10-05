import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import {
  buildContactPayload,
  formatCountdown,
  maskEmail,
  maskPhone,
  OTP_WRONG_MESSAGE,
  otpErrorMessage,
  secondsUntil,
} from "./otp";

describe("otp helpers", () => {
  it("che email và SĐT", () => {
    expect(maskEmail("nguyenvana@gmail.com")).toBe("n******@gmail.com");
    expect(maskEmail("ab@x.vn")).toBe("a**@x.vn");
    expect(maskPhone("0912345678")).toBe("*******678");
  });

  it("secondsUntil làm tròn lên, không âm, mốc xấu → 0", () => {
    const now = Date.parse("2026-10-05T10:00:00Z");
    expect(secondsUntil("2026-10-05T10:00:30.200Z", now)).toBe(31);
    expect(secondsUntil("2026-10-05T09:00:00Z", now)).toBe(0);
    expect(secondsUntil("không phải ngày", now)).toBe(0);
    expect(secondsUntil(null, now)).toBe(0);
  });

  it("formatCountdown", () => {
    expect(formatCountdown(65)).toBe("01:05");
    expect(formatCountdown(-3)).toBe("00:00");
  });

  it("buildContactPayload chỉ gửi field đã đổi", () => {
    const cur = { email: "a@x.vn", phone: "0912345678" };
    expect(buildContactPayload(cur, { email: " a@x.vn ", phone: "0912345678" })).toEqual({});
    expect(buildContactPayload(cur, { email: "b@x.vn", phone: "0912345678" })).toEqual({ email: "b@x.vn" });
    expect(buildContactPayload(cur, { email: "a@x.vn", phone: "0987654321" })).toEqual({ phone: "0987654321" });
    expect(buildContactPayload(cur, { email: "A@X.vn", phone: "+84 912 345 678" })).toEqual({});
  });

  it("otpErrorMessage", () => {
    expect(otpErrorMessage(new ApiError(422, { message: "x", errors: { code: ["Mã OTP đã hết hạn."] } }))).toBe(
      "Mã OTP đã hết hạn.",
    );
    expect(otpErrorMessage(new ApiError(422, { message: "x" }))).toBe(OTP_WRONG_MESSAGE);
    expect(otpErrorMessage(new ApiError(429, { message: "Thử lại sau." }))).toBe("Thử lại sau.");
    expect(otpErrorMessage(new NetworkError("x"))).toMatch(/kết nối/);
  });
});
