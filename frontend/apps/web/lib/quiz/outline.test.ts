import { describe, expect, it } from "vitest";
import type { LearnCourse } from "@/lib/learn/schemas";
import { findQuizPlacement, parseAttemptId, parseId, parseResultFilter, scoreHeadline } from "./outline";

const quiz = (id: number) => ({ id, title: `Quiz ${id}`, time_limit_minutes: null, question_count: 3 });
const lesson = (id: number, quizzes = [] as ReturnType<typeof quiz>[]) => ({
  id,
  title: `Bài ${id}`,
  position: id,
  is_preview: false,
  duration_seconds: 60,
  video_ready: true,
  status: "not_started" as const,
  quizzes,
});
const outline: LearnCourse = {
  course: { id: 1, title: "K", slug: "k" },
  course_percent: 0,
  resume_lesson_id: null,
  chapters: [
    { id: 10, title: "C1", position: 1, quizzes: [quiz(900)], lessons: [lesson(101, [quiz(901)]), lesson(102)] },
    { id: 20, title: "C2", position: 2, quizzes: [], lessons: [] },
    { id: 30, title: "C3", position: 3, quizzes: [], lessons: [lesson(301)] },
  ],
};

describe("findQuizPlacement", () => {
  it("quiz của bài: bài chứa nó và bài kế tiếp", () => {
    expect(findQuizPlacement(outline, 901)).toMatchObject({ lessonId: 101, nextLessonId: 102 });
  });
  it("quiz của chương: bài kế tiếp là bài đầu của chương sau có bài (bỏ chương trống)", () => {
    expect(findQuizPlacement(outline, 900)).toMatchObject({ lessonId: null, nextLessonId: 301 });
  });
  it("không có → null", () => {
    expect(findQuizPlacement(outline, 12345)).toBeNull();
  });
});

describe("parse & lời động viên", () => {
  it("parseId/parseAttemptId chỉ nhận số nguyên dương", () => {
    expect(parseId("12")).toBe(12);
    expect(parseId("0")).toBeNull();
    expect(parseId("1e3")).toBeNull();
    expect(parseAttemptId("7")).toBe(7);
    expect(parseAttemptId("-1")).toBeUndefined();
    expect(parseAttemptId(["8", "9"])).toBe(8);
  });
  it("parseResultFilter", () => {
    expect(parseResultFilter("sai")).toBe("sai");
    expect(parseResultFilter("khac")).toBeUndefined();
  });
  it("scoreHeadline theo mức điểm", () => {
    expect(scoreHeadline(9)).toBe("Làm tốt lắm!");
    expect(scoreHeadline(5)).toContain("Khá");
    expect(scoreHeadline(2)).toContain("xem lại bài");
  });
});
