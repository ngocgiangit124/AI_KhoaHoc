import { describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
vi.mock("@/lib/api", () => ({ authFetch: vi.fn() }));

import { buildRegisterPayload, parseAuthUser } from "./api";
import type { RegisterFormValues } from "./schemas";

const base: RegisterFormValues = {
  name: " Nguyễn Văn A ",
  date_of_birth: "2000-01-01",
  email: "a@example.com",
  phone: "0912345678",
  grade_level: "9",
  password: "matkhau123",
  password_confirmation: "matkhau123",
  parent_phone: "0987654321",
  parent_email: "",
  referral_code: " ABC ",
  accept_terms: true,
  accept_privacy: true,
};

describe("buildRegisterPayload", () => {
  it("đủ tuổi: không gửi field phụ huynh; referral tắt thì bỏ; không captcha thì bỏ token", () => {
    const p = buildRegisterPayload({
      values: base,
      captchaToken: null,
      parentConsentAge: 18,
      referralEnabled: false,
      deviceId: "dev-1",
    });
    expect(p).toEqual({
      name: "Nguyễn Văn A",
      date_of_birth: "2000-01-01",
      email: "a@example.com",
      phone: "0912345678",
      grade_level: 9,
      password: "matkhau123",
      password_confirmation: "matkhau123",
      accept_terms: true,
      accept_privacy: true,
      device_id: "dev-1",
    });
  });

  it("dưới tuổi: gửi liên hệ phụ huynh đã nhập, referral bật, có captcha", () => {
    const p = buildRegisterPayload({
      values: { ...base, date_of_birth: "2020-01-01" },
      captchaToken: "tok",
      parentConsentAge: 18,
      referralEnabled: true,
      deviceId: "dev-1",
    });
    expect(p.parent_phone).toBe("0987654321");
    expect(p.parent_email).toBeUndefined();
    expect(p.referral_code).toBe("ABC");
    expect(p.captcha_token).toBe("tok");
  });
});

describe("parseAuthUser", () => {
  const user = {
    id: 1,
    name: "A",
    email: "a@example.com",
    phone: "0912345678",
    role: "hoc_sinh",
    grade_level: 9,
    is_verified: false,
    parent_consent_status: "pending",
  };
  it("nhận user đúng contract", () => {
    expect(parseAuthUser(user)?.parent_consent_status).toBe("pending");
  });
  it("sai shape → null, không ném lỗi", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    expect(parseAuthUser({ ...user, parent_consent_status: "x" })).toBeNull();
    expect(parseAuthUser(undefined)).toBeNull();
  });
});

describe("buildRegisterPayload forceParent", () => {
  it("gửi liên hệ phụ huynh dù client tính đủ tuổi khi forceParent", () => {
    const p = buildRegisterPayload({
      values: base,
      captchaToken: null,
      parentConsentAge: 18,
      referralEnabled: false,
      deviceId: "d",
      forceParent: true,
    });
    expect(p.parent_phone).toBe("0987654321");
  });
});
