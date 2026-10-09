import { describe, expect, it } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { classifyChangePasswordError, classifyForgotError, classifyRegisterError, classifyResetError, classifyOtpVerifyError } from "@/lib/auth/errors";
import { otpErrorMessage } from "@/lib/auth/otp";
import { classifyAcceptError, classifyExportError, classifyParentContactError, pendingPaymentMessage } from "@/lib/privacy/errors";
import { courseRefFromError } from "@/lib/learn/errors";

const MSG = "Email đã được sử dụng ạ";
const mk = (errors: Record<string, unknown>, code?: string) => new ApiError(422, { message: "Dữ liệu không hợp lệ", errors, ...(code ? { code } : {}) } as never);
const KEYS = ["name", "email", "phone", "password", "password_confirmation", "current_password", "login", "code", "parent_email", "parent_phone", "accept_terms"];
const std = () => mk(Object.fromEntries(KEYS.map((k) => [k, [MSG]])));
const weird = () => mk({ ...Object.fromEntries(KEYS.map((k) => [k, [MSG]])), limit: 2, resets_at: "2026-10-10T00:00:00+07:00", course: { slug: "a", title: "b" }, note: "chuỗi lẻ" });
const bad = (v: unknown) => {
  const s = JSON.stringify(v) ?? "undefined";
  expect(s).not.toContain("undefined");
  expect(s).not.toMatch(/"(?:[^"\\]|\\.)"[,}\]]/);
};
const cases: Array<[string, (e: ApiError) => unknown, string]> = [
  ["register", classifyRegisterError, "fields"],
  ["reset", classifyResetError, "fields"],
  ["change password", classifyChangePasswordError, "fields"],
  ["forgot", classifyForgotError, "field"],
  ["otp verify", classifyOtpVerifyError, "invalid"],
  ["otp message", otpErrorMessage, ""],
  ["parent contact", classifyParentContactError, "fields"],
  ["accept consent", classifyAcceptError, "fields"],
  ["export", classifyExportError, "password"],
  ["pending payment", pendingPaymentMessage, ""],
  ["learn course ref", courseRefFromError, ""],
];
describe("QA FW3-1 web 422", () => {
  for (const [name, fn, kind] of cases) {
    it(`${name}: chuẩn`, () => {
      const r = fn(std());
      bad(r);
      if (kind) expect((r as { kind: string }).kind).toBe(kind);
      console.log("STD", name, JSON.stringify(r));
    });
    it(`${name}: lẫn kiểu`, () => {
      const r = fn(weird());
      bad(r);
      console.log("MIX", name, JSON.stringify(r));
    });
    it(`${name}: rỗng`, () => {
      expect(() => fn(new ApiError(422, { message: "Lỗi" } as never))).not.toThrow();
      expect(() => fn(mk({}))).not.toThrow();
    });
  }
  it("register: chuỗi lẻ không thành ký tự đơn", () => {
    const r = classifyRegisterError(mk({ email: [MSG], note: "abc" })) as { errors: Record<string, string> };
    expect(r.errors).toEqual({ email: MSG });
  });
});
