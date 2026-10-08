import type { MyCourseItem, MyProgress } from "./schemas";

export type CourseStatus = { tone: "success" | "info" | "neutral"; label: string };

/** Trạng thái bằng chữ của một khóa (không chỉ dựa vào màu). */
export function courseStatus(progress: MyProgress, lastAccessedAt: string | null): CourseStatus {
  if (progress.is_completed) return { tone: "success", label: "Đã hoàn thành" };
  if (lastAccessedAt !== null) return { tone: "info", label: "Đang học" };
  return { tone: "neutral", label: "Chưa bắt đầu" };
}

export function ctaLabel(progress: MyProgress, lastAccessedAt: string | null): string {
  if (progress.is_completed) return "Xem lại";
  return lastAccessedAt !== null ? "Tiếp tục học" : "Bắt đầu học";
}

export function progressText(progress: MyProgress): string {
  return `${progress.completed_lessons}/${progress.total_lessons} bài · ${progress.percent}%`;
}

/** Khóa nổi bật ở đầu trang "Học tiếp": khóa đầu tiên (API đã sắp học gần nhất trước) đang học dở và có bài để mở. */
export function pickResume(items: MyCourseItem[]): MyCourseItem | undefined {
  return items.find((i) => i.enrollment.last_accessed_at !== null && !i.progress.is_completed && i.resume_lesson_id !== null);
}
