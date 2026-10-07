import { describe, expect, it } from "vitest";
import { formatEnrollments, formatLessonDuration, formatTotalDuration } from "./format";

describe("formatEnrollments", () => {
  it("0 -> câu chữ tích cực", () => expect(formatEnrollments(0)).toBe("Chưa có học sinh đăng ký"));
  it("định dạng vi-VN", () => expect(formatEnrollments(1240)).toBe("1.240 học sinh đã đăng ký"));
});

describe("formatTotalDuration", () => {
  it.each([
    [0, "—"],
    [30, "< 1 phút"],
    [600, "10 phút"],
    [3600, "1 giờ"],
    [3725, "1 giờ 2 phút"],
  ])("%i -> %s", (s, expected) => expect(formatTotalDuration(s)).toBe(expected));
});

describe("formatLessonDuration", () => {
  it.each([
    [null, null],
    [0, null],
    [125, "2:05"],
    [3725, "1:02:05"],
  ])("%s -> %s", (s, expected) => expect(formatLessonDuration(s)).toBe(expected));
});
