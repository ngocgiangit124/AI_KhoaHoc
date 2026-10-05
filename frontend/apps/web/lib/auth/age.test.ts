import { describe, expect, it } from "vitest";
import { ageOn, isBelowConsentAge, isValidIsoDate, todayInVietnam } from "./age";

describe("age", () => {
  it("todayInVietnam dùng múi giờ Asia/Ho_Chi_Minh (UTC+7)", () => {
    expect(todayInVietnam(new Date("2026-10-05T18:00:00Z"))).toBe("2026-10-06");
    expect(todayInVietnam(new Date("2026-10-05T16:59:00Z"))).toBe("2026-10-05");
  });

  it("isValidIsoDate loại ngày không có thật", () => {
    expect(isValidIsoDate("2010-02-28")).toBe(true);
    expect(isValidIsoDate("2010-02-30")).toBe(false);
    expect(isValidIsoDate("10/02/2010")).toBe(false);
    expect(isValidIsoDate("")).toBe(false);
  });

  it("ageOn tính đúng quanh sinh nhật", () => {
    expect(ageOn("2008-10-05", "2026-10-05")).toBe(18);
    expect(ageOn("2008-10-06", "2026-10-05")).toBe(17);
    expect(ageOn("2008-02-29", "2026-02-28")).toBe(17);
  });

  it("ageOn trả null khi sinh ở tương lai hoặc không hợp lệ", () => {
    expect(ageOn("2027-01-01", "2026-10-05")).toBeNull();
    expect(ageOn("abc", "2026-10-05")).toBeNull();
  });

  it("isBelowConsentAge: đúng 18 tuổi thì không cần phụ huynh", () => {
    expect(isBelowConsentAge("2008-10-05", 18, "2026-10-05")).toBe(false);
    expect(isBelowConsentAge("2008-10-06", 18, "2026-10-05")).toBe(true);
    expect(isBelowConsentAge("", 18, "2026-10-05")).toBe(false);
  });
});
