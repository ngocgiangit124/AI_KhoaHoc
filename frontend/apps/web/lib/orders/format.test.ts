import { describe, expect, it } from "vitest";
import { deadlineText, hasContactChannel, hoursUntil, itemsSummary, methodLabel, orderHistory, safeZaloUrl, studentStatus, telHref } from "./format";

describe("studentStatus (bảng trạng thái US-022)", () => {
  it.each([
    ["pending", null, "Chờ Quản trị viên duyệt"],
    ["paid", "manual_confirmed", "Đã thanh toán"],
    ["refunded", null, "Đã hoàn tiền"],
    ["cancelled", "user_cancelled", "Bạn đã huỷ đơn"],
    ["cancelled", "admin_cancelled", "Đã huỷ bởi Quản trị viên"],
    ["cancelled", "expired", "Đã huỷ do quá hạn chờ"],
    ["cancelled", "superseded", "Đã thay bằng đơn mới"],
    ["cancelled", "account_deleted", "Đã huỷ"],
    ["trang_thai_la", null, "Đã huỷ"],
  ])("%s / %s -> %s", (status, reason, label) => {
    expect(studentStatus(status, reason).label).toBe(label);
  });
});

describe("hạn chờ", () => {
  const now = Date.parse("2026-10-08T20:00:00+07:00");
  it("làm tròn lên theo giờ; dưới 1 giờ; quá hạn", () => {
    expect(hoursUntil("2026-10-10T19:42:00+07:00", now)).toBe(48);
    expect(deadlineText("2026-10-10T19:42:00+07:00", now)).toBe("19:42, 10/10/2026 (còn 48 giờ)");
    expect(deadlineText("2026-10-08T20:30:00+07:00", now)).toBe("20:30, 08/10/2026 (còn dưới 1 giờ)");
    expect(deadlineText("2026-10-08T19:00:00+07:00", now)).toContain("đã quá hạn");
    expect(hoursUntil("khong-phai-ngay", now)).toBe(0);
  });
});

describe("kênh liên hệ", () => {
  it("tel: giữ chữ số và + đầu; không có số -> null", () => {
    expect(telHref("0915 592 224")).toBe("tel:0915592224");
    expect(telHref("+84 915 592 224")).toBe("tel:+84915592224");
    expect(telHref("(không có)")).toBeNull();
  });
  it("chỉ nhận Zalo https://zalo.me/..., URL lạ bị bỏ", () => {
    expect(safeZaloUrl("https://zalo.me/0915592224")).toBe("https://zalo.me/0915592224");
    expect(safeZaloUrl("javascript:alert(1)")).toBeNull();
    expect(safeZaloUrl("https://evil.example/zalo.me/1")).toBeNull();
    expect(safeZaloUrl(null)).toBeNull();
  });
  it("hasContactChannel: kênh trống (null) không tính", () => {
    expect(hasContactChannel({ phone: null, zalo_url: null, email: null, hours: "8h–17h" })).toBe(false);
    expect(hasContactChannel({ phone: null, zalo_url: null, email: "hotro@vitaminvui.vn", hours: null })).toBe(true);
  });
});

describe("hiển thị khác", () => {
  it("nhãn phương thức, tóm tắt khóa", () => {
    expect(methodLabel("manual")).toBe("Liên hệ Quản trị viên");
    expect(methodLabel("none")).toBe("Miễn phí (mã giảm giá)");
    expect(methodLabel("zalopay")).toBe("zalopay");
    expect(itemsSummary(["Toán 9", "Văn 9"], 5)).toBe("Toán 9 và 4 khóa khác");
    expect(itemsSummary(["Toán 9"], 1)).toBe("Toán 9");
  });
  it("lịch sử phía học sinh: không lộ ghi chú nội bộ, đúng thứ tự", () => {
    const rows = orderHistory({ status: "cancelled", status_reason: "admin_cancelled", payment_method: "manual", created_at: "a", cancelled_at: "b" });
    expect(rows.map((r) => r.text)).toEqual(["Bạn đặt đơn, chọn “Liên hệ Quản trị viên”", "Quản trị viên huỷ đơn"]);
  });
});
