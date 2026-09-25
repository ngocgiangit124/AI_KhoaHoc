import { describe, expect, it } from "vitest";
import { isAllowedPayUrl, jsonLd, safeRedirect } from "./security";

describe("safeRedirect (S23 — chống open redirect)", () => {
  it("chấp nhận đường dẫn tương đối hợp lệ", () => {
    expect(safeRedirect("/tai-khoan/don-hang")).toBe("/tai-khoan/don-hang");
  });

  it.each([
    [null, "null"],
    [undefined, "undefined"],
    ["", "rỗng"],
    ["//evil.com", "protocol-relative //"],
    ["/\\evil.com", "backslash /\\"],
    ["http://evil.com", "URL tuyệt đối"],
    ["https://evil.com/x", "URL tuyệt đối https"],
    ["javascript:alert(1)", "scheme javascript:"],
    ["ftp://evil.com", "scheme khác"],
  ])("từ chối %s (%s) và trả về fallback", (input: string | null | undefined, _label: string) => {
    expect(safeRedirect(input)).toBe("/");
  });

  it("dùng fallback tuỳ chỉnh", () => {
    expect(safeRedirect("//evil.com", "/dang-nhap")).toBe("/dang-nhap");
  });

  it("từ chối ký tự điều khiển", () => {
    expect(safeRedirect("/a\u0000b")).toBe("/");
  });
});

describe("isAllowedPayUrl (S23 — chỉ mở MoMo khi host thuộc allowlist)", () => {
  const allowlist = ["test-payment.momo.vn"];

  it("chấp nhận host đúng allowlist qua https", () => {
    expect(isAllowedPayUrl("https://test-payment.momo.vn/pay/abc", allowlist)).toBe(true);
  });

  it("từ chối host không thuộc allowlist", () => {
    expect(isAllowedPayUrl("https://evil.com/pay", allowlist)).toBe(false);
  });

  it("từ chối http (không phải https)", () => {
    expect(isAllowedPayUrl("http://test-payment.momo.vn/pay/abc", allowlist)).toBe(false);
  });

  it("từ chối URL không hợp lệ", () => {
    expect(isAllowedPayUrl("not-a-url", allowlist)).toBe(false);
  });

  it("từ chối host giả dạng bằng subdomain phụ", () => {
    expect(isAllowedPayUrl("https://test-payment.momo.vn.evil.com/pay", allowlist)).toBe(false);
  });
});

describe("jsonLd (S23 — escape < để chống đóng sớm thẻ script)", () => {
  it("escape ký tự < (đủ để phá chuỗi \"</script>\")", () => {
    const result = jsonLd({ x: "</script><script>alert(1)</script>" });
    expect(result).not.toContain("</script>");
    expect(result).toContain("\\u003c/script>");
  });
});
