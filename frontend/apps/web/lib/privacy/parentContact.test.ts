import { describe, expect, it } from "vitest";
import { buildParentContactBody } from "./parentContact";
import { readUnsubscribeToken } from "./unsubscribe";
import { parentNoticeStatus } from "./format";

const base = { email: "", phone: "", removeEmail: false, removePhone: false, password: "matkhau-123" };

describe("buildParentContactBody", () => {
  it("ô trống bỏ key, có nhập thì thay, xoá thì null", () => {
    expect(buildParentContactBody({ ...base, email: " pa@example.com " }).body).toEqual({ current_password: "matkhau-123", parent_email: "pa@example.com" });
    expect(buildParentContactBody({ ...base, removePhone: true }).body).toEqual({ current_password: "matkhau-123", parent_phone: null });
    const both = buildParentContactBody({ ...base, email: "a@b.vn", removePhone: true }).body;
    expect(both).toEqual({ current_password: "matkhau-123", parent_email: "a@b.vn", parent_phone: null });
  });
  it("không thay đổi gì / thiếu mật khẩu / sai định dạng -> lỗi, không có body", () => {
    expect(buildParentContactBody(base).errors.form).toBe("Vui lòng nhập thông tin cần cập nhật.");
    expect(buildParentContactBody({ ...base, email: "a@b.vn", password: "" }).errors.current_password).toBeTruthy();
    const r = buildParentContactBody({ ...base, email: "khong-hop-le", phone: "123" });
    expect(r.body).toBeNull();
    expect(r.errors.parent_email).toBeTruthy();
    expect(r.errors.parent_phone).toBeTruthy();
  });
});

describe("readUnsubscribeToken", () => {
  it("đọc t, bỏ rỗng và quá 512 ký tự", () => {
    expect(readUnsubscribeToken("?t=12.abc_-")).toBe("12.abc_-");
    expect(readUnsubscribeToken("")).toBeNull();
    expect(readUnsubscribeToken("?t=")).toBeNull();
    expect(readUnsubscribeToken(`?t=${"a".repeat(513)}`)).toBeNull();
  });
});

describe("parentNoticeStatus", () => {
  const c = { email_masked: null, phone_masked: null, has_email: false, has_phone: false, notices_enabled: false, notices_opted_out_at: null };
  it("4 trạng thái", () => {
    expect(parentNoticeStatus(c)).toBe("none");
    expect(parentNoticeStatus({ ...c, has_email: true, notices_enabled: true })).toBe("enabled");
    expect(parentNoticeStatus({ ...c, has_email: true, notices_opted_out_at: "2026-10-01T00:00:00+07:00" })).toBe("opted-out");
    expect(parentNoticeStatus({ ...c, has_email: true })).toBe("paused");
  });
});
