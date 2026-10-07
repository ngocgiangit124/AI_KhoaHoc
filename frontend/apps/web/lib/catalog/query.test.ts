import { describe, expect, it } from "vitest";
import {
  hasActiveFilters,
  isValidCourseSlug,
  pageWindow,
  parseCatalogQuery,
  parseGradeSegment,
  toApiQueryString,
  toPageHref,
} from "./query";

describe("parseGradeSegment", () => {
  it.each([
    ["lop-6", 6],
    ["lop-9", 9],
    ["lop-12", 12],
  ])("%s -> %i", (seg, grade) => expect(parseGradeSegment(seg)).toBe(grade));

  it.each(["lop-99", "lop-5", "lop-13", "lop-0", "lop-09", "lop-", "lop-abc", "lop-9x", "khoa-hoc", "Lop-9", "lop-123"])(
    "%s -> null (404)",
    (seg) => expect(parseGradeSegment(seg)).toBeNull(),
  );
});

describe("parseCatalogQuery", () => {
  it("mặc định", () => {
    expect(parseCatalogQuery({})).toEqual({ grade: null, subjectIds: [], q: "", sort: "newest", page: 1 });
  });

  it("đọc đủ tham số, subject_ids lặp, bỏ trùng", () => {
    const q = parseCatalogQuery({ grade: "8", subject_ids: ["3", "5", "3"], q: "  hình học ", sort: "popular", page: "2" });
    expect(q).toEqual({ grade: 8, subjectIds: [3, 5], q: "hình học", sort: "popular", page: 2 });
  });

  it("giá trị sai bị bỏ qua êm, không ném lỗi", () => {
    const q = parseCatalogQuery({ grade: "99", subject_ids: ["abc", "-1", "0"], sort: "lạ", page: "-3" });
    expect(q).toEqual({ grade: null, subjectIds: [], q: "", sort: "newest", page: 1 });
  });

  it("q dài hơn 100 ký tự bị cắt", () => {
    expect(parseCatalogQuery({ q: "a".repeat(150) }).q).toHaveLength(100);
  });

  it("lớp cố định ghi đè ?grade", () => {
    expect(parseCatalogQuery({ grade: "7" }, 9).grade).toBe(9);
  });

  it("tối đa 20 chuyên đề", () => {
    const ids = Array.from({ length: 30 }, (_, i) => String(i + 1));
    expect(parseCatalogQuery({ subject_ids: ids }).subjectIds).toHaveLength(20);
  });
});

describe("toApiQueryString / toPageHref", () => {
  const base = { grade: 8, subjectIds: [3, 5], q: "hình học", sort: "popular" as const, page: 2 };

  it("API dùng subject_ids[] và bỏ giá trị mặc định", () => {
    const qs = decodeURIComponent(toApiQueryString(base));
    expect(qs).toBe("grade=8&subject_ids[]=3&subject_ids[]=5&q=hình+học&sort=popular&page=2");
    expect(toApiQueryString({ grade: null, subjectIds: [], q: "", sort: "newest", page: 1 })).toBe("");
  });

  it("URL trang: subject_ids lặp key; omitGrade ở /lop-{grade}", () => {
    expect(decodeURIComponent(toPageHref("/khoa-hoc", base, false))).toBe(
      "/khoa-hoc?grade=8&subject_ids=3&subject_ids=5&q=hình+học&sort=popular&page=2",
    );
    expect(toPageHref("/lop-8", { ...base, page: 1, subjectIds: [], q: "", sort: "newest" }, true)).toBe("/lop-8");
  });

  it("hasActiveFilters", () => {
    expect(hasActiveFilters({ ...base, grade: null, subjectIds: [], q: "" }, false)).toBe(false);
    expect(hasActiveFilters({ ...base, subjectIds: [], q: "" }, false)).toBe(true);
    expect(hasActiveFilters({ ...base, subjectIds: [], q: "" }, true)).toBe(false);
  });
});

describe("pageWindow", () => {
  it("rút gọn bằng gap", () => {
    expect(pageWindow(5, 10)).toEqual([1, "gap", 4, 5, 6, "gap", 10]);
    expect(pageWindow(1, 3)).toEqual([1, 2, 3]);
    expect(pageWindow(1, 1)).toEqual([1]);
  });
});

describe("isValidCourseSlug", () => {
  it("chấp nhận slug chuẩn, chặn ký tự lạ", () => {
    expect(isValidCourseSlug("toan-9-hinh-hoc-2")).toBe(true);
    for (const bad of ["", "A-b", "a/b", "../x", "a b", "a--", "-a", "a%2f"]) expect(isValidCourseSlug(bad)).toBe(false);
  });
});
