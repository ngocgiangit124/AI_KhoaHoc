import { describe, expect, it } from "vitest";
import { noteForRequest, validateNote } from "./note";

describe("ghi chú cho Quản trị viên", () => {
  it("hợp lệ: nhiều dòng, tối đa 500 ký tự", () => {
    expect(validateNote("Gọi sau 18h\nZalo của mẹ: 0987 654 321")).toBeNull();
    expect(validateNote("a".repeat(500))).toBeNull();
    expect(validateNote("a".repeat(501))).toContain("500");
  });
  it("cấm < > và ký tự điều khiển (như server)", () => {
    expect(validateNote("<b>hi</b>")).toContain("<");
    expect(validateNote("a\u0000b")).not.toBeNull();
  });
  it("noteForRequest: rỗng/chỉ khoảng trắng -> không gửi", () => {
    expect(noteForRequest("   ")).toBeUndefined();
    expect(noteForRequest("  Gọi sau 18h ")).toBe("Gọi sau 18h");
  });
});
