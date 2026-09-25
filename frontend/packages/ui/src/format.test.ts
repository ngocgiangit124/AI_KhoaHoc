import { describe, expect, it } from "vitest";
import { formatCurrencyVnd, formatDateVn, formatDateTimeVn } from "./format";

// Intl.NumberFormat('vi-VN', {style:'currency'}) chèn khoảng trắng đặc biệt (thường là
// U+00A0/U+202F, không phải space thường) trước "₫" — chuẩn hoá về space thường để test
// không phụ thuộc bản ICU cụ thể của môi trường chạy.
function normalizeSpaces(value: string): string {
  return value.replace(/\s/g, " ");
}

describe("formatCurrencyVnd", () => {
  it("định dạng số nguyên VNĐ theo vi-VN (dấu chấm ngăn hàng nghìn, ký hiệu ₫)", () => {
    expect(normalizeSpaces(formatCurrencyVnd(1500000))).toBe("1.500.000 ₫");
  });

  it("định dạng 0đ", () => {
    expect(normalizeSpaces(formatCurrencyVnd(0))).toBe("0 ₫");
  });
});

describe("formatDateTimeVn / formatDateVn", () => {
  it("format theo giờ Asia/Ho_Chi_Minh, không phụ thuộc múi giờ máy chạy test", () => {
    // 2026-09-25T14:30:00+07:00 == 07:30:00 UTC
    const iso = "2026-09-25T07:30:00.000Z";
    expect(formatDateTimeVn(iso)).toBe("25/09/2026, 14:30");
    expect(formatDateVn(iso)).toBe("25/09/2026");
  });
});
