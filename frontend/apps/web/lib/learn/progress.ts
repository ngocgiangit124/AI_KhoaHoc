import type { LearnCourse, LessonStatus } from "./schemas";

/** Đếm bài đã xong; `done` là các bài vừa hoàn thành ở phiên này (heartbeat báo `completed`, chưa kịp tải lại mục lục). */
export function lessonCounts(course: LearnCourse, done: ReadonlySet<number> = new Set()): { completed: number; total: number } {
  const all = course.chapters.flatMap((c) => c.lessons);
  return { completed: all.filter((l) => l.status === "completed" || done.has(l.id)).length, total: all.length };
}

export function effectiveStatus(status: LessonStatus, id: number, done: ReadonlySet<number>): LessonStatus {
  return done.has(id) ? "completed" : status;
}
