import { authFetch } from "@/lib/api";
import { courseProgressSchema, myCoursesSchema, type CourseProgress, type MyCourses } from "./schemas";

/** Chỉ gọi từ trình duyệt (cookie phiên `vv_session` là host-only của host API). */
export async function fetchMyCourses(page: number, signal?: AbortSignal): Promise<MyCourses> {
  const qs = page > 1 ? `?page=${page}` : "";
  return myCoursesSchema.parse(await authFetch<unknown>(`/api/v1/me/courses${qs}`, { signal }));
}

export async function fetchCourseProgress(courseId: number, signal?: AbortSignal): Promise<CourseProgress> {
  return courseProgressSchema.parse(await authFetch<unknown>(`/api/v1/me/courses/${courseId}/progress`, { signal }));
}
