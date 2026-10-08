import { describe, expect, it } from "vitest";
import { classifyMyLoadError, parseCourseId, parsePage } from "./errors";
import { ctaLabel, courseStatus, pickResume, progressText } from "./format";
import { courseProgressSchema, myCoursesSchema, type MyCourseItem, type MyProgress } from "./schemas";
import { ApiError, NetworkError } from "@vitaminvui/api-client";

const prog = (o: Partial<MyProgress> = {}): MyProgress => ({ percent: 30, completed_lessons: 6, total_lessons: 20, is_completed: false, has_content: true, ...o });
const item = (o: Partial<MyCourseItem> & { last?: string | null; progress?: MyProgress }): MyCourseItem => ({
  course: { id: 1, title: "K", slug: "k", grade_level: 9, thumbnail_url: null, is_published: true },
  enrollment: { id: 1, status: "active", activated_at: null, last_accessed_at: o.last ?? null },
  progress: o.progress ?? prog(),
  resume_lesson_id: o.resume_lesson_id === undefined ? 5 : o.resume_lesson_id,
  best_quiz_score: null,
});

describe("trạng thái khóa", () => {
  it("hoàn thành / đang học / chưa bắt đầu", () => {
    expect(courseStatus(prog({ is_completed: true, percent: 100 }), "x").label).toBe("Đã hoàn thành");
    expect(courseStatus(prog(), "2026-10-01T00:00:00+07:00").label).toBe("Đang học");
    expect(courseStatus(prog({ percent: 0 }), null).label).toBe("Chưa bắt đầu");
    expect(ctaLabel(prog({ is_completed: true }), "x")).toBe("Xem lại");
    expect(ctaLabel(prog(), null)).toBe("Bắt đầu học");
    expect(ctaLabel(prog(), "x")).toBe("Tiếp tục học");
  });
  it("chữ tiến độ khớp AC1 (6/20 → 30%)", () => {
    expect(progressText(prog())).toBe("6/20 bài · 30%");
  });
  it("pickResume bỏ khóa đã xong/chưa học/không có bài", () => {
    const a = item({ last: "x", progress: prog({ is_completed: true, percent: 100 }) });
    const b = item({ last: null });
    const c = item({ last: "x", resume_lesson_id: null });
    const d = item({ last: "x" });
    expect(pickResume([a, b, c, d])).toBe(d);
    expect(pickResume([a, b])).toBeUndefined();
  });
});

describe("lỗi và tham số", () => {
  it("phân loại lỗi", () => {
    expect(classifyMyLoadError(new ApiError(401, { message: "x" }))).toBe("session");
    expect(classifyMyLoadError(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }))).toBe("not_owned");
    expect(classifyMyLoadError(new ApiError(404, { message: "x" }))).toBe("not_found");
    expect(classifyMyLoadError(new ApiError(429, { message: "x" }))).toBe("throttled");
    expect(classifyMyLoadError(new ApiError(500, { message: "x" }))).toBe("error");
    expect(classifyMyLoadError(new NetworkError(new Error("x")))).toBe("error");
  });
  it("parsePage / parseCourseId", () => {
    expect(parsePage(undefined)).toBe(1);
    expect(parsePage("3")).toBe(3);
    expect(parsePage("0")).toBe(1);
    expect(parsePage("-2")).toBe(1);
    expect(parsePage("abc")).toBe(1);
    expect(parsePage(["2", "5"])).toBe(2);
    expect(parseCourseId("12")).toBe(12);
    expect(parseCourseId("0")).toBeNull();
    expect(parseCourseId("1abc")).toBeNull();
    expect(parseCourseId("12345678901")).toBeNull();
  });
});

describe("schema", () => {
  const course = { id: 1, title: "K", slug: "k", grade_level: 9, thumbnail_url: null, is_published: true };
  it("điểm DECIMAL dạng chuỗi được đổi sang số", () => {
    const parsed = courseProgressSchema.parse({
      course,
      enrollment: { activated_at: null, last_accessed_at: null },
      progress: prog(),
      resume_lesson_id: null,
      chapters: [],
      quizzes: [{ id: 1, title: "Q", chapter_id: null, lesson_id: 2, question_count: 3, attempted: true, attempts_count: 2, best_score: "8.33" }],
    });
    expect(parsed.quizzes[0]?.best_score).toBe(8.33);
  });
  it("danh sách rỗng hợp lệ", () => {
    const parsed = myCoursesSchema.parse({ data: [], meta: { current_page: 1, per_page: 12, total: 0, last_page: 1 }, pending: [], rejected: [] });
    expect(parsed.data).toEqual([]);
  });
  it("sai contract thì ném lỗi", () => {
    expect(() => myCoursesSchema.parse({ data: [{}] })).toThrow();
  });
});
