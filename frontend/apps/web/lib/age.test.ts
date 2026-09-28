import { describe, expect, it } from "vitest";
import { calculateAgeYears } from "./age";

describe("calculateAgeYears", () => {
  it("17 tuổi 364 ngày (chưa đủ 18) — sinh nhật ngày mai", () => {
    const now = new Date(2026, 8, 28); // 2026-09-28
    const dob = "2008-09-29";
    expect(calculateAgeYears(dob, now)).toBe(17);
  });

  it("đủ 18 tuổi đúng ngày sinh nhật hôm nay", () => {
    const now = new Date(2026, 8, 28); // 2026-09-28
    const dob = "2008-09-28";
    expect(calculateAgeYears(dob, now)).toBe(18);
  });

  it("đã qua sinh nhật trong năm nay", () => {
    const now = new Date(2026, 8, 28);
    const dob = "2008-01-01";
    expect(calculateAgeYears(dob, now)).toBe(18);
  });

  it("chưa tới sinh nhật trong năm nay", () => {
    const now = new Date(2026, 8, 28);
    const dob = "2008-12-31";
    expect(calculateAgeYears(dob, now)).toBe(17);
  });

  it("ngày sinh không hợp lệ trả về null", () => {
    expect(calculateAgeYears("khong-phai-ngay")).toBeNull();
  });
});
