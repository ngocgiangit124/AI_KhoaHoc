import { describe, expect, it } from "vitest";
import { homeTeachersSchema, type HomeTeacherRow } from "./schemas";
import { HOME_TEACHERS_MAX, teacherPhotoAlt, toHomeTeacher, toHomeTeachers } from "./teachers";

const row = (over: Partial<HomeTeacherRow> = {}): HomeTeacherRow => ({
  id: 12,
  name: "Nguyễn Thị Lan",
  headline: "Giáo viên Toán THPT chuyên",
  bio: "10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh 2025.",
  avatar_url: "https://static.example/a.webp",
  grade_levels: [9, 10],
  courses_count: 3,
  ...over,
});

describe("toHomeTeacher", () => {
  it("đổi courses_count thành published_courses_count của thẻ UI, giữ nguyên xuống dòng của bio", () => {
    const t = toHomeTeacher(row());
    expect(t.published_courses_count).toBe(3);
    expect(t.bio).toBe("10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh 2025.");
    expect(t.grade_levels).toEqual([9, 10]);
  });

  it("chuỗi rỗng/khoảng trắng của headline, bio, avatar coi như không có", () => {
    const t = toHomeTeacher(row({ headline: "  ", bio: "\n ", avatar_url: " " }));
    expect(t).toMatchObject({ headline: null, bio: null, avatar_url: null });
  });

  it("không biến đổi HTML: giữ nguyên chuỗi để React hiển thị như chữ", () => {
    expect(toHomeTeacher(row({ bio: "<script>alert(1)</script>" })).bio).toBe("<script>alert(1)</script>");
  });
});

describe("toHomeTeachers", () => {
  it(`tối đa ${HOME_TEACHERS_MAX} người, giữ thứ tự API`, () => {
    const rows = Array.from({ length: 9 }, (_, i) => row({ id: i + 1 }));
    expect(toHomeTeachers(rows).map((t) => t.id)).toEqual([1, 2, 3, 4, 5, 6]);
  });
});

describe("teacherPhotoAlt", () => {
  it("theo US-020 BR7", () => expect(teacherPhotoAlt("Nguyễn Thị Lan")).toBe("Ảnh thầy/cô Nguyễn Thị Lan"));
});

describe("homeTeachersSchema", () => {
  it("chấp nhận response đúng contract và `data: []`", () => {
    expect(homeTeachersSchema.parse({ data: [] }).data).toEqual([]);
    expect(homeTeachersSchema.parse({ data: [row()] }).data[0]?.courses_count).toBe(3);
  });

  it("từ chối response sai hình dạng (để khu vực ẩn đi thay vì render sai)", () => {
    expect(homeTeachersSchema.safeParse({ items: [] }).success).toBe(false);
    expect(homeTeachersSchema.safeParse({ data: [{ id: "x", name: 1 }] }).success).toBe(false);
  });
});
